<?php
if (!defined('ABSPATH')) exit;

class IFP_Relationships {
    const META_KEY = '_ifp_production_scope';

    public static function init() {
        foreach (['ifp_notice','ifp_resource','ifp_event','ifp_contact','ifp_sponsor'] as $type) {
            add_filter("manage_{$type}_posts_columns", [__CLASS__, 'columns']);
            add_action("manage_{$type}_posts_custom_column", [__CLASS__, 'column'], 10, 2);
            add_action("save_post_{$type}", [__CLASS__, 'save_quick_edit'], 30);
        }

        add_action('quick_edit_custom_box', [__CLASS__, 'quick_edit_field'], 10, 2);
        add_action('bulk_edit_custom_box', [__CLASS__, 'quick_edit_field'], 10, 2);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_quick_edit_assets']);
    }

    public static function current_id() {
        return IFP_Production_Status::current_id(true);
    }

    public static function options() {
        return get_posts([
            'post_type' => 'ifp_production',
            'post_status' => ['publish','draft','future','private'],
            'posts_per_page' => -1,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);
    }

    public static function scope($id) {
        $value = get_post_meta($id, self::META_KEY, true);
        return $value === '' ? 'all' : (string) $value;
    }

    public static function render($id) {
        $saved = self::scope($id);
        $current = self::current_id();

        echo '<p><label><strong>Production</strong><br>';
        echo '<select class="widefat" name="ifp_production_scope">';
        echo '<option value="all" '.selected($saved, 'all', false).'>All Productions / Shared</option>';

        if ($current) {
            echo '<option value="current" '.selected($saved, 'current', false).'>Current Production (automatic)</option>';
        }

        foreach (self::options() as $production) {
            echo '<option value="'.esc_attr((string) $production->ID).'" '.selected($saved, (string) $production->ID, false).'>'.esc_html($production->post_title).'</option>';
        }

        echo '</select></label></p>';
        echo '<p class="description">Shared items appear for every production. Named assignments stay with that show.</p>';
    }

    public static function save($id) {
        $scope = sanitize_text_field(wp_unslash($_POST['ifp_production_scope'] ?? 'all'));
        if (!in_array($scope, ['all','current'], true) && !ctype_digit($scope)) {
            $scope = 'all';
        }
        update_post_meta($id, self::META_KEY, $scope);
    }

    public static function clause($production_id = 0) {
        $current_id = self::current_id();
        $production_id = $production_id ?: $current_id;
        $query = [
            'relation' => 'OR',
            ['key' => self::META_KEY, 'compare' => 'NOT EXISTS'],
            ['key' => self::META_KEY, 'value' => 'all'],
        ];

        if ($production_id && $current_id && absint($production_id) === $current_id) {
            $query[] = ['key' => self::META_KEY, 'value' => 'current'];
        }

        if ($production_id) {
            $query[] = ['key' => self::META_KEY, 'value' => (string) absint($production_id)];
        }

        return $query;
    }

    public static function columns($columns) {
        $new = [];
        foreach ($columns as $key => $label) {
            $new[$key] = $label;
            if ($key === 'title') {
                $new['ifp_production_scope'] = 'Production';
            }
        }
        return $new;
    }

    public static function column($column, $id) {
        if ($column !== 'ifp_production_scope') return;

        $scope = self::scope($id);
        echo '<span class="ifp-scope-value" data-scope="'.esc_attr($scope).'" hidden></span>';

        if ($scope === 'all') {
            echo '<span class="ifp-scope-badge ifp-scope-badge--shared">Shared</span>';
        } elseif ($scope === 'current') {
            echo '<span class="ifp-scope-badge ifp-scope-badge--current">Current Production</span>';
        } else {
            $title = get_the_title((int) $scope);
            echo '<span class="ifp-scope-badge">'.esc_html($title ?: 'Missing Production').'</span>';
        }
    }

    public static function quick_edit_field($column_name, $post_type) {
        if ($column_name !== 'ifp_production_scope') return;
        if (!in_array($post_type, ['ifp_notice','ifp_resource','ifp_event','ifp_contact','ifp_sponsor'], true)) return;

        static $rendered = [];
        $screen_key = $post_type . ':' . current_filter();

        if (!empty($rendered[$screen_key])) return;
        $rendered[$screen_key] = true;

        wp_nonce_field('ifp_quick_edit_scope', 'ifp_quick_edit_scope_nonce');

        echo '<fieldset class="inline-edit-col-right ifp-quick-edit-production">';
        echo '<div class="inline-edit-col">';
        echo '<label class="inline-edit-group">';
        echo '<span class="title">Production</span>';
        echo '<select name="ifp_production_scope">';
        echo '<option value="__no_change__">— No Change —</option>';
        echo '<option value="all">All Productions / Shared</option>';

        if (self::current_id()) {
            echo '<option value="current">Current Production (automatic)</option>';
        }

        foreach (self::options() as $production) {
            echo '<option value="'.esc_attr((string) $production->ID).'">'.esc_html($production->post_title).'</option>';
        }

        echo '</select>';
        echo '</label>';
        echo '</div>';
        echo '</fieldset>';
    }

    public static function enqueue_quick_edit_assets($hook) {
        if ($hook !== 'edit.php') return;

        $screen = get_current_screen();
        if (!$screen || !in_array($screen->post_type, ['ifp_notice','ifp_resource','ifp_event','ifp_contact','ifp_sponsor'], true)) {
            return;
        }

        wp_enqueue_script(
            'ifp-production-quick-edit',
            IFP_URL . 'assets/production-quick-edit.js',
            ['jquery', 'inline-edit-post'],
            IFP_VERSION,
            true
        );
    }

    public static function save_quick_edit($post_id) {
        if (!isset($_POST['ifp_quick_edit_scope_nonce'])) return;
        if (!wp_verify_nonce(
            sanitize_text_field(wp_unslash($_POST['ifp_quick_edit_scope_nonce'])),
            'ifp_quick_edit_scope'
        )) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $submitted_scope = sanitize_text_field(wp_unslash($_POST['ifp_production_scope'] ?? '__no_change__'));
        if ($submitted_scope === '__no_change__') return;

        self::save($post_id);
    }
}
