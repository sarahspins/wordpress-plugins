<?php
/**
 * Public day/week schedule calendar.
 *
 * The calendar deliberately caches a complete week. Both the day and week views
 * read from that same payload, so changing views or selecting another day in the
 * loaded week never causes another Dash request.
 */
class IFRD_Schedule_Calendar {
    const AJAX_ACTION = 'ifrd_schedule_calendar_data';
    const NONCE_ACTION = 'ifrd_schedule_calendar_nonce';
    const CRON_HOOK = 'ifrd_warm_schedule_calendar_cache';
    const REFRESH_HOOK = 'ifrd_refresh_schedule_calendar_week';
    const CACHE_PREFIX = 'ifrd_cal_week_';
    const STALE_PREFIX = 'ifrd_cal_stale_';

    private $schedule_display;

    public function __construct($schedule_display) {
        $this->schedule_display = $schedule_display;

        add_action('wp_enqueue_scripts', array($this, 'register_assets'));
        add_shortcode('rink_schedule_calendar', array($this, 'shortcode'));
        add_shortcode('rink_schedule_list', array($this, 'shortcode'));
        add_action('wp_ajax_' . self::AJAX_ACTION, array($this, 'ajax'));
        add_action('wp_ajax_nopriv_' . self::AJAX_ACTION, array($this, 'ajax'));
        add_filter('cron_schedules', array($this, 'cron_schedules'));
        add_action('init', array($this, 'ensure_cron'));
        add_action(self::CRON_HOOK, array($this, 'warm_cache'));
        add_action(self::REFRESH_HOOK, array($this, 'refresh_week'), 10, 1);
    }

    public static function deactivate() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        wp_clear_scheduled_hook(self::REFRESH_HOOK);
        delete_option('ifrd_calendar_warm_schedule');
    }

    public function cron_schedules($schedules) {
        $schedules['ifrd_fifteen_minutes'] = array('interval' => 15 * MINUTE_IN_SECONDS, 'display' => 'Every fifteen minutes');
        return $schedules;
    }

    public function register_assets() {
        $base = IFRD_URL . 'assets/';
        wp_register_style('ifrd-schedule-calendar', $base . 'schedule-calendar.css', array(), IFRD_VERSION);
        wp_register_script('ifrd-schedule-calendar', $base . 'schedule-calendar.js', array(), IFRD_VERSION, true);
    }

    private function next_cache_warm_timestamp() {
        $now = new DateTimeImmutable('now', $this->timezone());
        $noon = $now->setTime(12, 0, 0);

        if ($now < $noon) {
            return $noon->getTimestamp();
        }

        return $now->modify('+1 day')->setTime(0, 0, 0)->getTimestamp();
    }

    public function ensure_cron() {
        $schedule_version = '2.7.11-every-fifteen-minutes';
        if (get_option('ifrd_calendar_warm_schedule') !== $schedule_version) {
            wp_clear_scheduled_hook(self::CRON_HOOK);
            update_option('ifrd_calendar_warm_schedule', $schedule_version, false);
            // Prime shortly after activation, then keep the shared static files
            // warm without making display browsers rebuild them.
            wp_schedule_single_event(time() + 5, self::CRON_HOOK);
            wp_schedule_event(time() + 15 * MINUTE_IN_SECONDS, 'ifrd_fifteen_minutes', self::CRON_HOOK);
            return;
        }

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 15, 'ifrd_fifteen_minutes', self::CRON_HOOK);
        }
    }

    private function opts() {
        return $this->schedule_display->opts();
    }

    private function timezone() {
        $o = $this->opts();
        $name = !empty($o['display_timezone']) ? (string) $o['display_timezone'] : 'America/Chicago';

        try {
            return new DateTimeZone($name);
        } catch (Exception $e) {
            return new DateTimeZone('America/Chicago');
        }
    }

    public function shortcode($atts) {
        $o = $this->opts();
        $atts = shortcode_atts(array(
            'view' => $o['calendar_default_view'] ?? 'week',
            'title' => $o['display_title'] ?? 'Rink Schedule',
            'subtitle' => 'Browse Gold and Silver rink events by day or week.',
        ), $atts, 'rink_schedule_calendar');

        $view = in_array($atts['view'], array('day', 'week'), true) ? $atts['view'] : 'week';
        $today = new DateTimeImmutable('now', $this->timezone());
        $id = 'ifrd-calendar-' . wp_generate_password(8, false, false);
        $initial_payload = $this->cached_week_snapshot($this->week_start_for($today->format('Y-m-d')));

        wp_enqueue_style('ifrd-schedule-calendar');
        wp_enqueue_script('ifrd-schedule-calendar');

        $config = array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'staticBaseUrl' => IFRD_Static_Schedule_Cache::base_url(),
            'action' => self::AJAX_ACTION,
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
            'initialDate' => $today->format('Y-m-d'),
            'initialView' => $view,
            'refreshMs' => max(60, absint($o['refresh_seconds'] ?? 300)) * 1000,
            'goldTitle' => $o['gold_title'] ?? 'Gold Rink',
            'silverTitle' => $o['silver_title'] ?? 'Silver Rink',
            'colorStrength' => min(100, max(0, intval($o['calendar_color_strength'] ?? 50))),
        );

        ob_start();
        ?>
        <div id="<?php echo esc_attr($id); ?>" class="ifrd-calendar" data-ifrd-calendar data-config="<?php echo esc_attr(wp_json_encode($config)); ?>">
            <header class="ifrd-calendar-heading">
                <div>
                    <h1><?php echo esc_html($atts['title']); ?></h1>
                    <p><?php echo esc_html($atts['subtitle']); ?></p>
                </div>
                <div class="ifrd-calendar-view-switch" role="group" aria-label="Schedule view">
                    <button type="button" data-view="day">Day</button>
                    <button type="button" data-view="week">Week</button>
                </div>
            </header>

            <div class="ifrd-calendar-controls<?php echo empty($o['calendar_session_selector']) ? ' without-session-selector' : ''; ?>">
                <label>
                    <span>Date</span>
                    <input type="date" data-calendar-date value="<?php echo esc_attr($today->format('Y-m-d')); ?>">
                </label>
                <label>
                    <span>Rink</span>
                    <select data-calendar-rink>
                        <option value="all">All Rinks</option>
                        <option value="gold"><?php echo esc_html($o['gold_title'] ?? 'Gold Rink'); ?></option>
                        <option value="silver"><?php echo esc_html($o['silver_title'] ?? 'Silver Rink'); ?></option>
                    </select>
                </label>
                <?php if (!empty($o['calendar_session_selector'])): ?>
                    <label>
                        <span>Session Type</span>
                        <select data-calendar-session>
                            <option value="all">All Session Types</option>
                        </select>
                    </label>
                <?php endif; ?>
                <button type="button" class="ifrd-calendar-today" data-calendar-today>Today</button>
            </div>

            <div class="ifrd-calendar-week-nav" data-week-nav>
                <button type="button" data-week-previous aria-label="Previous week">&lsaquo;</button>
                <strong data-week-range>Loading…</strong>
                <button type="button" data-week-next aria-label="Next week">&rsaquo;</button>
            </div>

            <div class="ifrd-calendar-status" data-calendar-status aria-live="polite">Loading schedule…</div>
            <div class="ifrd-calendar-content" data-calendar-content aria-live="polite">
                <div class="ifrd-calendar-loading">Loading schedule…</div>
            </div>
            <?php if (is_array($initial_payload)): ?>
                <script type="application/json" data-calendar-initial><?php
                    echo wp_json_encode($initial_payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
                ?></script>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    public function ajax() {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $date = isset($_POST['date']) ? sanitize_text_field(wp_unslash($_POST['date'])) : '';
        $week_start = $this->week_start_for($date);
        $payload = $this->get_week($week_start, false);

        if (is_wp_error($payload)) {
            wp_send_json_error(array('message' => $payload->get_error_message()), 500);
        }

        wp_send_json_success($payload);
    }

    public function warm_cache() {
        if (!IFRD_Dash_Connector::is_ready()) {
            return;
        }

        $current = $this->week_start_for('');
        $this->get_week($current, true);
        $next_args = array($current->modify('+7 days')->format('Y-m-d'));
        if (!wp_next_scheduled(self::REFRESH_HOOK, $next_args)) {
            wp_schedule_single_event(time() + 90, self::REFRESH_HOOK, $next_args);
        }
    }

    public function refresh_week($week_start_value) {
        if (!IFRD_Dash_Connector::is_ready()) {
            return;
        }

        $this->get_week($this->week_start_for((string) $week_start_value), true);
    }

    private function week_start_for($value) {
        $tz = $this->timezone();
        $date = null;

        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $tz);
        }

        if (!$date) {
            $date = new DateTimeImmutable('now', $tz);
        }

        return $date->setTime(0, 0, 0)->modify('-' . (((int) $date->format('N')) - 1) . ' days');
    }

    private function cache_keys($week_start) {
        $o = $this->opts();
        $version = absint(get_option('ifrd_calendar_cache_version', 1));
        $identity = implode('|', array(
            IFRD_VERSION,
            $version,
            $week_start->format('Y-m-d'),
            $o['display_timezone'] ?? 'America/Chicago',
            $o['gold_resource_id'] ?? '1',
            $o['silver_resource_id'] ?? '2',
            !empty($o['calendar_registration_links']) ? 'links-on' : 'links-off',
        ));
        $hash = md5($identity);

        return array(self::CACHE_PREFIX . $hash, self::STALE_PREFIX . $hash);
    }

    /**
     * Read only: used while rendering the shortcode so a previously warmed
     * schedule can be embedded into the page without waiting for AJAX.
     */
    private function cached_week_snapshot($week_start) {
        list($cache_key, $stale_key) = $this->cache_keys($week_start);
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            $cached['cacheStatus'] = 'preloaded';
            return $cached;
        }

        $stale = get_transient($stale_key);
        if (is_array($stale)) {
            $stale['cacheStatus'] = 'preloaded-stale';
            $stale['warning'] = 'Showing the cached schedule while an update runs in the background.';
            return $stale;
        }

        return null;
    }

    private function get_week($week_start, $force) {
        $o = $this->opts();
        $ttl = max(60, absint($o['calendar_cache_seconds'] ?? 900));
        list($cache_key, $stale_key) = $this->cache_keys($week_start);

        if (!$force) {
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                $cached['cacheStatus'] = 'cached';
                IFRD_Static_Schedule_Cache::write('week-' . $week_start->format('Y-m-d'), $cached);
                return $cached;
            }

            // Serve the last successful copy immediately and refresh it after the
            // response. This prevents a cold-looking page during a normal reload.
            $stale = get_transient($stale_key);
            if (is_array($stale)) {
                $args = array($week_start->format('Y-m-d'));
                if (!wp_next_scheduled(self::REFRESH_HOOK, $args)) {
                    wp_schedule_single_event(time() + 1, self::REFRESH_HOOK, $args);
                }
                $stale['cacheStatus'] = 'stale-refreshing';
                $stale['warning'] = 'Showing the cached schedule while an update runs in the background.';
                return $stale;
            }
        }

        $lock_key = $cache_key . '_lock';
        $stale = get_transient($stale_key);
        if (get_transient($lock_key)) {
            if (is_array($stale)) {
                $stale['cacheStatus'] = 'stale-refreshing';
                $stale['warning'] = 'Showing the cached schedule while an update runs in the background.';
                return $stale;
            }
            return new WP_Error('ifrd_calendar_refreshing', 'This schedule week is already refreshing. Please try again shortly.');
        }

        set_transient($lock_key, 1, 3 * MINUTE_IN_SECONDS);
        try {
            $payload = $this->fetch_week($week_start, $ttl, $force);

            if (is_wp_error($payload)) {
                if (is_array($stale)) {
                    $stale['cacheStatus'] = 'stale';
                    $stale['warning'] = 'Showing the most recently cached schedule while Dash is unavailable.';
                    return $stale;
                }

                return $payload;
            }

            $payload['cacheStatus'] = 'fresh';
            set_transient($cache_key, $payload, $ttl);
            set_transient($stale_key, $payload, 2 * DAY_IN_SECONDS);
            IFRD_Static_Schedule_Cache::write('week-' . $week_start->format('Y-m-d'), $payload);

            return $payload;
        } finally {
            delete_transient($lock_key);
        }
    }

    private function parse_datetime($value) {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        $tz = $this->timezone();

        try {
            if (preg_match('/(?:Z|[+\-]\d{2}:?\d{2})$/i', $value)) {
                return (new DateTimeImmutable($value))->setTimezone($tz);
            }

            return new DateTimeImmutable($value, $tz);
        } catch (Exception $e) {
            return null;
        }
    }

    private function registration_boolean_state($value) {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value === 1;
        }
        if (!is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));
        if (in_array($value, array('1', 'true', 'yes', 'on', 'open', 'active', 'enabled', 'available'), true)) {
            return true;
        }
        if (in_array($value, array('0', 'false', 'no', 'off', 'closed', 'inactive', 'disabled', 'unavailable'), true)) {
            return false;
        }
        return null;
    }

    /**
     * Reject registration when Dash explicitly reports it closed, outside its
     * sign-up window, or full. When $require_positive is true, at least one
     * affirmative open/enabled/window signal must also be present.
     */
    private function registration_attributes_open($attributes, $require_positive = false) {
        if (!is_array($attributes)) {
            return !$require_positive;
        }

        $positive = false;
        foreach (array('online_signup', 'onlineSignup', 'online_registration', 'registration_open', 'registrationOpen', 'registration_enabled', 'registrationEnabled', 'allow_registration', 'allowRegistration', 'open_for_registration', 'openForRegistration', 'can_register', 'canRegister', 'is_registration_open', 'isRegistrationOpen') as $field) {
            if (!array_key_exists($field, $attributes)) {
                continue;
            }
            $state = $this->registration_boolean_state($attributes[$field]);
            if ($state === false) {
                return false;
            }
            if ($state === true) {
                $positive = true;
            }
        }

        foreach (array('registration_status', 'registrationStatus', 'signup_status', 'signupStatus', 'status') as $field) {
            if (empty($attributes[$field]) || !is_string($attributes[$field])) {
                continue;
            }
            $status = strtolower(trim($attributes[$field]));
            if (in_array($status, array('closed', 'disabled', 'inactive', 'archived', 'ended', 'expired', 'cancelled', 'canceled', 'unavailable', 'full', 'sold out', 'sold_out'), true)) {
                return false;
            }
            if (in_array($status, array('open', 'active', 'enabled', 'available', 'registering', 'registration open'), true)) {
                $positive = true;
            }
        }

        $now = new DateTimeImmutable('now', $this->timezone());
        foreach (array('signup_start', 'signupStart', 'registration_start', 'registrationStart', 'registration_opens_at', 'registrationOpensAt', 'online_signup_start', 'onlineSignupStart') as $field) {
            if (empty($attributes[$field])) {
                continue;
            }
            $opens = $this->parse_datetime($attributes[$field]);
            if ($opens && $now < $opens) {
                return false;
            }
            if ($opens) {
                $positive = true;
            }
            break;
        }

        foreach (array('signup_end', 'signupEnd', 'registration_end', 'registrationEnd', 'registration_closes_at', 'registrationClosesAt', 'online_signup_end', 'onlineSignupEnd') as $field) {
            if (empty($attributes[$field])) {
                continue;
            }
            $closes = $this->parse_datetime($attributes[$field]);
            if ($closes && $now >= $closes) {
                return false;
            }
            if ($closes) {
                $positive = true;
            }
            break;
        }

        $capacity = 0;
        foreach (array('register_capacity', 'registration_capacity', 'capacity', 'max_registrants', 'maxRegistrants') as $field) {
            if (isset($attributes[$field]) && is_numeric($attributes[$field])) {
                $capacity = max(0, intval($attributes[$field]));
                break;
            }
        }

        if ($capacity > 0) {
            foreach (array('registered_count', 'registrant_count', 'registration_count', 'registrants_count', 'registered', 'enrollment_count', 'enrolled_count') as $field) {
                if (isset($attributes[$field]) && is_numeric($attributes[$field]) && intval($attributes[$field]) >= $capacity) {
                    return false;
                }
            }
        }

        return $require_positive ? $positive : true;
    }

    private function registration_url_value($value) {
        if (is_array($value)) {
            foreach (array('href', 'url', 'link') as $field) {
                if (!empty($value[$field])) {
                    $value = $value[$field];
                    break;
                }
            }
        }

        if (!is_string($value)) {
            return '';
        }

        $url = esc_url_raw(trim($value));
        return preg_match('#^https?://#i', $url) ? $url : '';
    }

    /**
     * Find a URL only when it appears in registration/sign-up context. This
     * deliberately ignores generic JSON:API self/related links.
     */
    private function registration_url_from_source($source, $in_registration_context = false, $depth = 0) {
        if (!is_array($source) || $depth > 5) {
            return '';
        }

        foreach ($source as $key => $value) {
            $key_name = strtolower((string) $key);
            $is_registration_key = (
                strpos($key_name, 'registr') !== false ||
                strpos($key_name, 'signup') !== false ||
                strpos($key_name, 'sign_up') !== false ||
                strpos($key_name, 'enroll') !== false ||
                strpos($key_name, 'book') !== false
            );
            $context = $in_registration_context || $is_registration_key;

            if ($context) {
                $url = $this->registration_url_value($value);
                if ($url !== '') {
                    return $url;
                }
            }

            if (is_array($value)) {
                $url = $this->registration_url_from_source($value, $context, $depth + 1);
                if ($url !== '') {
                    return $url;
                }
            }
        }

        return '';
    }

    private function event_registration_url($raw) {
        $a = isset($raw['attributes']) && is_array($raw['attributes']) ? $raw['attributes'] : array();
        $sources = array($a);
        if (!empty($a['registration']) && is_array($a['registration'])) {
            $sources[] = $a['registration'];
        }
        if (!empty($a['links']) && is_array($a['links'])) {
            $sources[] = $a['links'];
        }
        if (!empty($raw['links']) && is_array($raw['links'])) {
            $sources[] = $raw['links'];
        }

        $url = '';
        $url_fields = array(
            'registration_url', 'registrationUrl', 'registration_link', 'registrationLink',
            'registration_link_url', 'registrationLinkUrl', 'registration_href', 'registrationHref',
            'online_registration_url', 'onlineRegistrationUrl', 'online_signup_url', 'onlineSignupUrl',
            'online_registration', 'onlineRegistration', 'online_signup', 'onlineSignup',
            'signup_url', 'signupUrl', 'public_registration_url', 'publicRegistrationUrl',
            'register_url', 'registerUrl', 'register_link', 'registerLink', 'register', 'registration'
        );

        foreach ($sources as $source) {
            foreach ($url_fields as $field) {
                if (!array_key_exists($field, $source)) {
                    continue;
                }
                $url = $this->registration_url_value($source[$field]);
                if ($url !== '') {
                    break 2;
                }
            }
        }

        if ($url === '') {
            foreach ($sources as $source) {
                $url = $this->registration_url_from_source($source);
                if ($url !== '') {
                    break;
                }
            }
        }

        if ($url === '') {
            return '';
        }

        foreach (array('online_signup', 'onlineSignup', 'online_registration', 'registration_open', 'registrationOpen', 'registration_enabled', 'registrationEnabled', 'allow_registration', 'allowRegistration', 'open_for_registration', 'openForRegistration', 'can_register', 'canRegister', 'is_registration_open', 'isRegistrationOpen') as $field) {
            if (!array_key_exists($field, $a)) {
                continue;
            }
            if ($this->registration_boolean_state($a[$field]) === false) {
                return '';
            }
        }

        foreach (array('registration_status', 'registrationStatus', 'signup_status', 'signupStatus') as $field) {
            if (empty($a[$field]) || !is_string($a[$field])) {
                continue;
            }
            $status = strtolower(trim($a[$field]));
            if (in_array($status, array('closed', 'disabled', 'ended', 'expired', 'cancelled', 'canceled', 'unavailable', 'full', 'sold out', 'sold_out'), true)) {
                return '';
            }
        }

        $now = new DateTimeImmutable('now', $this->timezone());
        if (!empty($a['end'])) {
            $event_end = $this->parse_datetime($a['end']);
            if ($event_end && $event_end <= $now) {
                return '';
            }
        }

        foreach (array('signup_start', 'signupStart', 'registration_start', 'registrationStart', 'registration_opens_at', 'registrationOpensAt', 'online_signup_start', 'onlineSignupStart') as $field) {
            if (empty($a[$field])) {
                continue;
            }
            $opens = $this->parse_datetime($a[$field]);
            if ($opens && $now < $opens) {
                return '';
            }
            break;
        }

        foreach (array('signup_end', 'signupEnd', 'registration_end', 'registrationEnd', 'registration_closes_at', 'registrationClosesAt', 'online_signup_end', 'onlineSignupEnd') as $field) {
            if (empty($a[$field])) {
                continue;
            }
            $closes = $this->parse_datetime($a[$field]);
            if ($closes && $now >= $closes) {
                return '';
            }
            break;
        }

        return $url;
    }

    private function resource_registration_url($raw) {
        if (!is_array($raw)) {
            return '';
        }

        $attributes = isset($raw['attributes']) && is_array($raw['attributes']) ? $raw['attributes'] : array();
        // Do not scan JSON:API relationships here: a relationship named
        // "registrations" may contain an API URL, not a customer-facing link.
        $sources = array($attributes);
        if (!empty($raw['registration']) && is_array($raw['registration'])) {
            $sources[] = $raw['registration'];
        }

        foreach ($sources as $source) {
            $url = $this->registration_url_from_source($source);
            if ($url !== '') {
                return $url;
            }
        }

        return '';
    }

    private function is_registration_candidate($event) {
        if (!empty($event['registrationLinks'])) {
            return false;
        }

        if (($event['registrationAllowed'] ?? true) === false || ($event['onlineSignup'] ?? null) === false) {
            return false;
        }

        if (!empty($event['registerCapacity']) || ($event['onlineSignup'] ?? null) === true) {
            return true;
        }

        $title = strtolower((string) ($event['title'] ?? ''));
        return (
            strpos($title, 'freestyle') !== false ||
            strpos($title, 'stick & puck') !== false ||
            strpos($title, 'stick and puck') !== false ||
            strpos($title, 'private hockey coach') !== false ||
            strpos($title, 'coaches ice') !== false ||
            strpos($title, 'phci') !== false ||
            strpos($title, 'public ice') !== false ||
            strpos($title, 'public skate') !== false ||
            $this->is_calendar_lts_title($title)
        );
    }

    private function team_registration_link($team_id, $cache_ttl) {
        $team_id = absint($team_id);
        $company = sanitize_key((string) IFRD_Dash_Connector::company());
        if (!$team_id || $company === '') {
            return '';
        }

        $payload = IFRD_Dash_Connector::get('teams/' . rawurlencode((string) $team_id), array(), array(
            'cache_ttl' => max($cache_ttl, 12 * HOUR_IN_SECONDS),
            'force' => false,
        ));
        if (is_wp_error($payload) || empty($payload['data']) || !is_array($payload['data'])) {
            return '';
        }

        $team = $payload['data'];
        $attributes = isset($team['attributes']) && is_array($team['attributes']) ? $team['attributes'] : array();
        if (!$this->registration_attributes_open($attributes, false)) {
            return '';
        }

        $explicit = $this->resource_registration_url($team);
        if ($explicit !== '') {
            return $explicit;
        }

        if (!$this->registration_attributes_open($attributes, true)) {
            return '';
        }

        return esc_url_raw(
            'https://apps.daysmartrecreation.com/dash/x/#/online/' .
            rawurlencode($company) . '/teams/' . $team_id
        );
    }

    private function league_registration_link($league_id, $facility_id, $cache_ttl) {
        $league_id = absint($league_id);
        $facility_id = absint($facility_id);
        $company = sanitize_key((string) IFRD_Dash_Connector::company());
        if (!$league_id || $company === '') {
            return '';
        }

        $payload = IFRD_Dash_Connector::get('leagues/' . rawurlencode((string) $league_id), array(), array(
            'cache_ttl' => max($cache_ttl, 12 * HOUR_IN_SECONDS),
            'force' => false,
        ));
        if (is_wp_error($payload) || empty($payload['data']) || !is_array($payload['data'])) {
            return '';
        }

        $league = $payload['data'];
        $attributes = isset($league['attributes']) && is_array($league['attributes']) ? $league['attributes'] : array();
        if (!$this->registration_attributes_open($attributes, false)) {
            return '';
        }

        $explicit = $this->resource_registration_url($league);
        if ($explicit !== '') {
            return $explicit;
        }

        if (!$this->registration_attributes_open($attributes, true)) {
            return '';
        }

        if (!$facility_id) {
            $facility_id = absint($attributes['facility_id'] ?? $attributes['facilityId'] ?? 0);
        }
        $url = 'https://apps.daysmartrecreation.com/dash/x/' . rawurlencode($company) . '/programs/level/' . $league_id;
        if ($facility_id) {
            $url = add_query_arg('facility_ids', $facility_id, $url);
        }

        return esc_url_raw($url);
    }

    /**
     * The events collection frequently supplies the team/league ID but not the
     * public sign-up URL. Resolve that related object once (the connector caches
     * it) and attach the same public registration route used elsewhere on-site.
     */
    private function enrich_registration_links($events, $cache_ttl) {
        $team_links = array();
        $league_links = array();

        foreach ($events as &$event) {
            if (!$this->is_registration_candidate($event)) {
                continue;
            }

            $url = '';
            $team_id = absint($event['homeTeamId'] ?? 0);
            if ($team_id) {
                if (!array_key_exists($team_id, $team_links)) {
                    $team_links[$team_id] = $this->team_registration_link($team_id, $cache_ttl);
                }
                $url = $team_links[$team_id];
            }

            $league_id = absint($event['leagueId'] ?? 0);
            if ($url === '' && !$team_id && $league_id) {
                $facility_id = absint($event['facilityId'] ?? 0);
                $lookup_key = $league_id . '|' . $facility_id;
                if (!array_key_exists($lookup_key, $league_links)) {
                    $league_links[$lookup_key] = $this->league_registration_link($league_id, $facility_id, $cache_ttl);
                }
                $url = $league_links[$lookup_key];
            }

            if ($url !== '') {
                $event['registrationLinks'] = array(array(
                    'title' => (string) ($event['title'] ?? 'Register'),
                    'url' => $url,
                ));
            }
        }
        unset($event);

        return $events;
    }

    private function is_calendar_lts_title($title) {
        $title = strtolower(trim(wp_strip_all_tags((string) $title)));
        if ($title === '') {
            return false;
        }

        return (
            strpos($title, 'learn to skate') !== false ||
            preg_match('/(^|\s)lts(\s|$)/i', $title) ||
            strpos($title, 'large group') !== false ||
            strpos($title, 'snowplow sam') !== false ||
            preg_match('/\bbasic\s*\d/i', $title) ||
            preg_match('/\badult\s*\d/i', $title) ||
            strpos($title, 'free skate') !== false
        );
    }

    private function is_calendar_lts_event($event) {
        $titles = !empty($event['sessionTypes']) && is_array($event['sessionTypes'])
            ? $event['sessionTypes']
            : array($event['title'] ?? '');
        $has_title = false;

        foreach ($titles as $title) {
            if (trim((string) $title) === '') {
                continue;
            }
            $has_title = true;
            if (!$this->is_calendar_lts_title($title)) {
                return false;
            }
        }

        return $has_title;
    }

    private function is_calendar_lts_anchor($event) {
        $titles = !empty($event['sessionTypes']) && is_array($event['sessionTypes'])
            ? $event['sessionTypes']
            : array($event['title'] ?? '');

        foreach ($titles as $title) {
            $title = strtolower(trim(wp_strip_all_tags((string) $title)));
            if (
                strpos($title, 'learn to skate') !== false ||
                strpos($title, 'large group') !== false ||
                preg_match('/(^|\s)lts(\s|$)/i', $title)
            ) {
                return true;
            }
        }

        return false;
    }

    private function merge_registration_links($first, $second) {
        $links = array();
        foreach (array_merge((array) $first, (array) $second) as $link) {
            if (!is_array($link) || empty($link['url'])) {
                continue;
            }
            $url = $this->registration_url_value($link['url']);
            if ($url === '' || isset($links[$url])) {
                continue;
            }
            $links[$url] = array(
                'title' => sanitize_text_field($link['title'] ?? 'Register'),
                'url' => $url,
            );
        }
        return array_values($links);
    }

    private function normalize_dash_color($value) {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $number = (int) $value;
            if ($number >= 0 && $number <= 16777215) {
                return sprintf('#%06x', $number);
            }
        }

        if (!is_string($value)) {
            return '';
        }

        $value = trim($value);
        if (preg_match('/^#?([0-9a-f]{6})$/i', $value, $matches)) {
            return '#' . strtolower($matches[1]);
        }
        if (preg_match('/^#?([0-9a-f]{3})$/i', $value, $matches)) {
            return '#' . strtolower($matches[1]);
        }
        if (preg_match('/^rgb\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*\)$/i', $value, $matches)) {
            $red = min(255, (int) $matches[1]);
            $green = min(255, (int) $matches[2]);
            $blue = min(255, (int) $matches[3]);
            return sprintf('#%02x%02x%02x', $red, $green, $blue);
        }

        return '';
    }

    private function extract_dash_color($attributes) {
        if (!is_array($attributes)) {
            return '';
        }

        $fields = array(
            'color', 'colour', 'color_code', 'colorCode', 'calendar_color', 'calendarColor',
            'background_color', 'backgroundColor', 'bg_color', 'event_color', 'eventColor',
            'display_color', 'displayColor', 'hex_color', 'hexColor', 'web_color', 'webColor',
            'self_color', 'selfColor'
        );

        foreach ($fields as $field) {
            if (!array_key_exists($field, $attributes)) {
                continue;
            }
            $color = $this->normalize_dash_color($attributes[$field]);
            if ($color !== '') {
                return $color;
            }
        }

        foreach ($attributes as $value) {
            if (is_array($value)) {
                $color = $this->extract_dash_color($value);
                if ($color !== '') {
                    return $color;
                }
            }
        }

        return '';
    }

    private function event_type_color_map($events, $cache_ttl, $force) {
        $needed = array();
        foreach ($events as $event) {
            if (empty($event['dashColor']) && !empty($event['eventTypeId'])) {
                $needed[(string) $event['eventTypeId']] = true;
            }
        }

        if (empty($needed)) {
            return array();
        }

        $cache_key = 'ifrd_event_type_colors_v1';
        $map = get_transient($cache_key);
        if (!is_array($map)) {
            $map = array();
        }

        $missing = array_values(array_diff(array_keys($needed), array_keys($map)));
        if (empty($missing)) {
            return $map;
        }

        $paths = array('event-types', 'eventTypes', 'event_types');
        foreach ($paths as $path) {
            $payload = IFRD_Dash_Connector::get($path, array(
                'page' => array('number' => 1, 'size' => 500),
            ), array(
                'cache_ttl' => max($cache_ttl, 12 * HOUR_IN_SECONDS),
                'force' => false,
            ));

            if (is_wp_error($payload) || empty($payload['data']) || !is_array($payload['data'])) {
                continue;
            }

            foreach ($payload['data'] as $raw_type) {
                $id = (string) ($raw_type['id'] ?? '');
                $attributes = isset($raw_type['attributes']) && is_array($raw_type['attributes']) ? $raw_type['attributes'] : array();
                $color = $this->extract_dash_color($attributes);
                if ($id !== '' && $color !== '') {
                    $map[$id] = $color;
                }
            }

            if (!empty($map)) {
                break;
            }
        }

        $missing = array_values(array_diff(array_keys($needed), array_keys($map)));
        $working_path = '';
        foreach (array_slice($missing, 0, 30) as $event_type_id) {
            $try_paths = $working_path !== '' ? array($working_path) : $paths;
            foreach ($try_paths as $path) {
                $payload = IFRD_Dash_Connector::get($path . '/' . rawurlencode($event_type_id), array(), array(
                    'cache_ttl' => max($cache_ttl, 12 * HOUR_IN_SECONDS),
                    'force' => false,
                ));
                if (is_wp_error($payload) || empty($payload['data']) || !is_array($payload['data'])) {
                    continue;
                }
                $attributes = isset($payload['data']['attributes']) && is_array($payload['data']['attributes']) ? $payload['data']['attributes'] : array();
                $color = $this->extract_dash_color($attributes);
                if ($color !== '') {
                    $map[(string) $event_type_id] = $color;
                    $working_path = $path;
                }
                break;
            }
        }

        foreach ($missing as $event_type_id) {
            if (!array_key_exists((string) $event_type_id, $map)) {
                $map[(string) $event_type_id] = '';
            }
        }

        set_transient($cache_key, $map, 12 * HOUR_IN_SECONDS);
        return $map;
    }

    private function normalize_event($raw) {
        $a = isset($raw['attributes']) && is_array($raw['attributes']) ? $raw['attributes'] : array();
        $start = $this->parse_datetime($a['start'] ?? '');
        $end = $this->parse_datetime($a['end'] ?? '');

        if (!$start || !$end || $end <= $start) {
            return null;
        }

        $o = $this->opts();
        $resource_id = (string) ($a['resource_id'] ?? '');
        $rink_key = '';

        if ($resource_id === (string) ($o['gold_resource_id'] ?? '1')) {
            $rink_key = 'gold';
        } elseif ($resource_id === (string) ($o['silver_resource_id'] ?? '2')) {
            $rink_key = 'silver';
        }

        if ($rink_key === '') {
            return null;
        }

        $end_minutes = ((int) $end->format('G') * 60) + (int) $end->format('i');
        if ($end->format('Y-m-d') !== $start->format('Y-m-d')) {
            $end_minutes = 1440;
        }

        $title = $this->schedule_display->event_title($raw);
        $registration_url = !empty($o['calendar_registration_links'])
            ? $this->event_registration_url($raw)
            : '';

        return array(
            'id' => (string) ($raw['id'] ?? ''),
            'title' => $title,
            'sessionTypes' => array($title),
            'date' => $start->format('Y-m-d'),
            'start' => $start->format(DATE_ATOM),
            'end' => $end->format(DATE_ATOM),
            'startLabel' => $start->format('g:i A'),
            'endLabel' => $end->format('g:i A'),
            'startMinutes' => ((int) $start->format('G') * 60) + (int) $start->format('i'),
            'endMinutes' => $end_minutes,
            'resourceId' => $resource_id,
            'rinkKey' => $rink_key,
            'rink' => $rink_key === 'gold' ? ($o['gold_title'] ?? 'Gold Rink') : ($o['silver_title'] ?? 'Silver Rink'),
            'eventTypeId' => (string) ($a['event_type_id'] ?? ''),
            'leagueId' => (string) ($a['league_id'] ?? ''),
            'homeTeamId' => (string) ($a['hteam_id'] ?? ''),
            'awayTeamId' => (string) ($a['vteam_id'] ?? ''),
            'facilityId' => (string) ($a['facility_id'] ?? $a['facilityId'] ?? ''),
            'registerCapacity' => isset($a['register_capacity']) ? max(0, intval($a['register_capacity'])) : 0,
            'onlineSignup' => $this->registration_boolean_state($a['online_signup'] ?? $a['onlineSignup'] ?? null),
            'registrationAllowed' => $this->registration_attributes_open($a, false),
            'dashColor' => $this->extract_dash_color($a),
            'registrationLinks' => $registration_url !== '' ? array(array('title' => $title, 'url' => $registration_url)) : array(),
            'publish' => !empty($a['publish']),
        );
    }

    private function merge_simultaneous($events) {
        $groups = array();

        foreach ($events as $event) {
            $key = implode('|', array($event['date'], $event['rinkKey'], $event['start'], $event['end']));
            if (!isset($groups[$key])) {
                $event['titles'] = array($event['title']);
                $groups[$key] = $event;
                continue;
            }

            if (!in_array($event['title'], $groups[$key]['titles'], true)) {
                $groups[$key]['titles'][] = $event['title'];
            }
            if (empty($groups[$key]['dashColor']) && !empty($event['dashColor'])) {
                $groups[$key]['dashColor'] = $event['dashColor'];
            }
            $groups[$key]['registrationLinks'] = $this->merge_registration_links(
                $groups[$key]['registrationLinks'] ?? array(),
                $event['registrationLinks'] ?? array()
            );
        }

        foreach ($groups as &$event) {
            natcasesort($event['titles']);
            $event['sessionTypes'] = array_values($event['titles']);
            $event['title'] = implode(' • ', $event['sessionTypes']);
            if (count($event['sessionTypes']) > 1 && $this->is_calendar_lts_event($event)) {
                $event['title'] = 'Learn to Skate';
                $event['sessionTypes'] = array('Learn to Skate');
            }
            unset($event['titles']);
        }

        $merged = array_values($groups);
        usort($merged, function($a, $b) {
            if ($a['start'] === $b['start']) {
                if ($a['rinkKey'] === $b['rinkKey']) {
                    return strcasecmp($a['title'], $b['title']);
                }
                return $a['rinkKey'] === 'gold' ? -1 : 1;
            }
            return strcmp($a['start'], $b['start']);
        });

        return $merged;
    }

    private function collapse_overlapping_lts($events) {
        $lanes = array();
        $result = array();

        foreach ($events as $event) {
            $key = $event['date'] . '|' . $event['rinkKey'];
            if (!isset($lanes[$key])) {
                $lanes[$key] = array();
            }
            $lanes[$key][] = $event;
        }

        foreach ($lanes as $lane_events) {
            usort($lane_events, function($a, $b) {
                if ((int) $a['startMinutes'] === (int) $b['startMinutes']) {
                    return (int) $b['endMinutes'] <=> (int) $a['endMinutes'];
                }
                return (int) $a['startMinutes'] <=> (int) $b['startMinutes'];
            });

            $components = array();
            $component = array();
            $component_end = -1;

            foreach ($lane_events as $event) {
                $starts = (int) $event['startMinutes'];
                $touches_lts = (
                    $starts === $component_end &&
                    $this->is_calendar_lts_event($event) &&
                    !empty($component) &&
                    $this->is_calendar_lts_event($component[count($component) - 1])
                );

                if (empty($component) || $starts < $component_end || $touches_lts) {
                    $component[] = $event;
                    $component_end = max($component_end, (int) $event['endMinutes']);
                    continue;
                }

                $components[] = $component;
                $component = array($event);
                $component_end = (int) $event['endMinutes'];
            }

            if (!empty($component)) {
                $components[] = $component;
            }

            foreach ($components as $items) {
                $has_anchor = false;
                foreach ($items as $item) {
                    if ($this->is_calendar_lts_anchor($item)) {
                        $has_anchor = true;
                        break;
                    }
                }

                // A long Learn to Skate/Large Group parent overlaps its child
                // classes. Collapse the complete interval component so no child
                // blocks remain floating over the parent on the weekly grid.
                if (count($items) > 1 && $has_anchor) {
                    $collapsed = $items[0];
                    $collapsed['title'] = 'Learn to Skate';
                    $collapsed['sessionTypes'] = array('Learn to Skate');
                    $collapsed['registrationLinks'] = array();

                    foreach ($items as $item) {
                        $collapsed['registrationLinks'] = $this->merge_registration_links(
                            $collapsed['registrationLinks'],
                            $item['registrationLinks'] ?? array()
                        );
                        if ((int) $item['startMinutes'] < (int) $collapsed['startMinutes']) {
                            $collapsed['start'] = $item['start'];
                            $collapsed['startLabel'] = $item['startLabel'];
                            $collapsed['startMinutes'] = $item['startMinutes'];
                        }
                        if ((int) $item['endMinutes'] > (int) $collapsed['endMinutes']) {
                            $collapsed['end'] = $item['end'];
                            $collapsed['endLabel'] = $item['endLabel'];
                            $collapsed['endMinutes'] = $item['endMinutes'];
                        }
                        if (empty($collapsed['dashColor']) && !empty($item['dashColor'])) {
                            $collapsed['dashColor'] = $item['dashColor'];
                        }
                    }

                    $result[] = $collapsed;
                    continue;
                }

                foreach ($items as $item) {
                    $result[] = $item;
                }
            }
        }

        usort($result, function($a, $b) {
            if ($a['start'] === $b['start']) {
                if ($a['rinkKey'] === $b['rinkKey']) {
                    return strcasecmp($a['title'], $b['title']);
                }
                return $a['rinkKey'] === 'gold' ? -1 : 1;
            }
            return strcmp($a['start'], $b['start']);
        });

        return $result;
    }

    private function fetch_week($week_start, $cache_ttl, $force) {
        if (!IFRD_Dash_Connector::is_ready()) {
            return new WP_Error('ifrd_connector_not_ready', 'The shared Dash Connector is not installed, active, and configured.');
        }

        $week_end = $week_start->modify('+7 days');
        $events = array();
        $page = 1;
        $last_page = 1;
        $max_pages = 25;

        do {
            $payload = IFRD_Dash_Connector::get('events', array(
                'filter[start__gte]' => $week_start->format('Y-m-d\\TH:i:s'),
                'filter[start__lte]' => $week_end->modify('-1 second')->format('Y-m-d\\TH:i:s'),
                'sort' => 'start',
                'page' => array('number' => $page, 'size' => 100),
            ), array(
                'cache_ttl' => $cache_ttl,
                'force' => (bool) $force,
            ));

            if (is_wp_error($payload)) {
                return $payload;
            }

            $meta_page = isset($payload['meta']['page']) && is_array($payload['meta']['page']) ? $payload['meta']['page'] : array();
            $last_page = max(1, absint($meta_page['last-page'] ?? 1));
            $raw_events = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : array();
            $past_week = false;

            foreach ($raw_events as $raw) {
                $event = $this->normalize_event($raw);
                if (!$event) {
                    continue;
                }

                $event_start = $this->parse_datetime($event['start']);
                if ($event_start && $event_start >= $week_end) {
                    $past_week = true;
                    continue;
                }

                if (!$event['publish'] || !$event_start || $event_start < $week_start) {
                    continue;
                }

                $events[] = $event;
            }

            if ($past_week) {
                break;
            }

            $page++;
        } while ($page <= $last_page && $page <= $max_pages);

        $type_colors = $this->event_type_color_map($events, $cache_ttl, $force);
        foreach ($events as &$event) {
            if (empty($event['dashColor']) && !empty($event['eventTypeId']) && !empty($type_colors[(string) $event['eventTypeId']])) {
                $event['dashColor'] = $type_colors[(string) $event['eventTypeId']];
            }
        }
        unset($event);

        $o = $this->opts();
        if (!empty($o['calendar_registration_links'])) {
            $events = $this->enrich_registration_links($events, $cache_ttl);
        }
        $events = $this->merge_simultaneous($events);
        $events = $this->collapse_overlapping_lts($events);
        $days = array();
        $today = (new DateTimeImmutable('now', $this->timezone()))->format('Y-m-d');

        for ($offset = 0; $offset < 7; $offset++) {
            $date = $week_start->modify('+' . $offset . ' days');
            $key = $date->format('Y-m-d');
            $days[$key] = array(
                'date' => $key,
                'dayName' => $date->format('D'),
                'dayNumber' => $date->format('j'),
                'longLabel' => $date->format('l, F j'),
                'isToday' => $key === $today,
                'gold' => array(),
                'silver' => array(),
                'total' => 0,
            );
        }

        foreach ($events as $event) {
            if (!isset($days[$event['date']])) {
                continue;
            }
            $days[$event['date']][$event['rinkKey']][] = $event;
            $days[$event['date']]['total']++;
        }

        return array(
            'weekStart' => $week_start->format('Y-m-d'),
            'weekEnd' => $week_end->modify('-1 day')->format('Y-m-d'),
            'weekLabel' => $week_start->format('M j') . ' – ' . $week_end->modify('-1 day')->format('j, Y'),
            'generatedAt' => (new DateTimeImmutable('now', $this->timezone()))->format(DATE_ATOM),
            'days' => array_values($days),
            'total' => count($events),
            'warning' => '',
        );
    }
}
