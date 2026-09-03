<?php
if (!defined('ABSPATH')) exit;

class IFPROG_Admin {
    const OPTION = 'ifprog_display_settings';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
        add_action('admin_notices', [__CLASS__, 'season_lifecycle_notice']);
        add_action('admin_post_ifprog_current_season', [__CLASS__, 'mark_season_current']);
        add_action('admin_post_ifprog_complete_season', [__CLASS__, 'complete_season']);

        add_filter('manage_ifprog_season_posts_columns', [__CLASS__, 'season_columns']);
        add_action('manage_ifprog_season_posts_custom_column', [__CLASS__, 'season_column'], 10, 2);
        add_filter('manage_ifprog_level_posts_columns', [__CLASS__, 'level_columns']);
        add_action('manage_ifprog_level_posts_custom_column', [__CLASS__, 'level_column'], 10, 2);
        add_filter('manage_ifprog_program_posts_columns', [__CLASS__, 'program_columns']);
        add_action('manage_ifprog_program_posts_custom_column', [__CLASS__, 'program_column'], 10, 2);
        add_action('restrict_manage_posts', [__CLASS__, 'program_filters']);
        add_action('pre_get_posts', [__CLASS__, 'apply_program_filters']);
        add_action('ifprog_group_add_form_fields', [__CLASS__, 'group_order_add_field']);
        add_action('ifprog_group_edit_form_fields', [__CLASS__, 'group_order_edit_field']);
        add_action('created_ifprog_group', [__CLASS__, 'save_group_order']);
        add_action('edited_ifprog_group', [__CLASS__, 'save_group_order']);
        add_filter('manage_edit-ifprog_group_columns', [__CLASS__, 'group_columns']);
        add_filter('manage_ifprog_group_custom_column', [__CLASS__, 'group_column'], 10, 3);
    }

    public static function defaults() {
        return [
            'accent_color' => '#1473a8',
            'secondary_color' => '#0b2942',
            'surface_color' => '#ffffff',
            'catalog_background_color' => '#f3f7f9',
            'level_background_color' => '#ffffff',
            'class_background_color' => '#ffffff',
            'text_color' => '#263b48',
            'muted_text_color' => '#60717d',
            'border_color' => '#d7e1e7',
            'season_border_color' => '#79acc9',
            'program_group_border_color' => '#d7e1e7',
            'level_border_color' => '#d7e1e7',
            'class_border_color' => '#d7e1e7',
            'button_background_color' => '#1473a8',
            'button_text_color' => '#ffffff',
            'button_hover_background_color' => '#ff8033',
            'button_hover_text_color' => '#ffffff',
            'density' => 'compact',
            'group_gap' => 12,
            'section_spacing_top' => 0,
            'section_spacing_bottom' => 0,
            'corner_radius' => 14,
            'season_corner_radius' => 14,
            'level_corner_radius' => 14,
            'class_corner_radius' => 0,
            'season_border_width' => 1,
            'program_group_border_width' => 1,
            'level_border_width' => 1,
            'class_border_width' => 1,
            'base_font_size' => 16,
            'session_heading_size' => 26,
            'group_heading_size' => 22,
            'level_heading_size' => 20,
            'class_heading_size' => 17,
            'show_season_descriptions' => 1,
            'show_group_descriptions' => 1,
            'show_level_descriptions' => 1,
            'show_program_descriptions' => 1,
            'open_label' => 'Registration Open',
            'coming_soon_label' => 'Coming Soon',
            'closed_label' => 'Registration Closed',
            'default_button_label' => 'Register Now',
            'empty_message' => 'Registration information will be available soon.',
        ];
    }

    public static function add_default_settings() {
        add_option(self::OPTION, self::defaults(), '', false);
    }

    public static function settings() {
        $saved = (array) get_option(self::OPTION, []);
        $settings = wp_parse_args($saved, self::defaults());
        if (!array_key_exists('level_background_color', $saved)) {
            $settings['level_background_color'] = $settings['surface_color'];
        }
        if (!array_key_exists('class_background_color', $saved)) {
            $settings['class_background_color'] = $settings['surface_color'];
        }
        if (!array_key_exists('season_border_color', $saved)) {
            $settings['season_border_color'] = self::mix_hex_colors(
                $settings['accent_color'],
                $settings['border_color'],
                48
            );
        }
        if (!array_key_exists('program_group_border_color', $saved)) {
            $settings['program_group_border_color'] = $settings['border_color'];
        }
        if (!array_key_exists('level_border_color', $saved)) {
            $settings['level_border_color'] = $settings['border_color'];
        }
        if (!array_key_exists('class_border_color', $saved)) {
            $settings['class_border_color'] = $settings['border_color'];
        }
        if (!array_key_exists('level_corner_radius', $saved)) {
            $settings['level_corner_radius'] = $settings['corner_radius'];
        }
        return $settings;
    }

    public static function register_settings() {
        register_setting('ifprog_display_group', self::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [__CLASS__, 'sanitize_settings'],
            'default' => self::defaults(),
        ]);
    }

    public static function sanitize_settings($input) {
        $defaults = self::defaults();
        $density = sanitize_key($input['density'] ?? $defaults['density']);
        if (!in_array($density, ['compact','comfortable','spacious'], true)) $density = $defaults['density'];
        return [
            'accent_color' => sanitize_hex_color($input['accent_color'] ?? '') ?: $defaults['accent_color'],
            'secondary_color' => sanitize_hex_color($input['secondary_color'] ?? '') ?: $defaults['secondary_color'],
            'surface_color' => sanitize_hex_color($input['surface_color'] ?? '') ?: $defaults['surface_color'],
            'catalog_background_color' => sanitize_hex_color($input['catalog_background_color'] ?? '') ?: $defaults['catalog_background_color'],
            'level_background_color' => sanitize_hex_color($input['level_background_color'] ?? '') ?: $defaults['level_background_color'],
            'class_background_color' => sanitize_hex_color($input['class_background_color'] ?? '') ?: $defaults['class_background_color'],
            'text_color' => sanitize_hex_color($input['text_color'] ?? '') ?: $defaults['text_color'],
            'muted_text_color' => sanitize_hex_color($input['muted_text_color'] ?? '') ?: $defaults['muted_text_color'],
            'border_color' => sanitize_hex_color($input['border_color'] ?? '') ?: $defaults['border_color'],
            'season_border_color' => sanitize_hex_color($input['season_border_color'] ?? '') ?: $defaults['season_border_color'],
            'program_group_border_color' => sanitize_hex_color($input['program_group_border_color'] ?? '') ?: $defaults['program_group_border_color'],
            'level_border_color' => sanitize_hex_color($input['level_border_color'] ?? '') ?: $defaults['level_border_color'],
            'class_border_color' => sanitize_hex_color($input['class_border_color'] ?? '') ?: $defaults['class_border_color'],
            'button_background_color' => sanitize_hex_color($input['button_background_color'] ?? '') ?: $defaults['button_background_color'],
            'button_text_color' => sanitize_hex_color($input['button_text_color'] ?? '') ?: $defaults['button_text_color'],
            'button_hover_background_color' => sanitize_hex_color($input['button_hover_background_color'] ?? '') ?: $defaults['button_hover_background_color'],
            'button_hover_text_color' => sanitize_hex_color($input['button_hover_text_color'] ?? '') ?: $defaults['button_hover_text_color'],
            'density' => $density,
            'group_gap' => self::bounded_int($input['group_gap'] ?? '', $defaults['group_gap'], 4, 32),
            'section_spacing_top' => self::bounded_int($input['section_spacing_top'] ?? '', $defaults['section_spacing_top'], 0, 200),
            'section_spacing_bottom' => self::bounded_int($input['section_spacing_bottom'] ?? '', $defaults['section_spacing_bottom'], 0, 200),
            'corner_radius' => self::bounded_int($input['corner_radius'] ?? '', $defaults['corner_radius'], 0, 40),
            'season_corner_radius' => self::bounded_int($input['season_corner_radius'] ?? '', $defaults['season_corner_radius'], 0, 40),
            'level_corner_radius' => self::bounded_int($input['level_corner_radius'] ?? '', $defaults['level_corner_radius'], 0, 40),
            'class_corner_radius' => self::bounded_int($input['class_corner_radius'] ?? '', $defaults['class_corner_radius'], 0, 40),
            'season_border_width' => self::bounded_int($input['season_border_width'] ?? '', $defaults['season_border_width'], 0, 8),
            'program_group_border_width' => self::bounded_int($input['program_group_border_width'] ?? '', $defaults['program_group_border_width'], 0, 8),
            'level_border_width' => self::bounded_int($input['level_border_width'] ?? '', $defaults['level_border_width'], 0, 8),
            'class_border_width' => self::bounded_int($input['class_border_width'] ?? '', $defaults['class_border_width'], 0, 4),
            'base_font_size' => self::bounded_int($input['base_font_size'] ?? '', $defaults['base_font_size'], 13, 20),
            'session_heading_size' => self::bounded_int($input['session_heading_size'] ?? '', $defaults['session_heading_size'], 18, 38),
            'group_heading_size' => self::bounded_int($input['group_heading_size'] ?? '', $defaults['group_heading_size'], 17, 32),
            'level_heading_size' => self::bounded_int($input['level_heading_size'] ?? '', $defaults['level_heading_size'], 16, 30),
            'class_heading_size' => self::bounded_int($input['class_heading_size'] ?? '', $defaults['class_heading_size'], 14, 24),
            'show_season_descriptions' => !empty($input['show_season_descriptions']) ? 1 : 0,
            'show_group_descriptions' => !empty($input['show_group_descriptions']) ? 1 : 0,
            'show_level_descriptions' => !empty($input['show_level_descriptions']) ? 1 : 0,
            'show_program_descriptions' => !empty($input['show_program_descriptions']) ? 1 : 0,
            'open_label' => sanitize_text_field($input['open_label'] ?? $defaults['open_label']),
            'coming_soon_label' => sanitize_text_field($input['coming_soon_label'] ?? $defaults['coming_soon_label']),
            'closed_label' => sanitize_text_field($input['closed_label'] ?? $defaults['closed_label']),
            'default_button_label' => sanitize_text_field($input['default_button_label'] ?? $defaults['default_button_label']),
            'empty_message' => sanitize_text_field($input['empty_message'] ?? $defaults['empty_message']),
        ];
    }

    public static function menu() {
        add_menu_page(
            'Ice & Field Programming',
            'Programming',
            'edit_posts',
            'ifprog-dashboard',
            [__CLASS__, 'dashboard'],
            'dashicons-welcome-learn-more',
            25
        );
        add_submenu_page('ifprog-dashboard', 'Programming Dashboard', 'Dashboard', 'edit_posts', 'ifprog-dashboard', [__CLASS__, 'dashboard']);
        add_submenu_page('ifprog-dashboard', 'Seasons', 'Seasons', 'edit_posts', 'edit.php?post_type=ifprog_season');
        add_submenu_page('ifprog-dashboard', 'Program Groups', 'Program Groups', 'manage_categories', 'edit-tags.php?taxonomy=ifprog_group&post_type=ifprog_level');
        add_submenu_page('ifprog-dashboard', 'Levels', 'Levels', 'edit_posts', 'edit.php?post_type=ifprog_level');
        add_submenu_page('ifprog-dashboard', 'Add Level', 'Add Level', 'edit_posts', 'post-new.php?post_type=ifprog_level');
        add_submenu_page('ifprog-dashboard', 'Programs', 'Programs', 'edit_posts', 'edit.php?post_type=ifprog_program');
        add_submenu_page('ifprog-dashboard', 'Add Program', 'Add Program', 'edit_posts', 'post-new.php?post_type=ifprog_program');
        add_submenu_page('ifprog-dashboard', 'Sports', 'Sports', 'manage_categories', 'edit-tags.php?taxonomy=ifprog_sport&post_type=ifprog_program');
        add_submenu_page('ifprog-dashboard', 'Formats', 'Formats', 'manage_categories', 'edit-tags.php?taxonomy=ifprog_format&post_type=ifprog_program');
        add_submenu_page('ifprog-dashboard', 'Program Categories', 'Program Categories', 'manage_categories', 'edit-tags.php?taxonomy=ifprog_category&post_type=ifprog_program');
        add_submenu_page('ifprog-dashboard', 'Season Discovery & Sync', 'Season Discovery', 'manage_options', 'ifprog-preview', ['IFPROG_Preview', 'page']);
        add_submenu_page('ifprog-dashboard', 'Monitoring', 'Monitoring', 'manage_options', 'ifprog-monitoring', ['IFPROG_Monitoring', 'settings_page']);
        add_submenu_page('ifprog-dashboard', 'Activity Log', 'Activity Log', 'manage_options', 'ifprog-audit', ['IFPROG_Monitoring', 'audit_page']);
        add_submenu_page('ifprog-dashboard', 'Dash Connection', 'Dash Connection', 'manage_options', 'ifprog-dash', ['IFPROG_Dash', 'page']);
        add_submenu_page('ifprog-dashboard', 'Display Settings', 'Settings', 'manage_options', 'ifprog-settings', [__CLASS__, 'settings_page']);
    }

    public static function assets($hook) {
        $screen = get_current_screen();
        $is_programming_screen = $screen && in_array($screen->post_type, ['ifprog_season','ifprog_level','ifprog_program'], true);
        if (!$is_programming_screen && strpos((string) $hook, 'ifprog') === false) return;

        wp_enqueue_style('ifprog-admin', IFPROG_URL . 'assets/admin.css', [], IFPROG_VERSION);
        wp_enqueue_script('ifprog-admin', IFPROG_URL . 'assets/admin.js', [], IFPROG_VERSION, true);
        wp_localize_script('ifprog-admin', 'ifprogPreviewBatch', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ifprog_preview_batch'),
        ]);
    }

    public static function season_lifecycle_notice() {
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'edit-ifprog_season' || !current_user_can('edit_posts')) return;

        $season_ids = get_posts([
            'post_type' => 'ifprog_season',
            'post_status' => ['publish','draft','pending','private','future'],
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_query' => [[
                'key' => IFPROG_Status::SEASON_STATUS_KEY,
                'value' => ['current','upcoming','completed'],
                'compare' => 'IN',
            ]],
        ]);
        $season_ids = array_map('absint', $season_ids);
        $ready = array_values(array_filter($season_ids, function($season_id) {
            return IFPROG_Status::season_status($season_id) !== 'current' &&
                IFPROG_Status::effective_season_status($season_id) === 'current';
        }));
        $overdue = array_values(array_filter($season_ids, function($season_id) {
            return IFPROG_Status::season_status($season_id) !== 'completed' &&
                IFPROG_Status::effective_season_status($season_id) === 'completed';
        }));
        if (!$ready && !$overdue) return;
        ?>
        <div class="notice notice-warning">
            <?php if ($ready): ?>
                <p><strong>Registration has opened for <?php echo esc_html(count($ready) === 1 ? 'this Season' : 'these Seasons'); ?>.</strong> The public catalog now treats <?php echo esc_html(count($ready) === 1 ? 'it' : 'them'); ?> as Current. Should the lifecycle status be updated?</p>
                <ul class="ifprog-completion-list">
                    <?php foreach ($ready as $season_id):
                        $open_timestamp = IFPROG_Status::timestamp(get_post_meta($season_id, '_ifprog_season_registration_open', true));
                        $current_url = wp_nonce_url(
                            add_query_arg([
                                'action' => 'ifprog_current_season',
                                'season_id' => $season_id,
                            ], admin_url('admin-post.php')),
                            'ifprog_current_season_' . $season_id
                        );
                        ?>
                        <li>
                            <strong><?php echo esc_html(get_the_title($season_id)); ?></strong>
                            <?php if ($open_timestamp): ?>
                                <span>— registration opened <?php echo esc_html(wp_date(get_option('date_format'), $open_timestamp)); ?></span>
                            <?php endif; ?>
                            <a class="button button-small" href="<?php echo esc_url($current_url); ?>">Mark Current</a>
                            <a href="<?php echo esc_url(get_edit_post_link($season_id)); ?>">Edit Season</a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if ($overdue): ?>
                <p><strong>Registration has closed for <?php echo esc_html(count($overdue) === 1 ? 'this Season' : 'these Seasons'); ?>.</strong> The public catalog now treats <?php echo esc_html(count($overdue) === 1 ? 'it' : 'them'); ?> as Completed. Should the lifecycle status be updated?</p>
                <ul class="ifprog-completion-list">
                    <?php foreach ($overdue as $season_id):
                        $close_timestamp = IFPROG_Status::timestamp(get_post_meta($season_id, '_ifprog_season_registration_close', true));
                        $complete_url = wp_nonce_url(
                            add_query_arg([
                                'action' => 'ifprog_complete_season',
                                'season_id' => $season_id,
                            ], admin_url('admin-post.php')),
                            'ifprog_complete_season_' . $season_id
                        );
                        ?>
                        <li>
                            <strong><?php echo esc_html(get_the_title($season_id)); ?></strong>
                            <?php if ($close_timestamp): ?>
                                <span>— registration closed <?php echo esc_html(wp_date(get_option('date_format'), $close_timestamp)); ?></span>
                            <?php endif; ?>
                            <a class="button button-small" href="<?php echo esc_url($complete_url); ?>">Mark Completed</a>
                            <a href="<?php echo esc_url(get_edit_post_link($season_id)); ?>">Edit Season</a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function mark_season_current() {
        $season_id = absint($_GET['season_id'] ?? 0);
        if (!$season_id || get_post_type($season_id) !== 'ifprog_season') wp_die('Season not found.');
        if (!current_user_can('edit_post', $season_id)) wp_die('You are not allowed to edit this Season.');
        check_admin_referer('ifprog_current_season_' . $season_id);

        IFPROG_Status::set_season_status($season_id, 'current');
        wp_safe_redirect(add_query_arg([
            'post_type' => 'ifprog_season',
            'ifprog_current' => 1,
        ], admin_url('edit.php')));
        exit;
    }

    public static function complete_season() {
        $season_id = absint($_GET['season_id'] ?? 0);
        if (!$season_id || get_post_type($season_id) !== 'ifprog_season') wp_die('Season not found.');
        if (!current_user_can('edit_post', $season_id)) wp_die('You are not allowed to edit this Season.');
        check_admin_referer('ifprog_complete_season_' . $season_id);

        IFPROG_Status::set_season_status($season_id, 'completed');
        wp_safe_redirect(add_query_arg([
            'post_type' => 'ifprog_season',
            'ifprog_completed' => 1,
        ], admin_url('edit.php')));
        exit;
    }

    public static function dashboard() {
        if (!current_user_can('edit_posts')) return;

        $current_ids = IFPROG_Status::current_season_ids(false);
        $current_seasons = array_values(array_filter(array_map('get_post', $current_ids)));
        $season_counts = wp_count_posts('ifprog_season');
        $level_counts = wp_count_posts('ifprog_level');
        $program_counts = wp_count_posts('ifprog_program');
        $published_program_ids = get_posts([
            'post_type' => 'ifprog_program',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
        ]);
        $states = ['coming_soon' => 0, 'open' => 0, 'closed' => 0];
        foreach ($published_program_ids as $program_id) {
            $state = IFPROG_Status::program_state($program_id);
            if (isset($states[$state])) $states[$state]++;
        }
        ?>
        <div class="wrap ifprog-admin">
            <div class="ifprog-page-header">
                <div>
                    <p class="ifprog-kicker">Ice &amp; Field Programming</p>
                    <h1>Programming Dashboard</h1>
                    <p class="ifprog-lead">Manage Seasons, Program Groups, Levels, classes, leagues, camps, clinics, and public registration information from one place.</p>
                </div>
                <div class="ifprog-actions">
                    <a class="button button-primary button-hero" href="<?php echo esc_url(admin_url('post-new.php?post_type=ifprog_program')); ?>">Add Program</a>
                    <a class="button" href="<?php echo esc_url(admin_url('post-new.php?post_type=ifprog_season')); ?>">Add Season</a>
                </div>
            </div>

            <?php if ($current_seasons): ?>
                <div class="ifprog-current-list">
                    <?php foreach ($current_seasons as $current): ?>
                        <section class="ifprog-current">
                            <div>
                                <span class="ifprog-status ifprog-status--open">Current Season</span>
                                <h2><?php echo esc_html($current->post_title); ?></h2>
                                <p><?php echo esc_html(self::season_date_range($current->ID) ?: 'Season dates have not been entered.'); ?></p>
                            </div>
                            <div class="ifprog-actions">
                                <a class="button button-primary" href="<?php echo esc_url(get_edit_post_link($current->ID)); ?>">Edit Season</a>
                                <a class="button" href="<?php echo esc_url(add_query_arg(['post_type' => 'ifprog_level', 'ifprog_season' => $current->ID], admin_url('edit.php'))); ?>">View Levels</a>
                                <a class="button" href="<?php echo esc_url(add_query_arg(['post_type' => 'ifprog_program', 'ifprog_season' => $current->ID], admin_url('edit.php'))); ?>">View Programs</a>
                            </div>
                        </section>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <section class="ifprog-empty">
                    <span class="dashicons dashicons-calendar-alt"></span>
                    <div>
                        <h2>Choose the current programming seasons</h2>
                        <p>Create one or more Seasons and mark each active programming area Current.</p>
                    </div>
                    <a class="button button-primary" href="<?php echo esc_url(admin_url('post-new.php?post_type=ifprog_season')); ?>">Create Season</a>
                </section>
            <?php endif; ?>

            <div class="ifprog-stat-grid">
                <a class="ifprog-stat" href="<?php echo esc_url(admin_url('edit.php?post_type=ifprog_season')); ?>"><strong><?php echo esc_html(absint($season_counts->publish ?? 0)); ?></strong><span>Published Seasons</span></a>
                <a class="ifprog-stat" href="<?php echo esc_url(admin_url('edit.php?post_type=ifprog_level')); ?>"><strong><?php echo esc_html(absint($level_counts->publish ?? 0)); ?></strong><span>Published Levels</span></a>
                <a class="ifprog-stat" href="<?php echo esc_url(admin_url('edit.php?post_type=ifprog_program')); ?>"><strong><?php echo esc_html(absint($program_counts->publish ?? 0)); ?></strong><span>Published Programs</span></a>
                <a class="ifprog-stat" href="<?php echo esc_url(add_query_arg(['post_type' => 'ifprog_program', 'ifprog_state' => 'open'], admin_url('edit.php'))); ?>"><strong><?php echo esc_html($states['open']); ?></strong><span>Registration Open</span></a>
                <a class="ifprog-stat" href="<?php echo esc_url(add_query_arg(['post_type' => 'ifprog_program', 'ifprog_state' => 'coming_soon'], admin_url('edit.php'))); ?>"><strong><?php echo esc_html($states['coming_soon']); ?></strong><span>Coming Soon</span></a>
            </div>

            <div class="ifprog-card-grid">
                <section class="ifprog-card">
                    <h2>Public Shortcodes</h2>
                    <p>Add a Shortcode block to any WordPress page. Use the full accordion catalog or a compact day-by-day Class Offerings list. Filters can be combined.</p>
                    <div class="ifprog-code-list">
                        <code>[if_programming sport="figure-skating" format="class"]</code>
                        <code>[if_programming sport="hockey" format="league"]</code>
                        <code>[if_programming category="homeschool" group_program_groups="no"]</code>
                        <code>[if_programming category="adaptive" group_program_groups="no"]</code>
                        <code>[if_programming season="all" status="coming_soon,open"]</code>
                        <code>[if_programming_offerings sport="figure-skating" format="class"]</code>
                        <code>[if_programming_offerings season="upcoming" sport="figure-skating" format="class"]</code>
                    </div>
                </section>
                <section class="ifprog-card">
                    <h2>Shared Dash Connector</h2>
                    <?php if (IFPROG_Dash::ready()): $diagnostics = IFPROG_Dash::diagnostics(); ?>
                        <p><span class="ifprog-status ifprog-status--open">Connected and ready</span></p>
                        <p>Company: <strong><?php echo esc_html($diagnostics['company'] ?? '—'); ?></strong></p>
                    <?php elseif (IFPROG_Dash::available()): ?>
                        <p><span class="ifprog-status ifprog-status--soon">Setup required</span></p>
                    <?php else: ?>
                        <p><span class="ifprog-status ifprog-status--closed">Not detected</span></p>
                    <?php endif; ?>
                    <p>Discover new Dash Seasons, review source changes, and explicitly import or refresh selected records.</p>
                    <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=ifprog-preview')); ?>">Open Season Discovery</a>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=ifprog-dash')); ?>">View Connection</a>
                </section>
                <?php $monitoring = IFPROG_Monitoring::settings(); ?>
                <section class="ifprog-card">
                    <h2>Guarded Monitoring</h2>
                    <p><span class="ifprog-status <?php echo $monitoring['status'] === 'ready' ? 'ifprog-status--open' : 'ifprog-status--soon'; ?>"><?php echo $monitoring['status'] === 'ready' ? 'Configuration ready' : 'Paused'; ?></span></p>
                    <p>Prepare global and per-family discovery rules and review the local history of manual discovery and sync activity. The 1.9.1 maintenance line schedules no background checks.</p>
                    <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=ifprog-monitoring')); ?>">Open Monitoring</a>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=ifprog-audit')); ?>">View Activity Log</a>
                </section>
            </div>
        </div>
        <?php
    }

    public static function settings_page() {
        if (!current_user_can('manage_options')) return;
        $settings = self::settings();
        ?>
        <div class="wrap ifprog-admin">
            <div class="ifprog-page-header">
                <div>
                    <p class="ifprog-kicker">Ice &amp; Field Programming</p>
                    <h1>Display Settings</h1>
                    <p class="ifprog-lead">Customize the colors, type sizes, spacing, and descriptions used by the public Programming catalog.</p>
                </div>
            </div>
            <form method="post" action="options.php">
                <?php settings_fields('ifprog_display_group'); ?>
                <section class="ifprog-card">
                    <h2>Registration Labels</h2>
                    <table class="form-table">
                        <tr><th><label for="ifprog-open-label">Registration Open</label></th><td><input id="ifprog-open-label" class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[open_label]" value="<?php echo esc_attr($settings['open_label']); ?>"></td></tr>
                        <tr><th><label for="ifprog-soon-label">Coming Soon</label></th><td><input id="ifprog-soon-label" class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[coming_soon_label]" value="<?php echo esc_attr($settings['coming_soon_label']); ?>"></td></tr>
                        <tr><th><label for="ifprog-closed-label">Registration Closed</label></th><td><input id="ifprog-closed-label" class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[closed_label]" value="<?php echo esc_attr($settings['closed_label']); ?>"></td></tr>
                        <tr><th><label for="ifprog-button-label">Default Button</label></th><td><input id="ifprog-button-label" class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[default_button_label]" value="<?php echo esc_attr($settings['default_button_label']); ?>"></td></tr>
                        <tr><th><label for="ifprog-empty-message">Empty Results</label></th><td><input id="ifprog-empty-message" class="large-text" name="<?php echo esc_attr(self::OPTION); ?>[empty_message]" value="<?php echo esc_attr($settings['empty_message']); ?>"></td></tr>
                    </table>
                </section>
                <section class="ifprog-card">
                    <h2>Colors</h2>
                    <table class="form-table">
                        <tr><th>Accent</th><td><input type="color" name="<?php echo esc_attr(self::OPTION); ?>[accent_color]" value="<?php echo esc_attr($settings['accent_color']); ?>"></td></tr>
                        <tr><th>Heading</th><td><input type="color" name="<?php echo esc_attr(self::OPTION); ?>[secondary_color]" value="<?php echo esc_attr($settings['secondary_color']); ?>"></td></tr>
                        <tr><th>Season Background</th><td><input type="color" name="<?php echo esc_attr(self::OPTION); ?>[catalog_background_color]" value="<?php echo esc_attr($settings['catalog_background_color']); ?>"> <span class="description">Fills the Season heading and the complete expanded Season area.</span></td></tr>
                        <tr><th>Season Border</th><td><input type="color" name="<?php echo esc_attr(self::OPTION); ?>[season_border_color]" value="<?php echo esc_attr($settings['season_border_color']); ?>"></td></tr>
                        <tr><th>Program Group Background</th><td><input type="color" name="<?php echo esc_attr(self::OPTION); ?>[surface_color]" value="<?php echo esc_attr($settings['surface_color']); ?>"></td></tr>
                        <tr><th>Program Group Border</th><td><input type="color" name="<?php echo esc_attr(self::OPTION); ?>[program_group_border_color]" value="<?php echo esc_attr($settings['program_group_border_color']); ?>"></td></tr>
                        <tr><th>Level Background</th><td><input type="color" name="<?php echo esc_attr(self::OPTION); ?>[level_background_color]" value="<?php echo esc_attr($settings['level_background_color']); ?>"></td></tr>
                        <tr><th>Level Border</th><td><input type="color" name="<?php echo esc_attr(self::OPTION); ?>[level_border_color]" value="<?php echo esc_attr($settings['level_border_color']); ?>"> <span class="description">The left accent still follows the Register Button color.</span></td></tr>
                        <tr><th>Class Background</th><td><input type="color" name="<?php echo esc_attr(self::OPTION); ?>[class_background_color]" value="<?php echo esc_attr($settings['class_background_color']); ?>"></td></tr>
                        <tr><th>Class Divider</th><td><input type="color" name="<?php echo esc_attr(self::OPTION); ?>[class_border_color]" value="<?php echo esc_attr($settings['class_border_color']); ?>"></td></tr>
                        <tr><th>Body Text</th><td><input type="color" name="<?php echo esc_attr(self::OPTION); ?>[text_color]" value="<?php echo esc_attr($settings['text_color']); ?>"></td></tr>
                        <tr><th>Muted Text</th><td><input type="color" name="<?php echo esc_attr(self::OPTION); ?>[muted_text_color]" value="<?php echo esc_attr($settings['muted_text_color']); ?>"></td></tr>
                        <tr><th>Inner Dividers</th><td><input type="color" name="<?php echo esc_attr(self::OPTION); ?>[border_color]" value="<?php echo esc_attr($settings['border_color']); ?>"> <span class="description">Used between a Season heading and its contents and for empty-result boxes.</span></td></tr>
                        <tr><th>Register Button</th><td><input type="color" name="<?php echo esc_attr(self::OPTION); ?>[button_background_color]" value="<?php echo esc_attr($settings['button_background_color']); ?>"></td></tr>
                        <tr><th>Register Button Text</th><td><input type="color" name="<?php echo esc_attr(self::OPTION); ?>[button_text_color]" value="<?php echo esc_attr($settings['button_text_color']); ?>"></td></tr>
                        <tr><th>Register Button Hover</th><td><input type="color" name="<?php echo esc_attr(self::OPTION); ?>[button_hover_background_color]" value="<?php echo esc_attr($settings['button_hover_background_color']); ?>"> <span class="description">Defaults to Ice &amp; Field orange.</span></td></tr>
                        <tr><th>Register Button Hover Text</th><td><input type="color" name="<?php echo esc_attr(self::OPTION); ?>[button_hover_text_color]" value="<?php echo esc_attr($settings['button_hover_text_color']); ?>"></td></tr>
                    </table>
                </section>
                <section class="ifprog-card">
                    <h2>Size &amp; Spacing</h2>
                    <table class="form-table">
                        <tr>
                            <th><label for="ifprog-density">Display Density</label></th>
                            <td>
                                <select id="ifprog-density" name="<?php echo esc_attr(self::OPTION); ?>[density]">
                                    <option value="compact" <?php selected($settings['density'], 'compact'); ?>>Compact</option>
                                    <option value="comfortable" <?php selected($settings['density'], 'comfortable'); ?>>Comfortable</option>
                                    <option value="spacious" <?php selected($settings['density'], 'spacious'); ?>>Spacious</option>
                                </select>
                            </td>
                        </tr>
                        <tr><th><label for="ifprog-group-gap">Space Between Sections</label></th><td><input id="ifprog-group-gap" type="number" min="4" max="32" name="<?php echo esc_attr(self::OPTION); ?>[group_gap]" value="<?php echo esc_attr($settings['group_gap']); ?>"> px</td></tr>
                        <tr><th><label for="ifprog-section-spacing-top">Space Above Catalog</label></th><td><input id="ifprog-section-spacing-top" type="number" min="0" max="200" name="<?php echo esc_attr(self::OPTION); ?>[section_spacing_top]" value="<?php echo esc_attr($settings['section_spacing_top']); ?>"> px <span class="description">Adds space outside the complete shortcode section.</span></td></tr>
                        <tr><th><label for="ifprog-section-spacing-bottom">Space Below Catalog</label></th><td><input id="ifprog-section-spacing-bottom" type="number" min="0" max="200" name="<?php echo esc_attr(self::OPTION); ?>[section_spacing_bottom]" value="<?php echo esc_attr($settings['section_spacing_bottom']); ?>"> px <span class="description">Adds space outside the complete shortcode section.</span></td></tr>
                        <tr><th><label for="ifprog-season-corner-radius">Season Corner Radius</label></th><td><input id="ifprog-season-corner-radius" type="number" min="0" max="40" name="<?php echo esc_attr(self::OPTION); ?>[season_corner_radius]" value="<?php echo esc_attr($settings['season_corner_radius']); ?>"> px</td></tr>
                        <tr><th><label for="ifprog-season-border-width">Season Border Width</label></th><td><input id="ifprog-season-border-width" type="number" min="0" max="8" name="<?php echo esc_attr(self::OPTION); ?>[season_border_width]" value="<?php echo esc_attr($settings['season_border_width']); ?>"> px</td></tr>
                        <tr><th><label for="ifprog-corner-radius">Program Group Corner Radius</label></th><td><input id="ifprog-corner-radius" type="number" min="0" max="40" name="<?php echo esc_attr(self::OPTION); ?>[corner_radius]" value="<?php echo esc_attr($settings['corner_radius']); ?>"> px</td></tr>
                        <tr><th><label for="ifprog-program-group-border-width">Program Group Border Width</label></th><td><input id="ifprog-program-group-border-width" type="number" min="0" max="8" name="<?php echo esc_attr(self::OPTION); ?>[program_group_border_width]" value="<?php echo esc_attr($settings['program_group_border_width']); ?>"> px</td></tr>
                        <tr><th><label for="ifprog-level-corner-radius">Level Corner Radius</label></th><td><input id="ifprog-level-corner-radius" type="number" min="0" max="40" name="<?php echo esc_attr(self::OPTION); ?>[level_corner_radius]" value="<?php echo esc_attr($settings['level_corner_radius']); ?>"> px</td></tr>
                        <tr><th><label for="ifprog-level-border-width">Level Border Width</label></th><td><input id="ifprog-level-border-width" type="number" min="0" max="8" name="<?php echo esc_attr(self::OPTION); ?>[level_border_width]" value="<?php echo esc_attr($settings['level_border_width']); ?>"> px <span class="description">The colored left accent remains visible at 4 px.</span></td></tr>
                        <tr><th><label for="ifprog-class-corner-radius">Class Corner Radius</label></th><td><input id="ifprog-class-corner-radius" type="number" min="0" max="40" name="<?php echo esc_attr(self::OPTION); ?>[class_corner_radius]" value="<?php echo esc_attr($settings['class_corner_radius']); ?>"> px</td></tr>
                        <tr><th><label for="ifprog-class-border-width">Class Divider Width</label></th><td><input id="ifprog-class-border-width" type="number" min="0" max="4" name="<?php echo esc_attr(self::OPTION); ?>[class_border_width]" value="<?php echo esc_attr($settings['class_border_width']); ?>"> px <span class="description">Keeps classes as a compact list instead of restoring separate bubbles.</span></td></tr>
                        <tr><th><label for="ifprog-base-size">Base Text Size</label></th><td><input id="ifprog-base-size" type="number" min="13" max="20" name="<?php echo esc_attr(self::OPTION); ?>[base_font_size]" value="<?php echo esc_attr($settings['base_font_size']); ?>"> px</td></tr>
                        <tr><th><label for="ifprog-session-size">Session Heading</label></th><td><input id="ifprog-session-size" type="number" min="18" max="38" name="<?php echo esc_attr(self::OPTION); ?>[session_heading_size]" value="<?php echo esc_attr($settings['session_heading_size']); ?>"> px</td></tr>
                        <tr><th><label for="ifprog-group-size">Program Group Heading</label></th><td><input id="ifprog-group-size" type="number" min="17" max="32" name="<?php echo esc_attr(self::OPTION); ?>[group_heading_size]" value="<?php echo esc_attr($settings['group_heading_size']); ?>"> px</td></tr>
                        <tr><th><label for="ifprog-level-size">Level Heading</label></th><td><input id="ifprog-level-size" type="number" min="16" max="30" name="<?php echo esc_attr(self::OPTION); ?>[level_heading_size]" value="<?php echo esc_attr($settings['level_heading_size']); ?>"> px</td></tr>
                        <tr><th><label for="ifprog-class-size">Class Name</label></th><td><input id="ifprog-class-size" type="number" min="14" max="24" name="<?php echo esc_attr(self::OPTION); ?>[class_heading_size]" value="<?php echo esc_attr($settings['class_heading_size']); ?>"> px</td></tr>
                    </table>
                </section>
                <section class="ifprog-card">
                    <h2>Descriptions</h2>
                    <p><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[show_season_descriptions]" value="1" <?php checked($settings['show_season_descriptions'], 1); ?>> Show Season descriptions when a Season is expanded</label></p>
                    <p><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[show_group_descriptions]" value="1" <?php checked($settings['show_group_descriptions'], 1); ?>> Show Program Group descriptions when a Group is expanded</label></p>
                    <p><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[show_level_descriptions]" value="1" <?php checked($settings['show_level_descriptions'], 1); ?>> Show Level descriptions when a Level is expanded</label></p>
                    <p><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[show_program_descriptions]" value="1" <?php checked($settings['show_program_descriptions'], 1); ?>> Show distinct class descriptions beneath class names</label></p>
                </section>
                <?php submit_button('Save Programming Settings'); ?>
            </form>
        </div>
        <?php
    }

    public static function season_columns($columns) {
        return [
            'cb' => $columns['cb'] ?? '<input type="checkbox">',
            'title' => 'Season',
            'ifprog_status' => 'Lifecycle',
            'ifprog_order' => 'Display Order',
            'ifprog_dates' => 'Dates',
            'ifprog_production' => 'Production',
            'ifprog_dash' => 'Dash',
            'date' => $columns['date'] ?? 'Date',
        ];
    }

    public static function group_order_add_field() {
        wp_nonce_field('ifprog_group_order', 'ifprog_group_order_nonce');
        ?>
        <div class="form-field">
            <label for="ifprog-group-order">Display Order</label>
            <input id="ifprog-group-order" type="number" min="0" max="9999" name="ifprog_group_order" value="100">
            <p>Lower numbers appear first in the public catalog.</p>
        </div>
        <?php
    }

    public static function group_order_edit_field($term) {
        $order = get_term_meta($term->term_id, '_ifprog_group_order', true);
        if ($order === '') $order = 100;
        wp_nonce_field('ifprog_group_order', 'ifprog_group_order_nonce');
        ?>
        <tr class="form-field">
            <th scope="row"><label for="ifprog-group-order">Display Order</label></th>
            <td>
                <input id="ifprog-group-order" type="number" min="0" max="9999" name="ifprog_group_order" value="<?php echo esc_attr($order); ?>">
                <p class="description">Lower numbers appear first in the public catalog.</p>
            </td>
        </tr>
        <?php
    }

    public static function save_group_order($term_id) {
        if (!current_user_can('manage_categories')) return;
        if (!isset($_POST['ifprog_group_order_nonce'])) return;
        $nonce = sanitize_text_field(wp_unslash($_POST['ifprog_group_order_nonce']));
        if (!wp_verify_nonce($nonce, 'ifprog_group_order')) return;
        $order = self::bounded_int($_POST['ifprog_group_order'] ?? 100, 100, 0, 9999);
        update_term_meta($term_id, '_ifprog_group_order', $order);
    }

    public static function group_columns($columns) {
        $updated = [];
        foreach ($columns as $key => $label) {
            $updated[$key] = $label;
            if ($key === 'name') $updated['ifprog_order'] = 'Display Order';
        }
        return $updated;
    }

    public static function group_column($content, $column, $term_id) {
        if ($column !== 'ifprog_order') return $content;
        $order = get_term_meta($term_id, '_ifprog_group_order', true);
        return esc_html($order === '' ? 100 : absint($order));
    }

    public static function season_column($column, $post_id) {
        if ($column === 'ifprog_status') {
            $saved_status = IFPROG_Status::season_status($post_id);
            $status = IFPROG_Status::effective_season_status($post_id);
            $label = ucfirst($status) . ($status !== $saved_status ? ' (automatic)' : '');
            echo '<span class="ifprog-status ifprog-status--' . esc_attr(self::status_class($status)) . '">' . esc_html($label) . '</span>';
        } elseif ($column === 'ifprog_order') {
            $order = get_post_meta($post_id, '_ifprog_season_order', true);
            echo esc_html($order === '' ? 100 : min(9999, absint($order)));
        } elseif ($column === 'ifprog_dates') {
            echo esc_html(self::season_date_range($post_id) ?: '—');
        } elseif ($column === 'ifprog_production') {
            if (get_post_meta($post_id, '_ifprog_is_production', true) !== '1') {
                echo '—';
                return;
            }
            $production_id = absint(get_post_meta($post_id, '_ifprog_production_id', true));
            if ($production_id && get_post_type($production_id) === 'ifp_production') {
                $url = get_edit_post_link($production_id);
                echo $url
                    ? '<a href="' . esc_url($url) . '">' . esc_html(get_the_title($production_id)) . '</a>'
                    : esc_html(get_the_title($production_id));
            } else {
                echo '<span class="ifprog-status ifprog-status--soon">Production · not linked</span>';
            }
        } elseif ($column === 'ifprog_dash') {
            $id = absint(get_post_meta($post_id, '_ifprog_dash_season_id', true));
            echo $id ? '#' . esc_html($id) : '—';
        }
    }

    public static function program_columns($columns) {
        return [
            'cb' => $columns['cb'] ?? '<input type="checkbox">',
            'title' => 'Program',
            'ifprog_state' => 'Registration',
            'ifprog_season' => 'Season',
            'ifprog_level' => 'Level',
            'ifprog_sport' => 'Sport',
            'ifprog_format' => 'Format',
            'ifprog_category' => 'Program Categories',
            'ifprog_production_link' => 'Production Group',
            'ifprog_dates' => 'Program Dates',
            'date' => $columns['date'] ?? 'Date',
        ];
    }

    public static function program_column($column, $post_id) {
        if ($column === 'ifprog_state') {
            $state = IFPROG_Status::program_state($post_id);
            echo '<span class="ifprog-status ifprog-status--' . esc_attr(self::status_class($state)) . '">' . esc_html(IFPROG_Status::state_label($state)) . '</span>';
        } elseif ($column === 'ifprog_season') {
            $season_id = absint(IFPROG_Fields::get($post_id, 'season_id'));
            echo $season_id ? '<a href="' . esc_url(get_edit_post_link($season_id)) . '">' . esc_html(get_the_title($season_id)) . '</a>' : '—';
        } elseif ($column === 'ifprog_level') {
            $level_id = absint(IFPROG_Fields::get($post_id, 'level_id'));
            echo $level_id ? '<a href="' . esc_url(get_edit_post_link($level_id)) . '">' . esc_html(get_the_title($level_id)) . '</a>' : esc_html(IFPROG_Fields::get($post_id, 'level') ?: '—');
        } elseif ($column === 'ifprog_sport') {
            echo wp_kses_post(self::term_links($post_id, 'ifprog_sport'));
        } elseif ($column === 'ifprog_format') {
            echo wp_kses_post(self::term_links($post_id, 'ifprog_format'));
        } elseif ($column === 'ifprog_category') {
            echo wp_kses_post(self::program_category_assignments($post_id));
        } elseif ($column === 'ifprog_production_link') {
            $group_id = absint(get_post_meta($post_id, '_ifprog_group_id', true));
            echo $group_id && get_post_type($group_id) === 'ifp_group'
                ? '<a href="' . esc_url(get_edit_post_link($group_id)) . '">' . esc_html(get_the_title($group_id)) . '</a>'
                : '—';
        } elseif ($column === 'ifprog_dates') {
            echo esc_html(self::program_date_range($post_id) ?: '—');
        }
    }

    public static function program_filters($post_type) {
        if (!in_array($post_type, ['ifprog_level','ifprog_program'], true)) return;

        $selected_season = absint($_GET['ifprog_season'] ?? 0);
        $seasons = get_posts([
            'post_type' => 'ifprog_season',
            'post_status' => 'any',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);

        echo '<select name="ifprog_season"><option value="0">All seasons</option>';
        foreach ($seasons as $season) {
            echo '<option value="' . esc_attr($season->ID) . '" ' . selected($selected_season, $season->ID, false) . '>' . esc_html($season->post_title) . '</option>';
        }
        echo '</select>';

        if ($post_type === 'ifprog_level') {
            $selected_group = absint($_GET['ifprog_group'] ?? 0);
            wp_dropdown_categories([
                'taxonomy' => 'ifprog_group',
                'hide_empty' => false,
                'show_option_all' => 'All program groups',
                'name' => 'ifprog_group',
                'orderby' => 'name',
                'selected' => $selected_group,
                'hierarchical' => true,
                'value_field' => 'term_id',
            ]);
            return;
        }
        if ($post_type !== 'ifprog_program') return;

        $selected_level = absint($_GET['ifprog_level'] ?? 0);
        $selected_state = sanitize_key(wp_unslash($_GET['ifprog_state'] ?? ''));
        $levels = get_posts([
            'post_type' => 'ifprog_level',
            'post_status' => 'any',
            'posts_per_page' => -1,
            'orderby' => [
                'menu_order' => 'ASC',
                'title' => 'ASC',
            ],
            'order' => 'ASC',
        ]);
        echo '<select name="ifprog_level"><option value="0">All levels</option>';
        foreach ($levels as $level) {
            $level_season_id = absint(get_post_meta($level->ID, '_ifprog_level_season_id', true));
            $label = ($level_season_id ? get_the_title($level_season_id) . ' — ' : '') . $level->post_title;
            echo '<option value="' . esc_attr($level->ID) . '" ' . selected($selected_level, $level->ID, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';

        echo '<select name="ifprog_state"><option value="">All registration states</option>';
        foreach ([
            'coming_soon' => 'Coming Soon',
            'open' => 'Registration Open',
            'closed' => 'Registration Closed',
            'draft' => 'Draft / hidden',
            'archived' => 'Archived / hidden',
        ] as $state => $label) {
            echo '<option value="' . esc_attr($state) . '" ' . selected($selected_state, $state, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }

    public static function apply_program_filters($query) {
        if (!is_admin() || !$query->is_main_query()) return;

        $post_type = $query->get('post_type');
        if ($post_type === 'ifprog_level') {
            $season_id = absint($_GET['ifprog_season'] ?? 0);
            $group_id = absint($_GET['ifprog_group'] ?? 0);
            if ($season_id) {
                $query->set('meta_key', '_ifprog_level_season_id');
                $query->set('meta_value', $season_id);
            }
            if ($group_id) {
                $query->set('tax_query', [[
                    'taxonomy' => 'ifprog_group',
                    'field' => 'term_id',
                    'terms' => [$group_id],
                ]]);
            }
            return;
        }
        if ($post_type !== 'ifprog_program') return;

        $season_id = absint($_GET['ifprog_season'] ?? 0);
        $level_id = absint($_GET['ifprog_level'] ?? 0);
        $category = sanitize_title(wp_unslash($_GET['ifprog_category'] ?? ''));
        $meta_query = [];
        if ($season_id) {
            $meta_query[] = [
                'relation' => 'OR',
                ['key' => IFPROG_Fields::local_key('season_id'), 'value' => $season_id],
                ['key' => IFPROG_Fields::dash_key('season_id'), 'value' => $season_id],
            ];
        }
        if ($level_id) {
            $meta_query[] = [
                'relation' => 'OR',
                ['key' => IFPROG_Fields::local_key('level_id'), 'value' => $level_id],
                ['key' => IFPROG_Fields::dash_key('level_id'), 'value' => $level_id],
            ];
        }
        if ($meta_query) {
            if (count($meta_query) > 1) $meta_query['relation'] = 'AND';
            $query->set('meta_query', $meta_query);
        }
        if ($category !== '') {
            $query->set('tax_query', [[
                'taxonomy' => 'ifprog_category',
                'field' => 'slug',
                'terms' => [$category],
            ]]);
        }

        $state = sanitize_key(wp_unslash($_GET['ifprog_state'] ?? ''));
        if ($state !== '') {
            add_filter('the_posts', function($posts, $filtered_query) use ($query, $state) {
                if ($filtered_query !== $query) return $posts;
                return array_values(array_filter($posts, function($post) use ($state) {
                    return IFPROG_Status::program_state($post->ID) === $state;
                }));
            }, 10, 2);
        }
    }

    public static function level_columns($columns) {
        return [
            'cb' => $columns['cb'] ?? '<input type="checkbox">',
            'title' => 'Level',
            'ifprog_season' => 'Season',
            'ifprog_group' => 'Program Group',
            'ifprog_category' => 'Program Categories',
            'ifprog_age_range' => 'Age Range',
            'ifprog_programs' => 'Programs',
            'ifprog_production_link' => 'Production Division',
            'ifprog_dash' => 'Dash',
            'date' => $columns['date'] ?? 'Date',
        ];
    }

    public static function level_column($column, $post_id) {
        if ($column === 'ifprog_season') {
            $season_id = absint(get_post_meta($post_id, '_ifprog_level_season_id', true));
            echo $season_id ? '<a href="' . esc_url(get_edit_post_link($season_id)) . '">' . esc_html(get_the_title($season_id)) . '</a>' : '—';
        } elseif ($column === 'ifprog_group') {
            $terms = get_the_terms($post_id, 'ifprog_group');
            echo $terms && !is_wp_error($terms) ? esc_html(implode(', ', wp_list_pluck($terms, 'name'))) : '—';
        } elseif ($column === 'ifprog_category') {
            $terms = get_the_terms($post_id, 'ifprog_category');
            echo $terms && !is_wp_error($terms) ? esc_html(implode(', ', wp_list_pluck($terms, 'name'))) : '—';
        } elseif ($column === 'ifprog_age_range') {
            echo esc_html(IFPROG_Dash::level_age_range($post_id) ?: '—');
        } elseif ($column === 'ifprog_programs') {
            $ids = get_posts([
                'post_type' => 'ifprog_program',
                'post_status' => array_keys(get_post_stati()),
                'posts_per_page' => -1,
                'fields' => 'ids',
                'meta_query' => [
                    'relation' => 'OR',
                    ['key' => IFPROG_Fields::local_key('level_id'), 'value' => absint($post_id)],
                    ['key' => IFPROG_Fields::dash_key('level_id'), 'value' => absint($post_id)],
                ],
            ]);
            $url = add_query_arg(['post_type' => 'ifprog_program', 'ifprog_level' => $post_id], admin_url('edit.php'));
            echo '<a href="' . esc_url($url) . '">' . esc_html(count($ids)) . '</a>';
        } elseif ($column === 'ifprog_production_link') {
            $division_id = absint(get_post_meta($post_id, '_ifprog_division_id', true));
            echo $division_id && get_post_type($division_id) === 'ifp_division'
                ? '<a href="' . esc_url(get_edit_post_link($division_id)) . '">' . esc_html(get_the_title($division_id)) . '</a>'
                : '—';
        } elseif ($column === 'ifprog_dash') {
            $league_id = absint(get_post_meta($post_id, '_ifprog_dash_league_id', true));
            echo $league_id ? '#' . esc_html($league_id) : '—';
        }
    }

    private static function term_links($post_id, $taxonomy) {
        $terms = get_the_terms($post_id, $taxonomy);
        if (!$terms || is_wp_error($terms)) return '—';
        return implode(', ', array_map(function($term) use ($taxonomy) {
            $url = add_query_arg([
                'post_type' => 'ifprog_program',
                $taxonomy => $term->slug,
            ], admin_url('edit.php'));
            return '<a href="' . esc_url($url) . '">' . esc_html($term->name) . '</a>';
        }, $terms));
    }

    private static function program_category_assignments($post_id) {
        $direct_terms = get_the_terms($post_id, 'ifprog_category');
        $direct_terms = $direct_terms && !is_wp_error($direct_terms) ? array_values($direct_terms) : [];
        $parts = [];
        if ($direct_terms) {
            $parts[] = self::term_links($post_id, 'ifprog_category');
        }

        $direct_ids = array_map('absint', wp_list_pluck($direct_terms, 'term_id'));
        $level_id = absint(IFPROG_Fields::get($post_id, 'level_id'));
        $level_terms = $level_id ? get_the_terms($level_id, 'ifprog_category') : [];
        $level_terms = $level_terms && !is_wp_error($level_terms) ? array_values($level_terms) : [];
        $inherited = array_values(array_filter($level_terms, function($term) use ($direct_ids) {
            return !in_array(absint($term->term_id), $direct_ids, true);
        }));
        if ($inherited) {
            $parts[] = '<span title="Inherited from Level">Level: ' .
                esc_html(implode(', ', wp_list_pluck($inherited, 'name'))) .
                '</span>';
        }

        return $parts
            ? implode('<br>', $parts)
            : '<span class="ifprog-admin-missing">Not assigned</span>';
    }

    private static function season_date_range($post_id) {
        return self::date_range(
            get_post_meta($post_id, '_ifprog_season_start_date', true),
            get_post_meta($post_id, '_ifprog_season_end_date', true)
        );
    }

    private static function program_date_range($post_id) {
        return self::date_range(
            IFPROG_Fields::get($post_id, 'start_date'),
            IFPROG_Fields::get($post_id, 'end_date')
        );
    }

    private static function date_range($start, $end) {
        $start_ts = IFPROG_Status::timestamp($start);
        $end_ts = IFPROG_Status::timestamp($end);
        if (!$start_ts && !$end_ts) return '';
        if ($start_ts && $end_ts) {
            return wp_date(get_option('date_format'), $start_ts) . ' – ' . wp_date(get_option('date_format'), $end_ts);
        }
        return wp_date(get_option('date_format'), $start_ts ?: $end_ts);
    }

    private static function status_class($status) {
        if (in_array($status, ['current','open'], true)) return 'open';
        if (in_array($status, ['upcoming','coming_soon'], true)) return 'soon';
        if (in_array($status, ['completed','closed'], true)) return 'closed';
        return 'muted';
    }

    private static function bounded_int($value, $default, $min, $max) {
        if ($value === '' || !is_numeric($value)) return absint($default);
        return max(absint($min), min(absint($max), absint($value)));
    }

    private static function mix_hex_colors($first, $second, $first_percent) {
        $first = sanitize_hex_color($first);
        $second = sanitize_hex_color($second);
        if (!$first || !$second) return $second ?: '#d7e1e7';

        if (strlen($first) === 4) {
            $first = '#' . $first[1] . $first[1] . $first[2] . $first[2] . $first[3] . $first[3];
        }
        if (strlen($second) === 4) {
            $second = '#' . $second[1] . $second[1] . $second[2] . $second[2] . $second[3] . $second[3];
        }

        $first_weight = max(0, min(100, absint($first_percent))) / 100;
        $second_weight = 1 - $first_weight;
        $channels = [];
        for ($index = 0; $index < 3; $index++) {
            $offset = 1 + ($index * 2);
            $channels[] = (int) round(
                (hexdec(substr($first, $offset, 2)) * $first_weight) +
                (hexdec(substr($second, $offset, 2)) * $second_weight)
            );
        }
        return sprintf('#%02x%02x%02x', $channels[0], $channels[1], $channels[2]);
    }
}
