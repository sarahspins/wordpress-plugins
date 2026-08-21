<?php
if (!defined('ABSPATH')) exit;

class IFP_Page_Layout {
    const META_KEY = '_ifp_page_layout';

    public static function init() {
        add_action('add_meta_boxes_page', [__CLASS__, 'add_meta_box']);
        add_action('save_post_page', [__CLASS__, 'save']);
        add_filter('body_class', [__CLASS__, 'body_classes']);
    }

    public static function shortcodes() {
        return [
            'ifp_homepage',
            'ifp_participant_hub',
            'ifp_current_production',
            'ifp_countdown',
            'ifp_sponsors',
            'ifp_participant_notices',
            'ifp_participant_resources',
        ];
    }

    public static function add_meta_box() {
        add_meta_box(
            'ifp_page_layout',
            'Ice & Field Productions Layout',
            [__CLASS__, 'render_meta_box'],
            'page',
            'side',
            'default'
        );
    }

    public static function render_meta_box($post) {
        wp_nonce_field('ifp_save_page_layout', 'ifp_page_layout_nonce');
        $saved = get_post_meta($post->ID, self::META_KEY, true) ?: 'auto';
        ?>
        <p><label><strong>Page Layout</strong><br>
            <select class="widefat" name="ifp_page_layout">
                <option value="auto" <?php selected($saved, 'auto'); ?>>Automatic</option>
                <option value="productions" <?php selected($saved, 'productions'); ?>>Productions Layout</option>
                <option value="standard" <?php selected($saved, 'standard'); ?>>Standard Page Layout</option>
            </select>
        </label></p>
        <p class="description">
            Automatic uses the Productions Layout when this page contains an Ice & Field Productions shortcode.
        </p>
        <?php
    }

    public static function save($post_id) {
        if (!isset($_POST['ifp_page_layout_nonce'])) return;
        if (!wp_verify_nonce(
            sanitize_text_field(wp_unslash($_POST['ifp_page_layout_nonce'])),
            'ifp_save_page_layout'
        )) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $layout = sanitize_text_field(wp_unslash($_POST['ifp_page_layout'] ?? 'auto'));
        if (!in_array($layout, ['auto','productions','standard'], true)) {
            $layout = 'auto';
        }

        update_post_meta($post_id, self::META_KEY, $layout);
    }

    public static function contains_plugin_shortcode($post) {
        if (!$post instanceof WP_Post) return false;

        foreach (self::shortcodes() as $shortcode) {
            if (has_shortcode($post->post_content, $shortcode)) {
                return true;
            }
        }

        return false;
    }

    public static function resolved_layout($post) {
        if (!$post instanceof WP_Post) return 'standard';

        $saved = get_post_meta($post->ID, self::META_KEY, true) ?: 'auto';
        if ($saved === 'productions' || $saved === 'standard') {
            return $saved;
        }

        return self::contains_plugin_shortcode($post) ? 'productions' : 'standard';
    }

    public static function body_classes($classes) {
        if (!is_singular('page')) return $classes;

        $post = get_queried_object();
        if (!$post instanceof WP_Post) return $classes;

        $layout = self::resolved_layout($post);
        $classes[] = $layout === 'productions'
            ? 'ifp-production-page'
            : 'ifp-standard-page';

        if (self::contains_plugin_shortcode($post)) {
            $classes[] = 'ifp-has-production-shortcode';
        }

        return array_values(array_unique($classes));
    }
}
