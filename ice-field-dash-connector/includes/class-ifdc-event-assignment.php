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
    const AUTOMATION_ENABLED_OPTION = 'ifdc_event_assignment_automation_enabled';
    const AUTOMATION_EMAIL_OPTION = 'ifdc_event_assignment_notification_emails';
    const AUTOMATION_HISTORY_OPTION = 'ifdc_event_assignment_history';
    const AUTOMATION_SUPPRESSED_OPTION = 'ifdc_event_assignment_suppressed_events';
    const MAX_HISTORY_ITEMS = 500;
    const PUBLIC_SKATING_EVENT_TYPE_ID = 10;
    const PUBLIC_SKATING_EXCLUDED_TEAM_IDS = [117, 118, 218, 219, 220, 221, 328, 329];

    public static function init() {
        add_action('wp_ajax_ifdc_search_assignment_events', [__CLASS__, 'ajax_search_events']);
        add_action('wp_ajax_ifdc_search_assignment_teams', [__CLASS__, 'ajax_search_teams']);
        add_action('wp_ajax_ifdc_prepare_standard_assignments', [__CLASS__, 'ajax_prepare_standard_assignments']);
        add_action('wp_ajax_ifdc_preview_completed_visibility', [__CLASS__, 'ajax_preview_completed_visibility']);
        add_action('wp_ajax_ifdc_apply_completed_visibility', [__CLASS__, 'ajax_apply_completed_visibility']);
        add_action('wp_ajax_ifdc_assign_event_batch', [__CLASS__, 'ajax_assign_batch']);
        add_action(self::NIGHTLY_HOOK, [__CLASS__, 'run_scheduled']);
        add_action('admin_post_ifdc_run_nightly_assignments', [__CLASS__, 'admin_run_nightly']);
        add_action('admin_post_ifdc_save_assignment_automation', [__CLASS__, 'admin_save_automation']);
        add_action('admin_post_ifdc_undo_automatic_event_update', [__CLASS__, 'admin_undo_automatic_event_update']);
        add_action('admin_post_ifdc_resume_automatic_event_update', [__CLASS__, 'admin_resume_automatic_event_update']);
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
        add_submenu_page(
            'ifdc-dashboard',
            'Automatic Update History',
            'Update History',
            IFDC_Admin::CAP_ASSIGN_EVENTS,
            'ifdc-update-history',
            [__CLASS__, 'history_page']
        );
    }

    public static function page() {
        $now = current_time('timestamp');
        $month = wp_date('Y-m', $now);
        $last_run = get_option(self::NIGHTLY_RESULT_OPTION, []);
        $next_run = wp_next_scheduled(self::NIGHTLY_HOOK);
        $automation_enabled = self::automation_enabled();
        $automation_emails = (array) get_option(self::AUTOMATION_EMAIL_OPTION, []);
        $automation_timezone = self::automation_timezone();
        $visibility_month = (new DateTimeImmutable('first day of last month', $automation_timezone))->format('Y-m');
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
                        <h2>Prepare All 4 Sessions</h2>
                        <p class="description">Find destinations, class assignments, and capacity corrections for the four recurring session types in one pass.</p>
                    </div>
                    <label class="ifdc-standard-month">Month
                        <input type="month" id="ifdc-standard-assignment-month" value="<?php echo esc_attr($month); ?>">
                    </label>
                </div>
                <div class="ifdc-standard-definitions">
                    <span><strong>Open Freestyle</strong><small>Capacity 20</small></span>
                    <span><strong>Stick &amp; Puck</strong><small>Capacity 25</small></span>
                    <span><strong>Private Hockey Coaches Ice</strong><small>Capacity 25</small></span>
                    <span><strong>Public Skating</strong><small>Capacity 250 · themed events protected</small></span>
                </div>
                <div class="notice notice-info inline">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="ifdc_save_assignment_automation">
                        <?php wp_nonce_field('ifdc_save_assignment_automation'); ?>
                        <p><label><input type="checkbox" name="automation_enabled" value="1" <?php checked($automation_enabled); ?>> <strong>Enable automatic event assignment checks on this website</strong></label></p>
                        <p class="description">Disabled by default. When enabled, checks this month and next month hourly from 5:45 AM through 6:45 PM in <strong><?php echo esc_html($automation_timezone->getName()); ?></strong>, read from Displays. It also makes the previous completed month's recurring-session Teams inactive so empty registration cards disappear.</p>
                        <p><label for="ifdc-automation-emails"><strong>Email automatic event-update summaries to</strong></label><br>
                            <textarea id="ifdc-automation-emails" name="automation_emails" class="large-text" rows="2" placeholder="<?php echo esc_attr(get_option('admin_email')); ?>"><?php echo esc_textarea(implode(', ', $automation_emails)); ?></textarea>
                            <span class="description">Separate multiple addresses with commas or new lines. Mail is sent only when an automatic scheduled run changes one or more events.</span>
                        </p>
                        <p><button type="submit" class="button button-primary">Save Automation Setting</button></p>
                    </form>
                    <?php if ($automation_enabled): ?>
                        <p><?php echo $next_run ? 'Next scheduled check: ' . esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $next_run, $automation_timezone)) . '.' : 'The next check is being scheduled.'; ?></p>
                    <?php else: ?>
                        <p><strong>Automatic checks are disabled on this website.</strong></p>
                    <?php endif; ?>
                    <?php if (is_array($last_run) && !empty($last_run['finished_at'])): ?>
                        <p>Last run: <?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), absint($last_run['finished_at']), $automation_timezone)); ?> — <?php echo esc_html(absint($last_run['updated'] ?? 0)); ?> updated, <?php echo esc_html(absint($last_run['unchanged'] ?? 0)); ?> already correct, <?php echo esc_html(absint($last_run['errors'] ?? 0)); ?> errors.</p>
                    <?php endif; ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="ifdc_run_nightly_assignments">
                        <?php wp_nonce_field('ifdc_run_nightly_assignments'); ?>
                        <button type="submit" class="button">Run Current + Next Month Now</button>
                    </form>
                </div>
                <p>
                    <button type="button" class="button button-primary" id="ifdc-prepare-standard-assignments">Prepare All 4</button>
                    <button type="button" class="button button-primary" id="ifdc-apply-standard-assignments" disabled>Update Selected Groups</button>
                </p>
                <div id="ifdc-standard-assignment-status" class="ifdc-result" hidden></div>
                <div id="ifdc-standard-assignment-preview" class="ifdc-empty-state">
                    <span class="dashicons dashicons-list-view"></span>
                    <h3>No monthly preview loaded</h3>
                    <p>Preparing a preview does not change Dash.</p>
                </div>
            </section>

            <section class="ifdc-card ifdc-visibility-card">
                <div class="ifdc-card-heading">
                    <div>
                        <p class="ifdc-eyebrow">Registration cleanup pilot</p>
                        <h2>Completed Monthly Visibility</h2>
                        <p class="description">Preview the four recurring monthly Teams before making completed months inactive and turning off Online Registration in Dash. Levels and events remain unchanged.</p>
                    </div>
                    <label class="ifdc-standard-month">Completed month
                        <input type="month" id="ifdc-completed-visibility-month" value="<?php echo esc_attr($visibility_month); ?>">
                    </label>
                </div>
                <label class="ifdc-checkbox-row">
                    <input type="checkbox" id="ifdc-completed-visibility-previous">
                    Include this month and all previous months
                </label>
                <p>
                    <button type="button" class="button button-primary" id="ifdc-preview-completed-visibility">Preview Completed Month</button>
                    <button type="button" class="button button-primary" id="ifdc-apply-completed-visibility" disabled>Deactivate Selected Teams</button>
                </p>
                <div id="ifdc-completed-visibility-status" class="ifdc-result" hidden></div>
                <div id="ifdc-completed-visibility-preview" class="ifdc-empty-state">
                    <span class="dashicons dashicons-visibility"></span>
                    <h3>No visibility preview loaded</h3>
                    <p>Previewing does not change Dash.</p>
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
                        <p class="description">Defaults to 20 for Freestyle, 25 for hockey or Stick &amp; Puck, and 250 for Public Skating event type 10. Clear the field to preserve existing capacities.</p>
                    </div>
                    <div class="ifdc-capacity-setting">
                        <label for="ifdc-assignment-event-name"><strong>Set event name</strong> <span class="description">(optional)</span></label>
                        <input type="text" class="regular-text" id="ifdc-assignment-event-name" placeholder="Preserve existing event names">
                        <p class="description">Enter a consistent name to include already-assigned events whose names differ. Event type 10 defaults to Public Skating.</p>
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

    public static function ajax_preview_completed_visibility() {
        self::guard_ajax();
        $month = sanitize_text_field(wp_unslash($_POST['month'] ?? ''));
        $preview = self::prepare_completed_visibility($month, !empty($_POST['include_previous']));
        if (is_wp_error($preview)) self::send_wp_error($preview);
        wp_send_json_success($preview);
    }

    public static function ajax_apply_completed_visibility() {
        self::guard_ajax();
        $month = sanitize_text_field(wp_unslash($_POST['month'] ?? ''));
        $include_previous = !empty($_POST['include_previous']);
        $requested_keys = array_values(array_unique(array_filter(array_map('sanitize_key', (array) wp_unslash($_POST['group_keys'] ?? [])))));
        if (!$requested_keys) wp_send_json_error(['message' => 'Select at least one completed session group.'], 400);
        $result = self::apply_completed_team_visibility($month, $requested_keys, $include_previous);
        if (is_wp_error($result)) self::send_wp_error($result);
        wp_send_json_success($result);
    }

    private static function apply_completed_team_visibility($month, $requested_keys = null, $include_previous = false) {
        $preview = self::prepare_completed_visibility($month, $include_previous);
        if (is_wp_error($preview)) return $preview;
        $groups = [];
        foreach ((array) ($preview['groups'] ?? []) as $group) $groups[$group['key']] = $group;
        if ($requested_keys === null) {
            $requested_keys = array_keys(array_filter($groups, function($group) { return !empty($group['needs_update']); }));
        }

        $updated_teams = [];
        $skipped = [];
        $errors = [];
        foreach ((array) $requested_keys as $key) {
            $group = $groups[$key] ?? null;
            if (!$group || empty($group['target']['id']) || empty($group['needs_update'])) {
                $skipped[] = ['group' => $key, 'message' => 'The completed month no longer has an active, uniquely matched Team.'];
                continue;
            }
            $team_id = absint($group['target']['id']);
            $result = IFDC_Client::update_team_visibility($team_id, true);
            if (is_wp_error($result)) {
                $errors[] = ['id' => $team_id, 'message' => $result->get_error_message()];
                continue;
            }
            $verify_payload = IFDC_Client::get_team($team_id, [], ['force' => true, 'cache' => false]);
            $verify = is_wp_error($verify_payload) ? null : self::data_record($verify_payload);
            $verify_attrs = self::attributes($verify);
            if (!$verify || empty($verify_attrs['inactive']) || !empty($verify_attrs['online_signup'])) {
                $errors[] = ['id' => $team_id, 'message' => 'Dash did not retain both the inactive and Online Registration-off Team settings.'];
                continue;
            }
            $updated_teams[] = $team_id;
        }
        return ['updated_teams' => $updated_teams, 'skipped' => $skipped, 'errors' => $errors];
    }

    private static function prepare_completed_visibility($month, $include_previous = false) {
        if (!preg_match('/^(\d{4})-(\d{2})$/', $month, $matches) || !checkdate(absint($matches[2]), 1, absint($matches[1]))) {
            return new WP_Error('ifdc_invalid_month', 'Choose a valid completed month.');
        }
        $timezone = self::automation_timezone();
        $selected = DateTimeImmutable::createFromFormat('!Y-m', $month, $timezone);
        $current = new DateTimeImmutable('first day of this month', $timezone);
        if (!$selected || $selected >= $current) return new WP_Error('ifdc_month_not_completed', 'Choose a month before the current month.');

        $start = $selected->format('Y-m-01');
        $end = $selected->format('Y-m-t');
        $month_label = $selected->format('F Y');
        if ($include_previous) return self::prepare_completed_visibility_range($selected, $month, $month_label);
        $definitions = [
            ['key' => 'open_freestyle', 'name' => 'Open Freestyle', 'include_inactive_destination' => true],
            ['key' => 'stick_puck', 'name' => 'Stick & Puck', 'include_inactive_destination' => true],
            ['key' => 'private_hockey', 'name' => 'Private Hockey Coaches Ice', 'include_inactive_destination' => true],
            ['key' => 'public_skating', 'name' => 'Public Skating', 'event_type_id' => self::PUBLIC_SKATING_EVENT_TYPE_ID, 'destination_resolver' => 'public_skating', 'include_inactive_destination' => true],
        ];

        // Fetch the completed month's schedule once. Repeating this paginated
        // request for every session group can exceed shorter admin-AJAX timeouts.
        $events_result = IFDC_Client::get_events([
            'filter[start__gte]' => $start . 'T00:00:00',
            'filter[start__lte]' => $end . 'T23:59:59',
            'sort' => 'start',
            'page[size]' => 500,
        ], ['force' => true, 'cache' => false, 'max_pages' => 10]);
        if (is_wp_error($events_result)) return $events_result;
        $month_events = (array) ($events_result['data'] ?? []);

        $groups = [];
        foreach ($definitions as $definition) {
            $target = self::find_standard_destination($definition['name'], $month_label, $definition);
            $active_event_ids = [];
            $inactive_event_count = 0;
            $protected_event_count = 0;
            if ($target) {
                foreach ($month_events as $event) {
                    $attrs = self::attributes($event);
                    if (absint($attrs['hteam_id'] ?? 0) !== absint($target['id'])) continue;
                    if ($definition['key'] === 'public_skating' && self::is_protected_public_skating_event($attrs)) {
                        $protected_event_count++;
                        continue;
                    }
                    if (!empty($attrs['inactive'])) $inactive_event_count++;
                    else $active_event_ids[] = absint($event['id'] ?? 0);
                }
            }

            $team_inactive = !empty($target['inactive']);
            $team_online_signup = !empty($target['online_signup']);
            $groups[] = [
                'key' => $definition['key'],
                'name' => $definition['name'],
                'target' => $target,
                'team_inactive' => $team_inactive,
                'team_online_signup' => $team_online_signup,
                'active_event_ids' => array_values(array_filter($active_event_ids)),
                'active_event_count' => count(array_filter($active_event_ids)),
                'inactive_event_count' => $inactive_event_count,
                'protected_event_count' => $protected_event_count,
                'needs_update' => $target && !$protected_event_count && (!$team_inactive || $team_online_signup),
            ];
        }

        return ['month' => $month, 'month_label' => $month_label, 'groups' => $groups];
    }

    private static function prepare_completed_visibility_range($cutoff, $month, $month_label) {
        $teams_result = IFDC_Client::get_collection('teams', [
            'sort' => 'name',
            'page[size]' => 500,
        ], ['force' => true, 'cache' => false, 'max_pages' => 25]);
        if (is_wp_error($teams_result)) return $teams_result;
        if (!empty($teams_result['meta']['truncated'])) return new WP_Error('ifdc_team_list_truncated', 'Dash returned more Teams than could be safely reviewed. Use the single-month option instead.');

        $levels_result = IFDC_Client::get_collection('leagues', [
            'sort' => 'name',
            'page[size]' => 500,
        ], ['force' => true, 'cache' => false, 'max_pages' => 25]);
        if (is_wp_error($levels_result)) return $levels_result;
        if (!empty($levels_result['meta']['truncated'])) return new WP_Error('ifdc_level_list_truncated', 'Dash returned more Levels than could be safely reviewed. Use the single-month option instead.');

        $level_names = [];
        foreach ((array) ($levels_result['data'] ?? []) as $level) {
            $attrs = self::attributes($level);
            $level_names[absint($level['id'] ?? 0)] = sanitize_text_field($attrs['name'] ?? '');
        }
        $definitions = [
            'open_freestyle' => 'Open Freestyle',
            'stick_puck' => 'Stick & Puck',
            'private_hockey' => 'Private Hockey Coaches Ice',
        ];
        $month_pattern = '(January|February|March|April|May|June|July|August|September|October|November|December)\s+(20\d{2})';
        $groups = [];

        foreach ((array) ($teams_result['data'] ?? []) as $team) {
            $attrs = self::attributes($team);
            $team_id = absint($team['id'] ?? 0);
            if (!$team_id) continue;
            $team_name = sanitize_text_field($attrs['name'] ?? '');
            $level_id = absint($attrs['league_id'] ?? 0);
            $level_name = $level_names[$level_id] ?? '';
            $date_text = $team_name . ' ' . $level_name;
            if (!preg_match('/' . $month_pattern . '/i', $date_text, $date_match)) continue;
            $team_month = DateTimeImmutable::createFromFormat('!F Y', ucfirst(strtolower($date_match[1])) . ' ' . $date_match[2], self::automation_timezone());
            if (!$team_month || $team_month > $cutoff) continue;

            $key = '';
            $name = '';
            $team_text = self::normalize_match_text($team_name);
            foreach ($definitions as $definition_key => $definition_name) {
                if (strpos($team_text, self::normalize_match_text($definition_name)) !== false) {
                    $key = $definition_key;
                    $name = $definition_name;
                    break;
                }
            }
            if ($key === '') {
                $expected_level = self::normalize_match_text($team_month->format('Y') . ' Public Skating');
                if (
                    self::normalize_match_text($level_name) === $expected_level &&
                    self::normalize_match_text($team_name) === self::normalize_match_text($team_month->format('F Y'))
                ) {
                    $key = 'public_skating';
                    $name = 'Public Skating';
                }
            }
            if ($key === '') continue;
            if ($key === 'public_skating' && in_array($team_id, self::PUBLIC_SKATING_EXCLUDED_TEAM_IDS, true)) continue;

            $inactive = !empty($attrs['inactive']);
            $online_signup = !empty($attrs['online_signup']);
            $groups[] = [
                'key' => sanitize_key($key . '_' . $team_id),
                'name' => $name . ' — ' . $team_month->format('F Y'),
                'target' => [
                    'id' => $team_id,
                    'name' => $team_name,
                    'season_id' => absint($attrs['season_id'] ?? 0),
                    'season_name' => '',
                    'level_id' => $level_id,
                    'level_name' => $level_name,
                    'inactive' => $inactive,
                    'online_signup' => $online_signup,
                ],
                'team_inactive' => $inactive,
                'team_online_signup' => $online_signup,
                'active_event_count' => 0,
                'inactive_event_count' => 0,
                'protected_event_count' => 0,
                'needs_update' => !$inactive || $online_signup,
                'sort_month' => $team_month->format('Y-m'),
            ];
        }

        usort($groups, function($a, $b) {
            return strcmp($b['sort_month'], $a['sort_month']) ?: strcmp($a['name'], $b['name']);
        });
        foreach ($groups as &$group) unset($group['sort_month']);
        unset($group);
        return [
            'month' => $month,
            'month_label' => $month_label . ' and previous months',
            'include_previous' => true,
            'groups' => $groups,
        ];
    }

    private static function prepare_standard_month($month, $respect_suppression = false) {
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
        $definitions[] = [
            'key' => 'public_skating',
            'name' => 'Public Skating',
            'capacity' => 250,
            'event_type_id' => self::PUBLIC_SKATING_EVENT_TYPE_ID,
            'destination_resolver' => 'public_skating',
            'automate_name' => true,
        ];
        $suppressed_event_ids = array_values(array_filter(array_map('absint', (array) get_option(self::AUTOMATION_SUPPRESSED_OPTION, []))));

        $groups = [];
        $total_updates = 0;
        foreach ($definitions as $definition) {
            $target = self::find_standard_destination($definition['name'], $month_label, $definition);
            $event_query = [
                'filter[start__gte]' => $start . 'T00:00:00',
                'filter[start__lte]' => $end . 'T23:59:59',
                'sort' => 'start',
                'page[size]' => 500,
            ];
            if (!empty($definition['event_type_id'])) {
                $event_query['filter[event_type_id]'] = absint($definition['event_type_id']);
            } else {
                $event_query['filter[desc__contains]'] = $definition['name'];
            }
            $events_result = IFDC_Client::get_events($event_query, [
                'force' => true,
                'cache' => false,
                'max_pages' => 10,
            ]);
            if (is_wp_error($events_result)) return $events_result;

            $found = 0;
            $replacement_count = 0;
            $capacity_count = 0;
            $rename_count = 0;
            $base_update_count = 0;
            $updates = [];
            foreach ((array) ($events_result['data'] ?? []) as $record) {
                $attrs = self::attributes($record);
                $record_id = absint($record['id'] ?? 0);
                if ($respect_suppression && in_array($record_id, $suppressed_event_ids, true)) continue;
                if (!empty($definition['event_type_id'])) {
                    if (absint($attrs['event_type_id'] ?? 0) !== absint($definition['event_type_id'])) continue;
                } elseif (stripos((string) ($attrs['desc'] ?? ''), $definition['name']) === false) {
                    continue;
                }
                $current_team_id = absint($attrs['hteam_id'] ?? 0);
                if (
                    !empty($definition['event_type_id']) &&
                    self::is_protected_public_skating_event($attrs)
                ) continue;
                $found++;
                $current_capacity = absint($attrs['register_capacity'] ?? 0);
                $current_name = sanitize_text_field($attrs['desc'] ?? $definition['name']);
                $team_needs_update = $target && $current_team_id !== absint($target['id']);
                $capacity_needs_update = $definition['capacity'] !== null && $current_capacity !== absint($definition['capacity']);
                $name_needs_update = strcasecmp(trim($current_name), trim($definition['name'])) !== 0;
                $automated_name_needs_update = !empty($definition['automate_name']) && $name_needs_update;
                if (!$team_needs_update && !$capacity_needs_update && !$name_needs_update) continue;
                if ($team_needs_update && $current_team_id) $replacement_count++;
                if ($capacity_needs_update) $capacity_count++;
                if ($name_needs_update) $rename_count++;
                if ($team_needs_update || $capacity_needs_update || $automated_name_needs_update) $base_update_count++;
                $updates[] = [
                    'id' => $record_id,
                    'name' => $current_name,
                    'start' => sanitize_text_field($attrs['start'] ?? ''),
                    'resource_id' => absint($attrs['resource_id'] ?? 0),
                    'team_id' => $current_team_id,
                    'capacity' => $current_capacity,
                    'assignment_update' => $team_needs_update || $capacity_needs_update || $automated_name_needs_update,
                    'name_mismatch' => $name_needs_update,
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
                'ready_count' => max(0, $found - $base_update_count),
                'update_count' => $base_update_count,
                'replacement_count' => $replacement_count,
                'capacity_count' => $capacity_count,
                'rename_count' => $rename_count,
                'event_name' => $definition['name'],
                'automate_name' => !empty($definition['automate_name']),
                'manual_only' => !empty($definition['manual_only']),
                'events' => $updates,
            ];
            $groups[] = $group;
            if ($target) $total_updates += $base_update_count;
        }

        return [
            'month' => $month,
            'month_label' => $month_label,
            'groups' => $groups,
            'total_updates' => $total_updates,
            'can_apply' => $total_updates > 0 && count(array_filter($groups, function($group) {
                return absint($group['update_count'] ?? 0) > 0 && empty($group['target']);
            })) === 0,
        ];
    }

    public static function ensure_nightly_schedule() {
        if (!self::automation_enabled()) {
            if (wp_next_scheduled(self::NIGHTLY_HOOK)) wp_clear_scheduled_hook(self::NIGHTLY_HOOK);
            return;
        }
        $scheduled = wp_get_scheduled_event(self::NIGHTLY_HOOK);
        $timezone = self::automation_timezone();
        if ($scheduled && $scheduled->schedule === 'hourly' && wp_date('i', $scheduled->timestamp, $timezone) === '45') return;
        if ($scheduled) wp_clear_scheduled_hook(self::NIGHTLY_HOOK);

        $now = new DateTimeImmutable('now', $timezone);
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

    public static function admin_save_automation() {
        if (!current_user_can(IFDC_Admin::CAP_ASSIGN_EVENTS)) wp_die('Permission denied.');
        check_admin_referer('ifdc_save_assignment_automation');
        update_option(self::AUTOMATION_ENABLED_OPTION, !empty($_POST['automation_enabled']) ? 1 : 0, false);
        $raw_emails = preg_split('/[\s,;]+/', (string) wp_unslash($_POST['automation_emails'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        $emails = array_values(array_unique(array_filter(array_map('sanitize_email', (array) $raw_emails), 'is_email')));
        update_option(self::AUTOMATION_EMAIL_OPTION, $emails, false);
        wp_clear_scheduled_hook(self::NIGHTLY_HOOK);
        self::ensure_nightly_schedule();
        wp_safe_redirect(admin_url('admin.php?page=ifdc-event-assignment'));
        exit;
    }

    public static function run_scheduled() {
        if (!self::automation_enabled()) return;
        $hour = (int) (new DateTimeImmutable('now', self::automation_timezone()))->format('G');
        if ($hour < 5 || $hour > 18) return;
        self::run_nightly(true);
    }

    public static function run_nightly($automatic = false) {
        if (get_transient(self::NIGHTLY_LOCK)) return new WP_Error('ifdc_assignment_running', 'The nightly assignment check is already running.');
        set_transient(self::NIGHTLY_LOCK, 1, 30 * MINUTE_IN_SECONDS);

        $summary = [
            'started_at' => time(),
            'finished_at' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'errors' => 0,
            'months' => [],
            'visibility_cleanup' => [],
            'event_changes' => [],
        ];

        if (!IFDC_Client::is_configured()) {
            $summary['errors'] = 1;
            $summary['message'] = 'The Dash Connector is not configured.';
        } else {
            $current = (new DateTimeImmutable('now', self::automation_timezone()))->modify('first day of this month');
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
                $summary['event_changes'] = array_merge($summary['event_changes'], (array) ($result['changes'] ?? []));
                $summary['months'][$month] = $result;
            }

            $completed_month = $current->modify('first day of previous month')->format('Y-m');
            $visibility = self::apply_completed_team_visibility($completed_month);
            if (is_wp_error($visibility)) {
                $summary['errors']++;
                $summary['visibility_cleanup'] = [
                    'month' => $completed_month,
                    'error' => $visibility->get_error_message(),
                ];
            } else {
                $visibility_errors = count((array) ($visibility['errors'] ?? []));
                $summary['updated'] += count((array) ($visibility['updated_teams'] ?? []));
                $summary['unchanged'] += count((array) ($visibility['skipped'] ?? []));
                $summary['errors'] += $visibility_errors;
                $summary['visibility_cleanup'] = array_merge(['month' => $completed_month], $visibility);
            }
        }

        $summary['finished_at'] = time();
        if ($automatic && !empty($summary['event_changes'])) {
            self::store_automatic_history($summary['event_changes'], $summary['finished_at']);
            self::send_automatic_update_email($summary);
        }
        update_option(self::NIGHTLY_RESULT_OPTION, $summary, false);
        delete_transient(self::NIGHTLY_LOCK);
        return $summary;
    }

    private static function store_automatic_history($changes, $timestamp) {
        $history = (array) get_option(self::AUTOMATION_HISTORY_OPTION, []);
        $new_items = [];
        foreach ((array) $changes as $change) {
            if (empty($change['event_id'])) continue;
            $change['history_id'] = wp_generate_uuid4();
            $change['changed_at'] = absint($timestamp);
            $change['undone_at'] = 0;
            $new_items[] = $change;
        }
        update_option(self::AUTOMATION_HISTORY_OPTION, array_slice(array_merge(array_reverse($new_items), $history), 0, self::MAX_HISTORY_ITEMS), false);
    }

    private static function send_automatic_update_email($summary) {
        $recipients = array_values(array_filter((array) get_option(self::AUTOMATION_EMAIL_OPTION, []), 'is_email'));
        if (!$recipients) return;
        $changes = (array) ($summary['event_changes'] ?? []);
        if (!$changes) return;

        $site_name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $subject = sprintf('[%s] Dash Connector automatically updated %d event%s', $site_name, count($changes), count($changes) === 1 ? '' : 's');
        $lines = [
            $site_name . ' automatically updated ' . count($changes) . ' Dash event' . (count($changes) === 1 ? '' : 's') . '.',
            '',
        ];
        foreach ($changes as $change) {
            $before = (array) ($change['before'] ?? []);
            $after = (array) ($change['after'] ?? []);
            $lines[] = sprintf(
                '#%d %s%s — Team %d → %d; capacity %d → %d; name “%s” → “%s”',
                absint($change['event_id'] ?? 0),
                sanitize_text_field($change['event_name'] ?? 'Event'),
                !empty($change['event_start']) ? ' (' . sanitize_text_field($change['event_start']) . ')' : '',
                absint($before['team_id'] ?? 0),
                absint($after['team_id'] ?? 0),
                absint($before['capacity'] ?? 0),
                absint($after['capacity'] ?? 0),
                sanitize_text_field($before['name'] ?? ''),
                sanitize_text_field($after['name'] ?? '')
            );
        }
        $lines[] = '';
        $lines[] = 'Review history or use guarded Undo: ' . admin_url('admin.php?page=ifdc-update-history');
        wp_mail($recipients, $subject, implode("\n", $lines));
    }

    public static function history_page() {
        if (!current_user_can(IFDC_Admin::CAP_ASSIGN_EVENTS)) wp_die('Permission denied.');
        $history = (array) get_option(self::AUTOMATION_HISTORY_OPTION, []);
        $suppressed = array_values(array_filter(array_map('absint', (array) get_option(self::AUTOMATION_SUPPRESSED_OPTION, []))));
        $notice = sanitize_key($_GET['ifdc_history_notice'] ?? '');
        ?>
        <div class="wrap ifdc-wrap">
            <h1>Automatic Update History</h1>
            <p class="ifdc-lead">Verified event changes made by scheduled Dash Connector runs. The newest 500 changes are retained.</p>
            <?php if ($notice): ?>
                <div class="notice <?php echo $notice === 'undone' ? 'notice-success' : 'notice-error'; ?> inline"><p><?php echo $notice === 'undone' ? 'The event was restored to its recorded previous state.' : 'Undo was not performed because the event no longer matches the automatic update or Dash rejected the restoration.'; ?></p></div>
            <?php endif; ?>
            <section class="ifdc-card">
                <?php if (!$history): ?>
                    <p>No automatic event changes have been recorded yet.</p>
                <?php else: ?>
                    <div class="ifdc-table-wrap"><table class="widefat striped">
                        <thead><tr><th>Changed</th><th>Event</th><th>Group</th><th>Before</th><th>After</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($history as $item):
                            $before = (array) ($item['before'] ?? []);
                            $after = (array) ($item['after'] ?? []);
                            $undone = !empty($item['undone_at']);
                            $paused = in_array(absint($item['event_id'] ?? 0), $suppressed, true);
                        ?>
                            <tr>
                                <td><?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), absint($item['changed_at'] ?? 0), self::automation_timezone())); ?></td>
                                <td><strong>#<?php echo esc_html(absint($item['event_id'] ?? 0)); ?> <?php echo esc_html($item['event_name'] ?? 'Event'); ?></strong><br><span class="description"><?php echo esc_html($item['event_start'] ?? ''); ?></span></td>
                                <td><?php echo esc_html($item['group'] ?? ''); ?></td>
                                <td>Team #<?php echo esc_html(absint($before['team_id'] ?? 0)); ?><br>Capacity <?php echo esc_html(absint($before['capacity'] ?? 0)); ?><br><?php echo esc_html($before['name'] ?? ''); ?></td>
                                <td>Team #<?php echo esc_html(absint($after['team_id'] ?? 0)); ?><br>Capacity <?php echo esc_html(absint($after['capacity'] ?? 0)); ?><br><?php echo esc_html($after['name'] ?? ''); ?></td>
                                <td>
                                    <?php if ($undone): ?>
                                        <span class="ifdc-status is-ready">Undone<?php echo $paused ? ' · automation paused' : ''; ?></span>
                                        <?php if ($paused): ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px">
                                            <input type="hidden" name="action" value="ifdc_resume_automatic_event_update">
                                            <input type="hidden" name="event_id" value="<?php echo esc_attr(absint($item['event_id'] ?? 0)); ?>">
                                            <?php wp_nonce_field('ifdc_resume_automatic_event_update_' . absint($item['event_id'] ?? 0)); ?>
                                            <button type="submit" class="button button-small">Resume automation</button>
                                        </form><?php endif; ?>
                                    <?php else: ?>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Restore this event and prevent future automatic changes to it?');">
                                            <input type="hidden" name="action" value="ifdc_undo_automatic_event_update">
                                            <input type="hidden" name="history_id" value="<?php echo esc_attr($item['history_id'] ?? ''); ?>">
                                            <?php wp_nonce_field('ifdc_undo_automatic_event_update_' . ($item['history_id'] ?? '')); ?>
                                            <button type="submit" class="button">Undo &amp; prevent repeat</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                <?php endif; ?>
            </section>
        </div>
        <?php
    }

    public static function admin_undo_automatic_event_update() {
        if (!current_user_can(IFDC_Admin::CAP_ASSIGN_EVENTS)) wp_die('Permission denied.');
        $history_id = sanitize_text_field(wp_unslash($_POST['history_id'] ?? ''));
        check_admin_referer('ifdc_undo_automatic_event_update_' . $history_id);
        $history = (array) get_option(self::AUTOMATION_HISTORY_OPTION, []);
        $index = null;
        foreach ($history as $key => $item) {
            if (hash_equals((string) ($item['history_id'] ?? ''), $history_id)) { $index = $key; break; }
        }
        $success = false;
        if ($index !== null && empty($history[$index]['undone_at'])) {
            $item = $history[$index];
            $after = (array) ($item['after'] ?? []);
            $before = (array) ($item['before'] ?? []);
            $payload = IFDC_Client::get_data('events/' . absint($item['event_id'] ?? 0), [], ['force' => true, 'cache' => false]);
            $record = is_wp_error($payload) ? null : self::data_record($payload);
            $attrs = self::attributes($record);
            $matches = $record &&
                absint($attrs['hteam_id'] ?? 0) === absint($after['team_id'] ?? 0) &&
                absint($attrs['register_capacity'] ?? 0) === absint($after['capacity'] ?? 0) &&
                sanitize_text_field($attrs['desc'] ?? '') === sanitize_text_field($after['name'] ?? '');
            if ($matches) {
                $restore = IFDC_Client::restore_automatic_event_state(
                    absint($item['event_id'] ?? 0),
                    absint($before['team_id'] ?? 0),
                    absint($before['capacity'] ?? 0),
                    sanitize_text_field($before['name'] ?? '')
                );
                if (!is_wp_error($restore)) {
                    $verify_payload = IFDC_Client::get_data('events/' . absint($item['event_id'] ?? 0), [], ['force' => true, 'cache' => false]);
                    $verify = is_wp_error($verify_payload) ? null : self::data_record($verify_payload);
                    $verify_attrs = self::attributes($verify);
                    $success = $verify &&
                        absint($verify_attrs['hteam_id'] ?? 0) === absint($before['team_id'] ?? 0) &&
                        absint($verify_attrs['register_capacity'] ?? 0) === absint($before['capacity'] ?? 0) &&
                        sanitize_text_field($verify_attrs['desc'] ?? '') === sanitize_text_field($before['name'] ?? '');
                }
            }
        }
        if ($success) {
            $history[$index]['undone_at'] = time();
            update_option(self::AUTOMATION_HISTORY_OPTION, $history, false);
            $suppressed = array_values(array_unique(array_filter(array_map('absint', (array) get_option(self::AUTOMATION_SUPPRESSED_OPTION, [])))));
            $suppressed[] = absint($history[$index]['event_id'] ?? 0);
            update_option(self::AUTOMATION_SUPPRESSED_OPTION, array_values(array_unique(array_filter($suppressed))), false);
        }
        wp_safe_redirect(admin_url('admin.php?page=ifdc-update-history&ifdc_history_notice=' . ($success ? 'undone' : 'blocked')));
        exit;
    }

    public static function admin_resume_automatic_event_update() {
        if (!current_user_can(IFDC_Admin::CAP_ASSIGN_EVENTS)) wp_die('Permission denied.');
        $event_id = absint($_POST['event_id'] ?? 0);
        check_admin_referer('ifdc_resume_automatic_event_update_' . $event_id);
        $suppressed = array_values(array_filter(array_map('absint', (array) get_option(self::AUTOMATION_SUPPRESSED_OPTION, [])), function($id) use ($event_id) { return $id !== $event_id; }));
        update_option(self::AUTOMATION_SUPPRESSED_OPTION, $suppressed, false);
        wp_safe_redirect(admin_url('admin.php?page=ifdc-update-history'));
        exit;
    }

    private static function automation_enabled() {
        return (bool) get_option(self::AUTOMATION_ENABLED_OPTION, false);
    }

    private static function automation_timezone() {
        $display_settings = get_option('ifrd_schedule_settings', []);
        $name = is_array($display_settings) ? trim((string) ($display_settings['display_timezone'] ?? '')) : '';
        if ($name === '') $name = wp_timezone_string();
        try {
            return new DateTimeZone($name ?: 'UTC');
        } catch (Exception $exception) {
            return new DateTimeZone('UTC');
        }
    }

    private static function apply_standard_month($month) {
        $prepared = self::prepare_standard_month($month, true);
        if (is_wp_error($prepared)) return $prepared;

        $automated_groups = array_values(array_filter($prepared['groups'], function($group) { return empty($group['manual_only']); }));
        $missing = array_filter($automated_groups, function($group) {
            return absint($group['update_count'] ?? 0) > 0 && empty($group['target']['id']);
        });
        if ($missing) {
            return new WP_Error(
                'ifdc_destination_missing',
                'No unique destination was found for: ' . implode(', ', array_column($missing, 'name')) . '. Nothing was changed for this month.'
            );
        }

        $result = ['updated' => 0, 'unchanged' => 0, 'errors' => 0, 'error_messages' => [], 'changes' => []];
        foreach ($automated_groups as $group) {
            $target_id = absint($group['target']['id']);
            $capacity = absint($group['capacity']);
            $event_name = !empty($group['automate_name']) ? sanitize_text_field($group['event_name'] ?? '') : null;
            if ($event_name === '') $event_name = null;
            $result['unchanged'] += absint($group['ready_count'] ?? 0);
            foreach ((array) ($group['events'] ?? []) as $event) {
                $event_id = absint($event['id'] ?? 0);
                if (!$event_id) continue;
                $update = IFDC_Client::update_event_assignment($event_id, $target_id, $capacity, $event_name);
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
                    absint($attrs['register_capacity'] ?? 0) !== $capacity ||
                    ($event_name !== null && sanitize_text_field($attrs['desc'] ?? '') !== $event_name)
                ) {
                    $result['errors']++;
                    $result['error_messages'][] = '#' . $event_id . ': Dash did not retain the verified assignment, capacity, and event name.';
                    continue;
                }
                $result['updated']++;
                $result['changes'][] = [
                    'event_id' => $event_id,
                    'event_name' => sanitize_text_field($attrs['desc'] ?? $event['name'] ?? ''),
                    'event_start' => sanitize_text_field($attrs['start'] ?? $event['start'] ?? ''),
                    'month' => $month,
                    'group' => sanitize_text_field($group['name'] ?? ''),
                    'before' => [
                        'team_id' => absint($event['team_id'] ?? 0),
                        'capacity' => absint($event['capacity'] ?? 0),
                        'name' => sanitize_text_field($event['name'] ?? ''),
                    ],
                    'after' => [
                        'team_id' => $target_id,
                        'capacity' => $capacity,
                        'name' => sanitize_text_field($attrs['desc'] ?? ''),
                    ],
                ];
            }
        }
        return $result;
    }

    private static function find_standard_destination($name, $month_label, $definition = []) {
        if (($definition['destination_resolver'] ?? '') === 'public_skating') {
            return self::find_public_skating_destination($month_label, !empty($definition['include_inactive_destination']));
        }
        $allowed_team_ids = array_values(array_filter(array_map('absint', (array) ($definition['allowed_team_ids'] ?? []))));
        $allowed_level_id = absint($definition['allowed_level_id'] ?? 0);
        if (!empty($definition['manual_only']) && (!$allowed_team_ids || !$allowed_level_id)) return null;
        $result = IFDC_Client::get_collection('teams', [
            'filter[name__contains]' => !empty($definition['event_type_id']) ? 'Public' : $name,
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
            if (!empty($attrs['inactive']) && empty($definition['include_inactive_destination'])) continue;
            $team_id = absint($record['id'] ?? 0);
            if ($allowed_team_ids && !in_array($team_id, $allowed_team_ids, true)) continue;
            $team_name = sanitize_text_field($attrs['name'] ?? '');
            $team_text = self::normalize_match_text($team_name);
            $season_id = absint($attrs['season_id'] ?? 0);
            $level_id = absint($attrs['league_id'] ?? 0);
            if ($allowed_level_id && $level_id !== $allowed_level_id) continue;
            $season_name = $season_id ? self::resource_name('seasons', $season_id) : '';
            $level_name = $level_id ? self::resource_name('leagues', $level_id) : '';
            $level_text = self::normalize_match_text($level_name);
            $season_text = self::normalize_match_text($season_name);
            $score = 0;
            if ($team_text === $target_text) $score += 320;
            elseif (substr($team_text, -strlen($target_text)) === $target_text) $score += 260;
            elseif (strpos($team_text, $target_text) !== false) $score += 210;
            elseif (!empty($definition['event_type_id']) && strpos($team_text, 'public') !== false) $score += 210;
            if ($month_text && strpos($team_text, $month_text) === 0) $score += 100;
            elseif ($month_text && strpos($team_text, $month_text) !== false) $score += 80;
            if ($month_text && strpos($level_text, $month_text) !== false) $score += 130;
            if (!empty($year_match[0]) && strpos($season_text, $year_match[0]) !== false) $score += 20;
            $ranked[] = [
                'score' => $score,
                'id' => $team_id,
                'name' => $team_name,
                'season_id' => $season_id,
                'season_name' => $season_name,
                'level_id' => $level_id,
                'level_name' => $level_name,
                'inactive' => !empty($attrs['inactive']),
                'online_signup' => !empty($attrs['online_signup']),
            ];
        }
        usort($ranked, function($a, $b) { return $b['score'] <=> $a['score']; });
        if (!$ranked || $ranked[0]['score'] < 250) return null;
        if (isset($ranked[1]) && $ranked[0]['score'] - $ranked[1]['score'] < 20) return null;
        unset($ranked[0]['score']);
        return $ranked[0];
    }

    private static function find_public_skating_destination($month_label, $include_inactive = false) {
        $month_text = self::normalize_match_text($month_label);
        if (!preg_match('/\b(\d{4})\b/', $month_text, $year_match)) return null;

        $expected_level = self::normalize_match_text($year_match[1] . ' Public Skating');
        $result = IFDC_Client::get_collection('teams', [
            'filter[name__contains]' => $month_label,
            'sort' => 'name',
            'page[size]' => 100,
        ], [
            'force' => true,
            'cache' => false,
            'max_pages' => 3,
        ]);
        if (is_wp_error($result)) return null;

        $matches = [];
        foreach ((array) ($result['data'] ?? []) as $record) {
            $attrs = self::attributes($record);
            if (!empty($attrs['inactive']) && !$include_inactive) continue;

            $team_name = sanitize_text_field($attrs['name'] ?? '');
            if (self::normalize_match_text($team_name) !== $month_text) continue;

            $level_id = absint($attrs['league_id'] ?? 0);
            if (!$level_id) continue;
            $level_name = self::resource_name('leagues', $level_id);
            if (self::normalize_match_text($level_name) !== $expected_level) continue;

            $season_id = absint($attrs['season_id'] ?? 0);
            $matches[] = [
                'id' => absint($record['id'] ?? 0),
                'name' => $team_name,
                'season_id' => $season_id,
                'season_name' => $season_id ? self::resource_name('seasons', $season_id) : '',
                'level_id' => $level_id,
                'level_name' => $level_name,
                'inactive' => !empty($attrs['inactive']),
                'online_signup' => !empty($attrs['online_signup']),
            ];
        }

        return count($matches) === 1 ? $matches[0] : null;
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
            if (self::is_protected_public_skating_event($attrs)) continue;
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
        $event_name = trim(sanitize_text_field(wp_unslash($_POST['event_name'] ?? '')));
        if ($event_name === '') $event_name = null;
        $raw_ids = isset($_POST['event_ids']) ? (array) wp_unslash($_POST['event_ids']) : [];
        $raw_expected = isset($_POST['expected_team_ids']) ? (array) wp_unslash($_POST['expected_team_ids']) : [];
        $raw_expected_capacities = isset($_POST['expected_capacities']) ? (array) wp_unslash($_POST['expected_capacities']) : [];
        $raw_expected_names = isset($_POST['expected_event_names']) ? (array) wp_unslash($_POST['expected_event_names']) : [];
        $expected_team_ids = [];
        $expected_capacities = [];
        $expected_names = [];
        foreach ($raw_expected as $event_id => $expected_team_id) {
            $expected_team_ids[absint($event_id)] = absint($expected_team_id);
        }
        foreach ($raw_expected_capacities as $event_id => $expected_capacity) {
            $expected_capacities[absint($event_id)] = absint($expected_capacity);
        }
        foreach ($raw_expected_names as $event_id => $expected_name) {
            $expected_names[absint($event_id)] = sanitize_text_field($expected_name);
        }
        $event_ids = array_slice(array_values(array_unique(array_filter(array_map('absint', $raw_ids)))), 0, self::MAX_UPDATE_BATCH);
        if (!$event_ids || (!$team_id && $capacity === null && $event_name === null)) {
            wp_send_json_error(['message' => 'Choose at least one event and a destination class, capacity, or event name.'], 400);
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
            $current_name = sanitize_text_field($attrs['desc'] ?? '');
            if (self::is_protected_public_skating_event($attrs)) {
                $skipped[] = [
                    'id' => $event_id,
                    'message' => 'This themed or special Public Skating event is permanently protected and cannot be changed by this tool.',
                ];
                continue;
            }
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
            if ($event_name !== null && (!array_key_exists($event_id, $expected_names) || $current_name !== $expected_names[$event_id])) {
                $errors[] = [
                    'id' => $event_id,
                    'message' => 'The event name changed after the preview. Search again before updating this event.',
                ];
                continue;
            }
            $team_matches = !$team_id || $current_team_id === $team_id;
            $capacity_matches = $capacity === null || $current_capacity === $capacity;
            $name_matches = $event_name === null || $current_name === $event_name;
            if ($team_matches && $capacity_matches && $name_matches) {
                $skipped[] = [
                    'id' => $event_id,
                    'message' => 'The requested class/team, capacity, and event name are already set.',
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

            $result = IFDC_Client::update_event_assignment($event_id, $team_id ?: null, $capacity, $event_name);
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
            if ($event_name !== null && sanitize_text_field($verify_attrs['desc'] ?? '') !== $event_name) {
                $errors[] = ['id' => $event_id, 'message' => 'Dash did not retain the requested event name.'];
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

    private static function is_protected_public_skating_event($attributes) {
        $attributes = is_array($attributes) ? $attributes : [];
        if (absint($attributes['event_type_id'] ?? 0) !== self::PUBLIC_SKATING_EVENT_TYPE_ID) return false;

        $team_id = absint($attributes['hteam_id'] ?? 0);
        if (in_array($team_id, self::PUBLIC_SKATING_EXCLUDED_TEAM_IDS, true)) return true;

        $title = trim(wp_strip_all_tags((string) ($attributes['desc'] ?? '')));
        $length = function_exists('mb_strlen') ? mb_strlen($title) : strlen($title);
        return $length > 22;
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
