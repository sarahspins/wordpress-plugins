<?php
/**
 * Independently managed full-screen video pages that reuse the TV player.
 */
class IFRD_Additional_Video_Screen {
    const CAPABILITY = 'edit_pages';

    private $key;
    private $title;
    private $option;
    private $refresh_option;
    private $ajax_action;
    private $page_slug;
    private $shortcode;
    private $settings_group;
    private $refresh_action;

    public function __construct($key, $title, $page_slug, $shortcode) {
        $this->key = sanitize_key($key);
        $this->title = (string) $title;
        $this->option = 'ifrd_' . $this->key . '_screen_settings';
        $this->refresh_option = 'ifrd_' . $this->key . '_screen_refresh_version';
        $this->ajax_action = 'ifrd_' . $this->key . '_screen_refresh_status';
        $this->page_slug = sanitize_key($page_slug);
        $this->shortcode = sanitize_key($shortcode);
        $this->settings_group = 'ifrd_' . $this->key . '_screen_group';
        $this->refresh_action = 'ifrd_refresh_' . $this->key . '_screens';

        add_action('admin_menu', array($this, 'menu'), 30);
        add_action('admin_init', array($this, 'settings'));
        add_filter('option_page_capability_' . $this->settings_group, array($this, 'settings_capability'));
        add_action('admin_enqueue_scripts', array($this, 'admin_assets'));
        add_action('admin_post_' . $this->refresh_action, array($this, 'refresh_screens'));
        add_action('wp_ajax_' . $this->ajax_action, array($this, 'refresh_status'));
        add_action('wp_ajax_nopriv_' . $this->ajax_action, array($this, 'refresh_status'));
        add_shortcode($this->shortcode, array($this, 'shortcode'));
    }

    public function settings_capability() {
        return self::CAPABILITY;
    }

    public function opts() {
        return wp_parse_args(get_option($this->option, array()), array('video_url' => ''));
    }

    public function menu() {
        add_submenu_page(
            'ifrd-schedule-display',
            $this->title,
            $this->title,
            self::CAPABILITY,
            $this->page_slug,
            array($this, 'page')
        );
    }

    public function settings() {
        register_setting($this->settings_group, $this->option, array($this, 'sanitize'));
    }

    public function admin_assets($hook) {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page === $this->page_slug) {
            wp_enqueue_media();
        }
    }

    public function current_refresh_version() {
        $version = (string) get_option($this->refresh_option, '');
        if ($version === '') {
            $version = (string) time();
            update_option($this->refresh_option, $version, false);
        }
        return $version;
    }

    public function bump_refresh_version() {
        $version = (string) round(microtime(true) * 1000);
        update_option($this->refresh_option, $version, false);
        return $version;
    }

    public function sanitize($input) {
        $video_url = isset($input['video_url']) ? esc_url_raw($input['video_url']) : '';
        $old = $this->opts();
        if ($video_url !== (string) ($old['video_url'] ?? '')) {
            $this->bump_refresh_version();
        }
        return array('video_url' => $video_url);
    }

    public function refresh_screens() {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to refresh these screens.', 'ice-field-rink-displays'));
        }

        check_admin_referer($this->refresh_action);
        $this->bump_refresh_version();
        wp_safe_redirect(add_query_arg(
            array('page' => $this->page_slug, 'screens-refreshed' => '1'),
            admin_url('admin.php')
        ));
        exit;
    }

    public function refresh_status() {
        nocache_headers();
        wp_send_json_success(array('version' => $this->current_refresh_version()));
    }

    public function page() {
        $o = $this->opts();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html($this->title); ?></h1>
            <?php if (!empty($_GET['screens-refreshed'])): ?>
                <div class="notice notice-success is-dismissible"><p>Refresh requested. Open screens should reload within about 60 seconds.</p></div>
            <?php endif; ?>
            <form method="post" action="options.php">
                <?php settings_fields($this->settings_group); ?>
                <table class="form-table">
                    <tr>
                        <th>Full-Screen Video</th>
                        <td><?php IFRD_Banner_Media::render_picker($this->option, $o, 'video_url', 'ifrd-' . $this->key . '-screen-file', array('video'), array(
                            'title' => 'Choose a video for ' . $this->title,
                            'button_text' => 'Use this video',
                            'placeholder' => 'Select a video or paste its URL',
                            'description' => 'The video fills the browser, starts muted, and repeats continuously. Saving a different video also asks open screens to refresh.',
                        )); ?></td>
                    </tr>
                </table>
                <?php submit_button('Save Video'); ?>
            </form>
            <hr>
            <h2>Remote Refresh</h2>
            <p>Use this whenever open screens need to reload.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr($this->refresh_action); ?>">
                <?php wp_nonce_field($this->refresh_action); ?>
                <?php submit_button('Refresh Screens Now', 'secondary', 'submit', false); ?>
            </form>
            <p><strong>Shortcode:</strong> <code>[<?php echo esc_html($this->shortcode); ?>]</code></p>
        </div>
        <?php IFRD_Banner_Media::print_picker_script(); ?>
        <?php
    }

    public function shortcode($atts) {
        $o = $this->opts();
        return IFRD_Video_For_Screens::render_player(
            (string) ($o['video_url'] ?? ''),
            $this->current_refresh_version(),
            $this->ajax_action,
            'ifrd-' . $this->key . '-video-'
        );
    }
}

