<?php
if (!defined('ABSPATH')) exit;

/**
 * Monitoring configuration, activity logs, and guarded daily synchronization
 * for individually opted-in imported Seasons.
 */
class IFPROG_Monitoring {
    const OPTION = 'ifprog_monitoring_settings';
    const SETTINGS_GROUP = 'ifprog_monitoring_group';
    const FOUNDATION_VERSION_OPTION = 'ifprog_monitoring_foundation_version';
    const FOUNDATION_VERSION = '1.9.2';
    const MAX_FAMILIES = 10;
    const AUTOMATIC_SYNC_HOOK = 'ifprog_daily_imported_season_sync';
    const AUTOMATIC_SYNC_LOCK = 'ifprog_daily_imported_season_sync_lock';
    const AUTOMATIC_SYNC_RESULT = 'ifprog_daily_imported_season_sync_result';

    public static function init() {
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_init', [__CLASS__, 'maybe_install']);
        add_action('update_option_' . self::OPTION, [__CLASS__, 'settings_updated'], 10, 3);
        add_action('init', [__CLASS__, 'ensure_automatic_sync_schedule']);
        add_action(self::AUTOMATIC_SYNC_HOOK, [__CLASS__, 'run_automatic_season_sync']);
    }

    public static function install() {
        IFPROG_Audit::install();
        add_option(self::OPTION, self::defaults(), '', false);

        $installed = (string) get_option(self::FOUNDATION_VERSION_OPTION, '');
        if (version_compare($installed ?: '0', self::FOUNDATION_VERSION, '<')) {
            IFPROG_Audit::record(
                'foundation_ready',
                'Guarded daily synchronization is available for individually opted-in imported Seasons.',
                ['source' => 'monitoring', 'severity' => 'success']
            );
            update_option(self::FOUNDATION_VERSION_OPTION, self::FOUNDATION_VERSION, false);
        }
    }

    public static function maybe_install() {
        self::install();
    }

    public static function ensure_automatic_sync_schedule() {
        if (wp_next_scheduled(self::AUTOMATIC_SYNC_HOOK)) return;

        $timezone = wp_timezone();
        $next = new DateTimeImmutable('tomorrow 04:15:00', $timezone);
        wp_schedule_event($next->getTimestamp(), 'daily', self::AUTOMATIC_SYNC_HOOK);
    }

    public static function clear_automatic_sync_schedule() {
        wp_clear_scheduled_hook(self::AUTOMATIC_SYNC_HOOK);
        delete_transient(self::AUTOMATIC_SYNC_LOCK);
    }

    public static function run_automatic_season_sync() {
        if (get_transient(self::AUTOMATIC_SYNC_LOCK)) return;
        set_transient(self::AUTOMATIC_SYNC_LOCK, 1, 30 * MINUTE_IN_SECONDS);

        $summary = [
            'started_at' => time(),
            'finished_at' => 0,
            'eligible_seasons' => 0,
            'checked' => 0,
            'synced' => 0,
            'skipped_closed' => 0,
            'errors' => [],
        ];

        try {
            if (!IFPROG_Dash::ready()) {
                throw new Exception('The shared Dash Connector is not configured.');
            }

            $season_posts = get_posts([
                'post_type' => 'ifprog_season',
                'post_status' => array_keys(get_post_stati()),
                'posts_per_page' => -1,
                'fields' => 'ids',
                'meta_query' => [
                    'relation' => 'AND',
                    [
                        'key' => '_ifprog_automatic_sync',
                        'value' => '1',
                    ],
                    [
                        'key' => '_ifprog_dash_season_id',
                        'value' => 0,
                        'compare' => '>',
                        'type' => 'NUMERIC',
                    ],
                ],
            ]);
            $summary['eligible_seasons'] = count($season_posts);
            if (!$season_posts) return self::finish_automatic_sync($summary);

            $season_result = IFPROG_Dash::seasons([
                'cache_ttl' => 300,
                'force' => true,
            ]);
            if (is_wp_error($season_result)) throw new Exception($season_result->get_error_message());

            $dash_seasons = [];
            foreach (self::collection_data($season_result) as $record) {
                $dash_id = absint($record['id'] ?? 0);
                if ($dash_id) $dash_seasons[$dash_id] = $record;
            }

            $shared_data_forced = false;
            foreach ($season_posts as $season_post_id) {
                $season_post_id = absint($season_post_id);
                $dash_id = absint(get_post_meta($season_post_id, '_ifprog_dash_season_id', true));
                $record = $dash_seasons[$dash_id] ?? null;
                update_post_meta($season_post_id, '_ifprog_automatic_sync_last_checked', current_time('mysql'));
                $summary['checked']++;

                if (!$record) {
                    $summary['errors'][] = 'Dash Season #' . $dash_id . ' was not found.';
                    continue;
                }
                if (!self::registration_is_open($record)) {
                    $summary['skipped_closed']++;
                    continue;
                }

                $preview = IFPROG_Preview::build($record, true, !$shared_data_forced);
                $shared_data_forced = true;
                if (is_wp_error($preview)) {
                    $summary['errors'][] = get_the_title($season_post_id) . ': ' . $preview->get_error_message();
                    continue;
                }

                $team_ids = [];
                $level_ids = [];
                foreach ((array) ($preview['rows'] ?? []) as $row) {
                    if (empty($row['eligible'])) continue;
                    if (($row['row_type'] ?? 'team') === 'level') {
                        $level_ids[] = absint($row['league_id'] ?? 0);
                    } else {
                        $team_ids[] = absint($row['team_id'] ?? 0);
                    }
                }
                $team_ids = array_values(array_filter(array_unique($team_ids)));
                $level_ids = array_values(array_filter(array_unique($level_ids)));
                if (!$team_ids && !$level_ids) {
                    $summary['errors'][] = get_the_title($season_post_id) . ': no eligible Dash offerings were found.';
                    continue;
                }

                $update_names = get_post_meta($season_post_id, '_ifprog_automatic_sync_names', true) === '1';
                $update_descriptions = get_post_meta($season_post_id, '_ifprog_automatic_sync_descriptions', true) === '1';
                $presentation_updates = [
                    'season_title' => $update_names,
                    'season_description' => $update_descriptions,
                    'level_titles' => $update_names,
                    'level_descriptions' => $update_descriptions,
                    'program_titles' => $update_names,
                    'program_descriptions' => $update_descriptions,
                ];

                $result = IFPROG_Sync::run(
                    $preview,
                    $team_ids,
                    get_post_status($season_post_id) === 'publish',
                    false,
                    $level_ids,
                    $presentation_updates,
                    [],
                    [],
                    true
                );
                if (is_wp_error($result)) {
                    $summary['errors'][] = get_the_title($season_post_id) . ': ' . $result->get_error_message();
                    continue;
                }
                $summary['synced']++;
            }
        } catch (Throwable $error) {
            $summary['errors'][] = $error->getMessage();
        }

        return self::finish_automatic_sync($summary);
    }

    private static function finish_automatic_sync($summary) {
        $summary['finished_at'] = time();
        update_option(self::AUTOMATIC_SYNC_RESULT, $summary, false);
        delete_transient(self::AUTOMATIC_SYNC_LOCK);
        IFPROG_Audit::record(
            $summary['errors'] ? 'automatic_sync_completed_with_warnings' : 'automatic_sync_completed',
            sprintf(
                'Daily automatic Season sync checked %d and synchronized %d opted-in Season%s.',
                absint($summary['checked']),
                absint($summary['synced']),
                absint($summary['checked']) === 1 ? '' : 's'
            ),
            [
                'source' => 'monitoring',
                'severity' => $summary['errors'] ? 'warning' : 'success',
                'context' => $summary,
            ]
        );
        return $summary;
    }

    private static function registration_is_open($record) {
        $attributes = is_array($record['attributes'] ?? null) ? $record['attributes'] : [];
        $now = current_datetime()->getTimestamp();
        $opens = IFPROG_Status::timestamp($attributes['signup_start'] ?? '');
        $closes = IFPROG_Status::timestamp($attributes['signup_end'] ?? '');
        if (!$opens && !$closes) return false;
        if ($opens && $now < $opens) return false;
        if ($closes && $now > $closes) return false;
        return true;
    }

    private static function collection_data($result) {
        if (!is_array($result)) return [];
        if (isset($result['data']) && is_array($result['data'])) return array_values($result['data']);
        return array_values(array_filter($result, 'is_array'));
    }

    public static function defaults() {
        return [
            'status' => 'paused',
            'interval' => 'daily',
            'registration_close_signal' => 1,
            'refresh_imported' => 1,
            'notification_email' => sanitize_email((string) get_option('admin_email', '')),
            'audit_retention_days' => 180,
            'families' => [
                [
                    'id' => 'learn-to-skate',
                    'label' => 'Learn to Skate',
                    'patterns' => 'learn to skate',
                    'enabled' => 1,
                ],
                [
                    'id' => 'learn-to-play',
                    'label' => 'Learn to Play',
                    'patterns' => 'learn to play',
                    'enabled' => 1,
                ],
                [
                    'id' => 'adult-leagues',
                    'label' => 'Adult Leagues',
                    'patterns' => 'adult league, d league, mixed league',
                    'enabled' => 1,
                ],
            ],
        ];
    }

    public static function settings() {
        $saved = get_option(self::OPTION, []);
        $saved = is_array($saved) ? $saved : [];
        $settings = wp_parse_args($saved, self::defaults());
        $settings['families'] = array_key_exists('families', $saved) && is_array($saved['families'])
            ? array_values($saved['families'])
            : self::defaults()['families'];
        return $settings;
    }

    public static function register_settings() {
        register_setting(self::SETTINGS_GROUP, self::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [__CLASS__, 'sanitize'],
            'default' => self::defaults(),
        ]);
    }

    public static function sanitize($input) {
        $input = is_array($input) ? $input : [];
        $defaults = self::defaults();
        $status = sanitize_key((string) ($input['status'] ?? 'paused'));
        if (!in_array($status, ['paused','ready'], true)) $status = 'paused';
        $interval = sanitize_key((string) ($input['interval'] ?? 'daily'));
        if (!in_array($interval, ['twice_daily','daily','weekly'], true)) $interval = 'daily';

        $families = [];
        $ids = [];
        foreach (array_slice((array) ($input['families'] ?? []), 0, self::MAX_FAMILIES) as $row) {
            $row = is_array($row) ? $row : [];
            $label = sanitize_text_field((string) ($row['label'] ?? ''));
            $patterns = self::sanitize_patterns($row['patterns'] ?? '');
            if ($label === '' || $patterns === '') continue;

            $base_id = sanitize_title((string) ($row['id'] ?? $label));
            if ($base_id === '') $base_id = sanitize_title($label);
            $id = $base_id;
            $suffix = 2;
            while (isset($ids[$id])) {
                $id = $base_id . '-' . $suffix;
                $suffix++;
            }
            $ids[$id] = true;
            $families[] = [
                'id' => $id,
                'label' => $label,
                'patterns' => $patterns,
                'enabled' => !empty($row['enabled']) ? 1 : 0,
            ];
        }

        $notification_email = sanitize_email((string) ($input['notification_email'] ?? $defaults['notification_email']));
        if ($notification_email === '') $notification_email = $defaults['notification_email'];

        return [
            'status' => $status,
            'interval' => $interval,
            'registration_close_signal' => !empty($input['registration_close_signal']) ? 1 : 0,
            'refresh_imported' => !empty($input['refresh_imported']) ? 1 : 0,
            'notification_email' => $notification_email,
            'audit_retention_days' => max(30, min(365, absint($input['audit_retention_days'] ?? $defaults['audit_retention_days']))),
            'families' => $families,
        ];
    }

    public static function settings_updated($old_value, $value, $option) {
        if ($option !== self::OPTION || !is_array($value)) return;

        $old_value = is_array($old_value) ? $old_value : [];
        $changed = [];
        $labels = [
            'status' => 'global status',
            'interval' => 'check frequency',
            'registration_close_signal' => 'registration-close signal',
            'refresh_imported' => 'imported Season refreshes',
            'notification_email' => 'notification email',
            'audit_retention_days' => 'activity retention',
            'families' => 'Season families',
        ];
        foreach ($labels as $key => $label) {
            if (($old_value[$key] ?? null) !== ($value[$key] ?? null)) $changed[] = $label;
        }
        if (!$changed) return;

        IFPROG_Audit::record(
            'monitoring_settings_updated',
            'Monitoring settings were updated.',
            [
                'source' => 'monitoring',
                'severity' => 'success',
                'context' => [
                    'changed' => $changed,
                    'status' => $value['status'] ?? 'paused',
                ],
            ]
        );
    }

    public static function family_for_season_name($season_name, $enabled_only = false) {
        $season_name = strtolower(remove_accents(sanitize_text_field((string) $season_name)));
        if ($season_name === '') return [];

        foreach ((array) self::settings()['families'] as $family) {
            if (!is_array($family) || empty($family['label'])) continue;
            if ($enabled_only && empty($family['enabled'])) continue;
            $patterns = preg_split('/[\r\n,]+/', (string) ($family['patterns'] ?? ''));
            foreach ((array) $patterns as $pattern) {
                $pattern = strtolower(remove_accents(trim((string) $pattern)));
                if ($pattern !== '' && strpos($season_name, $pattern) !== false) return $family;
            }
        }
        return [];
    }

    public static function settings_page() {
        if (!current_user_can('manage_options')) return;
        $settings = self::settings();
        $families = array_values((array) $settings['families']);
        $row_count = min(self::MAX_FAMILIES, max(5, count($families) + 2));
        while (count($families) < $row_count) {
            $families[] = ['id' => '', 'label' => '', 'patterns' => '', 'enabled' => 0];
        }
        ?>
        <div class="wrap ifprog-admin">
            <div class="ifprog-page-header">
                <div>
                    <p class="ifprog-kicker">Ice &amp; Field Programming</p>
                    <h1>Monitoring</h1>
                    <p class="ifprog-lead">Configure discovery monitoring and review the guarded daily synchronization used by opted-in imported Seasons.</p>
                </div>
                <div class="ifprog-actions">
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=ifprog-audit')); ?>">View Activity Log</a>
                </div>
            </div>

            <?php settings_errors(); ?>

            <section class="ifprog-monitoring-state ifprog-monitoring-state--<?php echo esc_attr($settings['status']); ?>">
                <span class="dashicons <?php echo $settings['status'] === 'ready' ? 'dashicons-yes-alt' : 'dashicons-controls-pause'; ?>"></span>
                <div>
                    <h2><?php echo $settings['status'] === 'ready' ? 'Configuration ready' : 'Monitoring paused'; ?></h2>
                    <p>General Season-family discovery monitoring remains controlled here. Daily synchronization is enabled separately on each imported Season and runs only while that Season's Dash registration window is open.</p>
                </div>
            </section>

            <form method="post" action="options.php">
                <?php settings_fields(self::SETTINGS_GROUP); ?>

                <section class="ifprog-card">
                    <h2>Monitoring controls</h2>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="ifprog-monitoring-status">Global status</label></th>
                            <td>
                                <select id="ifprog-monitoring-status" name="<?php echo esc_attr(self::OPTION); ?>[status]">
                                    <option value="paused" <?php selected($settings['status'], 'paused'); ?>>Paused</option>
                                    <option value="ready" <?php selected($settings['status'], 'ready'); ?>>Ready for scheduled monitoring</option>
                                </select>
                                <p class="description">This controls broader Season-family discovery monitoring. It does not override the per-Season automatic-sync checkbox.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="ifprog-monitoring-interval">Planned check frequency</label></th>
                            <td>
                                <select id="ifprog-monitoring-interval" name="<?php echo esc_attr(self::OPTION); ?>[interval]">
                                    <option value="twice_daily" <?php selected($settings['interval'], 'twice_daily'); ?>>Twice daily</option>
                                    <option value="daily" <?php selected($settings['interval'], 'daily'); ?>>Daily</option>
                                    <option value="weekly" <?php selected($settings['interval'], 'weekly'); ?>>Weekly</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Discovery signals</th>
                            <td class="ifprog-monitoring-checks">
                                <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[registration_close_signal]" value="1" <?php checked($settings['registration_close_signal'], 1); ?>> Look for a successor when a monitored Season reaches its registration closing date</label>
                                <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[refresh_imported]" value="1" <?php checked($settings['refresh_imported'], 1); ?>> Compare imported Current and Upcoming Seasons for class changes</label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="ifprog-monitoring-email">Notification email</label></th>
                            <td>
                                <input id="ifprog-monitoring-email" class="regular-text" type="email" name="<?php echo esc_attr(self::OPTION); ?>[notification_email]" value="<?php echo esc_attr($settings['notification_email']); ?>">
                                <p class="description">Saved for future discovery notifications. Daily Season synchronization does not currently send email.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="ifprog-monitoring-retention">Keep activity history</label></th>
                            <td><input id="ifprog-monitoring-retention" class="small-text" type="number" min="30" max="365" name="<?php echo esc_attr(self::OPTION); ?>[audit_retention_days]" value="<?php echo esc_attr($settings['audit_retention_days']); ?>"> days</td>
                        </tr>
                    </table>
                </section>

                <section class="ifprog-card">
                    <h2>Season families</h2>
                    <p>Use the words that reliably appear in each Dash Season name. Commas separate alternatives. A paused family remains visible here but will be skipped by future scheduled checks.</p>
                    <div class="ifprog-table-scroll">
                        <table class="widefat striped ifprog-family-table">
                            <thead><tr><th>Monitor</th><th>Family name</th><th>Season name contains</th></tr></thead>
                            <tbody>
                            <?php foreach ($families as $index => $family): ?>
                                <tr>
                                    <td class="ifprog-family-enabled">
                                        <input type="hidden" name="<?php echo esc_attr(self::OPTION); ?>[families][<?php echo esc_attr($index); ?>][id]" value="<?php echo esc_attr($family['id'] ?? ''); ?>">
                                        <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[families][<?php echo esc_attr($index); ?>][enabled]" value="1" <?php checked(!empty($family['enabled'])); ?>> <span class="screen-reader-text">Monitor this family</span></label>
                                    </td>
                                    <td><input class="regular-text" type="text" name="<?php echo esc_attr(self::OPTION); ?>[families][<?php echo esc_attr($index); ?>][label]" value="<?php echo esc_attr($family['label'] ?? ''); ?>" placeholder="e.g. Learn to Skate"></td>
                                    <td><input class="large-text" type="text" name="<?php echo esc_attr(self::OPTION); ?>[families][<?php echo esc_attr($index); ?>][patterns]" value="<?php echo esc_attr($family['patterns'] ?? ''); ?>" placeholder="e.g. learn to skate, LTS"></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="description">Blank rows are ignored. Up to <?php echo esc_html(self::MAX_FAMILIES); ?> families can be saved.</p>
                </section>

                <?php submit_button('Save Monitoring Settings'); ?>
            </form>
        </div>
        <?php
    }

    public static function audit_page() {
        if (!current_user_can('manage_options')) return;
        $filters = [
            'event' => sanitize_key((string) wp_unslash($_GET['ifprog_event'] ?? '')),
            'severity' => sanitize_key((string) wp_unslash($_GET['ifprog_severity'] ?? '')),
            'family' => sanitize_text_field((string) wp_unslash($_GET['ifprog_family'] ?? '')),
        ];
        $entries = IFPROG_Audit::filtered($filters);
        $page = max(1, absint($_GET['paged'] ?? 1));
        $per_page = 25;
        $total = count($entries);
        $page_entries = array_slice($entries, ($page - 1) * $per_page, $per_page);
        $settings = self::settings();
        ?>
        <div class="wrap ifprog-admin">
            <div class="ifprog-page-header">
                <div>
                    <p class="ifprog-kicker">Ice &amp; Field Programming</p>
                    <h1>Activity Log</h1>
                    <p class="ifprog-lead">A local history of monitoring settings, discovery decisions, protected syncs, and failures.</p>
                </div>
                <div class="ifprog-actions">
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=ifprog-monitoring')); ?>">Monitoring Settings</a>
                </div>
            </div>

            <section class="ifprog-card ifprog-audit-card">
                <form class="ifprog-audit-filters" method="get">
                    <input type="hidden" name="page" value="ifprog-audit">
                    <label>Event
                        <select name="ifprog_event">
                            <option value="">All events</option>
                            <?php foreach (IFPROG_Audit::event_labels() as $event => $label): ?>
                                <option value="<?php echo esc_attr($event); ?>" <?php selected($filters['event'], $event); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Result
                        <select name="ifprog_severity">
                            <option value="">All results</option>
                            <option value="success" <?php selected($filters['severity'], 'success'); ?>>Success</option>
                            <option value="info" <?php selected($filters['severity'], 'info'); ?>>Information</option>
                            <option value="warning" <?php selected($filters['severity'], 'warning'); ?>>Warning</option>
                            <option value="error" <?php selected($filters['severity'], 'error'); ?>>Error</option>
                        </select>
                    </label>
                    <label>Family
                        <select name="ifprog_family">
                            <option value="">All families</option>
                            <?php foreach ((array) $settings['families'] as $family): ?>
                                <?php if (empty($family['label'])) continue; ?>
                                <option value="<?php echo esc_attr($family['label']); ?>" <?php selected($filters['family'], $family['label']); ?>><?php echo esc_html($family['label']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button class="button">Filter</button>
                    <a class="button button-link" href="<?php echo esc_url(admin_url('admin.php?page=ifprog-audit')); ?>">Reset</a>
                </form>

                <?php if (!$page_entries): ?>
                    <div class="ifprog-audit-empty">
                        <span class="dashicons dashicons-list-view"></span>
                        <h2>No matching activity yet</h2>
                        <p>Manual discovery, exclusions, restorations, syncs, failures, and monitoring-setting changes will appear here.</p>
                    </div>
                <?php else: ?>
                    <div class="ifprog-table-scroll">
                        <table class="widefat striped ifprog-audit-table">
                            <thead><tr><th>When</th><th>Event</th><th>Details</th><th>Season</th><th>By</th></tr></thead>
                            <tbody>
                            <?php foreach ($page_entries as $entry): ?>
                                <?php
                                $event = (string) ($entry['event'] ?? '');
                                $severity = (string) ($entry['severity'] ?? 'info');
                                $season_id = absint($entry['season_id'] ?? 0);
                                $actor_id = absint($entry['actor_id'] ?? 0);
                                $actor = $actor_id ? get_userdata($actor_id) : false;
                                ?>
                                <tr>
                                    <td><?php echo esc_html(self::audit_datetime($entry['created_at'] ?? '')); ?></td>
                                    <td><span class="ifprog-audit-result ifprog-audit-result--<?php echo esc_attr($severity); ?>"><?php echo esc_html(IFPROG_Audit::event_labels()[$event] ?? ucwords(str_replace('_', ' ', $event))); ?></span></td>
                                    <td>
                                        <strong><?php echo esc_html($entry['message'] ?? ''); ?></strong>
                                        <?php self::render_audit_context($entry['context'] ?? []); ?>
                                    </td>
                                    <td>
                                        <?php if ($season_id): ?>
                                            <a href="<?php echo esc_url(get_edit_post_link($season_id)); ?>"><?php echo esc_html(get_the_title($season_id) ?: 'WordPress Season #' . $season_id); ?></a><br>
                                        <?php endif; ?>
                                        <?php if (!empty($entry['dash_season_id'])): ?><small>Dash #<?php echo esc_html(absint($entry['dash_season_id'])); ?></small><?php endif; ?>
                                        <?php if (!$season_id && empty($entry['dash_season_id'])): ?>&mdash;<?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html($actor ? $actor->display_name : 'System'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php
                    $pages = (int) ceil($total / $per_page);
                    if ($pages > 1) {
                        echo '<div class="tablenav"><div class="tablenav-pages">';
                        echo wp_kses_post(paginate_links([
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'current' => $page,
                            'total' => $pages,
                        ]));
                        echo '</div></div>';
                    }
                    ?>
                <?php endif; ?>
                <p class="description">Activity is kept for <?php echo esc_html($settings['audit_retention_days']); ?> days, with a safety limit of <?php echo esc_html(IFPROG_Audit::MAX_ENTRIES); ?> entries. Raw Dash payloads and credentials are never written here.</p>
            </section>
        </div>
        <?php
    }

    private static function sanitize_patterns($patterns) {
        $parts = preg_split('/[\r\n,]+/', (string) $patterns);
        $parts = array_values(array_unique(array_filter(array_map(function($part) {
            return sanitize_text_field(trim((string) $part));
        }, (array) $parts))));
        return implode(', ', array_slice($parts, 0, 12));
    }

    private static function audit_datetime($gmt) {
        $gmt = sanitize_text_field((string) $gmt);
        if ($gmt === '') return 'Unknown';
        return get_date_from_gmt($gmt, get_option('date_format') . ' ' . get_option('time_format'));
    }

    private static function render_audit_context($context) {
        if (!is_array($context) || !$context) return;
        $lines = [];
        foreach ($context as $key => $value) {
            $label = ucwords(str_replace('_', ' ', (string) $key));
            if (is_array($value)) {
                $value = implode(', ', array_map(function($item) {
                    return is_scalar($item) ? (string) $item : wp_json_encode($item);
                }, $value));
            } elseif (is_bool($value)) {
                $value = $value ? 'Yes' : 'No';
            }
            if ((string) $value === '') continue;
            $lines[] = '<span><b>' . esc_html($label) . ':</b> ' . esc_html((string) $value) . '</span>';
        }
        if ($lines) echo '<div class="ifprog-audit-context">' . implode('', $lines) . '</div>';
    }
}
