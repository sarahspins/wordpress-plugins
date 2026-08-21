<?php
if (!defined('ABSPATH')) exit;

class IFDC_Admin {
    const CAP_EXPLORE = 'ifdc_use_object_explorer';
    const CAP_ASSIGN_EVENTS = 'ifdc_assign_events';
    const CAPABILITY_VERSION_OPTION = 'ifdc_capability_version';
    const CAPABILITY_VERSION = '1';

    public static function init() {
        self::maybe_install_capabilities();
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
        add_action('wp_ajax_ifdc_test_connection', [__CLASS__, 'ajax_test']);
        add_action('wp_ajax_ifdc_explore', [__CLASS__, 'ajax_explore']);
        add_action('wp_ajax_ifdc_delete_team', [__CLASS__, 'ajax_delete_team']);
        add_action('wp_ajax_ifdc_clear_schema', [__CLASS__, 'ajax_clear_schema']);
        add_action('admin_notices', [__CLASS__, 'notice']);
    }

    public static function menu() {
        add_menu_page(
            'Ice & Field Dash Connector',
            'Dash Connector',
            self::CAP_EXPLORE,
            'ifdc-dashboard',
            [__CLASS__, 'dashboard'],
            'dashicons-rest-api',
            25
        );
        add_submenu_page('ifdc-dashboard', 'Dash Status', 'Status', self::CAP_EXPLORE, 'ifdc-dashboard', [__CLASS__, 'dashboard']);
        add_submenu_page('ifdc-dashboard', 'Dash Settings', 'Settings', 'manage_options', 'ifdc-settings', [__CLASS__, 'settings_page']);
        IFDC_GitHub_Updater::menu();
        IFDC_Event_Assignment::menu();
        add_submenu_page('ifdc-dashboard', 'Dash Object Explorer', 'Object Explorer', self::CAP_EXPLORE, 'ifdc-explorer', [__CLASS__, 'explorer_page']);
        add_submenu_page('ifdc-dashboard', 'Discovered Dash Schema', 'Schema', 'manage_options', 'ifdc-schema', [__CLASS__, 'schema_page']);
    }

    public static function install_capabilities() {
        foreach (['administrator', 'editor'] as $role_name) {
            $role = get_role($role_name);
            if (!$role) continue;
            $role->add_cap(self::CAP_EXPLORE);
            $role->add_cap(self::CAP_ASSIGN_EVENTS);
        }
        update_option(self::CAPABILITY_VERSION_OPTION, self::CAPABILITY_VERSION, false);
    }

    private static function maybe_install_capabilities() {
        if ((string) get_option(self::CAPABILITY_VERSION_OPTION, '') === self::CAPABILITY_VERSION) return;
        self::install_capabilities();
    }

    public static function register_settings() {
        register_setting('ifdc_group', IFDC_Client::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [__CLASS__, 'sanitize'],
            'default' => IFDC_Client::defaults(),
        ]);
        register_setting('ifdc_group', IFDC_GitHub_Updater::OPTION, [
            'type'=>'array',
            'sanitize_callback'=>['IFDC_GitHub_Updater', 'sanitize'],
            'default'=>IFDC_GitHub_Updater::defaults(),
        ]);
    }

    public static function sanitize($input) {
        $old = IFDC_Client::settings();
        $defaults = IFDC_Client::defaults();

        $clean = [
            'enabled' => !empty($input['enabled']) ? '1' : '0',
            'client_id' => sanitize_text_field($input['client_id'] ?? ''),
            'client_secret' => sanitize_text_field($input['client_secret'] ?? ''),
            'company' => sanitize_text_field($input['company'] ?? $defaults['company']),
            'api_base' => esc_url_raw($input['api_base'] ?? $defaults['api_base']),
            'auth_url' => esc_url_raw($input['auth_url'] ?? $defaults['auth_url']),
            'timeout' => max(5, min(60, absint($input['timeout'] ?? $defaults['timeout']))),
            'cache_ttl' => max(0, min(DAY_IN_SECONDS, absint($input['cache_ttl'] ?? $defaults['cache_ttl']))),
        ];

        if ($clean['client_secret'] === '' && !empty($old['client_secret'])) {
            $clean['client_secret'] = $old['client_secret'];
        }

        IFDC_Client::clear_token();
        return $clean;
    }

    public static function assets($hook) {
        if (strpos($hook, 'ifdc-') === false) return;
        wp_enqueue_style('ifdc-admin', IFDC_URL . 'assets/admin.css', [], IFDC_VERSION);
        wp_enqueue_script('ifdc-admin', IFDC_URL . 'assets/admin.js', ['jquery'], IFDC_VERSION, true);
        wp_localize_script('ifdc-admin', 'IFDC', [
            'ajax' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ifdc_admin'),
            'canDeleteTeams' => current_user_can('manage_options'),
        ]);
    }

    public static function notice() {
        if (!current_user_can('manage_options') || IFDC_Client::is_configured()) return;
        $screen = get_current_screen();
        if ($screen && strpos((string) $screen->id, 'ifdc-') !== false) return;

        echo '<div class="notice notice-warning"><p><strong>Ice & Field Dash Connector:</strong> Dash credentials are not configured. <a href="' .
            esc_url(admin_url('admin.php?page=ifdc-settings')) . '">Open settings</a>.</p></div>';
    }

    public static function dashboard() {
        $d = IFDC_Client::diagnostics();
        $token = $d['token'];
        ?>
        <div class="wrap ifdc-wrap">
            <h1>Dash Connector</h1>
            <p class="ifdc-lead">Shared Dash/DaySmart access for Ice & Field plugins.</p>

            <div class="ifdc-grid">
                <section class="ifdc-card">
                    <h2>Connection</h2>
                    <p class="ifdc-status <?php echo $d['configured'] ? 'is-ready' : 'is-warning'; ?>">
                        <?php echo $d['configured'] ? 'Configured' : 'Setup required'; ?>
                    </p>
                    <dl>
                        <dt>Company</dt><dd><?php echo esc_html($d['company'] ?: 'Not set'); ?></dd>
                        <dt>Token cached</dt><dd><?php echo $token['cached'] ? 'Yes' : 'No'; ?></dd>
                        <dt>Requests today</dt><dd><?php echo esc_html($d['requests_today']); ?></dd>
                    </dl>
                    <?php if (current_user_can('manage_options')): ?>
                        <p>
                            <button class="button button-primary" id="ifdc-test">Test Connection</button>
                            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=ifdc-settings')); ?>">Settings</a>
                        </p>
                        <div id="ifdc-test-result" class="ifdc-result" hidden></div>
                    <?php endif; ?>
                </section>

                <section class="ifdc-card">
                    <h2>Token</h2>
                    <?php if ($token['cached']): ?>
                        <dl>
                            <dt>Issued</dt><dd><?php echo esc_html(wp_date('M j, Y g:i a', $token['issued_at'])); ?></dd>
                            <dt>Expires</dt><dd><?php echo esc_html(wp_date('M j, Y g:i a', $token['expires_at'])); ?></dd>
                            <dt>Auth response</dt><dd><?php echo esc_html($token['response_ms']); ?> ms</dd>
                        </dl>
                    <?php else: ?>
                        <p>No cached token. A token is created automatically when a plugin makes its first request.</p>
                    <?php endif; ?>
                </section>

                <section class="ifdc-card">
                    <h2>Developer Tools</h2>
                    <p>Inspect live API responses without adding temporary code to another plugin.</p>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=ifdc-explorer')); ?>">Open API Explorer</a>
                    <?php if (current_user_can(self::CAP_ASSIGN_EVENTS)): ?>
                        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=ifdc-event-assignment')); ?>">Open Event Assignment</a>
                    <?php endif; ?>
                </section>
            </div>
        </div>
        <?php
    }

    public static function settings_page() {
        $s = IFDC_Client::settings();
        ?>
        <div class="wrap ifdc-wrap">
            <h1>Dash Connector Settings</h1>
            <form method="post" action="options.php">
                <?php settings_fields('ifdc_group'); ?>
                <table class="form-table">
                    <tr>
                        <th>Enable Connector</th>
                        <td><label><input type="checkbox" name="<?php echo esc_attr(IFDC_Client::OPTION); ?>[enabled]" value="1" <?php checked($s['enabled'], '1'); ?>> Allow Ice & Field plugins to use this connection</label></td>
                    </tr>
                    <tr><th>Client ID</th><td><input class="regular-text" name="<?php echo esc_attr(IFDC_Client::OPTION); ?>[client_id]" value="<?php echo esc_attr($s['client_id']); ?>"></td></tr>
                    <tr>
                        <th>Client Secret</th>
                        <td>
                            <input type="password" class="regular-text" name="<?php echo esc_attr(IFDC_Client::OPTION); ?>[client_secret]" value="">
                            <p class="description"><?php echo $s['client_secret'] ? 'A secret is saved. Leave blank to keep it.' : 'No secret is currently saved.'; ?></p>
                        </td>
                    </tr>
                    <tr><th>Company</th><td><input class="regular-text" name="<?php echo esc_attr(IFDC_Client::OPTION); ?>[company]" value="<?php echo esc_attr($s['company']); ?>"></td></tr>
                    <tr><th>API Base</th><td><input class="large-text code" name="<?php echo esc_attr(IFDC_Client::OPTION); ?>[api_base]" value="<?php echo esc_attr($s['api_base']); ?>"></td></tr>
                    <tr><th>Authentication URL</th><td><input class="large-text code" name="<?php echo esc_attr(IFDC_Client::OPTION); ?>[auth_url]" value="<?php echo esc_attr($s['auth_url']); ?>"></td></tr>
                    <tr><th>Timeout</th><td><input type="number" min="5" max="60" name="<?php echo esc_attr(IFDC_Client::OPTION); ?>[timeout]" value="<?php echo esc_attr($s['timeout']); ?>"> seconds</td></tr>
                    <tr><th>Default API Cache</th><td><input type="number" min="0" max="86400" name="<?php echo esc_attr(IFDC_Client::OPTION); ?>[cache_ttl]" value="<?php echo esc_attr($s['cache_ttl']); ?>"> seconds<p class="description">Use 0 to disable response caching. Consumers may override this per request.</p></td></tr>
                </table>
                <?php IFDC_GitHub_Updater::render_settings(); ?>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }


    public static function endpoint_catalog() {
        /**
         * Presets are intentionally editable after selection. Dash installations can
         * expose different resource names, so the Explorer never hides the actual path.
         */
        return [
            'custom' => [
                'label' => 'Custom request',
                'endpoint' => '',
                'query' => '',
                'description' => 'Enter any read-only Dash/DaySmart endpoint.',
            ],
            'events' => [
                'label' => 'Events',
                'endpoint' => 'events',
                'query' => '',
                'description' => 'Schedule events. Add date, resource, or event filters as needed.',
            ],
            'event_registrants' => [
                'label' => 'Event registrants',
                'endpoint' => 'events/{event_id}/registrants',
                'query' => '',
                'description' => 'Registrants for one event. Replace {event_id}.',
            ],
            'event_registrations' => [
                'label' => 'Event registrations',
                'endpoint' => 'event-registrations',
                'query' => '',
                'description' => 'Registration records. Add supported filters after inspecting the response.',
            ],
            'customers' => [
                'label' => 'Customers',
                'endpoint' => 'customers',
                'query' => '',
                'description' => 'Customer records. Use a specific ID when possible.',
            ],
            'customer_events' => [
                'label' => 'Customer registered events',
                'endpoint' => 'customers/{customer_id}/relationships/registeredEvents',
                'query' => '',
                'description' => 'Registered-event relationships for one customer.',
            ],
            'resources' => [
                'label' => 'Resources',
                'endpoint' => 'resources',
                'query' => '',
                'description' => 'Facility resources such as rinks or rooms.',
            ],
            'leagues' => [
                'label' => 'Leagues / programs',
                'endpoint' => 'leagues',
                'query' => '',
                'description' => 'League or program-style records exposed by this Dash account.',
            ],
            'seasons' => [
                'label' => 'Seasons',
                'endpoint' => 'seasons',
                'query' => '',
                'description' => 'Season records, when available through the account API.',
            ],
            'levels' => [
                'label' => 'Levels',
                'endpoint' => 'levels',
                'query' => '',
                'description' => 'Program or class levels, when available through the account API.',
            ],
            'classes' => [
                'label' => 'Classes',
                'endpoint' => 'classes',
                'query' => '',
                'description' => 'Class records, when available through the account API.',
            ],
            'teams' => [
                'label' => 'Teams / groups',
                'endpoint' => 'teams',
                'query' => '',
                'description' => 'Team or group records.',
            ],
            'team' => [
                'label' => 'Specific team',
                'endpoint' => 'teams/{team_id}',
                'query' => '',
                'description' => 'One team record. Replace {team_id}.',
            ],
            'team_registered_customers' => [
                'label' => 'Team registered customers',
                'endpoint' => 'teams/{team_id}/registeredCustomers',
                'query' => '',
                'description' => 'Clean roster endpoint for a team. Replace {team_id}.',
            ],
            'team_with_roster' => [
                'label' => 'Team with included roster',
                'endpoint' => 'teams/{team_id}',
                'query' => 'include=registeredCustomers',
                'description' => 'Team record with registered customers included when supported.',
            ],
            'rsvp_states' => [
                'label' => 'RSVP states',
                'endpoint' => 'rsvpStates',
                'query' => '',
                'description' => 'RSVP/check-in state definitions, when exposed.',
            ],
            'checkin_events' => [
                'label' => 'Check-in events',
                'endpoint' => 'checkInEvents',
                'query' => '',
                'description' => 'Check-in activity, when exposed.',
            ],
        ];
    }

    public static function explorer_page() {
        $catalog = self::endpoint_catalog();
        ?>
        <div class="wrap ifdc-wrap">
            <div class="ifdc-explorer-heading">
                <div>
                    <h1>Dash Object Explorer</h1>
                    <p class="ifdc-lead">Browse Dash objects, follow discovered relationships, and retain access to the complete raw response.</p>
                </div>
                <span class="ifdc-readonly-badge"><?php echo current_user_can('manage_options') ? 'Read-only browsing • Guarded team deletion' : 'Read-only GET requests'; ?></span>
            </div>

            <div class="ifdc-explorer-layout">
                <aside class="ifdc-card ifdc-explorer-controls">
                    <div class="ifdc-object-shortcuts">
                        <h3>Objects</h3>
                        <button type="button" class="ifdc-object-link is-active" data-endpoint="teams"><span class="dashicons dashicons-groups"></span> Teams</button>
                        <button type="button" class="ifdc-object-link" data-endpoint="events"><span class="dashicons dashicons-calendar-alt"></span> Events</button>
                        <button type="button" class="ifdc-object-link" data-endpoint="customers"><span class="dashicons dashicons-admin-users"></span> Customers</button>
                        <button type="button" class="ifdc-object-link" data-endpoint="leagues"><span class="dashicons dashicons-category"></span> Leagues</button>
                        <button type="button" class="ifdc-object-link" data-endpoint="seasons"><span class="dashicons dashicons-archive"></span> Seasons</button>
                        <button type="button" class="ifdc-object-link" data-endpoint="resources"><span class="dashicons dashicons-location-alt"></span> Resources</button>
                    </div>

                    <label for="ifdc-preset"><strong>Request preset</strong></label>
                    <select id="ifdc-preset" class="widefat">
                        <?php foreach ($catalog as $key => $item): ?>
                            <option
                                value="<?php echo esc_attr($key); ?>"
                                data-endpoint="<?php echo esc_attr($item['endpoint']); ?>"
                                data-query="<?php echo esc_attr($item['query']); ?>"
                                data-description="<?php echo esc_attr($item['description']); ?>"
                            ><?php echo esc_html($item['label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p id="ifdc-preset-description" class="description">Enter any read-only Dash/DaySmart endpoint.</p>

                    <label for="ifdc-endpoint"><strong>Endpoint or full approved URL</strong></label>
                    <input id="ifdc-endpoint" class="large-text code" value="events">
                    <p class="description">Placeholders such as <code>{event_id}</code> must be replaced before running.</p>

                    <label for="ifdc-query"><strong>Query parameters</strong></label>
                    <textarea id="ifdc-query" class="large-text code" rows="7" placeholder="start_date=2026-07-17&#10;resource_id=1"></textarea>
                    <p class="description">One <code>key=value</code> pair per line. The configured company is included automatically.</p>

                    <div class="ifdc-control-actions">
                        <button class="button button-primary" id="ifdc-run">Run Request</button>
                        <button class="button" id="ifdc-clear" type="button">Clear</button>
                    </div>

                    <div class="ifdc-history">
                        <h3>Request history</h3>
                        <div id="ifdc-history-list"><p class="description">Requests from this browser session appear here.</p></div>
                    </div>
                </aside>

                <main class="ifdc-explorer-main">
                    <div id="ifdc-explorer-meta" class="ifdc-result" hidden></div>

                    <section class="ifdc-card ifdc-browser-card">
                        <div class="ifdc-card-heading">
                            <div>
                                <p class="ifdc-eyebrow">Response browser</p>
                                <h2 id="ifdc-browser-title">No response yet</h2>
                            </div>
                            <div class="ifdc-view-toggle" role="group" aria-label="Response view">
                                <button class="button is-active" type="button" data-ifdc-view="browser">Browser</button>
                                <button class="button" type="button" data-ifdc-view="raw">Raw JSON</button>
                            </div>
                        </div>

                        <div id="ifdc-browser-view">
                            <div id="ifdc-response-summary" class="ifdc-summary-grid"></div>
                            <div id="ifdc-table-wrap" class="ifdc-table-wrap">
                                <div class="ifdc-empty-state">
                                    <span class="dashicons dashicons-rest-api"></span>
                                    <h3>Choose a resource and run a request</h3>
                                    <p>JSON:API collections will be converted into a searchable table automatically.</p>
                                </div>
                            </div>
                        </div>

                        <div id="ifdc-raw-view" hidden>
                            <div class="ifdc-raw-toolbar">
                                <button class="button" id="ifdc-copy-json" type="button">Copy JSON</button>
                                <span id="ifdc-copy-status" class="description"></span>
                            </div>
                            <pre id="ifdc-explorer-output" class="ifdc-json">No request has been run.</pre>
                        </div>
                    </section>

                    <section id="ifdc-record-panel" class="ifdc-card ifdc-record-panel" hidden>
                        <div class="ifdc-card-heading">
                            <div>
                                <p class="ifdc-eyebrow">Selected record</p>
                                <h2 id="ifdc-record-title">Record</h2>
                            </div>
                            <button class="button" id="ifdc-close-record" type="button">Close</button>
                        </div>
                        <div id="ifdc-record-content"></div>
                    </section>
                </main>
            </div>
        </div>
        <?php
    }


    public static function schema_page() {
        $schema = get_option('ifdc_discovered_schema', []);
        if (!is_array($schema)) $schema = [];
        ?>
        <div class="wrap ifdc-wrap">
            <div class="ifdc-explorer-heading">
                <div>
                    <h1>Discovered Dash Schema</h1>
                    <p class="ifdc-lead">A living reference built automatically from successful Object Explorer requests.</p>
                </div>
                <button class="button" id="ifdc-clear-schema" type="button">Clear Discovered Schema</button>
            </div>
            <div id="ifdc-schema-message" class="ifdc-result" hidden></div>
            <?php if (!$schema): ?>
                <section class="ifdc-card ifdc-empty-state">
                    <span class="dashicons dashicons-database-view"></span>
                    <h2>No objects discovered yet</h2>
                    <p>Run requests in the Object Explorer. Attribute and relationship names will be recorded here automatically.</p>
                </section>
            <?php else: ?>
                <div class="ifdc-schema-grid">
                    <?php foreach ($schema as $type => $item): ?>
                        <section class="ifdc-card ifdc-schema-card">
                            <div class="ifdc-card-heading">
                                <div><p class="ifdc-eyebrow">Dash object</p><h2><?php echo esc_html($type); ?></h2></div>
                                <span class="ifdc-readonly-badge"><?php echo esc_html(absint($item['samples'] ?? 0)); ?> samples</span>
                            </div>
                            <h3>Attributes</h3>
                            <div class="ifdc-schema-chips">
                                <?php foreach ((array)($item['attributes'] ?? []) as $field): ?><code><?php echo esc_html($field); ?></code><?php endforeach; ?>
                            </div>
                            <h3>Relationships</h3>
                            <div class="ifdc-schema-chips">
                                <?php foreach ((array)($item['relationships'] ?? []) as $field): ?><code><?php echo esc_html($field); ?></code><?php endforeach; ?>
                            </div>
                            <?php if (!empty($item['endpoints'])): ?>
                                <h3>Seen at</h3>
                                <ul class="ifdc-schema-endpoints"><?php foreach ((array)$item['endpoints'] as $endpoint): ?><li><code><?php echo esc_html($endpoint); ?></code></li><?php endforeach; ?></ul>
                            <?php endif; ?>
                        </section>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function learn_schema($payload, $endpoint) {
        $root = is_array($payload) && array_key_exists('data', $payload) ? $payload['data'] : $payload;
        $records = is_array($root) && array_keys($root) === range(0, count($root)-1) ? $root : [$root];
        $schema = get_option('ifdc_discovered_schema', []);
        if (!is_array($schema)) $schema = [];
        foreach ($records as $record) {
            if (!is_array($record) || empty($record['type'])) continue;
            $type = sanitize_key($record['type']);
            if (!isset($schema[$type])) $schema[$type] = ['attributes'=>[], 'relationships'=>[], 'endpoints'=>[], 'samples'=>0];
            $schema[$type]['samples'] = absint($schema[$type]['samples']) + 1;
            $schema[$type]['attributes'] = array_values(array_unique(array_merge((array)$schema[$type]['attributes'], array_keys((array)($record['attributes'] ?? [])))));
            $schema[$type]['relationships'] = array_values(array_unique(array_merge((array)$schema[$type]['relationships'], array_keys((array)($record['relationships'] ?? [])))));
            $schema[$type]['endpoints'] = array_slice(array_values(array_unique(array_merge((array)$schema[$type]['endpoints'], [$endpoint]))), -10);
            sort($schema[$type]['attributes']); sort($schema[$type]['relationships']);
        }
        ksort($schema);
        update_option('ifdc_discovered_schema', $schema, false);
    }

    public static function ajax_clear_schema() {
        check_ajax_referer('ifdc_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Permission denied.'], 403);
        delete_option('ifdc_discovered_schema');
        wp_send_json_success(['message' => 'Discovered schema cleared.']);
    }

    public static function ajax_test() {
        check_ajax_referer('ifdc_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Permission denied.'], 403);

        $result = IFDC_Client::test_connection();
        if (is_wp_error($result)) {
            wp_send_json_error([
                'message' => $result->get_error_message(),
                'details' => $result->get_error_data(),
            ]);
        }
        wp_send_json_success($result);
    }

    public static function ajax_explore() {
        check_ajax_referer('ifdc_admin', 'nonce');
        if (!current_user_can(self::CAP_EXPLORE)) wp_send_json_error(['message' => 'Permission denied.'], 403);

        $endpoint = sanitize_text_field(wp_unslash($_POST['endpoint'] ?? ''));
        $raw_query = sanitize_textarea_field(wp_unslash($_POST['query'] ?? ''));
        if ($endpoint === '') wp_send_json_error(['message' => 'Enter an endpoint.']);

        $query = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw_query) as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '=') === false) continue;
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if ($key !== '') $query[sanitize_key($key)] = sanitize_text_field($value);
        }

        $result = IFDC_Client::get($endpoint, $query, ['force' => !empty($_POST['force'])]);
        if (is_wp_error($result)) {
            wp_send_json_error([
                'message' => $result->get_error_message(),
                'details' => $result->get_error_data(),
            ]);
        }

        self::learn_schema($result['data'] ?? [], $endpoint);
        wp_send_json_success($result);
    }

    public static function ajax_delete_team() {
        check_ajax_referer('ifdc_admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Only a WordPress administrator can delete a team.'], 403);
        }

        $team_id = absint($_POST['team_id'] ?? 0);
        $confirmation = sanitize_text_field(wp_unslash($_POST['confirmation'] ?? ''));
        $expected_confirmation = 'DELETE TEAM #' . $team_id;
        if (!$team_id || !hash_equals($expected_confirmation, $confirmation)) {
            wp_send_json_error(['message' => 'The typed confirmation did not exactly match the team ID.'], 400);
        }

        $team_payload = IFDC_Client::get_team($team_id, [], ['force' => true, 'cache' => false]);
        if (is_wp_error($team_payload)) {
            wp_send_json_error([
                'message' => 'The team could not be freshly verified before deletion. ' . $team_payload->get_error_message(),
                'details' => $team_payload->get_error_data(),
            ], 409);
        }

        $team_record = is_array($team_payload) && isset($team_payload['data']) && is_array($team_payload['data'])
            ? $team_payload['data']
            : $team_payload;
        if (!is_array($team_record) || absint($team_record['id'] ?? 0) !== $team_id) {
            wp_send_json_error(['message' => 'Dash returned a different or invalid team record. Nothing was deleted.'], 409);
        }
        $team_type = sanitize_key($team_record['type'] ?? 'teams');
        if ($team_type && $team_type !== 'teams' && $team_type !== 'team') {
            wp_send_json_error(['message' => 'The selected record is not a Dash team. Nothing was deleted.'], 409);
        }

        $team_attributes = is_array($team_record['attributes'] ?? null) ? $team_record['attributes'] : [];
        $team_name = sanitize_text_field($team_attributes['name'] ?? $team_attributes['desc'] ?? ('Team #' . $team_id));

        $roster_payload = IFDC_Client::get_data(
            'teams/' . $team_id . '/registeredCustomers',
            ['page[size]' => 1],
            ['force' => true, 'cache' => false]
        );
        if (is_wp_error($roster_payload)) {
            wp_send_json_error([
                'message' => 'The team roster could not be checked, so deletion was blocked. ' . $roster_payload->get_error_message(),
                'details' => $roster_payload->get_error_data(),
            ], 409);
        }
        $roster_records = is_array($roster_payload) && array_key_exists('data', $roster_payload)
            ? $roster_payload['data']
            : $roster_payload;
        if (is_array($roster_records) && !empty($roster_records)) {
            wp_send_json_error([
                'message' => 'Deletion blocked: this team still has registered customers. Remove or transfer its roster in Dash first.',
            ], 409);
        }

        $events_payload = IFDC_Client::get_data(
            'events',
            ['filter[hteam_id]' => $team_id, 'page[size]' => 5],
            ['force' => true, 'cache' => false]
        );
        if (is_wp_error($events_payload)) {
            wp_send_json_error([
                'message' => 'Schedule-event associations could not be checked, so deletion was blocked. ' . $events_payload->get_error_message(),
                'details' => $events_payload->get_error_data(),
            ], 409);
        }
        $event_records = is_array($events_payload) && array_key_exists('data', $events_payload)
            ? $events_payload['data']
            : $events_payload;
        $matching_events = [];
        foreach ((array) $event_records as $event_record) {
            if (!is_array($event_record)) continue;
            $event_attributes = is_array($event_record['attributes'] ?? null) ? $event_record['attributes'] : $event_record;
            if (absint($event_attributes['hteam_id'] ?? 0) === $team_id) {
                $matching_events[] = absint($event_record['id'] ?? 0);
            }
        }
        if ($matching_events) {
            wp_send_json_error([
                'message' => 'Deletion blocked: this team is still assigned to schedule event' . (count($matching_events) === 1 ? '' : 's') . ' #' . implode(', #', array_filter($matching_events)) . '. Reassign those events first.',
            ], 409);
        }
        if (is_array($event_records) && !empty($event_records)) {
            wp_send_json_error([
                'message' => 'Dash returned unrelated events while checking this team, so deletion was blocked rather than trusting an unsupported filter.',
            ], 409);
        }

        $result = IFDC_Client::delete_team($team_id);
        if (is_wp_error($result)) {
            wp_send_json_error([
                'message' => $result->get_error_message(),
                'details' => $result->get_error_data(),
            ], 409);
        }

        $verified = false;
        $verification_note = '';
        $verify = IFDC_Client::get_team($team_id, [], ['force' => true, 'cache' => false]);
        if (is_wp_error($verify)) {
            $verify_data = $verify->get_error_data();
            $verified = is_array($verify_data) && absint($verify_data['status'] ?? 0) === 404;
            if (!$verified) $verification_note = ' Dash accepted the deletion, but the follow-up lookup could not confirm it.';
        } else {
            wp_send_json_error([
                'message' => 'Dash accepted the request, but the team still exists in a fresh lookup. Do not retry until it is checked directly in Dash.',
            ], 409);
        }

        $log = get_option('ifdc_team_deletion_log', []);
        if (!is_array($log)) $log = [];
        array_unshift($log, [
            'team_id' => $team_id,
            'team_name' => $team_name,
            'user_id' => get_current_user_id(),
            'deleted_at' => current_time('mysql'),
            'verified' => $verified,
        ]);
        update_option('ifdc_team_deletion_log', array_slice($log, 0, 50), false);

        wp_send_json_success([
            'message' => $team_name . ' (#' . $team_id . ') was permanently deleted.' . $verification_note,
            'team_id' => $team_id,
            'team_name' => $team_name,
            'verified' => $verified,
        ]);
    }
}
