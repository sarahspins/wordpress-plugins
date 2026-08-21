<?php
if (!defined('ABSPATH')) exit;

/**
 * Guarded admin tool for attaching existing Dash classes/teams to roster-only
 * schedule events. It never creates or deletes Dash records. Reassignment is
 * permitted only when an administrator explicitly selects and confirms an event.
 */
class IFDC_Event_Assignment {
    const MAX_RANGE_DAYS = 92;
    const MAX_RESULTS = 1000;
    const MAX_UPDATE_BATCH = 10;
    const NIGHTLY_HOOK = 'ifdc_nightly_event_assignment';
    const NIGHTLY_RESULT_OPTION = 'ifdc_nightly_event_assignment_result';
    const NIGHTLY_LOCK = 'ifdc_nightly_event_assignment_lock';

    public static function init() {
        add_action('wp_ajax_ifdc_search_assignment_events', [__CLASS__, 'ajax_search_events']);
        add_action('wp_ajax_ifdc_search_assignment_teams', [__CLASS__, 'ajax_search_teams']);
        add_action('wp_ajax_ifdc_prepare_standard_assignments', [__CLASS__, 'ajax_prepare_standard_assignments']);
        add_action('wp_ajax_ifdc_assign_event_batch', [__CLASS__, 'ajax_assign_batch']);
        add_action(self::NIGHTLY_HOOK, [__CLASS__, 'run_scheduled']);
        add_action('admin_post_ifdc_run_nightly_assignments', [__CLASS__, 'admin_run_nightly']);
        add_action('init', [__CLASS__, 'ensure_nightly_schedule']);
    }

    public static function menu() {
        add_submenu_page(
            'ifdc-dashboard',
            'Dash Event Assignment',
            'Event Assignment',
            IFDC_Admin::CAP_ASSIGN_EVENTS,
            'ifdc-event-assignment',
            [__CLASS__, 'page']
        );
    }

    public static function page() {
        $now = current_time('timestamp');
        $month = wp_date('Y-m', $now);
        $last_run = get_option(self::NIGHTLY_RESULT_OPTION, []);
        $next_run = wp_next_scheduled(self::NIGHTLY_HOOK);
        ?>
        <div class="wrap ifdc-wrap ifdc-assignment-wrap">
            <div class="ifdc-explorer-heading">
                <div>
                    <h1>Dash Event Assignment</h1>
                    <p class="ifdc-lead">Find roster-only events and attach them to an existing Dash season, level, and class.</p>
                </div>
                <span class="ifdc-readonly-badge">Preview before updating</span>
            </div>

            <div class="notice notice-info inline">
                <p><strong>Safe by design:</strong> events already assigned to the chosen destination are excluded. Assignments to a different class are clearly marked and change only when you select and confirm them. This tool never creates or deletes records.</p>
            </div>

            <section class="ifdc-card ifdc-standard-card">
                <div class="ifdc-card-heading">
                    <div>
                        <p class="ifdc-eyebrow">Standard monthly workflow</p>
                        <h2>Prepare All 3 Sessions</h2>
                        <p class="description">Find destinations, class assignments, and capacity corrections for the three recurring session types in one pass.</p>
                    </div>
                    <label class="ifdc-standard-month">Month
                        <input type="month" id="ifdc-standard-assignment-month" value="<?php echo esc_attr($month); ?>">
                    </label>
                </div>
                <div class="ifdc-standard-definitions">
                    <span><strong>Open Freestyle</strong><small>Capacity 20</small></span>
                    <span><strong>Stick &amp; Puck</strong><small>Capacity 25</small></span>
                    <span><strong>Private Hockey Coaches Ice</strong><small>Capacity 25</small></span>
                </div>
                <div class="notice notice-info inline">
                    <p><strong>Business-hours automation:</strong> checks this month and next month hourly from 5:45 AM through 6:45 PM. <?php echo $next_run ? 'Next scheduled check: ' . esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $next_run)) . '.' : 'The next check is being scheduled.'; ?></p>
                    <?php if (is_array($last_run) && !empty($last_run['finished_at'])): ?>
                        <p>Last run: <?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), absint($last_run['finished_at']))); ?> — <?php echo esc_html(absint($last_run['updated'] ?? 0)); ?> updated, <?php echo esc_html(absint($last_run['unchanged'] ?? 0)); ?> already correct, <?php echo esc_html(absint($last_run['errors'] ?? 0)); ?> errors.</p>
                    <?php endif; ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="ifdc_run_nightly_assignments">
                        <?php wp_nonce_field('ifdc_run_nightly_assignments'); ?>
                        <button type="submit" class="button">Run Current + Next Month Now</button>
                    </form>
                </div>
                <p>
                    <button type="button" class="button button-primary" id="ifdc-prepare-standard-assignments">Prepare All 3</button>
                    <button type="button" class="button button-primary" id="ifdc-apply-standard-assignments" disabled>Update Selected Groups</button>
                </p>
                <div id="ifdc-standard-assignment-status" class="ifdc-result" hidden></div>
                <div id="ifdc-standard-assignment-preview" class="ifdc-empty-state">
                    <span class="dashicons dashicons-list-view"></span>
                    <h3>No monthly preview loaded</h3>
                    <p>Preparing a preview does not change Dash.</p>
                </div>
            </section>

            <h2 class="ifdc-single-workflow-heading">Single Event Type or Capacity Repair</h2>

            <div class="ifdc-assignment-grid">
                <section class="ifdc-card">
                    <p class="ifdc-eyebrow">Step 1</p>
                    <h2>Find schedule events</h2>
                    <div class="ifdc-assignment-fields">
                        <label>Month
                            <input type="month" id="ifdc-assignment-month" value="<?php echo esc_attr($month); ?>">
                        </label>
                        <label>Event name contains
                            <input type="text" id="ifdc-assignment-name" value="Open Freestyle" placeholder="Open Freestyle">
                        </label>
                        <label>Event type ID <span class="description">(optional)</span>
                            <input type="number" min="1" id="ifdc-assignment-type" placeholder="9">
                        </label>
                    </div>
                    <label class="ifdc-checkbox-row">
                        <input type="checkbox" id="ifdc-assignment-include-other" checked>
                        Include events currently assigned to a different class/team
                    </label>
                    <label class="ifdc-checkbox-row">
                        <input type="checkbox" id="ifdc-assignment-unlimited-only">
                        Only show events with unlimited or missing capacity
                    </label>
                    <p><button type="button" class="button button-primary" id="ifdc-search-assignment-events">Search Events</button></p>
                    <div id="ifdc-assignment-event-status" class="ifdc-result" hidden></div>
                </section>

                <section class="ifdc-card">
                    <p class="ifdc-eyebrow">Step 2</p>
                    <h2>Choose the destination class <span class="description">(optional)</span></h2>
                    <p class="description">Choose a class to assign events, or leave this unselected for a capacity-only update.</p>
                    <div class="ifdc-inline-search">
                        <input type="search" id="ifdc-assignment-team-query" placeholder="August Open Freestyle or 126">
                        <button type="button" class="button" id="ifdc-search-assignment-teams">Search Classes</button>
                    </div>
                    <div id="ifdc-assignment-team-status" class="ifdc-result" hidden></div>
                    <div id="ifdc-assignment-team-results"></div>
                    <p><button type="button" class="button" id="ifdc-clear-assignment-team" disabled>Capacity Only — Clear Destination</button></p>
                    <div class="ifdc-capacity-setting">
                        <label for="ifdc-assignment-capacity"><strong>Set event capacity</strong></label>
                        <input type="number" min="0" max="9999" id="ifdc-assignment-capacity" placeholder="Preserve existing capacities">
                        <p class="description">Defaults to 20 for Freestyle and 25 for hockey or Stick &amp; Puck. Clear the field to preserve existing capacities.</p>
                    </div>
                </section>
            </div>

            <section class="ifdc-card" id="ifdc-assignment-results-card">
                <div class="ifdc-card-heading">
                    <div>
                        <p class="ifdc-eyebrow">Step 3</p>
                        <h2>Review and apply</h2>
                    </div>
                    <div class="ifdc-selection-actions">
                        <button type="button" class="button" id="ifdc-select-all-events" disabled>Select All</button>
                        <button type="button" class="button" id="ifdc-deselect-all-events" disabled>Deselect All</button>
                    </div>
                </div>
                <div id="ifdc-assignment-events" class="ifdc-empty-state">
                    <span class="dashicons dashicons-calendar-alt"></span>
                    <h3>No preview loaded</h3>
                    <p>Search for events above. Nothing in Dash changes during the search.</p>
                </div>
                <div class="ifdc-assignment-footer">
                    <div>
                        <strong id="ifdc-assignment-selected-count">0 events selected</strong>
                        <span id="ifdc-assignment-target-summary" class="description">Capacity-only update; existing class assignments preserved</span>
                    </div>
                    <button type="button" class="button button-primary button-hero" id="ifdc-apply-event-assignment" disabled>Update Selected Events</button>
                </div>
                <div id="ifdc-assignment-apply-status" class="ifdc-result" hidden></div>
            </section>

            <div id="ifdc-assignment-overlay" class="ifdc-assignment-overlay" hidden>
                <div class="ifdc-assignment-dialog" role="dialog" aria-modal="true" aria-labelledby="ifdc-assignment-dialog-title" aria-describedby="ifdc-assignment-dialog-status">
                    <span class="dashicons dashicons-update ifdc-assignment-dialog-icon" aria-hidden="true"></span>
                    <h2 id="ifdc-assignment-dialog-title">Assigning events…</h2>
                    <p id="ifdc-assignment-dialog-destination" class="ifdc-assignment-dialog-destination"></p>
                    <div class="ifdc-progress-track" role="progressbar" aria-label="Event assignment progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                        <span id="ifdc-assignment-progress-bar"></span>
                    </div>
                    <div class="ifdc-assignment-progress-meta">
                        <strong id="ifdc-assignment-progress-percent">0%</strong>
                        <span id="ifdc-assignment-progress-count">Preparing…</span>
                    </div>
                    <p id="ifdc-assignment-dialog-status" class="ifdc-assignment-dialog-status" aria-live="polite">Preparing the first batch…</p>
                    <p id="ifdc-assignment-dialog-warning" class="description">Keep this page open while Dash is being updated and each event is verified.</p>
                    <button type="button" class="button button-secondary button-hero" id="ifdc-close-assignment-overlay">Stop Working</button>
                </div>
            </div>
        </div>
        <?php
    }

    public static function ajax_prepare_standard_assignments() {
        self::guard_ajax();

        $month = sanitize_text_field(wp_unslash($_POST['month'] ?? ''));
        $prepared = self::prepare_standard_month($month);
        if (is_wp_error($prepared)) self::send_wp_error($prepared);
        wp_send_json_success($prepared);
    }

    private static function prepare_standard_month($month) {
        if (!preg_match('/^(\d{4})-(\d{2})$/', $month, $matches)) {
            return new WP_Error('ifdc_invalid_month', 'Choose a valid month.');
        }
        $year = absint($matches[1]);
        $month_number = absint($matches[2]);
        if (!checkdate($month_number, 1, $year)) return new WP_Error('ifdc_invalid_month', 'Choose a valid month.');

        $start = sprintf('%04d-%02d-01', $year, $month_number);
        $end = wp_date('Y-m-t', strtotime($start . ' 12:00:00'));
        $month_label = wp_date('F Y', strtotime($start . ' 12:00:00'));
        $definitions = [
            ['key' => 'open_freestyle', 'name' => 'Open Freestyle', 'capacity' => 20],
            ['key' => 'stick_puck', 'name' => 'Stick & Puck', 'capacity' => 25],
            ['key' => 'private_hockey', 'name' => 'Private Hockey Coaches Ice', 'capacity' => 25],
        ];

        $groups = [];
        $total_updates = 0;
        foreach ($definitions as $definition) {
            $target = self::find_standard_destination($definition['name'], $month_label);
            $events_result = IFDC_Client::get_events([
                'filter[start__gte]' => $start . 'T00:00:00',
                'filter[start__lte]' => $end . 'T23:59:59',
                'filter[desc__contains]' => $definition['name'],
                'sort' => 'start',
                'page[size]' => 500,
            ], [
                'force' => true,
                'cache' => false,
                'max_pages' => 10,
            ]);
            if (is_wp_error($events_result)) return $events_result;

            $found = 0;
            $replacement_count = 0;
            $capacity_count = 0;
            $updates = [];
            foreach ((array) ($events_result['data'] ?? []) as $record) {
                $attrs = self::attributes($record);
                if (stripos((string) ($attrs['desc'] ?? ''), $definition['name']) === false) continue;
                $found++;
                $current_team_id = absint($attrs['hteam_id'] ?? 0);
                $current_capacity = absint($attrs['register_capacity'] ?? 0);
                $team_needs_update = $target && $current_team_id !== absint($target['id']);
                $capacity_needs_update = $current_capacity !== absint($definition['capacity']);
                if (!$team_needs_update && !$capacity_needs_update) continue;
                if ($team_needs_update && $current_team_id) $replacement_count++;
                if ($capacity_needs_update) $capacity_count++;
                $updates[] = [
                    'id' => absint($record['id'] ?? 0),
                    'name' => sanitize_text_field($attrs['desc'] ?? $definition['name']),
                    'start' => sanitize_text_field($attrs['start'] ?? ''),
                    'resource_id' => absint($attrs['resource_id'] ?? 0),
                    'team_id' => $current_team_id,
                    'capacity' => $current_capacity,
                ];
            }
            usort($updates, function($a, $b) {
                return strcmp($a['start'], $b['start']) ?: ($a['id'] <=> $b['id']);
            });

            $group = [
                'key' => $definition['key'],
                'name' => $definition['name'],
                'capacity' => $definition['capacity'],
                'target' => $target,
                'found_count' => $found,
                'ready_count' => max(0, $found - count($updates)),
                'update_count' => count($updates),
                'replacement_count' => $replacement_count,
                'capacity_count' => $capacity_count,
                'events' => $updates,
            ];
            $groups[] = $group;
            if ($target) $total_updates += count($updates);
        }

        return [
            'month' => $month,
            'month_label' => $month_label,
            'groups' => $groups,
            'total_updates' => $total_updates,
            'can_apply' => $total_updates > 0 && count(array_filter($groups, function($group) { return empty($group['target']); })) === 0,
        ];
    }

    public static function ensure_nightly_schedule() {
        $scheduled = wp_get_scheduled_event(self::NIGHTLY_HOOK);
        if ($scheduled && $scheduled->schedule === 'hourly' && wp_date('i', $scheduled->timestamp) === '45') return;
        if ($scheduled) wp_clear_scheduled_hook(self::NIGHTLY_HOOK);

        $now = current_datetime();
        $next = $now->setTime(5, 45, 0);
        if ($next->getTimestamp() <= $now->getTimestamp()) {
            $candidate = $now->setTime((int) $now->format('H'), 45, 0);
            if ($candidate->getTimestamp() <= $now->getTimestamp()) $candidate = $candidate->modify('+1 hour');
            $next = (int) $candidate->format('H') <= 18 ? $candidate : $next->modify('+1 day');
        }
        wp_schedule_event($next->getTimestamp(), 'hourly', self::NIGHTLY_HOOK);
    }

    public static function clear_nightly_schedule() {
        wp_clear_scheduled_hook(self::NIGHTLY_HOOK);
        delete_transient(self::NIGHTLY_LOCK);
    }

    public static function admin_run_nightly() {
        if (!current_user_can(IFDC_Admin::CAP_ASSIGN_EVENTS)) wp_die('Permission denied.');
        check_admin_referer('ifdc_run_nightly_assignments');
        self::run_nightly();
        wp_safe_redirect(admin_url('admin.php?page=ifdc-event-assignment'));
        exit;
    }

    public static function run_scheduled() {
        $hour = (int) current_datetime()->format('G');
        if ($hour < 5 || $hour > 18) return;
        self::run_nightly();
    }

    public static function run_nightly() {
        if (get_transient(self::NIGHTLY_LOCK)) return new WP_Error('ifdc_assignment_running', 'The nightly assignment check is already running.');
        set_transient(self::NIGHTLY_LOCK, 1, 30 * MINUTE_IN_SECONDS);

        $summary = [
            'started_at' => time(),
            'finished_at' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'errors' => 0,
            'months' => [],
        ];

        if (!IFDC_Client::is_configured()) {
            $summary['errors'] = 1;
            $summary['message'] = 'The Dash Connector is not configured.';
        } else {
            $current = current_datetime()->modify('first day of this month');
            foreach ([$current, $current->modify('first day of next month')] as $date) {
                $month = $date->format('Y-m');
                $result = self::apply_standard_month($month);
                if (is_wp_error($result)) {
                    $summary['errors']++;
                    $summary['months'][$month] = ['error' => $result->get_error_message()];
                    continue;
                }
                $summary['updated'] += absint($result['updated'] ?? 0);
                $summary['unchanged'] += absint($result['unchanged'] ?? 0);
                $summary['errors'] += absint($result['errors'] ?? 0);
                $summary['months'][$month] = $result;
            }
        }

        $summary['finished_at'] = time();
        update_option(self::NIGHTLY_RESULT_OPTION, $summary, false);
        delete_transient(self::NIGHTLY_LOCK);
        return $summary;
    }

    private static function apply_standard_month($month) {
        $prepared = self::prepare_standard_month($month);
        if (is_wp_error($prepared)) return $prepared;

        $missing = array_filter($prepared['groups'], function($group) { return empty($group['target']['id']); });
        if ($missing) {
            return new WP_Error(
                'ifdc_destination_missing',
                'No unique destination was found for: ' . implode(', ', array_column($missing, 'name')) . '. Nothing was changed for this month.'
            );
        }

        $result = ['updated' => 0, 'unchanged' => 0, 'errors' => 0, 'error_messages' => []];
        foreach ($prepared['groups'] as $group) {
            $target_id = absint($group['target']['id']);
            $capacity = absint($group['capacity']);
            $result['unchanged'] += absint($group['ready_count'] ?? 0);
            foreach ((array) ($group['events'] ?? []) as $event) {
                $event_id = absint($event['id'] ?? 0);
                if (!$event_id) continue;
                $update = IFDC_Client::update_event_assignment($event_id, $target_id, $capacity);
                if (is_wp_error($update)) {
                    $result['errors']++;
                    $result['error_messages'][] = '#' . $event_id . ': ' . $update->get_error_message();
                    continue;
                }

                $verify_payload = IFDC_Client::get_data('events/' . $event_id, [], ['force' => true, 'cache' => false]);
                $verify = is_wp_error($verify_payload) ? null : self::data_record($verify_payload);
                $attrs = self::attributes($verify);
                if (
                    !$verify ||
                    absint($attrs['hteam_id'] ?? 0) !== $target_id ||
                    absint($attrs['register_capacity'] ?? 0) !== $capacity
                ) {
                    $result['errors']++;
                    $result['error_messages'][] = '#' . $event_id . ': Dash did not retain the verified assignment and capacity.';
                    continue;
                }
                $result['updated']++;
            }
        }
        return $result;
    }

    private static function find_standard_destination($name, $month_label) {
        $result = IFDC_Client::get_collection('teams', [
            'filter[name__contains]' => $name,
            'sort' => 'name',
            'page[size]' => 100,
        ], [
            'force' => true,
            'cache' => false,
            'max_pages' => 3,
        ]);
        if (is_wp_error($result)) return null;

        $target_text = self::normalize_match_text($name);
        $month_text = self::normalize_match_text($month_label);
        preg_match('/\b\d{4}\b/', $month_text, $year_match);
        $ranked = [];
        foreach ((array) ($result['data'] ?? []) as $record) {
            $attrs = self::attributes($record);
            if (!empty($attrs['inactive'])) continue;
            $team_name = sanitize_text_field($attrs['name'] ?? '');
            $team_text = self::normalize_match_text($team_name);
            $season_id = absint($attrs['season_id'] ?? 0);
            $level_id = absint($attrs['league_id'] ?? 0);
            $season_name = $season_id ? self::resource_name('seasons', $season_id) : '';
            $level_name = $level_id ? self::resource_name('leagues', $level_id) : '';
            $level_text = self::normalize_match_text($level_name);
            $season_text = self::normalize_match_text($season_name);
            $score = 0;
            if ($team_text === $target_text) $score += 320;
            elseif (substr($team_text, -strlen($target_text)) === $target_text) $score += 260;
            elseif (strpos($team_text, $target_text) !== false) $score += 210;
            if ($month_text && strpos($team_text, $month_text) === 0) $score += 100;
            if ($month_text && strpos($level_text, $month_text) !== false) $score += 130;
            if (!empty($year_match[0]) && strpos($season_text, $year_match[0]) !== false) $score += 20;
            $ranked[] = [
                'score' => $score,
                'id' => absint($record['id'] ?? 0),
                'name' => $team_name,
                'season_id' => $season_id,
                'season_name' => $season_name,
                'level_id' => $level_id,
                'level_name' => $level_name,
            ];
        }
        usort($ranked, function($a, $b) { return $b['score'] <=> $a['score']; });
        if (!$ranked || $ranked[0]['score'] < 250) return null;
        if (isset($ranked[1]) && $ranked[0]['score'] - $ranked[1]['score'] < 20) return null;
        unset($ranked[0]['score']);
        return $ranked[0];
    }

    private static function normalize_match_text($value) {
        $value = strtolower(str_replace('&', ' and ', (string) $value));
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value);
        return trim(preg_replace('/\s+/', ' ', $value));
    }

    public static function ajax_search_events() {
        self::guard_ajax();

        $start = self::sanitize_date($_POST['start'] ?? '');
        $end = self::sanitize_date($_POST['end'] ?? '');
        if (!$start || !$end) wp_send_json_error(['message' => 'Choose a valid start and end date.'], 400);
        if ($start > $end) wp_send_json_error(['message' => 'The start date must be on or before the end date.'], 400);

        $days = (int) floor((strtotime($end . ' 12:00:00') - strtotime($start . ' 12:00:00')) / DAY_IN_SECONDS) + 1;
        if ($days > self::MAX_RANGE_DAYS) {
            wp_send_json_error(['message' => 'Search up to ' . self::MAX_RANGE_DAYS . ' days at a time.'], 400);
        }

        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $event_type = absint($_POST['event_type'] ?? 0);
        $include_other = !empty($_POST['include_other']);
        $only_unlimited = !empty($_POST['only_unlimited']);
        $query = [
            'filter[start__gte]' => $start . 'T00:00:00',
            'filter[start__lte]' => $end . 'T23:59:59',
            'sort' => 'start',
            'page[size]' => 500,
        ];
        if ($name !== '') $query['filter[desc__contains]'] = $name;
        if ($event_type) $query['filter[event_type_id]'] = $event_type;

        $result = IFDC_Client::get_events($query, [
            'force' => true,
            'cache' => false,
            'max_pages' => 10,
        ]);
        if (is_wp_error($result)) self::send_wp_error($result);

        $events = [];
        $total_matches = 0;
        foreach ((array) ($result['data'] ?? []) as $record) {
            $attrs = self::attributes($record);
            if ($name !== '' && stripos((string) ($attrs['desc'] ?? ''), $name) === false) continue;
            if ($event_type && absint($attrs['event_type_id'] ?? 0) !== $event_type) continue;
            $current_team_id = absint($attrs['hteam_id'] ?? 0);
            $current_capacity = absint($attrs['register_capacity'] ?? 0);
            if (!$include_other && $current_team_id) continue;
            if ($only_unlimited && $current_capacity > 0) continue;
            $total_matches++;
            if (count($events) >= self::MAX_RESULTS) continue;
            $events[] = [
                'id' => absint($record['id'] ?? 0),
                'name' => sanitize_text_field($attrs['desc'] ?? 'Untitled event'),
                'start' => sanitize_text_field($attrs['start'] ?? ''),
                'end' => sanitize_text_field($attrs['end'] ?? ''),
                'event_type_id' => absint($attrs['event_type_id'] ?? 0),
                'resource_id' => absint($attrs['resource_id'] ?? 0),
                'team_id' => $current_team_id,
                'level_id' => absint($attrs['league_id'] ?? 0),
                'capacity' => array_key_exists('register_capacity', $attrs) && $attrs['register_capacity'] !== null ? absint($attrs['register_capacity']) : null,
            ];
        }

        usort($events, function($a, $b) {
            return strcmp($a['start'], $b['start']) ?: ($a['id'] <=> $b['id']);
        });

        wp_send_json_success([
            'events' => $events,
            'count' => count($events),
            'total_matches' => $total_matches,
            'limited' => $total_matches > self::MAX_RESULTS || !empty($result['meta']['truncated']),
        ]);
    }

    public static function ajax_search_teams() {
        self::guard_ajax();

        $term = sanitize_text_field(wp_unslash($_POST['term'] ?? ''));
        if ($term === '') wp_send_json_error(['message' => 'Enter a class name or team ID.'], 400);

        if (ctype_digit($term)) {
            $payload = IFDC_Client::get_team(absint($term), [], ['force' => true, 'cache' => false]);
            if (is_wp_error($payload)) self::send_wp_error($payload);
            $records = [self::data_record($payload)];
        } else {
            if (strlen($term) < 2) wp_send_json_error(['message' => 'Enter at least two characters.'], 400);
            $result = IFDC_Client::get_collection('teams', [
                'filter[name__contains]' => $term,
                'sort' => 'name',
                'page[size]' => 100,
            ], [
                'force' => true,
                'cache' => false,
                'max_pages' => 2,
            ]);
            if (is_wp_error($result)) self::send_wp_error($result);
            $record_map = [];
            foreach ((array) ($result['data'] ?? []) as $record) {
                $id = absint($record['id'] ?? 0);
                if ($id) $record_map[$id] = $record;
            }

            // Month-and-year destinations are commonly level names rather than
            // class/team names. Include every class under a matching level.
            $levels = IFDC_Client::get_collection('leagues', [
                'filter[name__contains]' => $term,
                'sort' => 'name',
                'page[size]' => 25,
            ], [
                'force' => true,
                'cache' => false,
                'max_pages' => 2,
            ]);
            if (!is_wp_error($levels)) {
                foreach (array_slice((array) ($levels['data'] ?? []), 0, 20) as $level) {
                    $level_id = absint($level['id'] ?? 0);
                    if (!$level_id) continue;
                    $level_teams = IFDC_Client::get_collection('teams', [
                        'filter[league_id]' => $level_id,
                        'sort' => 'name',
                        'page[size]' => 100,
                    ], [
                        'force' => true,
                        'cache' => false,
                        'max_pages' => 2,
                    ]);
                    if (is_wp_error($level_teams)) continue;
                    foreach ((array) ($level_teams['data'] ?? []) as $record) {
                        $id = absint($record['id'] ?? 0);
                        if ($id) $record_map[$id] = $record;
                    }
                }
            }
            $records = array_slice(array_values($record_map), 0, 40);
        }

        $season_names = [];
        $level_names = [];
        $teams = [];
        foreach ($records as $record) {
            if (!is_array($record)) continue;
            $attrs = self::attributes($record);
            $season_id = absint($attrs['season_id'] ?? 0);
            $level_id = absint($attrs['league_id'] ?? 0);
            if ($season_id && !array_key_exists($season_id, $season_names)) {
                $season_names[$season_id] = self::resource_name('seasons', $season_id);
            }
            if ($level_id && !array_key_exists($level_id, $level_names)) {
                $level_names[$level_id] = self::resource_name('leagues', $level_id);
            }
            $teams[] = [
                'id' => absint($record['id'] ?? 0),
                'name' => sanitize_text_field($attrs['name'] ?? 'Untitled class'),
                'season_id' => $season_id,
                'season_name' => $season_names[$season_id] ?? '',
                'level_id' => $level_id,
                'level_name' => $level_names[$level_id] ?? '',
                'inactive' => !empty($attrs['inactive']),
            ];
        }

        wp_send_json_success(['teams' => $teams, 'count' => count($teams)]);
    }

    public static function ajax_assign_batch() {
        self::guard_ajax();

        $team_id = absint($_POST['team_id'] ?? 0);
        $allow_reassign = !empty($_POST['allow_reassign']);
        $capacity_raw = sanitize_text_field(wp_unslash($_POST['capacity'] ?? ''));
        if ($capacity_raw !== '' && !ctype_digit($capacity_raw)) {
            wp_send_json_error(['message' => 'Capacity must be a whole number of zero or greater.'], 400);
        }
        $capacity = $capacity_raw === '' ? null : min(9999, absint($capacity_raw));
        $raw_ids = isset($_POST['event_ids']) ? (array) wp_unslash($_POST['event_ids']) : [];
        $raw_expected = isset($_POST['expected_team_ids']) ? (array) wp_unslash($_POST['expected_team_ids']) : [];
        $raw_expected_capacities = isset($_POST['expected_capacities']) ? (array) wp_unslash($_POST['expected_capacities']) : [];
        $expected_team_ids = [];
        $expected_capacities = [];
        foreach ($raw_expected as $event_id => $expected_team_id) {
            $expected_team_ids[absint($event_id)] = absint($expected_team_id);
        }
        foreach ($raw_expected_capacities as $event_id => $expected_capacity) {
            $expected_capacities[absint($event_id)] = absint($expected_capacity);
        }
        $event_ids = array_slice(array_values(array_unique(array_filter(array_map('absint', $raw_ids)))), 0, self::MAX_UPDATE_BATCH);
        if (!$event_ids || (!$team_id && $capacity === null)) {
            wp_send_json_error(['message' => 'Choose at least one event and either a destination class or a capacity.'], 400);
        }

        if ($team_id) {
            $team_payload = IFDC_Client::get_team($team_id, [], ['force' => true, 'cache' => false]);
            if (is_wp_error($team_payload)) self::send_wp_error($team_payload);
            $team_record = self::data_record($team_payload);
            if (!$team_record || absint($team_record['id'] ?? 0) !== $team_id) {
                wp_send_json_error(['message' => 'The selected destination class could not be verified.'], 400);
            }
        }

        $updated = [];
        $skipped = [];
        $errors = [];
        foreach ($event_ids as $event_id) {
            $current_payload = IFDC_Client::get_data('events/' . $event_id, [], ['force' => true, 'cache' => false]);
            if (is_wp_error($current_payload)) {
                $errors[] = ['id' => $event_id, 'message' => $current_payload->get_error_message()];
                continue;
            }
            $current = self::data_record($current_payload);
            $attrs = self::attributes($current);
            $current_team_id = absint($attrs['hteam_id'] ?? 0);
            $current_capacity = absint($attrs['register_capacity'] ?? 0);
            if (!array_key_exists($event_id, $expected_team_ids) || $current_team_id !== $expected_team_ids[$event_id]) {
                $errors[] = [
                    'id' => $event_id,
                    'message' => 'The assignment changed after the preview. Search again before updating this event.',
                ];
                continue;
            }
            if (!array_key_exists($event_id, $expected_capacities) || $current_capacity !== $expected_capacities[$event_id]) {
                $errors[] = [
                    'id' => $event_id,
                    'message' => 'The capacity changed after the preview. Search again before updating this event.',
                ];
                continue;
            }
            $team_matches = !$team_id || $current_team_id === $team_id;
            $capacity_matches = $capacity === null || $current_capacity === $capacity;
            if ($team_matches && $capacity_matches) {
                $skipped[] = [
                    'id' => $event_id,
                    'message' => 'The requested class/team and capacity are already set.',
                ];
                continue;
            }
            if ($team_id && $current_team_id && $current_team_id !== $team_id && !$allow_reassign) {
                $skipped[] = [
                    'id' => $event_id,
                    'message' => 'Assigned to class/team #' . $current_team_id . '; reassignment was not confirmed.',
                ];
                continue;
            }

            $result = IFDC_Client::update_event_assignment($event_id, $team_id ?: null, $capacity);
            if (is_wp_error($result)) {
                $errors[] = ['id' => $event_id, 'message' => $result->get_error_message()];
                continue;
            }

            $verify_payload = IFDC_Client::get_data('events/' . $event_id, [], ['force' => true, 'cache' => false]);
            if (is_wp_error($verify_payload)) {
                $errors[] = ['id' => $event_id, 'message' => 'Dash accepted the update, but verification failed: ' . $verify_payload->get_error_message()];
                continue;
            }
            $verify = self::data_record($verify_payload);
            $verify_attrs = self::attributes($verify);
            if ($team_id && absint($verify_attrs['hteam_id'] ?? 0) !== $team_id) {
                $errors[] = ['id' => $event_id, 'message' => 'Dash did not retain the selected class/team assignment.'];
                continue;
            }
            if (!$team_id && absint($verify_attrs['hteam_id'] ?? 0) !== $current_team_id) {
                $errors[] = ['id' => $event_id, 'message' => 'The capacity update unexpectedly changed the class/team assignment.'];
                continue;
            }
            if ($capacity !== null && absint($verify_attrs['register_capacity'] ?? 0) !== $capacity) {
                $errors[] = ['id' => $event_id, 'message' => 'Dash did not retain the requested event capacity.'];
                continue;
            }
            $updated[] = $event_id;
        }

        wp_send_json_success([
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
        ]);
    }

    private static function guard_ajax() {
        check_ajax_referer('ifdc_admin', 'nonce');
        if (!current_user_can(IFDC_Admin::CAP_ASSIGN_EVENTS)) wp_send_json_error(['message' => 'Permission denied.'], 403);
        if (!IFDC_Client::is_configured()) wp_send_json_error(['message' => 'The Dash Connector is not configured.'], 400);
    }

    private static function sanitize_date($value) {
        $value = sanitize_text_field(wp_unslash($value));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return '';
        [$year, $month, $day] = array_map('intval', explode('-', $value));
        return checkdate($month, $day, $year) ? $value : '';
    }

    private static function attributes($record) {
        return is_array($record) && is_array($record['attributes'] ?? null) ? $record['attributes'] : [];
    }

    private static function data_record($payload) {
        if (!is_array($payload)) return null;
        if (isset($payload['data']) && is_array($payload['data'])) return $payload['data'];
        return $payload;
    }

    private static function resource_name($type, $id) {
        $payload = IFDC_Client::get_data($type . '/' . absint($id), [], ['cache_ttl' => HOUR_IN_SECONDS]);
        if (is_wp_error($payload)) return '';
        $record = self::data_record($payload);
        $attrs = self::attributes($record);
        foreach (['name', 'title', 'desc', 'description'] as $key) {
            if (!empty($attrs[$key])) return sanitize_text_field($attrs[$key]);
        }
        return '';
    }

    private static function send_wp_error($error) {
        $data = $error->get_error_data();
        $status = is_array($data) ? absint($data['status'] ?? 0) : 0;
        wp_send_json_error([
            'message' => $error->get_error_message(),
            'details' => $data,
        ], $status >= 400 && $status < 600 ? $status : 500);
    }
}
