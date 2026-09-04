<?php
/**
 * Full-screen looping video display for lobby and rink screens.
 */
class IFRD_Video_For_Screens {
    const OPTION = 'ifrd_video_screen_settings';
    const REFRESH_OPTION = 'ifrd_video_screen_refresh_version';
    const PLUGIN_VERSION_OPTION = 'ifrd_plugin_version';
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
            'video_schedule' => array(),
            'display_mode' => 'video',
            'slideshow_images' => array(),
            'slideshow_seconds' => '10',
            'slideshow_transition' => 'fade',
            'slideshow_transition_seconds' => '1',
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

    public static function current_display_version() {
        $video_settings = wp_parse_args(get_option(self::OPTION, array()), array(
            'video_url' => '',
            'video_schedule' => array(),
        ));
        $schedule_settings = wp_parse_args(get_option(IFRD_Schedule_Display::OPTION, array()), array(
            'display_timezone' => 'America/Chicago',
            'banner_url' => '',
            'banner_media_type' => 'auto',
            'banner_schedule' => array(),
            'banner_mode' => 'single',
            'banner_slideshow_images' => array(),
            'banner_slideshow_seconds' => '10',
            'banner_slideshow_transition' => 'fade',
            'banner_slideshow_transition_seconds' => '1',
        ));
        $timezone_name = IFRD_Scheduled_Media::timezone_name($schedule_settings);
        $video = IFRD_Scheduled_Media::effective($video_settings['video_url'], 'video', $video_settings['video_schedule'], $timezone_name);
        $banner = IFRD_Scheduled_Media::effective($schedule_settings['banner_url'], $schedule_settings['banner_media_type'], $schedule_settings['banner_schedule'], $timezone_name);

        return self::current_refresh_version() . '-' . substr(md5($video['token'] . '|' . $banner['token']), 0, 16);
    }

    public function maybe_refresh_after_update() {
        if ((string) get_option(self::PLUGIN_VERSION_OPTION, '') === IFRD_VERSION) {
            return;
        }

        update_option(self::PLUGIN_VERSION_OPTION, IFRD_VERSION, false);
        $version = self::bump_refresh_version();
        update_option('ifrd_pricing_screen_refresh_version', $version, false);
        update_option('ifrd_public_skating_rules_screen_refresh_version', $version, false);
    }

    public function sanitize($input) {
        $video_url = isset($input['video_url']) ? esc_url_raw($input['video_url']) : '';
        $old = $this->opts();
        $timezone_name = IFRD_Scheduled_Media::timezone_name();
        $video_schedule = IFRD_Scheduled_Media::sanitize($input['video_schedule'] ?? array(), $timezone_name);
        $display_mode = (($input['display_mode'] ?? 'video') === 'slideshow') ? 'slideshow' : 'video';
        $slideshow_images = IFRD_Expiring_Slideshow::sanitize($input['slideshow_images'] ?? array(), $timezone_name);
        $slideshow_seconds = (string) min(300, max(2, intval($input['slideshow_seconds'] ?? 10)));
        $slideshow_transition = in_array(($input['slideshow_transition'] ?? 'fade'), array('fade', 'slide', 'none'), true) ? $input['slideshow_transition'] : 'fade';
        $slideshow_transition_seconds = (string) min(5, max(0.1, floatval($input['slideshow_transition_seconds'] ?? 1)));

        if (
            $video_url !== (string) ($old['video_url'] ?? '') ||
            wp_json_encode($video_schedule) !== wp_json_encode($old['video_schedule'] ?? array()) ||
            $display_mode !== ($old['display_mode'] ?? 'video') ||
            wp_json_encode($slideshow_images) !== wp_json_encode($old['slideshow_images'] ?? array()) ||
            $slideshow_seconds !== (string) ($old['slideshow_seconds'] ?? '10')
            || $slideshow_transition !== ($old['slideshow_transition'] ?? 'fade')
            || $slideshow_transition_seconds !== (string) ($old['slideshow_transition_seconds'] ?? '1')
        ) {
            self::bump_refresh_version();
        }

        return array(
            'video_url' => $video_url,
            'video_schedule' => $video_schedule,
            'display_mode' => $display_mode,
            'slideshow_images' => $slideshow_images,
            'slideshow_seconds' => $slideshow_seconds,
            'slideshow_transition' => $slideshow_transition,
            'slideshow_transition_seconds' => $slideshow_transition_seconds,
        );
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
            'version' => self::current_display_version(),
        ));
    }

    public function page() {
        $o = $this->opts();
        ?>
        <div class="wrap">
            <h1>Video for Screens</h1>
            <?php if (!empty($_GET['screens-refreshed'])): ?>
                <div class="notice notice-success is-dismissible"><p>Refresh requested. Open video screens should reload within about 60 seconds.</p></div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields('ifrd_video_screen_group'); ?>
                <table class="form-table">
                    <tr><th>Display Mode</th><td><select name="<?php echo esc_attr(self::OPTION); ?>[display_mode]"><option value="video" <?php selected($o['display_mode'], 'video'); ?>>Video</option><option value="slideshow" <?php selected($o['display_mode'], 'slideshow'); ?>>Image slideshow</option></select></td></tr>
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
                <?php IFRD_Expiring_Slideshow::render_editor(self::OPTION, 'slideshow_images', $o['slideshow_images'], IFRD_Scheduled_Media::timezone_name(), 'Screen Image Slideshow'); ?>
                <p><label><strong>Seconds per image</strong> <input type="number" min="2" max="300" class="small-text" name="<?php echo esc_attr(self::OPTION); ?>[slideshow_seconds]" value="<?php echo esc_attr($o['slideshow_seconds']); ?>"></label></p>
                <p><label><strong>Transition</strong> <select name="<?php echo esc_attr(self::OPTION); ?>[slideshow_transition]"><option value="fade" <?php selected($o['slideshow_transition'], 'fade'); ?>>Fade</option><option value="slide" <?php selected($o['slideshow_transition'], 'slide'); ?>>Slide</option><option value="none" <?php selected($o['slideshow_transition'], 'none'); ?>>None</option></select></label> &nbsp; <label><strong>Duration</strong> <input type="number" min="0.1" max="5" step="0.1" class="small-text" name="<?php echo esc_attr(self::OPTION); ?>[slideshow_transition_seconds]" value="<?php echo esc_attr($o['slideshow_transition_seconds']); ?>"> seconds</label></p>
                <?php IFRD_Scheduled_Media::render_editor(
                    self::OPTION,
                    'video_schedule',
                    $o['video_schedule'] ?? array(),
                    IFRD_Scheduled_Media::timezone_name(),
                    'Scheduled Video Changes'
                ); ?>
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
        <?php IFRD_Scheduled_Media::print_editor_script(); ?>
        <?php IFRD_Expiring_Slideshow::print_editor_script(); ?>
        <?php
    }

    public function shortcode($atts) {
        $o = $this->opts();
        if (($o['display_mode'] ?? 'video') === 'slideshow') {
            $slides = IFRD_Expiring_Slideshow::active($o['slideshow_images'] ?? array(), IFRD_Scheduled_Media::timezone_name());
            return self::render_player('', self::current_display_version(), self::AJAX_ACTION, 'ifrd-screen-video-', $slides, $o['slideshow_seconds'] ?? 10, $o['slideshow_transition'] ?? 'fade', $o['slideshow_transition_seconds'] ?? 1);
        }
        $effective = IFRD_Scheduled_Media::effective(
            $o['video_url'] ?? '',
            'video',
            $o['video_schedule'] ?? array(),
            IFRD_Scheduled_Media::timezone_name()
        );
        $video_url = $effective['url'];
        $refresh_version = self::current_display_version();

        return self::render_player($video_url, $refresh_version, self::AJAX_ACTION, 'ifrd-screen-video-');
    }

    public static function render_player($video_url, $refresh_version, $ajax_action, $id_prefix = 'ifrd-screen-video-', $slides = array(), $slide_seconds = 10, $transition = 'fade', $transition_seconds = 1) {
        $id = sanitize_html_class($id_prefix) . wp_generate_password(8, false, false);

        ob_start();
        ?>
        <div id="<?php echo esc_attr($id); ?>" class="ifrd-screen-video">
            <?php if ($slides): ?>
                <?php echo IFRD_Expiring_Slideshow::render($slides, $slide_seconds, 'ifrd-fullscreen-slideshow', '', $transition, $transition_seconds); ?>
            <?php elseif ($video_url !== ''): ?>
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
            #<?php echo esc_attr($id); ?> .ifrd-fullscreen-slideshow,#<?php echo esc_attr($id); ?> .ifrd-slide,#<?php echo esc_attr($id); ?> .ifrd-slide img{position:absolute;inset:0;width:100%;height:100%}#<?php echo esc_attr($id); ?> .ifrd-slide img{object-fit:contain;background:#000}
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
            if(initialUrl.searchParams.has('screen_refresh')&&window.history&&history.replaceState){setTimeout(function(){initialUrl.searchParams.delete('screen_refresh');history.replaceState(history.state,'',initialUrl.toString());},0);}

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

            window.setInterval(checkForRefresh,60000);
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
