<?php
if (!defined('ABSPATH')) exit;

class IFPROG_Post_Types {
    public static function init() {
        add_action('init', [__CLASS__, 'register']);
        add_action('admin_init', [__CLASS__, 'maybe_seed_terms']);
    }

    public static function register() {
        register_post_type('ifprog_season', [
            'labels' => [
                'name' => 'Seasons',
                'singular_name' => 'Season',
                'add_new' => 'Add Season',
                'add_new_item' => 'Add New Season',
                'edit_item' => 'Edit Season',
                'new_item' => 'New Season',
                'search_items' => 'Search Seasons',
                'not_found' => 'No seasons found',
                'all_items' => 'Seasons',
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'show_in_rest' => false,
            'supports' => ['title','editor','revisions'],
            'menu_icon' => 'dashicons-calendar-alt',
        ]);

        register_post_type('ifprog_level', [
            'labels' => [
                'name' => 'Levels',
                'singular_name' => 'Level',
                'add_new' => 'Add Level',
                'add_new_item' => 'Add New Level',
                'edit_item' => 'Edit Level',
                'new_item' => 'New Level',
                'search_items' => 'Search Levels',
                'not_found' => 'No levels found',
                'all_items' => 'Levels',
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'show_in_rest' => false,
            'supports' => ['title','editor','page-attributes','revisions'],
            'menu_icon' => 'dashicons-networking',
        ]);

        register_post_type('ifprog_program', [
            'labels' => [
                'name' => 'Programs',
                'singular_name' => 'Program',
                'add_new' => 'Add Program',
                'add_new_item' => 'Add New Program',
                'edit_item' => 'Edit Program',
                'new_item' => 'New Program',
                'search_items' => 'Search Programs',
                'not_found' => 'No programs found',
                'all_items' => 'Programs',
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'show_in_rest' => false,
            'supports' => ['title','editor','excerpt','thumbnail','page-attributes','revisions'],
            'menu_icon' => 'dashicons-welcome-learn-more',
        ]);

        self::register_taxonomy(
            'ifprog_sport',
            'Sports',
            'Sport',
            ['ifprog_season','ifprog_program']
        );
        self::register_taxonomy(
            'ifprog_format',
            'Formats',
            'Format',
            ['ifprog_season','ifprog_program']
        );
        self::register_taxonomy(
            'ifprog_category',
            'Program Categories',
            'Program Category',
            ['ifprog_level','ifprog_program']
        );
        self::register_taxonomy(
            'ifprog_group',
            'Program Groups',
            'Program Group',
            ['ifprog_level'],
            ['meta_box_cb' => false]
        );
    }

    private static function register_taxonomy($taxonomy, $plural, $singular, $object_types, $extra = []) {
        register_taxonomy($taxonomy, $object_types, array_merge([
            'labels' => [
                'name' => $plural,
                'singular_name' => $singular,
                'search_items' => 'Search ' . $plural,
                'all_items' => 'All ' . $plural,
                'parent_item' => 'Parent ' . $singular,
                'parent_item_colon' => 'Parent ' . $singular . ':',
                'edit_item' => 'Edit ' . $singular,
                'update_item' => 'Update ' . $singular,
                'add_new_item' => 'Add New ' . $singular,
                'new_item_name' => 'New ' . $singular . ' Name',
                'not_found' => 'No ' . $plural . ' found',
                'back_to_items' => 'Go to ' . $plural,
                'menu_name' => $plural,
            ],
            'public' => false,
            'show_ui' => true,
            'show_admin_column' => false,
            'show_in_rest' => false,
            'hierarchical' => true,
            'rewrite' => false,
        ], $extra));
    }

    public static function seed_terms() {
        self::ensure_term('ifprog_sport', 'Figure Skating', 'figure-skating');
        self::ensure_term('ifprog_sport', 'Hockey', 'hockey');

        self::ensure_term('ifprog_format', 'Class', 'class');
        self::ensure_term('ifprog_format', 'League', 'league');
        self::ensure_term('ifprog_format', 'Camp', 'camp');
        self::ensure_term('ifprog_format', 'Clinic', 'clinic');
        self::ensure_term('ifprog_format', 'Drop-In', 'drop-in');

        self::ensure_term('ifprog_category', 'Learn to Skate', 'learn-to-skate');
        self::ensure_term('ifprog_category', 'Learn to Play', 'learn-to-play');
        self::ensure_term('ifprog_category', 'Specialty Classes', 'specialty-classes');
        self::ensure_term('ifprog_category', 'Camps & Clinics', 'camps-clinics');
        self::ensure_term('ifprog_category', 'Homeschool', 'homeschool');
        self::ensure_term('ifprog_category', 'Adaptive', 'adaptive');

        self::ensure_group('Basic Skills', 'basic-skills', 10);
        self::ensure_group('Adult', 'adult', 20);
        self::ensure_group('Free Skate', 'free-skate', 30);
        self::ensure_group('Snowplow Sam', 'snowplow-sam', 40);
        self::ensure_group('Hockey', 'hockey', 50);
    }

    public static function maybe_seed_terms() {
        $seeded_version = (string) get_option('ifprog_seeded_terms_version', '');
        if ($seeded_version !== '' && version_compare($seeded_version, IFPROG_VERSION, '>=')) return;
        self::seed_terms();
        if (!get_option('ifprog_special_categories_backfilled', false)) {
            self::backfill_special_categories();
            update_option('ifprog_special_categories_backfilled', 1, false);
        }
        update_option('ifprog_seeded_terms_version', IFPROG_VERSION, false);
    }

    /**
     * Add website presentation categories inferred from stable naming rules.
     * Existing categories are retained.
     */
    public static function assign_special_categories($program_id) {
        $program_id = absint($program_id);
        if (!$program_id || get_post_type($program_id) !== 'ifprog_program') return;

        $title = trim((string) get_the_title($program_id));
        $level_id = absint(IFPROG_Fields::get($program_id, 'level_id'));
        $level_title = $level_id ? trim((string) get_the_title($level_id)) : '';
        if ($level_title === '') {
            $level_title = trim((string) IFPROG_Fields::get($program_id, 'level'));
        }
        $payload = get_post_meta($program_id, '_ifprog_dash_payload', true);
        $source_title = is_array($payload)
            ? trim((string) ($payload['team']['name'] ?? ''))
            : '';
        $source_level = is_array($payload)
            ? trim((string) ($payload['league']['name'] ?? ''))
            : '';

        $program_slugs = [];
        if (preg_match('/^HS\b/i', $title) || preg_match('/^HS\b/i', $source_title)) {
            $program_slugs[] = 'homeschool';
        }
        $adaptive_program = preg_match('/^Adaptive\b/i', $title) ||
            preg_match('/^Adaptive\b/i', $source_title);
        $adaptive_level = preg_match('/^Adaptive\b/i', $level_title) ||
            preg_match('/^Adaptive\b/i', $source_level);
        if ($adaptive_level && $level_id) {
            self::add_categories($level_id, ['adaptive']);
        } elseif ($adaptive_program || $adaptive_level) {
            $program_slugs[] = 'adaptive';
        }
        self::add_categories($program_id, $program_slugs);
    }

    private static function add_categories($object_id, $slugs) {
        $slugs = array_values(array_unique(array_filter(array_map('sanitize_title', (array) $slugs))));
        if (!$slugs) return;

        $special_ids = self::assignment_term_ids('ifprog_category', $slugs);
        if (!$special_ids) return;

        $existing_ids = wp_get_object_terms($object_id, 'ifprog_category', ['fields' => 'ids']);
        if (is_wp_error($existing_ids)) $existing_ids = [];
        $term_ids = array_values(array_unique(array_merge(
            array_map('absint', (array) $existing_ids),
            $special_ids
        )));
        wp_set_object_terms($object_id, $term_ids, 'ifprog_category', false);
    }

    private static function backfill_special_categories() {
        $program_ids = get_posts([
            'post_type' => 'ifprog_program',
            'post_status' => array_keys(get_post_stati()),
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
        ]);
        foreach ($program_ids as $program_id) {
            self::assign_special_categories($program_id);
        }
    }

    private static function ensure_group($name, $slug, $order) {
        $term_id = self::ensure_term('ifprog_group', $name, $slug);
        if ($term_id && get_term_meta($term_id, '_ifprog_group_order', true) === '') {
            update_term_meta($term_id, '_ifprog_group_order', absint($order));
        }
    }

    private static function ensure_term($taxonomy, $name, $slug) {
        $term = term_exists($slug, $taxonomy);
        if (!$term) {
            $matches = self::matching_term_ids($taxonomy, [$slug]);
            if ($matches) return absint($matches[0]);
        }
        if (!$term) $term = wp_insert_term($name, $taxonomy, ['slug' => $slug]);
        if (is_wp_error($term)) return 0;
        return is_array($term) ? absint($term['term_id']) : absint($term);
    }

    /**
     * Return every existing term whose slug or normalized display name matches
     * a public filter slug. This preserves compatibility with older Dash terms
     * such as "Hockey" whose stored slug may be "h".
     */
    public static function matching_term_ids($taxonomy, $slugs) {
        $slugs = array_values(array_unique(array_filter(array_map('sanitize_title', (array) $slugs))));
        if (!$slugs || !taxonomy_exists($taxonomy)) return [];

        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
        ]);
        if (is_wp_error($terms)) return [];

        $ids = [];
        foreach ($terms as $term) {
            if (
                in_array(sanitize_title($term->slug), $slugs, true) ||
                in_array(sanitize_title($term->name), $slugs, true)
            ) {
                $ids[] = absint($term->term_id);
            }
        }
        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * Resolve one real term ID for each imported classification slug. Exact
     * slugs win, then normalized term names, and a new term is created only
     * when neither representation already exists.
     */
    public static function assignment_term_ids($taxonomy, $slugs) {
        $slugs = array_values(array_unique(array_filter(array_map('sanitize_title', (array) $slugs))));
        if (!$slugs || !taxonomy_exists($taxonomy)) return [];

        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
        ]);
        if (is_wp_error($terms)) $terms = [];

        $ids = [];
        foreach ($slugs as $slug) {
            $matched_id = 0;
            foreach ($terms as $term) {
                if (sanitize_title($term->slug) === $slug) {
                    $matched_id = absint($term->term_id);
                    break;
                }
            }
            if (!$matched_id) {
                foreach ($terms as $term) {
                    if (sanitize_title($term->name) === $slug) {
                        $matched_id = absint($term->term_id);
                        break;
                    }
                }
            }
            if (!$matched_id) {
                $created = wp_insert_term(
                    ucwords(str_replace('-', ' ', $slug)),
                    $taxonomy,
                    ['slug' => $slug]
                );
                if (!is_wp_error($created)) {
                    $matched_id = is_array($created)
                        ? absint($created['term_id'] ?? 0)
                        : absint($created);
                } elseif ($created->get_error_code() === 'term_exists') {
                    $matched_id = absint($created->get_error_data());
                }
            }
            if ($matched_id) $ids[] = $matched_id;
        }
        return array_values(array_unique(array_filter($ids)));
    }
}
