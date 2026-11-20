<?php
/**
 * Full-screen image slideshow for lobby and rink screens.
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
        add_action('admin_post_ifrd_import_master_slideshow', array($this, 'import_master_slideshow'));
        add_action('init', array($this, 'maybe_refresh_after_update'), 1);
        add_action('wp_ajax_' . self::AJAX_ACTION, array($this, 'refresh_status'));
        add_action('wp_ajax_nopriv_' . self::AJAX_ACTION, array($this, 'refresh_status'));
        add_shortcode('video_for_screens', array($this, 'shortcode'));
    }

    public function defaults() {
        return array(
            'video_url' => '',
            'video_schedule' => array(),
            'display_mode' => 'slideshow',
            'slideshow_images' => array(),
            'slideshow_seconds' => '10',
            'slideshow_transition' => 'brand-wipe',
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
            'takeover_schedule' => array(),
        ));
        $timezone_name = IFRD_Scheduled_Media::timezone_name($schedule_settings);
        $video = IFRD_Scheduled_Media::effective($video_settings['video_url'], 'video', $video_settings['video_schedule'], $timezone_name);
        $banner = IFRD_Scheduled_Media::effective($schedule_settings['banner_url'], $schedule_settings['banner_media_type'], $schedule_settings['banner_schedule'], $timezone_name);
        $takeover = IFRD_Schedule_Takeover::effective($schedule_settings['takeover_schedule'] ?? array(), $timezone_name);

        return self::current_refresh_version() . '-' . substr(md5($video['token'] . '|' . $banner['token'] . '|' . $takeover['token']), 0, 16);
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
        $display_mode = 'slideshow';
        $slideshow_images = IFRD_Expiring_Slideshow::sanitize($input['slideshow_images'] ?? array(), $timezone_name);
        $slideshow_seconds = (string) min(120, max(2, intval($input['slideshow_seconds'] ?? 10)));
        $slideshow_transition = 'brand-wipe';
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

    public function import_master_slideshow() {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to import this slideshow.', 'ice-field-rink-displays'));
        }
        check_admin_referer('ifrd_import_master_slideshow');
        if (!class_exists('Ice_Field_Display_Cloud') || !method_exists('Ice_Field_Display_Cloud', 'master_slideshow_data')) {
            wp_die(esc_html__('Ice & Field Display Cloud 0.2.0 or newer is required.', 'ice-field-rink-displays'));
        }
        $master = Ice_Field_Display_Cloud::master_slideshow_data();
        if (is_wp_error($master)) {
            wp_die(esc_html($master->get_error_message()));
        }
        $slides = array();
        foreach ((array) ($master['slides'] ?? array()) as $slide) {
            if (!is_array($slide) || empty($slide['url'])) {
                continue;
            }
            $slides[] = array(
                'url' => esc_url_raw((string) $slide['url']),
                'starts_at' => sanitize_text_field((string) ($slide['starts_at'] ?? '')),
                'expires_at' => sanitize_text_field((string) ($slide['expires_at'] ?? '')),
                'delete_after_expiry' => '0',
            );
        }
        $settings = $this->opts();
        $settings['display_mode'] = 'slideshow';
        $settings['slideshow_images'] = $slides;
        $settings['slideshow_seconds'] = (string) min(120, max(2, (int) ($master['seconds'] ?? 10)));
        update_option(self::OPTION, $settings, false);
        self::bump_refresh_version();
        wp_safe_redirect(add_query_arg(
            array('page' => 'ifrd-video-for-screens', 'master-imported' => '1'),
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
            <p>Manage the ordered image slideshow shown by the Video for Screens channel. Display 01 checks WordPress for saved changes every five minutes, stores the images locally, and then synchronizes the playlist to the fleet.</p>
            <?php if (!empty($_GET['screens-refreshed'])): ?>
                <div class="notice notice-success is-dismissible"><p>Refresh requested. Open WordPress slideshow previews should reload within about 60 seconds; display 01 will also collect this revision during its next fleet check.</p></div>
            <?php endif; ?>
            <?php if (!empty($_GET['master-imported'])): ?>
                <div class="notice notice-success is-dismissible"><p>Display 01's slideshow was imported. You can now edit it below; saving will queue it for the next fleet synchronization.</p></div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields('ifrd_video_screen_group'); ?>
                <?php IFRD_Expiring_Slideshow::render_editor(self::OPTION, 'slideshow_images', $o['slideshow_images'], IFRD_Scheduled_Media::timezone_name(), 'Screen Image Slideshow'); ?>
                <p><label><strong>Seconds per image</strong> <input type="number" min="2" max="120" class="small-text" name="<?php echo esc_attr(self::OPTION); ?>[slideshow_seconds]" value="<?php echo esc_attr($o['slideshow_seconds']); ?>"></label></p>
                <p><strong>Transition:</strong> Ice &amp; Field color wipe <span class="description">— used by both the WordPress shortcode and Pi screens.</span></p>
                <?php submit_button('Save Slideshow'); ?>
            </form>

            <?php $this->render_master_slideshow(); ?>

            <hr>
            <h2>Remote Refresh</h2>
            <p>Use this to reload open WordPress slideshow preview pages. The Pi fleet checks saved slideshow revisions automatically.</p>
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

    private function render_master_slideshow() {
        if (!class_exists('Ice_Field_Display_Cloud') || !method_exists('Ice_Field_Display_Cloud', 'master_slideshow_data')) {
            return;
        }
        $master = Ice_Field_Display_Cloud::master_slideshow_data();
        ?>
        <hr>
        <h2>Currently on Display 01</h2>
        <?php if (is_wp_error($master)) : ?>
            <p><?php echo esc_html($master->get_error_message()); ?></p>
        <?php else : ?>
            <p><strong>Master revision:</strong> <?php echo esc_html((string) $master['revision']); ?>
                <?php if (!empty($master['published_at'])) : ?> &nbsp; <strong>Published:</strong> <?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime((string) $master['published_at']))); ?><?php endif; ?>
                &nbsp; <strong>Hold:</strong> <?php echo esc_html((string) $master['seconds']); ?> seconds</p>
            <?php if (!empty($master['slides'])) : ?>
                <div style="display:flex;flex-wrap:wrap;gap:12px;margin:12px 0 18px">
                    <?php foreach ($master['slides'] as $slide) : ?>
                        <figure style="width:160px;margin:0"><img src="<?php echo esc_url($slide['url']); ?>" alt="" style="display:block;width:160px;height:90px;object-fit:contain;background:#071f33;border:1px solid #c3c4c7;border-radius:6px"><figcaption style="margin-top:4px;word-break:break-word"><?php echo esc_html((string) ($slide['name'] ?? 'Slide image')); ?><?php if (!empty($slide['starts_at'])) : ?><br><small>Begins <?php echo esc_html((string) $slide['starts_at']); ?></small><?php endif; ?><?php if (!empty($slide['expires_at'])) : ?><br><small>Stops <?php echo esc_html((string) $slide['expires_at']); ?></small><?php endif; ?></figcaption></figure>
                    <?php endforeach; ?>
                </div>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="ifrd_import_master_slideshow">
                    <?php wp_nonce_field('ifrd_import_master_slideshow'); ?>
                    <?php submit_button('Import from Display 01', 'secondary', 'submit', false); ?>
                    <p class="description">This replaces the editable WordPress slideshow with the master copy. It does not change display 01 until you save the slideshow above.</p>
                </form>
            <?php else : ?>
                <p>Display 01 is not currently publishing an image slideshow for this channel.</p>
            <?php endif; ?>
        <?php endif; ?>
        <?php
    }

    public function shortcode($atts) {
        return self::render_current();
    }

    public static function render_current($id_prefix = 'ifrd-screen-video-') {
        $o = wp_parse_args(get_option(self::OPTION, array()), array(
            'video_url' => '',
            'video_schedule' => array(),
            'display_mode' => 'slideshow',
            'slideshow_images' => array(),
            'slideshow_seconds' => '10',
            'slideshow_transition' => 'brand-wipe',
            'slideshow_transition_seconds' => '1',
        ));
        $slides = IFRD_Expiring_Slideshow::active($o['slideshow_images'] ?? array(), IFRD_Scheduled_Media::timezone_name());
        return self::render_player('', self::current_display_version(), self::AJAX_ACTION, $id_prefix, $slides, $o['slideshow_seconds'] ?? 10, 'brand-wipe', $o['slideshow_transition_seconds'] ?? 1);
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
