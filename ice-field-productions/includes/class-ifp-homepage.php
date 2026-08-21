<?php
if (!defined('ABSPATH')) exit;

class IFP_Homepage {
    const OPTION = 'ifp_homepage_builder';

    public static function init() {
        add_shortcode('ifp_homepage', [__CLASS__, 'shortcode']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
    }

    public static function defaults() {
        return [
            'sections' => ['hero','pathways','participant','dates','venue','sponsors','upcoming','archive'],
            'enabled' => [
                'hero'=>1,'pathways'=>1,'participant'=>1,
                'dates'=>1,'venue'=>1,'sponsors'=>1,'upcoming'=>1,'archive'=>1
            ],
            'hero_eyebrow' => 'Current Production',
            'hero_ticket_text' => 'Buy Tickets',
            'hero_volunteer_text' => 'Volunteer',
            'hero_register_text' => 'Join the Cast',
            'hero_title_color' => '#ffffff',
            'hero_eyebrow_color' => '#ff8033',
            'hero_tagline_color' => '#ffffff',
            'hero_overlay_color' => '#001f33',
            'hero_overlay_opacity' => 62,
            'hero_alignment' => 'left',
            'hero_height' => 'large',
            'hero_countdown_show_heading' => '1',
            'hero_countdown_eyebrow' => 'Opening Night',
            'hero_countdown_heading' => 'The story begins in',
            'hero_use_production_countdown_color' => '1',
            'hero_countdown_eyebrow_color' => '#ff8033',
            'hero_countdown_heading_color' => '#ffffff',
            'hero_countdown_bg' => '#f3f7fa',
            'hero_countdown_number_color' => '#003b5c',
            'hero_countdown_label_color' => '#5f6d7c',
            'hero_primary_bg' => '#ff8033',
            'hero_primary_text' => '#ffffff',
            'hero_secondary_bg' => '#003b5c',
            'hero_secondary_text' => '#ffffff',

            'hero_show_logo' => '1',
            'hero_show_text_title' => '1',
            'hero_logo_width' => 520,
            'hero_logo_max_height' => 260,
            'hero_logo_spacing' => 24,

            'layout_remove_theme_spacing' => '1',
            'layout_full_width' => '1',
            'layout_hide_page_title' => '1',
            'layout_hide_breadcrumbs' => '1',
            'layout_flush_header' => '1',
            'layout_hero_top_offset' => 0,
            'layout_content_top_padding' => 110,
            'layout_content_bottom_padding' => 80,
            'pathways_heading' => 'Join. Watch. Support.',
            'join_title' => 'Join the Show',
            'join_text' => 'Registration, rehearsals, costumes, and participant resources.',
            'join_button' => 'Participant Information',
            'watch_title' => 'Watch the Show',
            'watch_text' => 'Tickets, showtimes, seating, parking, and accessibility.',
            'watch_button' => 'Plan Your Visit',
            'support_title' => 'Support the Show',
            'support_text' => 'Sponsors, program ads, volunteers, and community support.',
            'support_button' => 'Support the Production',
            'support_page_id' => 0,
            'support_url' => '',

            'join_bg_start' => '#477b98',
            'join_bg_end' => '#003b5c',
            'join_title_color' => '#ffffff',
            'join_text_color' => '#ffffff',
            'join_link_color' => '#ffffff',

            'watch_bg_start' => '#8b6689',
            'watch_bg_end' => '#593b61',
            'watch_title_color' => '#ffffff',
            'watch_text_color' => '#ffffff',
            'watch_link_color' => '#ffffff',

            'support_bg_start' => '#bc823f',
            'support_bg_end' => '#7f4c1f',
            'support_title_color' => '#ffffff',
            'support_text_color' => '#ffffff',
            'support_link_color' => '#ffffff',

            'pathway_corner_radius' => 24,
            'pathway_card_height' => 310,
            'pathway_card_height_mobile' => 260,

            'sponsor_show_tagline' => '1',
            'sponsor_logo_radius' => 12,
            'sponsor_logo_border_width' => 1,
            'sponsor_logo_border_color' => '#e3e8ec',
            'sponsor_card_background' => '#ffffff',
            'sponsor_tagline_color' => '#5f6d7c',

            'participant_heading' => 'Everything your skater needs',
            'participant_text' => 'Keep announcements, rehearsals, costumes, resources, and important deadlines in one predictable location.',
            'dates_heading' => 'Important Dates',
            'sponsors_heading' => 'Our Sponsors',
            'upcoming_heading' => 'Upcoming Productions',
            'upcoming_limit' => 6,
            'archive_heading' => 'Past Productions',
            'archive_limit' => 3,

            'section_padding_desktop' => 76,
            'section_padding_mobile' => 56,
            'pathways_section_bg' => '#f6f8fa',
            'participant_section_bg' => '#ffffff',
            'dates_section_bg' => '#003b5c',
            'dates_section_text' => '#ffffff',
            'sponsors_section_bg' => '#f6f8fa',
            'archive_section_bg' => '#eef3f6',
        ];
    }

    public static function settings() {
        $defaults = self::defaults();
        $settings = wp_parse_args(get_option(self::OPTION, []), $defaults);
        $valid_sections = array_keys($defaults['enabled']);
        $saved_sections = isset($settings['sections']) && is_array($settings['sections']) ? $settings['sections'] : [];
        $settings['sections'] = array_values(array_intersect($saved_sections, $valid_sections));
        foreach ($valid_sections as $section) {
            if (!in_array($section, $settings['sections'], true)) $settings['sections'][] = $section;
        }

        $saved_enabled = isset($settings['enabled']) && is_array($settings['enabled']) ? $settings['enabled'] : [];
        $settings['enabled'] = [];
        foreach ($valid_sections as $section) {
            $settings['enabled'][$section] = array_key_exists($section, $saved_enabled)
                ? (!empty($saved_enabled[$section]) ? 1 : 0)
                : (!empty($defaults['enabled'][$section]) ? 1 : 0);
        }

        return $settings;
    }

    public static function register_settings() {
        register_setting('ifp_homepage_group', self::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [__CLASS__, 'sanitize'],
            'default' => self::defaults(),
        ]);
    }

    public static function sanitize($input) {
        $defaults = self::defaults();
        $out = $defaults;
        $valid = array_keys($defaults['enabled']);
        $order = isset($input['sections']) && is_array($input['sections']) ? array_values(array_intersect($input['sections'], $valid)) : $defaults['sections'];
        foreach ($valid as $section) if (!in_array($section, $order, true)) $order[] = $section;
        $out['sections'] = $order;
        $out['enabled'] = [];
        foreach ($valid as $section) $out['enabled'][$section] = !empty($input['enabled'][$section]) ? 1 : 0;

        foreach ($defaults as $key=>$default) {
            if (in_array($key, ['sections','enabled'], true)) continue;
            if (!isset($input[$key])) continue;
            $color_keys = [
                'hero_title_color','hero_eyebrow_color','hero_tagline_color',
                'hero_overlay_color','hero_countdown_eyebrow_color',
                'hero_countdown_heading_color','hero_countdown_bg',
                'hero_countdown_number_color','hero_countdown_label_color',
                'hero_primary_bg','hero_primary_text',
                'hero_secondary_bg','hero_secondary_text',
                'join_bg_start','join_bg_end','join_title_color','join_text_color','join_link_color',
                'watch_bg_start','watch_bg_end','watch_title_color','watch_text_color','watch_link_color',
                'support_bg_start','support_bg_end','support_title_color','support_text_color','support_link_color',
                'sponsor_logo_border_color','sponsor_card_background','sponsor_tagline_color',
                'pathways_section_bg','participant_section_bg',
                'dates_section_bg','dates_section_text','sponsors_section_bg','archive_section_bg'
            ];
            $rich_text_keys = ['join_text','watch_text','support_text','participant_text'];
            if ($key === 'support_url') {
                $out[$key] = esc_url_raw($input[$key]);
            } elseif ($key === 'support_page_id') {
                $out[$key] = absint($input[$key]);
            } elseif (in_array($key, $rich_text_keys, true)) {
                $out[$key] = wp_kses_post($input[$key]);
            } elseif (in_array($key, ['archive_limit','upcoming_limit'], true)) {
                $out[$key] = max(1, min(12, absint($input[$key])));
            } elseif (in_array($key, ['section_padding_desktop','section_padding_mobile'], true)) {
                $out[$key] = max(0, min(240, absint($input[$key])));
            } elseif ($key === 'hero_overlay_opacity') {
                $out[$key] = max(0, min(100, absint($input[$key])));
            } elseif (in_array($key, ['hero_logo_width','hero_logo_max_height'], true)) {
                $out[$key] = max(80, min(1200, absint($input[$key])));
            } elseif ($key === 'pathway_corner_radius') {
                $out[$key] = max(0, min(80, absint($input[$key])));
            } elseif ($key === 'pathway_card_height') {
                $out[$key] = max(180, min(800, absint($input[$key])));
            } elseif ($key === 'pathway_card_height_mobile') {
                $out[$key] = max(160, min(600, absint($input[$key])));
            } elseif ($key === 'sponsor_logo_radius') {
                $out[$key] = max(0, min(100, absint($input[$key])));
            } elseif ($key === 'sponsor_logo_border_width') {
                $out[$key] = max(0, min(12, absint($input[$key])));
            } elseif (in_array($key, ['hero_logo_spacing','layout_hero_top_offset','layout_content_top_padding','layout_content_bottom_padding'], true)) {
                $out[$key] = max(-250, min(400, intval($input[$key])));
            } elseif (in_array($key, ['hero_show_logo','hero_show_text_title','hero_countdown_show_heading','hero_use_production_countdown_color','sponsor_show_tagline','layout_remove_theme_spacing','layout_full_width','layout_hide_page_title','layout_hide_breadcrumbs','layout_flush_header'], true)) {
                $out[$key] = !empty($input[$key]) ? '1' : '0';
            } elseif ($key === 'hero_alignment') {
                $out[$key] = in_array($input[$key], ['left','center','right'], true) ? $input[$key] : 'left';
            } elseif ($key === 'hero_height') {
                $out[$key] = in_array($input[$key], ['small','medium','large','full'], true) ? $input[$key] : 'large';
            } elseif (in_array($key, $color_keys, true)) {
                $out[$key] = sanitize_hex_color($input[$key]) ?: $default;
            } else {
                $out[$key] = sanitize_textarea_field($input[$key]);
            }
        }
        return $out;
    }

    private static function current() {
        return IFP_Production_Status::current(true);
    }

    public static function shortcode() {
        $production = self::current();
        if (!$production) return '<div class="ifp-empty">No current production is configured.</div>';
        $s = self::settings();
        ob_start();
        $layout_classes = ['ifp-homepage'];
        if (!empty($s['layout_remove_theme_spacing'])) $layout_classes[] = 'ifp-layout-remove-theme-spacing';
        if (!empty($s['layout_full_width'])) $layout_classes[] = 'ifp-layout-full-width';
        if (!empty($s['layout_hide_page_title'])) $layout_classes[] = 'ifp-layout-hide-page-title';
        if (!empty($s['layout_hide_breadcrumbs'])) $layout_classes[] = 'ifp-layout-hide-breadcrumbs';
        if (!empty($s['layout_flush_header'])) $layout_classes[] = 'ifp-layout-flush-header';
        $homepage_style = implode(';', [
            '--ifp-section-padding-desktop:' . absint($s['section_padding_desktop']) . 'px',
            '--ifp-section-padding-mobile:' . absint($s['section_padding_mobile']) . 'px',
            '--ifp-pathways-section-bg:' . esc_attr($s['pathways_section_bg']),
            '--ifp-participant-section-bg:' . esc_attr($s['participant_section_bg']),
            '--ifp-dates-section-bg:' . esc_attr($s['dates_section_bg']),
            '--ifp-dates-section-text:' . esc_attr($s['dates_section_text']),
            '--ifp-sponsors-section-bg:' . esc_attr($s['sponsors_section_bg']),
            '--ifp-archive-section-bg:' . esc_attr($s['archive_section_bg'])
        ]);
        echo '<div class="'.esc_attr(implode(' ', $layout_classes)).'" style="'.$homepage_style.'">';
        foreach ($s['sections'] as $section) {
            if (empty($s['enabled'][$section])) continue;
            $method = 'render_' . $section;
            if (method_exists(__CLASS__, $method)) self::$method($production, $s);
        }
        echo '</div>';
        return ob_get_clean();
    }

    private static function render_hero($p,$s) {
        $ticket_state = IFP_Production_Pages::ticket_state($p->ID, true);
        $ticket = $ticket_state['status'] === 'active' ? $ticket_state['url'] : '';
        $registration_state = IFP_Production_Pages::registration_state($p->ID);
        $register = $registration_state['status'] === 'open'
            ? (get_post_meta($p->ID,'_ifp_registration_url',true) ?: IFP_Links::get('registration'))
            : '';
        $volunteer = get_post_meta($p->ID,'_ifp_volunteer_url',true) ?: IFP_Links::get('volunteer');
        $tagline = get_post_meta($p->ID,'_ifp_tagline',true);
        $opening = get_post_meta($p->ID,'_ifp_opening_date',true);
        $date_range = IFP_Production_Pages::formatted_range($p->ID);
        $description = trim((string) $p->post_content);
        $description_html = $description !== '' ? apply_filters('the_content', $description) : '';
        $accent = get_post_meta($p->ID,'_ifp_accent_color',true) ?: '#ff8033';
        $secondary = get_post_meta($p->ID,'_ifp_secondary_color',true) ?: '#003b5c';
        $countdown_text = get_post_meta($p->ID,'_ifp_countdown_text_color',true) ?: '#ffffff';
        $panel_color = get_post_meta($p->ID,'_ifp_overlay_color',true) ?: $secondary;
        $panel_opacity_value = get_post_meta($p->ID,'_ifp_panel_overlay_opacity',true);
        $panel_opacity = max(0, min(100, $panel_opacity_value === '' ? 78 : absint($panel_opacity_value))) / 100;
        $image = get_the_post_thumbnail_url($p->ID,'full');
        $logo_id = absint(get_post_meta($p->ID,'_ifp_show_logo_id',true));
        $logo_url = $logo_id ? wp_get_attachment_image_url($logo_id,'full') : '';
        $use_production_countdown_color = !empty($s['hero_use_production_countdown_color']);
        $countdown_background = $use_production_countdown_color ? $secondary : $s['hero_countdown_bg'];
        $countdown_number = $use_production_countdown_color ? $countdown_text : $s['hero_countdown_number_color'];
        $countdown_label = $use_production_countdown_color
            ? self::hex_to_rgba($countdown_text, .78)
            : $s['hero_countdown_label_color'];

        $opacity = max(0, min(100, (int) $s['hero_overlay_opacity'])) / 100;
        $height_class = 'ifp-home-hero--' . sanitize_html_class($s['hero_height']);
        $align_class = 'ifp-home-hero--align-' . sanitize_html_class($s['hero_alignment']);

        $vars = [
            '--ifp-accent' => $accent,
            '--ifp-hero-title' => $s['hero_title_color'],
            '--ifp-hero-eyebrow' => $accent,
            '--ifp-hero-tagline' => $s['hero_tagline_color'],
            '--ifp-hero-overlay' => self::hex_to_rgba($s['hero_overlay_color'], $opacity),
            '--ifp-countdown-eyebrow' => $accent,
            '--ifp-countdown-heading' => $s['hero_countdown_heading_color'],
            '--ifp-countdown-bg' => $countdown_background,
            '--ifp-countdown-number' => $countdown_number,
            '--ifp-countdown-label' => $countdown_label,
            '--ifp-primary-bg' => $accent,
            '--ifp-primary-text' => $s['hero_primary_text'],
            '--ifp-secondary-bg' => $secondary,
            '--ifp-secondary-text' => $s['hero_secondary_text'],
            '--ifp-home-info-panel' => self::hex_to_rgba($panel_color, $panel_opacity),
            '--ifp-home-panel-border' => self::hex_to_rgba($accent, .72),
            '--ifp-logo-width' => absint($s['hero_logo_width']) . 'px',
            '--ifp-logo-max-height' => absint($s['hero_logo_max_height']) . 'px',
            '--ifp-logo-spacing' => intval($s['hero_logo_spacing']) . 'px',
            '--ifp-hero-top-offset' => intval($s['layout_hero_top_offset']) . 'px',
            '--ifp-content-top-padding' => intval($s['layout_content_top_padding']) . 'px',
            '--ifp-content-bottom-padding' => intval($s['layout_content_bottom_padding']) . 'px',
        ];
        if ($image) $vars['--ifp-hero'] = 'url(' . esc_url($image) . ')';

        $style = '';
        foreach ($vars as $name=>$value) $style .= $name . ':' . esc_attr($value) . ';';

        $show_logo = !empty($s['hero_show_logo']) && $logo_url;
        $showcase_class = $show_logo ? 'ifp-home-hero__showcase--has-logo' : 'ifp-home-hero__showcase--single';

        echo '<section class="ifp-home-hero '.esc_attr($height_class.' '.$align_class).'" style="'.$style.'">';
        echo '<div class="ifp-home-overlay"></div><div class="ifp-home-container ifp-home-hero__content">';
        echo '<div class="ifp-home-hero__showcase '.esc_attr($showcase_class).'">';

        if ($show_logo) {
            echo '<div class="ifp-home-hero__brand-panel">';
            echo '<span class="ifp-home-eyebrow">'.esc_html($s['hero_eyebrow']).'</span>';
            echo '<div class="ifp-show-logo"><img src="'.esc_url($logo_url).'" alt="'.esc_attr($p->post_title).'"></div>';
            echo '</div>';
        }

        echo '<div class="ifp-home-hero__info-panel">';
        if (!$show_logo) echo '<span class="ifp-home-eyebrow">'.esc_html($s['hero_eyebrow']).'</span>';
        echo '<h1>'.esc_html($p->post_title).'</h1>';
        if ($tagline) echo '<p class="ifp-home-lead">'.esc_html($tagline).'</p>';
        if ($date_range) echo '<p class="ifp-home-date">'.esc_html($date_range).'</p>';
        if ($description_html) echo '<div class="ifp-home-description">'.wp_kses_post($description_html).'</div>';
        if ($opening) {
            echo '<div class="ifp-home-countdown">';
            if (!empty($s['hero_countdown_show_heading'])) {
                echo '<div class="ifp-countdown-intro">';
                if (!empty($s['hero_countdown_eyebrow'])) {
                    echo '<span class="ifp-countdown-eyebrow">'.esc_html($s['hero_countdown_eyebrow']).'</span>';
                }
                if (!empty($s['hero_countdown_heading'])) {
                    echo '<h2 class="ifp-countdown-heading">'.esc_html($s['hero_countdown_heading']).'</h2>';
                }
                echo '</div>';
            }
            echo do_shortcode('[ifp_countdown]');
            echo '</div>';
        }
        echo '<div class="ifp-actions">';
        if ($ticket) {
            echo '<a class="ifp-button ifp-home-primary" href="'.esc_url($ticket).'">'.esc_html($s['hero_ticket_text']).'</a>';
        } elseif ($ticket_state['status'] === 'pending') {
            echo '<span class="ifp-button ifp-home-primary ifp-button--disabled" aria-disabled="true">Tickets Available '.esc_html($ticket_state['available_label']).'</span>';
        }
        if ($register) {
            echo '<a class="ifp-button ifp-button--secondary ifp-home-secondary" href="'.esc_url($register).'">'.esc_html($s['hero_register_text']).'</a>';
        } elseif ($registration_state['status'] === 'pending') {
            echo '<span class="ifp-button ifp-button--secondary ifp-home-secondary ifp-button--disabled" aria-disabled="true">Registration Opens '.esc_html($registration_state['open_label']).'</span>';
        }
        if ($volunteer) echo '<a class="ifp-button ifp-button--secondary ifp-home-secondary" href="'.esc_url($volunteer).'">'.esc_html($s['hero_volunteer_text']).'</a>';
        echo '</div></div></div></div></section>';
    }

    private static function hex_to_rgba($hex, $alpha) {
        $hex = sanitize_hex_color($hex) ?: '#001f33';
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        $r = hexdec(substr($hex,0,2));
        $g = hexdec(substr($hex,2,2));
        $b = hexdec(substr($hex,4,2));
        return 'rgba('.$r.','.$g.','.$b.','.max(0,min(1,(float)$alpha)).')';
    }

    private static function render_pathways($p,$s) {
        $register = get_post_meta($p->ID,'_ifp_registration_url',true)
            ?: IFP_Links::get('registration')
            ?: get_post_meta($p->ID,'_ifp_participant_hub_url',true)
            ?: IFP_Links::get('participant_hub');
        $ticket = IFP_Production_Pages::active_ticket_url($p->ID, true);
        $support = !empty($s['support_page_id'])
            ? get_permalink(absint($s['support_page_id']))
            : ($s['support_url']
                ?: get_post_meta($p->ID,'_ifp_program_ads_url',true)
                ?: IFP_Links::get('program_ads')
                ?: IFP_Links::get('sponsors'));
        echo '<section class="ifp-home-section ifp-home-section--pathways"><div class="ifp-home-container"><span class="ifp-home-eyebrow">Choose Your Path</span><h2>'.esc_html($s['pathways_heading']).'</h2><div class="ifp-home-paths">';
        self::path_card($s['join_title'],$s['join_text'],$s['join_button'],$register,'join',$s);
        self::path_card($s['watch_title'],$s['watch_text'],$s['watch_button'],$ticket,'watch',$s);
        self::path_card($s['support_title'],$s['support_text'],$s['support_button'],$support,'support',$s);
        echo '</div></div></section>';
    }

    private static function path_card($title,$text,$button,$url,$class,$s) {
        $style = implode(';', [
            '--ifp-path-bg-start:'.esc_attr($s[$class.'_bg_start']),
            '--ifp-path-bg-end:'.esc_attr($s[$class.'_bg_end']),
            '--ifp-path-title:'.esc_attr($s[$class.'_title_color']),
            '--ifp-path-text:'.esc_attr($s[$class.'_text_color']),
            '--ifp-path-link:'.esc_attr($s[$class.'_link_color']),
            '--ifp-path-radius:'.absint($s['pathway_corner_radius']).'px',
            '--ifp-path-height:'.absint($s['pathway_card_height']).'px',
            '--ifp-path-height-mobile:'.absint($s['pathway_card_height_mobile']).'px'
        ]);
        echo '<article class="ifp-home-path ifp-home-path--'.esc_attr($class).'" style="'.$style.'">';
        echo '<h3>'.esc_html($title).'</h3>';
        echo '<div class="ifp-home-path__copy">'.wp_kses_post(wpautop($text)).'</div>';
        if ($url) echo '<a href="'.esc_url($url).'">'.esc_html($button).' →</a>';
        echo '</article>';
    }

    private static function render_participant($p,$s) {
        $hub = get_post_meta($p->ID,'_ifp_participant_hub_url',true) ?: IFP_Links::get('participant_hub');
        echo '<section class="ifp-home-section ifp-home-section--participant"><div class="ifp-home-container ifp-home-two"><div class="ifp-home-card"><span class="ifp-home-eyebrow">Participant Hub</span><h2>'.esc_html($s['participant_heading']).'</h2><div class="ifp-rich-copy">'.wp_kses_post(wpautop($s['participant_text'])).'</div>';
        echo '<div class="ifp-home-resources">'.do_shortcode('[ifp_participant_notices]').do_shortcode('[ifp_participant_resources production_id="'.$p->ID.'"]').'</div>';
        if ($hub) echo '<a class="ifp-button ifp-button--secondary" href="'.esc_url($hub).'">Open Participant Hub</a>';
        echo '</div><div class="ifp-home-card ifp-home-card--accent"><h2>Quick Links</h2>';
        $links = [
            'Rehearsal Schedule'=>get_post_meta($p->ID,'_ifp_rehearsal_url',true) ?: IFP_Links::get('rehearsals'),
            'Costume Information'=>get_post_meta($p->ID,'_ifp_costume_url',true) ?: IFP_Links::get('costumes'),
            'Volunteer'=>get_post_meta($p->ID,'_ifp_volunteer_url',true) ?: IFP_Links::get('volunteer'),
            'Program Advertising'=>get_post_meta($p->ID,'_ifp_program_ads_url',true) ?: IFP_Links::get('program_ads'),
        ];
        echo '<div class="ifp-home-quicklinks">';
        foreach($links as $label=>$url) if($url) echo '<a href="'.esc_url($url).'">'.esc_html($label).' →</a>';
        echo '</div></div></div></section>';
    }

    private static function render_dates($p,$s) {
        $events = new WP_Query([
            'post_type' => 'ifp_event',
            'post_status' => 'publish',
            'posts_per_page' => 50,
            'meta_key' => '_ifp_event_date',
            'orderby' => 'meta_value',
            'order' => 'ASC',
            'meta_query' => ['relation'=>'AND',['key'=>'_ifp_event_featured','value'=>'1'], class_exists('IFP_Relationships') ? IFP_Relationships::clause($p->ID) : ['key'=>'_ifp_production_scope','compare'=>'NOT EXISTS']]
        ]);

        echo '<section class="ifp-home-section ifp-home-section--dates"><div class="ifp-home-container">';
        echo '<span class="ifp-home-eyebrow">Mark Your Calendar</span>';
        echo '<h2>'.esc_html($s['dates_heading']).'</h2>';
        echo '<div class="ifp-home-dates">';

        $count = 0;
        while ($events->have_posts()) {
            $events->the_post();

            $date = get_post_meta(get_the_ID(),'_ifp_event_date',true);
            if (!$date) continue;

            $date_range = IFP_Production_Pages::event_date_range(get_the_ID());
            $time = get_post_meta(get_the_ID(),'_ifp_event_time',true);
            $end_time = get_post_meta(get_the_ID(),'_ifp_event_end_time',true);
            $location = get_post_meta(get_the_ID(),'_ifp_event_location',true);
            $type = get_post_meta(get_the_ID(),'_ifp_event_type',true) ?: 'General';
            $link = get_post_meta(get_the_ID(),'_ifp_event_link',true);

            echo '<div class="ifp-date-card">';
            echo '<span class="ifp-date-type">'.esc_html($type).'</span>';
            echo '<strong>'.esc_html($date_range).'</strong>';
            echo '<span class="ifp-date-title">'.esc_html(get_the_title()).'</span>';

            if ($time) {
                $time_text = wp_date(get_option('time_format'), strtotime($time));
                if ($end_time) {
                    $time_text .= '–' . wp_date(get_option('time_format'), strtotime($end_time));
                }
                echo '<small>'.esc_html($time_text).'</small>';
            }

            if ($location) {
                echo '<small>'.esc_html($location).'</small>';
            }

            if ($link) {
                echo '<a href="'.esc_url($link).'">More information →</a>';
            }

            echo '</div>';
            $count++;
        }
        wp_reset_postdata();

        if (!$count) {
            $open = get_post_meta($p->ID,'_ifp_opening_date',true);
            $close = get_post_meta($p->ID,'_ifp_closing_date',true);

            if ($open) {
                echo '<div><strong>'.esc_html(wp_date(get_option('date_format'),strtotime($open))).'</strong><span>Opening Performance</span></div>';
            }

            if ($close) {
                echo '<div><strong>'.esc_html(wp_date(get_option('date_format'),strtotime($close))).'</strong><span>Closing Performance</span></div>';
            }
        }

        echo '</div></div></section>';
    }

    private static function render_venue($p,$s) {
        $venue_name = get_post_meta($p->ID, '_ifp_venue_name', true);
        $venue_address = get_post_meta($p->ID, '_ifp_venue_address', true);
        $parking_notes = get_post_meta($p->ID, '_ifp_parking_notes', true);
        $venue_image_id = absint(get_post_meta($p->ID, '_ifp_venue_image_id', true));
        if (!$venue_name && !$venue_address && !$parking_notes && !$venue_image_id) return;

        $accent = sanitize_hex_color(get_post_meta($p->ID, '_ifp_accent_color', true)) ?: '#ff8033';
        $secondary = sanitize_hex_color(get_post_meta($p->ID, '_ifp_secondary_color', true)) ?: '#003b5c';
        $section_style = '--ifp-accent:'.esc_attr($accent).';--ifp-home-venue-heading:'.esc_attr($secondary).';';

        echo '<section class="ifp-home-section ifp-home-section--venue" style="'.$section_style.'">';
        echo '<div class="ifp-home-container">';
        echo '<span class="ifp-home-eyebrow">Production Details</span><h2>Plan Your Visit</h2>';
        echo '<article class="ifp-home-venue-card'.($venue_image_id ? ' has-image' : '').'">';
        echo '<div class="ifp-home-venue-copy"><span class="ifp-home-venue-eyebrow">Venue</span>';
        if ($venue_name) echo '<h3>'.esc_html($venue_name).'</h3>';
        if ($venue_address) echo '<p>'.esc_html($venue_address).'</p>';
        if ($parking_notes) echo '<div class="ifp-home-venue-notes">'.wp_kses_post(wpautop($parking_notes)).'</div>';
        echo '</div>';
        if ($venue_image_id) {
            echo '<div class="ifp-home-venue-media">'.wp_get_attachment_image($venue_image_id, 'full', false, ['class'=>'ifp-home-venue-image']).'</div>';
        }
        echo '</article></div></section>';
    }

    private static function render_sponsors($p,$s) {
        $style = implode(';', [
            '--ifp-sponsor-logo-radius:'.absint($s['sponsor_logo_radius']).'px',
            '--ifp-sponsor-border-width:'.absint($s['sponsor_logo_border_width']).'px',
            '--ifp-sponsor-border-color:'.esc_attr($s['sponsor_logo_border_color']),
            '--ifp-sponsor-card-bg:'.esc_attr($s['sponsor_card_background']),
            '--ifp-sponsor-tagline:'.esc_attr($s['sponsor_tagline_color'])
        ]);
        $show = !empty($s['sponsor_show_tagline']) ? '1' : '0';
        echo '<section class="ifp-home-section ifp-home-section--sponsors"><div class="ifp-home-container"><span class="ifp-home-eyebrow">Community Support</span><h2>'.esc_html($s['sponsors_heading']).'</h2><div class="ifp-home-sponsor-wrap" style="'.$style.'">'.do_shortcode('[ifp_sponsors show_tagline="'.$show.'" group_by_level="1" production_id="'.$p->ID.'"]').'</div></div></section>';
    }

    private static function production_cards($query, $eyebrow, $heading, $class, $show_logo = false) {
        if (!$query->have_posts()) return;
        echo '<section class="ifp-home-section '.esc_attr($class).'"><div class="ifp-home-container"><span class="ifp-home-eyebrow">'.esc_html($eyebrow).'</span><h2>'.esc_html($heading).'</h2><div class="ifp-home-archive">';
        while ($query->have_posts()) {
            $query->the_post();
            $production_id = get_the_ID();
            $logo_id = $show_logo ? absint(get_post_meta($production_id, '_ifp_show_logo_id', true)) : 0;
            $logo_url = $logo_id ? wp_get_attachment_image_url($logo_id, 'large') : '';
            $has_art = has_post_thumbnail($production_id) || $logo_url;

            echo '<article><a href="'.esc_url(get_permalink($production_id)).'">';
            if ($has_art) {
                echo '<div class="ifp-home-production-art'.(has_post_thumbnail($production_id) ? ' has-featured-image' : '').'">';
                if (has_post_thumbnail($production_id)) {
                    echo get_the_post_thumbnail($production_id, 'medium_large', ['class' => 'ifp-home-production-artwork']);
                }
                if ($logo_url) {
                    echo '<span class="ifp-home-production-logo"><img src="'.esc_url($logo_url).'" alt="'.esc_attr(get_the_title($production_id).' logo').'"></span>';
                }
                echo '</div>';
            }
            echo '<h3>'.esc_html(get_the_title($production_id)).'</h3><p>'.esc_html(get_the_excerpt($production_id)).'</p></a></article>';
        }
        wp_reset_postdata();
        echo '</div></div></section>';
    }

    private static function render_upcoming($p,$s) {
        $q = new WP_Query([
            'post_type'=>'ifp_production','post_status'=>'publish','posts_per_page'=>(int)$s['upcoming_limit'],'post__not_in'=>[$p->ID],
            'meta_key'=>'_ifp_opening_date','orderby'=>'meta_value','order'=>'ASC',
            'meta_query'=>[['key'=>'_ifp_production_status','value'=>'upcoming']]
        ]);
        self::production_cards($q, 'Coming Next', $s['upcoming_heading'], 'ifp-home-section--upcoming', true);
    }

    private static function render_archive($p,$s) {
        $q = new WP_Query([
            'post_type'=>'ifp_production','post_status'=>'publish','posts_per_page'=>(int)$s['archive_limit'],'post__not_in'=>[$p->ID],
            'meta_query'=>[['key'=>'_ifp_production_status','value'=>['completed','archived'],'compare'=>'IN']],
            'meta_key'=>'_ifp_closing_date','orderby'=>'meta_value','order'=>'DESC'
        ]);
        self::production_cards($q, 'Memory Lane', $s['archive_heading'], 'ifp-home-section--archive');
    }
}
