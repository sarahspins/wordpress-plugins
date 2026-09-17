<?php
/**
 * Schedule Display
 */
class IFRD_Schedule_Display {
    const OPTION = 'ifrd_schedule_settings';
    const CACHE = 'ifrd_schedule_payload_v2711_static';
    const STALE_CACHE = 'ifrd_schedule_payload_stale_v2711';
    const CACHE_LOCK = 'ifrd_schedule_payload_lock_v2711';
    const CRON_HOOK = 'ifrd_refresh_static_today_schedule';
    const BACKGROUND_REFRESH_HOOK = 'ifrd_background_refresh_today_schedule';
    const BACKGROUND_REQUEST_LOCK = 'ifrd_background_schedule_refresh_requested';
    const MANUAL_REFRESH_HOOK = 'ifrd_manual_refresh_schedule_data';
    const MANUAL_REFRESH_STATUS_OPTION = 'ifrd_manual_schedule_refresh_status';
    const CAPABILITY = 'edit_pages';
    const REFRESH_HEALTH_OPTION = 'ifrd_schedule_refresh_health';

    public function __construct() {
        add_action('admin_menu', array($this, 'menu'), 20);
        add_action('admin_init', array($this, 'settings'));
        add_filter('option_page_capability_ifrd_schedule_group', array($this, 'settings_capability'));
        add_action('admin_enqueue_scripts', array($this, 'admin_assets'));
        add_action('admin_post_ifrd_refresh_schedule_screens', array($this, 'refresh_screens'));
        add_action('admin_post_ifrd_force_schedule_data_refresh', array($this, 'queue_manual_data_refresh'));
        add_shortcode('rink_schedule_display', array($this, 'shortcode'));
        add_action('wp_ajax_ifrd_schedule_data', array($this, 'ajax'));
        add_action('wp_ajax_nopriv_ifrd_schedule_data', array($this, 'ajax'));
        add_action('wp_ajax_ifrd_request_schedule_refresh', array($this, 'request_background_refresh'));
        add_action('wp_ajax_nopriv_ifrd_request_schedule_refresh', array($this, 'request_background_refresh'));
        add_filter('cron_schedules', array($this, 'cron_schedules'));
        add_action('init', array($this, 'ensure_cron'));
        add_action(self::CRON_HOOK, array($this, 'warm_static_cache'));
        add_action(self::BACKGROUND_REFRESH_HOOK, array($this, 'refresh_registration_counts'));
        add_action(self::MANUAL_REFRESH_HOOK, array($this, 'run_manual_data_refresh'));
    }

    public function cron_schedules($schedules) {
        $schedules['ifrd_five_minutes'] = array('interval' => 5 * MINUTE_IN_SECONDS, 'display' => 'Every five minutes');
        return $schedules;
    }

    public function ensure_cron() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 30, 'ifrd_five_minutes', self::CRON_HOOK);
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        wp_clear_scheduled_hook(self::BACKGROUND_REFRESH_HOOK);
        wp_clear_scheduled_hook(self::MANUAL_REFRESH_HOOK);
    }

    public function warm_static_cache() {
        $this->payload(true);
    }

    public function queue_manual_data_refresh() {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to refresh schedule data.', 'ice-field-rink-displays'));
        }
        check_admin_referer('ifrd_force_schedule_data_refresh');

        update_option(self::MANUAL_REFRESH_STATUS_OPTION, array('status' => 'queued', 'requested_at' => time()), false);
        if (!wp_next_scheduled(self::MANUAL_REFRESH_HOOK)) {
            wp_schedule_single_event(time(), self::MANUAL_REFRESH_HOOK);
        }
        if (function_exists('spawn_cron')) spawn_cron(time());

        wp_safe_redirect(add_query_arg(array('page' => 'ifrd-schedule-display', 'schedule-data-refresh' => 'queued'), admin_url('admin.php')));
        exit;
    }

    public function run_manual_data_refresh() {
        if (get_transient(self::CACHE_LOCK)) {
            if (!wp_next_scheduled(self::MANUAL_REFRESH_HOOK)) wp_schedule_single_event(time() + 30, self::MANUAL_REFRESH_HOOK);
            return;
        }
        delete_transient(self::CACHE);
        $result = $this->payload(true);
        if (is_wp_error($result)) {
            update_option(self::MANUAL_REFRESH_STATUS_OPTION, array('status' => 'failed', 'completed_at' => time(), 'message' => $result->get_error_message()), false);
            return;
        }
        IFRD_Video_For_Screens::bump_refresh_version();
        update_option(self::MANUAL_REFRESH_STATUS_OPTION, array('status' => 'complete', 'completed_at' => time()), false);
    }

    public function refresh_registration_counts() {
        try {
            $payload = get_transient(self::CACHE);
            if (!is_array($payload)) {
                $this->payload(true);
                return;
            }

            foreach (array('goldAll', 'silverAll') as $key) {
                $payload[$key] = $this->refresh_event_registration_counts((array) ($payload[$key] ?? array()));
            }
            $page_size = max(1, intval($payload['pageSize'] ?? 1));
            $payload['gold'] = array_slice((array) ($payload['goldAll'] ?? array()), 0, $page_size);
            $payload['silver'] = array_slice((array) ($payload['silverAll'] ?? array()), 0, $page_size);
            $payload['updatedAt'] = current_time('mysql');
            $payload['generatedAt'] = gmdate('c');

            $today = (new DateTimeImmutable('now', new DateTimeZone($this->schedule_timezone_name())))->format('Y-m-d');
            set_transient(self::CACHE, $payload, 240);
            set_transient(self::STALE_CACHE . '_' . $today, $payload, 2 * DAY_IN_SECONDS);
            if (IFRD_Static_Schedule_Cache::write('today-' . $today, $payload)) {
                $this->note_refresh_success();
            } else {
                $this->note_refresh_failure('The registration refresh could not update the static schedule file.');
            }
        } finally {
            delete_transient(self::BACKGROUND_REQUEST_LOCK);
        }
    }

    public function request_background_refresh() {
        if (!get_transient(self::BACKGROUND_REQUEST_LOCK) && !get_transient(self::CACHE_LOCK)) {
            set_transient(self::BACKGROUND_REQUEST_LOCK, 1, 3 * MINUTE_IN_SECONDS);
            if (!wp_next_scheduled(self::BACKGROUND_REFRESH_HOOK)) {
                wp_schedule_single_event(time(), self::BACKGROUND_REFRESH_HOOK);
            }
            if (function_exists('spawn_cron')) spawn_cron(time());
            wp_send_json_success(array('queued' => true));
        }
        wp_send_json_success(array('queued' => false));
    }

    public function defaults() {
        return array(
            'display_timezone' => 'America/Chicago',
            'gold_resource_id' => '1',
            'silver_resource_id' => '2',
            'refresh_seconds' => '300',
            'max_visible' => '8',
            'calendar_cache_seconds' => '900',
            'calendar_default_view' => 'week',
            'calendar_color_strength' => '50',
            'calendar_registration_links' => '1',
            'calendar_session_selector' => '1',
            'full_session_label' => 'FULL',
            'failure_alert_enabled' => '1',
            'failure_alert_threshold' => '3',
            'failure_alert_email' => (string) get_option('admin_email', ''),
            'display_title' => 'Ice & Field Schedule',
            'display_subtitle' => 'Today’s rink schedule',
            'gold_title' => 'Gold Rink',
            'silver_title' => 'Silver Rink',
            'logo_url' => '',
            'banner_url' => '',
            'banner_media_type' => 'auto',
            'banner_schedule' => array(),
            'banner_mode' => 'single',
            'banner_slideshow_images' => array(),
            'banner_slideshow_seconds' => '10',
            'banner_slideshow_transition' => 'fade',
            'banner_slideshow_transition_seconds' => '1',
            'locker_name_map' => "4=Warm Room\n5=Party Room 1\n6=Party Room 2\n7=Locker Room B\n8=Locker Room D\n9=Party Room 3\n10=Locker Room C\n11=Locker Room E\n12=Locker Room H\n13=Locker Room I\n14=Locker Room J\n15=Locker Room K",
            'bg_color' => '#06131f',
            'panel_color' => '#0d2235',
            'text_color' => '#f6f5fa',
            'muted_color' => '#b9c7d6',
            'accent_color' => '#33B6FF',
            'now_color' => '#4fd18b',
            'later_color' => '#FFc600',
            'next_color' => '#33B6FF',
            'full_badge_text_color' => '#ffffff',
            'full_badge_bg_color' => '#b42318',
        );
    }

    public function opts() {
        return wp_parse_args(get_option(self::OPTION, array()), $this->defaults());
    }

    public function menu() {
        add_menu_page(
            'Schedule Display',
            'Displays',
            self::CAPABILITY,
            'ifrd-schedule-display',
            array($this, 'page'),
            'dashicons-desktop',
            26
        );

        add_submenu_page(
            'ifrd-schedule-display',
            'Schedule Display',
            'Schedule Display',
            self::CAPABILITY,
            'ifrd-schedule-display',
            array($this, 'page')
        );
    }

    public function settings() {
        register_setting('ifrd_schedule_group', self::OPTION, array($this, 'sanitize'));
    }

    public function settings_capability() {
        return self::CAPABILITY;
    }

    public function admin_assets($hook) {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';

        if ($page === 'ifrd-schedule-display') {
            wp_enqueue_media();
        }
    }

    public function sanitize($input) {
        $defaults = $this->defaults();
        $clean = array();
        $old = $this->opts();

        if (!current_user_can('manage_options')) {
            $clean = $old;
            unset($clean['banner_link']);
            $clean['banner_url'] = isset($input['banner_url']) ? esc_url_raw($input['banner_url']) : (string) $old['banner_url'];
            $banner_media_type = isset($input['banner_media_type']) ? sanitize_key($input['banner_media_type']) : (string) $old['banner_media_type'];
            $clean['banner_media_type'] = in_array($banner_media_type, array('auto', 'image', 'video'), true) ? $banner_media_type : 'auto';
            $clean['banner_schedule'] = IFRD_Scheduled_Media::sanitize(
                $input['banner_schedule'] ?? array(),
                IFRD_Scheduled_Media::timezone_name($old)
            );
            $clean['banner_mode'] = (($input['banner_mode'] ?? 'single') === 'slideshow') ? 'slideshow' : 'single';
            $clean['banner_slideshow_images'] = IFRD_Expiring_Slideshow::sanitize($input['banner_slideshow_images'] ?? array(), IFRD_Scheduled_Media::timezone_name($old));
            $clean['banner_slideshow_seconds'] = (string) min(300, max(2, intval($input['banner_slideshow_seconds'] ?? 10)));
            $clean['banner_slideshow_transition'] = in_array(($input['banner_slideshow_transition'] ?? 'fade'), array('fade', 'slide', 'none'), true) ? $input['banner_slideshow_transition'] : 'fade';
            $clean['banner_slideshow_transition_seconds'] = (string) min(5, max(0.1, floatval($input['banner_slideshow_transition_seconds'] ?? 1)));
        } else {

            foreach ($defaults as $key => $default) {
                if ($key === 'banner_schedule') {
                    $submitted_timezone = sanitize_text_field((string) ($input['display_timezone'] ?? $old['display_timezone'] ?? 'America/Chicago'));
                    $clean[$key] = IFRD_Scheduled_Media::sanitize($input[$key] ?? array(), $submitted_timezone);
                    continue;
                }
                if ($key === 'banner_slideshow_images') {
                    $clean[$key] = IFRD_Expiring_Slideshow::sanitize($input[$key] ?? array(), sanitize_text_field((string) ($input['display_timezone'] ?? $old['display_timezone'])));
                    continue;
                }

                if (in_array($key, array('calendar_registration_links', 'calendar_session_selector', 'failure_alert_enabled'), true)) {
                    $clean[$key] = isset($input[$key]) ? '1' : '0';
                    continue;
                }

                $value = isset($input[$key]) ? $input[$key] : $default;

                if ($key === 'calendar_color_strength') {
                    $clean[$key] = (string) min(100, max(0, intval($value)));
                } elseif (in_array($key, array('gold_resource_id', 'silver_resource_id', 'refresh_seconds', 'max_visible', 'calendar_cache_seconds', 'failure_alert_threshold'), true)) {
                    $clean[$key] = $key === 'failure_alert_threshold' ? (string) min(24, max(1, intval($value))) : (string) max(1, intval($value));
                } elseif ($key === 'failure_alert_email') {
                    $clean[$key] = sanitize_email($value);
                } elseif ($key === 'calendar_default_view') {
                    $clean[$key] = in_array($value, array('day', 'week'), true) ? $value : 'week';
                } elseif ($key === 'locker_name_map') {
                    $clean[$key] = sanitize_textarea_field($value);
                } elseif ($key === 'banner_media_type') {
                    $clean[$key] = in_array($value, array('auto', 'image', 'video'), true) ? $value : 'auto';
                } elseif ($key === 'banner_mode') {
                    $clean[$key] = $value === 'slideshow' ? 'slideshow' : 'single';
                } elseif ($key === 'banner_slideshow_seconds') {
                    $clean[$key] = (string) min(300, max(2, intval($value)));
                } elseif ($key === 'banner_slideshow_transition') {
                    $clean[$key] = in_array($value, array('fade', 'slide', 'none'), true) ? $value : 'fade';
                } elseif ($key === 'banner_slideshow_transition_seconds') {
                    $clean[$key] = (string) min(5, max(0.1, floatval($value)));
                } elseif (substr($key, -4) === '_url') {
                    $clean[$key] = esc_url_raw($value);
                } elseif (substr($key, -6) === '_color') {
                    $clean[$key] = sanitize_hex_color($value) ?: $default;
                } else {
                    $clean[$key] = sanitize_text_field($value);
                }
            }
        }

        delete_transient(self::CACHE);
        update_option('ifrd_calendar_cache_version', absint(get_option('ifrd_calendar_cache_version', 1)) + 1, false);

        if (
            (string) ($clean['banner_url'] ?? '') !== (string) ($old['banner_url'] ?? '') ||
            (string) ($clean['banner_media_type'] ?? 'auto') !== (string) ($old['banner_media_type'] ?? 'auto') ||
            wp_json_encode($clean['banner_schedule'] ?? array()) !== wp_json_encode($old['banner_schedule'] ?? array())
            || ($clean['banner_mode'] ?? 'single') !== ($old['banner_mode'] ?? 'single')
            || wp_json_encode($clean['banner_slideshow_images'] ?? array()) !== wp_json_encode($old['banner_slideshow_images'] ?? array())
            || ($clean['banner_slideshow_seconds'] ?? '10') !== ($old['banner_slideshow_seconds'] ?? '10')
            || ($clean['banner_slideshow_transition'] ?? 'fade') !== ($old['banner_slideshow_transition'] ?? 'fade')
            || ($clean['banner_slideshow_transition_seconds'] ?? '1') !== ($old['banner_slideshow_transition_seconds'] ?? '1')
        ) {
            IFRD_Video_For_Screens::bump_refresh_version();
        }

        return $clean;
    }

    public function refresh_screens() {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to refresh these screens.', 'ice-field-rink-displays'));
        }

        check_admin_referer('ifrd_refresh_schedule_screens');
        IFRD_Video_For_Screens::bump_refresh_version();

        wp_safe_redirect(add_query_arg(
            array('page' => 'ifrd-schedule-display', 'schedule-screens-refreshed' => '1'),
            admin_url('admin.php')
        ));
        exit;
    }

    public function page() {
        $o = $this->opts();
        $effective_banner = IFRD_Scheduled_Media::effective($o['banner_url'], $o['banner_media_type'], $o['banner_schedule'] ?? array(), IFRD_Scheduled_Media::timezone_name($o));
        $next_banner_at = '';
        $now_key = (new DateTimeImmutable('now', new DateTimeZone(IFRD_Scheduled_Media::timezone_name($o))))->format('Y-m-d\TH:i');
        foreach (IFRD_Scheduled_Media::sanitize($o['banner_schedule'] ?? array(), IFRD_Scheduled_Media::timezone_name($o)) as $scheduled_banner) {
            if ($scheduled_banner['starts_at'] > $now_key) { $next_banner_at = $scheduled_banner['starts_at']; break; }
        }
        $manual_refresh = (array) get_option(self::MANUAL_REFRESH_STATUS_OPTION, array());
        ?>
        <div class="wrap">
            <h1>Schedule Display</h1>
            <?php if (!empty($_GET['schedule-screens-refreshed'])): ?>
                <div class="notice notice-success is-dismissible"><p>Schedule screen refresh requested. Open schedule displays should reload within about 60 seconds.</p></div>
            <?php endif; ?>
            <?php if (!empty($_GET['schedule-data-refresh'])): ?>
                <div class="notice notice-info is-dismissible"><p>Fresh schedule data has been queued. The schedule, registration totals, and locker assignments will rebuild in the background, then open TVs will be asked to reload.</p></div>
            <?php endif; ?>
            <?php if (($manual_refresh['status'] ?? '') === 'complete'): ?>
                <div class="notice notice-success is-dismissible"><p>Manual schedule data refresh completed <?php echo esc_html(wp_date('F j, Y g:i a', absint($manual_refresh['completed_at'] ?? time()))); ?>.</p></div>
            <?php elseif (($manual_refresh['status'] ?? '') === 'failed'): ?>
                <div class="notice notice-error is-dismissible"><p>Manual schedule data refresh failed: <?php echo esc_html($manual_refresh['message'] ?? 'Unknown error'); ?></p></div>
            <?php endif; ?>
            <section class="ifrd-admin-schedule-preview">
                <div class="ifrd-admin-preview-head"><strong>Live display preview</strong><span><?php echo $next_banner_at ? 'Next banner: ' . esc_html(str_replace('T', ' at ', $next_banner_at)) : 'No upcoming banner change'; ?></span></div>
                <div class="ifrd-admin-preview-screen">
                    <div class="ifrd-admin-preview-title"><strong><?php echo esc_html($o['display_title']); ?></strong><small><?php echo esc_html($o['display_subtitle']); ?></small></div>
                    <div class="ifrd-admin-preview-rinks"><span><?php echo esc_html($o['gold_title']); ?></span><span><?php echo esc_html($o['silver_title']); ?></span></div>
                    <div class="ifrd-admin-preview-banner" data-admin-banner-preview><?php if (!empty($effective_banner['url'])): ?><?php if (IFRD_Banner_Media::is_video($effective_banner['url'], $effective_banner['type'])): ?><video muted loop autoplay playsinline src="<?php echo esc_url($effective_banner['url']); ?>"></video><?php else: ?><img src="<?php echo esc_url($effective_banner['url']); ?>" alt=""><?php endif; ?><?php else: ?><span>No banner selected</span><?php endif; ?></div>
                </div>
            </section>
            <style>.ifrd-admin-schedule-preview{max-width:760px;margin:18px 0 24px}.ifrd-admin-preview-head{display:flex;justify-content:space-between;gap:16px;margin-bottom:8px;color:#50575e}.ifrd-admin-preview-screen{aspect-ratio:16/9;padding:18px;box-sizing:border-box;border-radius:12px;background:<?php echo esc_attr($o['bg_color']); ?>;color:<?php echo esc_attr($o['text_color']); ?>;display:grid;grid-template-rows:auto 1fr auto;gap:12px;overflow:hidden}.ifrd-admin-preview-title strong{display:block;font-size:24px}.ifrd-admin-preview-title small{color:<?php echo esc_attr($o['muted_color']); ?>}.ifrd-admin-preview-rinks{display:grid;grid-template-columns:1fr 1fr;gap:12px}.ifrd-admin-preview-rinks span{padding:12px;border-radius:10px;background:<?php echo esc_attr($o['panel_color']); ?>;font-size:20px;font-weight:700}.ifrd-admin-preview-banner{min-height:60px;display:flex;align-items:center;justify-content:center;color:<?php echo esc_attr($o['muted_color']); ?>}.ifrd-admin-preview-banner img,.ifrd-admin-preview-banner video{display:block;max-width:100%;max-height:90px;object-fit:contain}</style>
            <form method="post" action="options.php">
                <?php settings_fields('ifrd_schedule_group'); ?>
                <?php if (current_user_can('manage_options')): ?>
                <h2>Shared Dash Connector</h2>
                <?php IFRD_Dash_Connector::render_settings_status(); ?>
                <h2>Schedule Data</h2>
                <table class="form-table">
                    <tr><th>Display Time Zone</th><td><input class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[display_timezone]" value="<?php echo esc_attr($o['display_timezone'] ?? 'America/Chicago'); ?>"><p class="description">Use an IANA timezone such as <code>America/Chicago</code>. Used for schedule filtering, badges, and displayed event times.</p></td></tr>
                    <tr><th>Gold Resource ID</th><td><input type="number" class="small-text" name="<?php echo esc_attr(self::OPTION); ?>[gold_resource_id]" value="<?php echo esc_attr($o['gold_resource_id']); ?>"></td></tr>
                    <tr><th>Silver Resource ID</th><td><input type="number" class="small-text" name="<?php echo esc_attr(self::OPTION); ?>[silver_resource_id]" value="<?php echo esc_attr($o['silver_resource_id']); ?>"></td></tr>
                    <tr><th>Refresh Seconds</th><td><input type="number" class="small-text" name="<?php echo esc_attr(self::OPTION); ?>[refresh_seconds]" value="<?php echo esc_attr($o['refresh_seconds']); ?>"></td></tr>
                    <tr><th>Max Visible Per Rink</th><td><input type="number" class="small-text" name="<?php echo esc_attr(self::OPTION); ?>[max_visible]" value="<?php echo esc_attr($o['max_visible']); ?>"></td></tr>
                    <tr><th>Full Session Wording</th><td><input class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[full_session_label]" value="<?php echo esc_attr($o['full_session_label']); ?>"><p class="description">Shown before the registered-skater count when registration reaches the event capacity, for example <code>FULL - 20 registered skaters</code>. Leave blank to omit the full-session wording.</p></td></tr>
                    <tr><th>Refresh Failure Emails</th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[failure_alert_enabled]" value="1" <?php checked(!empty($o['failure_alert_enabled'])); ?>> Email when repeated scheduled refreshes fail</label><p class="description">Sends one outage email after the threshold is reached and one recovery email after the next successful refresh.</p></td></tr>
                    <tr><th>Failure Threshold</th><td><input type="number" min="1" max="24" class="small-text" name="<?php echo esc_attr(self::OPTION); ?>[failure_alert_threshold]" value="<?php echo esc_attr($o['failure_alert_threshold']); ?>"><p class="description">Consecutive five-minute refresh failures before notifying. The default of 3 is approximately 15 minutes.</p></td></tr>
                    <tr><th>Alert Email</th><td><input type="email" class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[failure_alert_email]" value="<?php echo esc_attr($o['failure_alert_email']); ?>"></td></tr>
                    <tr><th>Calendar Cache Seconds</th><td><input type="number" min="60" class="small-text" name="<?php echo esc_attr(self::OPTION); ?>[calendar_cache_seconds]" value="<?php echo esc_attr($o['calendar_cache_seconds']); ?>"><p class="description">Caches each week for the public day/week calendar. The default is 900 seconds (15 minutes).</p></td></tr>
                    <tr><th>Calendar Default View</th><td><select name="<?php echo esc_attr(self::OPTION); ?>[calendar_default_view]"><option value="week" <?php selected($o['calendar_default_view'], 'week'); ?>>Week</option><option value="day" <?php selected($o['calendar_default_view'], 'day'); ?>>Day</option></select></td></tr>
                    <tr><th>Calendar Event Color Strength</th><td><input type="number" min="0" max="100" step="5" class="small-text" name="<?php echo esc_attr(self::OPTION); ?>[calendar_color_strength]" value="<?php echo esc_attr($o['calendar_color_strength']); ?>">%<p class="description">Controls event-block color intensity in the selectable calendar. <code>50</code> is muted, <code>100</code> uses the full Dash or category color, and <code>0</code> removes the background fill.</p></td></tr>
                    <tr><th>Calendar Registration Links</th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[calendar_registration_links]" value="1" <?php checked(!empty($o['calendar_registration_links'])); ?>> Enable clickable registration links</label><p class="description">When disabled, calendar events are display-only and related registration records are not requested from Dash.</p></td></tr>
                    <tr><th>Calendar Session Type Selector</th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[calendar_session_selector]" value="1" <?php checked(!empty($o['calendar_session_selector'])); ?>> Show the Session Type selector</label><p class="description">Turn this off to hide the session-category filter from the public day/week calendar.</p></td></tr>
                </table>
                <h2>Display</h2>
                <table class="form-table">
                    <tr><th>Title</th><td><input class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[display_title]" value="<?php echo esc_attr($o['display_title']); ?>"></td></tr>
                    <tr><th>Subtitle</th><td><input class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[display_subtitle]" value="<?php echo esc_attr($o['display_subtitle']); ?>"></td></tr>
                    <tr><th>Gold Heading</th><td><input class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[gold_title]" value="<?php echo esc_attr($o['gold_title']); ?>"></td></tr>
                    <tr><th>Silver Heading</th><td><input class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[silver_title]" value="<?php echo esc_attr($o['silver_title']); ?>"></td></tr>
                    <tr><th>Logo</th><td><?php IFRD_Banner_Media::render_picker(self::OPTION, $o, 'logo_url', 'ifrd-schedule-logo', array('image'), array(
                        'title' => 'Choose display logo',
                        'button_text' => 'Use as logo',
                        'placeholder' => 'Select a logo or paste its image URL',
                        'description' => 'Choose an image from the WordPress Media Library.',
                    )); ?></td></tr>
                    <tr><th>Locker Room Name Map</th><td><textarea class="large-text code" rows="5" name="<?php echo esc_attr(self::OPTION); ?>[locker_name_map]"><?php echo esc_textarea($o['locker_name_map']); ?></textarea><p class="description">Optional fallback if Dash only returns locker IDs. One per line, like <code>1=Locker Room 1</code>.</p></td></tr>
                </table>
                <h2>Colors</h2>
                <table class="form-table">
                    <?php foreach (array('bg_color'=>'Background','panel_color'=>'Panel','text_color'=>'Text','muted_color'=>'Muted Text','accent_color'=>'Accent','now_color'=>'Now','next_color'=>'Up Next','later_color'=>'Later','full_badge_text_color'=>'FULL Badge Text','full_badge_bg_color'=>'FULL Badge Background') as $key => $label): ?>
                        <tr><th><?php echo esc_html($label); ?></th><td><input type="color" name="<?php echo esc_attr(self::OPTION); ?>[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($o[$key]); ?>"></td></tr>
                    <?php endforeach; ?>
                </table>
                <?php else: ?>
                <p>You can manage the media shown in the schedule display banner below.</p>
                <?php endif; ?>
                <h2>Schedule Banner Media</h2>
                <table class="form-table">
                    <tr><th>Current Banner Media</th><td><?php IFRD_Banner_Media::render_picker(self::OPTION, $o, 'banner_url', 'ifrd-schedule-banner', array('image', 'video'), array(
                        'type_field' => 'banner_media_type',
                        'title' => 'Choose banner image or video',
                        'button_text' => 'Use as banner',
                        'placeholder' => 'Select an image or video, or paste its URL',
                        'description' => 'This is the default banner. Video banners play automatically, muted, and on a continuous loop.',
                    )); ?></td></tr>
                </table>
                <h3>Banner Display Mode</h3>
                <p><select name="<?php echo esc_attr(self::OPTION); ?>[banner_mode]"><option value="single" <?php selected($o['banner_mode'], 'single'); ?>>Single image/video (including scheduled videos)</option><option value="slideshow" <?php selected($o['banner_mode'], 'slideshow'); ?>>Image slideshow</option></select></p>
                <?php IFRD_Expiring_Slideshow::render_editor(self::OPTION, 'banner_slideshow_images', $o['banner_slideshow_images'], IFRD_Scheduled_Media::timezone_name($o), 'Banner Image Slideshow'); ?>
                <p><label><strong>Seconds per image</strong> <input type="number" min="2" max="300" class="small-text" name="<?php echo esc_attr(self::OPTION); ?>[banner_slideshow_seconds]" value="<?php echo esc_attr($o['banner_slideshow_seconds']); ?>"></label></p>
                <p><label><strong>Transition</strong> <select name="<?php echo esc_attr(self::OPTION); ?>[banner_slideshow_transition]"><option value="fade" <?php selected($o['banner_slideshow_transition'], 'fade'); ?>>Fade</option><option value="slide" <?php selected($o['banner_slideshow_transition'], 'slide'); ?>>Slide</option><option value="none" <?php selected($o['banner_slideshow_transition'], 'none'); ?>>None</option></select></label> &nbsp; <label><strong>Duration</strong> <input type="number" min="0.1" max="5" step="0.1" class="small-text" name="<?php echo esc_attr(self::OPTION); ?>[banner_slideshow_transition_seconds]" value="<?php echo esc_attr($o['banner_slideshow_transition_seconds']); ?>"> seconds</label></p>
                <?php IFRD_Scheduled_Media::render_editor(
                    self::OPTION,
                    'banner_schedule',
                    $o['banner_schedule'] ?? array(),
                    IFRD_Scheduled_Media::timezone_name($o),
                    'Scheduled Banner Video Changes'
                ); ?>
                <?php submit_button(); ?>
            </form>
            <hr>
            <h2>Refresh Schedule Data</h2>
            <p>Bypass the saved schedule cache and rebuild today’s events, registration totals, and locker assignments. The refresh runs in the background and asks open TVs to reload after it completes.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="ifrd_force_schedule_data_refresh">
                <?php wp_nonce_field('ifrd_force_schedule_data_refresh'); ?>
                <?php submit_button('Refresh Schedule Data Now', 'secondary', 'submit', false); ?>
            </form>
            <hr>
            <h2>Update Schedule Video</h2>
            <p>Reload every open TV using <code>[rink_schedule_display]</code> so the current banner video and page changes appear immediately.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="ifrd_refresh_schedule_screens">
                <?php wp_nonce_field('ifrd_refresh_schedule_screens'); ?>
                <?php submit_button('Update Video Now', 'secondary', 'submit', false); ?>
            </form>
            <p><strong>TV shortcode:</strong> <code>[rink_schedule_display]</code></p>
            <p><strong>Calendar shortcode:</strong> <code>[rink_schedule_calendar]</code> (alias: <code>[rink_schedule_list]</code>)</p>
        </div>
        <?php IFRD_Banner_Media::print_picker_script(); ?>
        <?php IFRD_Scheduled_Media::print_editor_script(); ?>
        <?php IFRD_Expiring_Slideshow::print_editor_script(); ?>
        <script>(function(){const field=document.getElementById('ifrd-schedule-banner');const preview=document.querySelector('[data-admin-banner-preview]');if(!field||!preview)return;field.addEventListener('input',function(){const url=field.value.trim();const type=field.closest('.ifrd-media-picker').querySelector('.ifrd-media-type');const video=(type&&type.value==='video')||/\.(mp4|m4v|webm|ogv|ogg|mov)(?:[?#]|$)/i.test(url);preview.innerHTML=url?(video?'<video muted loop autoplay playsinline src="'+url.replace(/"/g,'&quot;')+'"></video>':'<img src="'+url.replace(/"/g,'&quot;')+'" alt="">'):'<span>No banner selected</span>';});})();</script>
        <?php
    }

    private function dash_related_name($type, $id) {
        $id = (string)$id;
        if ($id === '' || $id === '0') return '';

        $cache_key = 'ifrd_name_' . md5($type . '|' . $id);
        $cached = get_transient($cache_key);
        if ($cached !== false) return $cached;

        $paths = array(
            'team' => array('teams', 'team'),
            'league' => array('leagues', 'league'),
            'customer' => array('customers', 'customer'),
        );
        $try_paths = isset($paths[$type]) ? $paths[$type] : array($type . 's');

        foreach ($try_paths as $path) {
            $payload = IFRD_Dash_Connector::get(
                $path . '/' . rawurlencode($id),
                array(),
                array('cache_ttl' => 6 * HOUR_IN_SECONDS)
            );

            if (is_wp_error($payload) || empty($payload['data']['attributes'])) continue;

            $a = $payload['data']['attributes'];
            foreach (array('name', 'full_name', 'team_name', 'desc', 'description', 'title') as $field) {
                if (!empty($a[$field]) && is_string($a[$field])) {
                    $name = trim(wp_strip_all_tags(html_entity_decode((string)$a[$field], ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                    if ($name !== '') {
                        set_transient($cache_key, $name, 6 * HOUR_IN_SECONDS);
                        return $name;
                    }
                }
            }
        }

        set_transient($cache_key, '', 15 * MINUTE_IN_SECONDS);
        return '';
    }

    private function dash_team_title_from_event_attrs($a) {
        $home = !empty($a['hteam_id']) ? $this->dash_related_name('team', $a['hteam_id']) : '';
        $away = !empty($a['vteam_id']) ? $this->dash_related_name('team', $a['vteam_id']) : '';

        if ($home !== '' && $away !== '') {
            // If Dash lists the same team/class on both sides, show it once.
            if (strcasecmp(trim($home), trim($away)) === 0) {
                return $home;
            }
            return $home . ' vs ' . $away;
        }
        if ($home !== '') return $home;
        if ($away !== '') return $away;
        return '';
    }

    public function event_title($raw) {
        $a = isset($raw['attributes']) ? $raw['attributes'] : array();

        // Use short event fields only as the title.
        foreach (array('desc', 'name', 'title') as $field) {
            if (!empty($a[$field]) && is_string($a[$field])) {
                $title = trim(wp_strip_all_tags(html_entity_decode((string)$a[$field], ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                if ($title !== '') return $title;
            }
        }

        // For league/game events, resolve teams before considering fallback text.
        $team_title = $this->dash_team_title_from_event_attrs($a);
        if ($team_title !== '') return $team_title;

        $event_type = (string)($a['event_type_id'] ?? '');
        $league_id = (string)($a['league_id'] ?? '');
        $hteam_id = (string)($a['hteam_id'] ?? '');

        if ($event_type === 'k' || $league_id === '43' || $hteam_id === '99') return 'Stick & Puck';
        if ($event_type === '9') return 'Open Freestyle Session';

        // Only use best_description as title if it is short. Never use long description/notice as title.
        if (!empty($a['best_description']) && is_string($a['best_description'])) {
            $candidate = trim(wp_strip_all_tags(html_entity_decode((string)$a['best_description'], ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if ($candidate !== '' && strlen($candidate) <= 80) return $candidate;
        }

        return 'Scheduled Event';
    }


    private function schedule_timezone_name() {
        $o = $this->opts();
        $tz = trim((string)($o['display_timezone'] ?? 'America/Chicago'));

        if ($tz === '' || !in_array($tz, timezone_identifiers_list(), true)) {
            $tz = 'America/Chicago';
        }

        return $tz;
    }

    private function parse_schedule_time($value) {
        try {
            $dt = new DateTimeImmutable((string)$value, new DateTimeZone($this->schedule_timezone_name()));
            return $dt->getTimestamp();
        } catch (Exception $e) {
            return strtotime((string)$value);
        }
    }

    private function schedule_now_bounds() {
        $tz = new DateTimeZone($this->schedule_timezone_name());
        $now_dt = new DateTimeImmutable('now', $tz);
        $start_dt = $now_dt->setTime(0, 0, 0);
        $end_dt = $now_dt->setTime(23, 59, 59);

        return array(
            $now_dt->getTimestamp(),
            $start_dt->getTimestamp(),
            $end_dt->getTimestamp(),
        );
    }

    private function format_schedule_time($timestamp, $format = 'g:i A') {
        $dt = (new DateTimeImmutable('@' . intval($timestamp)))->setTimezone(new DateTimeZone($this->schedule_timezone_name()));
        return $dt->format($format);
    }

    private function normalize($raw) {
        $a = isset($raw['attributes']) ? $raw['attributes'] : array();

        if (empty($a['start']) || empty($a['end'])) {
            return null;
        }

        $start = $this->parse_schedule_time($a['start']);
        $end = $this->parse_schedule_time($a['end']);

        if (!$start || !$end) {
            return null;
        }

        $note = '';
        foreach (array('notice', 'best_description', 'description') as $field) {
            if (!empty($a[$field]) && is_string($a[$field])) {
                $candidate = html_entity_decode((string) $a[$field], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $candidate = str_replace("\xc2\xa0", ' ', $candidate);
                $candidate = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($candidate)));

                // Only show short public notes. Longer descriptions clutter the TV layout.
                if ($candidate !== '' && mb_strlen($candidate) <= 100) {
                    $note = $candidate;
                }

                break;
            }
        }

        $capacity = null;
        foreach (array('register_capacity', 'registration_capacity', 'capacity', 'max_registrants', 'maxRegistrants') as $capacity_field) {
            if (isset($a[$capacity_field]) && is_numeric($a[$capacity_field])) {
                $capacity = max(0, intval($a[$capacity_field]));
                break;
            }
        }

        return array(
            'id' => (string)($raw['id'] ?? ''),
            'title' => $this->event_title($raw),
            'start' => $a['start'],
            'end' => $a['end'],
            'startTs' => $start,
            'endTs' => $end,
            'startLabel' => $this->format_schedule_time($start),
            'endLabel' => $this->format_schedule_time($end),
            'resourceId' => intval($a['resource_id'] ?? 0),
            'publish' => !empty($a['publish']),
            'capacity' => $capacity,
            'note' => $note,
        );
    }

    private function parse_locker_name_map($map_text) {
        $map = array();
        $lines = preg_split('/\r\n|\r|\n/', (string) $map_text);

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || strpos($line, '=') === false) {
                continue;
            }

            list($id, $name) = array_map('trim', explode('=', $line, 2));

            if ($id !== '' && $name !== '') {
                $map[(string) $id] = $name;
            }
        }

        return $map;
    }

    private function locker_name_from_resource($resource, $fallback_map = array()) {
        if (!is_array($resource)) {
            return '';
        }

        $id = isset($resource['id']) ? (string) $resource['id'] : '';
        $type = isset($resource['type']) ? (string) $resource['type'] : '';
        $a = isset($resource['attributes']) && is_array($resource['attributes']) ? $resource['attributes'] : array();

        // Dash returns assigned locker rooms as child event records:
        // type=events, id=child event ID, attributes.resource_id=actual locker room resource ID,
        // attributes.desc=assignment note/team.
        if ($type === 'events' && !empty($a['resource_id'])) {
            $resource_id = (string) $a['resource_id'];
            $base_name = !empty($fallback_map[$resource_id]) ? $fallback_map[$resource_id] : 'Locker Room ' . $resource_id;
            $note = !empty($a['desc']) ? trim(wp_strip_all_tags((string) $a['desc'])) : '';

            if ($note !== '') {
                return $base_name . ' (' . $note . ')';
            }

            return $base_name;
        }

        // Standard resource payload by actual resource ID.
        if ($id !== '' && !empty($fallback_map[$id])) {
            return $fallback_map[$id];
        }

        foreach (array(
            'name',
            'resource_name',
            'locker_name',
            'locker_room_name',
            'room_name',
            'description',
            'desc',
            'label',
            'title',
            'room',
            'locker_room'
        ) as $field) {
            if (!empty($a[$field]) && is_string($a[$field])) {
                return trim(wp_strip_all_tags($a[$field]));
            }
        }

        // Some DaySmart resources nest useful labels deeper.
        foreach ($a as $value) {
            if (is_array($value)) {
                foreach (array('name', 'description', 'desc', 'label', 'title') as $field) {
                    if (!empty($value[$field]) && is_string($value[$field])) {
                        return trim(wp_strip_all_tags($value[$field]));
                    }
                }
            }
        }

        return $id !== '' ? 'Locker Room ' . $id : '';
    }

    private function fetch_locker_name_by_id($locker_id, $fallback_map = array()) {
        $locker_id = (string) $locker_id;

        if ($locker_id === '') {
            return '';
        }

        if (!empty($fallback_map[$locker_id])) {
            return $fallback_map[$locker_id];
        }

        $urls = array(
            // DaySmart returned links point to this host/path in your payloads.
            'https://api.daysmartrecreation.com/api/v1/lockers/' . rawurlencode($locker_id),
            'https://api.daysmartrecreation.com/api/v1/resources/' . rawurlencode($locker_id),
            // Also try the Dash platform host for completeness.
            'https://api.dashplatform.com/v1/lockers/' . rawurlencode($locker_id),
            'https://api.dashplatform.com/v1/resources/' . rawurlencode($locker_id),
        );

        foreach ($urls as $base_url) {
            $payload = IFRD_Dash_Connector::get(
                $base_url,
                array(),
                array('cache_ttl' => 240)
            );

            if (is_wp_error($payload) || empty($payload['data'])) {
                continue;
            }

            $name = $this->locker_name_from_resource($payload['data'], $fallback_map);

            if ($name !== '') {
                return $name;
            }
        }

        return 'Locker Room ' . $locker_id;
    }

    private function get_event_lockers($event_id, $fallback_map = array()) {
        $names = array();

        // Try the full relationship endpoint first.
        $full_urls = array(
            'https://api.daysmartrecreation.com/api/v1/events/' . rawurlencode($event_id) . '/lockers',
            'https://api.dashplatform.com/v1/events/' . rawurlencode($event_id) . '/lockers',
        );

        foreach ($full_urls as $base_url) {
            $payload = IFRD_Dash_Connector::get(
                $base_url,
                array('page[size]' => 50),
                array('cache_ttl' => 240)
            );

            if (is_wp_error($payload) || empty($payload['data']) || !is_array($payload['data'])) {
                continue;
            }

            foreach ($payload['data'] as $locker) {
                $name = $this->locker_name_from_resource($locker, $fallback_map);

                if ($name !== '') {
                    $names[] = $name;
                }
            }

            if (!empty($names)) {
                break;
            }
        }

        // Fallback to relationship IDs, then resolve each ID.
        if (empty($names)) {
            $relationship_urls = array(
                'https://api.daysmartrecreation.com/api/v1/events/' . rawurlencode($event_id) . '/relationships/lockers',
                'https://api.dashplatform.com/v1/events/' . rawurlencode($event_id) . '/relationships/lockers',
            );

            foreach ($relationship_urls as $base_url) {
                $payload = IFRD_Dash_Connector::get(
                    $base_url,
                    array('page[size]' => 50),
                    array('cache_ttl' => 240)
                );

                if (is_wp_error($payload) || empty($payload['data']) || !is_array($payload['data'])) {
                    continue;
                }

                foreach ($payload['data'] as $locker_rel) {
                    if (empty($locker_rel['id'])) {
                        continue;
                    }

                    $name = $this->fetch_locker_name_by_id($locker_rel['id'], $fallback_map);

                    if ($name !== '') {
                        $names[] = $name;
                    }
                }

                if (!empty($names)) {
                    break;
                }
            }
        }

        return array_values(array_unique(array_filter($names)));
    }

    private function participant_display_metadata_settings() {
        $saved = get_option('ifrd_participants_settings', array());

        return wp_parse_args(is_array($saved) ? $saved : array(), array(
            'registrants_endpoint' => 'https://api.dashplatform.com/v1/events/{event_id}/registrants',
            'qualifying_keywords' => "freestyle\nstick & puck\nstick and puck\nprivate hockey coaches ice\nprivate hockey coach\nphci",
        ));
    }

    private function event_qualifies_for_participant_display($event, $keywords) {
        $title = strtolower((string)($event['title'] ?? ''));
        $lines = preg_split('/\r\n|\r|\n/', (string)$keywords);

        foreach ($lines as $line) {
            $keyword = trim(strtolower($line));

            if ($keyword !== '' && strpos($title, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    private function get_event_registrant_count($event_id, $settings) {
        $endpoint = trim((string)($settings['registrants_endpoint'] ?? ''));

        if ($endpoint === '' || strpos($endpoint, '{event_id}') === false) {
            $endpoint = 'https://api.dashplatform.com/v1/events/{event_id}/registrants';
        }

        $endpoint = str_replace('{event_id}', rawurlencode((string)$event_id), $endpoint);
        $payload = IFRD_Dash_Connector::get(
            $endpoint,
            array('page[size]' => 500),
            array('cache' => false)
        );

        if (is_wp_error($payload)) {
            return $payload;
        }

        if (isset($payload['meta']['page']['total'])) {
            return max(0, intval($payload['meta']['page']['total']));
        }

        return !empty($payload['data']) && is_array($payload['data']) ? count($payload['data']) : 0;
    }

    private function refresh_event_registration_counts($events) {
        $participant_settings = $this->participant_display_metadata_settings();
        $schedule_settings = $this->opts();

        foreach ($events as &$event) {
            if (!$this->event_qualifies_for_participant_display($event, $participant_settings['qualifying_keywords'])) continue;
            $registrant_count = $this->get_event_registrant_count($event['id'] ?? '', $participant_settings);
            if (is_wp_error($registrant_count)) continue;

            $event['registrantCount'] = $registrant_count;
            $event['registrantText'] = $registrant_count === 1 ? '1 registered skater' : $registrant_count . ' registered skaters';
            $event['isFull'] = false;
            $event['fullLabel'] = '';
            $capacity = isset($event['capacity']) ? max(0, intval($event['capacity'])) : 0;
            $full_label = trim((string) ($schedule_settings['full_session_label'] ?? 'FULL'));
            if ($capacity > 0 && $registrant_count >= $capacity && $full_label !== '') {
                $event['isFull'] = true;
                $event['fullLabel'] = $full_label;
            }
        }
        unset($event);
        return $events;
    }

    private function add_locker_data_to_events($events, $locker_name_map = '') {
        $fallback_map = $this->parse_locker_name_map($locker_name_map);
        $participant_settings = $this->participant_display_metadata_settings();
        $schedule_settings = $this->opts();

        foreach ($events as &$event) {
            $event['registrantText'] = '';
            $event['isFull'] = false;
            $event['fullLabel'] = '';

            if ($this->event_qualifies_for_participant_display($event, $participant_settings['qualifying_keywords'])) {
                $registrant_count = $this->get_event_registrant_count(
                    $event['id'],
                    $participant_settings
                );

                if (!is_wp_error($registrant_count)) {
                    $event['registrantCount'] = $registrant_count;
                    if ($registrant_count === 1) {
                        $event['registrantText'] = '1 registered skater';
                    } else {
                        $event['registrantText'] = $registrant_count . ' registered skaters';
                    }

                    $capacity = isset($event['capacity']) ? max(0, intval($event['capacity'])) : 0;
                    $full_label = trim((string) ($schedule_settings['full_session_label'] ?? 'FULL'));
                    if ($capacity > 0 && $registrant_count >= $capacity && $full_label !== '') {
                        $event['registrantText'] = $full_label . ' - ' . $event['registrantText'];
                        $event['isFull'] = true;
                        $event['fullLabel'] = $full_label;
                    }
                }
            }

            $event['lockers'] = $this->get_event_lockers($event['id'], $fallback_map);

            if (!empty($event['lockers'])) {
                $event['lockerText'] = implode(', ', $event['lockers']);
            } else {
                $event['lockerText'] = '';
            }
        }

        return $events;
    }

    private function is_learn_to_skate_event($title) {
        $title_lc = strtolower((string) $title);

        // Only collapse true Learn to Skate blocks into the parent LTS display.
        // Specialty classes still get day/time cleanup but remain their own schedule rows.
        return (
            strpos($title_lc, 'learn to skate') !== false ||
            preg_match('/\blts\b/i', $title_lc)
        );
    }

    private function clean_lts_event_title($title) {
        $title = html_entity_decode((string) $title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = str_replace("\xc2\xa0", ' ', $title);
        $title = trim($title);

        // Strip weekday text only. Preserve punctuation/special characters in the rest of the title.
        $title = preg_replace('/\b(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)\b/i', '', $title);
        $title = preg_replace('/\b(Mon|Tue|Tues|Wed|Thu|Thur|Thurs|Fri|Sat|Sun)\b\.?/i', '', $title);

        // Strip time ranges only. Preserve dashes elsewhere.
        // Examples: 6:00pm-7:30pm, 6:00 PM – 7:30 PM, 6pm to 7pm
        $title = preg_replace('/\b\d{1,2}(?:\s*:\s*\d{2})?\s*(?:AM|PM|am|pm)?\s*(?:-|–|—|to)\s*\d{1,2}(?:\s*:\s*\d{2})?\s*(?:AM|PM|am|pm)\b/i', '', $title);

        // Strip standalone times only.
        // Examples: 6:00pm, 6:00 PM, 6 : 00 PM, 7pm
        $title = preg_replace('/\b\d{1,2}\s*:\s*\d{2}\s*(?:A\.?M\.?|P\.?M\.?|AM|PM|am|pm)\b/i', '', $title);
        $title = preg_replace('/\b\d{1,2}\s*(?:A\.?M\.?|P\.?M\.?|AM|PM|am|pm)\b/i', '', $title);

        // Strip 24-hour-style standalone times only.
        $title = preg_replace('/\b(?:[01]?\d|2[0-3])\s*:\s*[0-5]\d\b/', '', $title);

        // Only normalize whitespace and comma spacing; do not remove dashes/special characters.
        $title = preg_replace('/\s+/', ' ', $title);
        $title = preg_replace('/\s+,/', ',', $title);
        $title = preg_replace('/,\s*/', ', ', $title);

        return trim($title);
    }

    private function lts_parent_title($title) {
        $clean = $this->clean_lts_event_title($title);
        $clean_lc = strtolower($clean);

        if (strpos($clean_lc, 'learn to skate') !== false) {
            return 'Learn to Skate';
        }

        // Keep specialty/drop-in class titles specific, but cleaned.
        return $clean !== '' ? $clean : $title;
    }

    private function should_clean_schedule_title($title) {
        $title_lc = strtolower((string) $title);

        if (preg_match('/\b(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday|Mon|Tue|Tues|Wed|Thu|Thur|Thurs|Fri|Sat|Sun)\b\.?/i', $title)) {
            return true;
        }

        if (preg_match('/\b\d{1,2}(?::\d{2})?\s*(?:AM|PM|am|pm)\b/i', $title)) {
            return true;
        }

        return (
            strpos($title_lc, 'learn to skate') !== false ||
            preg_match('/\blts\b/i', $title_lc) ||
            strpos($title_lc, 'skating skills') !== false ||
            strpos($title_lc, 'jumps') !== false ||
            strpos($title_lc, 'spins') !== false ||
            strpos($title_lc, 'artistry') !== false ||
            strpos($title_lc, 'choreo') !== false ||
            strpos($title_lc, 'edge') !== false
        );
    }

    private function merge_learn_to_skate_blocks($events) {
        $groups = array();
        $ungrouped = array();

        foreach ($events as $event) {
            if ($this->should_clean_schedule_title($event['title'])) {
                $clean_title = $this->clean_lts_event_title($event['title']);
                if ($clean_title !== '') {
                    $event['title'] = $clean_title;
                }
            }

            if (!$this->is_learn_to_skate_event($event['title'])) {
                $ungrouped[] = $event;
                continue;
            }

            $parent_title = $this->lts_parent_title($event['title']);

            // Group same-day/same-rink Learn to Skate blocks into one parent display item.
            $key = implode('|', array(
                'lts',
                $event['resourceId'],
                date('Y-m-d', $event['startTs']),
                strtolower($parent_title),
            ));

            if (!isset($groups[$key])) {
                $groups[$key] = $event;
                $groups[$key]['title'] = $parent_title;
                $groups[$key]['startTs'] = $event['startTs'];
                $groups[$key]['endTs'] = $event['endTs'];
                $groups[$key]['start'] = $event['start'];
                $groups[$key]['end'] = $event['end'];
                $groups[$key]['startLabel'] = $event['startLabel'];
                $groups[$key]['endLabel'] = $event['endLabel'];
                $groups[$key]['subBlocks'] = array();
                $groups[$key]['isLtsParent'] = true;
            }

            $sub_title = $this->clean_lts_event_title($event['title']);
            if (strcasecmp($sub_title, $parent_title) === 0) {
                $sub_title = '';
            }

            $groups[$key]['subBlocks'][] = array(
                'time' => $event['startLabel'] . '–' . $event['endLabel'],
                'title' => $sub_title,
            );

            if ($event['startTs'] < $groups[$key]['startTs']) {
                $groups[$key]['startTs'] = $event['startTs'];
                $groups[$key]['start'] = $event['start'];
                $groups[$key]['startLabel'] = $event['startLabel'];
            }

            if ($event['endTs'] > $groups[$key]['endTs']) {
                $groups[$key]['endTs'] = $event['endTs'];
                $groups[$key]['end'] = $event['end'];
                $groups[$key]['endLabel'] = $event['endLabel'];
            }

            $groups[$key]['timeRange'] = $groups[$key]['startLabel'] . ' – ' . $groups[$key]['endLabel'];
        }

        $merged = array_merge(array_values($groups), $ungrouped);

        foreach ($merged as &$event) {
            if (!empty($event['subBlocks'])) {
                $seen = array();
                $blocks = array();

                foreach ($event['subBlocks'] as $block) {
                    $block_key = $block['time'] . '|' . $block['title'];
                    if (!isset($seen[$block_key])) {
                        $seen[$block_key] = true;
                        $blocks[] = $block;
                    }
                }

                usort($blocks, function($a, $b) {
                    return strcmp($a['time'], $b['time']);
                });

                $event['subBlocks'] = $blocks;
            }
        }

        usort($merged, function($a, $b) {
            if ($a['startTs'] === $b['startTs']) {
                // When a Learn to Skate parent shares the same start time as its sub-segments,
                // keep the parent first so the display reads as one grouped event.
                $a_lts = !empty($a['isLtsParent']) ? 0 : 1;
                $b_lts = !empty($b['isLtsParent']) ? 0 : 1;
                if ($a_lts !== $b_lts) {
                    return $a_lts <=> $b_lts;
                }

                return strcasecmp($a['title'], $b['title']);
            }

            return $a['startTs'] <=> $b['startTs'];
        });

        return $merged;
    }

    private function join_event_titles($titles) {
        $clean = array();

        foreach ((array) $titles as $title) {
            $title = html_entity_decode((string) $title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $title = str_replace("\xc2\xa0", ' ', $title);
            $title = preg_replace('/\s+/', ' ', $title);
            $title = preg_replace('/\s+,/', ',', $title);
            $title = preg_replace('/,\s*/', ', ', $title);
            $title = trim($title);
            $title = trim($title, " \t\n\r\0\x0B,");

            if ($title !== '') {
                $clean[] = $title;
            }
        }

        $clean = array_values(array_unique($clean));
        sort($clean, SORT_NATURAL | SORT_FLAG_CASE);

        $count = count($clean);

        if ($count === 0) {
            return '';
        }

        if ($count === 1) {
            return $clean[0];
        }

        if ($count === 2) {
            return $clean[0] . ' & ' . $clean[1];
        }

        $last = array_pop($clean);
        return implode(', ', $clean) . ' & ' . $last;
    }

    private function merge_simultaneous_events($events) {
        $groups = array();

        foreach ($events as $event) {
            $key = implode('|', array(
                $event['resourceId'],
                $event['start'],
                $event['end'],
            ));

            if (!isset($groups[$key])) {
                $groups[$key] = $event;
                $groups[$key]['mergedTitles'] = array($event['title']);
                $groups[$key]['mergedNotes'] = array();

                if (!empty($event['note'])) {
                    $groups[$key]['mergedNotes'][] = $event['note'];
                }

                continue;
            }

            if (!in_array($event['title'], $groups[$key]['mergedTitles'], true)) {
                $groups[$key]['mergedTitles'][] = $event['title'];
            }

            if (!empty($event['note']) && !in_array($event['note'], $groups[$key]['mergedNotes'], true)) {
                $groups[$key]['mergedNotes'][] = $event['note'];
            }

            $groups[$key]['title'] = $this->join_event_titles($groups[$key]['mergedTitles']);

            if (!empty($groups[$key]['mergedNotes'])) {
                $combined_note = implode(' • ', $groups[$key]['mergedNotes']);
                $groups[$key]['note'] = mb_strlen($combined_note) <= 100 ? $combined_note : '';
            }
        }

        $merged = array_values($groups);

        foreach ($merged as &$event) {
            unset($event['mergedTitles'], $event['mergedNotes']);
        }

        usort($merged, function($a, $b) {
            if ($a['startTs'] === $b['startTs']) {
                return strcasecmp($a['title'], $b['title']);
            }

            return $a['startTs'] <=> $b['startTs'];
        });

        return $merged;
    }

    private function note_refresh_failure($error) {
        $o = $this->opts();
        $state = wp_parse_args(get_option(self::REFRESH_HEALTH_OPTION, array()), array('failures' => 0, 'alerted' => 0, 'first_failed_at' => 0, 'last_error' => ''));
        $state['failures'] = absint($state['failures']) + 1;
        $state['first_failed_at'] = absint($state['first_failed_at']) ?: time();
        $state['last_error'] = is_wp_error($error) ? $error->get_error_message() : sanitize_text_field((string) $error);
        $threshold = max(1, min(24, intval($o['failure_alert_threshold'] ?? 3)));
        $recipient = sanitize_email((string) ($o['failure_alert_email'] ?? get_option('admin_email', '')));
        if (!empty($o['failure_alert_enabled']) && !$state['alerted'] && $state['failures'] >= $threshold && is_email($recipient)) {
            $subject = sprintf('[%s] Schedule display refresh failure', wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES));
            $message = "The Ice & Field schedule has failed to refresh " . $state['failures'] . " consecutive times.\n\nFirst failure: " . wp_date('F j, Y g:i a', $state['first_failed_at']) . "\nMost recent error: " . $state['last_error'] . "\n\nThe last successful schedule remains available to the TV displays.\n";
            if (wp_mail($recipient, $subject, $message)) $state['alerted'] = 1;
        }
        update_option(self::REFRESH_HEALTH_OPTION, $state, false);
    }

    private function note_refresh_success() {
        $state = wp_parse_args(get_option(self::REFRESH_HEALTH_OPTION, array()), array('failures' => 0, 'alerted' => 0));
        if (!empty($state['alerted'])) {
            $o = $this->opts();
            $recipient = sanitize_email((string) ($o['failure_alert_email'] ?? get_option('admin_email', '')));
            if (!empty($o['failure_alert_enabled']) && is_email($recipient)) {
                wp_mail($recipient, sprintf('[%s] Schedule display refresh recovered', wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES)), "The Ice & Field schedule refreshed successfully again at " . wp_date('F j, Y g:i a') . ".\n");
            }
        }
        delete_option(self::REFRESH_HEALTH_OPTION);
    }

    private function payload($force = false) {
        $cached = $force ? false : get_transient(self::CACHE);
        if ($cached) {
            $cached_date = new DateTimeImmutable('now', new DateTimeZone($this->schedule_timezone_name()));
            IFRD_Static_Schedule_Cache::write('today-' . $cached_date->format('Y-m-d'), $cached);
            return $cached;
        }

        $o = $this->opts();
        list($now, $day_start, $day_end) = $this->schedule_now_bounds();
        $timezone = new DateTimeZone($this->schedule_timezone_name());
        $query_start = (new DateTimeImmutable('@' . $day_start))->setTimezone($timezone)->format('Y-m-d\\TH:i:s');
        $query_end = (new DateTimeImmutable('@' . $day_end))->setTimezone($timezone)->format('Y-m-d\\TH:i:s');
        $stale_cache_key = self::STALE_CACHE . '_' . substr($query_start, 0, 10);
        $stale = get_transient($stale_cache_key);
        if (get_transient(self::CACHE_LOCK)) {
            if (is_array($stale)) return $stale;
            return new WP_Error('ifrd_schedule_refreshing', 'The schedule is already refreshing. Please try again shortly.');
        }
        set_transient(self::CACHE_LOCK, 1, 2 * MINUTE_IN_SECONDS);

        try {

        $events = array();
        $page = 1;
        $last = 1;

        do {
            $body = IFRD_Dash_Connector::get('events', array(
                'filter[start__gte]' => $query_start,
                'filter[start__lte]' => $query_end,
                'sort' => 'start',
                'page[number]' => $page,
                'page[size]' => 100,
            ), array('cache' => false));

            if (is_wp_error($body)) {
                if ($force) $this->note_refresh_failure($body);
                if (is_array($stale)) return $stale;
                return $body;
            }

            if (!empty($body['meta']['page']['last-page'])) {
                $last = intval($body['meta']['page']['last-page']);
            }

            foreach (($body['data'] ?? array()) as $raw) {
                $event = $this->normalize($raw);

                if (!$event) {
                    continue;
                }

                if ($event['startTs'] > $day_end && $page > 1) {
                    break 2;
                }

                if (!$event['publish']) {
                    continue;
                }

                if ($event['endTs'] < $now) {
                    continue;
                }

                if ($event['startTs'] < $day_start || $event['startTs'] > $day_end) {
                    continue;
                }

                if (!in_array($event['resourceId'], array(intval($o['gold_resource_id']), intval($o['silver_resource_id'])), true)) {
                    continue;
                }

                $events[] = $event;
            }

            $page++;
        } while ($page <= $last);

        usort($events, function($a, $b) {
            return $a['startTs'] <=> $b['startTs'];
        });

        $events = $this->merge_simultaneous_events($events);
        $events = $this->merge_learn_to_skate_blocks($events);

        $max = max(1, intval($o['max_visible']));
        $gold = array_values(array_filter($events, function($event) use ($o) {
            return $event['resourceId'] === intval($o['gold_resource_id']);
        }));
        $silver = array_values(array_filter($events, function($event) use ($o) {
            return $event['resourceId'] === intval($o['silver_resource_id']);
        }));

        // Every event can appear on a rotating TV page, so enrich the complete
        // display set before slicing the backward-compatible first page.
        $gold_all = $this->add_locker_data_to_events($gold, $o['locker_name_map']);
        $silver_all = $this->add_locker_data_to_events($silver, $o['locker_name_map']);
        $gold_visible = array_slice($gold_all, 0, $max);
        $silver_visible = array_slice($silver_all, 0, $max);

        $payload = array(
            'updatedAt' => current_time('mysql'),
            'generatedAt' => gmdate('c'),
            'gold' => $gold_visible,
            'silver' => $silver_visible,
            'goldAll' => $gold_all,
            'silverAll' => $silver_all,
            'pageSize' => $max,
            'goldAdditional' => max(0, count($gold) - $max),
            'silverAdditional' => max(0, count($silver) - $max),
        );

        set_transient(self::CACHE, $payload, 240);
        set_transient($stale_cache_key, $payload, 2 * DAY_IN_SECONDS);
        $static_written = IFRD_Static_Schedule_Cache::write('today-' . substr($query_start, 0, 10), $payload);
        if ($force) {
            if ($static_written) $this->note_refresh_success();
            else $this->note_refresh_failure('The static schedule file could not be written.');
        }

        return $payload;
        } finally {
            delete_transient(self::CACHE_LOCK);
        }
    }

    public function ajax() {
        $data = $this->payload();

        if (is_wp_error($data)) {
            wp_send_json_error(array('message' => $data->get_error_message()), 500);
        }

        wp_send_json_success($data);
    }

    public function shortcode($atts) {
        $o = $this->opts();
        $effective_banner = IFRD_Scheduled_Media::effective(
            $o['banner_url'] ?? '',
            $o['banner_media_type'] ?? 'auto',
            $o['banner_schedule'] ?? array(),
            IFRD_Scheduled_Media::timezone_name($o)
        );
        $o['banner_url'] = $effective_banner['url'];
        $o['banner_media_type'] = $effective_banner['type'];
        $banner_slides = (($o['banner_mode'] ?? 'single') === 'slideshow')
            ? IFRD_Expiring_Slideshow::active($o['banner_slideshow_images'] ?? array(), IFRD_Scheduled_Media::timezone_name($o))
            : array();
        $id = 'ifrd_sched_' . wp_generate_password(8, false);
        $screen_refresh_version = IFRD_Video_For_Screens::current_display_version();
        $today = new DateTimeImmutable('now', new DateTimeZone($this->schedule_timezone_name()));
        $static_schedule_url = IFRD_Static_Schedule_Cache::url('today-' . $today->format('Y-m-d'));
        $style = sprintf(
            '--ifr-bg:%s;--ifr-panel:%s;--ifr-text:%s;--ifr-muted:%s;--ifr-accent:%s;--ifr-now:%s;--ifr-next:%s;--ifr-later:%s;--ifr-full-text:%s;--ifr-full-bg:%s;',
            esc_attr($o['bg_color']),
            esc_attr($o['panel_color']),
            esc_attr($o['text_color']),
            esc_attr($o['muted_color']),
            esc_attr($o['accent_color']),
            esc_attr($o['now_color']),
            esc_attr($o['next_color']),
            esc_attr($o['later_color']),
            esc_attr($o['full_badge_text_color']),
            esc_attr($o['full_badge_bg_color'])
        );

        ob_start();
        ?>
        <div id="<?php echo esc_attr($id); ?>" class="ifrd-schedule-app" style="<?php echo esc_attr($style); ?>" data-ifrd-display-version="<?php echo esc_attr(IFRD_VERSION); ?>" data-ifrd-static-url="<?php echo esc_url($static_schedule_url); ?>" data-ifrd-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" data-ifrd-refresh-version="<?php echo esc_attr($screen_refresh_version); ?>" data-ifrd-refresh-action="<?php echo esc_attr(IFRD_Video_For_Screens::AJAX_ACTION); ?>" data-ifrd-refresh-seconds="<?php echo esc_attr(max(30, intval($o['refresh_seconds']))); ?>" data-ifrd-max-visible="<?php echo esc_attr(max(1, intval($o['max_visible']))); ?>">
            <div class="ifrd-schedule-topbar">
                <div class="ifrd-schedule-brand">
                    <?php if (!empty($o['logo_url'])): ?><img class="ifrd-schedule-logo" src="<?php echo esc_url($o['logo_url']); ?>" alt="Logo"><?php endif; ?>
                    <div><h1><?php echo esc_html($o['display_title']); ?></h1><p><?php echo esc_html($o['display_subtitle']); ?></p></div>
                </div>
                <div class="ifrd-schedule-clock"><strong data-time>--:--</strong><span data-date>Loading...</span></div>
            </div>
            <div class="ifrd-schedule-grid">
                <section class="ifrd-schedule-panel"><h2><?php echo esc_html($o['gold_title']); ?></h2><div class="ifrd-schedule-list" data-list="gold"></div></section>
                <section class="ifrd-schedule-panel"><h2><?php echo esc_html($o['silver_title']); ?></h2><div class="ifrd-schedule-list" data-list="silver"></div></section>
            </div>
            <div class="ifrd-schedule-footer">
                <span class="ifrd-schedule-health" data-status>Display <?php echo esc_html(IFRD_VERSION); ?> • API status: connecting… 0s</span>
				<span><span data-updated>Last updated: --</span> <span class="ifrd-schedule-count-disclaimer">*<em>Participant counts may not always be accurate due to delays in API processing.</em></span></span>
			</div>
			<div class="ifrd-schedule-footer ifrd-schedule-banner-footer">
                <?php if ($banner_slides): ?>
                    <?php echo IFRD_Expiring_Slideshow::render($banner_slides, $o['banner_slideshow_seconds'] ?? 10, 'ifrd-schedule-banner-slideshow', '', $o['banner_slideshow_transition'] ?? 'fade', $o['banner_slideshow_transition_seconds'] ?? 1); ?>
                <?php elseif (!empty($o['banner_url'])): ?>
                    <?php if (IFRD_Banner_Media::is_video($o['banner_url'], $o['banner_media_type'] ?? 'auto')): ?>
                        <video class="ifrd-schedule-banner" autoplay muted loop playsinline preload="metadata"><source src="<?php echo esc_url($o['banner_url']); ?>"></video>
                    <?php else: ?>
                        <img class="ifrd-schedule-banner" src="<?php echo esc_url($o['banner_url']); ?>" alt="">
                    <?php endif; ?>
                <?php endif; ?>
			</div>
        </div>
        <style>
        .ifrd-schedule-app{background:var(--ifr-bg);color:var(--ifr-text);font-family:system-ui,sans-serif;min-height:100vh;height:100vh;padding:2px;box-sizing:border-box;display:grid;grid-template-rows:auto 1fr auto;gap:12px;overflow:hidden}
        .ifrd-schedule-topbar{display:flex;justify-content:space-between;gap:10px;align-items:center}
        .ifrd-schedule-brand{display:flex;align-items:center;gap:14px;min-width:0}.ifrd-schedule-logo{max-height:70px;max-width:190px;object-fit:contain}
        .ifrd-schedule-topbar h1{font-size:clamp(30px,3vw,58px);line-height:1;margin:0;font-weight:850}.ifrd-schedule-topbar p{color:var(--ifr-muted);font-size:clamp(15px,1.3vw,24px);margin:5px 0 0}
        .ifrd-schedule-clock{text-align:right;min-width:260px;color:var(--ifr-muted)}.ifrd-schedule-clock strong{display:block;color:var(--ifr-text);font-size:clamp(28px,2.6vw,52px);line-height:1;white-space:nowrap}.ifrd-schedule-clock span{white-space:nowrap}
        .ifrd-schedule-grid{display:grid!important;grid-template-columns:minmax(0,1fr) minmax(0,1fr)!important;gap:14px;min-height:0}
        .ifrd-schedule-panel{background:var(--ifr-panel);border-radius:22px;padding:12px 14px;overflow:hidden;min-width:0;border:1px solid rgba(255,255,255,.1)}
        .ifrd-schedule-panel h2{font-size:clamp(24px,2vw,40px);line-height:1;margin:0 0 8px}
        .ifrd-schedule-event{display:grid;grid-template-columns:minmax(128px,8.5vw) minmax(0,1fr) auto;gap:12px;align-items:center;padding:5px 8px;border-top:1px solid rgba(255,255,255,.08);border-left:4px solid transparent;border-radius:8px}
        .ifrd-schedule-event:first-child{border-top-color:transparent}.ifrd-schedule-event.is-now{border-left-color:var(--ifr-now);background:rgba(255,255,255,.07)}.ifrd-schedule-event.is-later{opacity:.82}.ifrd-schedule-resurfacing .ifrd-schedule-title{color:var(--ifr-text)}.ifrd-schedule-time{min-width:0;overflow:hidden;font-weight:850;font-size:clamp(14px,1.1vw,21px);line-height:1.08}.ifrd-schedule-time span{display:block;color:var(--ifr-muted);font-size:.82em;font-weight:500;margin-top:2px}.ifrd-schedule-relative{max-width:100%;overflow:hidden;text-overflow:clip;color:var(--ifr-text)!important;font-size:12px;font-weight:750!important;line-height:1.05;letter-spacing:-.01em;white-space:nowrap}
        .ifrd-schedule-title{font-weight:850;font-size:clamp(16px,1.35vw,26px);line-height:1.1}.ifrd-schedule-meta{color:var(--ifr-muted);font-size:clamp(11px,.95vw,17px);margin-top:2px}.ifrd-schedule-subblocks{color:var(--ifr-muted);font-size:clamp(11px,.95vw,17px);margin-top:4px;line-height:1.25}.ifrd-schedule-subblocks strong{color:var(--ifr-text);font-weight:850}
        .ifrd-schedule-badges{display:flex;flex-direction:column;align-items:flex-end;gap:5px}.ifrd-schedule-badge{font-size:clamp(10px,.85vw,15px);font-weight:850;white-space:nowrap;background:rgba(255,255,255,.08);border-radius:999px;padding:6px 9px}.ifrd-schedule-badge.now{color:var(--ifr-now);background:rgba(255,255,255,.12)}.ifrd-schedule-badge.next{color:var(--ifr-next)}.ifrd-schedule-badge.later{color:var(--ifr-later)}.ifrd-schedule-badge.past{color:var(--ifr-muted)}.ifrd-registration-meta{display:flex;align-items:center;gap:6px}.ifrd-full-inline{display:inline-block;color:var(--ifr-full-text);background:var(--ifr-full-bg);border-radius:999px;padding:2px 6px;font-size:.72em;font-weight:850;line-height:1.15;white-space:nowrap}
        .ifrd-schedule-additional,.ifrd-schedule-empty,.ifrd-schedule-error{color:var(--ifr-muted);font-size:clamp(13px,1vw,18px);font-weight:700;padding-top:8px}.ifrd-schedule-error{color:#ffd4d4}.ifrd-schedule-group-label{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:6px 0 0;padding:6px 8px 2px;border-top:1px solid rgba(255,255,255,.14);color:var(--ifr-later);font-size:clamp(11px,.9vw,16px);font-weight:850;text-transform:uppercase;letter-spacing:.08em}.ifrd-schedule-rotation{color:var(--ifr-muted);font-size:clamp(10px,.8vw,14px);font-weight:800;text-transform:none;letter-spacing:0}.ifrd-schedule-later-page.is-rotating{animation:ifrd-page-slide .45s ease-out}@keyframes ifrd-page-slide{from{opacity:.25;transform:translateY(10px)}to{opacity:1;transform:none}}
        .ifrd-schedule-footer{display:flex;align-items:stretch;justify-content:space-between;gap:12px;color:var(--ifr-muted);font-size:8px;margin-top: -8px;margin-bottom: 0px;}.ifrd-schedule-health:empty{display:none}.ifrd-schedule-banner-footer{width:100%;justify-content:center;min-width:0}.ifrd-schedule-banner{display:block;width:100%;min-height:90px;max-height:140px;object-fit:contain;object-position:center center;border-radius:8px}.ifrd-schedule-locker{display:block}
        .ifrd-schedule-banner-slideshow{position:relative;width:100%;height:140px}.ifrd-schedule-banner-slideshow .ifrd-slide{position:absolute;inset:0;width:100%;height:100%}.ifrd-schedule-banner-slideshow img{display:block;width:100%;height:100%;object-fit:contain;object-position:center center;border-radius:8px}
        @media(max-width:99999px){.ifrd-schedule-grid{grid-template-columns:minmax(0,1fr) minmax(0,1fr)!important}}
        </style>
        <style>.ifrd-schedule-count-disclaimer{font-size:inherit;color:var(--ifr-muted)}</style>
        <script type="text/plain" data-ifrd-legacy-script>
        (function(){
            const root=document.getElementById('<?php echo esc_js($id); ?>');
            const connectionStatus=root.querySelector('[data-status]');
            const connectionStarted=Date.now();
            const connectionTimer=setInterval(function(){connectionStatus.textContent='Display 2.7.17 • API status: connecting… '+Math.floor((Date.now()-connectionStarted)/1000)+'s';},1000);
            function esc(v){return String(v??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));}
            function clock(){root.querySelector('[data-time]').textContent=new Date().toLocaleTimeString([],{hour:'numeric',minute:'2-digit'});root.querySelector('[data-date]').textContent=new Date().toLocaleDateString([],{weekday:'long',month:'long',day:'numeric'});}
            function badge(e){let n=Date.now(),s=new Date(e.start).getTime(),end=new Date(e.end).getTime();if(end<=n)return ['PAST','past'];if(s<=n&&end>n)return ['ON ICE NOW','now'];let mins=(s-n)/60000;if(mins>0&&mins<=60)return ['UP NEXT','next'];return ['LATER','later'];}
            function render(key,items,add){const el=root.querySelector('[data-list="'+key+'"]');const now=Date.now();const allItems=Array.isArray(items)?items:[];const pastItems=allItems.filter(e=>new Date(e.end).getTime()<=now);const activeItems=allItems.filter(e=>new Date(e.end).getTime()>now);items=activeItems.length?activeItems:(pastItems.length?[pastItems[pastItems.length-1]]:[]);if(!items.length){el.innerHTML='<div class="ifrd-schedule-empty">No more events today.</div>';return;}const hasCurrent=activeItems.some(e=>{let s=new Date(e.start).getTime(),end=new Date(e.end).getTime();return s<=now&&end>now;});const hasUpcoming=activeItems.some(e=>new Date(e.start).getTime()>now);let html='';if(!hasCurrent&&pastItems.length&&hasUpcoming){html+='<article class="ifrd-schedule-event ifrd-schedule-resurfacing"><div class="ifrd-schedule-time"></div><div><div class="ifrd-schedule-title">Resurfacing</div></div><div class="ifrd-schedule-badge now">ON ICE NOW</div></article>';}html+=items.map(e=>{let b=badge(e);let metaParts=[];if(e.note)metaParts.push(esc(e.note));if(e.registrantText)metaParts.push(esc(e.registrantText));let meta=metaParts.length?'<div class="ifrd-schedule-meta">'+metaParts.join(' • ')+'</div>':'';let locker=e.lockerText?'<div class="ifrd-schedule-meta ifrd-schedule-locker">'+esc(e.lockerText)+'</div>':'';let blocks=(e.subBlocks&&e.subBlocks.length)?'<div class="ifrd-schedule-subblocks">'+e.subBlocks.map(x=>'<div><strong>'+esc(x.time)+'</strong>'+((x.title)?' — '+esc(x.title):'')+'</div>').join('')+'</div>':'';return '<article class="ifrd-schedule-event"><div class="ifrd-schedule-time">'+esc(e.startLabel)+'<span>to '+esc(e.endLabel)+'</span></div><div><div class="ifrd-schedule-title">'+esc(e.title)+'</div>'+blocks+meta+locker+'</div><div class="ifrd-schedule-badge '+b[1]+'">'+b[0]+'</div></article>';}).join('');el.innerHTML=html+(add>0?'<div class="ifrd-schedule-additional">+'+add+' additional events scheduled</div>':'');}
            const scheduleCacheKey='ifrd_schedule_last_success_v231';
            function localDayKey(){const d=new Date();return [d.getFullYear(),String(d.getMonth()+1).padStart(2,'0'),String(d.getDate()).padStart(2,'0')].join('-');}
            let currentScheduleData=null;
            function displaySchedule(data){currentScheduleData=data;render('gold',data.gold||[],data.goldAdditional||0);render('silver',data.silver||[],data.silverAdditional||0);const updated=data.cachedAt?new Date(data.cachedAt):new Date();root.querySelector('[data-updated]').textContent='Last updated: '+updated.toLocaleTimeString([],{hour:'numeric',minute:'2-digit',second:'2-digit'});}
            function showCachedSchedule(){try{const cached=JSON.parse(localStorage.getItem(scheduleCacheKey));if(cached&&cached.cachedDay===localDayKey()){displaySchedule(cached);root.querySelector('[data-status]').textContent='API status: connecting — showing saved schedule';return true;}if(cached){localStorage.removeItem(scheduleCacheKey);}}catch(e){try{localStorage.removeItem(scheduleCacheKey);}catch(ignore){}}return false;}
            async function load(){const status=root.querySelector('[data-status]');try{let schedule=null;const staticUrl=<?php echo wp_json_encode($static_schedule_url); ?>;if(staticUrl){try{const staticResponse=await fetch(staticUrl,{cache:'no-store'});if(staticResponse.ok)schedule=await staticResponse.json();}catch(ignore){}}if(!schedule){let f=new FormData();f.append('action','ifrd_schedule_data');let r=await fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>',{method:'POST',body:f,cache:'no-store'});if(!r.ok)throw new Error('Schedule refresh failed');let p=await r.json();if(!p.success)throw new Error(p.data?.message||'Unable to load schedule');schedule=p.data;}schedule=Object.assign({},schedule,{cachedAt:Date.now(),cachedDay:localDayKey()});displaySchedule(schedule);try{localStorage.setItem(scheduleCacheKey,JSON.stringify(schedule));}catch(ignore){}clearInterval(connectionTimer);status.textContent='API status: connected';}catch(e){clearInterval(connectionTimer);const gold=root.querySelector('[data-list="gold"]');const silver=root.querySelector('[data-list="silver"]');const hasVisibleSchedule=(gold&&gold.children.length>0)||(silver&&silver.children.length>0);status.textContent=hasVisibleSchedule?'API status: refresh failed — showing last schedule':'API status: error';if(!hasVisibleSchedule&&gold){gold.innerHTML='<div class="ifrd-schedule-error">'+esc(e.message)+'</div>';}if(window.console&&console.error)console.error(e);}}
            const embeddedRefreshVersion=<?php echo wp_json_encode($screen_refresh_version); ?>;
            const initialPageUrl=new URL(window.location.href);
            let currentRefreshVersion=initialPageUrl.searchParams.get('screen_refresh')||embeddedRefreshVersion;
            if(initialPageUrl.searchParams.has('screen_refresh')&&window.history&&history.replaceState){setTimeout(function(){initialPageUrl.searchParams.delete('screen_refresh');history.replaceState(history.state,'',initialPageUrl.toString());},0);}
            async function checkForScreenRefresh(){try{const body=new URLSearchParams();body.set('action',<?php echo wp_json_encode(IFRD_Video_For_Screens::AJAX_ACTION); ?>);const response=await fetch(<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>,{method:'POST',headers:{'Accept':'application/json','Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:body.toString(),credentials:'same-origin',cache:'no-store'});const payload=await response.json();const latest=String(payload&&payload.success&&payload.data&&payload.data.version||'');if(!latest||latest===currentRefreshVersion)return;currentRefreshVersion=latest;const target=new URL(window.location.href);target.searchParams.set('screen_refresh',latest);window.location.replace(target.toString());}catch(ignore){}}
            setInterval(checkForScreenRefresh,60000);setTimeout(checkForScreenRefresh,5000);document.addEventListener('visibilitychange',function(){if(!document.hidden)checkForScreenRefresh();});
            clock();setInterval(clock,1000);showCachedSchedule();load();setInterval(load,<?php echo max(30,intval($o['refresh_seconds']))*1000; ?>);setInterval(function(){if(currentScheduleData)displaySchedule(currentScheduleData);},30000);
        })();
        </script>
        <script src="<?php echo esc_url(add_query_arg('ver', IFRD_VERSION, IFRD_URL . 'assets/schedule-display.js')); ?>"></script>
        <?php
        return ob_get_clean();
    }
}
