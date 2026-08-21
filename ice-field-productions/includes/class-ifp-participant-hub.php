<?php
if (!defined('ABSPATH')) exit;

class IFP_Participant_Hub {
    const OPTION = 'ifp_participant_hub_settings';

    public static function init() {
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_shortcode('ifp_participant_hub', [__CLASS__, 'shortcode']);
        add_filter('option_page_capability_ifp_participant_hub_group', [__CLASS__, 'settings_capability']);
    }

    public static function settings_capability($capability = '') {
        return 'edit_posts';
    }

    public static function defaults() {
        return [
            'heading' => 'Participant Hub',
            'introduction' => 'Schedules, updates, resources, and important information for current show participants.',

            'show_header' => '1',
            'show_notices' => '1',
            'show_timeline' => '1',
            'show_resources' => '1',
            'show_contacts' => '1',
            'show_quick_links' => '1',
            'show_countdown' => '1',
            'show_next_rehearsal' => '1',

            'hero_logo_position' => 'above',
            'hero_logo_width' => 220,
            'hero_use_featured_background' => '0',
            'hero_background_position' => 'center center',
            'hero_overlay_color' => '#003b5c',
            'hero_overlay_opacity' => 72,
            'hero_padding' => 42,
            'hero_radius' => 0,

            'countdown_eyebrow' => 'Opening Night',
            'next_date_eyebrow' => 'Next Important Date',
            'next_date_mode' => 'any',
            'status_card_background' => '#ffffff',
            'status_card_opacity' => 14,
            'hub_card_radius' => 20,
            'hub_link_radius' => 999,

            'show_notice_published_date' => '1',
            'show_notice_updated_date' => '1',
            'show_resource_published_date' => '1',
            'show_resource_updated_date' => '1',

            'notices_heading' => 'Announcements',
            'timeline_heading' => 'Upcoming Dates',
            'resources_heading' => 'Participant Resources',
            'contacts_heading' => 'Who to Contact',
            'featured_heading' => 'Featured Resources',

            'empty_notices' => 'There are no active participant announcements.',
            'empty_resources' => 'Participant resources will appear here as they are published.',
            'empty_contacts' => 'Production contacts will appear here as they are added.',

            'contact_categories' => "Production Team\nAdministration\nCostumes\nVolunteers\nTechnical\nFront Desk\nOther",

            'accent_color' => '#ff8033',
            'secondary_color' => '#003b5c',
            'section_spacing' => 54,
            'content_width' => 1180,
        ];
    }


    public static function settings() {
        return wp_parse_args(get_option(self::OPTION, []), self::defaults());
    }


    public static function contact_categories() {
        $settings = self::settings();
        $raw = (string) ($settings['contact_categories'] ?? '');
        $lines = preg_split('/\r\n|\r|\n/', $raw);
        $lines = array_values(array_unique(array_filter(array_map('trim', $lines))));
        return $lines ?: ['Production Team','Administration','Costumes','Volunteers','Technical','Front Desk','Other'];
    }

    public static function register_settings() {
        register_setting('ifp_participant_hub_group', self::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [__CLASS__, 'sanitize_settings'],
            'default' => self::defaults(),
        ]);
    }

    public static function sanitize_settings($input) {
        $defaults = self::defaults();
        $out = [];

        $checkboxes = [
            'show_header','show_notices','show_timeline','show_resources',
            'show_contacts','show_quick_links','show_countdown','show_next_rehearsal',
            'show_notice_published_date','show_notice_updated_date',
            'show_resource_published_date','show_resource_updated_date',
            'hero_use_featured_background'
        ];

        $colors = [
            'accent_color','secondary_color','hero_overlay_color',
            'status_card_background'
        ];

        $numbers = [
            'hero_logo_width' => [60, 600],
            'hero_overlay_opacity' => [0, 100],
            'hero_padding' => [0, 180],
            'hero_radius' => [0, 120],
            'status_card_opacity' => [0, 100],
            'hub_card_radius' => [0, 120],
            'hub_link_radius' => [0, 999],
            'section_spacing' => [0, 180],
            'content_width' => [720, 1800],
        ];

        foreach ($defaults as $key => $default) {
            if (in_array($key, $checkboxes, true)) {
                $out[$key] = !empty($input[$key]) ? '1' : '0';
            } elseif (in_array($key, $colors, true)) {
                $out[$key] = sanitize_hex_color($input[$key] ?? '') ?: $default;
            } elseif (isset($numbers[$key])) {
                [$min, $max] = $numbers[$key];
                $out[$key] = max($min, min($max, absint($input[$key] ?? $default)));
            } elseif ($key === 'hero_logo_position') {
                $value = sanitize_text_field($input[$key] ?? $default);
                $out[$key] = in_array($value, ['above','left','right','hidden'], true) ? $value : $default;
            } elseif ($key === 'hero_background_position') {
                $value = sanitize_text_field($input[$key] ?? $default);
                $allowed = ['center center','center top','center bottom','left center','right center'];
                $out[$key] = in_array($value, $allowed, true) ? $value : $default;
            } elseif ($key === 'next_date_mode') {
                $value = sanitize_text_field($input[$key] ?? $default);
                $allowed = ['any','rehearsal','performance','costume','custom'];
                $out[$key] = in_array($value, $allowed, true) ? $value : $default;
            } elseif ($key === 'introduction') {
                $out[$key] = wp_kses_post($input[$key] ?? '');
            } elseif ($key === 'contact_categories') {
                $lines = preg_split('/\r\n|\r|\n/', (string) ($input[$key] ?? ''));
                $lines = array_values(array_unique(array_filter(array_map('sanitize_text_field', $lines))));
                $out[$key] = implode("\n", $lines);
            } else {
                $out[$key] = sanitize_text_field($input[$key] ?? $default);
            }
        }

        return $out;
    }


    public static function current_production() {
        return IFP_Production_Status::current(true);
    }

    public static function render_admin_page() {
        if (!current_user_can('edit_posts')) return;

        $s = self::settings();
        $hub_url = IFP_Links::get('participant_hub');
        ?>
        <div class="wrap ifp-dashboard ifp-hub-settings">
            <div class="ifp-page-header">
                <div>
                    <p class="ifp-kicker">Participants</p>
                    <h1>Participant Hub</h1>
                    <p class="ifp-lead">Manage the participant experience through organized settings for the hero, status cards, content sections, contacts, and appearance.</p>
                </div>
                <div class="ifp-header-actions">
                    <?php if ($hub_url): ?>
                        <a class="button button-primary" href="<?php echo esc_url($hub_url); ?>" target="_blank" rel="noopener">Preview Participant Hub</a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="ifp-module-links">
                <a class="ifp-module-link" href="<?php echo esc_url(admin_url('edit.php?post_type=ifp_notice')); ?>">
                    <span class="dashicons dashicons-megaphone"></span>
                    <strong>Notices</strong>
                    <small>Announcements and reminders</small>
                </a>
                <a class="ifp-module-link" href="<?php echo esc_url(admin_url('edit.php?post_type=ifp_resource')); ?>">
                    <span class="dashicons dashicons-media-document"></span>
                    <strong>Resources</strong>
                    <small>Schedules, guides, music, and files</small>
                </a>
                <a class="ifp-module-link" href="<?php echo esc_url(admin_url('edit.php?post_type=ifp_event')); ?>">
                    <span class="dashicons dashicons-calendar-alt"></span>
                    <strong>Important Dates</strong>
                    <small>Fittings, rehearsals, and performances</small>
                </a>
                <a class="ifp-module-link" href="<?php echo esc_url(admin_url('edit.php?post_type=ifp_contact')); ?>">
                    <span class="dashicons dashicons-businessperson"></span>
                    <strong>Contacts</strong>
                    <small>Production team and coordinators</small>
                </a>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields('ifp_participant_hub_group'); ?>

                <div class="ifp-hub-settings-stack">

                    <details class="ifp-settings-accordion" open>
                        <summary>
                            <span class="dashicons dashicons-format-image"></span>
                            <span><strong>Hero</strong><small>Heading, logo, background image, overlay, and spacing</small></span>
                        </summary>
                        <div class="ifp-settings-accordion__body">
                            <div class="ifp-field-grid">
                                <p><label><strong>Page Heading</strong><br>
                                    <input class="widefat" type="text" name="<?php echo esc_attr(self::OPTION); ?>[heading]" value="<?php echo esc_attr($s['heading']); ?>">
                                </label></p>

                                <p><label><strong>Show Logo Position</strong><br>
                                    <select class="widefat" name="<?php echo esc_attr(self::OPTION); ?>[hero_logo_position]">
                                        <option value="above" <?php selected($s['hero_logo_position'], 'above'); ?>>Above</option>
                                        <option value="left" <?php selected($s['hero_logo_position'], 'left'); ?>>Left</option>
                                        <option value="right" <?php selected($s['hero_logo_position'], 'right'); ?>>Right</option>
                                        <option value="hidden" <?php selected($s['hero_logo_position'], 'hidden'); ?>>Hidden</option>
                                    </select>
                                </label></p>

                                <p><label><strong>Logo Width (px)</strong><br>
                                    <input class="widefat" type="number" min="60" max="600" name="<?php echo esc_attr(self::OPTION); ?>[hero_logo_width]" value="<?php echo esc_attr($s['hero_logo_width']); ?>">
                                </label></p>

                                <p><label><strong>Hero Padding (px)</strong><br>
                                    <input class="widefat" type="number" min="0" max="180" name="<?php echo esc_attr(self::OPTION); ?>[hero_padding]" value="<?php echo esc_attr($s['hero_padding']); ?>">
                                </label></p>

                                <p><label><strong>Hero Corner Radius (px)</strong><br>
                                    <input class="widefat" type="number" min="0" max="120" name="<?php echo esc_attr(self::OPTION); ?>[hero_radius]" value="<?php echo esc_attr($s['hero_radius']); ?>">
                                </label></p>

                                <p><label><strong>Background Position</strong><br>
                                    <select class="widefat" name="<?php echo esc_attr(self::OPTION); ?>[hero_background_position]">
                                        <option value="center center" <?php selected($s['hero_background_position'], 'center center'); ?>>Center</option>
                                        <option value="center top" <?php selected($s['hero_background_position'], 'center top'); ?>>Top</option>
                                        <option value="center bottom" <?php selected($s['hero_background_position'], 'center bottom'); ?>>Bottom</option>
                                        <option value="left center" <?php selected($s['hero_background_position'], 'left center'); ?>>Left</option>
                                        <option value="right center" <?php selected($s['hero_background_position'], 'right center'); ?>>Right</option>
                                    </select>
                                </label></p>
                            </div>

                            <p><label>
                                <input type="hidden" name="<?php echo esc_attr(self::OPTION); ?>[hero_use_featured_background]" value="0">
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[hero_use_featured_background]" value="1" <?php checked($s['hero_use_featured_background'], '1'); ?>>
                                Use the current production Featured Image as the blue hero-card background
                            </label></p>

                            <div class="ifp-field-grid">
                                <p><label><strong>Overlay Color</strong><br>
                                    <input class="widefat ifp-color-input" type="color" name="<?php echo esc_attr(self::OPTION); ?>[hero_overlay_color]" value="<?php echo esc_attr($s['hero_overlay_color']); ?>">
                                </label></p>

                                <p><label><strong>Overlay Opacity (%)</strong><br>
                                    <input class="widefat" type="number" min="0" max="100" name="<?php echo esc_attr(self::OPTION); ?>[hero_overlay_opacity]" value="<?php echo esc_attr($s['hero_overlay_opacity']); ?>">
                                </label></p>
                            </div>

                            <p><label><strong>Introduction</strong></label></p>
                            <?php
                            wp_editor($s['introduction'], 'ifp_participant_hub_intro', [
                                'textarea_name' => self::OPTION.'[introduction]',
                                'textarea_rows' => 6,
                                'media_buttons' => false,
                                'teeny' => true,
                            ]);
                            ?>
                        </div>
                    </details>

                    <details class="ifp-settings-accordion">
                        <summary>
                            <span class="dashicons dashicons-clock"></span>
                            <span><strong>Status Cards</strong><small>Countdown and next Important Date wording and appearance</small></span>
                        </summary>
                        <div class="ifp-settings-accordion__body">
                            <div class="ifp-field-grid">
                                <p><label>
                                    <input type="hidden" name="<?php echo esc_attr(self::OPTION); ?>[show_countdown]" value="0">
                                    <input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[show_countdown]" value="1" <?php checked($s['show_countdown'], '1'); ?>>
                                    Show opening-night countdown
                                </label></p>

                                <p><label>
                                    <input type="hidden" name="<?php echo esc_attr(self::OPTION); ?>[show_next_rehearsal]" value="0">
                                    <input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[show_next_rehearsal]" value="1" <?php checked($s['show_next_rehearsal'], '1'); ?>>
                                    Show next Important Date
                                </label></p>

                                <p><label><strong>Countdown Eyebrow</strong><br>
                                    <input class="widefat" type="text" name="<?php echo esc_attr(self::OPTION); ?>[countdown_eyebrow]" value="<?php echo esc_attr($s['countdown_eyebrow']); ?>">
                                </label></p>

                                <p><label><strong>Next Date Eyebrow</strong><br>
                                    <input class="widefat" type="text" name="<?php echo esc_attr(self::OPTION); ?>[next_date_eyebrow]" value="<?php echo esc_attr($s['next_date_eyebrow']); ?>">
                                </label></p>

                                <p><label><strong>Next Date Selection</strong><br>
                                    <select class="widefat" name="<?php echo esc_attr(self::OPTION); ?>[next_date_mode]">
                                        <option value="any" <?php selected($s['next_date_mode'], 'any'); ?>>Next Important Date of any type</option>
                                        <option value="rehearsal" <?php selected($s['next_date_mode'], 'rehearsal'); ?>>Next rehearsal or dress rehearsal</option>
                                        <option value="performance" <?php selected($s['next_date_mode'], 'performance'); ?>>Next performance</option>
                                        <option value="costume" <?php selected($s['next_date_mode'], 'costume'); ?>>Next costume-related date</option>
                                    </select>
                                </label></p>

                                <p><label><strong>Status Card Background</strong><br>
                                    <input class="widefat ifp-color-input" type="color" name="<?php echo esc_attr(self::OPTION); ?>[status_card_background]" value="<?php echo esc_attr($s['status_card_background']); ?>">
                                </label></p>

                                <p><label><strong>Status Card Background Opacity (%)</strong><br>
                                    <input class="widefat" type="number" min="0" max="100" name="<?php echo esc_attr(self::OPTION); ?>[status_card_opacity]" value="<?php echo esc_attr($s['status_card_opacity']); ?>">
                                </label></p>

                                <p><label><strong>Status / Main Card Radius (px)</strong><br>
                                    <input class="widefat" type="number" min="0" max="120" name="<?php echo esc_attr(self::OPTION); ?>[hub_card_radius]" value="<?php echo esc_attr($s['hub_card_radius']); ?>">
                                </label></p>
                            </div>
                        </div>
                    </details>

                    <details class="ifp-settings-accordion">
                        <summary>
                            <span class="dashicons dashicons-admin-links"></span>
                            <span><strong>Quick Links</strong><small>Visibility and button shape</small></span>
                        </summary>
                        <div class="ifp-settings-accordion__body">
                            <p><label>
                                <input type="hidden" name="<?php echo esc_attr(self::OPTION); ?>[show_quick_links]" value="0">
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[show_quick_links]" value="1" <?php checked($s['show_quick_links'], '1'); ?>>
                                Show quick links
                            </label></p>

                            <p><label><strong>Quick-Link Corner Radius (px)</strong><br>
                                <input class="small-text" type="number" min="0" max="999" name="<?php echo esc_attr(self::OPTION); ?>[hub_link_radius]" value="<?php echo esc_attr($s['hub_link_radius']); ?>">
                            </label></p>

                            <p class="description">Quick-link labels and destinations continue to come from <strong>Productions → Site Links</strong>.</p>
                        </div>
                    </details>

                    <details class="ifp-settings-accordion">
                        <summary>
                            <span class="dashicons dashicons-megaphone"></span>
                            <span><strong>Notices</strong><small>Heading, visibility, and date metadata</small></span>
                        </summary>
                        <div class="ifp-settings-accordion__body">
                            <div class="ifp-field-grid">
                                <p><label>
                                    <input type="hidden" name="<?php echo esc_attr(self::OPTION); ?>[show_notices]" value="0">
                                    <input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[show_notices]" value="1" <?php checked($s['show_notices'], '1'); ?>>
                                    Show notices
                                </label></p>

                                <p><label><strong>Section Heading</strong><br>
                                    <input class="widefat" type="text" name="<?php echo esc_attr(self::OPTION); ?>[notices_heading]" value="<?php echo esc_attr($s['notices_heading']); ?>">
                                </label></p>

                                <p><label>
                                    <input type="hidden" name="<?php echo esc_attr(self::OPTION); ?>[show_notice_published_date]" value="0">
                                    <input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[show_notice_published_date]" value="1" <?php checked($s['show_notice_published_date'], '1'); ?>>
                                    Show posted date
                                </label></p>

                                <p><label>
                                    <input type="hidden" name="<?php echo esc_attr(self::OPTION); ?>[show_notice_updated_date]" value="0">
                                    <input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[show_notice_updated_date]" value="1" <?php checked($s['show_notice_updated_date'], '1'); ?>>
                                    Show updated date
                                </label></p>
                            </div>

                            <p><label><strong>Empty-State Message</strong><br>
                                <input class="widefat" type="text" name="<?php echo esc_attr(self::OPTION); ?>[empty_notices]" value="<?php echo esc_attr($s['empty_notices']); ?>">
                            </label></p>
                        </div>
                    </details>

                    <details class="ifp-settings-accordion">
                        <summary>
                            <span class="dashicons dashicons-media-document"></span>
                            <span><strong>Resources</strong><small>Heading, metadata, and featured resources</small></span>
                        </summary>
                        <div class="ifp-settings-accordion__body">
                            <div class="ifp-field-grid">
                                <p><label>
                                    <input type="hidden" name="<?php echo esc_attr(self::OPTION); ?>[show_resources]" value="0">
                                    <input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[show_resources]" value="1" <?php checked($s['show_resources'], '1'); ?>>
                                    Show resources
                                </label></p>

                                <p><label><strong>Section Heading</strong><br>
                                    <input class="widefat" type="text" name="<?php echo esc_attr(self::OPTION); ?>[resources_heading]" value="<?php echo esc_attr($s['resources_heading']); ?>">
                                </label></p>

                                <p><label><strong>Featured Heading</strong><br>
                                    <input class="widefat" type="text" name="<?php echo esc_attr(self::OPTION); ?>[featured_heading]" value="<?php echo esc_attr($s['featured_heading']); ?>">
                                </label></p>

                                <p><label>
                                    <input type="hidden" name="<?php echo esc_attr(self::OPTION); ?>[show_resource_published_date]" value="0">
                                    <input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[show_resource_published_date]" value="1" <?php checked($s['show_resource_published_date'], '1'); ?>>
                                    Show posted date
                                </label></p>

                                <p><label>
                                    <input type="hidden" name="<?php echo esc_attr(self::OPTION); ?>[show_resource_updated_date]" value="0">
                                    <input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[show_resource_updated_date]" value="1" <?php checked($s['show_resource_updated_date'], '1'); ?>>
                                    Show updated date
                                </label></p>
                            </div>

                            <p><label><strong>Empty-State Message</strong><br>
                                <input class="widefat" type="text" name="<?php echo esc_attr(self::OPTION); ?>[empty_resources]" value="<?php echo esc_attr($s['empty_resources']); ?>">
                            </label></p>
                        </div>
                    </details>

                    <details class="ifp-settings-accordion">
                        <summary>
                            <span class="dashicons dashicons-calendar-alt"></span>
                            <span><strong>Important Dates</strong><small>Timeline visibility and heading</small></span>
                        </summary>
                        <div class="ifp-settings-accordion__body">
                            <div class="ifp-field-grid">
                                <p><label>
                                    <input type="hidden" name="<?php echo esc_attr(self::OPTION); ?>[show_timeline]" value="0">
                                    <input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[show_timeline]" value="1" <?php checked($s['show_timeline'], '1'); ?>>
                                    Show Important Dates timeline
                                </label></p>

                                <p><label><strong>Section Heading</strong><br>
                                    <input class="widefat" type="text" name="<?php echo esc_attr(self::OPTION); ?>[timeline_heading]" value="<?php echo esc_attr($s['timeline_heading']); ?>">
                                </label></p>
                            </div>
                        </div>
                    </details>

                    <details class="ifp-settings-accordion">
                        <summary>
                            <span class="dashicons dashicons-businessperson"></span>
                            <span><strong>Contacts</strong><small>Heading, categories, and empty state</small></span>
                        </summary>
                        <div class="ifp-settings-accordion__body">
                            <div class="ifp-field-grid">
                                <p><label>
                                    <input type="hidden" name="<?php echo esc_attr(self::OPTION); ?>[show_contacts]" value="0">
                                    <input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[show_contacts]" value="1" <?php checked($s['show_contacts'], '1'); ?>>
                                    Show contacts
                                </label></p>

                                <p><label><strong>Section Heading</strong><br>
                                    <input class="widefat" type="text" name="<?php echo esc_attr(self::OPTION); ?>[contacts_heading]" value="<?php echo esc_attr($s['contacts_heading']); ?>">
                                </label></p>
                            </div>

                            <p><label><strong>Available Categories — one per line</strong><br>
                                <textarea class="widefat" rows="9" name="<?php echo esc_attr(self::OPTION); ?>[contact_categories]"><?php echo esc_textarea($s['contact_categories']); ?></textarea>
                            </label></p>

                            <p><label><strong>Empty-State Message</strong><br>
                                <input class="widefat" type="text" name="<?php echo esc_attr(self::OPTION); ?>[empty_contacts]" value="<?php echo esc_attr($s['empty_contacts']); ?>">
                            </label></p>
                        </div>
                    </details>

                    <details class="ifp-settings-accordion">
                        <summary>
                            <span class="dashicons dashicons-art"></span>
                            <span><strong>Global Appearance</strong><small>Colors, spacing, width, and section visibility</small></span>
                        </summary>
                        <div class="ifp-settings-accordion__body">
                            <div class="ifp-field-grid">
                                <p><label>
                                    <input type="hidden" name="<?php echo esc_attr(self::OPTION); ?>[show_header]" value="0">
                                    <input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[show_header]" value="1" <?php checked($s['show_header'], '1'); ?>>
                                    Show Participant Hub hero
                                </label></p>

                                <p><label><strong>Accent Color</strong><br>
                                    <input class="widefat ifp-color-input" type="color" name="<?php echo esc_attr(self::OPTION); ?>[accent_color]" value="<?php echo esc_attr($s['accent_color']); ?>">
                                </label></p>

                                <p><label><strong>Secondary Color</strong><br>
                                    <input class="widefat ifp-color-input" type="color" name="<?php echo esc_attr(self::OPTION); ?>[secondary_color]" value="<?php echo esc_attr($s['secondary_color']); ?>">
                                </label></p>

                                <p><label><strong>Section Spacing (px)</strong><br>
                                    <input class="widefat" type="number" min="0" max="180" name="<?php echo esc_attr(self::OPTION); ?>[section_spacing]" value="<?php echo esc_attr($s['section_spacing']); ?>">
                                </label></p>

                                <p><label><strong>Content Width (px)</strong><br>
                                    <input class="widefat" type="number" min="720" max="1800" name="<?php echo esc_attr(self::OPTION); ?>[content_width]" value="<?php echo esc_attr($s['content_width']); ?>">
                                </label></p>
                            </div>
                        </div>
                    </details>

                </div>

                <section class="ifp-admin-card ifp-shortcode-instructions">
                    <h2>Participant Hub Page</h2>
                    <p>Create or open the WordPress page selected under <strong>Productions → Site Links → Participant Hub</strong>, then add one Shortcode block:</p>
                    <div class="ifp-shortcode-box"><code>[ifp_participant_hub]</code></div>
                </section>

                <?php submit_button('Save Participant Hub'); ?>
            </form>
        </div>
        <?php
    }


    public static function shortcode($atts = []) {
        $atts = shortcode_atts([
            'production_id' => 0,
        ], $atts, 'ifp_participant_hub');

        $production_id = absint($atts['production_id']);
        $p = $production_id ? get_post($production_id) : self::current_production();

        if ($p && $p->post_type !== 'ifp_production') {
            $p = null;
        }

        if ($p && get_post_status($p) !== 'publish' && !current_user_can('edit_post', $p->ID)) {
            $p = null;
        }

        if (!$p) return '<div class="ifp-empty">No current production is configured.</div>';

        $production_id = absint($p->ID);

        $s = self::settings();

        $featured_background = '';
        if ($s['hero_use_featured_background'] === '1') {
            $featured_background = get_the_post_thumbnail_url($p->ID, 'full') ?: '';
        }

        $style = implode(';', [
            '--ifp-hub-accent:'.esc_attr($s['accent_color']),
            '--ifp-hub-secondary:'.esc_attr($s['secondary_color']),
            '--ifp-hub-card-radius:'.absint($s['hub_card_radius']).'px',
            '--ifp-hub-link-radius:'.absint($s['hub_link_radius']).'px',
            '--ifp-hub-overlay-color:'.esc_attr($s['hero_overlay_color']),
            '--ifp-hub-overlay-opacity:'.max(0, min(100, absint($s['hero_overlay_opacity']))) / 100,
            '--ifp-hub-background-image:'.($featured_background ? 'url('.esc_url($featured_background).')' : 'none'),
            '--ifp-hub-background-position:'.esc_attr($s['hero_background_position']),
            '--ifp-hub-logo-width:'.absint($s['hero_logo_width']).'px',
            '--ifp-hub-hero-padding:'.absint($s['hero_padding']).'px',
            '--ifp-hub-hero-radius:'.absint($s['hero_radius']).'px',
            '--ifp-hub-status-bg:'.esc_attr($s['status_card_background']),
            '--ifp-hub-status-opacity:'.max(0, min(100, absint($s['status_card_opacity']))) / 100,
            '--ifp-hub-section-spacing:'.absint($s['section_spacing']).'px',
            '--ifp-hub-content-width:'.absint($s['content_width']).'px'
        ]);

        $classes = [
            'ifp-participant-hub',
            'ifp-hub-logo--'.sanitize_html_class($s['hero_logo_position'])
        ];

        if ($featured_background) {
            $classes[] = 'ifp-participant-hub--has-background';
        }

        ob_start();
        echo '<div class="'.esc_attr(implode(' ', $classes)).'" style="'.$style.'">';

        if ($s['show_header'] === '1') self::render_header($p, $s, $production_id);
        if ($s['show_notices'] === '1') self::render_notices($s, $production_id);
        if ($s['show_timeline'] === '1') self::render_timeline($s, $production_id);
        if ($s['show_resources'] === '1') self::render_resources($s, $production_id);
        if ($s['show_contacts'] === '1') self::render_contacts($s, $production_id);

        echo '</div>';
        return ob_get_clean();
    }

    private static function render_header($p, $s, $production_id) {
        $logo_id = absint(get_post_meta($p->ID,'_ifp_show_logo_id',true));
        $logo = $logo_id ? wp_get_attachment_image_url($logo_id,'large') : '';
        $tagline = get_post_meta($p->ID,'_ifp_tagline',true);
        $opening = get_post_meta($p->ID,'_ifp_opening_date',true);
        $next = self::next_important_date($s['next_date_mode'], $production_id);

        echo '<section class="ifp-hub-header">';

        echo '<div class="ifp-hub-header__identity">';
        echo '<div class="ifp-hub-header__identity-grid">';

        if ($logo && $s['hero_logo_position'] !== 'hidden') {
            echo '<div class="ifp-hub-logo-wrap">';
            echo '<img class="ifp-hub-logo" src="'.esc_url($logo).'" alt="'.esc_attr($p->post_title).'">';
            echo '</div>';
        }

        echo '<div class="ifp-hub-header__copy">';
        if (!$logo) {
            echo '<span class="ifp-hub-kicker">'.esc_html($p->post_title).'</span>';
        }
        echo '<h1>'.esc_html($s['heading']).'</h1>';
        if ($tagline) echo '<p class="ifp-hub-tagline">'.esc_html($tagline).'</p>';
        echo '<div class="ifp-hub-intro">'.wp_kses_post(wpautop($s['introduction'])).'</div>';
        echo '</div>';

        echo '</div>';
        echo '</div>';

        echo '<div class="ifp-hub-header__status">';
        if ($s['show_countdown'] === '1' && $opening) {
            echo '<div class="ifp-hub-status-card"><span>'.esc_html($s['countdown_eyebrow']).'</span>'.do_shortcode('[ifp_countdown production_id="'.absint($production_id).'"]').'</div>';
        }

        if ($s['show_next_rehearsal'] === '1' && $next) {
            echo '<div class="ifp-hub-status-card ifp-hub-next">';
            echo '<span>'.esc_html($s['next_date_eyebrow']).'</span>';
            echo '<strong>'.esc_html($next['title']).'</strong>';
            echo '<p>'.esc_html($next['date']);
            if ($next['time']) echo ' • '.esc_html($next['time']);
            echo '</p>';
            if ($next['location']) echo '<small>'.esc_html($next['location']).'</small>';
            echo '</div>';
        }
        echo '</div>';

        if ($s['show_quick_links'] === '1') {
            $links = [
                'Registration' => get_post_meta($production_id, '_ifp_registration_url', true),
                'Schedule' => IFP_Links::get('rehearsals'),
                'Costumes' => IFP_Links::get('costumes'),
                'Volunteer' => get_post_meta($production_id, '_ifp_volunteer_url', true) ?: IFP_Links::get('volunteer'),
                'Program Ads' => IFP_Links::get('program_ads'),
                'Contact' => IFP_Links::get('contact'),
            ];

            echo '<nav class="ifp-hub-quick-links" aria-label="Participant quick links">';
            foreach ($links as $label => $url) {
                if (!$url) continue;
                echo '<a href="'.esc_url($url).'">'.esc_html($label).'</a>';
            }
            echo '</nav>';
        }

        echo '</section>';
    }


    private static function active_notices($production_id) {
        $today = current_time('Y-m-d');
        $q = new WP_Query([
            'post_type' => 'ifp_notice',
            'post_status' => 'publish',
            'posts_per_page' => 50,
            'orderby' => ['date' => 'DESC'],
            'meta_query' => class_exists('IFP_Relationships') ? IFP_Relationships::clause($production_id) : [],
        ]);

        $rows = [];
        while ($q->have_posts()) {
            $q->the_post();
            $id = get_the_ID();
            $starts = get_post_meta($id,'_ifp_notice_starts',true);
            $expires = get_post_meta($id,'_ifp_notice_expires',true);
            if ($starts && $starts > $today) continue;
            if ($expires && $expires < $today) continue;

            $rows[] = [
                'id' => $id,
                'sticky' => get_post_meta($id,'_ifp_notice_sticky',true) === '1',
                'priority' => get_post_meta($id,'_ifp_notice_priority',true) ?: 'Information',
                'icon' => get_post_meta($id,'_ifp_notice_icon',true),
                'published' => get_post_time('U', true, $id),
                'updated' => get_post_modified_time('U', true, $id),
            ];
        }
        wp_reset_postdata();

        usort($rows, function($a, $b) {
            if ($a['sticky'] === $b['sticky']) return $b['id'] <=> $a['id'];
            return $a['sticky'] ? -1 : 1;
        });
        return $rows;
    }

    private static function render_notices($s, $production_id) {
        $rows = self::active_notices($production_id);
        echo '<section class="ifp-hub-section ifp-hub-notices">';
        echo '<div class="ifp-hub-section-heading"><span>Latest Updates</span><h2>'.esc_html($s['notices_heading']).'</h2></div>';

        if (!$rows) {
            echo '<div class="ifp-hub-empty">'.esc_html($s['empty_notices']).'</div>';
        } else {
            echo '<div class="ifp-hub-alerts">';
            foreach ($rows as $row) {
                $id = $row['id'];
                $type = sanitize_title($row['priority']);
                $icons = [
                    'information' => 'ℹ',
                    'success' => '✓',
                    'reminder' => '⏰',
                    'important' => '!',
                    'urgent' => '⚠',
                ];
                $icon = $row['icon'] ?: ($icons[$type] ?? 'ℹ');

                echo '<article class="ifp-hub-alert ifp-hub-alert--'.esc_attr($type).'">';
                echo '<div class="ifp-hub-alert__icon">'.esc_html($icon).'</div>';
                echo '<div class="ifp-hub-alert__body">';
                echo '<span class="ifp-hub-alert__type">'.esc_html($row['priority']).'</span>';
                echo '<h3>'.esc_html(get_the_title($id)).'</h3>';
                echo '<div>'.wp_kses_post(wpautop(get_post_field('post_content',$id))).'</div>';

                $date_parts = [];
                if ($s['show_notice_published_date'] === '1') {
                    $date_parts[] = 'Posted '.get_the_date(get_option('date_format'), $id);
                }
                if ($s['show_notice_updated_date'] === '1') {
                    $date_parts[] = 'Updated '.get_the_modified_date(get_option('date_format'), $id);
                }
                if ($date_parts) {
                    echo '<p class="ifp-content-dates">'.esc_html(implode(' • ', $date_parts)).'</p>';
                }

                echo '</div></article>';
            }
            echo '</div>';
        }
        echo '</section>';
    }

    private static function upcoming_events($production_id) {
        $today = current_time('Y-m-d');
        $q = new WP_Query([
            'post_type' => 'ifp_event',
            'post_status' => 'publish',
            'posts_per_page' => 50,
            'meta_key' => '_ifp_event_date',
            'orderby' => 'meta_value',
            'order' => 'ASC',
            'meta_query' => [
                'relation' => 'AND',
                ['key'=>'_ifp_event_featured','value'=>'1'],
                [
                    'relation' => 'OR',
                    ['key'=>'_ifp_event_date','value'=>$today,'compare'=>'>=','type'=>'DATE'],
                    ['key'=>'_ifp_event_end_date','value'=>$today,'compare'=>'>=','type'=>'DATE'],
                ],
                class_exists('IFP_Relationships') ? IFP_Relationships::clause($production_id) : ['key'=>'_ifp_production_scope','compare'=>'NOT EXISTS'],
            ]
        ]);

        $rows = [];
        while ($q->have_posts()) {
            $q->the_post();
            $id = get_the_ID();
            $rows[] = [
                'id' => $id,
                'date' => get_post_meta($id,'_ifp_event_date',true),
                'end_date' => get_post_meta($id,'_ifp_event_end_date',true),
                'time' => get_post_meta($id,'_ifp_event_time',true),
                'end_time' => get_post_meta($id,'_ifp_event_end_time',true),
                'location' => get_post_meta($id,'_ifp_event_location',true),
                'type' => get_post_meta($id,'_ifp_event_type',true) ?: 'General',
                'link' => get_post_meta($id,'_ifp_event_link',true),
            ];
        }
        wp_reset_postdata();
        return $rows;
    }

    private static function next_important_date($mode = 'any', $production_id = 0) {
        foreach (self::upcoming_events($production_id) as $row) {
            $type = strtolower((string) $row['type']);

            if ($mode === 'rehearsal' && !in_array($row['type'], ['Rehearsal','Dress Rehearsal'], true)) {
                continue;
            }

            if ($mode === 'performance' && $row['type'] !== 'Performance') {
                continue;
            }

            if ($mode === 'costume' && strpos($type, 'costume') === false) {
                continue;
            }

            $time = $row['time'] ? wp_date(get_option('time_format'), strtotime($row['time'])) : '';
            if ($time && $row['end_time']) {
                $time .= '–' . wp_date(get_option('time_format'), strtotime($row['end_time']));
            }

            return [
                'title' => get_the_title($row['id']),
                'date' => IFP_Production_Pages::event_date_range($row['id'], 'l, M j'),
                'time' => $time,
                'location' => $row['location'],
            ];
        }

        return null;
    }

    private static function render_timeline($s, $production_id) {
        $events = self::upcoming_events($production_id);
        echo '<section class="ifp-hub-section ifp-hub-timeline-section">';
        echo '<div class="ifp-hub-section-heading"><span>Mark Your Calendar</span><h2>'.esc_html($s['timeline_heading']).'</h2></div>';

        if (!$events) {
            echo '<div class="ifp-hub-empty">No upcoming participant dates have been published.</div>';
        } else {
            echo '<div class="ifp-hub-timeline">';
            $month = '';
            foreach ($events as $row) {
                $event_month = wp_date('F Y', strtotime($row['date']));
                if ($event_month !== $month) {
                    $month = $event_month;
                    echo '<h3 class="ifp-hub-timeline__month">'.esc_html($month).'</h3>';
                }

                $time = '';
                if ($row['time']) {
                    $time = wp_date(get_option('time_format'), strtotime($row['time']));
                    if ($row['end_time']) $time .= '–'.wp_date(get_option('time_format'), strtotime($row['end_time']));
                }

                echo '<article class="ifp-hub-timeline__item">';
                echo '<div class="ifp-hub-timeline__date"><strong>'.esc_html(wp_date('j', strtotime($row['date']))).'</strong><span>'.esc_html(wp_date('D', strtotime($row['date']))).'</span></div>';
                echo '<div class="ifp-hub-timeline__content">';
                echo '<span class="ifp-hub-timeline__type">'.esc_html($row['type']).'</span>';
                echo '<h4>'.esc_html(get_the_title($row['id'])).'</h4>';
                if (!empty($row['end_date']) && $row['end_date'] !== $row['date']) {
                    echo '<p>'.esc_html(IFP_Production_Pages::event_date_range($row['id'], 'M j, Y')).'</p>';
                }
                if ($time) echo '<p>'.esc_html($time).'</p>';
                if ($row['location']) echo '<p>'.esc_html($row['location']).'</p>';
                if ($row['link']) echo '<a href="'.esc_url($row['link']).'">More information →</a>';
                echo '</div></article>';
            }
            echo '</div>';
        }
        echo '</section>';
    }

    private static function resource_destination($id) {
        $source = get_post_meta($id,'_ifp_resource_source',true);
        $page_id = absint(get_post_meta($id,'_ifp_resource_page_id',true));
        $media_id = absint(get_post_meta($id,'_ifp_resource_media_id',true));
        $url = get_post_meta($id,'_ifp_resource_url',true);

        if (!$source) {
            if ($media_id) $source = 'media';
            elseif ($page_id) $source = 'page';
            else $source = 'url';
        }

        if ($source === 'media' && $media_id) {
            return wp_get_attachment_url($media_id) ?: '';
        }

        if ($source === 'page' && $page_id) {
            return get_permalink($page_id) ?: '';
        }

        return $url;
    }


    private static function resources($production_id) {
        $q = new WP_Query([
            'post_type' => 'ifp_resource',
            'post_status' => 'publish',
            'posts_per_page' => 100,
            'orderby' => 'title',
            'order' => 'ASC',
            'meta_query' => class_exists('IFP_Relationships') ? IFP_Relationships::clause($production_id) : [],
        ]);

        $groups = [];
        while ($q->have_posts()) {
            $q->the_post();
            $id = get_the_ID();
            $type = get_post_meta($id,'_ifp_resource_type',true) ?: 'General';
            $featured = get_post_meta($id,'_ifp_resource_featured',true) === '1' || $type === 'Featured';

            $row = [
                'id' => $id,
                'type' => $type,
                'featured' => $featured,
                'url' => self::resource_destination($id),
                'button' => get_post_meta($id,'_ifp_resource_button_text',true) ?: 'Open Resource',
                'password' => get_post_meta($id,'_ifp_resource_password_label',true) === '1',
                'source' => get_post_meta($id,'_ifp_resource_source',true),
                'media_id' => absint(get_post_meta($id,'_ifp_resource_media_id',true)),
                'published' => get_post_time('U', true, $id),
                'updated' => get_post_modified_time('U', true, $id),
            ];

            if ($featured) $groups['Featured'][] = $row;
            if ($type !== 'Featured') $groups[$type][] = $row;
        }
        wp_reset_postdata();
        return $groups;
    }

    private static function render_resource_card($row, $s) {
        $id = $row['id'];
        $icons = [
            'Handbook' => '📘',
            'Schedule' => '📅',
            'Rehearsal' => '⛸',
            'Costume' => '👗',
            'Music' => '♫',
            'Parent Info' => 'ℹ',
            'Volunteer' => '♥',
            'Forms' => '✎',
            'Staff' => '🔒',
            'Downloads' => '↓',
            'External Link' => '↗',
            'General' => '•',
            'Featured' => '★',
        ];
        $icon = $icons[$row['type']] ?? '•';

        echo '<article class="ifp-hub-resource">';
        echo '<div class="ifp-hub-resource__icon">'.esc_html($icon).'</div>';
        echo '<div class="ifp-hub-resource__content">';
        echo '<span>'.esc_html($row['type']).'</span>';
        echo '<h3>'.esc_html(get_the_title($id)).'</h3>';
        $content = get_post_field('post_excerpt',$id) ?: get_post_field('post_content',$id);
        if ($content) echo '<div>'.wp_kses_post(wpautop($content)).'</div>';

        if (!empty($row['media_id'])) {
            $file_path = get_attached_file($row['media_id']);
            $extension = $file_path ? strtoupper((string) pathinfo($file_path, PATHINFO_EXTENSION)) : 'FILE';
            $size = ($file_path && file_exists($file_path)) ? size_format(filesize($file_path), 1) : '';
            echo '<small class="ifp-resource-file-meta">'.esc_html(trim($extension.($size ? ' • '.$size : ''))).'</small>';
        }

        $resource_dates = [];
        if ($s['show_resource_published_date'] === '1') {
            $resource_dates[] = 'Posted '.get_the_date(get_option('date_format'), $id);
        }
        if ($s['show_resource_updated_date'] === '1') {
            $resource_dates[] = 'Updated '.get_the_modified_date(get_option('date_format'), $id);
        }
        if ($resource_dates) {
            echo '<p class="ifp-content-dates">'.esc_html(implode(' • ', $resource_dates)).'</p>';
        }

        if ($row['password']) echo '<small class="ifp-password-label">Password required</small>';
        if ($row['url']) echo '<a class="ifp-hub-resource__button" href="'.esc_url($row['url']).'">'.esc_html($row['button']).'</a>';
        echo '</div></article>';
    }

    private static function render_resources($s, $production_id) {
        $groups = self::resources($production_id);
        echo '<section class="ifp-hub-section ifp-hub-resources-section">';

        if (!empty($groups['Featured'])) {
            echo '<div class="ifp-hub-section-heading"><span>Start Here</span><h2>'.esc_html($s['featured_heading']).'</h2></div>';
            echo '<div class="ifp-hub-featured-resources">';
            foreach ($groups['Featured'] as $row) self::render_resource_card($row, $s);
            echo '</div>';
            unset($groups['Featured']);
        }

        echo '<div class="ifp-hub-section-heading"><span>Documents & Links</span><h2>'.esc_html($s['resources_heading']).'</h2></div>';

        if (!$groups) {
            echo '<div class="ifp-hub-empty">'.esc_html($s['empty_resources']).'</div>';
        } else {
            echo '<div class="ifp-hub-resource-groups">';
            foreach ($groups as $type => $rows) {
                echo '<section class="ifp-hub-resource-group">';
                echo '<h3>'.esc_html($type).'</h3>';
                echo '<div class="ifp-hub-resource-grid">';
                foreach ($rows as $row) self::render_resource_card($row, $s);
                echo '</div></section>';
            }
            echo '</div>';
        }
        echo '</section>';
    }

    private static function render_contacts($s, $production_id) {
        $q = new WP_Query([
            'post_type' => 'ifp_contact',
            'post_status' => 'publish',
            'posts_per_page' => 50,
            'meta_key' => '_ifp_contact_order',
            'orderby' => ['meta_value_num' => 'ASC', 'title' => 'ASC'],
            'meta_query' => ['relation'=>'AND',['key'=>'_ifp_contact_active','value'=>'1'], class_exists('IFP_Relationships') ? IFP_Relationships::clause($production_id) : ['key'=>'_ifp_production_scope','compare'=>'NOT EXISTS']]
        ]);

        echo '<section class="ifp-hub-section ifp-hub-contacts">';
        echo '<div class="ifp-hub-section-heading"><span>Questions?</span><h2>'.esc_html($s['contacts_heading']).'</h2></div>';

        if (!$q->have_posts()) {
            echo '<div class="ifp-hub-empty">'.esc_html($s['empty_contacts']).'</div>';
        } else {
            echo '<div class="ifp-hub-contact-grid">';
            while ($q->have_posts()) {
                $q->the_post();
                $id = get_the_ID();
                $role = get_post_meta($id,'_ifp_contact_role',true);
                $email = get_post_meta($id,'_ifp_contact_email',true);
                $phone = get_post_meta($id,'_ifp_contact_phone',true);
                $category = get_post_meta($id,'_ifp_contact_category',true);

                echo '<article class="ifp-hub-contact">';
                if (has_post_thumbnail($id)) {
                    echo '<div class="ifp-hub-contact__photo">'.get_the_post_thumbnail($id,'thumbnail').'</div>';
                } else {
                    echo '<div class="ifp-hub-contact__initial">'.esc_html(mb_substr(get_the_title($id),0,1)).'</div>';
                }
                echo '<div class="ifp-hub-contact__body">';
                if ($category) echo '<span>'.esc_html($category).'</span>';
                echo '<h3>'.esc_html(get_the_title($id)).'</h3>';
                if ($role) echo '<p>'.esc_html($role).'</p>';
                if (get_the_content()) echo '<div class="ifp-hub-contact__note">'.wp_kses_post(wpautop(get_the_content())).'</div>';
                echo '<div class="ifp-hub-contact__actions">';
                if ($email) echo '<a href="mailto:'.esc_attr(antispambot($email)).'">Email</a>';
                if ($phone) echo '<a href="tel:'.esc_attr(preg_replace('/[^0-9+]/','',$phone)).'">'.esc_html($phone).'</a>';
                echo '</div></div></article>';
            }
            echo '</div>';
            wp_reset_postdata();
        }

        echo '</section>';
    }
}
