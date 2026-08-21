<?php
if (!defined('ABSPATH')) exit;

class IFP_Groups {
    const NONCE_ACTION = 'ifp_save_group';
    const NONCE_NAME = 'ifp_group_nonce';

    public static function init() {
        add_action('init', [__CLASS__, 'register_post_type']);
        add_action('add_meta_boxes', [__CLASS__, 'add_meta_boxes']);
        add_action('save_post_ifp_group', [__CLASS__, 'save']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'admin_assets']);

        add_filter('manage_ifp_group_posts_columns', [__CLASS__, 'columns']);
        add_action('manage_ifp_group_posts_custom_column', [__CLASS__, 'column_content'], 10, 2);
        add_filter('manage_edit-ifp_group_sortable_columns', [__CLASS__, 'sortable_columns']);
        add_action('pre_get_posts', [__CLASS__, 'sort_groups']);

        add_action('restrict_manage_posts', [__CLASS__, 'filters']);
        add_action('pre_get_posts', [__CLASS__, 'apply_filters']);

        add_shortcode('ifp_groups', [__CLASS__, 'shortcode']);
    }

    public static function register_post_type() {
        register_post_type('ifp_group', [
            'labels' => [
                'name' => 'Groups',
                'singular_name' => 'Group',
                'add_new' => 'Add Group',
                'add_new_item' => 'Add New Group',
                'edit_item' => 'Edit Group',
                'new_item' => 'New Group',
                'view_item' => 'View Group',
                'search_items' => 'Search Groups',
                'not_found' => 'No groups found',
                'menu_name' => 'Groups',
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'ifp-dashboard',
            'supports' => ['title', 'editor', 'excerpt', 'thumbnail', 'page-attributes'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
            'show_in_rest' => true,
            'menu_icon' => 'dashicons-groups',
        ]);
    }

    public static function add_meta_boxes() {
        add_meta_box(
            'ifp_group_details',
            'Group Details',
            [__CLASS__, 'render_details'],
            'ifp_group',
            'normal',
            'high'
        );

        add_meta_box(
            'ifp_group_participants',
            'People / Participants',
            [__CLASS__, 'render_participants'],
            'ifp_group',
            'normal',
            'default'
        );

        add_meta_box(
            'ifp_group_dash',
            'Dash Connection',
            [__CLASS__, 'render_dash'],
            'ifp_group',
            'side',
            'default'
        );
    }

    public static function admin_assets($hook) {
        global $post_type;
        if ($post_type !== 'ifp_group') return;

        wp_enqueue_style('ifp-admin', IFP_URL . 'assets/admin.css', [], IFP_VERSION);
        wp_enqueue_script(
            'ifp-groups-admin',
            IFP_URL . 'assets/groups-admin.js',
            ['jquery'],
            IFP_VERSION,
            true
        );
    }

    private static function meta($post_id, $key, $default = '') {
        $value = get_post_meta($post_id, $key, true);
        return $value === '' ? $default : $value;
    }

    private static function current_production_id() {
        return class_exists('IFP_Relationships')
            ? IFP_Relationships::current_id()
            : 0;
    }

    private static function productions() {
        return get_posts([
            'post_type' => 'ifp_production',
            'post_status' => ['publish','draft','private','future'],
            'posts_per_page' => -1,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);
    }

    private static function performance_events() {
        return get_posts([
            'post_type' => 'ifp_event',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'meta_key' => '_ifp_event_date',
            'orderby' => 'meta_value',
            'order' => 'ASC',
            'meta_query' => [[
                'key' => '_ifp_event_type',
                'value' => 'Performance',
            ]],
        ]);
    }

    public static function group_types() {
        return [
            'Large Group',
            'Small Group',
            'Soloist',
            'Duet',
            'Trio',
            'Ensemble',
            'Opening Number',
            'Closing Number',
            'Guest Performance',
            'Other',
        ];
    }

    public static function render_details($post) {
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);

        $production_id = absint(self::meta($post->ID, '_ifp_group_production_id', self::current_production_id()));
        $division_id = absint(self::meta($post->ID, '_ifp_group_division_id', 0));
        $type = self::meta($post->ID, '_ifp_group_type', 'Large Group');
        $coach = self::meta($post->ID, '_ifp_group_coach');
        $rehearsal = self::meta($post->ID, '_ifp_group_rehearsal');
        $music = self::meta($post->ID, '_ifp_group_music');
        $costume = self::meta($post->ID, '_ifp_group_costume');
        $visibility = self::meta($post->ID, '_ifp_group_visibility', 'internal');
        $registration_url = self::meta($post->ID, '_ifp_registration_url');
        $performance_ids = array_map('absint', (array) self::meta($post->ID, '_ifp_group_performance_ids', []));
        ?>
        <div class="ifp-group-fields">
            <div class="ifp-field-grid">
                <p>
                    <label><strong>Production</strong><br>
                        <select class="widefat" name="ifp_group_production_id">
                            <option value="0">— Select Production —</option>
                            <?php foreach (self::productions() as $production): ?>
                                <option value="<?php echo esc_attr($production->ID); ?>" <?php selected($production_id, $production->ID); ?>>
                                    <?php echo esc_html($production->post_title); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </p>

                <p>
                    <label><strong>Division</strong><br>
                        <select class="widefat" name="ifp_group_division_id">
                            <option value="0">— No Division —</option>
                            <?php foreach (get_posts(['post_type'=>'ifp_division','post_status'=>'any','posts_per_page'=>-1,'orderby'=>'title','order'=>'ASC']) as $division): ?>
                                <option value="<?php echo esc_attr($division->ID); ?>" <?php selected($division_id, $division->ID); ?>><?php echo esc_html($division->post_title); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </p>

                <p>
                    <label><strong>Group Type</strong><br>
                        <select class="widefat" name="ifp_group_type">
                            <?php foreach (self::group_types() as $option): ?>
                                <option value="<?php echo esc_attr($option); ?>" <?php selected($type, $option); ?>>
                                    <?php echo esc_html($option); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </p>

                <p>
                    <label><strong>Coach / Choreographer</strong><br>
                        <input class="widefat" type="text" name="ifp_group_coach" value="<?php echo esc_attr($coach); ?>">
                    </label>
                </p>

                <p>
                    <label><strong>Visibility</strong><br>
                        <select class="widefat" name="ifp_group_visibility">
                            <option value="internal" <?php selected($visibility, 'internal'); ?>>Internal only</option>
                            <option value="participants" <?php selected($visibility, 'participants'); ?>>Participant Hub</option>
                            <option value="public" <?php selected($visibility, 'public'); ?>>Public</option>
                        </select>
                    </label>
                </p>

                <p>
                    <label><strong>Registration URL</strong><br>
                        <input class="widefat" type="url" name="ifp_group_registration_url" value="<?php echo esc_attr($registration_url); ?>" placeholder="Generated automatically from Dash">
                    </label>
                    <span class="description">A manual URL is preserved during later Dash syncs.</span>
                </p>
            </div>

            <p>
                <label><strong>Performance Assignments</strong></label>
            </p>
            <div class="ifp-group-performance-list">
                <?php
                $events = self::performance_events();
                if (!$events):
                ?>
                    <p class="description">No Important Dates marked as Performance are available yet.</p>
                <?php else: ?>
                    <?php foreach ($events as $event):
                        $date = get_post_meta($event->ID, '_ifp_event_date', true);
                        $date_range = IFP_Production_Pages::event_date_range($event->ID);
                        $time = get_post_meta($event->ID, '_ifp_event_time', true);
                        $end_time = get_post_meta($event->ID, '_ifp_event_end_time', true);
                        $time_range = $time ? wp_date(get_option('time_format'), strtotime($time)) : '';
                        if ($time_range && $end_time) {
                            $time_range .= '–' . wp_date(get_option('time_format'), strtotime($end_time));
                        }
                    ?>
                        <label>
                            <input
                                type="checkbox"
                                name="ifp_group_performance_ids[]"
                                value="<?php echo esc_attr($event->ID); ?>"
                                <?php checked(in_array($event->ID, $performance_ids, true)); ?>
                            >
                            <strong><?php echo esc_html($event->post_title); ?></strong>
                            <?php if ($date): ?>
                                <span><?php echo esc_html($date_range); ?></span>
                            <?php endif; ?>
                            <?php if ($time_range): ?>
                                <span><?php echo esc_html($time_range); ?></span>
                            <?php endif; ?>
                        </label>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <p>
                <label><strong>Rehearsal Information</strong><br>
                    <textarea class="widefat" rows="4" name="ifp_group_rehearsal"><?php echo esc_textarea($rehearsal); ?></textarea>
                </label>
            </p>

            <div class="ifp-field-grid">
                <p>
                    <label><strong>Music</strong><br>
                        <input class="widefat" type="text" name="ifp_group_music" value="<?php echo esc_attr($music); ?>" placeholder="Song title, link, or file notes">
                    </label>
                </p>

                <p>
                    <label><strong>Costume</strong><br>
                        <input class="widefat" type="text" name="ifp_group_costume" value="<?php echo esc_attr($costume); ?>" placeholder="Costume name, color, or status">
                    </label>
                </p>
            </div>

            <p class="description">Use the main editor for longer notes, choreography details, or internal instructions.</p>
        </div>
        <?php
    }

    public static function render_participants($post) {
        $participants = self::meta($post->ID, '_ifp_group_participants', []);
        if (!is_array($participants)) $participants = [];
        ?>
        <div class="ifp-group-participants">
            <?php
            $person_ids = array_values(array_unique(array_filter(array_map(function($row) { return absint($row['participant_id'] ?? 0); }, $participants))));
            $emails = [];
            foreach ($participants as $participant) {
                if (!is_array($participant)) continue;
                $email = sanitize_email((string) ($participant['email'] ?? ''));
                if ($email) $emails[strtolower($email)] = $email;
            }
            foreach ($person_ids as $person_id) {
                $email = sanitize_email(get_post_meta($person_id, '_ifp_participant_email', true));
                if ($email) $emails[strtolower($email)] = $email;
            }
            $people_url = add_query_arg(['post_type' => 'ifp_participant', 'ifp_people_group' => $post->ID], admin_url('edit.php'));
            ?>
            <p class="ifp-group-people-actions">
                <a class="button" href="<?php echo esc_url($people_url); ?>">View People</a>
                <?php if ($emails): ?>
                    <a class="button button-primary" href="<?php echo esc_url(IFP_Communications::compose_url('group', $post->ID)); ?>">Email Group</a>
                <?php else: ?>
                    <span class="button disabled">Email Group</span>
                <?php endif; ?>
            </p>
            <p class="description">Dash-linked people are synchronized from the connected Team. Manual entries and your notes are preserved during roster updates. Communication Center emails are reviewed before sending and recorded in local history.</p>

            <table class="widefat striped ifp-participants-table">
                <thead>
                    <tr>
                        <th>First Name</th>
                        <th>Last Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Role / Notes</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody class="ifp-participants-rows">
                    <?php foreach ($participants as $index => $participant): ?>
                        <?php self::participant_row($index, $participant); ?>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p>
                <button type="button" class="button ifp-add-participant">Add Participant</button>
            </p>

            <script type="text/template" id="ifp-participant-row-template">
                <?php self::participant_row('__INDEX__', []); ?>
            </script>
        </div>
        <?php
    }

    private static function participant_row($index, $participant) {
        $participant = wp_parse_args((array) $participant, [
            'first_name' => '',
            'last_name' => '',
            'email' => '',
            'phone' => '',
            'notes' => '',
            'dash_customer_id' => '',
            'dash_registration_id' => '',
            'participant_id' => '',
        ]);
        ?>
        <tr class="ifp-participant-row">
            <td>
                <input class="widefat" type="text" name="ifp_group_participants[<?php echo esc_attr($index); ?>][first_name]" value="<?php echo esc_attr($participant['first_name']); ?>">
            </td>
            <td>
                <input class="widefat" type="text" name="ifp_group_participants[<?php echo esc_attr($index); ?>][last_name]" value="<?php echo esc_attr($participant['last_name']); ?>">
            </td>
            <td>
                <input class="widefat" type="email" name="ifp_group_participants[<?php echo esc_attr($index); ?>][email]" value="<?php echo esc_attr($participant['email']); ?>">
            </td>
            <td>
                <input class="widefat" type="text" name="ifp_group_participants[<?php echo esc_attr($index); ?>][phone]" value="<?php echo esc_attr($participant['phone']); ?>">
            </td>
            <td>
                <input class="widefat" type="text" name="ifp_group_participants[<?php echo esc_attr($index); ?>][notes]" value="<?php echo esc_attr($participant['notes']); ?>">
                <input type="hidden" name="ifp_group_participants[<?php echo esc_attr($index); ?>][dash_customer_id]" value="<?php echo esc_attr($participant['dash_customer_id']); ?>">
                <input type="hidden" name="ifp_group_participants[<?php echo esc_attr($index); ?>][dash_registration_id]" value="<?php echo esc_attr($participant['dash_registration_id']); ?>">
                <input type="hidden" name="ifp_group_participants[<?php echo esc_attr($index); ?>][participant_id]" value="<?php echo esc_attr($participant['participant_id']); ?>">
            </td>
            <td>
                <button type="button" class="button-link-delete ifp-remove-participant">Remove</button>
            </td>
        </tr>
        <?php
    }

    public static function render_dash($post) {
        $team = self::meta($post->ID, '_ifp_dash_team_id');
        $season = self::meta($post->ID, '_ifp_dash_season_id');
        $league = self::meta($post->ID, '_ifp_dash_league_id');
        $product = self::meta($post->ID, '_ifp_dash_product_id');
        $status = self::meta($post->ID, '_ifp_dash_status');
        $last_sync = self::meta($post->ID, '_ifp_dash_last_sync');
        $participant_sync = self::meta($post->ID, '_ifp_dash_participants_last_sync');
        $roster_count = absint(self::meta($post->ID, '_ifp_dash_roster_count', 0));
        $registration_url = self::meta($post->ID, '_ifp_registration_url');
        ?>
        <p><label><strong>Team ID</strong><br>
            <input class="widefat" type="number" name="ifp_dash_team_id" value="<?php echo esc_attr($team); ?>">
        </label></p>

        <p><label><strong>Season ID</strong><br>
            <input class="widefat" type="number" name="ifp_dash_season_id" value="<?php echo esc_attr($season); ?>">
        </label></p>

        <p><label><strong>League ID</strong><br>
            <input class="widefat" type="number" name="ifp_dash_league_id" value="<?php echo esc_attr($league); ?>">
        </label></p>

        <p><label><strong>Product ID</strong><br>
            <input class="widefat" type="number" name="ifp_dash_product_id" value="<?php echo esc_attr($product); ?>">
        </label></p>

        <?php if ($status): ?>
            <p><strong>Dash status</strong><br><?php echo esc_html(ucfirst($status)); ?></p>
        <?php endif; ?>

        <?php if ($last_sync): ?>
            <p><strong>Last imported</strong><br><?php echo esc_html($last_sync); ?></p>
        <?php else: ?>
            <p class="description">Not connected to a Dash Team yet.</p>
        <?php endif; ?>

        <?php if ($team): ?>
            <p><strong>Roster</strong><br><?php echo esc_html($roster_count); ?> registered participant(s)</p>
            <?php if ($participant_sync): ?><p><strong>Participants last synced</strong><br><?php echo esc_html($participant_sync); ?></p><?php endif; ?>
            <?php if ($registration_url): ?><p><a class="button" href="<?php echo esc_url($registration_url); ?>" target="_blank" rel="noopener noreferrer">Open Registration</a></p><?php endif; ?>
            <p>
                <a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(add_query_arg(['action' => 'ifp_dash_sync_participants', 'group_id' => $post->ID], admin_url('admin-post.php')), 'ifp_dash_sync_participants_' . $post->ID)); ?>">Sync Participants</a>
            </p>
            <p><a class="button" href="<?php echo esc_url(add_query_arg(['endpoint' => 'teams/' . absint($team)], admin_url('admin.php?page=ifdc-explorer'))); ?>">Inspect Team</a></p>
        <?php endif; ?>

        <?php if (!empty($_GET['ifp_synced'])): ?><p style="color:#18713c"><strong><?php echo esc_html(absint($_GET['ifp_synced'])); ?> participant(s) synchronized.</strong></p><?php endif; ?>
        <?php if (!empty($_GET['ifp_sync_error'])): ?><p style="color:#b32d2e"><strong><?php echo esc_html(sanitize_text_field(wp_unslash($_GET['ifp_sync_error']))); ?></strong></p><?php endif; ?>

        <p class="description">Dash Discovery manages these identifiers. Manual edits are available for repair or migration.</p>
        <?php
    }

    public static function save($post_id) {
        if (!isset($_POST[self::NONCE_NAME])) return;
        if (!wp_verify_nonce(
            sanitize_text_field(wp_unslash($_POST[self::NONCE_NAME])),
            self::NONCE_ACTION
        )) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        update_post_meta($post_id, '_ifp_group_production_id', absint($_POST['ifp_group_production_id'] ?? 0));
        update_post_meta($post_id, '_ifp_group_division_id', absint($_POST['ifp_group_division_id'] ?? 0));

        $type = sanitize_text_field(wp_unslash($_POST['ifp_group_type'] ?? 'Large Group'));
        if (!in_array($type, self::group_types(), true)) $type = 'Other';
        update_post_meta($post_id, '_ifp_group_type', $type);

        update_post_meta($post_id, '_ifp_group_coach', sanitize_text_field(wp_unslash($_POST['ifp_group_coach'] ?? '')));
        update_post_meta($post_id, '_ifp_group_rehearsal', sanitize_textarea_field(wp_unslash($_POST['ifp_group_rehearsal'] ?? '')));
        update_post_meta($post_id, '_ifp_group_music', sanitize_text_field(wp_unslash($_POST['ifp_group_music'] ?? '')));
        update_post_meta($post_id, '_ifp_group_costume', sanitize_text_field(wp_unslash($_POST['ifp_group_costume'] ?? '')));
        update_post_meta($post_id, '_ifp_registration_url', esc_url_raw(wp_unslash($_POST['ifp_group_registration_url'] ?? '')));

        $visibility = sanitize_text_field(wp_unslash($_POST['ifp_group_visibility'] ?? 'internal'));
        if (!in_array($visibility, ['internal','participants','public'], true)) $visibility = 'internal';
        update_post_meta($post_id, '_ifp_group_visibility', $visibility);

        $performance_ids = array_values(array_filter(array_map(
            'absint',
            (array) ($_POST['ifp_group_performance_ids'] ?? [])
        )));
        update_post_meta($post_id, '_ifp_group_performance_ids', $performance_ids);

        foreach (['team','season','league','product'] as $field) {
            update_post_meta(
                $post_id,
                "_ifp_dash_{$field}_id",
                absint($_POST["ifp_dash_{$field}_id"] ?? 0)
            );
        }

        $participants = [];
        $submitted = isset($_POST['ifp_group_participants']) && is_array($_POST['ifp_group_participants'])
            ? wp_unslash($_POST['ifp_group_participants'])
            : [];

        foreach ($submitted as $row) {
            if (!is_array($row)) continue;

            $participant = [
                'first_name' => sanitize_text_field($row['first_name'] ?? ''),
                'last_name' => sanitize_text_field($row['last_name'] ?? ''),
                'email' => sanitize_email($row['email'] ?? ''),
                'phone' => sanitize_text_field($row['phone'] ?? ''),
                'notes' => sanitize_text_field($row['notes'] ?? ''),
                'dash_customer_id' => sanitize_text_field($row['dash_customer_id'] ?? ''),
                'dash_registration_id' => sanitize_text_field($row['dash_registration_id'] ?? ''),
                'participant_id' => absint($row['participant_id'] ?? 0),
            ];

            if ($participant['first_name'] === '' && $participant['last_name'] === '') {
                continue;
            }

            $participants[] = $participant;
        }

        update_post_meta($post_id, '_ifp_group_participants', $participants);
        update_post_meta($post_id, '_ifp_group_participant_count', count($participants));
        update_post_meta($post_id, '_ifp_group_participant_ids', array_values(array_unique(array_filter(array_map(function($row) { return absint($row['participant_id'] ?? 0); }, $participants)))));
    }

    public static function columns($columns) {
        $new = [];
        foreach ($columns as $key => $label) {
            $new[$key] = $label;

            if ($key === 'title') {
                $new['ifp_group_type'] = 'Type';
                $new['ifp_group_production'] = 'Production';
                $new['ifp_group_programming'] = 'Programming Program';
                $new['ifp_group_participants'] = 'Participants';
                $new['ifp_group_dash'] = 'Dash';
            }
        }
        return $new;
    }

    public static function column_content($column, $post_id) {
        if ($column === 'ifp_group_type') {
            echo esc_html(self::meta($post_id, '_ifp_group_type', '—'));
        }

        if ($column === 'ifp_group_production') {
            $production_id = absint(self::meta($post_id, '_ifp_group_production_id'));
            echo $production_id ? esc_html(get_the_title($production_id)) : '—';
        }

        if ($column === 'ifp_group_programming') {
            $program_id = absint(self::meta($post_id, '_ifp_programming_program_id'));
            echo $program_id && get_post_type($program_id) === 'ifprog_program'
                ? '<a href="'.esc_url(get_edit_post_link($program_id)).'">'.esc_html(get_the_title($program_id)).'</a>'
                : '—';
        }

        if ($column === 'ifp_group_participants') {
            echo esc_html((string) absint(self::meta($post_id, '_ifp_group_participant_count', 0)));
        }

        if ($column === 'ifp_group_dash') {
            $team_id = absint(self::meta($post_id, '_ifp_dash_team_id'));
            $league_id = absint(self::meta($post_id, '_ifp_dash_league_id'));

            if ($team_id) {
                echo '<span class="ifp-scope-badge ifp-scope-badge--current">Team '.esc_html($team_id).'</span>';
                if ($league_id) echo ' <span class="ifp-scope-badge">League '.esc_html($league_id).'</span>';
            } else {
                echo '<span class="ifp-scope-badge ifp-scope-badge--shared">Manual</span>';
            }
        }
    }

    public static function sortable_columns($columns) {
        $columns['ifp_group_type'] = 'ifp_group_type';
        $columns['ifp_group_participants'] = 'ifp_group_participants';
        return $columns;
    }

    public static function sort_groups($query) {
        if (!is_admin() || !$query->is_main_query()) return;
        if ($query->get('post_type') !== 'ifp_group') return;

        if ($query->get('orderby') === 'ifp_group_type') {
            $query->set('meta_key', '_ifp_group_type');
            $query->set('orderby', 'meta_value');
        }

        if ($query->get('orderby') === 'ifp_group_participants') {
            $query->set('meta_key', '_ifp_group_participant_count');
            $query->set('orderby', 'meta_value_num');
        }
    }

    public static function filters($post_type) {
        if ($post_type !== 'ifp_group') return;

        $selected_production = absint($_GET['ifp_group_production_filter'] ?? 0);
        $selected_type = sanitize_text_field(wp_unslash($_GET['ifp_group_type_filter'] ?? ''));
        ?>
        <select name="ifp_group_production_filter">
            <option value="0">All Productions</option>
            <?php foreach (self::productions() as $production): ?>
                <option value="<?php echo esc_attr($production->ID); ?>" <?php selected($selected_production, $production->ID); ?>>
                    <?php echo esc_html($production->post_title); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select name="ifp_group_type_filter">
            <option value="">All Group Types</option>
            <?php foreach (self::group_types() as $type): ?>
                <option value="<?php echo esc_attr($type); ?>" <?php selected($selected_type, $type); ?>>
                    <?php echo esc_html($type); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    public static function apply_filters($query) {
        if (!is_admin() || !$query->is_main_query()) return;
        if ($query->get('post_type') !== 'ifp_group') return;

        $meta_query = [];

        $production_id = absint($_GET['ifp_group_production_filter'] ?? 0);
        if ($production_id) {
            $meta_query[] = [
                'key' => '_ifp_group_production_id',
                'value' => $production_id,
                'type' => 'NUMERIC',
            ];
        }

        $type = sanitize_text_field(wp_unslash($_GET['ifp_group_type_filter'] ?? ''));
        if ($type !== '') {
            $meta_query[] = [
                'key' => '_ifp_group_type',
                'value' => $type,
            ];
        }

        if ($meta_query) {
            $query->set('meta_query', $meta_query);
        }
    }

    public static function shortcode($atts) {
        $atts = shortcode_atts([
            'production_id' => 0,
            'visibility' => 'public',
            'show_registration' => '1',
        ], $atts);

        $production_id = absint($atts['production_id']);
        if (!$production_id) {
            $production_id = self::current_production_id();
        }

        $meta_query = [];

        if ($production_id) {
            $meta_query[] = [
                'key' => '_ifp_group_production_id',
                'value' => $production_id,
                'type' => 'NUMERIC',
            ];
        }

        if ($atts['visibility'] !== 'all') {
            $meta_query[] = [
                'key' => '_ifp_group_visibility',
                'value' => $atts['visibility'] === 'participants'
                    ? ['participants','public']
                    : 'public',
                'compare' => is_array($atts['visibility'] === 'participants' ? ['participants','public'] : 'public') ? 'IN' : '=',
            ];
        }

        $query = new WP_Query([
            'post_type' => 'ifp_group',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => ['menu_order' => 'ASC', 'title' => 'ASC'],
            'meta_query' => $meta_query,
        ]);

        if (!$query->have_posts()) {
            return '<div class="ifp-empty">No groups have been published.</div>';
        }

        ob_start();
        echo '<div class="ifp-groups-grid">';

        while ($query->have_posts()) {
            $query->the_post();
            $id = get_the_ID();
            $type = self::meta($id, '_ifp_group_type');
            $coach = self::meta($id, '_ifp_group_coach');
            $count = absint(self::meta($id, '_ifp_group_participant_count', 0));
            $registration_url = self::meta($id, '_ifp_registration_url');

            echo '<article class="ifp-group-card">';
            if (has_post_thumbnail()) {
                echo get_the_post_thumbnail($id, 'medium_large', ['class' => 'ifp-group-card__image']);
            }
            echo '<div class="ifp-group-card__body">';
            echo '<span class="ifp-group-card__type">'.esc_html($type).'</span>';
            echo '<h3>'.esc_html(get_the_title()).'</h3>';
            if (has_excerpt()) echo '<p>'.esc_html(get_the_excerpt()).'</p>';
            if ($coach) echo '<p><strong>Coach:</strong> '.esc_html($coach).'</p>';
            if ($count) echo '<p><strong>Cast:</strong> '.esc_html($count).' participants</p>';
            if ($atts['show_registration'] !== '0' && $registration_url) echo '<a class="ifp-button ifp-button--small ifp-group-card__registration" href="'.esc_url($registration_url).'" target="_blank" rel="noopener noreferrer">Register</a>';
            echo '</div></article>';
        }

        wp_reset_postdata();
        echo '</div>';
        return ob_get_clean();
    }
}
