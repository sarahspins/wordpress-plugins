<?php
if (!defined('ABSPATH')) exit;

/** Read-only availability finder for gaps between Dash schedule events. */
class IFDC_Schedule_Gaps {
    const MAX_RANGE_DAYS = 31;
    const MAX_RESULTS = 5000;
    const TARGET_RESOURCE_IDS = [1, 2];
    const WEEKLY_HOOK = 'ifdc_weekly_schedule_gap_report'; // Legacy combined hook.
    const ICE_CUT_WEEKLY_HOOK = 'ifdc_weekly_ice_cut_report';
    const GAP_WEEKLY_HOOK = 'ifdc_weekly_schedule_gap_report_v2';
    const SETTINGS_OPTION = 'ifdc_schedule_gap_report_settings';

    public static function init() {
        add_filter('cron_schedules', [__CLASS__, 'cron_schedules']);
        add_action('wp_ajax_ifdc_find_schedule_gaps', [__CLASS__, 'ajax_find']);
        add_action('admin_post_ifdc_save_gap_report', [__CLASS__, 'save_report_settings']);
        add_action('admin_post_ifdc_send_gap_report_now', [__CLASS__, 'send_report_now']);
        add_action(self::ICE_CUT_WEEKLY_HOOK, [__CLASS__, 'send_weekly_ice_cut_report']);
        add_action(self::GAP_WEEKLY_HOOK, [__CLASS__, 'send_weekly_gap_report']);
        add_action('init', [__CLASS__, 'ensure_schedule']);
    }

    public static function cron_schedules($schedules) {
        $schedules['weekly'] = ['interval' => WEEK_IN_SECONDS, 'display' => 'Once Weekly'];
        return $schedules;
    }

    public static function menu() {
        add_submenu_page(
            'ifdc-dashboard',
            'Schedule Gaps',
            'Schedule Gaps',
            IFDC_Admin::CAP_EXPLORE,
            'ifdc-schedule-gaps',
            [__CLASS__, 'page']
        );
    }

    public static function page() {
        $timezone = self::schedule_timezone();
        $today = new DateTimeImmutable('today', $timezone);
        $week_start = $today->modify('monday this week')->format('Y-m-d');
        $settings = self::settings();
        $next_ice_cut_report = wp_next_scheduled(self::ICE_CUT_WEEKLY_HOOK);
        $next_gap_report = wp_next_scheduled(self::GAP_WEEKLY_HOOK);
        $report_notice = sanitize_key(wp_unslash($_GET['ifdc_gap_report_notice'] ?? ''));
        $ice_cut_mail_status = IFDC_Mailer::status('weekly_ice_cuts');
        $gap_mail_status = IFDC_Mailer::status('weekly_schedule_gaps');
        ?>
        <div class="wrap ifdc-wrap ifdc-gaps-wrap">
            <?php if (in_array($report_notice, ['ice_cuts_sent', 'schedule_gaps_sent'], true)): ?>
                <div class="notice notice-success is-dismissible"><p>The <?php echo $report_notice === 'ice_cuts_sent' ? 'ice-cut schedule' : 'schedule-gaps report'; ?> was accepted by the WordPress mail transport.</p></div>
            <?php elseif (in_array($report_notice, ['ice_cuts_failed', 'schedule_gaps_failed'], true)): ?>
                <div class="notice notice-error is-dismissible"><p>The <?php echo $report_notice === 'ice_cuts_failed' ? 'ice-cut schedule' : 'schedule-gaps report'; ?> could not be sent. Review Email History for details.</p></div>
            <?php endif; ?>
            <div class="ifdc-explorer-heading">
                <div>
                    <h1>Schedule Gaps</h1>
                    <p class="ifdc-lead">Find open blocks on the Gold and Silver schedules during a week or month.</p>
                </div>
                <span class="ifdc-readonly-badge">Read only</span>
            </div>

            <section class="ifdc-card">
                <div class="ifdc-gap-fields">
                    <label>Range
                        <select id="ifdc-gap-range">
                            <option value="week">Week</option>
                            <option value="month">Month</option>
                        </select>
                    </label>
                    <label id="ifdc-gap-week-label">Week containing
                        <input type="date" id="ifdc-gap-date" value="<?php echo esc_attr($week_start); ?>">
                    </label>
                    <label id="ifdc-gap-month-label" hidden>Month
                        <input type="month" id="ifdc-gap-month" value="<?php echo esc_attr($today->format('Y-m')); ?>">
                    </label>
                    <label>Day starts
                        <input type="time" id="ifdc-gap-day-start" value="06:00">
                    </label>
                    <label>Day ends
                        <input type="time" id="ifdc-gap-day-end" value="23:59">
                    </label>
                    <label>Minimum gap
                        <span><input type="number" id="ifdc-gap-minutes" min="45" max="1440" step="15" value="45"> minutes</span>
                    </label>
                </div>
                <p class="description">Only gaps between events on the same resource and calendar day are included. Time before the first event and after the last event is ignored. Overlapping events are treated as one occupied block.</p>
                <p><button type="button" class="button button-primary" id="ifdc-find-schedule-gaps">Find Schedule Gaps</button></p>
                <div id="ifdc-gap-status" class="ifdc-result" hidden></div>
            </section>

            <section class="ifdc-card">
                <div class="ifdc-card-heading">
                    <div><h2>Open blocks</h2><p id="ifdc-gap-summary" class="description">Choose a range and run the search.</p></div>
                    <button type="button" class="button" id="ifdc-gap-print" hidden>Print Results</button>
                </div>
                <div id="ifdc-gap-results" class="ifdc-empty-state">
                    <span class="dashicons dashicons-clock"></span><h3>No search yet</h3>
                    <p>Results will be grouped by date and resource.</p>
                </div>
            </section>

            <section class="ifdc-card">
                <h2>Weekly Email Reports</h2>
                <p class="description">Ice cuts and schedule gaps are separate branded emails with independent recipients and Monday send times.</p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="ifdc_save_gap_report">
                    <?php wp_nonce_field('ifdc_save_gap_report'); ?>
                    <div class="ifdc-email-report-settings">
                        <div class="ifdc-email-report-setting">
                            <h3>Ice Cut Schedule</h3>
                            <p><label><input type="checkbox" name="ice_cuts_enabled" value="1" <?php checked($settings['ice_cuts']['enabled']); ?>> <strong>Enable weekly ice-cut email</strong></label></p>
                            <label>Ice-cut recipients
                                <textarea name="ice_cuts_recipients" rows="3" class="large-text" placeholder="operations@example.com"><?php echo esc_textarea(implode(', ', $settings['ice_cuts']['recipients'])); ?></textarea>
                            </label>
                            <label>Send every Monday at <input type="time" name="ice_cuts_time" value="<?php echo esc_attr($settings['ice_cuts']['time']); ?>"></label>
                            <?php if ($settings['ice_cuts']['enabled'] && $next_ice_cut_report): ?><p class="description">Next: <?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $next_ice_cut_report, $timezone)); ?></p><?php endif; ?>
                        </div>
                        <div class="ifdc-email-report-setting">
                            <h3>Schedule Gaps</h3>
                            <p><label><input type="checkbox" name="schedule_gaps_enabled" value="1" <?php checked($settings['schedule_gaps']['enabled']); ?>> <strong>Enable weekly schedule-gaps email</strong></label></p>
                            <label>Schedule-gap recipients
                                <textarea name="schedule_gaps_recipients" rows="3" class="large-text" placeholder="sales@example.com"><?php echo esc_textarea(implode(', ', $settings['schedule_gaps']['recipients'])); ?></textarea>
                            </label>
                            <label>Send every Monday at <input type="time" name="schedule_gaps_time" value="<?php echo esc_attr($settings['schedule_gaps']['time']); ?>"></label>
                            <?php if ($settings['schedule_gaps']['enabled'] && $next_gap_report): ?><p class="description">Next: <?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $next_gap_report, $timezone)); ?></p><?php endif; ?>
                        </div>
                    </div>
                    <p><button type="submit" class="button button-primary">Save Weekly Emails</button></p>
                </form>
                <div class="ifdc-email-report-actions">
                    <?php self::render_report_action('ice_cuts', 'Send Ice Cut Schedule Now', $settings['ice_cuts'], $ice_cut_mail_status, $timezone); ?>
                    <?php self::render_report_action('schedule_gaps', 'Send Schedule Gaps Now', $settings['schedule_gaps'], $gap_mail_status, $timezone); ?>
                </div>
            </section>
        </div>
        <?php
    }

    public static function ajax_find() {
        if (!current_user_can(IFDC_Admin::CAP_EXPLORE)) wp_send_json_error(['message' => 'Permission denied.'], 403);
        check_ajax_referer('ifdc_admin', 'nonce');

        $start = self::date(wp_unslash($_POST['start'] ?? ''));
        $end = self::date(wp_unslash($_POST['end'] ?? ''));
        $day_start = self::time(wp_unslash($_POST['day_start'] ?? ''));
        $day_end = self::time(wp_unslash($_POST['day_end'] ?? ''));
        $minimum = max(45, min(1440, absint($_POST['minimum'] ?? 45)));
        if (!$start || !$end || !$day_start || !$day_end) wp_send_json_error(['message' => 'Choose a valid date range and daily hours.'], 400);
        if ($start > $end) wp_send_json_error(['message' => 'The start date must be on or before the end date.'], 400);
        if ($day_start >= $day_end) wp_send_json_error(['message' => 'The daily end time must be after the start time.'], 400);

        $timezone = self::schedule_timezone();
        $first = new DateTimeImmutable($start, $timezone);
        $last = new DateTimeImmutable($end, $timezone);
        $days = (int) $first->diff($last)->days + 1;
        if ($days > self::MAX_RANGE_DAYS) wp_send_json_error(['message' => 'Search up to 31 days at a time.'], 400);

        $resources = IFDC_Client::get_collection('resources', ['sort' => 'name', 'page[size]' => 100], [
            'force' => true, 'cache' => false, 'max_pages' => 5,
        ]);
        if (is_wp_error($resources)) self::error($resources);
        $resource_map = [];
        foreach ((array) ($resources['data'] ?? []) as $resource) {
            $attrs = self::attributes($resource);
            if (!empty($attrs['inactive'])) continue;
            $id = absint($resource['id'] ?? 0);
            $name = sanitize_text_field($attrs['name'] ?? ('Resource #' . $id));
            if (in_array($id, self::TARGET_RESOURCE_IDS, true)) $resource_map[$id] = $name;
        }
        if (!$resource_map) wp_send_json_error(['message' => 'Dash returned no active Gold or Silver resources.'], 404);

        $events = IFDC_Client::get_events([
            'filter[start__gte]' => $start . 'T00:00:00',
            'filter[start__lte]' => $end . 'T23:59:59',
            'sort' => 'start',
            'page[size]' => 500,
        ], ['force' => true, 'cache' => false, 'max_pages' => 20]);
        if (is_wp_error($events)) self::error($events);
        if (!empty($events['meta']['truncated'])) wp_send_json_error(['message' => 'Dash returned more schedule pages than can be safely analyzed. Narrow the range.'], 400);

        $normalized = [];
        foreach ((array) ($events['data'] ?? []) as $event) {
            $attrs = self::attributes($event);
            $resource_id = absint($attrs['resource_id'] ?? 0);
            if (!$resource_id || !isset($resource_map[$resource_id])) continue;
            try {
                $event_start = new DateTimeImmutable((string) ($attrs['start'] ?? ''), $timezone);
                $event_end = new DateTimeImmutable((string) ($attrs['end'] ?? ''), $timezone);
            } catch (Exception $exception) { continue; }
            if ($event_end <= $event_start) continue;
            $normalized[] = ['resource_id' => $resource_id, 'start' => $event_start, 'end' => $event_end, 'name' => sanitize_text_field($attrs['desc'] ?? '')];
        }

        $gaps = self::calculate_gaps($resource_map, $normalized, $first, $last, $day_start, $day_end, $minimum, $timezone);
        $ice_cuts = self::calculate_ice_cuts($resource_map, $normalized, $first, $last, $day_start, $day_end, $timezone);
        wp_send_json_success([
            'gaps' => array_slice($gaps, 0, self::MAX_RESULTS),
            'ice_cuts' => array_slice($ice_cuts, 0, self::MAX_RESULTS),
            'count' => count($gaps),
            'limited' => count($gaps) > self::MAX_RESULTS,
            'resource_count' => count($resource_map),
            'event_count' => count($normalized),
            'start' => $start,
            'end' => $end,
            'minimum' => $minimum,
        ]);
    }

    /** Exact internal 15/30 minute gaps bounded by events are likely resurfacing cuts. */
    public static function calculate_ice_cuts($resources, $events, DateTimeImmutable $first, DateTimeImmutable $last, $day_start, $day_end, DateTimeZone $timezone) {
        $by_resource = [];
        foreach ($events as $event) $by_resource[$event['resource_id']][] = $event;
        $cuts = [];
        for ($day = $first; $day <= $last; $day = $day->modify('+1 day')) {
            $window_start = new DateTimeImmutable($day->format('Y-m-d') . ' ' . $day_start, $timezone);
            $window_end = new DateTimeImmutable($day->format('Y-m-d') . ' ' . $day_end, $timezone);
            foreach ($resources as $resource_id => $resource_name) {
                $occupied = [];
                foreach ((array) ($by_resource[$resource_id] ?? []) as $event) {
                    if ($event['end'] <= $window_start || $event['start'] >= $window_end) continue;
                    $occupied[] = [max($event['start']->getTimestamp(), $window_start->getTimestamp()), min($event['end']->getTimestamp(), $window_end->getTimestamp())];
                }
                usort($occupied, function($a, $b) { return $a[0] <=> $b[0]; });
                $merged = [];
                foreach ($occupied as $block) {
                    $last_index = count($merged) - 1;
                    if ($last_index >= 0 && $block[0] <= $merged[$last_index][1]) $merged[$last_index][1] = max($merged[$last_index][1], $block[1]);
                    else $merged[] = $block;
                }
                for ($index = 1; $index < count($merged); $index++) {
                    $minutes = (int) floor(($merged[$index][0] - $merged[$index - 1][1]) / 60);
                    if (!in_array($minutes, [15, 30], true)) continue;
                    self::add_gap($cuts, $resource_id, $resource_name, $merged[$index - 1][1], $merged[$index][0], $minutes, $timezone);
                    $cuts[count($cuts) - 1]['type'] = 'ice_cut';
                }
            }
        }
        usort($cuts, function($a, $b) { return strcmp($a['start'], $b['start']) ?: strcasecmp($a['resource_name'], $b['resource_name']); });
        $cuts = self::classify_ice_cut_sequence($cuts);
        foreach ($events as $event) {
            if (strcasecmp(trim((string) ($event['name'] ?? '')), 'Ice maintenance') !== 0) continue;
            if ($event['start'] < $first || $event['start'] >= $last->modify('+1 day')) continue;
            $resource_id = absint($event['resource_id'] ?? 0);
            if (!isset($resources[$resource_id])) continue;
            $start_date = $event['start']->setTimezone($timezone);
            $end_date = $event['end']->setTimezone($timezone);
            $cuts[] = [
                'resource_id' => $resource_id,
                'resource_name' => sanitize_text_field($resources[$resource_id]),
                'start' => $start_date->format(DateTimeInterface::ATOM),
                'end' => $end_date->format(DateTimeInterface::ATOM),
                'date_label' => $start_date->format('D, M j'),
                'start_label' => $start_date->format('g:i a'),
                'end_label' => $end_date->format('g:i a'),
                'minutes' => max(0, (int) floor(($end_date->getTimestamp() - $start_date->getTimestamp()) / 60)),
                'type' => 'scheduled_maintenance',
                'cut_status' => 'Scheduled ice maintenance',
                'cut_status_class' => 'scheduled',
            ];
        }
        usort($cuts, [__CLASS__, 'sort_ice_cuts']);
        return $cuts;
    }

    private static function sort_ice_cuts($left, $right) {
        $time_order = strcmp($left['start'], $right['start']);
        if ($time_order) return $time_order;
        $priority = ['first' => 0, 'second' => 1, 'staffing' => 2, 'flexible' => 3, 'scheduled' => 4, '' => 5];
        $left_priority = $priority[$left['cut_status_class'] ?? ''] ?? 5;
        $right_priority = $priority[$right['cut_status_class'] ?? ''] ?? 5;
        return ($left_priority <=> $right_priority) ?: strcasecmp($left['resource_name'], $right['resource_name']);
    }

    private static function classify_ice_cut_sequence($cuts) {
        foreach ($cuts as &$cut) {
            $cut['cut_status'] = '';
            $cut['cut_status_class'] = '';
        }
        unset($cut);
        for ($left = 0; $left < count($cuts); $left++) {
            for ($right = $left + 1; $right < count($cuts); $right++) {
                if (substr($cuts[$left]['start'], 0, 10) !== substr($cuts[$right]['start'], 0, 10)) break;
                if ($cuts[$left]['resource_id'] === $cuts[$right]['resource_id']) continue;
                $left_start = strtotime($cuts[$left]['start']); $left_end = strtotime($cuts[$left]['end']);
                $right_start = strtotime($cuts[$right]['start']); $right_end = strtotime($cuts[$right]['end']);
                if (max($left_start, $right_start) >= min($left_end, $right_end)) continue;

                if ($cuts[$left]['minutes'] === 15 && $cuts[$right]['minutes'] === 15) {
                    $cuts[$left]['cut_status'] = 'Overlaps ' . $cuts[$right]['resource_name'];
                    $cuts[$right]['cut_status'] = 'Overlaps ' . $cuts[$left]['resource_name'];
                    $cuts[$left]['cut_status_class'] = $cuts[$right]['cut_status_class'] = 'staffing';
                    continue;
                }

                if ($left_start < $right_start) { $first = $left; $second = $right; }
                elseif ($right_start < $left_start) { $first = $right; $second = $left; }
                elseif ($cuts[$left]['minutes'] < $cuts[$right]['minutes']) { $first = $left; $second = $right; }
                elseif ($cuts[$right]['minutes'] < $cuts[$left]['minutes']) { $first = $right; $second = $left; }
                else {
                    $cuts[$left]['cut_status'] = $cuts[$right]['cut_status'] = 'Either order — two cuts fit';
                    $cuts[$left]['cut_status_class'] = $cuts[$right]['cut_status_class'] = 'flexible';
                    continue;
                }
                if ($cuts[$first]['cut_status_class'] !== 'staffing') {
                    $cuts[$first]['cut_status'] = 'First cut';
                    $cuts[$first]['cut_status_class'] = 'first';
                }
                if ($cuts[$second]['cut_status_class'] !== 'staffing') {
                    $cuts[$second]['cut_status'] = 'Second cut';
                    $cuts[$second]['cut_status_class'] = 'second';
                }
            }
        }
        return $cuts;
    }

    public static function settings() {
        $saved = (array) get_option(self::SETTINGS_OPTION, []);
        $legacy = [
            'enabled' => !empty($saved['enabled']),
            'recipients' => array_values((array) ($saved['recipients'] ?? [])),
            'time' => self::time($saved['time'] ?? '') ?: '06:00',
        ];
        return [
            'ice_cuts' => wp_parse_args((array) ($saved['ice_cuts'] ?? []), $legacy),
            'schedule_gaps' => wp_parse_args((array) ($saved['schedule_gaps'] ?? []), $legacy),
        ];
    }

    public static function save_report_settings() {
        if (!current_user_can('manage_options')) wp_die('Permission denied.');
        check_admin_referer('ifdc_save_gap_report');
        $settings = [
            'ice_cuts' => [
                'enabled' => !empty($_POST['ice_cuts_enabled']),
                'recipients' => self::sanitize_emails($_POST['ice_cuts_recipients'] ?? ''),
                'time' => self::time(wp_unslash($_POST['ice_cuts_time'] ?? '06:00')) ?: '06:00',
            ],
            'schedule_gaps' => [
                'enabled' => !empty($_POST['schedule_gaps_enabled']),
                'recipients' => self::sanitize_emails($_POST['schedule_gaps_recipients'] ?? ''),
                'time' => self::time(wp_unslash($_POST['schedule_gaps_time'] ?? '06:30')) ?: '06:30',
            ],
        ];
        update_option(self::SETTINGS_OPTION, $settings, false);
        self::clear_report_schedules();
        self::ensure_schedule();
        wp_safe_redirect(admin_url('admin.php?page=ifdc-schedule-gaps&settings-updated=1'));
        exit;
    }

    public static function ensure_schedule() {
        $settings = self::settings();
        wp_clear_scheduled_hook(self::WEEKLY_HOOK);
        self::ensure_report_schedule(self::ICE_CUT_WEEKLY_HOOK, $settings['ice_cuts']);
        self::ensure_report_schedule(self::GAP_WEEKLY_HOOK, $settings['schedule_gaps']);
    }

    public static function send_report_now() {
        if (!current_user_can('manage_options')) wp_die('Permission denied.');
        check_admin_referer('ifdc_send_gap_report_now');
        $type = sanitize_key(wp_unslash($_POST['report_type'] ?? ''));
        $sent = $type === 'ice_cuts' ? self::send_weekly_ice_cut_report() : ($type === 'schedule_gaps' ? self::send_weekly_gap_report() : false);
        $notice = in_array($type, ['ice_cuts', 'schedule_gaps'], true) ? $type . '_' . ($sent === true ? 'sent' : 'failed') : 'schedule_gaps_failed';
        wp_safe_redirect(admin_url('admin.php?page=ifdc-schedule-gaps&ifdc_gap_report_notice=' . $notice));
        exit;
    }

    public static function send_weekly_ice_cut_report() {
        $settings = self::settings();
        $report_settings = $settings['ice_cuts'];
        if (!$report_settings['recipients']) return false;
        $timezone = self::schedule_timezone();
        $today = new DateTimeImmutable('today', $timezone);
        $first = $today->format('N') === '1' ? $today : $today->modify('next monday');
        $last = $first->modify('+13 days');
        $schedule = self::load_schedule($first, $last, '06:00', '23:59', 45, $timezone);
        if (is_wp_error($schedule)) return false;
        $subject = 'Ice cut schedule: ' . $first->format('M j') . '–' . $last->format('M j, Y');
        $content = self::email_table('Ice Cuts — Next Two Weeks', $schedule['ice_cuts'], true);
        $body = self::email_shell('OPERATIONS', $subject, 'Gold and Silver resurfacing windows, sequencing, and staffing alerts.', $content, '#33b6ff');
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        return IFDC_Mailer::send('weekly_ice_cuts', $report_settings['recipients'], $subject, $body, $headers);
    }

    public static function send_weekly_gap_report() {
        $settings = self::settings();
        $report_settings = $settings['schedule_gaps'];
        if (!$report_settings['recipients']) return false;
        $timezone = self::schedule_timezone();
        $today = new DateTimeImmutable('today', $timezone);
        $first = $today->format('N') === '1' ? $today : $today->modify('next monday');
        $urgent_last = $first->modify('+13 days');
        $later_first = $urgent_last->modify('+1 day');
        $later_last = $later_first->modify('+55 days');
        $urgent = self::load_schedule($first, $urgent_last, '06:00', '23:59', 45, $timezone);
        $later = self::load_schedule($later_first, $later_last, '06:00', '23:59', 45, $timezone);
        if (is_wp_error($urgent) || is_wp_error($later)) return false;
        $subject = 'Schedule gaps: ' . $first->format('M j') . '–' . $later_last->format('M j, Y');
        $content = self::email_section('Urgent — Next Two Weeks', 'Openings of 45 minutes or longer:', self::email_table('', $urgent['gaps'], false))
            . self::email_section('Planning — Following Eight Weeks', 'Openings of 45 minutes or longer:', self::email_table('', $later['gaps'], false));
        $body = self::email_shell('SCHEDULE OPPORTUNITIES', $subject, 'Gold and Silver openings between scheduled events.', $content, '#ff8033');
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        return IFDC_Mailer::send('weekly_schedule_gaps', $report_settings['recipients'], $subject, $body, $headers);
    }

    private static function render_report_action($type, $label, $settings, $mail_status, DateTimeZone $timezone) {
        ?>
        <div class="ifdc-email-report-action">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="ifdc_send_gap_report_now">
                <input type="hidden" name="report_type" value="<?php echo esc_attr($type); ?>">
                <?php wp_nonce_field('ifdc_send_gap_report_now'); ?>
                <button type="submit" class="button"><?php echo esc_html($label); ?></button>
            </form>
            <?php if (!empty($mail_status['attempted_at'])): ?>
                <p class="description">Last attempt: <?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), absint($mail_status['attempted_at']), $timezone)); ?> — <?php echo empty($mail_status['failed']) ? 'accepted' : 'failed'; ?>.</p>
            <?php elseif (empty($settings['recipients'])): ?>
                <p class="description">Add at least one recipient before sending.</p>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function sanitize_emails($value) {
        $parts = preg_split('/[\s,;]+/', wp_unslash((string) $value));
        return array_values(array_unique(array_filter(array_map('sanitize_email', $parts), 'is_email')));
    }

    private static function ensure_report_schedule($hook, $settings) {
        if (empty($settings['enabled']) || empty($settings['recipients'])) {
            wp_clear_scheduled_hook($hook);
            return;
        }
        if (wp_next_scheduled($hook)) return;
        $timezone = self::schedule_timezone();
        $now = new DateTimeImmutable('now', $timezone);
        $next = new DateTimeImmutable('monday ' . ($settings['time'] ?: '06:00'), $timezone);
        if ($next <= $now) $next = $next->modify('+1 week');
        wp_schedule_event($next->getTimestamp(), 'weekly', $hook);
    }

    public static function clear_report_schedules() {
        wp_clear_scheduled_hook(self::WEEKLY_HOOK);
        wp_clear_scheduled_hook(self::ICE_CUT_WEEKLY_HOOK);
        wp_clear_scheduled_hook(self::GAP_WEEKLY_HOOK);
    }

    private static function load_schedule(DateTimeImmutable $first, DateTimeImmutable $last, $day_start, $day_end, $minimum, DateTimeZone $timezone) {
        $resources = IFDC_Client::get_collection('resources', ['sort' => 'name', 'page[size]' => 100], ['force' => true, 'cache' => false, 'max_pages' => 5]);
        if (is_wp_error($resources)) return $resources;
        $resource_map = [];
        foreach ((array) ($resources['data'] ?? []) as $resource) {
            $attrs = self::attributes($resource);
            if (!empty($attrs['inactive'])) continue;
            $id = absint($resource['id'] ?? 0);
            $name = sanitize_text_field($attrs['name'] ?? ('Resource #' . $id));
            if (in_array($id, self::TARGET_RESOURCE_IDS, true)) $resource_map[$id] = $name;
        }
        if (!$resource_map) return new WP_Error('ifdc_gap_resources_missing', 'Dash returned no active Gold or Silver resources.');
        $gaps = [];
        $ice_cuts = [];
        for ($chunk_first = $first; $chunk_first <= $last; $chunk_first = $chunk_last->modify('+1 day')) {
            $chunk_last = min($last, $chunk_first->modify('+6 days'));
            $events = IFDC_Client::get_events([
                'filter[start__gte]' => $chunk_first->format('Y-m-d') . 'T00:00:00',
                'filter[start__lte]' => $chunk_last->format('Y-m-d') . 'T23:59:59',
                'sort' => 'start', 'page[size]' => 500,
            ], ['force' => true, 'cache' => false, 'max_pages' => 10]);
            if (is_wp_error($events)) return $events;
            if (!empty($events['meta']['truncated'])) return new WP_Error('ifdc_gap_truncated', 'One week of the schedule was too large to create a complete report.');
            $normalized = [];
            foreach ((array) ($events['data'] ?? []) as $event) {
                $attrs = self::attributes($event);
                $resource_id = absint($attrs['resource_id'] ?? 0);
                if (!$resource_id || !isset($resource_map[$resource_id])) continue;
                try { $start = new DateTimeImmutable((string) ($attrs['start'] ?? ''), $timezone); $end = new DateTimeImmutable((string) ($attrs['end'] ?? ''), $timezone); }
                catch (Exception $exception) { continue; }
                if ($end > $start) $normalized[] = ['resource_id' => $resource_id, 'start' => $start, 'end' => $end, 'name' => sanitize_text_field($attrs['desc'] ?? '')];
            }
            $gaps = array_merge($gaps, self::calculate_gaps($resource_map, $normalized, $chunk_first, $chunk_last, $day_start, $day_end, $minimum, $timezone));
            $ice_cuts = array_merge($ice_cuts, self::calculate_ice_cuts($resource_map, $normalized, $chunk_first, $chunk_last, $day_start, $day_end, $timezone));
            unset($events, $normalized);
        }
        return [
            'gaps' => $gaps,
            'ice_cuts' => $ice_cuts,
        ];
    }

    private static function email_table($title, $items, $group_by_day = false) {
        $html = $title ? '<h2 style="color:#003b5c;font-size:22px;margin:0 0 14px">' . esc_html($title) . '</h2>' : '';
        if (!$items) return $html . '<p style="background:#fffaf2;border-left:4px solid #ffc600;border-radius:6px;margin:0 0 22px;padding:12px 14px">None found.</p>';
        $groups = $group_by_day ? [] : ['' => $items];
        if ($group_by_day) foreach ($items as $item) $groups[substr($item['start'], 0, 10)][] = $item;
        foreach ($groups as $group) {
            if ($group_by_day) $html .= '<h3 style="background:#003b5c;border-radius:6px 6px 0 0;color:#fff;font-size:16px;margin:22px 0 0;padding:10px 12px">' . esc_html($group[0]['date_label']) . '<span style="float:right;font-size:13px;font-weight:normal">' . count($group) . ' cut' . (count($group) === 1 ? '' : 's') . '</span></h3>';
            $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:separate;border-spacing:0;border-radius:' . ($group_by_day ? '0 0 6px 6px' : '6px') . ';margin:0 0 22px;overflow:hidden"><thead><tr style="background:#e7f5ff;color:#003b5c">' . ($group_by_day ? '' : '<th align="left" style="border:1px solid #c7d7e0;padding:9px">Date</th>') . '<th align="left" style="border:1px solid #c7d7e0;padding:9px">Time</th><th align="left" style="border:1px solid #c7d7e0;padding:9px">Length</th><th align="left" style="border:1px solid #c7d7e0;padding:9px">Resource</th>' . ($group_by_day ? '<th align="left" style="border:1px solid #c7d7e0;padding:9px">Resurfacing</th>' : '') . '</tr></thead><tbody>';
            foreach ($group as $item) {
                $status_class = $item['cut_status_class'] ?? '';
                $row_styles = [
                    'staffing' => 'background:#fff0f0;color:#8a2424;font-weight:600',
                    'first' => 'background:#e7f5ff;color:#0a4b78',
                    'second' => 'background:#f0ecff;color:#4b2e83',
                ];
                $row_style = $row_styles[$status_class] ?? 'background:#fff;color:#182433';
                $cell = 'border:1px solid #d9e1e6;padding:9px;vertical-align:top';
                $status = $item['cut_status'] ?: 'No overlap';
                $html .= '<tr style="' . esc_attr($row_style) . '">' . ($group_by_day ? '' : '<td style="' . esc_attr($cell) . '">' . esc_html($item['date_label']) . '</td>') . '<td style="' . esc_attr($cell) . '">' . esc_html($item['start_label'] . '–' . $item['end_label']) . '</td><td style="' . esc_attr($cell) . '">' . esc_html($item['minutes'] . ' min') . '</td><td style="' . esc_attr($cell) . '"><strong>' . esc_html($item['resource_name']) . '</strong></td>' . ($group_by_day ? '<td style="' . esc_attr($cell) . '">' . esc_html($status) . '</td>' : '') . '</tr>';
            }
            $html .= '</tbody></table>';
        }
        return $html;
    }

    private static function email_section($title, $description, $content) {
        return '<div style="margin:0 0 30px"><h2 style="color:#003b5c;font-size:22px;margin:0 0 4px">' . esc_html($title) . '</h2><p style="color:#566873;margin:0 0 14px">' . esc_html($description) . '</p>' . $content . '</div>';
    }

    private static function email_shell($eyebrow, $title, $intro, $content, $accent) {
        $logo_url = IFDC_URL . 'assets/images/ice-and-field-logo-white.png';
        return '<!doctype html><html><body style="background:#eef3f6;color:#182433;font-family:Arial,sans-serif;margin:0;padding:24px 10px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center"><table role="presentation" width="760" cellpadding="0" cellspacing="0" style="background:#fff;border-collapse:separate;border-radius:6px;border-spacing:0;max-width:760px;overflow:hidden;width:100%"><tr><td style="background:#003b5c;border-bottom:6px solid ' . esc_attr($accent) . ';border-radius:6px 6px 0 0;padding:25px 30px"><img src="' . esc_url($logo_url) . '" width="264" height="60" alt="Ice &amp; Field at The Crossover" style="display:block;height:auto;max-width:100%;width:264px"><p style="color:#bde7ff;font-size:12px;font-weight:bold;letter-spacing:1.5px;margin:20px 0 7px">' . esc_html($eyebrow) . '</p><h1 style="color:#fff;font-size:27px;line-height:1.2;margin:0">' . esc_html($title) . '</h1></td></tr><tr><td style="padding:26px 30px 8px"><p style="color:#566873;font-size:16px;line-height:1.5;margin:0 0 24px">' . esc_html($intro) . '</p>' . $content . '</td></tr><tr><td style="background:#fffaf2;border-radius:0 0 6px 6px;border-top:1px solid #f0dfca;color:#566873;font-size:12px;padding:16px 30px">Generated by the Ice &amp; Field Dash Connector using the configured rink-display timezone.</td></tr></table></td></tr></table></body></html>';
    }

    /** Pure interval calculation, public to make the edge cases easy to test. */
    public static function calculate_gaps($resources, $events, DateTimeImmutable $first, DateTimeImmutable $last, $day_start, $day_end, $minimum, DateTimeZone $timezone) {
        $by_resource = [];
        foreach ($events as $event) $by_resource[$event['resource_id']][] = $event;
        $gaps = [];
        for ($day = $first; $day <= $last; $day = $day->modify('+1 day')) {
            $window_start = new DateTimeImmutable($day->format('Y-m-d') . ' ' . $day_start, $timezone);
            $window_end = new DateTimeImmutable($day->format('Y-m-d') . ' ' . $day_end, $timezone);
            foreach ($resources as $resource_id => $resource_name) {
                $occupied = [];
                foreach ((array) ($by_resource[$resource_id] ?? []) as $event) {
                    if ($event['end'] <= $window_start || $event['start'] >= $window_end) continue;
                    $occupied[] = [max($event['start']->getTimestamp(), $window_start->getTimestamp()), min($event['end']->getTimestamp(), $window_end->getTimestamp())];
                }
                usort($occupied, function($a, $b) { return $a[0] <=> $b[0]; });
                $merged = [];
                foreach ($occupied as $block) {
                    $last_index = count($merged) - 1;
                    if ($last_index >= 0 && $block[0] <= $merged[$last_index][1]) $merged[$last_index][1] = max($merged[$last_index][1], $block[1]);
                    else $merged[] = $block;
                }
                for ($index = 1; $index < count($merged); $index++) {
                    self::add_gap($gaps, $resource_id, $resource_name, $merged[$index - 1][1], $merged[$index][0], $minimum, $timezone);
                }
            }
        }
        usort($gaps, function($a, $b) { return strcmp($a['start'], $b['start']) ?: strcasecmp($a['resource_name'], $b['resource_name']); });
        return $gaps;
    }

    private static function add_gap(&$gaps, $resource_id, $resource_name, $start, $end, $minimum, $timezone) {
        $minutes = (int) floor(($end - $start) / 60);
        if ($minutes < $minimum) return;
        $start_date = (new DateTimeImmutable('@' . $start))->setTimezone($timezone);
        $end_date = (new DateTimeImmutable('@' . $end))->setTimezone($timezone);
        $gaps[] = [
            'resource_id' => absint($resource_id), 'resource_name' => sanitize_text_field($resource_name),
            'start' => $start_date->format(DateTimeInterface::ATOM),
            'end' => $end_date->format(DateTimeInterface::ATOM),
            'date_label' => $start_date->format('D, M j'),
            'start_label' => $start_date->format('g:i a'),
            'end_label' => $end_date->format('g:i a'),
            'minutes' => $minutes,
        ];
    }

    private static function attributes($record) {
        return is_array($record['attributes'] ?? null) ? $record['attributes'] : (is_array($record) ? $record : []);
    }

    private static function schedule_timezone() {
        $display_settings = get_option('ifrd_schedule_settings', []);
        $name = is_array($display_settings) ? trim((string) ($display_settings['display_timezone'] ?? '')) : '';
        if ($name === '') $name = wp_timezone_string();
        try {
            return new DateTimeZone($name ?: 'America/Chicago');
        } catch (Exception $exception) {
            return new DateTimeZone('America/Chicago');
        }
    }

    private static function date($value) {
        $value = sanitize_text_field($value);
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        return $date && $date->format('Y-m-d') === $value ? $value : '';
    }

    private static function time($value) {
        $value = sanitize_text_field($value);
        return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value) ? $value : '';
    }

    private static function error($error) {
        $data = $error->get_error_data();
        wp_send_json_error(['message' => $error->get_error_message()], is_array($data) && !empty($data['status']) ? absint($data['status']) : 500);
    }
}
