<?php
/*
Plugin Name: Ice & Field Rink Displays
Description: Combined Dash/DaySmart schedule display and rink participants/check-in display for Ice & Field.
Version: 2.7.7
Author: Ice & Field
Requires Plugins: ice-field-dash-connector
Update URI: https://github.com/sarahspins/wordpress-plugins/tree/main/ice-field-rink-displays
*/

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Thin integration layer for the separately installed Ice & Field Dash Connector.
 * Authentication, tenant selection, request handling, and shared API caching all
 * live in that connector plugin rather than this display plugin.
 */
class IFRD_Dash_Connector {
    const MIN_VERSION = '1.3.0';

    public static function is_available() {
        if (!class_exists('IFDC_Client')) {
            return false;
        }

        return !defined('IFDC_VERSION') || version_compare(IFDC_VERSION, self::MIN_VERSION, '>=');
    }

    public static function is_ready() {
        return self::is_available() && IFDC_Client::is_configured();
    }

    public static function company() {
        return self::is_available() ? IFDC_Client::company() : '';
    }

    public static function get($path_or_url, $query = array(), $args = array()) {
        if (!self::is_available()) {
            return new WP_Error(
                'ifrd_connector_missing',
                'Ice & Field Dash Connector v' . self::MIN_VERSION . ' or newer is required.'
            );
        }

        if (!IFDC_Client::is_configured()) {
            return new WP_Error(
                'ifrd_connector_not_ready',
                'The Ice & Field Dash Connector is installed but is not configured.'
            );
        }

        if (!isset($query['company']) && IFDC_Client::company()) {
            $query['company'] = IFDC_Client::company();
        }

        return IFDC_Client::get_data($path_or_url, $query, $args);
    }

    public static function render_settings_status() {
        if (!self::is_available()) {
            echo '<p><strong>Status:</strong> Connector not detected. Install and activate Ice &amp; Field Dash Connector v' . esc_html(self::MIN_VERSION) . ' or newer.</p>';
            return;
        }

        if (!IFDC_Client::is_configured()) {
            echo '<p><strong>Status:</strong> Connector installed; setup required. <a class="button button-primary" href="' .
                esc_url(admin_url('admin.php?page=ifdc-settings')) . '">Configure Dash Connector</a></p>';
            return;
        }

        echo '<p><strong>Status:</strong> <span style="color:#18713c">Connected and ready</span></p>';
        echo '<p><strong>Company:</strong> ' . esc_html(IFDC_Client::company()) . ' &nbsp; <a class="button" href="' .
            esc_url(admin_url('admin.php?page=ifdc-dashboard')) . '">Open Dash Connector</a></p>';
    }
}

/**
 * Shared Media Library helpers for the two display settings pages.
 */
class IFRD_Banner_Media {
    public static function is_video($url, $type = 'auto') {
        $type = sanitize_key((string) $type);

        if ($type === 'video') {
            return true;
        }

        if ($type === 'image') {
            return false;
        }

        $path = wp_parse_url((string) $url, PHP_URL_PATH);
        $filetype = wp_check_filetype((string) $path);

        if (!empty($filetype['type'])) {
            return strpos((string) $filetype['type'], 'video/') === 0;
        }

        $extension = strtolower((string) pathinfo((string) $path, PATHINFO_EXTENSION));
        return in_array($extension, array('mp4', 'm4v', 'webm', 'ogv', 'ogg', 'mov'), true);
    }

    public static function render_picker($option_name, $settings, $field_name, $field_id, $allowed_types = array('image'), $args = array()) {
        $allowed_types = array_values(array_intersect(array('image', 'video'), array_map('sanitize_key', (array) $allowed_types)));

        if (empty($allowed_types)) {
            $allowed_types = array('image');
        }

        $url = isset($settings[$field_name]) ? (string) $settings[$field_name] : '';
        $type_field = !empty($args['type_field']) ? sanitize_key($args['type_field']) : '';
        $type = $type_field && isset($settings[$type_field]) ? sanitize_key($settings[$type_field]) : 'auto';
        $title = !empty($args['title']) ? (string) $args['title'] : 'Choose media';
        $button_text = !empty($args['button_text']) ? (string) $args['button_text'] : 'Use this media';
        $placeholder = !empty($args['placeholder']) ? (string) $args['placeholder'] : 'Select media or paste its URL';
        $description = !empty($args['description']) ? (string) $args['description'] : '';

        if (!in_array($type, array('auto', 'image', 'video'), true)) {
            $type = 'auto';
        }
        ?>
        <div
            class="ifrd-media-picker"
            data-media-types="<?php echo esc_attr(implode(',', $allowed_types)); ?>"
            data-media-title="<?php echo esc_attr($title); ?>"
            data-media-button="<?php echo esc_attr($button_text); ?>"
        >
            <input
                id="<?php echo esc_attr($field_id); ?>"
                class="large-text ifrd-media-url"
                name="<?php echo esc_attr($option_name); ?>[<?php echo esc_attr($field_name); ?>]"
                value="<?php echo esc_attr($url); ?>"
                placeholder="<?php echo esc_attr($placeholder); ?>"
            >
            <p>
                <button type="button" class="button ifrd-media-select">Choose from Media Library</button>
                <button type="button" class="button ifrd-media-clear">Clear</button>
            </p>
            <?php if ($type_field): ?>
                <label>
                    Media type:
                    <select class="ifrd-media-type" name="<?php echo esc_attr($option_name); ?>[<?php echo esc_attr($type_field); ?>]">
                        <option value="auto" <?php selected($type, 'auto'); ?>>Detect automatically</option>
                        <option value="image" <?php selected($type, 'image'); ?>>Image</option>
                        <option value="video" <?php selected($type, 'video'); ?>>Video</option>
                    </select>
                </label>
            <?php endif; ?>
            <?php if ($description !== ''): ?><p class="description"><?php echo esc_html($description); ?></p><?php endif; ?>
        </div>
        <?php
    }

    public static function print_picker_script() {
        ?>
        <script>
        (function(){
            document.addEventListener('click',function(event){
                const selectButton=event.target.closest('.ifrd-media-select');
                const clearButton=event.target.closest('.ifrd-media-clear');

                if(!selectButton&&!clearButton)return;

                event.preventDefault();
                const picker=(selectButton||clearButton).closest('.ifrd-media-picker');
                const urlField=picker.querySelector('.ifrd-media-url');
                const typeField=picker.querySelector('.ifrd-media-type');

                if(clearButton){
                    urlField.value='';
                    if(typeField)typeField.value='auto';
                    return;
                }

                if(!window.wp||!wp.media)return;

                const allowedTypes=(picker.dataset.mediaTypes||'image').split(',').filter(Boolean);
                const libraryType=allowedTypes.length===1?allowedTypes[0]:allowedTypes;

                const frame=wp.media({
                    title:picker.dataset.mediaTitle||'Choose media',
                    button:{text:picker.dataset.mediaButton||'Use this media'},
                    library:{type:libraryType},
                    multiple:false
                });

                frame.on('select',function(){
                    const attachment=frame.state().get('selection').first().toJSON();
                    urlField.value=attachment.url||'';
                    if(typeField)typeField.value=attachment.type==='video'?'video':'image';
                });

                frame.open();
            });
        })();
        </script>
        <?php
    }
}

/**
 * Full-screen looping video display for lobby and rink screens.
 */
class IFRD_Video_For_Screens {
    const OPTION = 'ifrd_video_screen_settings';
    const REFRESH_OPTION = 'ifrd_video_screen_refresh_version';
    const PLUGIN_VERSION_OPTION = 'ifrd_plugin_version';
    const PLUGIN_VERSION = '2.7.6';
    const AJAX_ACTION = 'ifrd_video_screen_refresh_status';
    const CAPABILITY = 'edit_pages';

    public function __construct() {
        add_action('admin_menu', array($this, 'menu'), 30);
        add_action('admin_init', array($this, 'settings'));
        add_filter('option_page_capability_ifrd_video_screen_group', array($this, 'settings_capability'));
        add_action('admin_enqueue_scripts', array($this, 'admin_assets'));
        add_action('admin_post_ifrd_refresh_video_screens', array($this, 'refresh_screens'));
        add_action('init', array($this, 'maybe_refresh_after_update'), 1);
        add_action('wp_ajax_' . self::AJAX_ACTION, array($this, 'refresh_status'));
        add_action('wp_ajax_nopriv_' . self::AJAX_ACTION, array($this, 'refresh_status'));
        add_shortcode('video_for_screens', array($this, 'shortcode'));
    }

    public function defaults() {
        return array(
            'video_url' => '',
        );
    }

    public function opts() {
        return wp_parse_args(get_option(self::OPTION, array()), $this->defaults());
    }

    public function menu() {
        add_submenu_page(
            'ifrd-schedule-display',
            'Video for Screens',
            'Video for Screens',
            self::CAPABILITY,
            'ifrd-video-for-screens',
            array($this, 'page')
        );
    }

    public function settings() {
        register_setting('ifrd_video_screen_group', self::OPTION, array($this, 'sanitize'));
    }

    public function settings_capability() {
        return self::CAPABILITY;
    }

    public function admin_assets($hook) {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page === 'ifrd-video-for-screens') {
            wp_enqueue_media();
        }
    }

    public static function current_refresh_version() {
        $version = (string) get_option(self::REFRESH_OPTION, '');
        if ($version === '') {
            $version = (string) time();
            update_option(self::REFRESH_OPTION, $version, false);
        }
        return $version;
    }

    public static function bump_refresh_version() {
        $version = (string) round(microtime(true) * 1000);
        update_option(self::REFRESH_OPTION, $version, false);
        return $version;
    }

    public function maybe_refresh_after_update() {
        if ((string) get_option(self::PLUGIN_VERSION_OPTION, '') === self::PLUGIN_VERSION) {
            return;
        }

        update_option(self::PLUGIN_VERSION_OPTION, self::PLUGIN_VERSION, false);
        $version = self::bump_refresh_version();
        update_option('ifrd_pricing_screen_refresh_version', $version, false);
        update_option('ifrd_public_skating_rules_screen_refresh_version', $version, false);
    }

    public function sanitize($input) {
        $video_url = isset($input['video_url']) ? esc_url_raw($input['video_url']) : '';
        $old = $this->opts();

        if ($video_url !== (string) ($old['video_url'] ?? '')) {
            self::bump_refresh_version();
        }

        return array('video_url' => $video_url);
    }

    public function refresh_screens() {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to refresh these screens.', 'ice-field-rink-displays'));
        }

        check_admin_referer('ifrd_refresh_video_screens');
        self::bump_refresh_version();

        wp_safe_redirect(add_query_arg(
            array('page' => 'ifrd-video-for-screens', 'screens-refreshed' => '1'),
            admin_url('admin.php')
        ));
        exit;
    }

    public function refresh_status() {
        nocache_headers();
        wp_send_json_success(array(
            'version' => self::current_refresh_version(),
        ));
    }

    public function page() {
        $o = $this->opts();
        ?>
        <div class="wrap">
            <h1>Video for Screens</h1>
            <?php if (!empty($_GET['screens-refreshed'])): ?>
                <div class="notice notice-success is-dismissible"><p>Refresh requested. Open video screens should reload within about 30 seconds.</p></div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields('ifrd_video_screen_group'); ?>
                <table class="form-table">
                    <tr>
                        <th>Full-Screen Video</th>
                        <td><?php IFRD_Banner_Media::render_picker(self::OPTION, $o, 'video_url', 'ifrd-video-screen-file', array('video'), array(
                            'title' => 'Choose a screen video',
                            'button_text' => 'Use this video',
                            'placeholder' => 'Select a video or paste its URL',
                            'description' => 'The video fills the browser, starts muted, and repeats continuously. Saving a different video also asks open screens to refresh.',
                        )); ?></td>
                    </tr>
                </table>
                <?php submit_button('Save Video'); ?>
            </form>

            <hr>
            <h2>Remote Refresh</h2>
            <p>Use this after updating the page, changing display settings, or whenever an open screen needs to reload.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="ifrd_refresh_video_screens">
                <?php wp_nonce_field('ifrd_refresh_video_screens'); ?>
                <?php submit_button('Refresh Screens Now', 'secondary', 'submit', false); ?>
            </form>

            <p><strong>Shortcode:</strong> <code>[video_for_screens]</code></p>
        </div>
        <?php IFRD_Banner_Media::print_picker_script(); ?>
        <?php
    }

    public function shortcode($atts) {
        $o = $this->opts();
        $video_url = (string) ($o['video_url'] ?? '');
        $refresh_version = self::current_refresh_version();

        return self::render_player($video_url, $refresh_version, self::AJAX_ACTION, 'ifrd-screen-video-');
    }

    public static function render_player($video_url, $refresh_version, $ajax_action, $id_prefix = 'ifrd-screen-video-') {
        $id = sanitize_html_class($id_prefix) . wp_generate_password(8, false, false);

        ob_start();
        ?>
        <div id="<?php echo esc_attr($id); ?>" class="ifrd-screen-video">
            <?php if ($video_url !== ''): ?>
                <video autoplay muted loop playsinline preload="auto">
                    <source src="<?php echo esc_url($video_url); ?>">
                </video>
            <?php else: ?>
                <div class="ifrd-screen-video-empty">No screen video has been selected.</div>
            <?php endif; ?>
        </div>
        <style>
            html.ifrd-screen-video-active,body.ifrd-screen-video-active{margin:0!important;padding:0!important;overflow:hidden!important;background:#000!important}
            #<?php echo esc_attr($id); ?>{position:fixed;inset:0;z-index:2147483000;display:grid;width:100vw;height:100vh;place-items:center;overflow:hidden;background:#000}
            #<?php echo esc_attr($id); ?> video{display:block;width:100%;height:100%;object-fit:cover;background:#000}
            html.ifrd-screen-video-webos #<?php echo esc_attr($id); ?>{inset:auto;top:0;left:0;display:block}
            html.ifrd-screen-video-webos #<?php echo esc_attr($id); ?> video{position:absolute;top:0;left:0;max-width:none;max-height:none;object-fit:fill}
            #<?php echo esc_attr($id); ?> .ifrd-screen-video-empty{padding:32px;color:#fff;font:700 clamp(20px,3vw,42px)/1.2 system-ui,sans-serif;text-align:center}
        </style>
        <script>
        (function(){
            document.documentElement.classList.add('ifrd-screen-video-active');
            if(document.body)document.body.classList.add('ifrd-screen-video-active');
            const isWebOs=/Web0S|webOS|NetCast/i.test(navigator.userAgent||'');
            if(isWebOs){
                document.documentElement.classList.add('ifrd-screen-video-webos');
                if(document.body)document.body.classList.add('ifrd-screen-video-webos');
            }
            const embeddedVersion=<?php echo wp_json_encode($refresh_version); ?>;
            const initialUrl=new URL(window.location.href);
            let currentVersion=initialUrl.searchParams.get('screen_refresh')||embeddedVersion;

            async function checkForRefresh(){
                try{
                    const body=new URLSearchParams();
                    body.set('action',<?php echo wp_json_encode($ajax_action); ?>);
                    const response=await fetch(<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>,{
                        method:'POST',
                        headers:{'Accept':'application/json','Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
                        body:body.toString(),
                        credentials:'same-origin',
                        cache:'no-store'
                    });
                    const payload=await response.json();
                    const latest=String(payload&&payload.success&&payload.data&&payload.data.version||'');
                    if(!latest||latest===currentVersion)return;

                    currentVersion=latest;
                    const target=new URL(window.location.href);
                    target.searchParams.set('screen_refresh',latest);
                    window.location.replace(target.toString());
                }catch(ignore){}
            }

            window.setInterval(checkForRefresh,30000);
            window.setTimeout(checkForRefresh,5000);
            document.addEventListener('visibilitychange',function(){if(!document.hidden)checkForRefresh();});

            const video=document.querySelector('#<?php echo esc_js($id); ?> video');
            if(video){
                if(isWebOs){
                    const sizeWebOsVideo=function(){
                        const width=Math.max(document.documentElement.clientWidth||0,window.innerWidth||0);
                        const height=Math.max(document.documentElement.clientHeight||0,window.innerHeight||0);
                        const container=document.getElementById(<?php echo wp_json_encode($id); ?>);
                        if(container){container.style.width=width+'px';container.style.height=height+'px';}
                        video.style.width=width+'px';
                        video.style.height=height+'px';
                        video.setAttribute('width',String(width));
                        video.setAttribute('height',String(height));
                    };
                    sizeWebOsVideo();
                    window.addEventListener('resize',sizeWebOsVideo);
                    window.addEventListener('orientationchange',sizeWebOsVideo);
                }
                const play=function(){const attempt=video.play();if(attempt&&typeof attempt.catch==='function')attempt.catch(function(){});};
                play();
                document.addEventListener('click',play,{once:true});
                document.addEventListener('touchstart',play,{once:true,passive:true});
            }
        })();
        </script>
        <?php
        return ob_get_clean();
    }
}

/**
 * Independently managed full-screen video pages that reuse the TV player.
 */
class IFRD_Additional_Video_Screen {
    const CAPABILITY = 'edit_pages';

    private $key;
    private $title;
    private $option;
    private $refresh_option;
    private $ajax_action;
    private $page_slug;
    private $shortcode;
    private $settings_group;
    private $refresh_action;

    public function __construct($key, $title, $page_slug, $shortcode) {
        $this->key = sanitize_key($key);
        $this->title = (string) $title;
        $this->option = 'ifrd_' . $this->key . '_screen_settings';
        $this->refresh_option = 'ifrd_' . $this->key . '_screen_refresh_version';
        $this->ajax_action = 'ifrd_' . $this->key . '_screen_refresh_status';
        $this->page_slug = sanitize_key($page_slug);
        $this->shortcode = sanitize_key($shortcode);
        $this->settings_group = 'ifrd_' . $this->key . '_screen_group';
        $this->refresh_action = 'ifrd_refresh_' . $this->key . '_screens';

        add_action('admin_menu', array($this, 'menu'), 30);
        add_action('admin_init', array($this, 'settings'));
        add_filter('option_page_capability_' . $this->settings_group, array($this, 'settings_capability'));
        add_action('admin_enqueue_scripts', array($this, 'admin_assets'));
        add_action('admin_post_' . $this->refresh_action, array($this, 'refresh_screens'));
        add_action('wp_ajax_' . $this->ajax_action, array($this, 'refresh_status'));
        add_action('wp_ajax_nopriv_' . $this->ajax_action, array($this, 'refresh_status'));
        add_shortcode($this->shortcode, array($this, 'shortcode'));
    }

    public function settings_capability() {
        return self::CAPABILITY;
    }

    public function opts() {
        return wp_parse_args(get_option($this->option, array()), array('video_url' => ''));
    }

    public function menu() {
        add_submenu_page(
            'ifrd-schedule-display',
            $this->title,
            $this->title,
            self::CAPABILITY,
            $this->page_slug,
            array($this, 'page')
        );
    }

    public function settings() {
        register_setting($this->settings_group, $this->option, array($this, 'sanitize'));
    }

    public function admin_assets($hook) {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page === $this->page_slug) {
            wp_enqueue_media();
        }
    }

    public function current_refresh_version() {
        $version = (string) get_option($this->refresh_option, '');
        if ($version === '') {
            $version = (string) time();
            update_option($this->refresh_option, $version, false);
        }
        return $version;
    }

    public function bump_refresh_version() {
        $version = (string) round(microtime(true) * 1000);
        update_option($this->refresh_option, $version, false);
        return $version;
    }

    public function sanitize($input) {
        $video_url = isset($input['video_url']) ? esc_url_raw($input['video_url']) : '';
        $old = $this->opts();
        if ($video_url !== (string) ($old['video_url'] ?? '')) {
            $this->bump_refresh_version();
        }
        return array('video_url' => $video_url);
    }

    public function refresh_screens() {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to refresh these screens.', 'ice-field-rink-displays'));
        }

        check_admin_referer($this->refresh_action);
        $this->bump_refresh_version();
        wp_safe_redirect(add_query_arg(
            array('page' => $this->page_slug, 'screens-refreshed' => '1'),
            admin_url('admin.php')
        ));
        exit;
    }

    public function refresh_status() {
        nocache_headers();
        wp_send_json_success(array('version' => $this->current_refresh_version()));
    }

    public function page() {
        $o = $this->opts();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html($this->title); ?></h1>
            <?php if (!empty($_GET['screens-refreshed'])): ?>
                <div class="notice notice-success is-dismissible"><p>Refresh requested. Open screens should reload within about 30 seconds.</p></div>
            <?php endif; ?>
            <form method="post" action="options.php">
                <?php settings_fields($this->settings_group); ?>
                <table class="form-table">
                    <tr>
                        <th>Full-Screen Video</th>
                        <td><?php IFRD_Banner_Media::render_picker($this->option, $o, 'video_url', 'ifrd-' . $this->key . '-screen-file', array('video'), array(
                            'title' => 'Choose a video for ' . $this->title,
                            'button_text' => 'Use this video',
                            'placeholder' => 'Select a video or paste its URL',
                            'description' => 'The video fills the browser, starts muted, and repeats continuously. Saving a different video also asks open screens to refresh.',
                        )); ?></td>
                    </tr>
                </table>
                <?php submit_button('Save Video'); ?>
            </form>
            <hr>
            <h2>Remote Refresh</h2>
            <p>Use this whenever open screens need to reload.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr($this->refresh_action); ?>">
                <?php wp_nonce_field($this->refresh_action); ?>
                <?php submit_button('Refresh Screens Now', 'secondary', 'submit', false); ?>
            </form>
            <p><strong>Shortcode:</strong> <code>[<?php echo esc_html($this->shortcode); ?>]</code></p>
        </div>
        <?php IFRD_Banner_Media::print_picker_script(); ?>
        <?php
    }

    public function shortcode($atts) {
        $o = $this->opts();
        return IFRD_Video_For_Screens::render_player(
            (string) ($o['video_url'] ?? ''),
            $this->current_refresh_version(),
            $this->ajax_action,
            'ifrd-' . $this->key . '-video-'
        );
    }
}

/**
 * Displays menu shortcode reference.
 */
class IFRD_Shortcodes_Page {
    public function __construct() {
        add_action('admin_menu', array($this, 'menu'), 40);
    }

    public function menu() {
        add_submenu_page(
            'ifrd-schedule-display',
            'Display Shortcodes',
            'Shortcodes',
            'manage_options',
            'ifrd-display-shortcodes',
            array($this, 'page')
        );
    }

    private function shortcode_example($shortcode) {
        return '<pre class="ifrd-shortcode-example"><code>' . esc_html($shortcode) . '</code></pre>';
    }

    public function page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap ifrd-shortcodes-page">
            <h1>Display Shortcodes</h1>
            <p class="description">Add these to a WordPress Shortcode block, page, post, or compatible page-builder shortcode element.</p>

            <style>
                .ifrd-shortcodes-page{max-width:1120px}.ifrd-shortcodes-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(330px,1fr));gap:18px;margin-top:20px}.ifrd-shortcodes-card{box-sizing:border-box;margin:0;padding:20px;border:1px solid #dcdcde;border-radius:10px;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.04)}.ifrd-shortcodes-card h2{margin-top:0}.ifrd-shortcode-example{overflow:auto;margin:12px 0;padding:12px 14px;border-radius:6px;background:#f0f0f1;white-space:pre-wrap;word-break:break-word}.ifrd-shortcodes-card table{width:100%;border-collapse:collapse}.ifrd-shortcodes-card th,.ifrd-shortcodes-card td{padding:8px;border-bottom:1px solid #e8e8e8;text-align:left;vertical-align:top}.ifrd-shortcodes-card th{font-weight:700}.ifrd-shortcodes-card ul{margin-bottom:0}.ifrd-shortcodes-wide{grid-column:1/-1}.ifrd-shortcodes-note{margin:18px 0;padding:12px 14px;border-left:4px solid #2271b1;background:#fff}
            </style>

            <div class="ifrd-shortcodes-note">
                <strong>Connection requirement:</strong> Ice &amp; Field Dash Connector v<?php echo esc_html(IFRD_Dash_Connector::MIN_VERSION); ?> or newer must be active and configured. Display colors, rink IDs, media, cache timing, and other global choices come from the Schedule Display or Participants Display settings pages rather than shortcode attributes.
            </div>

            <div class="ifrd-shortcodes-grid">
                <section class="ifrd-shortcodes-card">
                    <h2>TV Schedule Display</h2>
                    <p>The full-screen, two-rink schedule designed for a lobby or TV display.</p>
                    <?php echo $this->shortcode_example('[rink_schedule_display]'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <h3>Attributes</h3>
                    <p>None. Configure its headings, rink IDs, colors, logo, banner, timezone, visible event count, and refresh interval under <a href="<?php echo esc_url(admin_url('admin.php?page=ifrd-schedule-display')); ?>">Displays → Schedule Display</a>.</p>
                    <p><strong>Placement:</strong> A blank or full-width page template normally works best because this display is designed to fill the browser viewport.</p>
                    <p><strong>Remote video update:</strong> Saving a different schedule banner asks open TV schedule pages to reload. The same settings page also includes an <em>Update Video Now</em> button for forcing the reload without changing the selected media. Screens check for the request every 30 seconds.</p>
                </section>

                <section class="ifrd-shortcodes-card">
                    <h2>Participants Display</h2>
                    <p>Shows the current and next event for one rink, including registrants for configured qualifying sessions.</p>
                    <?php echo $this->shortcode_example('[rink_participants_display rink="gold"]'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <?php echo $this->shortcode_example('[rink_participants_display rink="silver"]'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <h3>Attributes</h3>
                    <table>
                        <thead><tr><th>Attribute</th><th>Values</th><th>Default</th></tr></thead>
                        <tbody><tr><td><code>rink</code></td><td><code>gold</code> or <code>silver</code></td><td><code>gold</code></td></tr></tbody>
                    </table>
                    <p>Qualifying keywords, privacy formatting, media, colors, and testing offset are controlled under <a href="<?php echo esc_url(admin_url('admin.php?page=ifrd-participants-display')); ?>">Displays → Participants Display</a>.</p>
                </section>

                <section class="ifrd-shortcodes-card ifrd-shortcodes-wide">
                    <h2>Selectable Schedule Calendar</h2>
                    <p>The public daily/weekly schedule with date, rink, session-type, and Day/Week controls. Both views use time-scaled event blocks, with Gold and Silver aligned to the same clock.</p>
                    <?php echo $this->shortcode_example('[rink_schedule_calendar]'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <?php echo $this->shortcode_example('[rink_schedule_calendar view="day" title="Today’s Schedule" subtitle="Choose a rink or session type."]'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <h3>Attributes</h3>
                    <table>
                        <thead><tr><th>Attribute</th><th>Values</th><th>Default</th><th>Purpose</th></tr></thead>
                        <tbody>
                            <tr><td><code>view</code></td><td><code>week</code> or <code>day</code></td><td>The Calendar Default View setting</td><td>Chooses the initially selected view. Visitors can still switch views.</td></tr>
                            <tr><td><code>title</code></td><td>Any plain text</td><td>The Schedule Display title</td><td>Overrides the heading for this shortcode instance.</td></tr>
                            <tr><td><code>subtitle</code></td><td>Any plain text</td><td>Browse Gold and Silver rink events by day or week.</td><td>Overrides the explanatory text beneath the heading.</td></tr>
                        </tbody>
                    </table>
                    <h3>Compatibility alias</h3>
                    <p><code>[rink_schedule_list]</code> is an alias for the same selectable calendar and accepts the same <code>view</code>, <code>title</code>, and <code>subtitle</code> attributes.</p>
                    <?php echo $this->shortcode_example('[rink_schedule_list view="week"]'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <p><strong>Session Type filter:</strong> The selector is generated automatically from categories found in the selected week, including Freestyle, Stick &amp; Puck, Public Skating, Private Hockey / Coaches Ice, Hockey, Learn to Skate, Camps, Specialty Classes, and Other. It can be hidden under <a href="<?php echo esc_url(admin_url('admin.php?page=ifrd-schedule-display')); ?>">Displays → Schedule Display</a>.</p>
                    <p><strong>Event colors:</strong> Adjust Calendar Event Color Strength under <a href="<?php echo esc_url(admin_url('admin.php?page=ifrd-schedule-display')); ?>">Displays → Schedule Display</a>. The default 50% setting mutes both Dash-provided colors and fallback category colors.</p>
                    <p><strong>Registration:</strong> Enable or disable calendar registration links under <a href="<?php echo esc_url(admin_url('admin.php?page=ifrd-schedule-display')); ?>">Displays → Schedule Display</a>. When enabled, an event becomes clickable when Dash supplies or resolves a public registration destination and positively reports registration open. Closed, expired, not-yet-open, and full sessions are not linked. If a combined block has multiple destinations, clicking it opens a choice list.</p>
                    <p><strong>Server cache:</strong> The current and next weeks are refreshed at local midnight and noon. The current week is embedded with the page so a warmed schedule can appear immediately, while stale data remains visible during background updates.</p>
                </section>

                <section class="ifrd-shortcodes-card">
                    <h2>Video for Screens</h2>
                    <p>Displays the selected video full-screen, muted, and on a continuous loop.</p>
                    <?php echo $this->shortcode_example('[video_for_screens]'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <h3>Attributes</h3>
                    <p>None. Choose the video under <a href="<?php echo esc_url(admin_url('admin.php?page=ifrd-video-for-screens')); ?>">Displays → Video for Screens</a>.</p>
                    <p><strong>Remote refresh:</strong> The same page includes a Refresh Screens Now button. Open screens check for that request every 30 seconds and reload automatically.</p>
                </section>

                <section class="ifrd-shortcodes-card">
                    <h2>Pricing Page</h2>
                    <p>Displays the selected pricing video full-screen, muted, and on a continuous loop.</p>
                    <?php echo $this->shortcode_example('[pricing_page]'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <p>Choose and remotely refresh its video under <a href="<?php echo esc_url(admin_url('admin.php?page=ifrd-pricing-page')); ?>">Displays → Pricing Page</a>.</p>
                </section>

                <section class="ifrd-shortcodes-card">
                    <h2>Public Skating Rules Page</h2>
                    <p>Displays the selected public skating rules video full-screen, muted, and on a continuous loop.</p>
                    <?php echo $this->shortcode_example('[public_skating_rules_page]'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <p>Choose and remotely refresh its video under <a href="<?php echo esc_url(admin_url('admin.php?page=ifrd-public-skating-rules-page')); ?>">Displays → Public Skating Rules Page</a>.</p>
                </section>

                <section class="ifrd-shortcodes-card ifrd-shortcodes-wide">
                    <h2>Usage notes</h2>
                    <ul>
                        <li>Use straight quotation marks around attribute values, as shown above.</li>
                        <li>The calendar opens on today’s date. Its on-page controls determine the selected date, rink, session type, and view after loading.</li>
                        <li>The calendar’s Day and Week views use the same cached weekly data. Current and upcoming weeks are warmed at local midnight and noon.</li>
                        <li>The TV schedule and participants display refresh automatically according to their settings.</li>
                        <li>If a display reports a connection problem, verify the shared Dash Connector first.</li>
                    </ul>
                    <div><?php IFRD_Dash_Connector::render_settings_status(); ?></div>
                </section>
            </div>
        </div>
        <?php
    }
}

/**
 * Schedule Display
 */
class IFRD_Schedule_Display {
    const OPTION = 'ifrd_schedule_settings';
    const CACHE = 'ifrd_schedule_payload_v276_full_sessions';
    const CAPABILITY = 'edit_pages';

    public function __construct() {
        add_action('admin_menu', array($this, 'menu'), 20);
        add_action('admin_init', array($this, 'settings'));
        add_filter('option_page_capability_ifrd_schedule_group', array($this, 'settings_capability'));
        add_action('admin_enqueue_scripts', array($this, 'admin_assets'));
        add_action('admin_post_ifrd_refresh_schedule_screens', array($this, 'refresh_screens'));
        add_shortcode('rink_schedule_display', array($this, 'shortcode'));
        add_action('wp_ajax_ifrd_schedule_data', array($this, 'ajax'));
        add_action('wp_ajax_nopriv_ifrd_schedule_data', array($this, 'ajax'));
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
            'display_title' => 'Ice & Field Schedule',
            'display_subtitle' => 'Today’s rink schedule',
            'gold_title' => 'Gold Rink',
            'silver_title' => 'Silver Rink',
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
            'now_color' => '#4fd18b',
            'later_color' => '#FFc600',
            'next_color' => '#33B6FF',
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
            $clean['banner_url'] = isset($input['banner_url']) ? esc_url_raw($input['banner_url']) : (string) $old['banner_url'];
            $banner_media_type = isset($input['banner_media_type']) ? sanitize_key($input['banner_media_type']) : (string) $old['banner_media_type'];
            $clean['banner_media_type'] = in_array($banner_media_type, array('auto', 'image', 'video'), true) ? $banner_media_type : 'auto';
        } else {

            foreach ($defaults as $key => $default) {
                if (in_array($key, array('calendar_registration_links', 'calendar_session_selector'), true)) {
                    $clean[$key] = isset($input[$key]) ? '1' : '0';
                    continue;
                }

                $value = isset($input[$key]) ? $input[$key] : $default;

                if ($key === 'calendar_color_strength') {
                    $clean[$key] = (string) min(100, max(0, intval($value)));
                } elseif (in_array($key, array('gold_resource_id', 'silver_resource_id', 'refresh_seconds', 'max_visible', 'calendar_cache_seconds'), true)) {
                    $clean[$key] = (string) max(1, intval($value));
                } elseif ($key === 'calendar_default_view') {
                    $clean[$key] = in_array($value, array('day', 'week'), true) ? $value : 'week';
                } elseif ($key === 'locker_name_map') {
                    $clean[$key] = sanitize_textarea_field($value);
                } elseif ($key === 'banner_media_type') {
                    $clean[$key] = in_array($value, array('auto', 'image', 'video'), true) ? $value : 'auto';
                } elseif (substr($key, -4) === '_url' || $key === 'banner_link') {
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
            (string) ($clean['banner_media_type'] ?? 'auto') !== (string) ($old['banner_media_type'] ?? 'auto')
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
        ?>
        <div class="wrap">
            <h1>Schedule Display</h1>
            <?php if (!empty($_GET['schedule-screens-refreshed'])): ?>
                <div class="notice notice-success is-dismissible"><p>Schedule screen refresh requested. Open schedule displays should reload within about 30 seconds.</p></div>
            <?php endif; ?>
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
                    <tr><th>Banner Media</th><td><?php IFRD_Banner_Media::render_picker(self::OPTION, $o, 'banner_url', 'ifrd-schedule-banner', array('image', 'video'), array(
                        'type_field' => 'banner_media_type',
                        'title' => 'Choose banner image or video',
                        'button_text' => 'Use as banner',
                        'placeholder' => 'Select an image or video, or paste its URL',
                        'description' => 'Video banners play automatically, muted, and on a continuous loop. Saving different banner media also asks open schedule screens to reload.',
                    )); ?></td></tr>
                    <tr><th>Banner Link URL</th><td><input class="large-text" name="<?php echo esc_attr(self::OPTION); ?>[banner_link]" value="<?php echo esc_attr($o['banner_link']); ?>"></td></tr>
                    <tr><th>Locker Room Name Map</th><td><textarea class="large-text code" rows="5" name="<?php echo esc_attr(self::OPTION); ?>[locker_name_map]"><?php echo esc_textarea($o['locker_name_map']); ?></textarea><p class="description">Optional fallback if Dash only returns locker IDs. One per line, like <code>1=Locker Room 1</code>.</p></td></tr>
                </table>
                <h2>Colors</h2>
                <table class="form-table">
                    <?php foreach (array('bg_color'=>'Background','panel_color'=>'Panel','text_color'=>'Text','muted_color'=>'Muted Text','accent_color'=>'Accent','now_color'=>'Now','next_color'=>'Up Next','later_color'=>'Later') as $key => $label): ?>
                        <tr><th><?php echo esc_html($label); ?></th><td><input type="color" name="<?php echo esc_attr(self::OPTION); ?>[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($o[$key]); ?>"></td></tr>
                    <?php endforeach; ?>
                </table>
                <?php else: ?>
                <p>Choose the image or video shown in the banner area of the schedule display.</p>
                <table class="form-table">
                    <tr><th>Banner Media</th><td><?php IFRD_Banner_Media::render_picker(self::OPTION, $o, 'banner_url', 'ifrd-schedule-banner', array('image', 'video'), array(
                        'type_field' => 'banner_media_type',
                        'title' => 'Choose banner image or video',
                        'button_text' => 'Use as banner',
                        'placeholder' => 'Select an image or video, or paste its URL',
                        'description' => 'Video banners play automatically, muted, and on a continuous loop. Saving different banner media also asks open schedule screens to reload.',
                    )); ?></td></tr>
                </table>
                <?php endif; ?>
                <?php submit_button(); ?>
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

    private function add_locker_data_to_events($events, $locker_name_map = '') {
        $fallback_map = $this->parse_locker_name_map($locker_name_map);
        $participant_settings = $this->participant_display_metadata_settings();
        $schedule_settings = $this->opts();

        foreach ($events as &$event) {
            $event['registrantText'] = '';

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

    private function payload() {
        $cached = get_transient(self::CACHE);
        if ($cached) {
            return $cached;
        }

        $o = $this->opts();
        list($now, $day_start, $day_end) = $this->schedule_now_bounds();

        $events = array();
        $page = 1;
        $last = 1;

        do {
            $body = IFRD_Dash_Connector::get('events', array(
                'sort' => 'start',
                'page[number]' => $page,
                'page[size]' => 100,
            ), array('cache' => false));

            if (is_wp_error($body)) {
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

        $gold_visible = array_slice($gold, 0, $max);
        $silver_visible = array_slice($silver, 0, $max);

        // Enrich only visible events to avoid excessive API calls.
        $gold_visible = $this->add_locker_data_to_events($gold_visible, $o['locker_name_map']);
        $silver_visible = $this->add_locker_data_to_events($silver_visible, $o['locker_name_map']);

        $payload = array(
            'updatedAt' => current_time('mysql'),
            'gold' => $gold_visible,
            'silver' => $silver_visible,
            'goldAdditional' => max(0, count($gold) - $max),
            'silverAdditional' => max(0, count($silver) - $max),
        );

        set_transient(self::CACHE, $payload, 240);

        return $payload;
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
        $id = 'ifrd_sched_' . wp_generate_password(8, false);
        $screen_refresh_version = IFRD_Video_For_Screens::current_refresh_version();
        $style = sprintf(
            '--ifr-bg:%s;--ifr-panel:%s;--ifr-text:%s;--ifr-muted:%s;--ifr-accent:%s;--ifr-now:%s;--ifr-next:%s;--ifr-later:%s;',
            esc_attr($o['bg_color']),
            esc_attr($o['panel_color']),
            esc_attr($o['text_color']),
            esc_attr($o['muted_color']),
            esc_attr($o['accent_color']),
            esc_attr($o['now_color']),
            esc_attr($o['next_color']),
            esc_attr($o['later_color'])
        );

        ob_start();
        ?>
        <div id="<?php echo esc_attr($id); ?>" class="ifrd-schedule-app" style="<?php echo esc_attr($style); ?>">
            <div class="ifrd-schedule-topbar">
                <div class="ifrd-schedule-brand">
                    <?php if (!empty($o['logo_url'])): ?><img class="ifrd-schedule-logo" src="<?php echo esc_url($o['logo_url']); ?>" alt="Logo"><?php endif; ?>
                    <div><h1><?php echo esc_html($o['display_title']); ?></h1><p><?php echo esc_html($o['display_subtitle']); ?></p></div>
                </div>
                <div class="ifrd-schedule-clock"><strong data-time>--:--</strong><span data-date>Loading...</span></div>
            </div>
            <div class="ifrd-schedule-grid">
                <section class="ifrd-schedule-panel"><h2><?php echo esc_html($o['gold_title']); ?></h2><div data-list="gold"></div></section>
                <section class="ifrd-schedule-panel"><h2><?php echo esc_html($o['silver_title']); ?></h2><div data-list="silver"></div></section>
            </div>
            <div class="ifrd-schedule-footer">
                <span data-status>API status: connecting...</span>
				<span data-updated>Last updated: --</span>
			</div>
			<div class="ifrd-schedule-footer">
                <?php if (!empty($o['banner_url'])): ?>
                    <?php if (!empty($o['banner_link'])): ?><a href="<?php echo esc_url($o['banner_link']); ?>"><?php endif; ?>
                    <?php if (IFRD_Banner_Media::is_video($o['banner_url'], $o['banner_media_type'] ?? 'auto')): ?>
                        <video class="ifrd-schedule-banner" autoplay muted loop playsinline preload="metadata"><source src="<?php echo esc_url($o['banner_url']); ?>"></video>
                    <?php else: ?>
                        <img class="ifrd-schedule-banner" src="<?php echo esc_url($o['banner_url']); ?>" alt="">
                    <?php endif; ?>
                    <?php if (!empty($o['banner_link'])): ?></a><?php endif; ?>
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
        .ifrd-schedule-event{display:grid;grid-template-columns:minmax(92px,7vw) 1fr auto;gap:10px;align-items:center;padding:7px 0;border-top:1px solid rgba(255,255,255,.08)}
        .ifrd-schedule-event:first-child{border-top:0}.ifrd-schedule-resurfacing .ifrd-schedule-title{color:var(--ifr-text)}.ifrd-schedule-time{font-weight:850;font-size:clamp(14px,1.1vw,21px);line-height:1.08}.ifrd-schedule-time span{display:block;color:var(--ifr-muted);font-size:.82em;font-weight:500;margin-top:2px}
        .ifrd-schedule-title{font-weight:850;font-size:clamp(16px,1.35vw,26px);line-height:1.1}.ifrd-schedule-meta{color:var(--ifr-muted);font-size:clamp(11px,.95vw,17px);margin-top:2px}.ifrd-schedule-subblocks{color:var(--ifr-muted);font-size:clamp(11px,.95vw,17px);margin-top:4px;line-height:1.25}.ifrd-schedule-subblocks strong{color:var(--ifr-text);font-weight:850}
        .ifrd-schedule-badge{font-size:clamp(10px,.85vw,15px);font-weight:850;white-space:nowrap;background:rgba(255,255,255,.08);border-radius:999px;padding:6px 9px}.ifrd-schedule-badge.now{color:var(--ifr-now)}.ifrd-schedule-badge.next{color:var(--ifr-next)}.ifrd-schedule-badge.later{color:var(--ifr-later)}
        .ifrd-schedule-additional,.ifrd-schedule-empty,.ifrd-schedule-error{color:var(--ifr-muted);font-size:clamp(13px,1vw,18px);font-weight:700;padding-top:8px}.ifrd-schedule-error{color:#ffd4d4}
        .ifrd-schedule-footer{display:flex;align-items:stretch;justify-content:space-between;gap:12px;color:var(--ifr-muted);font-size:8px;margin-top: -8px;margin-bottom: 0px;}.ifrd-schedule-banner{min-height:90px;max-height:140px;max-width:100%;object-fit:contain;border-radius:8px}.ifrd-schedule-locker{display:block}
        @media(max-width:99999px){.ifrd-schedule-grid{grid-template-columns:minmax(0,1fr) minmax(0,1fr)!important}}
        </style>
        <script>
        (function(){
            const root=document.getElementById('<?php echo esc_js($id); ?>');
            function esc(v){return String(v??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));}
            function clock(){root.querySelector('[data-time]').textContent=new Date().toLocaleTimeString([],{hour:'numeric',minute:'2-digit'});root.querySelector('[data-date]').textContent=new Date().toLocaleDateString([],{weekday:'long',month:'long',day:'numeric'});}
            function badge(e){let n=Date.now(),s=new Date(e.start).getTime(),end=new Date(e.end).getTime();if(s<=n&&end>n)return ['ON ICE NOW','now'];let mins=(s-n)/60000;if(mins>0&&mins<=60)return ['UP NEXT','next'];return ['LATER','later'];}
            function render(key,items,add){const el=root.querySelector('[data-list="'+key+'"]'); if(!items.length){el.innerHTML='<div class="ifrd-schedule-empty">No more events today.</div>';return;} const now=Date.now(); const hasCurrent=items.some(e=>{let s=new Date(e.start).getTime(),end=new Date(e.end).getTime();return s<=now&&end>now;}); const firstStart=new Date(items[0].start).getTime(); const beforeFirstEvent=now<firstStart; let html=''; if(!hasCurrent && !beforeFirstEvent){html+='<article class="ifrd-schedule-event ifrd-schedule-resurfacing"><div class="ifrd-schedule-time"></div><div><div class="ifrd-schedule-title">Resurfacing</div></div><div class="ifrd-schedule-badge now">ON ICE NOW</div></article>';} html+=items.map(e=>{let b=badge(e);let metaParts=[];if(e.note)metaParts.push(esc(e.note));if(e.registrantText)metaParts.push(esc(e.registrantText));let meta=metaParts.length?'<div class="ifrd-schedule-meta">'+metaParts.join(' • ')+'</div>':'';let locker=e.lockerText?'<div class="ifrd-schedule-meta ifrd-schedule-locker">'+esc(e.lockerText)+'</div>':'';let blocks=(e.subBlocks&&e.subBlocks.length)?'<div class="ifrd-schedule-subblocks">'+e.subBlocks.map(x=>'<div><strong>'+esc(x.time)+'</strong>'+((x.title)?' — '+esc(x.title):'')+'</div>').join('')+'</div>':'';return '<article class="ifrd-schedule-event"><div class="ifrd-schedule-time">'+esc(e.startLabel)+'<span>to '+esc(e.endLabel)+'</span></div><div><div class="ifrd-schedule-title">'+esc(e.title)+'</div>'+blocks+meta+locker+'</div><div class="ifrd-schedule-badge '+b[1]+'">'+b[0]+'</div></article>';}).join(''); el.innerHTML=html+(add>0?'<div class="ifrd-schedule-additional">+'+add+' additional events scheduled</div>':'');}
            const scheduleCacheKey='ifrd_schedule_last_success_v231';
            function localDayKey(){const d=new Date();return [d.getFullYear(),String(d.getMonth()+1).padStart(2,'0'),String(d.getDate()).padStart(2,'0')].join('-');}
            function displaySchedule(data){render('gold',data.gold||[],data.goldAdditional||0);render('silver',data.silver||[],data.silverAdditional||0);const updated=data.cachedAt?new Date(data.cachedAt):new Date();root.querySelector('[data-updated]').textContent='Last updated: '+updated.toLocaleTimeString([],{hour:'numeric',minute:'2-digit',second:'2-digit'});}
            function showCachedSchedule(){try{const cached=JSON.parse(localStorage.getItem(scheduleCacheKey));if(cached&&cached.cachedDay===localDayKey()){displaySchedule(cached);root.querySelector('[data-status]').textContent='API status: connecting — showing saved schedule';return true;}if(cached){localStorage.removeItem(scheduleCacheKey);}}catch(e){try{localStorage.removeItem(scheduleCacheKey);}catch(ignore){}}return false;}
            async function load(){const status=root.querySelector('[data-status]');try{status.textContent='API status: updating...';let f=new FormData();f.append('action','ifrd_schedule_data');let r=await fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>',{method:'POST',body:f,cache:'no-store'});if(!r.ok)throw new Error('Schedule refresh failed');let p=await r.json();if(!p.success)throw new Error(p.data?.message||'Unable to load schedule');const schedule=Object.assign({},p.data,{cachedAt:Date.now(),cachedDay:localDayKey()});displaySchedule(schedule);try{localStorage.setItem(scheduleCacheKey,JSON.stringify(schedule));}catch(ignore){}status.textContent='API status: connected';}catch(e){const gold=root.querySelector('[data-list="gold"]');const silver=root.querySelector('[data-list="silver"]');const hasVisibleSchedule=(gold&&gold.children.length>0)||(silver&&silver.children.length>0);status.textContent=hasVisibleSchedule?'API status: refresh failed — showing last schedule':'API status: error';if(!hasVisibleSchedule&&gold){gold.innerHTML='<div class="ifrd-schedule-error">'+esc(e.message)+'</div>';}if(window.console&&console.error)console.error(e);}}
            const embeddedRefreshVersion=<?php echo wp_json_encode($screen_refresh_version); ?>;
            const initialPageUrl=new URL(window.location.href);
            let currentRefreshVersion=initialPageUrl.searchParams.get('screen_refresh')||embeddedRefreshVersion;
            async function checkForScreenRefresh(){try{const body=new URLSearchParams();body.set('action',<?php echo wp_json_encode(IFRD_Video_For_Screens::AJAX_ACTION); ?>);const response=await fetch(<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>,{method:'POST',headers:{'Accept':'application/json','Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:body.toString(),credentials:'same-origin',cache:'no-store'});const payload=await response.json();const latest=String(payload&&payload.success&&payload.data&&payload.data.version||'');if(!latest||latest===currentRefreshVersion)return;currentRefreshVersion=latest;const target=new URL(window.location.href);target.searchParams.set('screen_refresh',latest);window.location.replace(target.toString());}catch(ignore){}}
            setInterval(checkForScreenRefresh,30000);setTimeout(checkForScreenRefresh,5000);document.addEventListener('visibilitychange',function(){if(!document.hidden)checkForScreenRefresh();});
            clock();setInterval(clock,1000);showCachedSchedule();load();setInterval(load,<?php echo max(30,intval($o['refresh_seconds']))*1000; ?>);
        })();
        </script>
        <?php
        return ob_get_clean();
    }
}

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
        add_action('init', array($this, 'ensure_cron'));
        add_action(self::CRON_HOOK, array($this, 'warm_cache'));
        add_action(self::REFRESH_HOOK, array($this, 'refresh_week'), 10, 1);
    }

    public static function deactivate() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        wp_clear_scheduled_hook(self::REFRESH_HOOK);
        delete_option('ifrd_calendar_warm_schedule');
    }

    public function register_assets() {
        $base = plugin_dir_url(__FILE__) . 'assets/';
        wp_register_style('ifrd-schedule-calendar', $base . 'schedule-calendar.css', array(), '2.7.6');
        wp_register_script('ifrd-schedule-calendar', $base . 'schedule-calendar.js', array(), '2.7.6', true);
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
        $schedule_version = '2.5.7-midnight-noon';
        if (get_option('ifrd_calendar_warm_schedule') !== $schedule_version) {
            wp_clear_scheduled_hook(self::CRON_HOOK);
            update_option('ifrd_calendar_warm_schedule', $schedule_version, false);
            // Prime the new cache shortly after this update is activated, while
            // also establishing the permanent midnight/noon schedule.
            wp_schedule_single_event(time() + 5, self::CRON_HOOK);
            wp_schedule_event($this->next_cache_warm_timestamp(), 'twicedaily', self::CRON_HOOK);
            return;
        }

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event($this->next_cache_warm_timestamp(), 'twicedaily', self::CRON_HOOK);
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
        $this->get_week($current->modify('+7 days'), true);
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
            '2.5.7',
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

        $payload = $this->fetch_week($week_start, $ttl, $force);

        if (is_wp_error($payload)) {
            $stale = get_transient($stale_key);
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

        return $payload;
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
        $max_pages = 100;

        do {
            $payload = IFRD_Dash_Connector::get('events', array(
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

$ifrd_schedule_display = new IFRD_Schedule_Display();
new IFRD_Participants_Display();
new IFRD_Schedule_Calendar($ifrd_schedule_display);
new IFRD_Video_For_Screens();
new IFRD_Additional_Video_Screen('pricing', 'Pricing Page', 'ifrd-pricing-page', 'pricing_page');
new IFRD_Additional_Video_Screen('public_skating_rules', 'Public Skating Rules Page', 'ifrd-public-skating-rules-page', 'public_skating_rules_page');
new IFRD_Shortcodes_Page();
register_deactivation_hook(__FILE__, array('IFRD_Schedule_Calendar', 'deactivate'));
