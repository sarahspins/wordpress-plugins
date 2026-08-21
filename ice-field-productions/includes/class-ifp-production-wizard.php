<?php
if (!defined('ABSPATH')) exit;

class IFP_Production_Wizard {
    public static function init() {
        add_action('admin_post_ifp_create_production', [__CLASS__, 'handle_create']);
    }

    public static function render_page() {
        if (!current_user_can('edit_posts')) {
            wp_die(esc_html__('You do not have permission to create productions.', 'ice-field-productions'));
        }

        $created_id = isset($_GET['created']) ? absint($_GET['created']) : 0;
        $updated_id = isset($_GET['updated']) ? absint($_GET['updated']) : 0;
        $selected_id = isset($_GET['production_id']) ? absint($_GET['production_id']) : 0;
        $editing = $selected_id ? get_post($selected_id) : null;
        if (!$editing || $editing->post_type !== 'ifp_production' || !current_user_can('edit_post', $editing->ID)) {
            $editing = null;
            $selected_id = 0;
        }

        $productions = self::editable_productions();
        $is_editing = (bool) $editing;
        $can_publish = current_user_can('publish_posts');
        $lifecycle = $is_editing ? IFP_Production_Status::get($editing->ID) : 'upcoming';
        $post_status = $is_editing ? get_post_status($editing) : 'draft';
        $values = [
            'title' => $is_editing ? $editing->post_title : '',
            'content' => $is_editing ? $editing->post_content : '',
            'season' => $is_editing ? get_post_meta($editing->ID, '_ifp_season', true) : 'Summer',
            'year' => $is_editing ? get_post_meta($editing->ID, '_ifp_year', true) : wp_date('Y'),
            'tagline' => $is_editing ? get_post_meta($editing->ID, '_ifp_tagline', true) : '',
            'opening_date' => $is_editing ? self::datetime_input_value(get_post_meta($editing->ID, '_ifp_opening_date', true)) : '',
            'closing_date' => $is_editing ? self::datetime_input_value(get_post_meta($editing->ID, '_ifp_closing_date', true)) : '',
            'current_start_date' => $is_editing ? get_post_meta($editing->ID, IFP_Production_Status::CURRENT_START_KEY, true) : '',
            'accent_color' => $is_editing ? get_post_meta($editing->ID, '_ifp_accent_color', true) : '#ff8033',
            'secondary_color' => $is_editing ? get_post_meta($editing->ID, '_ifp_secondary_color', true) : '#003b5c',
            'countdown_text_color' => $is_editing ? get_post_meta($editing->ID, '_ifp_countdown_text_color', true) : '#ffffff',
            'overlay_color' => $is_editing ? get_post_meta($editing->ID, '_ifp_overlay_color', true) : '#003b5c',
            'hero_overlay_opacity' => $is_editing ? get_post_meta($editing->ID, '_ifp_hero_overlay_opacity', true) : '92',
            'panel_overlay_opacity' => $is_editing ? get_post_meta($editing->ID, '_ifp_panel_overlay_opacity', true) : '78',
            'ticket_url' => $is_editing ? get_post_meta($editing->ID, '_ifp_ticket_url', true) : '',
            'ticket_available_date' => $is_editing ? get_post_meta($editing->ID, '_ifp_ticket_available_date', true) : '',
            'registration_url' => $is_editing ? get_post_meta($editing->ID, '_ifp_registration_url', true) : '',
            'registration_open' => $is_editing ? self::datetime_input_value(get_post_meta($editing->ID, '_ifp_registration_open', true)) : '',
            'registration_close' => $is_editing ? self::datetime_input_value(get_post_meta($editing->ID, '_ifp_registration_close', true)) : '',
            'participant_hub_url' => $is_editing ? get_post_meta($editing->ID, '_ifp_participant_hub_url', true) : '',
            'volunteer_url' => $is_editing ? get_post_meta($editing->ID, '_ifp_volunteer_url', true) : '',
        ];
        if (!$values['accent_color']) $values['accent_color'] = '#ff8033';
        if (!$values['secondary_color']) $values['secondary_color'] = '#003b5c';
        if (!$values['countdown_text_color']) $values['countdown_text_color'] = '#ffffff';
        if (!$values['overlay_color']) $values['overlay_color'] = $values['secondary_color'];
        if ($values['hero_overlay_opacity'] === '') $values['hero_overlay_opacity'] = '92';
        if ($values['panel_overlay_opacity'] === '') $values['panel_overlay_opacity'] = '78';
        $starter_values = $is_editing ? self::starter_event_values($editing->ID) : [];
        ?>
        <div class="wrap ifp-dashboard ifp-production-wizard">
            <div class="ifp-page-header">
                <div>
                    <p class="ifp-kicker">Production Lifecycle</p>
                    <h1><?php echo $is_editing ? 'Complete Production Setup' : 'New Production Wizard'; ?></h1>
                    <p class="ifp-lead"><?php echo $is_editing ? 'Review and complete the existing Production without creating a duplicate.' : 'Create the show, establish its core dates and links, and optionally switch the public site to it immediately.'; ?></p>
                </div>
            </div>

            <section class="ifp-admin-card" style="margin-bottom:22px">
                <h2>Choose What to Set Up</h2>
                <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
                    <input type="hidden" name="page" value="ifp-production-wizard">
                    <label for="ifp-wizard-production"><strong>Production</strong></label><br>
                    <select id="ifp-wizard-production" name="production_id" style="min-width:min(100%,420px)">
                        <option value="0">Create a new Production</option>
                        <?php foreach ($productions as $production): ?>
                            <option value="<?php echo esc_attr($production->ID); ?>" <?php selected($selected_id, $production->ID); ?>>
                                <?php echo esc_html($production->post_title . ' — ' . ucfirst(IFP_Production_Status::get($production->ID))); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button class="button button-primary">Load Guided Setup</button>
                </form>
                <p class="description">Draft, Upcoming, and Current Productions are available. Selecting one loads its existing information into the steps below.</p>
            </section>

            <?php if ($created_id): ?>
                <div class="notice notice-success is-dismissible">
                    <p>
                        <strong>Production created.</strong>
                        <a href="<?php echo esc_url(get_edit_post_link($created_id)); ?>">Add artwork, logo, and additional details.</a>
                    </p>
                </div>
            <?php endif; ?>

            <?php if ($updated_id): ?>
                <div class="notice notice-success is-dismissible">
                    <p>
                        <strong>Production setup updated.</strong>
                        <a href="<?php echo esc_url(get_edit_post_link($updated_id)); ?>">Open the complete Production editor.</a>
                    </p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="ifp_create_production">
                <input type="hidden" name="production_id" value="<?php echo esc_attr($selected_id); ?>">
                <?php wp_nonce_field('ifp_create_production', 'ifp_wizard_nonce'); ?>

                <div class="ifp-wizard-grid">
                    <main class="ifp-wizard-main">
                        <section class="ifp-admin-card ifp-wizard-step">
                            <div class="ifp-wizard-step__number">1</div>
                            <div class="ifp-wizard-step__content">
                                <h2>Production Basics</h2>

                                <p><label><strong>Production Title</strong><br>
                                    <input class="widefat" required type="text" name="production_title" value="<?php echo esc_attr($values['title']); ?>" placeholder="Once Upon a Time">
                                </label></p>

                                <div class="ifp-field-grid">
                                    <p><label><strong>Season</strong><br>
                                        <select class="widefat" name="production_season">
                                            <?php foreach (['Summer','Holiday','Spring','Fall','Showcase','Other'] as $season_option): ?>
                                                <option <?php selected($values['season'], $season_option); ?>><?php echo esc_html($season_option); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label></p>

                                    <p><label><strong>Year</strong><br>
                                        <input class="widefat" type="number" min="2020" max="2100" name="production_year" value="<?php echo esc_attr($values['year']); ?>">
                                    </label></p>
                                </div>

                                <p><label><strong>Tagline</strong><br>
                                    <input class="widefat" type="text" name="production_tagline" value="<?php echo esc_attr($values['tagline']); ?>" placeholder="Come be a part of our story!">
                                </label></p>

                                <p><label><strong>Production Story / Introduction</strong><br>
                                    <textarea class="widefat" rows="6" name="production_content" placeholder="Introduce the show, its theme, and the experience..."><?php echo esc_textarea($values['content']); ?></textarea>
                                </label></p>
                            </div>
                        </section>

                        <section class="ifp-admin-card ifp-wizard-step">
                            <div class="ifp-wizard-step__number">2</div>
                            <div class="ifp-wizard-step__content">
                                <h2>Performance Dates</h2>

                                <div class="ifp-field-grid">
                                    <p><label><strong>Opening Date & Time</strong><br>
                                        <input class="widefat" type="datetime-local" name="opening_date" value="<?php echo esc_attr($values['opening_date']); ?>">
                                    </label></p>

                                    <p><label><strong>Closing Date & Time</strong><br>
                                        <input class="widefat" type="datetime-local" name="closing_date" value="<?php echo esc_attr($values['closing_date']); ?>">
                                    </label></p>
                                </div>

                                <p><label><strong>Become Current On</strong><br>
                                    <input class="widefat" type="date" name="current_start_date" value="<?php echo esc_attr($values['current_start_date']); ?>">
                                </label></p>

                                <p class="description">A published Upcoming Production becomes Current automatically on this date. A Current Production becomes Completed after its Closing Date &amp; Time. The wizard can also create separate Opening and Closing Performance entries in Important Dates.</p>
                            </div>
                        </section>

                        <section class="ifp-admin-card ifp-wizard-step">
                            <div class="ifp-wizard-step__number">3</div>
                            <div class="ifp-wizard-step__content">
                                <h2>Brand Colors &amp; Overlays</h2>

                                <div class="ifp-field-grid">
                                    <p><label><strong>Accent Color</strong><br>
                                        <input class="widefat ifp-color-input" type="color" name="accent_color" value="<?php echo esc_attr($values['accent_color']); ?>">
                                    </label></p>

                                    <p><label><strong>Secondary Color</strong><br>
                                        <input class="widefat ifp-color-input" type="color" name="secondary_color" value="<?php echo esc_attr($values['secondary_color']); ?>">
                                    </label></p>

                                    <p><label><strong>Countdown Text Color</strong><br>
                                        <input class="widefat ifp-color-input" type="color" name="countdown_text_color" value="<?php echo esc_attr($values['countdown_text_color']); ?>">
                                    </label></p>

                                    <p><label><strong>Overlay Color</strong><br>
                                        <input class="widefat ifp-color-input" type="color" name="overlay_color" value="<?php echo esc_attr($values['overlay_color']); ?>">
                                    </label></p>

                                    <p><label><strong>Hero Image Overlay Opacity (0–100%)</strong><br>
                                        <input class="widefat" type="number" min="0" max="100" name="hero_overlay_opacity" value="<?php echo esc_attr($values['hero_overlay_opacity']); ?>">
                                    </label></p>

                                    <p><label><strong>Information Box Opacity (0–100%)</strong><br>
                                        <input class="widefat" type="number" min="0" max="100" name="panel_overlay_opacity" value="<?php echo esc_attr($values['panel_overlay_opacity']); ?>">
                                    </label></p>
                                </div>

                                <p class="description">Set the hero overlay to 0 for untouched artwork. Countdown and detail boxes use their own opacity. Featured artwork and the transparent show logo are added from the Production editor after creation.</p>
                            </div>
                        </section>

                        <section class="ifp-admin-card ifp-wizard-step">
                            <div class="ifp-wizard-step__number">4</div>
                            <div class="ifp-wizard-step__content">
                                <h2>Links</h2>

                                <div class="ifp-field-grid">
                                    <p><label><strong>Tickets URL</strong><br>
                                        <input class="widefat" type="url" name="ticket_url" value="<?php echo esc_attr($values['ticket_url']); ?>" placeholder="https://">
                                    </label></p>

                                    <p><label><strong>Tickets Available On</strong><br>
                                        <input class="widefat" type="date" name="ticket_available_date" value="<?php echo esc_attr($values['ticket_available_date']); ?>">
                                    </label></p>

                                    <p><label><strong>Registration URL</strong><br>
                                        <input class="widefat" type="url" name="registration_url" value="<?php echo esc_attr($values['registration_url']); ?>" placeholder="https://">
                                    </label></p>

                                    <p><label><strong>Registration Opens</strong><br>
                                        <input class="widefat" type="datetime-local" name="registration_open" value="<?php echo esc_attr($values['registration_open']); ?>">
                                    </label></p>

                                    <p><label><strong>Registration Closes</strong><br>
                                        <input class="widefat" type="datetime-local" name="registration_close" value="<?php echo esc_attr($values['registration_close']); ?>">
                                    </label></p>

                                    <p><label><strong>Participant Hub URL</strong><br>
                                        <input class="widefat" type="url" name="participant_hub_url" value="<?php echo esc_attr($values['participant_hub_url']); ?>" placeholder="https://">
                                    </label></p>

                                    <p><label><strong>Volunteer URL</strong><br>
                                        <input class="widefat" type="url" name="volunteer_url" value="<?php echo esc_attr($values['volunteer_url']); ?>" placeholder="https://">
                                    </label></p>
                                </div>

                                <p class="description">Ticket buttons become active on the availability date and are removed after the Closing Date &amp; Time. Blank production-specific links continue to fall back to centralized Site Links where supported.</p>
                            </div>
                        </section>

                        <section class="ifp-admin-card ifp-wizard-step">
                            <div class="ifp-wizard-step__number">5</div>
                            <div class="ifp-wizard-step__content">
                                <h2>Starter Important Dates</h2>
                                <p>Select any milestones you want created now. Dates left blank will not be created.</p>

                                <div class="ifp-starter-dates">
                                    <?php
                                    $starter_dates = [
                                        'costume_fitting' => ['Costume Fitting', 'Costume Fitting'],
                                        'costume_delivery' => ['Costume Delivery', 'Costume Delivery'],
                                        'photo_day' => ['Photo Day', 'Photo Day'],
                                        'dress_rehearsal' => ['Dress Rehearsal', 'Dress Rehearsal'],
                                    ];

                                    foreach ($starter_dates as $key => $details):
                                        $starter_value = wp_parse_args($starter_values[$key] ?? [], ['date' => '', 'time' => '']);
                                    ?>
                                        <div class="ifp-starter-date">
                                            <label class="ifp-starter-date__toggle">
                                                <input type="checkbox" name="starter_dates[<?php echo esc_attr($key); ?>][enabled]" value="1" <?php checked($starter_value['date'] !== ''); ?>>
                                                <strong><?php echo esc_html($details[0]); ?></strong>
                                            </label>
                                            <input type="date" name="starter_dates[<?php echo esc_attr($key); ?>][date]" value="<?php echo esc_attr($starter_value['date']); ?>">
                                            <input type="time" name="starter_dates[<?php echo esc_attr($key); ?>][time]" value="<?php echo esc_attr($starter_value['time']); ?>">
                                        </div>
                                    <?php endforeach; ?>

                                    <div class="ifp-starter-date">
                                        <label class="ifp-starter-date__toggle">
                                            <input type="checkbox" name="create_performance_dates" value="1" <?php checked(!$is_editing || !empty($starter_values['performance'])); ?>>
                                            <strong>Opening and Closing Performances</strong>
                                        </label>
                                        <span class="description">Uses the dates entered above. Existing matching dates are updated instead of duplicated.</span>
                                    </div>
                                </div>
                            </div>
                        </section>
                    </main>

                    <aside class="ifp-wizard-sidebar">
                        <section class="ifp-admin-card ifp-wizard-publish">
                            <h2><?php echo $is_editing ? 'Update Production' : 'Create Production'; ?></h2>

                            <?php if ($can_publish): ?>
                                <p><label>
                                    <input type="checkbox" name="make_current" value="1" <?php checked($is_editing ? $lifecycle === 'current' : true); ?>>
                                    <strong>Make this the current production</strong>
                                </label></p>
                                <p class="description"><?php echo $is_editing ? 'Leave this unchecked to preserve the Production’s existing lifecycle status. A future Become Current On date takes precedence over this immediate option.' : 'The previous current production will remain published but will move to Completed. A future Become Current On date delays this change until that date.'; ?></p>

                                <p><label>
                                    <input type="checkbox" name="publish_now" value="1" <?php checked($is_editing ? $post_status === 'publish' : true); ?>>
                                    <?php echo $is_editing ? 'Keep or make this Production published' : 'Publish immediately'; ?>
                                </label></p>
                            <?php else: ?>
                                <p class="description"><?php echo $is_editing ? 'The existing publication and lifecycle status will be preserved.' : 'This production will be saved as a draft for an editor or administrator to publish.'; ?></p>
                            <?php endif; ?>

                            <button type="submit" class="button button-primary button-hero"><?php echo $is_editing ? 'Update Production Setup' : 'Create Production'; ?></button>
                        </section>

                        <section class="ifp-admin-card">
                            <h2><?php echo $is_editing ? 'After Updating' : 'After Creation'; ?></h2>
                            <ol class="ifp-steps">
                                <li>Add the Featured Image.</li>
                                <li>Add the transparent Show Logo.</li>
                                <li>Review the readiness checklist.</li>
                                <li>Add participant resources, contacts, and sponsors.</li>
                            </ol>
                        </section>
                    </aside>
                </div>
            </form>
        </div>
        <?php
    }

    public static function handle_create() {
        if (!current_user_can('edit_posts')) {
            wp_die(esc_html__('You do not have permission to create productions.', 'ice-field-productions'));
        }

        check_admin_referer('ifp_create_production', 'ifp_wizard_nonce');

        $title = sanitize_text_field(wp_unslash($_POST['production_title'] ?? ''));
        if ($title === '') {
            wp_die(esc_html__('A production title is required.', 'ice-field-productions'));
        }

        $existing_id = absint($_POST['production_id'] ?? 0);
        $existing = $existing_id ? get_post($existing_id) : null;
        if ($existing_id && (!$existing || $existing->post_type !== 'ifp_production' || !current_user_can('edit_post', $existing_id))) {
            wp_die(esc_html__('You do not have permission to update that Production.', 'ice-field-productions'));
        }

        $can_publish = current_user_can('publish_posts');
        $publish = $can_publish && !empty($_POST['publish_now']);
        $existing_status = $existing ? get_post_status($existing) : '';
        if ($existing && !$can_publish) {
            $resolved_status = $existing_status;
        } else {
            $resolved_status = $publish
                ? 'publish'
                : ($existing_status === 'publish'
                    ? 'draft'
                    : (in_array($existing_status, ['draft','pending','private','future'], true) ? $existing_status : 'draft'));
        }
        $postarr = [
            'post_type' => 'ifp_production',
            'post_title' => $title,
            'post_content' => wp_kses_post(wp_unslash($_POST['production_content'] ?? '')),
        ];
        if (!$existing || $can_publish) $postarr['post_status'] = $resolved_status;
        if ($existing) $postarr['ID'] = $existing_id;

        $post_id = $existing
            ? wp_update_post(wp_slash($postarr), true)
            : wp_insert_post(wp_slash($postarr), true);

        if (is_wp_error($post_id)) {
            wp_die(esc_html($post_id->get_error_message()));
        }

        $meta = [
            '_ifp_season' => sanitize_text_field(wp_unslash($_POST['production_season'] ?? '')),
            '_ifp_year' => absint($_POST['production_year'] ?? wp_date('Y')),
            '_ifp_tagline' => sanitize_text_field(wp_unslash($_POST['production_tagline'] ?? '')),
            '_ifp_opening_date' => sanitize_text_field(wp_unslash($_POST['opening_date'] ?? '')),
            '_ifp_closing_date' => sanitize_text_field(wp_unslash($_POST['closing_date'] ?? '')),
            IFP_Production_Status::CURRENT_START_KEY => sanitize_text_field(wp_unslash($_POST['current_start_date'] ?? '')),
            '_ifp_accent_color' => sanitize_hex_color($_POST['accent_color'] ?? '') ?: '#ff8033',
            '_ifp_secondary_color' => sanitize_hex_color($_POST['secondary_color'] ?? '') ?: '#003b5c',
            '_ifp_countdown_text_color' => sanitize_hex_color($_POST['countdown_text_color'] ?? '') ?: '#ffffff',
            '_ifp_overlay_color' => sanitize_hex_color($_POST['overlay_color'] ?? '') ?: '#003b5c',
            '_ifp_hero_overlay_opacity' => max(0, min(100, absint($_POST['hero_overlay_opacity'] ?? 92))),
            '_ifp_panel_overlay_opacity' => max(0, min(100, absint($_POST['panel_overlay_opacity'] ?? 78))),
            '_ifp_ticket_url' => esc_url_raw(wp_unslash($_POST['ticket_url'] ?? '')),
            '_ifp_ticket_available_date' => sanitize_text_field(wp_unslash($_POST['ticket_available_date'] ?? '')),
            '_ifp_registration_url' => esc_url_raw(wp_unslash($_POST['registration_url'] ?? '')),
            '_ifp_registration_open' => sanitize_text_field(wp_unslash($_POST['registration_open'] ?? '')),
            '_ifp_registration_close' => sanitize_text_field(wp_unslash($_POST['registration_close'] ?? '')),
            '_ifp_participant_hub_url' => esc_url_raw(wp_unslash($_POST['participant_hub_url'] ?? '')),
            '_ifp_volunteer_url' => esc_url_raw(wp_unslash($_POST['volunteer_url'] ?? '')),
        ];

        foreach ($meta as $key => $value) {
            update_post_meta($post_id, $key, $value);
        }

        $current_start_date = sanitize_text_field(wp_unslash($_POST['current_start_date'] ?? ''));
        $scheduled_for_future = $current_start_date !== '' && $current_start_date > current_time('Y-m-d');
        if ($publish && !empty($_POST['make_current']) && !$scheduled_for_future) {
            IFP_Production_Status::set($post_id, 'current', 'completed');
        } elseif (!$existing) {
            IFP_Production_Status::set($post_id, 'upcoming');
        }

        self::create_starter_events($post_id);
        IFP_Production_Status::reconcile(true);

        wp_safe_redirect(add_query_arg(
            $existing
                ? ['page' => 'ifp-production-wizard', 'production_id' => $post_id, 'updated' => $post_id]
                : ['page' => 'ifp-production-wizard', 'created' => $post_id],
            admin_url('admin.php')
        ));
        exit;
    }

    private static function create_starter_events($production_id) {
        $opening = sanitize_text_field(wp_unslash($_POST['opening_date'] ?? ''));
        $closing = sanitize_text_field(wp_unslash($_POST['closing_date'] ?? ''));

        if (!empty($_POST['create_performance_dates'])) {
            if ($opening) self::create_event($production_id, 'Opening Performance', 'Performance', $opening);
            if ($closing && $closing !== $opening) self::create_event($production_id, 'Closing Performance', 'Performance', $closing);
        }

        $definitions = [
            'costume_fitting' => ['Costume Fitting', 'Costume Fitting'],
            'costume_delivery' => ['Costume Delivery', 'Costume Delivery'],
            'photo_day' => ['Photo Day', 'Photo Day'],
            'dress_rehearsal' => ['Dress Rehearsal', 'Dress Rehearsal'],
        ];

        $submitted = isset($_POST['starter_dates']) && is_array($_POST['starter_dates'])
            ? wp_unslash($_POST['starter_dates'])
            : [];

        foreach ($definitions as $key => $definition) {
            $row = isset($submitted[$key]) && is_array($submitted[$key]) ? $submitted[$key] : [];
            if (empty($row['enabled']) || empty($row['date'])) continue;

            $date_time = sanitize_text_field($row['date']);
            if (!empty($row['time'])) {
                $date_time .= 'T' . sanitize_text_field($row['time']);
            }

            self::create_event($production_id, $definition[0], $definition[1], $date_time);
        }
    }

    private static function create_event($production_id, $title, $type, $date_time) {
        $timestamp = strtotime($date_time);
        if (!$timestamp) return;

        $existing = get_posts([
            'post_type' => 'ifp_event',
            'post_status' => 'any',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'title' => $title,
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => '_ifp_production_scope',
                    'value' => (string) absint($production_id),
                ],
                [
                    'key' => '_ifp_event_type',
                    'value' => $type,
                ],
            ],
        ]);

        $event_id = $existing ? absint($existing[0]) : wp_insert_post([
            'post_type' => 'ifp_event',
            'post_status' => get_post_status($production_id) === 'publish' && current_user_can('publish_posts') ? 'publish' : 'draft',
            'post_title' => $title,
        ]);

        if (!$event_id || is_wp_error($event_id)) return;

        update_post_meta($event_id, '_ifp_event_date', wp_date('Y-m-d', $timestamp));
        update_post_meta($event_id, '_ifp_event_time', strpos($date_time, 'T') !== false ? wp_date('H:i', $timestamp) : '');
        update_post_meta($event_id, '_ifp_event_type', $type);
        update_post_meta($event_id, '_ifp_event_featured', '1');
        update_post_meta($event_id, '_ifp_production_scope', (string) absint($production_id));
    }

    private static function editable_productions() {
        $productions = get_posts([
            'post_type' => 'ifp_production',
            'post_status' => ['publish','draft','pending','private','future'],
            'posts_per_page' => -1,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        return array_values(array_filter($productions, function($production) {
            if (!current_user_can('edit_post', $production->ID)) return false;
            return !in_array(IFP_Production_Status::get($production->ID), ['completed','archived'], true);
        }));
    }

    private static function starter_event_values($production_id) {
        $values = [];
        $events = get_posts([
            'post_type' => 'ifp_event',
            'post_status' => 'any',
            'posts_per_page' => -1,
            'meta_key' => '_ifp_production_scope',
            'meta_value' => (string) absint($production_id),
        ]);
        $titles = [
            'Costume Fitting' => 'costume_fitting',
            'Costume Delivery' => 'costume_delivery',
            'Photo Day' => 'photo_day',
            'Dress Rehearsal' => 'dress_rehearsal',
        ];

        foreach ($events as $event) {
            $type = get_post_meta($event->ID, '_ifp_event_type', true);
            if ($type === 'Performance' && in_array($event->post_title, ['Opening Performance','Closing Performance'], true)) {
                $values['performance'] = true;
            }

            if (!isset($titles[$event->post_title])) continue;
            $values[$titles[$event->post_title]] = [
                'date' => sanitize_text_field((string) get_post_meta($event->ID, '_ifp_event_date', true)),
                'time' => sanitize_text_field((string) get_post_meta($event->ID, '_ifp_event_time', true)),
            ];
        }

        return $values;
    }

    private static function datetime_input_value($value) {
        $value = trim((string) $value);
        if ($value === '') return '';
        $timestamp = strtotime($value);
        return $timestamp ? wp_date('Y-m-d\\TH:i', $timestamp) : '';
    }
}
