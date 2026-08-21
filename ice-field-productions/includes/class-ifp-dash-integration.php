<?php
if (!defined('ABSPATH')) exit;

class IFP_Dash_Integration {
    const OPTION = 'ifp_dash_import_settings';
    const NONCE_ACTION = 'ifp_dash_discovery';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu'], 30);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_notices', [__CLASS__, 'notice']);
        add_action('admin_post_ifp_dash_import_teams', [__CLASS__, 'import_teams']);
        add_action('admin_post_ifp_dash_sync_participants', [__CLASS__, 'sync_participants_action']);
        add_action('wp_ajax_ifp_dash_preview_roster', [__CLASS__, 'ajax_preview_roster']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
    }

    public static function defaults() {
        return [
            'season_id' => '12',
            'league_ids' => '',
            'production_id' => '0',
            'import_mode' => 'manual',
            'import_status' => 'draft',
            'sync_participants' => '1',
        ];
    }

    public static function settings() {
        $saved = (array) get_option(self::OPTION, []);

        // Preserve useful values from the pre-team discovery settings.
        if (empty($saved['league_ids']) && !empty($saved['level_ids'])) {
            $saved['league_ids'] = $saved['level_ids'];
        }

        return wp_parse_args($saved, self::defaults());
    }

    public static function connector_available() {
        return class_exists('IFDC_Client');
    }

    public static function connector_ready() {
        return self::connector_available() && IFDC_Client::is_configured();
    }

    public static function menu() {
        add_submenu_page(
            'ifp-dashboard',
            'Dash Discovery',
            'Dash Discovery',
            'manage_options',
            'ifp-dash-integration',
            [__CLASS__, 'page']
        );
    }

    public static function register_settings() {
        register_setting('ifp_dash_import_group', self::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [__CLASS__, 'sanitize'],
            'default' => self::defaults(),
        ]);
    }

    public static function sanitize($input) {
        $league_ids = preg_replace('/[^0-9,\s]/', '', (string) ($input['league_ids'] ?? ''));
        $league_ids = implode(',', array_values(array_unique(array_filter(array_map('absint', preg_split('/[\s,]+/', $league_ids))))));

        $import_status = sanitize_key($input['import_status'] ?? 'draft');
        if (!in_array($import_status, ['draft','private','publish'], true)) $import_status = 'draft';

        return [
            'season_id' => (string) absint($input['season_id'] ?? 0),
            'league_ids' => $league_ids,
            'production_id' => (string) absint($input['production_id'] ?? 0),
            'import_mode' => 'manual',
            'import_status' => $import_status,
            'sync_participants' => !empty($input['sync_participants']) ? '1' : '0',
        ];
    }

    public static function assets($hook) {
        if (strpos((string) $hook, 'ifp-dash-integration') === false) return;

        wp_enqueue_style('ifp-admin', IFP_URL . 'assets/admin.css', [], IFP_VERSION);
        wp_enqueue_script(
            'ifp-dash-integration',
            IFP_URL . 'assets/dash-integration.js',
            [],
            IFP_VERSION,
            true
        );
        wp_localize_script('ifp-dash-integration', 'IFPDashDiscovery', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ifp_dash_roster_preview'),
            'loading' => 'Loading roster…',
            'error' => 'Dash could not load this roster.',
        ]);
    }

    public static function notice() {
        if (!current_user_can('manage_options')) return;
        $screen = get_current_screen();
        if (!$screen || strpos((string) $screen->id, 'ifp') === false) return;
        if (self::connector_ready()) return;

        if (!self::connector_available()) {
            echo '<div class="notice notice-warning"><p><strong>Ice & Field Productions:</strong> Dash features require Ice & Field Dash Connector v1.2.0 or newer.</p></div>';
        } else {
            echo '<div class="notice notice-warning"><p><strong>Ice & Field Productions:</strong> The shared Dash Connector needs credentials before group discovery can begin. <a href="' .
                esc_url(admin_url('admin.php?page=ifdc-settings')) . '">Configure Dash Connector</a>.</p></div>';
        }
    }

    private static function productions() {
        return get_posts([
            'post_type' => 'ifp_production',
            'post_status' => ['publish', 'draft', 'private', 'future'],
            'posts_per_page' => -1,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);
    }

    private static function league_ids($settings) {
        return array_values(array_filter(array_map('absint', preg_split('/[\s,]+/', (string) ($settings['league_ids'] ?? '')))));
    }

    private static function discover($settings, $force = false) {
        if (!self::connector_ready()) {
            return new WP_Error('ifp_dash_not_ready', 'The Dash Connector is not ready.');
        }

        $season_id = absint($settings['season_id'] ?? 0);
        if (!$season_id) {
            return new WP_Error('ifp_dash_missing_season', 'Enter a Dash Season ID before running discovery.');
        }

        $result = IFDC_Client::get_teams(
            ['season_id' => $season_id],
            ['force' => $force, 'cache_ttl' => 300]
        );
        if (is_wp_error($result)) return $result;

        $teams = is_array($result['data'] ?? null) ? $result['data'] : [];
        $league_ids = self::league_ids($settings);
        if ($league_ids) {
            $teams = array_values(array_filter($teams, function($team) use ($league_ids) {
                $league_id = absint($team['attributes']['league_id'] ?? 0);
                return in_array($league_id, $league_ids, true);
            }));
        }

        usort($teams, function($a, $b) {
            $league_a = absint($a['attributes']['league_id'] ?? 0);
            $league_b = absint($b['attributes']['league_id'] ?? 0);
            if ($league_a !== $league_b) return $league_a <=> $league_b;
            return strcasecmp((string) ($a['attributes']['name'] ?? ''), (string) ($b['attributes']['name'] ?? ''));
        });

        return $teams;
    }

    public static function page() {
        $settings = self::settings();
        $available = self::connector_available();
        $ready = self::connector_ready();
        $diag = $ready ? IFDC_Client::diagnostics() : null;
        $discover_requested = isset($_GET['ifp_discover']);
        $teams = null;
        $error = null;

        if ($discover_requested && $ready) {
            check_admin_referer(self::NONCE_ACTION);
            $teams = self::discover($settings, !empty($_GET['refresh']));
            if (is_wp_error($teams)) {
                $error = $teams;
                $teams = [];
            }
        }
        ?>
        <div class="wrap ifp-dashboard ifp-dash-discovery">
            <div class="ifp-page-header">
                <div>
                    <p class="ifp-kicker">Ice &amp; Field Productions</p>
                    <h1>Dash Discovery</h1>
                    <p class="ifp-lead">Preview Dash teams and rosters before creating or updating Production Groups.</p>
                </div>
            </div>

            <?php if (!empty($_GET['ifp_imported'])): ?>
                <div class="notice notice-success is-dismissible"><p>
                    <?php echo esc_html(absint($_GET['ifp_imported'])); ?> group(s) imported or updated from Dash.
                </p></div>
            <?php endif; ?>

            <?php if (!empty($_GET['ifp_synced'])): ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html(absint($_GET['ifp_synced'])); ?> participant(s) synchronized from Dash.</p></div>
            <?php endif; ?>

            <?php if (!empty($_GET['ifp_import_error'])): ?>
                <div class="notice notice-error"><p><?php echo esc_html(sanitize_text_field(wp_unslash($_GET['ifp_import_error']))); ?></p></div>
            <?php endif; ?>

            <section class="ifp-current-card" style="display:block;padding:26px;">
                <h2>Shared Connector</h2>
                <?php if (!$available): ?>
                    <p><strong>Status:</strong> Connector plugin not detected.</p>
                <?php elseif (!$ready): ?>
                    <p><strong>Status:</strong> Connector installed; setup required.</p>
                    <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=ifdc-settings')); ?>">Configure Connector</a>
                <?php else: ?>
                    <p><strong>Status:</strong> <span style="color:#18713c">Connected and ready</span></p>
                    <p><strong>Company:</strong> <?php echo esc_html($diag['company']); ?> &nbsp; <strong>Requests today:</strong> <?php echo esc_html($diag['requests_today']); ?></p>
                    <p>
                        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=ifdc-explorer')); ?>">Open Object Explorer</a>
                        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=ifdc-schema')); ?>">View Learned Schema</a>
                    </p>
                <?php endif; ?>
            </section>

            <section class="ifp-current-card" style="display:block;padding:26px;margin-top:22px;">
                <h2>Discovery Scope</h2>
                <form method="post" action="options.php">
                    <?php settings_fields('ifp_dash_import_group'); ?>
                    <table class="form-table">
                        <tr>
                            <th><label for="ifp-production-id">Production</label></th>
                            <td>
                                <select id="ifp-production-id" name="<?php echo esc_attr(self::OPTION); ?>[production_id]">
                                    <option value="0">— Select the WordPress production —</option>
                                    <?php foreach (self::productions() as $production): ?>
                                        <option value="<?php echo esc_attr($production->ID); ?>" <?php selected(absint($settings['production_id']), $production->ID); ?>><?php echo esc_html($production->post_title); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">Imported teams will be assigned to this production.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="ifp-season-id">Dash Season ID</label></th>
                            <td><input id="ifp-season-id" type="number" name="<?php echo esc_attr(self::OPTION); ?>[season_id]" value="<?php echo esc_attr($settings['season_id']); ?>"></td>
                        </tr>
                        <tr>
                            <th><label for="ifp-league-ids">Dash League IDs</label></th>
                            <td>
                                <input id="ifp-league-ids" class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[league_ids]" value="<?php echo esc_attr($settings['league_ids']); ?>">
                                <p class="description">Optional comma-separated filter. Leave blank to preview every team in the selected season.</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Import Mode</th>
                            <td><strong>Manual preview and approval</strong><p class="description">Nothing is imported until you select teams and click the import button.</p></td>
                        </tr>
                    </table>
                    <?php submit_button('Save Discovery Scope'); ?>
                </form>

                <?php if ($ready): ?>
                    <?php
                    $discover_url = wp_nonce_url(
                        add_query_arg(['page' => 'ifp-dash-integration', 'ifp_discover' => 1], admin_url('admin.php')),
                        self::NONCE_ACTION
                    );
                    ?>
                    <p>
                        <a class="button button-primary button-hero" href="<?php echo esc_url($discover_url); ?>">Preview Dash Teams</a>
                        <?php if ($discover_requested): ?>
                            <a class="button" href="<?php echo esc_url(add_query_arg('refresh', 1, $discover_url)); ?>">Refresh from Dash</a>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
            </section>

            <?php if ($error): ?>
                <div class="notice notice-error"><p><strong>Dash discovery failed:</strong> <?php echo esc_html($error->get_error_message()); ?></p></div>
            <?php endif; ?>

            <?php if (is_array($teams)): ?>
                <?php self::render_results($teams, $settings); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function render_results($teams, $settings) {
        $production_id = absint($settings['production_id'] ?? 0);
        ?>
        <section class="ifp-current-card ifp-dash-results" style="display:block;padding:26px;margin-top:22px;">
            <div class="ifp-dash-results__heading">
                <div>
                    <h2>Team Preview</h2>
                    <p><?php echo esc_html(count($teams)); ?> team(s) matched Season <?php echo esc_html(absint($settings['season_id'])); ?><?php echo self::league_ids($settings) ? ' and the saved league filter' : ''; ?>.</p>
                </div>
            </div>

            <?php if (!$teams): ?>
                <p>No Dash teams matched this scope. Try leaving League IDs blank to inspect the entire season.</p>
                <?php return; ?>
            <?php endif; ?>

            <?php if (!$production_id): ?>
                <div class="notice notice-warning inline"><p>Select and save a WordPress production before importing. Roster previews are still available.</p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="ifp_dash_import_teams">
                <input type="hidden" name="production_id" value="<?php echo esc_attr($production_id); ?>">
                <?php wp_nonce_field('ifp_dash_import_teams'); ?>

                <div class="ifp-dash-team-toolbar">
                    <label><input type="checkbox" class="ifp-dash-select-all"> Select all</label>
                    <span class="description">Imported teams update their matching Group instead of creating duplicates.</span>
                </div>

                <div class="ifp-dash-team-list">
                    <?php foreach ($teams as $team): self::render_team($team); endforeach; ?>
                </div>

                <div class="ifp-dash-import-options" style="margin:20px 0;padding:18px;border:1px solid #dcdcde;background:#fff;">
                    <h3 style="margin-top:0;">Import Options</h3>
                    <p><strong>WordPress status</strong></p>
                    <label style="margin-right:18px;"><input type="radio" name="import_status" value="draft" <?php checked(($settings['import_status'] ?? 'draft'), 'draft'); ?>> Draft</label>
                    <label style="margin-right:18px;"><input type="radio" name="import_status" value="private" <?php checked(($settings['import_status'] ?? 'draft'), 'private'); ?>> Private</label>
                    <label><input type="radio" name="import_status" value="publish" <?php checked(($settings['import_status'] ?? 'draft'), 'publish'); ?>> Public</label>
                    <p style="margin-bottom:0;"><label><input type="checkbox" name="sync_participants" value="1" <?php checked(($settings['sync_participants'] ?? '1'), '1'); ?>> Import/synchronize registered participants now</label></p>
                </div>

                <?php
                $button_attrs = $production_id ? [] : ['disabled' => 'disabled'];
                submit_button('Import Selected Teams', 'primary', 'submit', false, $button_attrs);
                ?>
            </form>
        </section>
        <?php
    }

    private static function render_team($team) {
        $team_id = absint($team['id'] ?? 0);
        $a = is_array($team['attributes'] ?? null) ? $team['attributes'] : [];
        $name = (string) ($a['name'] ?? ('Team ' . $team_id));
        $existing = self::find_group($team_id);
        $status = (string) ($a['status'] ?? (!empty($a['inactive']) ? 'inactive' : 'unknown'));
        $days = array_map([__CLASS__, 'day_name'], (array) ($a['days_of_week'] ?? []));
        $start = !empty($a['start_date']) ? strtotime($a['start_date']) : 0;
        ?>
        <article class="ifp-dash-team" data-team-id="<?php echo esc_attr($team_id); ?>">
            <div class="ifp-dash-team__select">
                <input type="checkbox" name="team_ids[]" value="<?php echo esc_attr($team_id); ?>" aria-label="Select <?php echo esc_attr($name); ?>">
            </div>
            <div class="ifp-dash-team__main">
                <div class="ifp-dash-team__title-row">
                    <div>
                        <h3><?php echo esc_html($name); ?></h3>
                        <span class="ifp-scope-badge">Team <?php echo esc_html($team_id); ?></span>
                        <span class="ifp-scope-badge">League <?php echo esc_html(absint($a['league_id'] ?? 0)); ?></span>
                        <span class="ifp-scope-badge <?php echo $status === 'active' ? 'ifp-scope-badge--current' : 'ifp-scope-badge--shared'; ?>"><?php echo esc_html(ucfirst($status)); ?></span>
                        <?php if ($existing): ?><span class="ifp-scope-badge ifp-scope-badge--current">Already imported</span><?php endif; ?>
                    </div>
                    <?php if ($existing): ?>
                        <a class="button" href="<?php echo esc_url(get_edit_post_link($existing)); ?>">Edit Group</a>
                    <?php endif; ?>
                </div>

                <dl class="ifp-dash-team__facts">
                    <div><dt>Season</dt><dd><?php echo esc_html(absint($a['season_id'] ?? 0)); ?></dd></div>
                    <div><dt>Product</dt><dd><?php echo esc_html(absint($a['product_id'] ?? 0) ?: '—'); ?></dd></div>
                    <div><dt>Schedule</dt><dd><?php echo esc_html(($days ? implode(', ', $days) : '—') . ($start ? ' · ' . wp_date(get_option('time_format'), $start) : '')); ?></dd></div>
                    <div><dt>Length</dt><dd><?php echo !empty($a['event_length']) ? esc_html(absint($a['event_length']) . ' min') : '—'; ?></dd></div>
                    <div><dt>Capacity</dt><dd><?php echo !empty($a['max_roster_size']) ? esc_html(absint($a['max_roster_size'])) : 'No limit set'; ?></dd></div>
                    <div><dt>Upcoming events</dt><dd><?php echo !empty($a['has_upcoming_events']) ? 'Yes' : 'No'; ?></dd></div>
                </dl>

                <div class="ifp-dash-team__actions">
                    <button type="button" class="button ifp-dash-preview-roster" data-team-id="<?php echo esc_attr($team_id); ?>">Preview Roster &amp; Count</button>
                    <a class="button" href="<?php echo esc_url(add_query_arg(['endpoint' => 'teams/' . $team_id], admin_url('admin.php?page=ifdc-explorer'))); ?>">Inspect in Connector</a>
                </div>
                <div class="ifp-dash-roster" hidden aria-live="polite"></div>
            </div>
        </article>
        <?php
    }

    public static function ajax_preview_roster() {
        check_ajax_referer('ifp_dash_roster_preview', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Permission denied.'], 403);
        if (!self::connector_ready()) wp_send_json_error(['message' => 'Dash Connector is not ready.'], 400);

        $team_id = absint($_POST['team_id'] ?? 0);
        if (!$team_id) wp_send_json_error(['message' => 'Missing team ID.'], 400);

        $result = IFDC_Client::get_registered_customers($team_id, ['force' => !empty($_POST['force']), 'cache_ttl' => 300]);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 500);
        }

        $customers = is_array($result['data'] ?? null) ? $result['data'] : [];
        $rows = [];
        foreach ($customers as $customer) {
            $attrs = is_array($customer['attributes'] ?? null) ? $customer['attributes'] : [];
            $rows[] = [
                'id' => (string) ($customer['id'] ?? ''),
                'name' => self::customer_name($attrs, $customer),
                'email' => self::first_value($attrs, ['email', 'email_address', 'primary_email']),
                'status' => self::first_value($attrs, ['status', 'registration_status']),
            ];
        }

        wp_send_json_success([
            'count' => count($customers),
            'customers' => $rows,
            'truncated' => !empty($result['meta']['truncated']),
        ]);
    }

    public static function import_teams() {
        if (!current_user_can('manage_options')) wp_die('Permission denied.');
        check_admin_referer('ifp_dash_import_teams');

        $production_id = absint($_POST['production_id'] ?? 0);
        $team_ids = array_values(array_unique(array_filter(array_map('absint', (array) ($_POST['team_ids'] ?? [])))));
        $import_status = sanitize_key($_POST['import_status'] ?? 'draft');
        if (!in_array($import_status, ['draft','private','publish'], true)) $import_status = 'draft';
        $sync_participants = !empty($_POST['sync_participants']);
        $redirect = admin_url('admin.php?page=ifp-dash-integration');

        if (!$production_id || get_post_type($production_id) !== 'ifp_production') {
            wp_safe_redirect(add_query_arg('ifp_import_error', 'Select a valid WordPress production before importing.', $redirect));
            exit;
        }
        if (!$team_ids) {
            wp_safe_redirect(add_query_arg('ifp_import_error', 'Select at least one Dash team.', $redirect));
            exit;
        }
        if (!self::connector_ready()) {
            wp_safe_redirect(add_query_arg('ifp_import_error', 'The Dash Connector is not ready.', $redirect));
            exit;
        }

        $count = 0;
        foreach ($team_ids as $team_id) {
            $payload = IFDC_Client::get_team($team_id, [], ['force' => true, 'cache' => false]);
            if (is_wp_error($payload)) continue;
            $team = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
            if (!is_array($team)) continue;
            $post_id = self::upsert_group($team, $production_id, $import_status);
            if (!is_wp_error($post_id) && $post_id) {
                $count++;
                if ($sync_participants) self::sync_group_participants($post_id, true);
            }
        }

        wp_safe_redirect(add_query_arg(['ifp_imported' => $count, 'ifp_discover' => 1, '_wpnonce' => wp_create_nonce(self::NONCE_ACTION)], $redirect));
        exit;
    }

    private static function upsert_group($team, $production_id, $import_status = 'draft') {
        $team_id = absint($team['id'] ?? 0);
        $a = is_array($team['attributes'] ?? null) ? $team['attributes'] : [];
        if (!$team_id || empty($a['name'])) return new WP_Error('ifp_invalid_team', 'Dash team data was incomplete.');

        $post_id = self::find_group($team_id);
        $is_new = !$post_id;
        $existing_status = $post_id ? get_post_status($post_id) : '';
        $resolved_status = in_array($existing_status, ['draft','pending','private','publish','future'], true)
            ? $existing_status
            : (in_array($import_status, ['draft','private','publish'], true) ? $import_status : 'draft');
        $postarr = [
            'post_type' => 'ifp_group',
            'post_title' => sanitize_text_field($a['name']),
            'post_status' => $resolved_status,
        ];

        if ($post_id) {
            $postarr['ID'] = $post_id;
            $result = wp_update_post(wp_slash($postarr), true);
        } else {
            $postarr['post_excerpt'] = !empty($a['best_description']) ? wp_strip_all_tags($a['best_description']) : '';
            $result = wp_insert_post(wp_slash($postarr), true);
        }
        if (is_wp_error($result)) return $result;
        $post_id = absint($result);

        update_post_meta($post_id, '_ifp_group_production_id', absint($production_id));
        if ($is_new || get_post_meta($post_id, '_ifp_group_type', true) === '') {
            update_post_meta($post_id, '_ifp_group_type', self::infer_group_type((string) $a['name']));
        }
        if ($is_new || get_post_meta($post_id, '_ifp_group_visibility', true) === '') {
            update_post_meta($post_id, '_ifp_group_visibility', $resolved_status === 'publish' ? 'public' : 'internal');
        }

        update_post_meta($post_id, '_ifp_dash_team_id', $team_id);
        update_post_meta($post_id, '_ifp_dash_season_id', absint($a['season_id'] ?? 0));
        update_post_meta($post_id, '_ifp_dash_league_id', absint($a['league_id'] ?? 0));
        update_post_meta($post_id, '_ifp_dash_product_id', absint($a['product_id'] ?? 0));
        update_post_meta($post_id, '_ifp_dash_program_type_id', absint($a['program_type_id'] ?? 0));
        update_post_meta($post_id, '_ifp_dash_status', sanitize_text_field($a['status'] ?? ''));
        update_post_meta($post_id, '_ifp_dash_payload', $team);
        update_post_meta($post_id, '_ifp_dash_last_sync', current_time('mysql'));
        if (class_exists('IFP_Dash_Production_Import')) {
            IFP_Dash_Production_Import::sync_group_registration_url($post_id, $team);
        }

        return $post_id;
    }

    private static function find_group($team_id) {
        $posts = get_posts([
            'post_type' => 'ifp_group',
            'post_status' => 'any',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_key' => '_ifp_dash_team_id',
            'meta_value' => absint($team_id),
        ]);
        return $posts ? absint($posts[0]) : 0;
    }

    public static function sync_participants_action() {
        if (!current_user_can('manage_options')) wp_die('Permission denied.');
        $group_id = absint($_GET['group_id'] ?? $_POST['group_id'] ?? 0);
        check_admin_referer('ifp_dash_sync_participants_' . $group_id);
        $result = self::sync_group_participants($group_id, true);
        $redirect = get_edit_post_link($group_id, 'url') ?: admin_url('edit.php?post_type=ifp_group');
        if (is_wp_error($result)) {
            $redirect = add_query_arg('ifp_sync_error', rawurlencode($result->get_error_message()), $redirect);
        } else {
            $redirect = add_query_arg('ifp_synced', absint($result['count'] ?? 0), $redirect);
        }
        wp_safe_redirect($redirect);
        exit;
    }

    public static function sync_group_participants($group_id, $force = false) {
        $group_id = absint($group_id);
        if (!$group_id || get_post_type($group_id) !== 'ifp_group') return new WP_Error('ifp_invalid_group', 'Invalid Production Group.');
        if (!self::connector_ready()) return new WP_Error('ifp_dash_not_ready', 'The Dash Connector is not ready.');
        $team_id = absint(get_post_meta($group_id, '_ifp_dash_team_id', true));
        if (!$team_id) return new WP_Error('ifp_no_team', 'This Group is not connected to a Dash Team.');

        $result = IFDC_Client::get_registered_customers($team_id, ['force' => $force, 'cache_ttl' => 300]);
        if (is_wp_error($result)) return $result;
        $customers = is_array($result['data'] ?? null) ? $result['data'] : [];

        $existing_rows = get_post_meta($group_id, '_ifp_group_participants', true);
        if (!is_array($existing_rows)) $existing_rows = [];
        $existing_notes = [];
        $manual_rows = [];
        foreach ($existing_rows as $row) {
            $dash_id = sanitize_text_field((string) ($row['dash_customer_id'] ?? ''));
            if ($dash_id !== '') $existing_notes[$dash_id] = sanitize_text_field((string) ($row['notes'] ?? ''));
            else $manual_rows[] = $row;
        }

        $participant_ids = [];
        $rows = [];
        foreach ($customers as $customer) {
            if (!is_array($customer)) continue;
            $participant_id = IFP_Participants::upsert($customer);
            if (is_wp_error($participant_id)) continue;
            $participant_ids[] = absint($participant_id);
            $row = IFP_Participants::row($participant_id);
            $dash_id = (string) ($row['dash_customer_id'] ?? '');
            if (isset($existing_notes[$dash_id])) $row['notes'] = $existing_notes[$dash_id];
            $rows[] = $row;
        }

        // Manual participants are retained; Dash-linked participants missing from the current roster are unlinked.
        $rows = array_merge($rows, $manual_rows);
        update_post_meta($group_id, '_ifp_group_participant_ids', array_values(array_unique($participant_ids)));
        update_post_meta($group_id, '_ifp_group_participants', $rows);
        update_post_meta($group_id, '_ifp_group_participant_count', count($rows));
        update_post_meta($group_id, '_ifp_dash_roster_count', count($customers));
        update_post_meta($group_id, '_ifp_dash_participants_last_sync', current_time('mysql'));
        update_post_meta($group_id, '_ifp_dash_registered_customers_payload', $customers);

        return ['count' => count($customers), 'participant_ids' => $participant_ids];
    }

    private static function infer_group_type($name) {
        $name = strtolower($name);
        $map = [
            'large group' => 'Large Group', 'small group' => 'Small Group', 'solo' => 'Soloist',
            'duet' => 'Duet', 'trio' => 'Trio', 'ensemble' => 'Ensemble',
            'opening' => 'Opening Number', 'closing' => 'Closing Number', 'guest' => 'Guest Performance',
        ];
        foreach ($map as $needle => $type) if (strpos($name, $needle) !== false) return $type;
        return 'Other';
    }

    private static function customer_name($attrs, $customer) {
        $full = self::first_value($attrs, ['name', 'full_name', 'display_name']);
        if ($full !== '') return $full;
        $first = self::first_value($attrs, ['first_name', 'firstName', 'fname']);
        $last = self::first_value($attrs, ['last_name', 'lastName', 'lname']);
        $name = trim($first . ' ' . $last);
        return $name !== '' ? $name : 'Customer ' . (string) ($customer['id'] ?? '');
    }

    private static function first_value($attrs, $keys) {
        foreach ($keys as $key) {
            if (isset($attrs[$key]) && !is_array($attrs[$key]) && $attrs[$key] !== '') return (string) $attrs[$key];
        }
        return '';
    }

    private static function day_name($day) {
        $days = ['su' => 'Sun', 'm' => 'Mon', 'mo' => 'Mon', 'tu' => 'Tue', 'w' => 'Wed', 'we' => 'Wed', 'th' => 'Thu', 'f' => 'Fri', 'fr' => 'Fri', 'sa' => 'Sat'];
        $day = strtolower((string) $day);
        return $days[$day] ?? strtoupper($day);
    }
}
