<?php
/**
 * Participant Display
 */
class IFRD_Participants_Display {
    const OPTION = 'ifrd_participants_settings';

    public function __construct() {
        add_action('admin_menu', array($this, 'menu'), 30);
        add_action('admin_init', array($this, 'settings'));
        add_action('admin_enqueue_scripts', array($this, 'admin_assets'));
        add_shortcode('rink_participants_display', array($this, 'shortcode'));
        add_action('wp_ajax_ifrd_participants_data', array($this, 'ajax'));
        add_action('wp_ajax_nopriv_ifrd_participants_data', array($this, 'ajax'));
    }

    public function defaults() {
        return array(
            'gold_resource_id' => '1',
            'silver_resource_id' => '2',
            'testing_offset' => '0',
            'video_url' => '',
            'registrants_endpoint' => 'https://api.dashplatform.com/v1/events/{event_id}/registrants',
            'qualifying_keywords' => "freestyle\nstick & puck\nstick and puck\nprivate hockey coaches ice\nprivate hockey coach\nphci",
            'display_title' => 'Registered Participants',
            'subtitle_text' => '',
            'logo_url' => '',
            'banner_url' => '',
            'banner_media_type' => 'auto',
            'banner_link' => '',
            'locker_name_map' => "4=Warm Room\n5=Party Room 1\n6=Party Room 2\n7=Locker Room B\n8=Locker Room D\n9=Party Room 3\n10=Locker Room C\n11=Locker Room E\n12=Locker Room H\n13=Locker Room I\n14=Locker Room J\n15=Locker Room K",
            'bg_color' => '#06131f',
            'panel_color' => '#0d2235',
            'text_color' => '#f6f5fa',
            'muted_color' => '#b9c7d6',
            'accent_color' => '#33B6FF',
            'checked_color' => '#4fd18b',
            'now_color' => '#ff8033',
            'next_color' => '#FFc600',
        );
    }

    public function opts() {
        return wp_parse_args(get_option(self::OPTION, array()), $this->defaults());
    }

    public function menu() {
        add_submenu_page(
            'ifrd-schedule-display',
            'Participants Display',
            'Participants Display',
            'manage_options',
            'ifrd-participants-display',
            array($this, 'page')
        );
    }

    public function settings() {
        register_setting('ifrd_participants_group', self::OPTION, array($this, 'sanitize'));
    }

    public function admin_assets($hook) {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';

        if ($page === 'ifrd-participants-display') {
            wp_enqueue_media();
        }
    }

    public function sanitize($input) {
        $defaults = $this->defaults();
        $clean = array();

        foreach ($defaults as $key => $default) {
            $value = isset($input[$key]) ? $input[$key] : $default;

            if ($key === 'registrants_endpoint') {
                $clean[$key] = trim($value);
            } elseif ($key === 'qualifying_keywords') {
                $clean[$key] = sanitize_textarea_field($value);
            } elseif ($key === 'banner_media_type') {
                $clean[$key] = in_array($value, array('auto', 'image', 'video'), true) ? $value : 'auto';
            } elseif (in_array($key, array('gold_resource_id', 'silver_resource_id', 'testing_offset'), true)) {
                $clean[$key] = (string) intval($value);
            } elseif (substr($key, -4) === '_url' || $key === 'banner_link') {
                $clean[$key] = esc_url_raw($value);
            } elseif (substr($key, -6) === '_color') {
                $clean[$key] = sanitize_hex_color($value) ?: $default;
            } else {
                $clean[$key] = sanitize_text_field($value);
            }
        }

        return $clean;
    }

    public function page() {
        $o = $this->opts();
        ?>
        <div class="wrap">
            <h1>Participants Display</h1>
            <form method="post" action="options.php">
                <?php settings_fields('ifrd_participants_group'); ?>
                <h2>Shared Dash Connector</h2>
                <?php IFRD_Dash_Connector::render_settings_status(); ?>
                <h2>Participant Data</h2>
                <table class="form-table">
                    <tr><th>Gold Resource ID</th><td><input type="number" class="small-text" name="<?php echo esc_attr(self::OPTION); ?>[gold_resource_id]" value="<?php echo esc_attr($o['gold_resource_id']); ?>"></td></tr>
                    <tr><th>Silver Resource ID</th><td><input type="number" class="small-text" name="<?php echo esc_attr(self::OPTION); ?>[silver_resource_id]" value="<?php echo esc_attr($o['silver_resource_id']); ?>"></td></tr>
                    <tr><th>Testing Time Offset (minutes)</th><td><input type="number" class="small-text" name="<?php echo esc_attr(self::OPTION); ?>[testing_offset]" value="<?php echo esc_attr($o['testing_offset']); ?>"></td></tr>
                    <tr><th>Registrants Endpoint</th><td><input class="large-text code" name="<?php echo esc_attr(self::OPTION); ?>[registrants_endpoint]" value="<?php echo esc_attr($o['registrants_endpoint']); ?>"></td></tr>
                    <tr><th>Qualifying Keywords</th><td><textarea class="large-text code" rows="7" name="<?php echo esc_attr(self::OPTION); ?>[qualifying_keywords]"><?php echo esc_textarea($o['qualifying_keywords']); ?></textarea></td></tr>
                </table>
                <h2>Display</h2>
                <table class="form-table">
                    <tr><th>Display Text</th><td><input class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[display_title]" value="<?php echo esc_attr($o['display_title']); ?>"></td></tr>
                    <tr><th>Subtitle</th><td><input class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[subtitle_text]" value="<?php echo esc_attr($o['subtitle_text']); ?>"></td></tr>
                    <tr><th>Logo</th><td><?php IFRD_Banner_Media::render_picker(self::OPTION, $o, 'logo_url', 'ifrd-participants-logo', array('image'), array(
                        'title' => 'Choose display logo',
                        'button_text' => 'Use as logo',
                        'placeholder' => 'Select a logo or paste its image URL',
                        'description' => 'Choose an image from the WordPress Media Library.',
                    )); ?></td></tr>
                    <tr><th>Banner Media</th><td><?php IFRD_Banner_Media::render_picker(self::OPTION, $o, 'banner_url', 'ifrd-participants-banner', array('image', 'video'), array(
                        'type_field' => 'banner_media_type',
                        'title' => 'Choose banner image or video',
                        'button_text' => 'Use as banner',
                        'placeholder' => 'Select an image or video, or paste its URL',
                        'description' => 'Video banners play automatically, muted, and on a continuous loop.',
                    )); ?></td></tr>
                    <tr><th>Banner Link URL</th><td><input class="large-text" name="<?php echo esc_attr(self::OPTION); ?>[banner_link]" value="<?php echo esc_attr($o['banner_link']); ?>"></td></tr>
                    <tr><th>Fallback Video</th><td><?php IFRD_Banner_Media::render_picker(self::OPTION, $o, 'video_url', 'ifrd-participants-video', array('video'), array(
                        'title' => 'Choose fallback video',
                        'button_text' => 'Use as fallback video',
                        'placeholder' => 'Select a video or paste its URL',
                        'description' => 'Displayed when there is no qualifying participant event.',
                    )); ?></td></tr>
                </table>
                <h2>Colors</h2>
                <table class="form-table">
                    <?php foreach (array('bg_color'=>'Background','panel_color'=>'Panel','text_color'=>'Text','muted_color'=>'Muted Text','accent_color'=>'Accent','checked_color'=>'Checkmark','now_color'=>'Now','next_color'=>'Up Next') as $key => $label): ?>
                        <tr><th><?php echo esc_html($label); ?></th><td><input type="color" name="<?php echo esc_attr(self::OPTION); ?>[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($o[$key]); ?>"></td></tr>
                    <?php endforeach; ?>
                </table>
                <?php submit_button(); ?>
            </form>
            <p><strong>Shortcodes:</strong> <code>[rink_participants_display rink="gold"]</code> or <code>[rink_participants_display rink="silver"]</code></p>
        </div>
        <?php IFRD_Banner_Media::print_picker_script(); ?>
        <?php
    }

    private function event_title($raw) {
        $a = isset($raw['attributes']) ? $raw['attributes'] : array();
        $title = trim((string)($a['desc'] ?? ''));

        if ($title !== '') {
            return $title;
        }

        $event_type = (string)($a['event_type_id'] ?? '');
        $league_id = (string)($a['league_id'] ?? '');
        $hteam_id = (string)($a['hteam_id'] ?? '');

        if ($event_type === 'k' || $league_id === '43' || $hteam_id === '99') {
            return 'Stick & Puck';
        }

        if ($event_type === '9') {
            return 'Open Freestyle Session';
        }

        return 'Scheduled Event';
    }

    private function normalize_event($raw) {
        $a = isset($raw['attributes']) ? $raw['attributes'] : array();

        if (empty($a['start']) || empty($a['end'])) {
            return null;
        }

        $start = strtotime($a['start']);
        $end = strtotime($a['end']);

        if (!$start || !$end) {
            return null;
        }

        return array(
            'id' => (string)($raw['id'] ?? ''),
            'title' => $this->event_title($raw),
            'resourceId' => intval($a['resource_id'] ?? 0),
            'publish' => !empty($a['publish']),
            'start' => $a['start'],
            'end' => $a['end'],
            'startTs' => $start,
            'endTs' => $end,
            'startLabel' => date_i18n('g:i A', $start),
            'endLabel' => date_i18n('g:i A', $end),
            'eventTypeId' => (string)($a['event_type_id'] ?? ''),
            'leagueId' => (string)($a['league_id'] ?? ''),
            'homeTeamId' => (string)($a['hteam_id'] ?? ''),
            'capacity' => isset($a['register_capacity']) ? intval($a['register_capacity']) : null,
        );
    }

    private function event_category($event) {
        $title = strtolower($event['title'] ?? '');

        if (strpos($title, 'freestyle') !== false) {
            return 'freestyle';
        }

        if (strpos($title, 'stick') !== false || ($event['eventTypeId'] ?? '') === 'k' || ($event['homeTeamId'] ?? '') === '99') {
            return 'stick_puck';
        }

        if (strpos($title, 'private hockey coaches') !== false || strpos($title, 'phci') !== false) {
            return 'phci';
        }

        return 'other';
    }

    private function qualifies($event) {
        $o = $this->opts();
        $title = strtolower($event['title'] ?? '');
        $lines = preg_split('/\r\n|\r|\n/', $o['qualifying_keywords']);

        foreach ($lines as $line) {
            $keyword = trim(strtolower($line));

            if ($keyword !== '' && strpos($title, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    private function find_now_next($resource_id, &$debug = array()) {
        $o = $this->opts();
        $now = current_time('timestamp') - (intval($o['testing_offset']) * 60);
        $day_start = strtotime('today', $now);
        $day_end = strtotime('tomorrow', $now) - 1;

        $debug['pretend_now'] = date_i18n('Y-m-d g:i:s A', $now);
        $debug['resource_id_requested'] = $resource_id;

        $events = array();
        $page = 1;
        $last = 1;

        do {
            $payload = IFRD_Dash_Connector::get('events', array(
                'sort' => 'start',
                'page[number]' => $page,
                'page[size]' => 100,
            ), array('cache_ttl' => 240));

            if (is_wp_error($payload)) {
                return $payload;
            }

            if (!empty($payload['meta']['page']['last-page'])) {
                $last = intval($payload['meta']['page']['last-page']);
            }

            foreach (($payload['data'] ?? array()) as $raw) {
                $event = $this->normalize_event($raw);

                if (!$event) {
                    continue;
                }

                if ($event['startTs'] > $day_end && $page > 1) {
                    break 2;
                }

                if ($event['resourceId'] !== intval($resource_id) || !$event['publish']) {
                    continue;
                }

                if ($event['startTs'] < $day_start || $event['startTs'] > $day_end) {
                    continue;
                }

                $event['qualifies'] = $this->qualifies($event);
                $events[] = $event;
            }

            $page++;
        } while ($page <= $last);

        usort($events, function($a, $b) {
            return $a['startTs'] <=> $b['startTs'];
        });

        $current_any = null;
        $next_any = null;

        foreach ($events as $event) {
            if (!$current_any && $event['startTs'] <= $now && $event['endTs'] >= $now) {
                $current_any = $event;
            }

            if (!$next_any && $event['startTs'] > $now) {
                $next_any = $event;
            }
        }

        return array(
            'current_any' => $current_any,
            'next_any' => $next_any,
        );
    }

    private function truthy($value) {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return intval($value) === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), array('1','true','yes','y','checked_in','checked-in','checkedin','attended','present','arrived','complete','completed','scanned'), true);
        }

        return false;
    }

    private function format_name($raw_name, $event) {
        $name = trim(preg_replace('/\s+/', ' ', (string)$raw_name));

        if ($name === '') {
            return 'Registered Participant';
        }

        $parts = explode(' ', $name);
        $first = $parts[0] ?? '';
        $last = count($parts) > 1 ? $parts[count($parts) - 1] : '';
        $category = $this->event_category($event);

        if ($category === 'freestyle') {
            return $last !== '' ? $first . ' ' . strtoupper(substr($last, 0, 1)) . '.' : $first;
        }

        if ($category === 'stick_puck' || $category === 'phci') {
            return ($last !== '' && $first !== '') ? $last . ', ' . strtoupper(substr($first, 0, 1)) . '.' : $name;
        }

        return $name;
    }

    private function fetch_people($event) {
        $o = $this->opts();

        $registrants_endpoint = str_replace('{event_id}', rawurlencode($event['id']), $o['registrants_endpoint']);
        $registrants_payload = IFRD_Dash_Connector::get(
            $registrants_endpoint,
            array('page[size]' => 500),
            array('cache' => false)
        );

        if (is_wp_error($registrants_payload)) {
            return $registrants_payload;
        }

        $registrations_payload = IFRD_Dash_Connector::get(
            'https://api.dashplatform.com/v1/events/' . rawurlencode($event['id']) . '/registrations',
            array('page[size]' => 500),
            array('cache' => false)
        );
        $checked_by_customer = array();

        if (!is_wp_error($registrations_payload)) {
            foreach (($registrations_payload['data'] ?? array()) as $registration) {
                $a = isset($registration['attributes']) ? $registration['attributes'] : array();
                $customer_id = isset($a['customer_id']) ? (string)$a['customer_id'] : '';

                if ($customer_id === '') {
                    continue;
                }

                $checked = false;

                if (array_key_exists('checked_in', $a) && $a['checked_in'] !== null) {
                    $checked = $this->truthy($a['checked_in']);
                }

                if (!$checked && !empty($registration['id'])) {
                    $rel_payload = IFRD_Dash_Connector::get(
                        'https://api.dashplatform.com/v1/event-registrations/' . rawurlencode($registration['id']) . '/relationships/checkInEvents',
                        array('page[size]' => 50),
                        array('cache' => false)
                    );

                    if (!is_wp_error($rel_payload) && !empty($rel_payload['data']) && is_array($rel_payload['data'])) {
                        $checked = count($rel_payload['data']) > 0;
                    }
                }

                if ($checked) {
                    $checked_by_customer[$customer_id] = true;
                }
            }
        }

        $people = array();

        foreach (($registrants_payload['data'] ?? array()) as $raw) {
            $a = isset($raw['attributes']) ? $raw['attributes'] : array();
            $customer_id = (string)($raw['id'] ?? $a['customer_id'] ?? $a['id'] ?? '');

            $name = trim((string)($a['name'] ?? $a['full_name'] ?? $a['participant_name'] ?? $a['customer_name'] ?? ''));

            if ($name === '') {
                $name = trim((string)($a['first_name'] ?? $a['firstname'] ?? $a['customer_first_name'] ?? '') . ' ' . (string)($a['last_name'] ?? $a['lastname'] ?? $a['customer_last_name'] ?? ''));
            }

            if ($name === '') {
                $name = 'Registered Participant';
            }

            $people[] = array(
                'name' => $this->format_name($name, $event),
                'checked' => ($customer_id !== '' && !empty($checked_by_customer[$customer_id])),
            );
        }

        usort($people, function($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        return $people;
    }

    private function section($event) {
        if (!$event) {
            return null;
        }

        if (!$event['qualifies']) {
            return array(
                'fallbackEvent' => array(
                    'title' => $event['title'],
                    'startLabel' => $event['startLabel'],
                    'endLabel' => $event['endLabel'],
                ),
            );
        }

        $people = $this->fetch_people($event);
        $error = null;

        if (is_wp_error($people)) {
            $error = $people->get_error_message();
            $people = array();
        }

        return array(
            'event' => $event,
            'participants' => $people,
            'registrantError' => $error,
        );
    }

    public function ajax() {
        $o = $this->opts();
        $rink = sanitize_text_field($_POST['rink'] ?? 'gold');
        $resource = ($rink === 'silver') ? intval($o['silver_resource_id']) : intval($o['gold_resource_id']);
        $debug = array();

        $events = $this->find_now_next($resource, $debug);

        if (is_wp_error($events)) {
            wp_send_json_error(array('message' => $events->get_error_message(), 'debug' => $debug), 500);
        }

        $now = $this->section($events['current_any']);
        $next = $this->section($events['next_any']);

        if (!$now && !$next) {
            wp_send_json_success(array(
                'mode' => 'video',
                'reason' => 'No current or upcoming event found.',
                'video' => $o['video_url'],
            ));
        }

        wp_send_json_success(array(
            'mode' => 'participants',
            'now' => $now,
            'next' => $next,
        ));
    }

    public function shortcode($atts) {
        $atts = shortcode_atts(array('rink' => 'gold'), $atts);
        $rink = strtolower($atts['rink']) === 'silver' ? 'silver' : 'gold';
        $o = $this->opts();
        $id = 'ifrd_part_' . wp_generate_password(8, false);

        $style = sprintf(
            '--ifrp-bg:%s;--ifrp-panel:%s;--ifrp-text:%s;--ifrp-muted:%s;--ifrp-accent:%s;--ifrp-checked:%s;--ifrp-now:%s;--ifrp-next:%s;',
            esc_attr($o['bg_color']),
            esc_attr($o['panel_color']),
            esc_attr($o['text_color']),
            esc_attr($o['muted_color']),
            esc_attr($o['accent_color']),
            esc_attr($o['checked_color']),
            esc_attr($o['now_color']),
            esc_attr($o['next_color'])
        );

        ob_start();
        ?>
        <div id="<?php echo esc_attr($id); ?>" class="ifrd-part-app" style="<?php echo esc_attr($style); ?>">
            <div class="ifrd-part-loading">Loading...</div>
        </div>
        <style>
        .ifrd-part-app{background:var(--ifrp-bg);color:var(--ifrp-text);font-family:system-ui,sans-serif;min-height:100vh;height:100vh;padding:18px;box-sizing:border-box;display:grid;grid-template-rows:auto 1fr auto;gap:14px;overflow:hidden}
        .ifrd-part-top{display:flex;align-items:center;justify-content:space-between;gap:24px}.ifrd-part-brand{display:flex;align-items:center;gap:14px;min-width:0;flex:1}.ifrd-part-logo{max-height:72px;max-width:180px;object-fit:contain}
        .ifrd-part-title-main{font-size:clamp(30px,3.2vw,62px);font-weight:850;line-height:1;margin:0}.ifrd-part-sub-main{font-size:clamp(16px,1.45vw,28px);color:var(--ifrp-muted);margin-top:6px;font-weight:700}
        .ifrd-part-clock{text-align:right;color:var(--ifrp-muted);font-size:clamp(14px,1.2vw,22px);min-width:260px}.ifrd-part-clock strong{display:block;color:var(--ifrp-text);font-size:clamp(26px,2.4vw,48px);line-height:1;white-space:nowrap}.ifrd-part-clock span{white-space:nowrap}
        .ifrd-part-sections{display:grid;grid-template-columns:1fr 1fr;gap:14px;min-height:0}.ifrd-part-section{background:var(--ifrp-panel);border-radius:22px;padding:16px;overflow:hidden;border:1px solid rgba(255,255,255,.1);min-width:0}
        .ifrd-part-section-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:10px}.ifrd-part-label{font-size:clamp(18px,1.7vw,32px);font-weight:900;line-height:1}.ifrd-part-label.now{color:var(--ifrp-now)}.ifrd-part-label.next{color:var(--ifrp-next)}
        .ifrd-part-event-title{font-size:clamp(18px,1.55vw,30px);font-weight:850;line-height:1.1}.ifrd-part-event-time{color:var(--ifrp-muted);font-size:clamp(12px,1vw,18px);margin-top:4px}.ifrd-part-count{color:var(--ifrp-muted);font-size:clamp(12px,1vw,18px);font-weight:700;white-space:nowrap}
..ifrd-part-list{height:calc(100vh - 238px);columns:2;column-gap:20px;overflow:hidden}.ifrd-part-person{display:inline-flex;justify-content:space-between;align-items:center;width:100%;box-sizing:border-box;break-inside:avoid;page-break-inside:avoid;-webkit-column-break-inside:avoid;padding:2px 0;border-bottom:1px solid rgba(255,255,255,.07);font-size:clamp(12px,1.55vw,24px);font-weight:700;line-height:1.0}ifrd-part-list{height:calc(100vh - 238px);display:grid;grid-template-rows:repeat(9,1fr);grid-auto-flow:column;grid-auto-columns:1fr;column-gap:20px;overflow:hidden}.ifrd-part-person{display:flex;justify-content:space-between;align-items:center;width:100%;box-sizing:border-box;padding:2px 0;border-bottom:1px solid rgba(255,255,255,.07);font-size:clamp(12px,1.55vw,24px);font-weight:700;line-height:1.0}
        .ifrd-part-check{color:var(--ifrp-checked);font-weight:900}.ifrd-part-empty,.ifrd-part-loading,.ifrd-part-error{font-size:clamp(24px,2.6vw,48px);font-weight:800;color:var(--ifrp-muted);display:flex;align-items:center;justify-content:center;height:100%;text-align:center}.ifrd-part-error{color:#ffb3b3}
        .ifrd-part-video video{width:100%;max-height:82vh;object-fit:contain}.ifrd-part-footer{display:flex;align-items:center;justify-content:space-between;gap:12px;color:var(--ifrp-muted);font-size:clamp(12px,1vw,18px);min-height:0}.ifrd-part-banner{max-height:140px;max-width:100%;object-fit:contain;border-radius:10px}
        @media(max-width:900px){.ifrd-part-sections{grid-template-columns:1fr}.ifrd-part-app{height:auto;min-height:auto;overflow:auto}.ifrd-part-list{max-height:none}}
        </style>
        <script>
        (async function(){
            const root=document.getElementById('<?php echo esc_js($id); ?>');
            const settings={title:<?php echo json_encode($o['display_title']); ?>,subtitle:<?php echo json_encode($o['subtitle_text']); ?>,logo:<?php echo json_encode($o['logo_url']); ?>,banner:<?php echo json_encode($o['banner_url']); ?>,bannerType:<?php echo json_encode(IFRD_Banner_Media::is_video($o['banner_url'], $o['banner_media_type'] ?? 'auto') ? 'video' : 'image'); ?>,bannerLink:<?php echo json_encode($o['banner_link']); ?>};
            function esc(v){return String(v??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));}
            function nowTime(){return new Date().toLocaleTimeString([],{hour:'numeric',minute:'2-digit'});}
            function regText(n){return n===1?'1 Skater Registered':n+' Skaters Registered';}
            function brand(){const rinkName='<?php echo esc_js(ucfirst($rink)); ?> Rink'; const subtitle=settings.subtitle||settings.title||'Registered Participants'; const today=new Date().toLocaleDateString([],{weekday:'long',month:'long',day:'numeric'}); return '<div class="ifrd-part-top"><div class="ifrd-part-brand">'+(settings.logo?'<img class="ifrd-part-logo" src="'+esc(settings.logo)+'" alt="Logo">':'')+'<div><h1 class="ifrd-part-title-main">'+esc(rinkName)+'</h1>'+(subtitle?'<div class="ifrd-part-sub-main">'+esc(subtitle)+'</div>':'')+'</div></div><div class="ifrd-part-clock"><strong>'+esc(nowTime())+'</strong><span>'+esc(today)+'</span></div></div>';}
            function footer(){let banner='<span></span>';if(settings.banner){let media=settings.bannerType==='video'?'<video class="ifrd-part-banner" autoplay muted loop playsinline preload="metadata"><source src="'+esc(settings.banner)+'"></video>':'<img class="ifrd-part-banner" src="'+esc(settings.banner)+'" alt="">';banner=settings.bannerLink?'<a href="'+esc(settings.bannerLink)+'">'+media+'</a>':media;}return '<div class="ifrd-part-footer"><span>Last updated: '+esc(new Date().toLocaleTimeString([],{hour:'numeric',minute:'2-digit',second:'2-digit'}))+'</span>'+banner+'</div>';}
            function section(label,cls,sec){if(!sec||!sec.event){if(sec&&sec.fallbackEvent){return '<section class="ifrd-part-section"><div class="ifrd-part-label '+cls+'">'+esc(label)+':</div><div class="ifrd-part-empty" style="flex-direction:column;gap:10px;"><div style="font-size:clamp(22px,2vw,40px);color:var(--ifrp-text);font-weight:800;">'+esc(sec.fallbackEvent.title)+'</div><div style="font-size:clamp(14px,1.1vw,22px);color:var(--ifrp-muted);">'+esc(sec.fallbackEvent.startLabel)+'–'+esc(sec.fallbackEvent.endLabel)+'</div></div></section>';}return '<section class="ifrd-part-section"><div class="ifrd-part-label '+cls+'">'+esc(label)+':</div><div class="ifrd-part-empty">No event scheduled.</div></section>';} const p=sec.participants||[]; const err=sec.registrantError?'<div class="ifrd-part-error">Registrant issue: '+esc(sec.registrantError)+'</div>':'';return '<section class="ifrd-part-section"><div class="ifrd-part-section-head"><div><div class="ifrd-part-label '+cls+'">'+esc(label)+':</div><div class="ifrd-part-event-title">'+esc(sec.event.title)+'</div><div class="ifrd-part-event-time">'+esc(sec.event.startLabel)+'–'+esc(sec.event.endLabel)+'</div></div><div class="ifrd-part-count">'+esc(regText(p.length))+'</div></div>'+err+'<div class="ifrd-part-list">'+p.map(x=>'<div class="ifrd-part-person"><span>'+esc(x.name)+'</span>'+(x.checked?'<span class="ifrd-part-check">✓</span>':'<span></span>')+'</div>').join('')+'</div></section>';}
            try{let f=new FormData();f.append('action','ifrd_participants_data');f.append('rink','<?php echo esc_js($rink); ?>');let res=await fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>',{method:'POST',body:f});let payload=await res.json();if(!payload.success)throw new Error(payload.data?.message||payload.data||'Error');let data=payload.data;if(data.mode==='video'){let vid=data.video?'<div class="ifrd-part-video"><video autoplay muted loop playsinline><source src="'+esc(data.video)+'"></video></div>':'<div class="ifrd-part-empty">'+esc(data.reason||'No qualifying event')+'</div>';root.innerHTML=brand()+vid+footer();return;}root.innerHTML=brand()+'<div class="ifrd-part-sections">'+section('Now','now',data.now)+section('Up Next','next',data.next)+'</div>'+footer();}catch(e){root.innerHTML='<div class="ifrd-part-error">'+esc(e.message)+'</div>';}
        })();
        </script>
        <?php
        return ob_get_clean();
    }
}

