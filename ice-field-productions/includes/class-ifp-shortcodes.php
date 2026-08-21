<?php
if (!defined('ABSPATH')) exit;

class IFP_Shortcodes {
    public static function init() {
        add_shortcode('ifp_current_production',[__CLASS__,'current']);
        add_shortcode('ifp_countdown',[__CLASS__,'countdown']);
        add_shortcode('ifp_registration',[__CLASS__,'registration']);
        add_shortcode('ifp_sponsors',[__CLASS__,'sponsors']);
        add_shortcode('ifp_participant_notices',[__CLASS__,'notices']);
        add_shortcode('ifp_participant_resources',[__CLASS__,'resources']);
        add_action('wp_enqueue_scripts',[__CLASS__,'assets']);
    }

    public static function assets() {
        wp_enqueue_style('ifp-public', IFP_URL.'assets/public.css', [], IFP_VERSION);
        wp_enqueue_script('ifp-public', IFP_URL.'assets/public.js', [], IFP_VERSION, true);
    }

    private static function current_post() {
        return IFP_Production_Status::current(true);
    }

    public static function current() {
        $p = self::current_post();
        if (!$p) return '<div class="ifp-empty">No current production is configured.</div>';
        $ticket_state = IFP_Production_Pages::ticket_state($p->ID);
        $ticket = $ticket_state['status'] === 'active' ? $ticket_state['url'] : '';
        $registration_state = IFP_Production_Pages::registration_state($p->ID);
        $register = $registration_state['status'] === 'open'
            ? get_post_meta($p->ID,'_ifp_registration_url',true)
            : '';
        $volunteer = get_post_meta($p->ID,'_ifp_volunteer_url',true) ?: IFP_Links::get('volunteer');
        $tagline = get_post_meta($p->ID,'_ifp_tagline',true);
        $accent = get_post_meta($p->ID,'_ifp_accent_color',true) ?: '#ff8033';
        ob_start(); ?>
        <section class="ifp-current" style="--ifp-accent:<?php echo esc_attr($accent); ?>">
            <?php if (has_post_thumbnail($p->ID)): ?>
                <div class="ifp-current__image"><?php echo get_the_post_thumbnail($p->ID,'large'); ?></div>
            <?php endif; ?>
            <div class="ifp-current__body">
                <span class="ifp-eyebrow">Current Production</span>
                <h2><?php echo esc_html($p->post_title); ?></h2>
                <?php if ($tagline): ?><p class="ifp-tagline"><?php echo esc_html($tagline); ?></p><?php endif; ?>
                <div class="ifp-copy"><?php echo wpautop(wp_kses_post($p->post_excerpt ?: wp_trim_words($p->post_content,45))); ?></div>
                <?php echo self::countdown(); ?>
                <div class="ifp-actions">
                    <?php if ($ticket): ?><a class="ifp-button" href="<?php echo esc_url($ticket); ?>">Buy Tickets</a><?php elseif ($ticket_state['status'] === 'pending'): ?><span class="ifp-button ifp-button--disabled" aria-disabled="true">Tickets Available <?php echo esc_html($ticket_state['available_label']); ?></span><?php endif; ?>
                    <?php if ($register): ?><a class="ifp-button ifp-button--secondary" href="<?php echo esc_url($register); ?>">Join the Cast</a><?php endif; ?>
                    <?php if ($volunteer): ?><a class="ifp-button ifp-button--secondary" href="<?php echo esc_url($volunteer); ?>">Volunteer</a><?php endif; ?>
                </div>
            </div>
        </section>
        <?php return ob_get_clean();
    }

    public static function registration($atts = []) {
        $atts = shortcode_atts([
            'production_id' => 0,
            'show_heading' => '1',
            'show_status' => '1',
            'open_divisions' => '0',
        ], $atts, 'ifp_registration');

        $production_id = absint($atts['production_id']);
        $production = $production_id ? get_post($production_id) : self::current_post();
        if (!$production || $production->post_type !== 'ifp_production') return '';
        if (get_post_status($production) !== 'publish' && !current_user_can('edit_post', $production->ID)) return '';

        $production_id = $production->ID;
        $show_heading = self::truthy($atts['show_heading']);
        $show_status = self::truthy($atts['show_status']);
        $open_divisions = self::truthy($atts['open_divisions']);
        $state = IFP_Production_Pages::registration_state($production_id);
        $accent = sanitize_hex_color(get_post_meta($production_id, '_ifp_accent_color', true)) ?: '#ff8033';
        $secondary = sanitize_hex_color(get_post_meta($production_id, '_ifp_secondary_color', true)) ?: '#003b5c';
        $style = '--ifp-registration-accent:' . esc_attr($accent) . ';--ifp-registration-secondary:' . esc_attr($secondary);

        if ($state['status'] !== 'open') {
            if (!$show_status) return '';
            if ($state['status'] === 'pending') {
                $message = $state['open_label']
                    ? 'Registration opens ' . $state['open_label'] . '.'
                    : 'Registration will open soon.';
            } elseif ($state['status'] === 'closed') {
                $message = $state['close_label']
                    ? 'Registration closed ' . $state['close_label'] . '.'
                    : 'Registration is closed.';
            } else {
                $message = 'Registration is not currently available.';
            }
            return '<div class="ifp-registration-catalog" style="' . esc_attr($style) . '"><div class="ifp-registration__notice">' . esc_html($message) . '</div></div>';
        }

        $production_url = esc_url_raw((string) get_post_meta($production_id, '_ifp_registration_url', true));
        $divisions = get_posts([
            'post_type' => 'ifp_division',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => ['menu_order' => 'ASC', 'title' => 'ASC'],
            'meta_key' => '_ifp_division_production_id',
            'meta_value' => $production_id,
        ]);
        $groups = get_posts([
            'post_type' => 'ifp_group',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => ['menu_order' => 'ASC', 'title' => 'ASC'],
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => '_ifp_group_production_id',
                    'value' => $production_id,
                    'type' => 'NUMERIC',
                ],
                [
                    'key' => '_ifp_group_visibility',
                    'value' => 'public',
                ],
            ],
        ]);

        $groups_by_division = [];
        foreach ($groups as $group) {
            $division_id = absint(get_post_meta($group->ID, '_ifp_group_division_id', true));
            if (!isset($groups_by_division[$division_id])) $groups_by_division[$division_id] = [];
            $groups_by_division[$division_id][] = $group;
        }

        $division_rows = [];
        $known_division_ids = [];
        foreach ($divisions as $division) {
            $known_division_ids[] = $division->ID;
            $division_url = esc_url_raw((string) get_post_meta($division->ID, '_ifp_registration_url', true));
            $division_groups = $groups_by_division[$division->ID] ?? [];
            $actionable_groups = array_values(array_filter($division_groups, static function($group) {
                return (string) get_post_meta($group->ID, '_ifp_registration_url', true) !== '';
            }));
            $visible_groups = $division_url ? $division_groups : $actionable_groups;
            if (!$division_url && !$visible_groups) continue;
            $division_rows[] = [
                'post' => $division,
                'url' => $division_url,
                'groups' => $visible_groups,
            ];
        }

        $unassigned_groups = array_values(array_filter($groups, static function($group) use ($known_division_ids) {
            $division_id = absint(get_post_meta($group->ID, '_ifp_group_division_id', true));
            $url = (string) get_post_meta($group->ID, '_ifp_registration_url', true);
            return !in_array($division_id, $known_division_ids, true) && $url !== '';
        }));
        $has_nested_options = !empty($division_rows) || !empty($unassigned_groups);

        if (!$has_nested_options && (!$show_heading || !$production_url)) return '';

        ob_start();
        ?>
        <section class="ifp-registration-catalog" style="<?php echo esc_attr($style); ?>">
            <?php if ($show_heading): ?>
                <header class="ifp-registration__header<?php echo $production_url ? ' has-action' : ''; ?>">
                    <div>
                        <span class="ifp-registration__eyebrow"><?php echo esc_html($production->post_title); ?></span>
                        <h2>Registration Options</h2>
                        <?php if ($state['open_label'] || $state['close_label']): ?>
                            <p class="ifp-registration__dates">
                                <?php
                                $date_parts = [];
                                if ($state['open_label']) $date_parts[] = 'Opened ' . $state['open_label'];
                                if ($state['close_label']) $date_parts[] = 'Closes ' . $state['close_label'];
                                echo esc_html(implode(' · ', $date_parts));
                                ?>
                            </p>
                        <?php endif; ?>
                    </div>
                    <?php if ($production_url): ?>
                        <a class="ifp-registration__button" href="<?php echo esc_url($production_url); ?>" target="_blank" rel="noopener noreferrer">Register</a>
                    <?php endif; ?>
                </header>
            <?php endif; ?>

            <?php if ($has_nested_options): ?>
                <div class="ifp-registration__divisions">
                    <?php foreach ($division_rows as $row):
                        $division = $row['post'];
                        $description = trim((string) $division->post_content);
                        $has_panel = $description !== '' || !empty($row['groups']);
                        ?>
                        <div class="ifp-registration__division-wrap<?php echo $row['url'] ? ' has-action' : ''; ?>">
                            <?php if ($has_panel): ?>
                                <details class="ifp-registration__division" <?php echo $open_divisions ? 'open' : ''; ?>>
                                    <summary class="ifp-registration__division-summary">
                                        <h3><?php echo esc_html($division->post_title); ?></h3>
                                        <span class="ifp-registration__toggle" aria-hidden="true"></span>
                                    </summary>
                                    <div class="ifp-registration__division-panel">
                                        <?php if ($description): ?><div class="ifp-registration__description"><?php echo wp_kses_post(wpautop($description)); ?></div><?php endif; ?>
                                        <?php self::registration_group_rows($row['groups']); ?>
                                    </div>
                                </details>
                            <?php else: ?>
                                <div class="ifp-registration__division ifp-registration__division--static">
                                    <div class="ifp-registration__division-summary"><h3><?php echo esc_html($division->post_title); ?></h3></div>
                                </div>
                            <?php endif; ?>
                            <?php if ($row['url']): ?>
                                <a class="ifp-registration__button ifp-registration__division-button" href="<?php echo esc_url($row['url']); ?>" target="_blank" rel="noopener noreferrer" aria-label="Register for <?php echo esc_attr($division->post_title); ?>">Register</a>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($unassigned_groups): ?>
                        <div class="ifp-registration__groups ifp-registration__groups--unassigned">
                            <?php self::registration_group_rows($unassigned_groups, false); ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php
        return ob_get_clean();
    }

    private static function registration_group_rows($groups, $include_wrapper = true) {
        if (!$groups) return;
        if ($include_wrapper) echo '<div class="ifp-registration__groups">';
        foreach ($groups as $group) {
            $url = esc_url_raw((string) get_post_meta($group->ID, '_ifp_registration_url', true));
            $type = trim((string) get_post_meta($group->ID, '_ifp_group_type', true));
            echo '<article class="ifp-registration__group">';
            echo '<div class="ifp-registration__group-copy"><h4>' . esc_html($group->post_title) . '</h4>';
            if ($type) echo '<span>' . esc_html($type) . '</span>';
            echo '</div>';
            if ($url) {
                echo '<a class="ifp-registration__button ifp-registration__group-button" href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer" aria-label="Register for ' . esc_attr($group->post_title) . '">Register</a>';
            }
            echo '</article>';
        }
        if ($include_wrapper) echo '</div>';
    }

    private static function truthy($value) {
        return in_array(strtolower(trim((string) $value)), ['1','true','yes','on'], true);
    }

    public static function countdown($atts = []) {
        $atts = shortcode_atts([
            'production_id' => 0,
            'target' => '',
        ], $atts, 'ifp_countdown');

        $production_id = absint($atts['production_id']);
        $p = $production_id ? get_post($production_id) : self::current_post();
        if (!$p || $p->post_type !== 'ifp_production') return '';
        if (get_post_status($p) !== 'publish' && !current_user_can('edit_post', $p->ID)) return '';
        $target = sanitize_text_field((string) $atts['target']);
        if (!$target) {
            $target = get_post_meta($p->ID,'_ifp_opening_date',true);
        }
        if (!$target || !strtotime($target)) return '';
        return '<div class="ifp-countdown" data-opening="'.esc_attr($target).'">
            <div><strong data-days>00</strong><span>Days</span></div>
            <div><strong data-hours>00</strong><span>Hours</span></div>
            <div><strong data-minutes>00</strong><span>Minutes</span></div>
            <div><strong data-seconds>00</strong><span>Seconds</span></div>
        </div>';
    }

    public static function sponsors($atts) {
        $atts = shortcode_atts([
            'level' => '',
            'limit' => 50,
            'show_tagline' => '1',
            'group_by_level' => '0',
            'production_id' => 0
        ], $atts);

        $args = [
            'post_type' => 'ifp_sponsor',
            'post_status' => 'publish',
            'posts_per_page' => (int) $atts['limit'],
            'meta_key' => '_ifp_sponsor_active',
            'meta_value' => '1',
            'orderby' => 'title',
            'order' => 'ASC'
        ];

        $meta_query = ['relation' => 'AND'];
        if ($atts['level']) $meta_query[] = ['key'=>'_ifp_sponsor_level','value'=>sanitize_text_field($atts['level'])];
        if (class_exists('IFP_Relationships')) $meta_query[] = IFP_Relationships::clause(absint($atts['production_id']));
        if (count($meta_query) > 1) $args['meta_query'] = $meta_query;

        $query = new WP_Query($args);
        if (!$query->have_posts()) {
            return '<div class="ifp-empty">No sponsors to display.</div>';
        }

        $groups = [];
        while ($query->have_posts()) {
            $query->the_post();
            $level = get_post_meta(get_the_ID(),'_ifp_sponsor_level',true) ?: 'Community';
            if (!isset($groups[$level])) $groups[$level] = [];
            $groups[$level][] = get_the_ID();
        }
        wp_reset_postdata();

        $level_order = ['Presenting','Gold','Silver','Bronze','Community'];
        $labels = [
            'Presenting' => 'Presenting Sponsor',
            'Gold' => 'Gold Sponsors',
            'Silver' => 'Silver Sponsors',
            'Bronze' => 'Bronze Sponsors',
            'Community' => 'Community Partners'
        ];

        ob_start();

        $render_group = function($ids, $level = '') use ($atts) {
            echo '<div class="ifp-sponsors">';
            foreach ($ids as $sponsor_id) {
                $url = get_post_meta($sponsor_id,'_ifp_sponsor_url',true);
                $tagline = get_post_meta($sponsor_id,'_ifp_sponsor_tagline',true);

                echo '<article class="ifp-sponsor">';
                echo '<div class="ifp-sponsor__logo">';

                if ($url) {
                    echo '<a href="'.esc_url($url).'" target="_blank" rel="noopener" aria-label="'.esc_attr(get_the_title($sponsor_id)).'">';
                }

                if (has_post_thumbnail($sponsor_id)) {
                    echo get_the_post_thumbnail($sponsor_id,'medium');
                } else {
                    echo '<strong>'.esc_html(get_the_title($sponsor_id)).'</strong>';
                }

                if ($url) echo '</a>';
                echo '</div>';

                echo '<div class="ifp-sponsor__copy">';
                echo '<h3 class="ifp-sponsor__name">'.esc_html(get_the_title($sponsor_id)).'</h3>';
                if ($atts['show_tagline'] !== '0' && $tagline) {
                    echo '<p class="ifp-sponsor__tagline">'.esc_html($tagline).'</p>';
                }
                echo '</div>';
                echo '</article>';
            }
            echo '</div>';
        };

        if ($atts['group_by_level'] === '1' && !$atts['level']) {
            echo '<div class="ifp-sponsor-levels">';
            foreach ($level_order as $level) {
                if (empty($groups[$level])) continue;
                echo '<section class="ifp-sponsor-level ifp-sponsor-level--'.esc_attr(sanitize_title($level)).'">';
                echo '<h3 class="ifp-sponsor-level__heading">'.esc_html($labels[$level] ?? $level).'</h3>';
                $render_group($groups[$level], $level);
                echo '</section>';
            }

            foreach ($groups as $level => $ids) {
                if (in_array($level, $level_order, true)) continue;
                echo '<section class="ifp-sponsor-level">';
                echo '<h3 class="ifp-sponsor-level__heading">'.esc_html($level).'</h3>';
                $render_group($ids, $level);
                echo '</section>';
            }
            echo '</div>';
        } else {
            $flat = [];
            foreach ($groups as $ids) $flat = array_merge($flat, $ids);
            $render_group($flat);
        }

        return ob_get_clean();
    }

    public static function notices() {
        $today = current_time('Y-m-d');
        $q = new WP_Query(['post_type'=>'ifp_notice','posts_per_page'=>20,'orderby'=>'date','order'=>'DESC']);
        if (!$q->have_posts()) return '<div class="ifp-empty">No participant notices.</div>';
        ob_start(); echo '<div class="ifp-notices">';
        while ($q->have_posts()) { $q->the_post();
            $expires = get_post_meta(get_the_ID(),'_ifp_notice_expires',true);
            if ($expires && $expires < $today) continue;
            $priority = strtolower(get_post_meta(get_the_ID(),'_ifp_notice_priority',true) ?: 'normal');
            echo '<article class="ifp-notice ifp-notice--'.esc_attr($priority).'"><h3>'.esc_html(get_the_title()).'</h3><div>'.wp_kses_post(wpautop(get_the_content())).'</div></article>';
        }
        wp_reset_postdata(); echo '</div>'; return ob_get_clean();
    }

    public static function resources($atts) {
        $atts = shortcode_atts(['type'=>'', 'production_id'=>0], $atts);

        $meta_query = ['relation' => 'AND'];

        if ($atts['type']) {
            $meta_query[] = [
                'key' => '_ifp_resource_type',
                'value' => sanitize_text_field($atts['type'])
            ];
        }

        if (class_exists('IFP_Relationships')) {
            $meta_query[] = IFP_Relationships::clause(absint($atts['production_id']));
        }

        $args = [
            'post_type' => 'ifp_resource',
            'post_status' => 'publish',
            'posts_per_page' => 50,
            'orderby' => 'title',
            'order' => 'ASC',
        ];

        if (count($meta_query) > 1) {
            $args['meta_query'] = $meta_query;
        }

        $q = new WP_Query($args);
        if (!$q->have_posts()) return '<div class="ifp-empty">No participant resources.</div>';

        ob_start();
        echo '<div class="ifp-resources">';

        while ($q->have_posts()) {
            $q->the_post();
            $id = get_the_ID();

            $source = get_post_meta($id,'_ifp_resource_source',true);
            $page_id = absint(get_post_meta($id,'_ifp_resource_page_id',true));
            $media_id = absint(get_post_meta($id,'_ifp_resource_media_id',true));
            $external_url = get_post_meta($id,'_ifp_resource_url',true);

            if (!$source) {
                if ($media_id) $source = 'media';
                elseif ($page_id) $source = 'page';
                else $source = 'url';
            }

            if ($source === 'media' && $media_id) {
                $url = wp_get_attachment_url($media_id);
            } elseif ($source === 'page' && $page_id) {
                $url = get_permalink($page_id);
            } else {
                $url = $external_url;
            }

            $type = get_post_meta($id,'_ifp_resource_type',true);
            $button_text = get_post_meta($id,'_ifp_resource_button_text',true) ?: 'Open Resource';

            echo '<article class="ifp-resource">';
            echo '<span>'.esc_html($type).'</span>';
            echo '<h3>'.esc_html(get_the_title()).'</h3>';
            echo '<div>'.wp_kses_post(wpautop(get_the_excerpt() ?: get_the_content())).'</div>';

            if ($media_id && $source === 'media') {
                $file_path = get_attached_file($media_id);
                $extension = $file_path ? strtoupper((string) pathinfo($file_path, PATHINFO_EXTENSION)) : 'FILE';
                $size = ($file_path && file_exists($file_path)) ? size_format(filesize($file_path), 1) : '';
                echo '<small class="ifp-resource-file-meta">'.esc_html(trim($extension.($size ? ' • '.$size : ''))).'</small>';
            }

            if ($url) {
                echo '<a class="ifp-button ifp-button--small" href="'.esc_url($url).'">'.esc_html($button_text).'</a>';
            }

            echo '</article>';
        }

        wp_reset_postdata();
        echo '</div>';
        return ob_get_clean();
    }

}
