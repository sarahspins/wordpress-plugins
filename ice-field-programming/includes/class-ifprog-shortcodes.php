<?php
if (!defined('ABSPATH')) exit;

class IFPROG_Shortcodes {
    private static $instance = 0;

    public static function init() {
        add_shortcode('if_programming', [__CLASS__, 'catalog']);
        add_shortcode('if_programs', [__CLASS__, 'catalog']);
        add_shortcode('if_programming_offerings', [__CLASS__, 'offerings']);
        add_shortcode('if_class_offerings', [__CLASS__, 'offerings']);
    }

    public static function offerings($atts = []) {
        $settings = IFPROG_Admin::settings();
        $atts = shortcode_atts([
            'sport' => '',
            'format' => '',
            'category' => '',
            'group' => '',
            'level' => '',
            'season' => 'current',
            'status' => 'coming_soon,open',
            'heading' => '',
            'show_season' => 'auto',
            'show_dates' => 'no',
            'show_times' => 'no',
            'show_unscheduled' => 'yes',
            'combine_seasons' => 'yes',
            'include_show' => 'no',
            'empty' => $settings['empty_message'],
        ], $atts, 'if_programming_offerings');

        $query_args = [
            'post_type' => 'ifprog_program',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => [
                'menu_order' => 'ASC',
                'title' => 'ASC',
            ],
            'order' => 'ASC',
            'no_found_rows' => true,
        ];
        $sport_filters = self::csv_slugs($atts['sport']);
        $format_filters = self::csv_slugs($atts['format']);
        $category_filters = self::csv_slugs($atts['category']);
        $tax_query = [];
        self::add_tax_filter($tax_query, 'ifprog_sport', $sport_filters);
        self::add_tax_filter($tax_query, 'ifprog_format', $format_filters);
        if ($tax_query) {
            if (count($tax_query) > 1) $tax_query['relation'] = 'AND';
            $query_args['tax_query'] = $tax_query;
        }

        $season_ids = self::season_ids($atts['season']);
        $allowed_states = self::states($atts['status']);
        $level_filters = self::level_filters($atts['level']);
        $group_filters = self::level_filters($atts['group']);
        $include_show = self::truthy($atts['include_show']);
        $programs = array_values(array_filter(get_posts($query_args), function($program) use (
            $season_ids,
            $allowed_states,
            $level_filters,
            $group_filters,
            $category_filters,
            $include_show
        ) {
            $season_id = absint(IFPROG_Fields::get($program->ID, 'season_id'));
            if ($season_ids !== null && !in_array($season_id, $season_ids, true)) return false;
            if (!$include_show && self::program_is_show_related($program)) return false;
            if (!self::program_matches_category($program->ID, $category_filters)) return false;
            if (!self::program_matches_level($program->ID, $level_filters)) return false;
            if (!self::program_matches_group($program->ID, $group_filters)) return false;
            return in_array(IFPROG_Status::program_state($program->ID), $allowed_states, true);
        }));

        $sessions = self::session_groups($programs);
        $combine_seasons = self::truthy($atts['combine_seasons']);
        if ($combine_seasons) {
            $sessions = self::combine_offering_sessions($sessions);
        }
        $offerings = [];
        $show_times = self::truthy($atts['show_times']);
        foreach ($sessions as $session) {
            $days = self::offerings_by_day($session['programs'], $show_times);
            if (!self::truthy($atts['show_unscheduled'])) unset($days[8]);
            if ($days) {
                $session['offering_days'] = $days;
                $offerings[] = $session;
            }
        }

        wp_enqueue_style('ifprog-public', IFPROG_URL . 'assets/public.css', [], IFPROG_VERSION);

        self::$instance++;
        $instance_id = 'ifprog-offerings-' . self::$instance;
        $show_season = sanitize_key((string) $atts['show_season']);
        if (!in_array($show_season, ['auto','yes','no'], true)) $show_season = 'auto';
        $multiple = count($offerings) > 1;
        $custom_heading = sanitize_text_field((string) $atts['heading']);

        ob_start();
        ?>
        <section id="<?php echo esc_attr($instance_id); ?>" class="ifprog-offerings">
            <?php if (!$offerings): ?>
                <p class="ifprog-offerings__empty"><?php echo esc_html($atts['empty']); ?></p>
            <?php else: ?>
                <?php if ($custom_heading !== '' && $multiple): ?>
                    <p class="ifprog-offerings__heading"><strong><?php echo esc_html($custom_heading); ?></strong></p>
                <?php endif; ?>
                <?php foreach ($offerings as $session): ?>
                    <?php
                    $include_season = $show_season === 'yes' ||
                        ($show_season === 'auto' && $multiple && !$combine_seasons);
                    if ($custom_heading !== '' && !$multiple) {
                        $heading = $custom_heading;
                        if ($include_season) $heading .= ' — ' . $session['title'];
                    } elseif ($multiple && $custom_heading !== '') {
                        $heading = !empty($session['combined'])
                            ? self::offerings_heading($session['season_status'])
                            : $session['title'];
                    } else {
                        $heading = rtrim(self::offerings_heading($session['season_status']), ':');
                        if ($include_season) $heading .= ' — ' . $session['title'];
                        $heading .= ':';
                    }
                    ?>
                    <div class="ifprog-offerings__session">
                        <p class="ifprog-offerings__heading"><strong><?php echo esc_html($heading); ?></strong></p>
                        <?php if (self::truthy($atts['show_dates']) && $session['date_range']): ?>
                            <p class="ifprog-offerings__dates"><?php echo esc_html($session['date_range']); ?></p>
                        <?php endif; ?>
                        <ul class="ifprog-offerings__days">
                            <?php foreach ($session['offering_days'] as $day => $levels): ?>
                                <li><strong><?php echo esc_html(self::offering_day_label($day)); ?>:</strong> <?php echo esc_html(self::offering_day_summary($levels, $show_times)); ?>.</li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
        <?php
        return ob_get_clean();
    }

    public static function catalog($atts = []) {
        $settings = IFPROG_Admin::settings();
        $atts = shortcode_atts([
            'sport' => '',
            'format' => '',
            'category' => '',
            'group' => '',
            'level' => '',
            'season' => 'current,upcoming,completed',
            'status' => 'coming_soon,open,closed',
            'heading' => '',
            'group_levels' => 'yes',
            'group_program_groups' => 'yes',
            'open_sessions' => 'no',
            'open_groups' => 'no',
            'open_levels' => 'no',
            'open_first' => 'no',
            'show_dates' => 'yes',
            'show_registration_dates' => 'yes',
            'show_price' => 'yes',
            'hide_finished' => 'yes',
            'empty' => $settings['empty_message'],
        ], $atts, 'if_programming');

        $query_args = [
            'post_type' => 'ifprog_program',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => [
                'menu_order' => 'ASC',
                'title' => 'ASC',
            ],
            'order' => 'ASC',
            'no_found_rows' => true,
        ];

        $sport_filters = self::csv_slugs($atts['sport']);
        $format_filters = self::csv_slugs($atts['format']);
        $category_filters = self::csv_slugs($atts['category']);
        $tax_query = [];
        self::add_tax_filter($tax_query, 'ifprog_sport', $sport_filters);
        self::add_tax_filter($tax_query, 'ifprog_format', $format_filters);
        if ($tax_query) {
            if (count($tax_query) > 1) $tax_query['relation'] = 'AND';
            $query_args['tax_query'] = $tax_query;
        }

        $season_ids = self::season_ids($atts['season']);
        $allowed_states = self::states($atts['status']);
        $level_filters = self::level_filters($atts['level']);
        $group_filters = self::level_filters($atts['group']);
        $hide_finished = self::truthy($atts['hide_finished']);
        $catalog_now = current_datetime()->getTimestamp();
        $all_programs = get_posts($query_args);
        $programs = array_values(array_filter($all_programs, function($program) use ($allowed_states, $season_ids, $level_filters, $group_filters, $category_filters, $hide_finished, $catalog_now) {
            if (
                $season_ids !== null &&
                !in_array(absint(IFPROG_Fields::get($program->ID, 'season_id')), $season_ids, true)
            ) {
                return false;
            }
            if (!self::program_matches_category($program->ID, $category_filters)) return false;
            if (!self::program_matches_level($program->ID, $level_filters)) return false;
            if (!self::program_matches_group($program->ID, $group_filters)) return false;
            if ($hide_finished && !self::program_has_remaining_occurrence($program, $catalog_now)) return false;
            return in_array(IFPROG_Status::program_state($program->ID), $allowed_states, true);
        }));
        $standalone_levels = self::standalone_levels(
            $season_ids,
            $allowed_states,
            $sport_filters,
            $format_filters,
            $category_filters,
            $level_filters,
            $group_filters
        );
        $has_program_only_filters = $category_filters || $level_filters !== null || $group_filters !== null;
        $empty_upcoming_seasons = $has_program_only_filters
            ? []
            : self::empty_upcoming_seasons(
                $season_ids,
                $allowed_states,
                $sport_filters,
                $format_filters
            );
        unset($all_programs);

        wp_enqueue_style(
            'ifprog-public',
            IFPROG_URL . 'assets/public.css',
            [],
            IFPROG_VERSION
        );

        self::$instance++;
        $instance_id = 'ifprog-catalog-' . self::$instance;
        $density = isset($settings['density']) ? sanitize_key($settings['density']) : 'compact';
        $density_values = [
            'compact' => [10, 12],
            'comfortable' => [14, 16],
            'spacious' => [18, 20],
        ];
        if (!isset($density_values[$density])) $density = 'compact';
        $row_padding = $density_values[$density];
        $style = sprintf(
            '--ifprog-accent:%s;--ifprog-heading:%s;--ifprog-surface:%s;--ifprog-season-bg:%s;--ifprog-level-bg:%s;--ifprog-class-bg:%s;--ifprog-text:%s;--ifprog-muted:%s;--ifprog-border:%s;--ifprog-season-border:%s;--ifprog-group-border:%s;--ifprog-level-border:%s;--ifprog-class-border:%s;--ifprog-button-bg:%s;--ifprog-button-text:%s;--ifprog-button-hover-bg:%s;--ifprog-button-hover-text:%s;--ifprog-group-gap:%dpx;--ifprog-section-spacing-top:%dpx;--ifprog-section-spacing-bottom:%dpx;--ifprog-group-radius:%dpx;--ifprog-session-radius:%dpx;--ifprog-level-radius:%dpx;--ifprog-class-radius:%dpx;--ifprog-session-border-width:%dpx;--ifprog-group-border-width:%dpx;--ifprog-level-border-width:%dpx;--ifprog-class-border-width:%dpx;--ifprog-base-size:%dpx;--ifprog-session-size:%dpx;--ifprog-group-size:%dpx;--ifprog-level-size:%dpx;--ifprog-class-size:%dpx;--ifprog-row-pad-y:%dpx;--ifprog-row-pad-x:%dpx;',
            $settings['accent_color'],
            $settings['secondary_color'],
            $settings['surface_color'],
            $settings['catalog_background_color'],
            $settings['level_background_color'],
            $settings['class_background_color'],
            $settings['text_color'],
            $settings['muted_text_color'],
            $settings['border_color'],
            $settings['season_border_color'],
            $settings['program_group_border_color'],
            $settings['level_border_color'],
            $settings['class_border_color'],
            $settings['button_background_color'],
            $settings['button_text_color'],
            $settings['button_hover_background_color'],
            $settings['button_hover_text_color'],
            absint($settings['group_gap']),
            absint($settings['section_spacing_top']),
            absint($settings['section_spacing_bottom']),
            absint($settings['corner_radius']),
            absint($settings['season_corner_radius']),
            absint($settings['level_corner_radius']),
            absint($settings['class_corner_radius']),
            absint($settings['season_border_width']),
            absint($settings['program_group_border_width']),
            absint($settings['level_border_width']),
            absint($settings['class_border_width']),
            absint($settings['base_font_size']),
            absint($settings['session_heading_size']),
            absint($settings['group_heading_size']),
            absint($settings['level_heading_size']),
            absint($settings['class_heading_size']),
            $row_padding[0],
            $row_padding[1]
        );
        $session_groups = self::session_groups(
            $programs,
            $group_filters,
            $empty_upcoming_seasons,
            $standalone_levels
        );

        ob_start();
        ?>
        <section id="<?php echo esc_attr($instance_id); ?>" class="ifprog-catalog ifprog-density--<?php echo esc_attr($density); ?>" style="<?php echo esc_attr($style); ?>">
            <?php if ($atts['heading'] !== ''): ?>
                <h2 class="ifprog-catalog__heading"><?php echo esc_html($atts['heading']); ?></h2>
            <?php endif; ?>

            <?php if (!$session_groups): ?>
                <div class="ifprog-catalog__empty"><?php echo esc_html($atts['empty']); ?></div>
            <?php else: ?>
                <div class="ifprog-sessions">
                    <?php foreach ($session_groups as $session_index => $session):
                        $session_open = self::truthy($atts['open_sessions']) ||
                            (self::truthy($atts['open_first']) && $session_index === 0);
                        $session_is_upcoming = $session['season_status'] === 'upcoming';
                        $session_has_action = !$session_is_upcoming && $session['registration_url'] !== '';
                        $session_is_closed = !empty($session['registration_closed']);
                        $session_is_coming_soon = !$session['programs'] &&
                            !$session['standalone_levels'] &&
                            $session_is_upcoming;
                        $session_has_upcoming_status = !$session_has_action && $session_is_upcoming;
                        $session_upcoming_label = $settings['coming_soon_label'];
                        $session_static_label = $session_is_closed
                            ? $settings['closed_label']
                            : $session_upcoming_label;
                        ?>
                        <?php if ($session_is_closed || $session_is_coming_soon): ?>
                            <div class="ifprog-session-wrap ifprog-session-wrap--has-action ifprog-session-wrap--static<?php echo $session_is_closed ? ' ifprog-session-wrap--closed' : ' ifprog-session-wrap--soon'; ?>">
                                <div class="ifprog-session<?php echo $session_is_closed ? ' ifprog-session--closed' : ' ifprog-session--soon'; ?>">
                                    <div class="ifprog-session__summary ifprog-session__summary--static">
                                        <span class="ifprog-session__summary-copy">
                                            <h3 class="ifprog-session__title"><?php echo esc_html($session['title']); ?></h3>
                                            <?php if ($session_is_coming_soon && self::truthy($atts['show_dates']) && $session['date_range']): ?>
                                                <span class="ifprog-session__dates"><?php echo esc_html($session['date_range']); ?></span>
                                            <?php endif; ?>
                                            <?php if (
                                                $session_is_coming_soon &&
                                                self::truthy($atts['show_registration_dates']) &&
                                                ($session['registration_open'] || $session['registration_close'])
                                            ): ?>
                                                <span class="ifprog-session__registration">
                                                    <?php if ($session['registration_open']): ?>
                                                        <span>Registration opens <?php echo esc_html($session['registration_open']); ?></span>
                                                    <?php endif; ?>
                                                    <?php if ($session['registration_open'] && $session['registration_close']): ?>
                                                        <span class="ifprog-session__separator" aria-hidden="true">•</span>
                                                    <?php endif; ?>
                                                    <?php if ($session['registration_close']): ?>
                                                        <span>Registration closes <?php echo esc_html($session['registration_close']); ?></span>
                                                    <?php endif; ?>
                                                </span>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                </div>
                                <span
                                    class="ifprog-shortcut-button ifprog-shortcut-button--session ifprog-shortcut-button--<?php echo $session_is_closed ? 'closed' : 'soon'; ?>"
                                    role="status"
                                    aria-label="<?php echo esc_attr($session_static_label . ' for ' . $session['title']); ?>"
                                ><?php echo esc_html($session_static_label); ?></span>
                            </div>
                        <?php else: ?>
                            <div class="ifprog-session-wrap<?php echo ($session_has_action || $session_has_upcoming_status) ? ' ifprog-session-wrap--has-action' : ''; ?>">
                                <details class="ifprog-session" <?php echo $session_open ? 'open' : ''; ?>>
                                    <summary class="ifprog-session__summary">
                                        <span class="ifprog-session__summary-copy">
                                            <h3 class="ifprog-session__title"><?php echo esc_html($session['title']); ?></h3>
                                            <?php if (self::truthy($atts['show_dates']) && $session['date_range']): ?>
                                                <span class="ifprog-session__dates"><?php echo esc_html($session['date_range']); ?></span>
                                            <?php endif; ?>
                                            <?php if (
                                                self::truthy($atts['show_registration_dates']) &&
                                                ($session['registration_open'] || $session['registration_close'] || $session['registration_closed'])
                                            ): ?>
                                                <span class="ifprog-session__registration">
                                                    <?php if ($session['registration_open']): ?>
                                                        <span>Registration <?php echo $session['registration_opened'] ? 'opened on' : 'opens'; ?> <?php echo esc_html($session['registration_open']); ?></span>
                                                    <?php endif; ?>
                                                    <?php if ($session['registration_open'] && $session['registration_close']): ?>
                                                        <span class="ifprog-session__separator" aria-hidden="true">•</span>
                                                    <?php endif; ?>
                                                    <?php if ($session['registration_close']): ?>
                                                        <span>Registration closes <?php echo esc_html($session['registration_close']); ?></span>
                                                    <?php endif; ?>
                                                </span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="ifprog-toggle" aria-hidden="true"></span>
                                    </summary>
                                    <div class="ifprog-session__panel">
                                        <?php if (!empty($settings['show_season_descriptions']) && $session['description']): ?>
                                            <div class="ifprog-session__description"><?php echo wp_kses_post(wpautop($session['description'])); ?></div>
                                        <?php endif; ?>
                                        <?php if (self::truthy($atts['group_levels'])): ?>
                                            <?php if (self::truthy($atts['group_program_groups'])): ?>
                                                <div class="ifprog-program-groups">
                                                    <?php foreach ($session['program_groups'] as $program_group): ?>
                                                        <?php if (!empty($program_group['unassigned'])): ?>
                                                            <?php self::levels($program_group['levels'], $atts, $settings); ?>
                                                        <?php else: ?>
                                                            <details class="ifprog-program-group" <?php echo self::truthy($atts['open_groups']) ? 'open' : ''; ?>>
                                                                <summary class="ifprog-program-group__summary">
                                                                    <h4 class="ifprog-program-group__title"><?php echo esc_html($program_group['title']); ?></h4>
                                                                    <span class="ifprog-toggle" aria-hidden="true"></span>
                                                                </summary>
                                                                <div class="ifprog-program-group__panel">
                                                                    <?php if (!empty($settings['show_group_descriptions']) && $program_group['description']): ?>
                                                                        <div class="ifprog-program-group__description"><?php echo wp_kses_post(wpautop($program_group['description'])); ?></div>
                                                                    <?php endif; ?>
                                                                    <?php self::levels($program_group['levels'], $atts, $settings); ?>
                                                                </div>
                                                            </details>
                                                        <?php endif; ?>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else: ?>
                                                <?php self::levels($session['levels'], $atts, $settings); ?>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <div class="ifprog-classes">
                                                <?php foreach ($session['programs'] as $program):
                                                    self::program($program, [
                                                        'show_price' => self::truthy($atts['show_price']),
                                                        'settings' => $settings,
                                                    ]);
                                                endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </details>
                                <?php if ($session_has_action): ?>
                                    <a
                                        class="ifprog-shortcut-button ifprog-shortcut-button--session"
                                        href="<?php echo esc_url($session['registration_url']); ?>"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        aria-label="<?php echo esc_attr($settings['default_button_label'] . ' for ' . $session['title']); ?>"
                                    ><?php echo esc_html($settings['default_button_label']); ?></a>
                                <?php elseif ($session_has_upcoming_status): ?>
                                    <span
                                        class="ifprog-shortcut-button ifprog-shortcut-button--session ifprog-shortcut-button--soon ifprog-shortcut-button--accordion-status"
                                        role="status"
                                        aria-label="<?php echo esc_attr($session_upcoming_label . ' for ' . $session['title']); ?>"
                                    ><?php echo esc_html($session_upcoming_label); ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php
        return ob_get_clean();
    }

    private static function levels($groups, $atts, $settings) {
        ?>
        <div class="ifprog-levels">
            <?php foreach ($groups as $group):
                $level_has_action = $group['registration_url'] !== '';
                $level_is_coming_soon = !$level_has_action &&
                    !empty($group['is_league']) &&
                    ($group['registration_state'] ?? '') === 'coming_soon';
                ?>
                <div class="ifprog-level-wrap<?php echo ($level_has_action || $level_is_coming_soon) ? ' ifprog-level-wrap--has-action' : ''; ?>">
                    <details class="ifprog-level" <?php echo self::truthy($atts['open_levels']) ? 'open' : ''; ?>>
                        <summary class="ifprog-level__summary">
                            <span class="ifprog-level__summary-copy">
                                <h5 class="ifprog-level__title"><?php echo esc_html($group['title']); ?></h5>
                                <?php if ($group['age_range']): ?><span class="ifprog-level__ages"><?php echo esc_html($group['age_range']); ?></span><?php endif; ?>
                            </span>
                            <span class="ifprog-toggle" aria-hidden="true"></span>
                        </summary>
                        <div class="ifprog-level__panel">
                            <?php if (!empty($settings['show_level_descriptions']) && $group['description']): ?>
                                <div class="ifprog-level__description"><?php echo wp_kses_post(wpautop($group['description'])); ?></div>
                            <?php endif; ?>
                            <?php if ($group['programs']): ?>
                                <div class="ifprog-classes">
                                    <?php foreach ($group['programs'] as $program):
                                        self::program($program, [
                                            'show_price' => self::truthy($atts['show_price']),
                                            'settings' => $settings,
                                        ]);
                                    endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </details>
                    <?php if ($level_has_action): ?>
                        <a
                            class="ifprog-shortcut-button ifprog-shortcut-button--level"
                            href="<?php echo esc_url($group['registration_url']); ?>"
                            target="_blank"
                            rel="noopener noreferrer"
                            aria-label="<?php echo esc_attr($settings['default_button_label'] . ' for ' . $group['title']); ?>"
                        ><?php echo esc_html($settings['default_button_label']); ?></a>
                    <?php elseif ($level_is_coming_soon): ?>
                        <span
                            class="ifprog-shortcut-button ifprog-shortcut-button--level ifprog-shortcut-button--soon ifprog-shortcut-button--accordion-status"
                            role="status"
                            aria-label="<?php echo esc_attr($settings['coming_soon_label'] . ' for ' . $group['title']); ?>"
                        ><?php echo esc_html($settings['coming_soon_label']); ?></span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    private static function program($program, $display) {
        $post_id = $program->ID;
        $state = IFPROG_Status::program_state($post_id);
        $excerpt = trim((string) get_the_excerpt($program));
        $content = trim((string) $program->post_content);
        $schedule = IFPROG_Fields::get($post_id, 'schedule');
        $level_id = absint(IFPROG_Fields::get($post_id, 'level_id'));
        if ($level_id && $content) {
            $level_post = get_post($level_id);
            $level_description = $level_post ? trim((string) $level_post->post_content) : '';
            if ($level_description && self::same_description($content, $level_description)) {
                $generated_excerpt = sanitize_text_field(wp_trim_words(wp_strip_all_tags($content), 24));
                if (self::same_description($excerpt, $generated_excerpt)) $excerpt = '';
                $content = '';
            }
        }
        if (!$excerpt && $content) {
            $excerpt = sanitize_text_field(wp_trim_words(wp_strip_all_tags($content), 22));
        }
        $price = IFPROG_Fields::get($post_id, 'price');
        $weeks = absint(IFPROG_Fields::get($post_id, 'weeks'));
        $is_single_class = $weeks === 1;
        $is_drop_in = self::object_matches_terms($post_id, 'ifprog_format', ['drop-in']);
        $numeric_price = preg_replace('/[^0-9.\-]/', '', (string) $price);
        $is_free_one_day = $is_single_class
            && $numeric_price !== ''
            && is_numeric($numeric_price)
            && (float) $numeric_price === 0.0;
        $drop_in_unit_price = '';
        $drop_in_remaining_price = '';
        $drop_in_remaining_classes = null;
        if (
            $is_drop_in &&
            $weeks > 0 &&
            $numeric_price !== '' &&
            is_numeric($numeric_price) &&
            (float) $numeric_price > 0
        ) {
            $unit_price = (float) $numeric_price / $weeks;
            $drop_in_unit_price = self::currency($unit_price);
            $drop_in_remaining_classes = self::program_remaining_occurrence_count(
                $program,
                current_datetime()->getTimestamp()
            );
            if ($drop_in_remaining_classes !== null && $drop_in_remaining_classes > 0) {
                $drop_in_remaining_price = self::currency($unit_price * $drop_in_remaining_classes);
            }
        }
        $show_price = $display['show_price'] && !$is_free_one_day;
        $schedule_label = 'Day & Time';
        if ($is_free_one_day) {
            $start_timestamp = IFPROG_Status::timestamp(IFPROG_Fields::get($post_id, 'start_date'));
            if ($start_timestamp) {
                $schedule_label = 'Event Date & Time';
                $time_range = preg_replace('/^[^,]+,\s*/', '', (string) $schedule);
                $schedule = wp_date(get_option('date_format'), $start_timestamp);
                if ($time_range !== '') $schedule .= ' at ' . $time_range;
            }
        }
        $registration_url = esc_url_raw((string) IFPROG_Fields::get($post_id, 'registration_url'));
        $registration_overrides = IFPROG_Fields::overrides($post_id);
        if (
            !in_array('registration_url', $registration_overrides, true) &&
            get_post_meta($post_id, '_ifprog_dash_source_type', true) === 'team'
        ) {
            $team_url = IFPROG_Dash::team_registration_url(
                absint(get_post_meta($post_id, '_ifprog_dash_source_id', true))
            );
            if ($team_url !== '') $registration_url = $team_url;
        }
        $button_label = IFPROG_Fields::get($post_id, 'button_label') ?: $display['settings']['default_button_label'];
        $show_button = $state === 'open' && $registration_url !== '';
        $show_action = $show_button || $state !== 'open';
        ?>
        <article class="ifprog-class ifprog-class--<?php echo esc_attr(str_replace('_', '-', $state)); ?><?php echo $show_price ? '' : ' ifprog-class--no-price'; ?><?php echo $show_action ? ' ifprog-class--has-action' : ''; ?>">
            <div class="ifprog-class__copy">
                <h6 class="ifprog-class__title"><?php echo esc_html($program->post_title); ?></h6>
                <?php if (!empty($display['settings']['show_program_descriptions']) && $excerpt): ?>
                    <p class="ifprog-class__description"><?php echo esc_html($excerpt); ?></p>
                <?php endif; ?>
            </div>
            <div class="ifprog-class__schedule">
                <span class="ifprog-class__label"><?php echo esc_html($schedule_label); ?></span>
                <span><?php echo esc_html($schedule ?: 'Schedule coming soon'); ?></span>
            </div>
            <?php if ($show_price): ?>
                <div class="ifprog-class__price">
                    <?php if ($drop_in_unit_price !== ''): ?>
                        <span class="ifprog-class__label">Drop-In Pricing</span>
                        <span class="ifprog-class__price-value ifprog-class__price-value--drop-in">
                            <span class="ifprog-class__price-option">
                                <strong><?php echo esc_html($drop_in_unit_price); ?></strong>
                                <em class="ifprog-class__price-duration">per class</em>
                            </span>
                            <?php if ($drop_in_remaining_price !== ''): ?>
                                <span class="ifprog-class__price-option">
                                    <span class="ifprog-class__price-or">or</span>
                                    <strong><?php echo esc_html($drop_in_remaining_price); ?></strong>
                                    <em class="ifprog-class__price-duration">for remaining <?php echo esc_html($drop_in_remaining_classes); ?> <?php echo $drop_in_remaining_classes === 1 ? 'week' : 'weeks'; ?></em>
                                </span>
                            <?php endif; ?>
                        </span>
                    <?php else: ?>
                        <span class="ifprog-class__label"><?php echo esc_html($is_single_class ? 'Per Class' : 'Session Price'); ?></span>
                        <span class="ifprog-class__price-value">
                            <strong><?php echo esc_html($price ?: 'TBA'); ?></strong>
                            <?php if ($weeks > 1): ?>
                                <em class="ifprog-class__price-duration">for <?php echo esc_html($weeks); ?> weeks</em>
                            <?php endif; ?>
                        </span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if ($show_action): ?>
                <div class="ifprog-class__action">
                    <?php if ($show_button): ?>
                        <a class="ifprog-class__button" href="<?php echo esc_url($registration_url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($button_label); ?></a>
                    <?php else: ?>
                        <span class="ifprog-class__status"><?php echo esc_html(IFPROG_Status::state_label($state)); ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </article>
        <?php
    }

    private static function add_tax_filter(&$tax_query, $taxonomy, $raw) {
        $terms = is_array($raw) ? $raw : self::csv_slugs($raw);
        if (!$terms) return;
        $term_ids = IFPROG_Post_Types::matching_term_ids($taxonomy, $terms);
        if ($term_ids) {
            $tax_query[] = [
                'taxonomy' => $taxonomy,
                'field' => 'term_id',
                'terms' => $term_ids,
                'operator' => 'IN',
            ];
        } else {
            $tax_query[] = [
                'taxonomy' => $taxonomy,
                'field' => 'slug',
                'terms' => $terms,
                'operator' => 'IN',
            ];
        }
    }

    private static function season_ids($season) {
        $season = trim((string) $season);
        if ($season === '' || strtolower($season) === 'all') return null;

        $selectors = array_values(array_filter(array_map('trim', explode(',', $season))));
        if (count($selectors) > 1) {
            $ids = [];
            foreach ($selectors as $selector) {
                $selected_ids = self::season_ids($selector);
                if ($selected_ids === null) return null;
                $ids = array_merge($ids, $selected_ids);
            }
            return array_values(array_unique(array_map('absint', $ids)));
        }

        if (strtolower($season) === 'current') {
            return IFPROG_Status::current_season_ids(true);
        }

        if (in_array(strtolower($season), ['upcoming','future','completed'], true)) {
            $selected_status = strtolower($season) === 'future' ? 'upcoming' : strtolower($season);
            $season_ids = get_posts([
                'post_type' => 'ifprog_season',
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'fields' => 'ids',
            ]);
            return array_values(array_filter(array_map('absint', $season_ids), function($season_id) use ($selected_status) {
                return IFPROG_Status::effective_season_status($season_id) === $selected_status;
            }));
        }

        if (ctype_digit($season)) {
            $post_id = absint($season);
            return get_post_type($post_id) === 'ifprog_season' ? [$post_id] : [];
        }

        $season_post = get_page_by_path(sanitize_title($season), OBJECT, 'ifprog_season');
        return $season_post && $season_post->post_status === 'publish' ? [$season_post->ID] : [];
    }

    private static function empty_upcoming_seasons($season_ids, $allowed_states, $sport_filters = [], $format_filters = []) {
        if (!in_array('coming_soon', $allowed_states, true) || $season_ids === []) return [];

        $linked_season_ids = [];
        $program_ids = get_posts([
            'post_type' => 'ifprog_program',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
        ]);
        foreach (array_map('absint', $program_ids) as $program_id) {
            $season_id = absint(IFPROG_Fields::get($program_id, 'season_id'));
            if ($season_id) $linked_season_ids[$season_id] = true;
        }
        unset($program_ids);

        $args = [
            'post_type' => 'ifprog_season',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'orderby' => 'title',
            'order' => 'ASC',
            'no_found_rows' => true,
        ];
        if ($season_ids !== null) {
            $args['post__in'] = array_values(array_map('absint', $season_ids));
        }

        $empty_seasons = [];
        foreach (array_map('absint', get_posts($args)) as $season_id) {
            if (
                !$season_id ||
                !empty($linked_season_ids[$season_id]) ||
                IFPROG_Status::effective_season_status($season_id) !== 'upcoming' ||
                !self::season_matches_tax_filter($season_id, 'ifprog_sport', $sport_filters) ||
                !self::season_matches_tax_filter($season_id, 'ifprog_format', $format_filters)
            ) {
                continue;
            }

            $season = get_post($season_id);
            if ($season instanceof WP_Post) $empty_seasons[] = $season;
        }
        return $empty_seasons;
    }

    private static function season_matches_tax_filter($season_id, $taxonomy, $filters) {
        if (!$filters) return true;

        $assigned = wp_get_object_terms($season_id, $taxonomy);
        if (!is_wp_error($assigned) && $assigned) {
            $assigned_filters = [];
            foreach ($assigned as $term) {
                $assigned_filters[] = sanitize_title($term->slug);
                $assigned_filters[] = sanitize_title($term->name);
            }
            return (bool) array_intersect($filters, array_unique($assigned_filters));
        }

        $payload = get_post_meta($season_id, '_ifprog_dash_payload', true);
        if (!is_array($payload)) $payload = [];
        if (empty($payload['name'])) $payload['name'] = get_the_title($season_id);
        if (empty($payload['title'])) $payload['title'] = get_the_title($season_id);
        $classification = IFPROG_Preview::season_classification($payload);
        $season_terms = $taxonomy === 'ifprog_sport'
            ? $classification['sports']
            : $classification['formats'];
        return (bool) array_intersect($filters, $season_terms);
    }

    private static function states($raw) {
        if (strtolower(trim((string) $raw)) === 'all') {
            return IFPROG_Status::public_program_states();
        }
        $states = array_map('sanitize_key', preg_split('/[\s,]+/', (string) $raw));
        $states = array_values(array_intersect($states, IFPROG_Status::public_program_states()));
        return $states ?: ['coming_soon','open'];
    }

    private static function level_filters($raw) {
        $raw = trim((string) $raw);
        if ($raw === '' || strtolower($raw) === 'all') return null;
        return array_values(array_unique(array_filter(array_map(function($value) {
            $value = trim((string) $value);
            return ctype_digit($value) ? (string) absint($value) : sanitize_title($value);
        }, preg_split('/\s*,\s*/', $raw)))));
    }

    private static function program_matches_level($post_id, $filters) {
        if ($filters === null) return true;

        $level_id = absint(IFPROG_Fields::get($post_id, 'level_id'));
        if ($level_id) {
            if (in_array((string) $level_id, $filters, true)) return true;
            $level = get_post($level_id);
            if ($level && in_array($level->post_name, $filters, true)) return true;
        }

        $fallback = sanitize_title((string) IFPROG_Fields::get($post_id, 'level'));
        return $fallback !== '' && in_array($fallback, $filters, true);
    }

    private static function program_matches_category($post_id, $filters) {
        if (!$filters) return true;

        $terms = get_the_terms($post_id, 'ifprog_category');
        $terms = $terms && !is_wp_error($terms) ? array_values($terms) : [];

        $level_id = absint(IFPROG_Fields::get($post_id, 'level_id'));
        if ($level_id) {
            $level_terms = get_the_terms($level_id, 'ifprog_category');
            if ($level_terms && !is_wp_error($level_terms)) {
                $terms = array_merge($terms, array_values($level_terms));
            }
        }

        foreach ($terms as $term) {
            if (
                in_array((string) $term->term_id, $filters, true) ||
                in_array(sanitize_title($term->slug), $filters, true) ||
                in_array(sanitize_title($term->name), $filters, true)
            ) {
                return true;
            }
        }
        return false;
    }

    private static function program_matches_group($post_id, $filters) {
        if ($filters === null) return true;
        foreach (self::program_group_terms($post_id) as $term) {
            if (
                in_array((string) $term->term_id, $filters, true) ||
                in_array($term->slug, $filters, true)
            ) {
                return true;
            }
        }
        return false;
    }

    private static function standalone_levels(
        $season_ids,
        $allowed_states,
        $sport_filters,
        $format_filters,
        $category_filters,
        $level_filters,
        $group_filters
    ) {
        $linked_level_ids = [];
        $program_ids = get_posts([
            'post_type' => 'ifprog_program',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
        ]);
        foreach (array_map('absint', $program_ids) as $program_id) {
            $level_id = absint(IFPROG_Fields::get($program_id, 'level_id'));
            if ($level_id) $linked_level_ids[$level_id] = true;
        }

        $levels = get_posts([
            'post_type' => 'ifprog_level',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => [
                'menu_order' => 'ASC',
                'title' => 'ASC',
            ],
            'order' => 'ASC',
            'no_found_rows' => true,
        ]);

        return array_values(array_filter($levels, function($level) use (
            $linked_level_ids,
            $season_ids,
            $allowed_states,
            $sport_filters,
            $format_filters,
            $category_filters,
            $level_filters,
            $group_filters
        ) {
            if (!empty($linked_level_ids[$level->ID])) return false;

            $season_id = absint(get_post_meta($level->ID, '_ifprog_level_season_id', true));
            if (!$season_id || get_post_status($season_id) !== 'publish') return false;
            if ($season_ids !== null && !in_array($season_id, $season_ids, true)) return false;
            if (!in_array(self::standalone_level_state($season_id), $allowed_states, true)) return false;
            if (!self::season_matches_tax_filter($season_id, 'ifprog_sport', $sport_filters)) return false;
            if (!self::season_matches_tax_filter($season_id, 'ifprog_format', $format_filters)) return false;
            if (!self::object_matches_terms($level->ID, 'ifprog_category', $category_filters)) return false;
            if (!self::level_matches_filter($level, $level_filters)) return false;
            if (!self::object_matches_terms($level->ID, 'ifprog_group', $group_filters)) return false;
            return true;
        }));
    }

    private static function standalone_level_state($season_id) {
        $status = IFPROG_Status::effective_season_status(absint($season_id));
        if ($status === 'completed') return 'closed';
        if ($status === 'current') return 'open';
        return 'coming_soon';
    }

    private static function level_matches_filter($level, $filters) {
        if ($filters === null) return true;
        return in_array((string) $level->ID, $filters, true) ||
            in_array(sanitize_title($level->post_name), $filters, true);
    }

    private static function object_matches_terms($post_id, $taxonomy, $filters) {
        if ($filters === null || $filters === []) return true;
        $terms = get_the_terms($post_id, $taxonomy);
        if (!$terms || is_wp_error($terms)) return false;
        foreach ($terms as $term) {
            if (
                in_array((string) $term->term_id, $filters, true) ||
                in_array(sanitize_title($term->slug), $filters, true) ||
                in_array(sanitize_title($term->name), $filters, true)
            ) {
                return true;
            }
        }
        return false;
    }

    private static function program_group_terms($post_id) {
        $level_id = absint(IFPROG_Fields::get($post_id, 'level_id'));
        if (!$level_id) return [];
        return self::level_group_terms($level_id);
    }

    private static function level_group_terms($level_id) {
        $terms = get_the_terms(absint($level_id), 'ifprog_group');
        return $terms && !is_wp_error($terms) ? array_values($terms) : [];
    }

    private static function session_groups($programs, $group_filters = null, $empty_seasons = [], $standalone_levels = []) {
        $sessions = [];
        foreach ($empty_seasons as $season) {
            $sessions['season-' . $season->ID] = self::session_record($season);
        }

        foreach ($programs as $program) {
            $season_id = absint(IFPROG_Fields::get($program->ID, 'season_id'));
            $season = $season_id && get_post_type($season_id) === 'ifprog_season' ? get_post($season_id) : null;
            $key = $season ? 'season-' . $season_id : 'season-unassigned';
            if (!isset($sessions[$key])) {
                $sessions[$key] = self::session_record($season);
            }
            $sessions[$key]['programs'][] = $program;
        }

        foreach ($standalone_levels as $level) {
            $season_id = absint(get_post_meta($level->ID, '_ifprog_level_season_id', true));
            $season = $season_id && get_post_type($season_id) === 'ifprog_season' ? get_post($season_id) : null;
            $key = $season ? 'season-' . $season_id : 'season-unassigned';
            if (!isset($sessions[$key])) {
                $sessions[$key] = self::session_record($season);
            }
            $sessions[$key]['standalone_levels'][] = $level;
        }

        foreach ($sessions as &$session) {
            $session['programs'] = self::sort_programs_by_schedule($session['programs']);
            foreach ($session['programs'] as $program) {
                if (self::object_matches_terms($program->ID, 'ifprog_format', ['league'])) {
                    $session['is_league'] = true;
                    break;
                }
            }
            $session['registration_url'] = self::season_registration_url(
                $session['season_id'],
                $session['programs'],
                $session['standalone_levels']
            );
            $session['levels'] = array_values(self::level_groups(
                $session['programs'],
                false,
                $session['standalone_levels']
            ));
            $session['program_groups'] = array_values(self::program_group_groups(
                $session['programs'],
                $group_filters,
                $session['standalone_levels']
            ));
        }
        unset($session);

        uasort($sessions, function($a, $b) {
            if ($a['registration_closed'] !== $b['registration_closed']) {
                return $a['registration_closed'] <=> $b['registration_closed'];
            }
            if ($a['sort_date'] !== $b['sort_date']) return $a['sort_date'] <=> $b['sort_date'];
            if ($a['sort_end_date'] !== $b['sort_end_date']) return $a['sort_end_date'] <=> $b['sort_end_date'];
            if ($a['order'] !== $b['order']) return $a['order'] <=> $b['order'];
            return strcasecmp($a['title'], $b['title']);
        });
        return array_values($sessions);
    }

    private static function session_record($season) {
        $season_id = $season ? absint($season->ID) : 0;
        $start_date = $season ? get_post_meta($season_id, '_ifprog_season_start_date', true) : '';
        $end_date = $season ? get_post_meta($season_id, '_ifprog_season_end_date', true) : '';
        $registration_open = $season ? get_post_meta($season_id, '_ifprog_season_registration_open', true) : '';
        $registration_close = $season ? get_post_meta($season_id, '_ifprog_season_registration_close', true) : '';

        return [
            'season_id' => $season_id,
            'season_status' => $season ? IFPROG_Status::effective_season_status($season_id) : 'current',
            'title' => $season ? $season->post_title : 'Programs',
            'description' => $season ? trim((string) $season->post_content) : '',
            'date_range' => self::date_range($start_date, $end_date),
            'registration_open' => self::date_range($registration_open, ''),
            'registration_close' => self::date_range($registration_close, ''),
            'registration_opened' => $season ? IFPROG_Status::season_registration_opened($season_id) : false,
            'registration_closed' => $season ? IFPROG_Status::season_registration_closed($season_id) : false,
            'order' => $season ? self::season_order($season_id) : 9999,
            'sort_date' => IFPROG_Status::timestamp($start_date) ?: PHP_INT_MAX,
            'sort_end_date' => IFPROG_Status::timestamp($end_date) ?: PHP_INT_MAX,
            'is_league' => $season_id
                ? self::season_matches_tax_filter($season_id, 'ifprog_format', ['league'])
                : false,
            'registration_url' => '',
            'programs' => [],
            'standalone_levels' => [],
            'levels' => [],
            'program_groups' => [],
        ];
    }

    private static function season_order($season_id) {
        $order = get_post_meta(absint($season_id), '_ifprog_season_order', true);
        return $order === '' ? 100 : min(9999, absint($order));
    }

    private static function program_group_groups($programs, $filters = null, $standalone_levels = []) {
        $groups = [];
        foreach ($programs as $program) {
            $terms = self::program_group_terms($program->ID);
            if ($filters !== null) {
                $terms = array_values(array_filter($terms, function($term) use ($filters) {
                    return in_array((string) $term->term_id, $filters, true) ||
                        in_array($term->slug, $filters, true);
                }));
            }
            if (!$terms) $terms = [null];

            foreach ($terms as $term) {
                if ($term) {
                    $key = 'group-' . $term->term_id;
                    $title = $term->name;
                    $description = trim((string) $term->description);
                    $saved_order = get_term_meta($term->term_id, '_ifprog_group_order', true);
                    $order = $saved_order === '' ? 100 : absint($saved_order);
                    $unassigned = 0;
                } else {
                    $key = 'group-unassigned';
                    $title = 'Other Programs';
                    $description = '';
                    $order = 9999;
                    $unassigned = 1;
                }
                if (!isset($groups[$key])) {
                    $groups[$key] = [
                        'title' => $title,
                        'description' => $description,
                        'order' => $order,
                        'unassigned' => $unassigned,
                        'programs' => [],
                        'levels' => [],
                    ];
                }
                $groups[$key]['programs'][] = $program;
            }
        }

        foreach ($groups as &$group) {
            $group['levels'] = array_values(self::level_groups(
                $group['programs'],
                empty($group['unassigned'])
            ));
            unset($group['programs']);
        }
        unset($group);

        foreach ($standalone_levels as $level) {
            $terms = self::level_group_terms($level->ID);
            if ($filters !== null) {
                $terms = array_values(array_filter($terms, function($term) use ($filters) {
                    return in_array((string) $term->term_id, $filters, true) ||
                        in_array($term->slug, $filters, true);
                }));
            }
            if (!$terms) $terms = [null];

            foreach ($terms as $term) {
                if ($term) {
                    $key = 'group-' . $term->term_id;
                    $title = $term->name;
                    $description = trim((string) $term->description);
                    $saved_order = get_term_meta($term->term_id, '_ifprog_group_order', true);
                    $order = $saved_order === '' ? 100 : absint($saved_order);
                    $unassigned = 0;
                } else {
                    $key = 'group-unassigned';
                    $title = 'Other Programs';
                    $description = '';
                    $order = 9999;
                    $unassigned = 1;
                }
                if (!isset($groups[$key])) {
                    $groups[$key] = [
                        'title' => $title,
                        'description' => $description,
                        'order' => $order,
                        'unassigned' => $unassigned,
                        'levels' => [],
                    ];
                }
                $groups[$key]['levels'][] = self::level_record($level);
            }
        }

        foreach ($groups as &$group) {
            $group['levels'] = self::sort_level_records(
                $group['levels'],
                empty($group['unassigned'])
            );
        }
        unset($group);

        uasort($groups, function($a, $b) {
            if ($a['unassigned'] !== $b['unassigned']) return $a['unassigned'] <=> $b['unassigned'];
            if ($a['order'] !== $b['order']) return $a['order'] <=> $b['order'];
            return strcasecmp($a['title'], $b['title']);
        });
        return $groups;
    }

    private static function level_groups($programs, $sort_by_age = false, $standalone_levels = []) {
        $groups = [];
        foreach ($programs as $program) {
            $season_id = absint(IFPROG_Fields::get($program->ID, 'season_id'));
            $level_id = absint(IFPROG_Fields::get($program->ID, 'level_id'));
            $level = $level_id && get_post_type($level_id) === 'ifprog_level' ? get_post($level_id) : null;
            $fallback = trim((string) IFPROG_Fields::get($program->ID, 'level'));

            if ($level) {
                $key = 'level-' . $level_id;
                $title = $level->post_title;
                $description = $level->post_status === 'publish' ? trim((string) $level->post_content) : '';
                $age_range = IFPROG_Dash::level_age_range($level_id);
                $order = absint($level->menu_order);
            } else {
                $key = 'fallback-' . $season_id . '-' . sanitize_title($fallback ?: 'other-programs');
                $title = $fallback ?: 'Other Programs';
                $description = '';
                $age_range = '';
                $order = 9999;
            }

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'season_id' => $season_id,
                    'level_id' => $level_id,
                    'title' => $title,
                    'description' => $description,
                    'age_range' => $age_range,
                    'order' => $order,
                    'programs' => [],
                    'registration_state' => '',
                    'is_league' => false,
                ];
            }
            $groups[$key]['programs'][] = $program;
            if (self::object_matches_terms($program->ID, 'ifprog_format', ['league'])) {
                $groups[$key]['is_league'] = true;
            }
        }

        foreach ($standalone_levels as $level) {
            $groups['level-' . $level->ID] = self::level_record($level);
        }

        $groups = self::sort_level_records($groups, $sort_by_age);

        foreach ($groups as &$group) {
            $group['registration_state'] = self::level_program_state($group['programs']);
            $group['registration_url'] = self::level_registration_url(
                $group['level_id'],
                $group['programs'],
                $group['season_id']
            );
        }
        unset($group);

        return $groups;
    }

    private static function level_record($level) {
        $season_id = absint(get_post_meta($level->ID, '_ifprog_level_season_id', true));
        $record = [
            'season_id' => $season_id,
            'level_id' => absint($level->ID),
            'title' => $level->post_title,
            'description' => trim((string) $level->post_content),
            'age_range' => IFPROG_Dash::level_age_range($level->ID),
            'order' => absint($level->menu_order),
            'programs' => [],
            'registration_state' => self::standalone_level_state($season_id),
            'is_league' => $season_id
                ? self::season_matches_tax_filter($season_id, 'ifprog_format', ['league'])
                : false,
            'registration_url' => '',
        ];
        $record['registration_url'] = self::level_registration_url(
            $record['level_id'],
            [],
            $season_id
        );
        return $record;
    }

    private static function level_program_state($programs) {
        $states = [];
        foreach ($programs as $program) {
            $states[] = IFPROG_Status::program_state($program->ID);
        }
        if (in_array('open', $states, true)) return 'open';
        if (in_array('coming_soon', $states, true)) return 'coming_soon';
        if (in_array('closed', $states, true)) return 'closed';
        return '';
    }

    private static function sort_level_records($groups, $sort_by_age = false) {
        uasort($groups, function($a, $b) use ($sort_by_age) {
            if ($a['season_id'] !== $b['season_id']) return $a['season_id'] <=> $b['season_id'];
            if ($sort_by_age) {
                $a_age = self::age_range_sort_key($a['age_range']);
                $b_age = self::age_range_sort_key($b['age_range']);
                if ($a_age[0] !== $b_age[0]) return $a_age[0] <=> $b_age[0];
                if ($a_age[1] !== $b_age[1]) return $a_age[1] <=> $b_age[1];
            }
            if ($a['order'] !== $b['order']) return $a['order'] <=> $b['order'];
            return strcasecmp($a['title'], $b['title']);
        });
        return $groups;
    }

    private static function age_range_sort_key($age_range) {
        $age_range = trim((string) $age_range);
        if ($age_range === '') return [PHP_INT_MAX, PHP_INT_MAX];

        preg_match_all('/\d+/', $age_range, $matches);
        $ages = array_map('intval', $matches[0] ?? []);
        if (!$ages) return [PHP_INT_MAX, PHP_INT_MAX];

        if (stripos($age_range, 'up to') !== false) {
            return [0, $ages[0]];
        }
        if (count($ages) === 1) {
            return [$ages[0], strpos($age_range, '+') !== false ? PHP_INT_MAX : $ages[0]];
        }
        return [$ages[0], $ages[1]];
    }

    private static function sort_programs_by_schedule($programs) {
        $sortable = [];
        foreach ((array) $programs as $program) {
            $sortable[] = [
                'program' => $program,
                'key' => self::program_schedule_sort_key($program),
            ];
        }

        usort($sortable, function($a, $b) {
            foreach (['day','time','order'] as $field) {
                if ($a['key'][$field] !== $b['key'][$field]) {
                    return $a['key'][$field] <=> $b['key'][$field];
                }
            }
            $title = strcasecmp($a['key']['title'], $b['key']['title']);
            return $title !== 0 ? $title : ($a['key']['id'] <=> $b['key']['id']);
        });

        return array_values(array_map(function($row) {
            return $row['program'];
        }, $sortable));
    }

    private static function program_schedule_sort_key($program) {
        $schedule = strtolower(trim((string) IFPROG_Fields::get($program->ID, 'schedule')));
        $day = 8;
        foreach ([
            1 => '/\bmonday(?:s)?\b/i',
            2 => '/\btuesday(?:s)?\b/i',
            3 => '/\bwednesday(?:s)?\b/i',
            4 => '/\bthursday(?:s)?\b/i',
            5 => '/\bfriday(?:s)?\b/i',
            6 => '/\bsaturday(?:s)?\b/i',
            7 => '/\bsunday(?:s)?\b/i',
        ] as $rank => $pattern) {
            if (preg_match($pattern, $schedule)) {
                $day = $rank;
                break;
            }
        }

        $time = 24 * 60;
        if (preg_match('/\b(\d{1,2})(?::([0-5]\d))?\s*([ap])\.?m\.?\b/i', $schedule, $match)) {
            $hour = absint($match[1]) % 12;
            if (strtolower($match[3]) === 'p') $hour += 12;
            $time = ($hour * 60) + absint($match[2] ?? 0);
        } elseif (preg_match('/\b([01]?\d|2[0-3]):([0-5]\d)\b/', $schedule, $match)) {
            $time = (absint($match[1]) * 60) + absint($match[2]);
        }

        return [
            'day' => $day,
            'time' => $time,
            'order' => absint($program->menu_order),
            'title' => (string) $program->post_title,
            'id' => absint($program->ID),
        ];
    }

    private static function program_has_remaining_occurrence($program, $now) {
        $remaining = self::program_remaining_occurrence_count($program, $now);
        return $remaining === null || $remaining > 0;
    }

    private static function program_remaining_occurrence_count($program, $now) {
        $post_id = absint($program->ID);
        $weeks = absint(IFPROG_Fields::get($post_id, 'weeks'));
        $season_id = absint(IFPROG_Fields::get($post_id, 'season_id'));
        if (!$season_id || IFPROG_Status::effective_season_status($season_id, $now) !== 'current') {
            return $weeks ?: null;
        }

        $level_id = absint(IFPROG_Fields::get($post_id, 'level_id'));
        if (
            $level_id &&
            absint(get_post_meta($level_id, '_ifprog_dash_league_id', true)) === IFPROG_Sync::CURRENT_LEARN_TO_PLAY_LEAGUE_ID
        ) {
            return null;
        }

        $timezone = wp_timezone();
        $today = (new DateTimeImmutable('@' . absint($now)))
            ->setTimezone($timezone)
            ->setTime(0, 0, 0);
        $today_timestamp = $today->getTimestamp();
        $start = IFPROG_Status::timestamp(IFPROG_Fields::get($post_id, 'start_date'));
        $end = IFPROG_Status::timestamp(IFPROG_Fields::get($post_id, 'end_date'), true);
        if ($end && $end < $today_timestamp) return 0;

        if ($weeks === 1 && $start) {
            $event_day = (new DateTimeImmutable('@' . $start))
                ->setTimezone($timezone)
                ->setTime(0, 0, 0)
                ->getTimestamp();
            return $event_day >= $today_timestamp ? 1 : 0;
        }

        $schedule = (string) IFPROG_Fields::get($post_id, 'schedule');
        $weekdays = [];
        foreach ([
            1 => '/\bmonday(?:s)?\b/i',
            2 => '/\btuesday(?:s)?\b/i',
            3 => '/\bwednesday(?:s)?\b/i',
            4 => '/\bthursday(?:s)?\b/i',
            5 => '/\bfriday(?:s)?\b/i',
            6 => '/\bsaturday(?:s)?\b/i',
            7 => '/\bsunday(?:s)?\b/i',
        ] as $weekday => $pattern) {
            if (preg_match($pattern, $schedule)) $weekdays[] = $weekday;
        }
        if (!$weekdays || !$end) return null;

        $range_start = max($today_timestamp, $start ?: 0);
        $cursor = (new DateTimeImmutable('@' . $range_start))
            ->setTimezone($timezone)
            ->setTime(0, 0, 0);
        $cursor_day = absint($cursor->format('N'));
        $remaining = 0;
        foreach ($weekdays as $weekday) {
            $offset = ($weekday - $cursor_day + 7) % 7;
            $candidate = $offset ? $cursor->modify('+' . $offset . ' days') : $cursor;
            while ($candidate->getTimestamp() <= $end) {
                $remaining++;
                $candidate = $candidate->modify('+7 days');
            }
        }
        return $weeks ? min($remaining, $weeks) : $remaining;
    }

    private static function currency($amount) {
        $amount = round((float) $amount, 2);
        $decimals = abs($amount - round($amount)) < 0.005 ? 0 : 2;
        return '$' . number_format($amount, $decimals, '.', ',');
    }

    private static function offerings_by_day($programs, $show_times = false) {
        $days = [];
        foreach ((array) $programs as $program) {
            $sort_key = self::program_schedule_sort_key($program);
            $day = absint($sort_key['day']);
            $time_label = self::offering_time_label($program);
            $level_id = absint(IFPROG_Fields::get($program->ID, 'level_id'));
            $level = $level_id && get_post_type($level_id) === 'ifprog_level' ? get_post($level_id) : null;
            $title = $level
                ? sanitize_text_field((string) $level->post_title)
                : sanitize_text_field((string) IFPROG_Fields::get($program->ID, 'level'));
            if ($title === '') $title = sanitize_text_field((string) $program->post_title);
            if ($title === '') continue;

            $identity = $level_id ? 'level-' . $level_id : 'title-' . sanitize_title($title);
            if ($show_times) {
                $identity .= '-time-' . ($time_label !== '' ? sanitize_title($time_label) : 'tbd');
            }
            if (!isset($days[$day])) $days[$day] = [];
            if (isset($days[$day][$identity])) continue;
            $days[$day][$identity] = [
                'title' => $title,
                'time_label' => $time_label,
                'time_order' => absint($sort_key['time']),
                'sport_order' => self::offering_sport_sort_priority($program),
                'age_range' => $level
                    ? IFPROG_Dash::level_age_range($level->ID)
                    : sanitize_text_field((string) IFPROG_Fields::get($program->ID, 'age_range')),
                'order' => $level ? absint($level->menu_order) : absint($program->menu_order),
            ];
        }

        ksort($days, SORT_NUMERIC);
        foreach ($days as &$levels) {
            uasort($levels, function($a, $b) {
                if ($a['sport_order'] !== $b['sport_order']) {
                    return $a['sport_order'] <=> $b['sport_order'];
                }
                $a_age = self::age_range_sort_key($a['age_range']);
                $b_age = self::age_range_sort_key($b['age_range']);
                if ($a_age[0] !== $b_age[0]) return $a_age[0] <=> $b_age[0];
                if ($a_age[1] !== $b_age[1]) return $a_age[1] <=> $b_age[1];
                if ($a['order'] !== $b['order']) return $a['order'] <=> $b['order'];
                return strnatcasecmp($a['title'], $b['title']);
            });
            $levels = array_values($levels);
        }
        unset($levels);
        return $days;
    }

    private static function offering_time_label($program) {
        $schedule = trim((string) IFPROG_Fields::get($program->ID, 'schedule'));
        if ($schedule === '') return '';

        if (preg_match('/^[^,]+,\s*(.+)$/', $schedule, $match)) {
            return sanitize_text_field(trim($match[1]));
        }
        if (preg_match('/\b\d{1,2}(?::[0-5]\d)?\s*(?:[ap])\.?m\.?(?:\s*[–—-]\s*\d{1,2}(?::[0-5]\d)?\s*(?:[ap])\.?m\.?)?/i', $schedule, $match)) {
            return sanitize_text_field(trim($match[0]));
        }
        return '';
    }

    private static function offering_day_summary($levels, $show_times = false) {
        if (!$show_times) return self::human_list(self::compact_level_titles($levels));

        $time_groups = [];
        foreach ((array) $levels as $level) {
            $time_label = sanitize_text_field((string) ($level['time_label'] ?? ''));
            $key = $time_label !== '' ? $time_label : 'Time TBD';
            if (!isset($time_groups[$key])) {
                $time_groups[$key] = [
                    'label' => $key,
                    'order' => absint($level['time_order'] ?? (24 * 60)),
                    'levels' => [],
                ];
            }
            $time_groups[$key]['levels'][] = $level;
        }

        uasort($time_groups, function($a, $b) {
            if ($a['order'] !== $b['order']) return $a['order'] <=> $b['order'];
            return strnatcasecmp($a['label'], $b['label']);
        });

        $summaries = [];
        foreach ($time_groups as $time_group) {
            $summaries[] = $time_group['label'] . ': ' . self::human_list(
                self::compact_level_titles($time_group['levels'])
            );
        }
        return implode('; ', $summaries);
    }

    private static function offering_sport_sort_priority($program) {
        $terms = get_the_terms($program->ID, 'ifprog_sport');
        if (!$terms || is_wp_error($terms)) return 2;

        foreach ($terms as $term) {
            $sport = sanitize_title($term->name . ' ' . $term->slug);
            if (strpos($sport, 'figure-skating') !== false) return 0;
        }
        foreach ($terms as $term) {
            $sport = sanitize_title($term->name . ' ' . $term->slug);
            if (strpos($sport, 'hockey') !== false) return 1;
        }
        return 2;
    }

    private static function combine_offering_sessions($sessions) {
        $groups = [];
        foreach ((array) $sessions as $session) {
            $status = sanitize_key((string) ($session['season_status'] ?? 'current'));
            if (!isset($groups[$status])) {
                $groups[$status] = $session;
                $groups[$status]['programs'] = [];
                $groups[$status]['season_titles'] = [];
                $groups[$status]['date_ranges'] = [];
            }
            $groups[$status]['programs'] = array_merge(
                $groups[$status]['programs'],
                (array) ($session['programs'] ?? [])
            );
            if (!empty($session['title'])) $groups[$status]['season_titles'][] = $session['title'];
            if (!empty($session['date_range'])) $groups[$status]['date_ranges'][] = $session['date_range'];
        }

        foreach ($groups as &$group) {
            $group['season_titles'] = array_values(array_unique($group['season_titles']));
            $group['date_ranges'] = array_values(array_unique($group['date_ranges']));
            $group['programs'] = self::sort_programs_by_schedule($group['programs']);
            $group['title'] = self::human_list($group['season_titles']);
            $group['date_range'] = implode(' • ', $group['date_ranges']);
            $group['combined'] = count($group['season_titles']) > 1 ? 1 : 0;
        }
        unset($group);

        $status_order = ['current' => 1, 'upcoming' => 2, 'completed' => 3];
        uasort($groups, function($a, $b) use ($status_order) {
            $a_order = $status_order[$a['season_status']] ?? 9;
            $b_order = $status_order[$b['season_status']] ?? 9;
            return $a_order <=> $b_order;
        });
        return array_values($groups);
    }

    private static function compact_level_titles($levels) {
        $groups = [];
        $sequence = [];
        foreach ((array) $levels as $index => $level) {
            $title = sanitize_text_field((string) ($level['title'] ?? ''));
            if ($title === '') continue;
            $parts = self::numbered_level_parts($title);
            if ($parts) {
                $key = 'numbered-' . sanitize_title($parts['prefix'] . '-' . $parts['suffix']);
                if (!isset($groups[$key])) {
                    $groups[$key] = [
                        'prefix' => $parts['prefix'],
                        'separator' => $parts['separator'],
                        'suffix' => $parts['suffix'],
                        'numbers' => [],
                    ];
                    $sequence[] = ['type' => 'group', 'key' => $key, 'index' => $index];
                }
                $groups[$key]['numbers'][] = $parts['number'];
            } else {
                $sequence[] = ['type' => 'title', 'title' => $title, 'index' => $index];
            }
        }

        usort($sequence, function($a, $b) {
            return $a['index'] <=> $b['index'];
        });
        $titles = [];
        foreach ($sequence as $item) {
            if ($item['type'] === 'title') {
                $titles[] = $item['title'];
                continue;
            }
            $group = $groups[$item['key']];
            $numbers = array_values(array_unique(array_filter(array_map('absint', $group['numbers']))));
            sort($numbers, SORT_NUMERIC);
            foreach (self::number_ranges($numbers) as $range) {
                $titles[] = $group['prefix'] . $group['separator'] . $range . $group['suffix'];
            }
        }
        return array_values(array_unique($titles));
    }

    private static function numbered_level_parts($title) {
        $title = sanitize_text_field((string) $title);
        if (preg_match('/^(.*?[^\d\s])(\s*)(\d+)(\s+for\s+.+)$/ui', $title, $match)) {
            return [
                'prefix' => trim($match[1]),
                'separator' => $match[2] === '' ? '' : ' ',
                'number' => absint($match[3]),
                'suffix' => $match[4],
            ];
        }
        if (preg_match('/^(.*?[^\d\s])(\s*)(\d+)$/u', $title, $match)) {
            return [
                'prefix' => trim($match[1]),
                'separator' => $match[2] === '' ? '' : ' ',
                'number' => absint($match[3]),
                'suffix' => '',
            ];
        }
        return [];
    }

    private static function program_is_show_related($program) {
        $season_id = absint(IFPROG_Fields::get($program->ID, 'season_id'));
        $level_id = absint(IFPROG_Fields::get($program->ID, 'level_id'));
        $candidates = [
            (string) $program->post_title,
            $season_id ? (string) get_the_title($season_id) : '',
            $level_id ? (string) get_the_title($level_id) : '',
        ];
        foreach ($candidates as $candidate) {
            if ($candidate !== '' && preg_match('/\bshow\b/i', $candidate)) return true;
        }
        return false;
    }

    private static function number_ranges($numbers) {
        if (!$numbers) return [];
        $ranges = [];
        $start = $numbers[0];
        $last = $start;
        foreach (array_slice($numbers, 1) as $number) {
            if ($number === $last + 1) {
                $last = $number;
                continue;
            }
            $ranges[] = $start === $last ? (string) $start : $start . '–' . $last;
            $start = $last = $number;
        }
        $ranges[] = $start === $last ? (string) $start : $start . '–' . $last;
        return $ranges;
    }

    private static function offerings_heading($season_status) {
        if ($season_status === 'upcoming') return 'Upcoming Class Offerings:';
        if ($season_status === 'completed') return 'Past Class Offerings:';
        return 'Current Class Offerings:';
    }

    private static function offering_day_label($day) {
        $labels = [
            1 => 'Monday',
            2 => 'Tuesday',
            3 => 'Wednesday',
            4 => 'Thursday',
            5 => 'Friday',
            6 => 'Saturday',
            7 => 'Sunday',
            8 => 'Schedule TBD',
        ];
        return $labels[absint($day)] ?? 'Schedule TBD';
    }

    private static function human_list($items) {
        $items = array_values(array_filter(array_map('sanitize_text_field', (array) $items)));
        $count = count($items);
        if ($count === 0) return '';
        if ($count === 1) return $items[0];
        if ($count === 2) return $items[0] . ' and ' . $items[1];
        $last = array_pop($items);
        return implode(', ', $items) . ', and ' . $last;
    }

    private static function season_registration_url($season_id, $programs, $standalone_levels = []) {
        $season_id = absint($season_id);
        $has_open_registration = self::has_open_program($programs) ||
            ($standalone_levels && self::standalone_level_state($season_id) === 'open');
        if (!$season_id || !$has_open_registration) return '';

        $local_url = esc_url_raw((string) get_post_meta($season_id, '_ifprog_registration_url', true));
        if ($local_url !== '') return $local_url;

        $dash_url = esc_url_raw((string) get_post_meta($season_id, '_ifprog_dash_registration_url', true));
        if ($dash_url !== '') return $dash_url;

        $payload = get_post_meta($season_id, '_ifprog_dash_payload', true);
        if (is_array($payload)) {
            $season_url = IFPROG_Dash::season_registration_url(
                absint($payload['program_id'] ?? 0),
                absint(get_post_meta($season_id, '_ifprog_dash_season_id', true)),
                absint($payload['facility_id'] ?? 0)
            );
            if ($season_url !== '') return $season_url;
        }

        foreach ($standalone_levels as $level) {
            $level_url = self::level_registration_url($level->ID, [], $season_id);
            if ($level_url !== '') return $level_url;
        }
        return '';
    }

    private static function level_registration_url($level_id, $programs, $season_id = 0) {
        $level_id = absint($level_id);
        $season_id = absint($season_id);
        $has_open_registration = self::has_open_program($programs) ||
            (!$programs && $season_id && self::standalone_level_state($season_id) === 'open');
        if (!$has_open_registration) return '';

        if ($level_id) {
            $local_url = esc_url_raw((string) get_post_meta($level_id, '_ifprog_registration_url', true));
            if ($local_url !== '') return $local_url;

            $dash_url = esc_url_raw((string) get_post_meta($level_id, '_ifprog_dash_registration_url', true));
            if ($dash_url !== '') return $dash_url;
        }

        foreach ($programs as $program) {
            if (IFPROG_Status::program_state($program->ID) !== 'open') continue;
            $url = esc_url_raw((string) IFPROG_Fields::get($program->ID, 'registration_url'));
            if ($url !== '') return $url;
        }
        return '';
    }

    private static function has_open_program($programs) {
        foreach ($programs as $program) {
            if (IFPROG_Status::program_state($program->ID) === 'open') return true;
        }
        return false;
    }

    private static function csv_slugs($raw) {
        return array_values(array_unique(array_filter(array_map('sanitize_title', preg_split('/[\s,]+/', (string) $raw)))));
    }

    private static function term_names($post_id, $taxonomy) {
        $terms = get_the_terms($post_id, $taxonomy);
        if (!$terms || is_wp_error($terms)) return [];
        return wp_list_pluck($terms, 'name');
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

    private static function date_time($value) {
        $timestamp = IFPROG_Status::timestamp($value);
        return $timestamp ? wp_date(get_option('date_format') . ' \a\t ' . get_option('time_format'), $timestamp) : '';
    }

    private static function same_description($first, $second) {
        $normalize = function($value) {
            $value = html_entity_decode(wp_strip_all_tags((string) $value), ENT_QUOTES, 'UTF-8');
            $value = preg_replace('/\s+/', ' ', trim($value));
            return strtolower((string) $value);
        };
        $first = $normalize($first);
        return $first !== '' && $first === $normalize($second);
    }

    private static function truthy($value) {
        return in_array(strtolower((string) $value), ['1','yes','true','on'], true);
    }
}
