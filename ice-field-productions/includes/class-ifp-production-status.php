<?php
if (!defined('ABSPATH')) exit;

/**
 * Central source of truth for the Production lifecycle.
 */
class IFP_Production_Status {
    const META_KEY = '_ifp_production_status';
    const LEGACY_CURRENT_KEY = '_ifp_is_current';
    const DATA_VERSION_OPTION = 'ifp_data_version';
    const DATA_VERSION = '3.0.2';
    const CURRENT_START_KEY = '_ifp_current_start_date';
    const CRON_HOOK = 'ifp_lifecycle_transition_check';
    const LAST_CHECK_OPTION = 'ifp_lifecycle_last_check';

    public static function init() {
        add_action('init', [__CLASS__, 'maybe_upgrade'], 40);
        add_action('pre_get_posts', [__CLASS__, 'hide_lifecycle_drafts_from_archives']);
        add_action('template_redirect', [__CLASS__, 'hide_lifecycle_draft_singulars']);
        add_filter('rest_ifp_production_query', [__CLASS__, 'hide_lifecycle_drafts_from_rest'], 10, 2);
        add_filter('rest_pre_dispatch', [__CLASS__, 'hide_lifecycle_draft_rest_singulars'], 20, 3);
        add_action('init', [__CLASS__, 'maybe_schedule'], 45);
        add_action('init', [__CLASS__, 'maybe_reconcile'], 60);
        add_action(self::CRON_HOOK, [__CLASS__, 'reconcile']);
    }

    public static function values() {
        return ['draft','upcoming','current','completed','archived'];
    }

    public static function get($post_id) {
        $status = sanitize_key((string) get_post_meta($post_id, self::META_KEY, true));
        if (in_array($status, self::values(), true)) return $status;

        if (get_post_meta($post_id, self::LEGACY_CURRENT_KEY, true) === '1') {
            return 'current';
        }

        return self::inferred_noncurrent_status($post_id);
    }

    public static function current_id($published_only = true) {
        $post_status = $published_only
            ? 'publish'
            : ['publish','draft','pending','future','private'];

        $ids = get_posts([
            'post_type' => 'ifp_production',
            'post_status' => $post_status,
            'posts_per_page' => 1,
            'fields' => 'ids',
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_key' => self::META_KEY,
            'meta_value' => 'current',
        ]);

        if (!$ids) {
            $ids = get_posts([
                'post_type' => 'ifp_production',
                'post_status' => $post_status,
                'posts_per_page' => 1,
                'fields' => 'ids',
                'orderby' => 'date',
                'order' => 'DESC',
                'meta_key' => self::LEGACY_CURRENT_KEY,
                'meta_value' => '1',
            ]);
        }

        return $ids ? absint($ids[0]) : 0;
    }

    public static function current($published_only = true) {
        $id = self::current_id($published_only);
        return $id ? get_post($id) : null;
    }

    /**
     * Save a lifecycle status and keep the legacy current flag synchronized.
     *
     * @param int    $post_id Production ID.
     * @param string $status Lifecycle status.
     * @param string $previous_current_status Status assigned to the former current production.
     */
    public static function set($post_id, $status, $previous_current_status = 'upcoming') {
        $post_id = absint($post_id);
        $status = sanitize_key($status);
        if (!$post_id || !in_array($status, self::values(), true)) return false;

        if ($status === 'current') {
            $replacement = in_array($previous_current_status, self::values(), true)
                && $previous_current_status !== 'current'
                ? $previous_current_status
                : 'upcoming';

            $others = get_posts([
                'post_type' => 'ifp_production',
                'post_status' => 'any',
                'posts_per_page' => -1,
                'exclude' => [$post_id],
                'fields' => 'ids',
                'meta_query' => [
                    'relation' => 'OR',
                    ['key' => self::META_KEY, 'value' => 'current'],
                    ['key' => self::LEGACY_CURRENT_KEY, 'value' => '1'],
                ],
            ]);

            foreach ($others as $other_id) {
                update_post_meta($other_id, self::META_KEY, $replacement);
                update_post_meta($other_id, self::LEGACY_CURRENT_KEY, '0');
            }
        }

        update_post_meta($post_id, self::META_KEY, $status);
        update_post_meta($post_id, self::LEGACY_CURRENT_KEY, $status === 'current' ? '1' : '0');
        return true;
    }

    public static function maybe_schedule() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, 'hourly', self::CRON_HOOK);
        }
    }

    public static function unschedule() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        delete_option(self::LAST_CHECK_OPTION);
    }

    public static function maybe_reconcile() {
        self::reconcile(false);
    }

    /**
     * Apply date-driven lifecycle transitions in the site's local timezone.
     * WordPress Cron provides the regular check; the throttled init check makes
     * the transition happen on the first normal request even when Cron is late.
     */
    public static function reconcile($force = false) {
        $last_check = absint(get_option(self::LAST_CHECK_OPTION, 0));
        if (!$force && $last_check && (time() - $last_check) < (5 * MINUTE_IN_SECONDS)) {
            return ['completed' => 0, 'current' => 0];
        }

        update_option(self::LAST_CHECK_OPTION, time(), false);
        $now = current_datetime()->getTimestamp();
        $completed = 0;
        $promoted = 0;

        $current_ids = get_posts([
            'post_type' => 'ifp_production',
            'post_status' => 'any',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_key' => self::META_KEY,
            'meta_value' => 'current',
        ]);

        foreach ($current_ids as $post_id) {
            $closing = get_post_meta($post_id, '_ifp_closing_date', true);
            $closing_timestamp = self::local_timestamp($closing, true);
            if ($closing_timestamp && $closing_timestamp <= $now) {
                self::set($post_id, 'completed');
                $completed++;
            }
        }

        $scheduled = get_posts([
            'post_type' => 'ifp_production',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_key' => self::CURRENT_START_KEY,
            'orderby' => 'meta_value',
            'order' => 'ASC',
        ]);

        foreach ($scheduled as $post_id) {
            if (self::get($post_id) !== 'upcoming') continue;

            $start = get_post_meta($post_id, self::CURRENT_START_KEY, true);
            $start_timestamp = self::local_timestamp($start, false);
            if (!$start_timestamp || $start_timestamp > $now) continue;

            $closing = get_post_meta($post_id, '_ifp_closing_date', true);
            $closing_timestamp = self::local_timestamp($closing, true);
            if ($closing_timestamp && $closing_timestamp <= $now) {
                self::set($post_id, 'completed');
                $completed++;
                continue;
            }

            self::set($post_id, 'current', 'completed');
            $promoted++;
        }

        return ['completed' => $completed, 'current' => $promoted];
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

    public static function maybe_upgrade() {
        $installed = (string) get_option(self::DATA_VERSION_OPTION, '0');
        if (version_compare($installed, self::DATA_VERSION, '>=')) return;

        if (class_exists('IFP_Participants')) {
            IFP_Participants::grant_capabilities();
        }

        $ids = get_posts([
            'post_type' => 'ifp_production',
            'post_status' => 'any',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        // Preserve 3.0.1's lookup order: a lifecycle Current record wins; when
        // none exists, fall back to the newest record carrying the legacy flag.
        $preferred_current_id = 0;
        foreach ($ids as $id) {
            if (get_post_meta($id, self::META_KEY, true) === 'current') {
                $preferred_current_id = absint($id);
                break;
            }
        }
        if (!$preferred_current_id) {
            foreach ($ids as $id) {
                if (get_post_meta($id, self::LEGACY_CURRENT_KEY, true) === '1') {
                    $preferred_current_id = absint($id);
                    break;
                }
            }
        }

        foreach ($ids as $id) {
            $saved = sanitize_key((string) get_post_meta($id, self::META_KEY, true));

            if (absint($id) === $preferred_current_id) {
                $saved = 'current';
            } elseif ($saved === 'current' || !in_array($saved, self::values(), true)) {
                $saved = self::inferred_noncurrent_status($id);
            }

            update_post_meta($id, self::META_KEY, $saved);
            update_post_meta($id, self::LEGACY_CURRENT_KEY, $saved === 'current' ? '1' : '0');
        }

        update_option(self::DATA_VERSION_OPTION, self::DATA_VERSION, false);
    }

    public static function hide_lifecycle_drafts_from_archives($query) {
        if (is_admin() || !$query->is_main_query()) return;

        $post_type = $query->get('post_type');
        $includes_productions = $query->is_post_type_archive('ifp_production')
            || $query->is_search()
            || $post_type === 'ifp_production'
            || (is_array($post_type) && in_array('ifp_production', $post_type, true));
        if (!$includes_productions) return;

        $existing_meta_query = (array) $query->get('meta_query');
        $draft_guard = [
            'relation' => 'OR',
            ['key' => self::META_KEY, 'compare' => 'NOT EXISTS'],
            ['key' => self::META_KEY, 'value' => 'draft', 'compare' => '!='],
        ];
        $query->set('meta_query', $existing_meta_query
            ? ['relation' => 'AND', $existing_meta_query, $draft_guard]
            : [$draft_guard]
        );
    }

    public static function hide_lifecycle_drafts_from_rest($args, $request) {
        if (current_user_can('edit_posts')) return $args;

        $existing_meta_query = isset($args['meta_query']) ? (array) $args['meta_query'] : [];
        $draft_guard = [
            'relation' => 'OR',
            ['key' => self::META_KEY, 'compare' => 'NOT EXISTS'],
            ['key' => self::META_KEY, 'value' => 'draft', 'compare' => '!='],
        ];
        $args['meta_query'] = $existing_meta_query
            ? ['relation' => 'AND', $existing_meta_query, $draft_guard]
            : [$draft_guard];
        return $args;
    }

    public static function hide_lifecycle_draft_rest_singulars($result, $server, $request) {
        if ($result !== null) return $result;

        if (!preg_match('#^/wp/v2/ifp_production/(\d+)(?:/|$)#', (string) $request->get_route(), $matches)) {
            return $result;
        }

        $post_id = absint($matches[1]);
        if (self::get($post_id) !== 'draft' || current_user_can('edit_post', $post_id)) return $result;

        return new WP_Error('rest_post_invalid_id', 'Invalid post ID.', ['status' => 404]);
    }

    public static function hide_lifecycle_draft_singulars() {
        if (!is_singular('ifp_production')) return;

        $post_id = get_queried_object_id();
        if (self::get($post_id) !== 'draft' || current_user_can('edit_post', $post_id)) return;

        global $wp_query;
        $wp_query->set_404();
        status_header(404);
        nocache_headers();
        $template = get_404_template();
        if ($template) include $template;
        exit;
    }

    private static function inferred_noncurrent_status($post_id) {
        $closing = (string) get_post_meta($post_id, '_ifp_closing_date', true);
        $timestamp = $closing ? strtotime($closing) : 0;
        return ($timestamp && $timestamp < current_time('timestamp')) ? 'completed' : 'upcoming';
    }
}
