<?php
if (!defined('ABSPATH')) exit;

class IFPROG_Meta {
    const SEASON_NONCE = 'ifprog_save_season';
    const LEVEL_NONCE = 'ifprog_save_level';
    const PROGRAM_NONCE = 'ifprog_save_program';

    public static function init() {
        add_action('add_meta_boxes', [__CLASS__, 'add_meta_boxes']);
        add_action('save_post_ifprog_season', [__CLASS__, 'save_season']);
        add_action('save_post_ifprog_level', [__CLASS__, 'save_level']);
        add_action('save_post_ifprog_program', [__CLASS__, 'save_program']);
    }

    public static function add_meta_boxes() {
        add_meta_box(
            'ifprog_season_details',
            'Season Details',
            [__CLASS__, 'render_season_details'],
            'ifprog_season',
            'normal',
            'high'
        );
        add_meta_box(
            'ifprog_season_shortcode',
            'Season Shortcode',
            [__CLASS__, 'render_season_shortcode'],
            'ifprog_season',
            'side',
            'high'
        );
        add_meta_box(
            'ifprog_level_details',
            'Level Details',
            [__CLASS__, 'render_level_details'],
            'ifprog_level',
            'normal',
            'high'
        );
        add_meta_box(
            'ifprog_level_source',
            'Dash Source',
            [__CLASS__, 'render_level_source'],
            'ifprog_level',
            'side',
            'default'
        );
        add_meta_box(
            'ifprog_program_details',
            'Program & Registration Details',
            [__CLASS__, 'render_program_details'],
            'ifprog_program',
            'normal',
            'high'
        );
        add_meta_box(
            'ifprog_program_source',
            'Dash Source',
            [__CLASS__, 'render_program_source'],
            'ifprog_program',
            'side',
            'default'
        );
    }

    public static function render_season_details($post) {
        wp_nonce_field(self::SEASON_NONCE, 'ifprog_season_nonce');
        $status = IFPROG_Status::season_status($post->ID);
        $start = get_post_meta($post->ID, '_ifprog_season_start_date', true);
        $end = get_post_meta($post->ID, '_ifprog_season_end_date', true);
        $registration_open = get_post_meta($post->ID, '_ifprog_season_registration_open', true);
        $registration_close = get_post_meta($post->ID, '_ifprog_season_registration_close', true);
        $registration_url = get_post_meta($post->ID, '_ifprog_registration_url', true);
        $dash_id = get_post_meta($post->ID, '_ifprog_dash_season_id', true);
        $automatic_sync = get_post_meta($post->ID, '_ifprog_automatic_sync', true) === '1';
        $automatic_sync_names = get_post_meta($post->ID, '_ifprog_automatic_sync_names', true) === '1';
        $automatic_sync_descriptions = get_post_meta($post->ID, '_ifprog_automatic_sync_descriptions', true) === '1';
        $automatic_sync_checked = get_post_meta($post->ID, '_ifprog_automatic_sync_last_checked', true);
        $is_production = get_post_meta($post->ID, '_ifprog_is_production', true) === '1';
        $production_id = absint(get_post_meta($post->ID, '_ifprog_production_id', true));
        $sort_order = get_post_meta($post->ID, '_ifprog_season_order', true);
        if ($sort_order === '') $sort_order = 100;
        ?>
        <div class="ifprog-field-grid">
            <p><label><strong>Lifecycle Status</strong><br>
                <select class="widefat" name="ifprog_season_status">
                    <?php foreach ([
                        'draft' => 'Draft',
                        'upcoming' => 'Upcoming',
                        'current' => 'Current',
                        'completed' => 'Completed',
                        'archived' => 'Archived',
                    ] as $value => $label): ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($status, $value); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </label></p>
            <p><label><strong>Dash Season ID</strong><br>
                <input class="widefat" type="number" min="0" name="ifprog_dash_season_id" value="<?php echo esc_attr($dash_id); ?>">
            </label></p>
            <?php if ($dash_id): ?>
                <p><label>
                    <input type="checkbox" name="ifprog_automatic_sync" value="1" <?php checked($automatic_sync); ?>>
                    <strong>Keep up to date automatically</strong>
                </label><br>
                <span class="description">Once daily, while this Dash Season's registration window is open, import new class offerings and refresh protected Dash fields. Local presentation and classifications remain protected.<?php echo $automatic_sync_checked ? ' Last checked: ' . esc_html($automatic_sync_checked) . '.' : ''; ?></span></p>
                <p style="margin-left:24px"><label>
                    <input type="checkbox" name="ifprog_automatic_sync_names" value="1" <?php checked($automatic_sync_names); ?>>
                    Update Season, Level, and class names automatically from Dash
                </label><br>
                <label>
                    <input type="checkbox" name="ifprog_automatic_sync_descriptions" value="1" <?php checked($automatic_sync_descriptions); ?>>
                    Update Season, Level, and class descriptions automatically from Dash
                </label><br>
                <span class="description">Both options are off by default. Description updates stop when the WordPress copy has been edited locally.</span></p>
            <?php endif; ?>
            <p><label><strong>Display Order</strong><br>
                <input class="widefat" type="number" min="0" max="9999" name="ifprog_season_order" value="<?php echo esc_attr($sort_order); ?>">
                <span class="description">When Seasons have the same start and end dates, lower numbers appear first. Completed Seasons still remain at the bottom.</span>
            </label></p>
            <p><label><strong>Season Starts</strong><br>
                <input class="widefat" type="date" name="ifprog_season_start_date" value="<?php echo esc_attr($start); ?>">
            </label></p>
            <p><label><strong>Season Ends</strong><br>
                <input class="widefat" type="date" name="ifprog_season_end_date" value="<?php echo esc_attr($end); ?>">
            </label></p>
            <p><label><strong>Default Registration Opens</strong><br>
                <input class="widefat" type="datetime-local" name="ifprog_season_registration_open" value="<?php echo esc_attr($registration_open); ?>">
            </label></p>
            <p><label><strong>Default Registration Closes</strong><br>
                <input class="widefat" type="datetime-local" name="ifprog_season_registration_close" value="<?php echo esc_attr($registration_close); ?>">
            </label></p>
            <p><label><strong>Registration URL</strong><br>
                <input class="widefat" type="url" name="ifprog_season_registration_url" value="<?php echo esc_attr($registration_url); ?>" placeholder="Generated automatically from Dash">
            </label></p>
        </div>
        <hr>
        <h3>Production Alignment</h3>
        <p><label>
            <input type="checkbox" name="ifprog_is_production" value="1" <?php checked($is_production); ?>>
            <strong>This Season is a Production</strong>
        </label></p>
        <p><label><strong>Linked Production</strong><br>
            <?php if (post_type_exists('ifp_production')): ?>
                <select class="widefat" name="ifprog_production_id">
                    <option value="0">— Not linked yet —</option>
                    <?php foreach (get_posts([
                        'post_type' => 'ifp_production',
                        'post_status' => 'any',
                        'posts_per_page' => -1,
                        'orderby' => 'title',
                        'order' => 'ASC',
                    ]) as $production): ?>
                        <option value="<?php echo esc_attr($production->ID); ?>" <?php selected($production_id, $production->ID); ?>><?php echo esc_html($production->post_title); ?></option>
                    <?php endforeach; ?>
                </select>
            <?php else: ?>
                <input class="widefat" type="number" min="0" name="ifprog_production_id" value="<?php echo esc_attr($production_id); ?>">
                <span class="description">Productions is not currently active. The relationship ID can still be retained.</span>
            <?php endif; ?>
        </label></p>
        <p class="description">Stage 1 records the relationship only. Production records are not created or synchronized automatically yet.</p>
        <p class="description">A Season is the parent for its classes, leagues, camps, clinics, and other registration offerings. Multiple Seasons can be marked Current for different programming areas. Leave Registration URL empty to use the Dash-generated Season link.</p>
        <?php
    }

    public static function render_season_shortcode($post) {
        $shortcode = '[if_programming season="' . absint($post->ID) . '"]';
        $input_id = 'ifprog-season-shortcode-' . absint($post->ID);
        ?>
        <div class="ifprog-shortcode-copy">
            <input
                id="<?php echo esc_attr($input_id); ?>"
                class="widefat"
                type="text"
                value="<?php echo esc_attr($shortcode); ?>"
                readonly
                aria-label="Season-specific programming shortcode"
            >
            <button class="button button-primary" type="button" data-ifprog-copy-target="<?php echo esc_attr($input_id); ?>">Copy Shortcode</button>
            <span class="ifprog-copy-status" data-ifprog-copy-status aria-live="polite"></span>
        </div>
        <p class="description">Displays this WordPress Season only. The Season post ID remains reliable if its title or URL slug changes.</p>
        <?php
    }

    public static function render_level_details($post) {
        wp_nonce_field(self::LEVEL_NONCE, 'ifprog_level_nonce');
        $season_id = absint(get_post_meta($post->ID, '_ifprog_level_season_id', true));
        $age_range = IFPROG_Dash::level_age_range($post->ID);
        $dash_id = absint(get_post_meta($post->ID, '_ifprog_dash_league_id', true));
        $registration_url = get_post_meta($post->ID, '_ifprog_registration_url', true);
        $assigned_groups = wp_get_object_terms($post->ID, 'ifprog_group', ['fields' => 'ids']);
        if (is_wp_error($assigned_groups)) $assigned_groups = [];
        $assigned_groups = array_map('absint', $assigned_groups);
        $program_groups = get_terms([
            'taxonomy' => 'ifprog_group',
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
        ]);
        if (is_wp_error($program_groups)) $program_groups = [];
        $seasons = get_posts([
            'post_type' => 'ifprog_season',
            'post_status' => ['publish','draft','pending','private','future'],
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);
        ?>
        <div class="ifprog-field-grid">
            <p><label><strong>Season</strong><br>
                <select class="widefat" name="ifprog_level_season_id">
                    <option value="0">— No Season Assigned —</option>
                    <?php foreach ($seasons as $season): ?>
                        <option value="<?php echo esc_attr($season->ID); ?>" <?php selected($season_id, $season->ID); ?>><?php echo esc_html($season->post_title); ?></option>
                    <?php endforeach; ?>
                </select>
            </label></p>
            <p><label><strong>Age Range</strong><br>
                <input class="widefat" type="text" name="ifprog_level_age_range" value="<?php echo esc_attr($age_range); ?>" placeholder="Ages 6–12">
            </label></p>
            <p><label><strong>Dash League ID</strong><br>
                <input class="widefat" type="number" min="0" name="ifprog_dash_league_id" value="<?php echo esc_attr($dash_id); ?>">
            </label></p>
            <p><label><strong>Registration URL</strong><br>
                <input class="widefat" type="url" name="ifprog_level_registration_url" value="<?php echo esc_attr($registration_url); ?>" placeholder="Generated automatically from Dash">
            </label></p>
        </div>
        <?php if ($dash_id === IFPROG_Sync::CURRENT_LEARN_TO_PLAY_LEAGUE_ID): ?>
            <p class="description"><strong>Automatic Season routing:</strong> Dash League #86 stays with the published Current Learn to Play Season. It is not moved into the Upcoming Season because its registration link already provides access to future drop-in dates.</p>
        <?php endif; ?>
        <fieldset class="ifprog-group-choices">
            <legend><strong>Program Groups</strong></legend>
            <div>
                <?php foreach ($program_groups as $program_group): ?>
                    <label>
                        <input type="checkbox" name="ifprog_level_group_ids[]" value="<?php echo esc_attr($program_group->term_id); ?>" <?php checked(in_array(absint($program_group->term_id), $assigned_groups, true)); ?>>
                        <?php echo esc_html($program_group->name); ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <p class="description">Select every Group where this Level and its classes should appear. Unassigned Levels remain visible directly below the defined Program Groups.</p>
        </fieldset>
        <p class="description">Use the main editor for a level-wide description and Page Order to control its public order within each Group. Leave Registration URL empty to use the Dash-generated Level link.</p>
        <?php
    }

    public static function render_level_source($post) {
        $dash_id = absint(get_post_meta($post->ID, '_ifprog_dash_league_id', true));
        $last_sync = get_post_meta($post->ID, '_ifprog_dash_last_sync', true);
        if ($dash_id) {
            echo '<p><strong>Dash linked</strong></p>';
            echo '<p><strong>Source:</strong><br>League #' . esc_html($dash_id) . '</p>';
            if ($dash_id === IFPROG_Sync::CURRENT_LEARN_TO_PLAY_LEAGUE_ID) {
                echo '<p><strong>Season routing:</strong><br>Current Learn to Play</p>';
            }
            echo '<p><strong>Last synchronized:</strong><br>' . esc_html($last_sync ?: 'Never') . '</p>';
            echo '<p class="description">Local Program Groups, title, description, order, and publication status are preserved during refresh.</p>';
        } else {
            echo '<p><strong>Manual Level</strong></p>';
            echo '<p class="description">This Level is managed entirely in WordPress.</p>';
        }
    }

    public static function render_program_details($post) {
        wp_nonce_field(self::PROGRAM_NONCE, 'ifprog_program_nonce');
        $season_id = absint(IFPROG_Fields::get($post->ID, 'season_id'));
        $level_id = absint(IFPROG_Fields::get($post->ID, 'level_id'));
        $status = sanitize_key((string) get_post_meta($post->ID, IFPROG_Status::PROGRAM_STATUS_KEY, true));
        if (!in_array($status, IFPROG_Status::program_overrides(), true)) $status = 'automatic';
        $seasons = get_posts([
            'post_type' => 'ifprog_season',
            'post_status' => ['publish','draft','private','future'],
            'posts_per_page' => -1,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);
        $levels = get_posts([
            'post_type' => 'ifprog_level',
            'post_status' => ['publish','draft','pending','private','future'],
            'posts_per_page' => -1,
            'orderby' => [
                'menu_order' => 'ASC',
                'title' => 'ASC',
            ],
            'order' => 'ASC',
        ]);
        ?>
        <p><label><strong>Season</strong><br>
            <select class="widefat" name="ifprog_season_id">
                <option value="0">— No Season Assigned —</option>
                <?php foreach ($seasons as $season): ?>
                    <option value="<?php echo esc_attr($season->ID); ?>" <?php selected($season_id, $season->ID); ?>><?php echo esc_html($season->post_title); ?></option>
                <?php endforeach; ?>
            </select>
        </label></p>
        <p><label><strong>Level</strong><br>
            <select class="widefat" name="ifprog_level_id">
                <option value="0">— No Level Assigned —</option>
                <?php foreach ($levels as $level):
                    $level_season_id = absint(get_post_meta($level->ID, '_ifprog_level_season_id', true));
                    $season_label = $level_season_id ? get_the_title($level_season_id) : 'No Season';
                    ?>
                    <option value="<?php echo esc_attr($level->ID); ?>" <?php selected($level_id, $level->ID); ?>>
                        <?php echo esc_html($season_label . ' — ' . $level->post_title); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label></p>
        <div class="ifprog-field-grid">
            <p><label><strong>Registration Status</strong><br>
                <select class="widefat" name="ifprog_registration_status">
                    <?php foreach ([
                        'automatic' => 'Automatic from registration dates',
                        'draft' => 'Draft / hidden',
                        'coming_soon' => 'Coming Soon',
                        'open' => 'Registration Open',
                        'closed' => 'Registration Closed',
                        'archived' => 'Archived / hidden',
                    ] as $value => $label): ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($status, $value); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </label></p>
            <p><label><strong>Location</strong><br>
                <input class="widefat" type="text" name="ifprog_location" value="<?php echo esc_attr(IFPROG_Fields::get($post->ID, 'location')); ?>" placeholder="Ice & Field at The Crossover">
            </label></p>
            <p><label><strong>Program Starts</strong><br>
                <input class="widefat" type="date" name="ifprog_start_date" value="<?php echo esc_attr(IFPROG_Fields::get($post->ID, 'start_date')); ?>">
            </label></p>
            <p><label><strong>Program Ends</strong><br>
                <input class="widefat" type="date" name="ifprog_end_date" value="<?php echo esc_attr(IFPROG_Fields::get($post->ID, 'end_date')); ?>">
            </label></p>
            <p><label><strong>Registration Opens</strong><br>
                <input class="widefat" type="datetime-local" name="ifprog_registration_open" value="<?php echo esc_attr(IFPROG_Fields::get($post->ID, 'registration_open')); ?>">
            </label></p>
            <p><label><strong>Registration Closes</strong><br>
                <input class="widefat" type="datetime-local" name="ifprog_registration_close" value="<?php echo esc_attr(IFPROG_Fields::get($post->ID, 'registration_close')); ?>">
            </label></p>
            <p><label><strong>Age Range</strong><br>
                <input class="widefat" type="text" name="ifprog_age_range" value="<?php echo esc_attr(IFPROG_Fields::get($post->ID, 'age_range')); ?>" placeholder="Ages 6–12">
            </label></p>
            <p><label><strong>Fallback Level Label</strong><br>
                <input class="widefat" type="text" name="ifprog_level" value="<?php echo esc_attr(IFPROG_Fields::get($post->ID, 'level')); ?>" placeholder="Beginner">
            </label></p>
            <p><label><strong>Price</strong><br>
                <input class="widefat" type="text" name="ifprog_price" value="<?php echo esc_attr(IFPROG_Fields::get($post->ID, 'price')); ?>" placeholder="$175">
            </label></p>
            <p><label><strong>Session Occurrences</strong><br>
                <input class="widefat" type="number" min="0" name="ifprog_weeks" value="<?php echo esc_attr(IFPROG_Fields::get($post->ID, 'weeks')); ?>" placeholder="8">
            </label></p>
            <p><label><strong>Session Duration Unit</strong><br>
                <?php $duration_unit = IFPROG_Fields::get($post->ID, 'duration_unit', 'week'); ?>
                <select class="widefat" name="ifprog_duration_unit">
                    <option value="week" <?php selected($duration_unit, 'week'); ?>>Weeks</option>
                    <option value="day" <?php selected($duration_unit, 'day'); ?>>Days</option>
                </select>
            </label></p>
            <p><label><strong>Registration Button Text</strong><br>
                <input class="widefat" type="text" name="ifprog_button_label" value="<?php echo esc_attr(IFPROG_Fields::get($post->ID, 'button_label')); ?>" placeholder="Register Now">
            </label></p>
        </div>

        <p><label><strong>Schedule</strong><br>
            <textarea class="widefat" rows="3" name="ifprog_schedule" placeholder="Saturdays, 9:00–9:45 AM"><?php echo esc_textarea(IFPROG_Fields::get($post->ID, 'schedule')); ?></textarea>
        </label></p>
        <p><label><strong>Registration URL</strong><br>
            <input class="widefat" type="url" name="ifprog_registration_url" value="<?php echo esc_attr(IFPROG_Fields::get($post->ID, 'registration_url')); ?>" placeholder="https://">
        </label></p>
        <p><label><strong>Availability Message</strong><br>
            <textarea class="widefat" rows="3" name="ifprog_availability_note" placeholder="Join the waitlist or contact us for help."><?php echo esc_textarea(IFPROG_Fields::get($post->ID, 'availability_note')); ?></textarea>
        </label></p>
        <p class="description">Use the main editor for the complete public description and the Excerpt box for a brief accordion summary. Sport, Format, and Program Category are assigned in the sidebar.</p>
        <?php
    }

    public static function render_program_source($post) {
        $source_type = get_post_meta($post->ID, '_ifprog_dash_source_type', true);
        $source_id = get_post_meta($post->ID, '_ifprog_dash_source_id', true);
        $last_sync = get_post_meta($post->ID, '_ifprog_dash_last_sync', true);
        $overrides = IFPROG_Fields::overrides($post->ID);

        if ($source_id) {
            echo '<p><strong>Dash linked</strong></p>';
            echo '<p><strong>Source:</strong><br>' . esc_html(ucfirst((string) $source_type)) . ' #' . esc_html($source_id) . '</p>';
            echo '<p><strong>Last synchronized:</strong><br>' . esc_html($last_sync ?: 'Never') . '</p>';
            echo '<p><strong>Protected local fields:</strong><br>' . esc_html($overrides ? implode(', ', array_map(function($field) {
                return ucwords(str_replace('_', ' ', $field));
            }, $overrides)) : 'None') . '</p>';
        } else {
            echo '<p><strong>Manual record</strong></p>';
            echo '<p class="description">This Program is managed entirely in WordPress. Future Dash imports can link a source without replacing your local presentation.</p>';
        }
    }

    public static function save_season($post_id) {
        if (!isset($_POST['ifprog_season_nonce'])) return;
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ifprog_season_nonce'])), self::SEASON_NONCE)) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id)) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $status = sanitize_key(wp_unslash($_POST['ifprog_season_status'] ?? 'upcoming'));
        IFPROG_Status::set_season_status($post_id, $status);

        update_post_meta($post_id, '_ifprog_dash_season_id', absint($_POST['ifprog_dash_season_id'] ?? 0));
        update_post_meta($post_id, '_ifprog_automatic_sync', isset($_POST['ifprog_automatic_sync']) ? '1' : '0');
        update_post_meta($post_id, '_ifprog_automatic_sync_names', isset($_POST['ifprog_automatic_sync_names']) ? '1' : '0');
        update_post_meta($post_id, '_ifprog_automatic_sync_descriptions', isset($_POST['ifprog_automatic_sync_descriptions']) ? '1' : '0');
        update_post_meta($post_id, '_ifprog_is_production', isset($_POST['ifprog_is_production']) ? '1' : '0');
        $production_id = absint($_POST['ifprog_production_id'] ?? 0);
        if ($production_id && post_type_exists('ifp_production') && get_post_type($production_id) !== 'ifp_production') {
            $production_id = 0;
        }
        update_post_meta($post_id, '_ifprog_production_id', $production_id);
        $sort_order = intval($_POST['ifprog_season_order'] ?? 100);
        update_post_meta($post_id, '_ifprog_season_order', max(0, min(9999, $sort_order)));
        update_post_meta($post_id, '_ifprog_season_start_date', self::date_value($_POST['ifprog_season_start_date'] ?? ''));
        update_post_meta($post_id, '_ifprog_season_end_date', self::date_value($_POST['ifprog_season_end_date'] ?? ''));
        update_post_meta($post_id, '_ifprog_season_registration_open', self::datetime_value($_POST['ifprog_season_registration_open'] ?? ''));
        update_post_meta($post_id, '_ifprog_season_registration_close', self::datetime_value($_POST['ifprog_season_registration_close'] ?? ''));
        update_post_meta(
            $post_id,
            '_ifprog_registration_url',
            esc_url_raw(wp_unslash($_POST['ifprog_season_registration_url'] ?? ''))
        );
    }

    public static function save_program($post_id) {
        if (!isset($_POST['ifprog_program_nonce'])) return;
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ifprog_program_nonce'])), self::PROGRAM_NONCE)) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id)) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $status = sanitize_key(wp_unslash($_POST['ifprog_registration_status'] ?? 'automatic'));
        if (!in_array($status, IFPROG_Status::program_overrides(), true)) $status = 'automatic';
        update_post_meta($post_id, IFPROG_Status::PROGRAM_STATUS_KEY, $status);

        $season_id = absint($_POST['ifprog_season_id'] ?? 0);
        if ($season_id && get_post_type($season_id) !== 'ifprog_season') $season_id = 0;
        $level_id = absint($_POST['ifprog_level_id'] ?? 0);
        if ($level_id && get_post_type($level_id) !== 'ifprog_level') $level_id = 0;
        if ($level_id) {
            $level_season_id = absint(get_post_meta($level_id, '_ifprog_level_season_id', true));
            if (!$level_season_id) {
                $level_id = 0;
            } elseif (!$season_id) {
                $season_id = $level_season_id;
            } elseif ($level_season_id !== $season_id) {
                $level_id = 0;
            }
        }

        $values = [
            'season_id' => $season_id,
            'level_id' => $level_id,
            'start_date' => self::date_value($_POST['ifprog_start_date'] ?? ''),
            'end_date' => self::date_value($_POST['ifprog_end_date'] ?? ''),
            'registration_open' => self::datetime_value($_POST['ifprog_registration_open'] ?? ''),
            'registration_close' => self::datetime_value($_POST['ifprog_registration_close'] ?? ''),
            'schedule' => sanitize_textarea_field(wp_unslash($_POST['ifprog_schedule'] ?? '')),
            'age_range' => sanitize_text_field(wp_unslash($_POST['ifprog_age_range'] ?? '')),
            'level' => sanitize_text_field(wp_unslash($_POST['ifprog_level'] ?? '')),
            'location' => sanitize_text_field(wp_unslash($_POST['ifprog_location'] ?? '')),
            'price' => sanitize_text_field(wp_unslash($_POST['ifprog_price'] ?? '')),
            'weeks' => absint($_POST['ifprog_weeks'] ?? 0),
            'duration_unit' => sanitize_key(wp_unslash($_POST['ifprog_duration_unit'] ?? 'week')) === 'day' ? 'day' : 'week',
            'registration_url' => esc_url_raw(wp_unslash($_POST['ifprog_registration_url'] ?? '')),
            'button_label' => sanitize_text_field(wp_unslash($_POST['ifprog_button_label'] ?? '')),
            'availability_note' => sanitize_textarea_field(wp_unslash($_POST['ifprog_availability_note'] ?? '')),
        ];

        foreach ($values as $field => $value) {
            IFPROG_Fields::save_local($post_id, $field, $value);
        }

    }

    public static function save_level($post_id) {
        if (!isset($_POST['ifprog_level_nonce'])) return;
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ifprog_level_nonce'])), self::LEVEL_NONCE)) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id)) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $season_id = absint($_POST['ifprog_level_season_id'] ?? 0);
        if ($season_id && get_post_type($season_id) !== 'ifprog_season') $season_id = 0;

        update_post_meta($post_id, '_ifprog_level_season_id', $season_id);
        update_post_meta(
            $post_id,
            '_ifprog_level_age_range',
            sanitize_text_field(wp_unslash($_POST['ifprog_level_age_range'] ?? ''))
        );
        update_post_meta($post_id, '_ifprog_dash_league_id', absint($_POST['ifprog_dash_league_id'] ?? 0));
        update_post_meta(
            $post_id,
            '_ifprog_registration_url',
            esc_url_raw(wp_unslash($_POST['ifprog_level_registration_url'] ?? ''))
        );

        $group_ids = array_values(array_unique(array_filter(array_map(
            'absint',
            (array) ($_POST['ifprog_level_group_ids'] ?? [])
        ))));
        $group_ids = array_values(array_filter($group_ids, function($group_id) {
            return (bool) term_exists($group_id, 'ifprog_group');
        }));
        wp_set_object_terms($post_id, $group_ids, 'ifprog_group', false);
    }

    private static function date_value($value) {
        $value = sanitize_text_field(wp_unslash($value));
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
    }

    private static function datetime_value($value) {
        $value = sanitize_text_field(wp_unslash($value));
        return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?$/', $value) ? $value : '';
    }
}
