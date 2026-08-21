<?php
if (!defined('ABSPATH')) exit;

/**
 * Version 1.9.1 monitoring configuration and activity-log screens.
 *
 * This release deliberately does not register a cron event, contact Dash in
 * the background, send email, or import anything automatically.
 */
class IFPROG_Monitoring {
    const OPTION = 'ifprog_monitoring_settings';
    const SETTINGS_GROUP = 'ifprog_monitoring_group';
    const FOUNDATION_VERSION_OPTION = 'ifprog_monitoring_foundation_version';
    const FOUNDATION_VERSION = '1.9.1';
    const MAX_FAMILIES = 10;

    public static function init() {
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_init', [__CLASS__, 'maybe_install']);
        add_action('update_option_' . self::OPTION, [__CLASS__, 'settings_updated'], 10, 3);
    }

    public static function install() {
        IFPROG_Audit::install();
        add_option(self::OPTION, self::defaults(), '', false);

        $installed = (string) get_option(self::FOUNDATION_VERSION_OPTION, '');
        if (version_compare($installed ?: '0', self::FOUNDATION_VERSION, '<')) {
            IFPROG_Audit::record(
                'foundation_ready',
                'Monitoring foundation installed. Scheduled checks, emails, and automatic imports remain off.',
                ['source' => 'monitoring', 'severity' => 'success']
            );
            update_option(self::FOUNDATION_VERSION_OPTION, self::FOUNDATION_VERSION, false);
        }
    }

    public static function maybe_install() {
        self::install();
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
                    <p class="ifprog-lead">Prepare the rules that guarded Dash monitoring will use in the next 1.9 releases.</p>
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
                    <p>The 1.9.1 maintenance line stores these preferences and records activity only. It does not schedule Dash checks, send emails, or import anything automatically.</p>
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
                                <p class="description">Ready saves your preference for 1.9.2; it does not start background activity in this release.</p>
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
                                <p class="description">Saved now for 1.9.3. The 1.9.1 maintenance line does not send email.</p>
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
