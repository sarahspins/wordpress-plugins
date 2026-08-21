<?php
if (!defined('ABSPATH')) exit;

class IFP_Production_Pages {
    public static function init() {
        add_action('init', [__CLASS__, 'register_rewrite_rule'], 20);
        add_action('parse_request', [__CLASS__, 'route_direct_production'], 1);
        add_filter('single_template', [__CLASS__, 'single_template'], 99);
        add_filter('template_include', [__CLASS__, 'single_template'], 99);
        add_filter('body_class', [__CLASS__, 'body_classes']);
        add_action('admin_bar_menu', [__CLASS__, 'toolbar_edit_link'], 80);
    }

    public static function register_rewrite_rule() {
        add_rewrite_rule(
            '^productions/([^/]+)/?$',
            'index.php?post_type=ifp_production&name=$matches[1]',
            'top'
        );
    }

    /**
     * Route a direct Production permalink even when a stale or filtered
     * rewrite_rules option omits the custom post type's normal rule.
     */
    public static function route_direct_production($wp) {
        if (!is_object($wp) || empty($wp->request)) return;

        $request = trim((string) $wp->request, '/');
        if (!preg_match('#^productions/([^/]+)$#', $request, $matches)) return;

        $slug = sanitize_title(rawurldecode($matches[1]));
        if (!$slug) return;

        $production = get_page_by_path($slug, OBJECT, 'ifp_production');
        if (!$production instanceof WP_Post) return;

        unset(
            $wp->query_vars['error'],
            $wp->query_vars['page'],
            $wp->query_vars['pagename'],
            $wp->query_vars['attachment'],
            $wp->query_vars['attachment_id']
        );

        $wp->query_vars['post_type'] = 'ifp_production';
        $wp->query_vars['name'] = $production->post_name;
        $wp->matched_rule = '^productions/([^/]+)/?$';
        $wp->matched_query = 'post_type=ifp_production&name=' . rawurlencode($production->post_name);
    }

    public static function single_template($template) {
        if (!is_singular('ifp_production')) return $template;

        $plugin_template = IFP_DIR . 'templates/single-ifp_production.php';
        return file_exists($plugin_template) ? $plugin_template : $template;
    }

    public static function body_classes($classes) {
        if (is_singular('ifp_production')) {
            $classes[] = 'ifp-single-production';
            $classes[] = 'ifp-production-page';
        }
        return array_values(array_unique($classes));
    }

    public static function toolbar_edit_link($wp_admin_bar) {
        if (is_admin() || !is_singular('ifp_production')) return;

        $production_id = get_queried_object_id();
        if (!$production_id || !current_user_can('edit_post', $production_id)) return;

        $edit_url = get_edit_post_link($production_id, 'raw');
        if (!$edit_url) return;

        $wp_admin_bar->add_node([
            'id' => 'ifp-edit-production',
            'title' => 'Edit Production',
            'href' => $edit_url,
            'meta' => [
                'title' => 'Edit ' . get_the_title($production_id),
            ],
        ]);
    }

    public static function meta($post_id, $key, $default = '') {
        $value = get_post_meta($post_id, $key, true);
        return $value === '' ? $default : $value;
    }

    public static function link($post_id, $production_key, $site_key = '') {
        $value = self::meta($post_id, $production_key);
        if ($value) return $value;

        return ($site_key && class_exists('IFP_Links'))
            ? IFP_Links::get($site_key)
            : '';
    }

    /**
     * Resolve the public ticket-link state without changing the saved URL.
     * A blank availability date preserves the legacy immediately-active behavior.
     */
    public static function ticket_state($production_id, $allow_site_fallback = false) {
        $production_id = absint($production_id);
        $url = $production_id ? self::meta($production_id, '_ifp_ticket_url') : '';
        if (!$url && $allow_site_fallback && class_exists('IFP_Links')) {
            $url = IFP_Links::get('tickets');
        }

        $state = [
            'status' => $url ? 'active' : 'unconfigured',
            'url' => $url,
            'available_date' => '',
            'available_label' => '',
        ];
        if (!$production_id || !$url) return $state;

        $available_date = trim((string) self::meta($production_id, '_ifp_ticket_available_date'));
        $closing = trim((string) self::meta($production_id, '_ifp_closing_date'));
        $now = current_datetime()->getTimestamp();
        $closing_timestamp = self::local_timestamp($closing, true);
        $available_timestamp = self::local_timestamp($available_date, false);

        $state['available_date'] = $available_date;
        if ($available_timestamp) {
            $state['available_label'] = wp_date(get_option('date_format'), $available_timestamp);
        }

        if ($closing_timestamp && $closing_timestamp <= $now) {
            $state['status'] = 'closed';
            $state['url'] = '';
        } elseif ($available_timestamp && $available_timestamp > $now) {
            $state['status'] = 'pending';
            $state['url'] = '';
        }

        return $state;
    }

    public static function active_ticket_url($production_id, $allow_site_fallback = false) {
        $state = self::ticket_state($production_id, $allow_site_fallback);
        return $state['status'] === 'active' ? $state['url'] : '';
    }

    /**
     * Resolve the Production registration window in the site's local timezone.
     * Blank boundaries remain open-ended so older Productions keep working.
     */
    public static function registration_state($production_id) {
        $production_id = absint($production_id);
        $open = $production_id ? trim((string) self::meta($production_id, '_ifp_registration_open')) : '';
        $close = $production_id ? trim((string) self::meta($production_id, '_ifp_registration_close')) : '';
        $open_timestamp = self::local_timestamp($open, false);
        $close_timestamp = self::local_timestamp($close, true);
        $now = current_datetime()->getTimestamp();
        $lifecycle = $production_id && class_exists('IFP_Production_Status')
            ? IFP_Production_Status::get($production_id)
            : '';

        $state = [
            'status' => 'open',
            'open' => $open,
            'close' => $close,
            'open_label' => $open_timestamp ? wp_date(get_option('date_format'), $open_timestamp) : '',
            'close_label' => $close_timestamp ? wp_date(get_option('date_format'), $close_timestamp) : '',
        ];

        if (!$production_id) {
            $state['status'] = 'unavailable';
        } elseif (in_array($lifecycle, ['completed','archived'], true)) {
            $state['status'] = 'closed';
        } elseif ($open_timestamp && $open_timestamp > $now) {
            $state['status'] = 'pending';
        } elseif ($close_timestamp && $close_timestamp <= $now) {
            $state['status'] = 'closed';
        }

        return $state;
    }

    public static function registration_is_open($production_id) {
        $state = self::registration_state($production_id);
        return $state['status'] === 'open';
    }

    private static function local_timestamp($value, $date_only_at_end_of_day = false) {
        $value = trim((string) $value);
        if ($value === '') return 0;

        if ($date_only_at_end_of_day && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $value .= ' 23:59:59';
        }

        try {
            $date = new DateTimeImmutable($value, wp_timezone());
        } catch (Exception $exception) {
            return 0;
        }

        return $date->getTimestamp();
    }

    public static function event_query($production_id) {
        return new WP_Query([
            'post_type' => 'ifp_event',
            'post_status' => 'publish',
            'posts_per_page' => 50,
            'meta_key' => '_ifp_event_date',
            'orderby' => 'meta_value',
            'order' => 'ASC',
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => '_ifp_event_featured',
                    'value' => '1',
                ],
                class_exists('IFP_Relationships')
                    ? IFP_Relationships::clause($production_id)
                    : [
                        'key' => '_ifp_production_scope',
                        'compare' => 'NOT EXISTS',
                    ],
            ],
        ]);
    }

    public static function has_sponsors($production_id) {
        $args = [
            'post_type' => 'ifp_sponsor',
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => '_ifp_sponsor_active',
                    'value' => '1',
                ],
            ],
        ];

        if (class_exists('IFP_Relationships')) {
            $args['meta_query'][] = IFP_Relationships::clause($production_id);
        }

        return (bool) get_posts($args);
    }

    public static function has_public_groups($production_id) {
        return (bool) get_posts([
            'post_type' => 'ifp_group',
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => '_ifp_group_production_id',
                    'value' => absint($production_id),
                    'type' => 'NUMERIC',
                ],
                [
                    'key' => '_ifp_group_visibility',
                    'value' => 'public',
                ],
            ],
        ]);
    }

    public static function formatted_range($post_id) {
        $opening = self::meta($post_id, '_ifp_opening_date');
        $closing = self::meta($post_id, '_ifp_closing_date');

        if (!$opening && !$closing) return '';

        $date_format = get_option('date_format');
        $time_format = get_option('time_format');

        if ($opening && $closing) {
            $open_ts = strtotime($opening);
            $close_ts = strtotime($closing);

            if ($open_ts && $close_ts) {
                $same_day = wp_date('Y-m-d', $open_ts) === wp_date('Y-m-d', $close_ts);

                if ($same_day) {
                    return wp_date($date_format, $open_ts)
                        . ' • '
                        . wp_date($time_format, $open_ts)
                        . '–'
                        . wp_date($time_format, $close_ts);
                }

                return wp_date($date_format, $open_ts)
                    . ' – '
                    . wp_date($date_format, $close_ts);
            }
        }

        $value = $opening ?: $closing;
        $ts = strtotime($value);
        return $ts ? wp_date($date_format . ' • ' . $time_format, $ts) : '';
    }

    public static function event_date_range($event_id, $format = '') {
        $start = (string) get_post_meta($event_id, '_ifp_event_date', true);
        $end = (string) get_post_meta($event_id, '_ifp_event_end_date', true);
        if ($start === '') return '';

        $start_timestamp = strtotime($start);
        if (!$start_timestamp) return '';

        $format = $format ?: get_option('date_format');
        $text = wp_date($format, $start_timestamp);
        if ($end === '' || $end === $start) return $text;

        $end_timestamp = strtotime($end);
        if (!$end_timestamp) return $text;

        return $text . ' – ' . wp_date($format, $end_timestamp);
    }
}
