<?php
if (!defined('ABSPATH')) exit;

/**
 * Private GitHub Releases updater for the Ice & Field monorepo.
 * A single read-only token in Dash Connector serves every managed component.
 */
class IFDC_GitHub_Updater {
    const OPTION = 'ifdc_github_updates';
    const CACHE = 'ifdc_github_release_manifest';
    const REPOSITORY = 'sarahspins/wordpress-plugins';
    const PACKAGE_SCHEME = 'ifdc-github-asset://';

    public static function init() {
        add_filter('pre_set_site_transient_update_plugins', [__CLASS__, 'plugin_updates']);
        add_filter('pre_set_site_transient_update_themes', [__CLASS__, 'theme_updates']);
        add_filter('upgrader_pre_download', [__CLASS__, 'download'], 10, 4);
        add_action('admin_post_ifdc_check_github_updates', [__CLASS__, 'check_now']);
        add_action('admin_notices', [__CLASS__, 'admin_notice']);
    }

    public static function menu() {
        global $submenu;
        if (!current_user_can('update_plugins')) return;
        $submenu['ifdc-dashboard'][] = [
            'Check GitHub Releases',
            'update_plugins',
            wp_nonce_url(admin_url('admin-post.php?action=ifdc_check_github_updates'), 'ifdc_check_github_updates'),
        ];
    }

    public static function defaults() {
        return ['enabled'=>'0', 'token'=>''];
    }

    public static function settings() {
        return wp_parse_args(get_option(self::OPTION, []), self::defaults());
    }

    public static function sanitize($input) {
        $old = self::settings();
        $token = sanitize_text_field(wp_unslash($input['token'] ?? ''));
        if ($token === '') $token = (string) $old['token'];
        delete_site_transient(self::CACHE);
        return ['enabled'=>!empty($input['enabled'])?'1':'0', 'token'=>$token];
    }

    public static function configured() {
        $settings = self::settings();
        return $settings['enabled'] === '1' && $settings['token'] !== '';
    }

    public static function render_settings() {
        $settings = self::settings();
        ?>
        <h2>Private GitHub Updates</h2>
        <p>Use one fine-grained, read-only GitHub token to receive releases from <code><?php echo esc_html(self::REPOSITORY); ?></code> through the normal WordPress update screens.</p>
        <table class="form-table">
            <tr>
                <th>Enable GitHub Updates</th>
                <td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[enabled]" value="1" <?php checked($settings['enabled'], '1'); ?>> Check the private Ice &amp; Field release feed</label></td>
            </tr>
            <tr>
                <th>Fine-grained Token</th>
                <td>
                    <input type="password" class="regular-text" autocomplete="new-password" name="<?php echo esc_attr(self::OPTION); ?>[token]" value="">
                    <p class="description"><?php echo $settings['token'] ? 'A token is saved. Leave blank to keep it.' : 'Create a fine-grained token restricted to this repository with Contents: Read-only access.'; ?></p>
                </td>
            </tr>
        </table>
        <p class="description">Use <strong>Dash Connector → Check GitHub Releases</strong> whenever you want WordPress to check immediately.</p>
        <?php
    }

    private static function components() {
        return [
            'ice-field-dash-connector' => ['type'=>'plugin','file'=>'ice-field-dash-connector/ice-field-dash-connector.php'],
            'ice-field-programming' => ['type'=>'plugin','file'=>'ice-field-programming/ice-field-programming.php'],
            'ice-field-productions' => ['type'=>'plugin','file'=>'ice-field-productions/ice-field-productions.php'],
            'ice-field-rink-displays' => ['type'=>'plugin','file'=>'ice-field-rink-displays/ice-field-rink-displays.php'],
            'ice-field-productions-theme' => ['type'=>'theme','file'=>'ice-field-productions-theme'],
        ];
    }

    private static function headers() {
        $settings = self::settings();
        return [
            'Authorization' => 'Bearer ' . $settings['token'],
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent' => 'Ice-Field-WordPress-Updater/' . IFDC_VERSION,
        ];
    }

    private static function release() {
        if (!self::configured()) return new WP_Error('ifdc_updates_disabled', 'Private GitHub updates are not configured.');
        $cached = get_site_transient(self::CACHE);
        if (is_array($cached)) return $cached;

        $response = wp_remote_get('https://api.github.com/repos/' . self::REPOSITORY . '/releases/latest', [
            'timeout'=>20, 'headers'=>self::headers(),
        ]);
        if (is_wp_error($response)) return $response;
        $status = wp_remote_retrieve_response_code($response);
        $release = json_decode(wp_remote_retrieve_body($response), true);
        if ($status !== 200 || !is_array($release)) return new WP_Error('ifdc_release_error', 'GitHub release lookup failed.', ['status'=>$status]);

        $assets = [];
        foreach ((array) ($release['assets'] ?? []) as $asset) {
            $name = sanitize_file_name((string) ($asset['name'] ?? ''));
            if ($name !== '') $assets[$name] = ['id'=>absint($asset['id'] ?? 0), 'api_url'=>esc_url_raw($asset['url'] ?? '')];
        }
        $manifest_asset = $assets['ice-field-updates.json'] ?? null;
        if (!$manifest_asset || !$manifest_asset['api_url']) return new WP_Error('ifdc_manifest_missing', 'The latest GitHub release has no update manifest.');

        $manifest_response = wp_remote_get($manifest_asset['api_url'], [
            'timeout'=>20,
            'headers'=>array_merge(self::headers(), ['Accept'=>'application/octet-stream']),
        ]);
        if (is_wp_error($manifest_response)) return $manifest_response;
        $manifest = json_decode(wp_remote_retrieve_body($manifest_response), true);
        if (!is_array($manifest) || empty($manifest['components'])) return new WP_Error('ifdc_manifest_invalid', 'The GitHub update manifest is invalid.');

        $data = ['release'=>$release, 'assets'=>$assets, 'manifest'=>$manifest];
        set_site_transient(self::CACHE, $data, 6 * HOUR_IN_SECONDS);
        return $data;
    }

    public static function plugin_updates($transient) {
        if (!is_object($transient)) $transient = new stdClass();
        $data = self::release();
        if (is_wp_error($data)) return $transient;
        foreach (self::components() as $slug=>$component) {
            if ($component['type'] !== 'plugin') continue;
            $item = $data['manifest']['components'][$slug] ?? [];
            $asset = $data['assets'][$item['asset'] ?? ''] ?? [];
            if (empty($item['version']) || empty($asset['id'])) continue;
            $installed = get_file_data(WP_PLUGIN_DIR . '/' . $component['file'], ['Version'=>'Version'])['Version'] ?? '0';
            if (!version_compare($item['version'], $installed, '>')) continue;
            $transient->response[$component['file']] = (object) [
                'id'=>'github.com/' . self::REPOSITORY . '/' . $slug,
                'slug'=>$slug,
                'plugin'=>$component['file'],
                'new_version'=>$item['version'],
                'url'=>'https://github.com/' . self::REPOSITORY,
                'package'=>self::PACKAGE_SCHEME . absint($asset['id']),
                'requires'=>$item['requires'] ?? '',
                'requires_php'=>$item['requires_php'] ?? '',
                'tested'=>$item['tested'] ?? '',
            ];
        }
        return $transient;
    }

    public static function theme_updates($transient) {
        if (!is_object($transient)) $transient = new stdClass();
        $data = self::release();
        if (is_wp_error($data)) return $transient;
        $slug = 'ice-field-productions-theme';
        $item = $data['manifest']['components'][$slug] ?? [];
        $asset = $data['assets'][$item['asset'] ?? ''] ?? [];
        $theme = wp_get_theme($slug);
        if (!$theme->exists() || empty($item['version']) || empty($asset['id']) || !version_compare($item['version'], $theme->get('Version'), '>')) return $transient;
        $transient->response[$slug] = [
            'theme'=>$slug,
            'new_version'=>$item['version'],
            'url'=>'https://github.com/' . self::REPOSITORY,
            'package'=>self::PACKAGE_SCHEME . absint($asset['id']),
            'requires'=>$item['requires'] ?? '',
            'requires_php'=>$item['requires_php'] ?? '',
        ];
        return $transient;
    }

    public static function download($reply, $package, $upgrader, $hook_extra) {
        if (strpos((string) $package, self::PACKAGE_SCHEME) !== 0) return $reply;
        if (!self::configured()) return new WP_Error('ifdc_update_auth', 'The private GitHub update token is not configured.');
        $asset_id = absint(substr($package, strlen(self::PACKAGE_SCHEME)));
        if (!$asset_id) return new WP_Error('ifdc_update_asset', 'The GitHub release asset is invalid.');

        $response = wp_remote_get('https://api.github.com/repos/' . self::REPOSITORY . '/releases/assets/' . $asset_id, [
            'timeout'=>60,
            'redirection'=>5,
            'headers'=>array_merge(self::headers(), ['Accept'=>'application/octet-stream']),
        ]);
        if (is_wp_error($response)) return $response;
        if (wp_remote_retrieve_response_code($response) !== 200) return new WP_Error('ifdc_update_download', 'GitHub could not download the private release asset.');
        $temporary = wp_tempnam('ice-field-update-' . $asset_id . '.zip');
        if (!$temporary) return new WP_Error('ifdc_update_temp', 'WordPress could not create a temporary update file.');
        $written = file_put_contents($temporary, wp_remote_retrieve_body($response));
        if (!$written) return new WP_Error('ifdc_update_write', 'WordPress could not save the downloaded update package.');
        return $temporary;
    }

    public static function check_now() {
        if (!current_user_can('update_plugins')) wp_die('Permission denied.');
        check_admin_referer('ifdc_check_github_updates');
        delete_site_transient(self::CACHE);
        delete_site_transient('update_plugins');
        delete_site_transient('update_themes');
        wp_update_plugins();
        wp_update_themes();
        wp_safe_redirect(add_query_arg('ifdc_updates_checked', self::configured() ? '1' : 'not-configured', admin_url('update-core.php')));
        exit;
    }

    public static function admin_notice() {
        if (!current_user_can('update_plugins')) return;
        $status = sanitize_key((string) wp_unslash($_GET['ifdc_updates_checked'] ?? ''));
        if ($status === '1') {
            echo '<div class="notice notice-success is-dismissible"><p><strong>Ice &amp; Field:</strong> GitHub releases were checked. Available plugin and theme updates are shown below.</p></div>';
        } elseif ($status === 'not-configured') {
            echo '<div class="notice notice-warning"><p><strong>Ice &amp; Field:</strong> Private GitHub updates are not configured. <a href="' . esc_url(admin_url('admin.php?page=ifdc-settings')) . '">Open Dash Connector Settings</a>.</p></div>';
        }
    }
}
