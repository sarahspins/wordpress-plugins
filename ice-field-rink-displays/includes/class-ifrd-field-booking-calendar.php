<?php
/**
 * Privacy-safe monthly availability calendar for a Dash facility resource.
 *
 * The public payload deliberately contains no Dash event names, IDs, teams,
 * customers, or relationships. A scheduled event becomes only an unavailable
 * time block on its calendar date.
 */
class IFRD_Field_Booking_Calendar {
    const AJAX_ACTION = 'ifrd_field_booking_calendar_data';
    const NONCE_ACTION = 'ifrd_field_booking_calendar_nonce';
    const CACHE_PREFIX = 'ifrd_field_month_';
    const STALE_PREFIX = 'ifrd_field_stale_';

    private $schedule_display;

    public function __construct($schedule_display) {
        $this->schedule_display = $schedule_display;

        add_action('wp_enqueue_scripts', array($this, 'register_assets'));
        add_shortcode('field_booking_calendar', array($this, 'shortcode'));
        add_action('wp_ajax_' . self::AJAX_ACTION, array($this, 'ajax'));
        add_action('wp_ajax_nopriv_' . self::AJAX_ACTION, array($this, 'ajax'));
    }

    public static function deactivate() {
        // Monthly transients expire naturally and include the plugin version in
        // their identity, so an upgrade never reuses an older payload shape.
    }

    public function register_assets() {
        $base = IFRD_URL . 'assets/';
        wp_register_style('ifrd-field-booking-calendar', $base . 'field-booking-calendar.css', array(), IFRD_VERSION);
        wp_register_script('ifrd-field-booking-calendar', $base . 'field-booking-calendar.js', array(), IFRD_VERSION, true);
    }

    private function opts() {
        return $this->schedule_display->opts();
    }

    private function timezone() {
        $options = $this->opts();
        $name = !empty($options['display_timezone']) ? (string) $options['display_timezone'] : 'America/Chicago';

        try {
            return new DateTimeZone($name);
        } catch (Exception $exception) {
            return new DateTimeZone('America/Chicago');
        }
    }

    private function month_start($value) {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}$/', $value)) {
            $month = DateTimeImmutable::createFromFormat('!Y-m', $value, $this->timezone());
            if ($month && $month->format('Y-m') === $value) {
                return $month;
            }
        }

        return (new DateTimeImmutable('now', $this->timezone()))->modify('first day of this month')->setTime(0, 0, 0);
    }

    private function cache_keys($month_start, $resource_id) {
        $identity = implode('|', array(
            IFRD_VERSION,
            $month_start->format('Y-m'),
            absint($resource_id),
            $this->timezone()->getName(),
        ));
        $hash = md5($identity);

        return array(self::CACHE_PREFIX . $hash, self::STALE_PREFIX . $hash);
    }

    private function cached_month_snapshot($month_start, $resource_id) {
        list($cache_key, $stale_key) = $this->cache_keys($month_start, $resource_id);
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            $cached['cacheStatus'] = 'preloaded';
            return $cached;
        }

        $stale = get_transient($stale_key);
        if (is_array($stale)) {
            $stale['cacheStatus'] = 'preloaded-stale';
            $stale['warning'] = 'Showing the most recently available field calendar.';
            return $stale;
        }

        return null;
    }

    private function normalize_scale($value) {
        $scale = is_numeric($value) ? (int) round((float) $value) : 100;
        return min(100, max(60, $scale));
    }

    public function shortcode($atts) {
        $atts = shortcode_atts(array(
            'resource_id' => '3',
            'title' => 'Field Availability',
            'subtitle' => 'Scheduled field bookings are shown as unavailable.',
            'scale' => '100',
        ), $atts, 'field_booking_calendar');

        $resource_id = max(1, absint($atts['resource_id']));
        $scale = $this->normalize_scale($atts['scale']);
        $month = $this->month_start('');
        $id = 'ifrd-field-calendar-' . wp_generate_password(8, false, false);
        $initial_payload = $this->cached_month_snapshot($month, $resource_id);

        wp_enqueue_style('ifrd-field-booking-calendar');
        wp_enqueue_script('ifrd-field-booking-calendar');

        $config = array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action' => self::AJAX_ACTION,
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
            'resourceId' => $resource_id,
            'initialMonth' => $month->format('Y-m'),
            'canRefresh' => current_user_can('edit_pages'),
        );

        ob_start();
        ?>
        <div id="<?php echo esc_attr($id); ?>" class="ifrd-field-calendar" style="--iffc-scale:<?php echo esc_attr($scale / 100); ?>" data-ifrd-field-calendar data-config="<?php echo esc_attr(wp_json_encode($config)); ?>">
            <header class="ifrd-field-calendar-heading">
                <div>
                    <h2><?php echo esc_html($atts['title']); ?></h2>
                    <p><?php echo esc_html($atts['subtitle']); ?></p>
                </div>
                <?php if (current_user_can('edit_pages')): ?>
                    <button type="button" class="ifrd-field-calendar-refresh" data-field-refresh>Refresh Month</button>
                <?php endif; ?>
            </header>

            <nav class="ifrd-field-calendar-nav" aria-label="Field availability month">
                <button type="button" data-field-previous aria-label="Previous month">&lsaquo;</button>
                <strong data-field-month-label>Loading…</strong>
                <button type="button" data-field-next aria-label="Next month">&rsaquo;</button>
                <button type="button" class="ifrd-field-calendar-today" data-field-today>This Month</button>
            </nav>

            <div class="ifrd-field-calendar-status" data-field-status aria-live="polite">Loading field availability…</div>
            <div class="ifrd-field-calendar-scroll">
                <div class="ifrd-field-calendar-grid" data-field-grid aria-live="polite">
                    <div class="ifrd-field-calendar-loading">Loading field availability…</div>
                </div>
            </div>
            <?php if (is_array($initial_payload)): ?>
                <script type="application/json" data-field-initial><?php
                    echo wp_json_encode($initial_payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
                ?></script>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    public function ajax() {
        if (is_user_logged_in()) {
            check_ajax_referer(self::NONCE_ACTION, 'nonce');
        }

        $month_value = sanitize_text_field(wp_unslash($_POST['month'] ?? ''));
        $resource_id = max(1, absint($_POST['resource_id'] ?? 3));
        $force = !empty($_POST['force']) && current_user_can('edit_pages');
        $payload = $this->get_month($this->month_start($month_value), $resource_id, $force);

        if (is_wp_error($payload)) {
            wp_send_json_error(array('message' => $payload->get_error_message()), 500);
        }

        wp_send_json_success($payload);
    }

    private function get_month($month_start, $resource_id, $force) {
        $options = $this->opts();
        $ttl = max(60, absint($options['calendar_cache_seconds'] ?? 900));
        list($cache_key, $stale_key) = $this->cache_keys($month_start, $resource_id);

        if (!$force) {
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                $cached['cacheStatus'] = 'cached';
                return $cached;
            }
        }

        $stale = get_transient($stale_key);
        $lock_key = $cache_key . '_lock';
        if (get_transient($lock_key)) {
            if (is_array($stale)) {
                $stale['cacheStatus'] = 'stale-refreshing';
                $stale['warning'] = 'Showing the most recently available field calendar while an update runs.';
                return $stale;
            }
            return new WP_Error('ifrd_field_calendar_refreshing', 'This field calendar month is already refreshing. Please try again shortly.');
        }

        set_transient($lock_key, 1, 3 * MINUTE_IN_SECONDS);
        try {
            $payload = $this->fetch_month($month_start, $resource_id, $ttl, $force);
            if (is_wp_error($payload)) {
                if (is_array($stale)) {
                    $stale['cacheStatus'] = 'stale';
                    $stale['warning'] = 'Showing the most recently available field calendar while Dash is unavailable.';
                    return $stale;
                }
                return $payload;
            }

            $payload['cacheStatus'] = 'fresh';
            set_transient($cache_key, $payload, $ttl);
            set_transient($stale_key, $payload, 2 * DAY_IN_SECONDS);
            return $payload;
        } finally {
            delete_transient($lock_key);
        }
    }

    private function parse_datetime($value) {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            if (preg_match('/(?:Z|[+\-]\d{2}:?\d{2})$/i', trim($value))) {
                return (new DateTimeImmutable(trim($value)))->setTimezone($this->timezone());
            }
            return new DateTimeImmutable(trim($value), $this->timezone());
        } catch (Exception $exception) {
            return null;
        }
    }

    private function normalize_bookings($records, $month_start, $resource_id) {
        $month_end = $month_start->modify('+1 month');
        $segments = array();

        foreach ((array) $records as $record) {
            $attributes = isset($record['attributes']) && is_array($record['attributes']) ? $record['attributes'] : array();
            if ((string) ($attributes['resource_id'] ?? '') !== (string) $resource_id) {
                continue;
            }

            $start = $this->parse_datetime($attributes['start'] ?? '');
            $end = $this->parse_datetime($attributes['end'] ?? '');
            if (!$start || !$end || $end <= $start || $end <= $month_start || $start >= $month_end) {
                continue;
            }

            $cursor = $start < $month_start ? $month_start : $start;
            $limit = $end > $month_end ? $month_end : $end;
            while ($cursor < $limit) {
                $next_day = $cursor->modify('tomorrow')->setTime(0, 0, 0);
                $segment_end = $limit < $next_day ? $limit : $next_day;
                $date = $cursor->format('Y-m-d');
                $segments[$date][] = array($cursor->getTimestamp(), $segment_end->getTimestamp());
                $cursor = $segment_end;
            }
        }

        $bookings = array();
        foreach ($segments as $date => $date_segments) {
            usort($date_segments, function($first, $second) {
                return $first[0] <=> $second[0];
            });

            $merged = array();
            foreach ($date_segments as $segment) {
                $last_index = count($merged) - 1;
                // Treat short turnovers as continuously unavailable. Only a
                // true gap of one hour or longer should appear available.
                $gap = $last_index >= 0 ? $segment[0] - $merged[$last_index][1] : null;
                if ($last_index >= 0 && $gap < HOUR_IN_SECONDS) {
                    $merged[$last_index][1] = max($merged[$last_index][1], $segment[1]);
                } else {
                    $merged[] = $segment;
                }
            }

            foreach ($merged as $segment) {
                $start = (new DateTimeImmutable('@' . $segment[0]))->setTimezone($this->timezone());
                $end = (new DateTimeImmutable('@' . $segment[1]))->setTimezone($this->timezone());
                $day_start = $start->setTime(0, 0, 0);
                $all_day = $start == $day_start && $end == $day_start->modify('+1 day');
                $bookings[$date][] = array(
                    'label' => 'Unavailable',
                    'time' => $all_day ? 'All day' : $start->format('g:i A') . '–' . $end->format('g:i A'),
                );
            }
        }

        ksort($bookings);
        return $bookings;
    }

    private function fetch_month($month_start, $resource_id, $cache_ttl, $force) {
        if (!IFRD_Dash_Connector::is_ready()) {
            return new WP_Error('ifrd_connector_not_ready', 'The Dash Connector is not ready.');
        }

        // Include the prior day so an overnight booking that crosses into the
        // first of the month is not missed. Local filtering below remains the
        // authoritative resource and date boundary.
        $query_start = $month_start->modify('-1 day');
        $month_end = $month_start->modify('+1 month');
        $result = IFRD_Dash_Connector::get_events(array(
            'filter[start__gte]' => $query_start->format('Y-m-d') . 'T00:00:00',
            'filter[start__lte]' => $month_end->format('Y-m-d') . 'T00:00:00',
            'sort' => 'start',
            'page[size]' => 500,
        ), array(
            'cache_ttl' => $cache_ttl,
            'force' => (bool) $force,
            'max_pages' => 20,
            'collect_included' => false,
            'collection_filter' => function($record) use ($resource_id) {
                $attributes = isset($record['attributes']) && is_array($record['attributes']) ? $record['attributes'] : array();
                return (string) ($attributes['resource_id'] ?? '') === (string) $resource_id;
            },
        ));

        if (is_wp_error($result)) {
            return $result;
        }
        if (!empty($result['meta']['truncated'])) {
            return new WP_Error('ifrd_field_calendar_truncated', 'Dash returned more events than can be safely displayed for this month.');
        }

        return array(
            'month' => $month_start->format('Y-m'),
            'monthLabel' => $month_start->format('F Y'),
            'firstWeekday' => (int) $month_start->format('w'),
            'daysInMonth' => (int) $month_start->format('t'),
            'bookings' => $this->normalize_bookings($result['data'] ?? array(), $month_start, $resource_id),
            'updatedLabel' => wp_date('l, F j, Y \a\t g:i A', time(), $this->timezone()),
        );
    }
}
