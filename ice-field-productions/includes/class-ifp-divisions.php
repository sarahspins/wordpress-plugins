<?php
if (!defined('ABSPATH')) exit;

class IFP_Divisions {
    const NONCE_ACTION = 'ifp_save_division';
    const NONCE_NAME = 'ifp_division_nonce';

    public static function init() {
        add_action('init', [__CLASS__, 'register_post_type']);
        add_action('add_meta_boxes', [__CLASS__, 'meta_boxes']);
        add_action('save_post_ifp_division', [__CLASS__, 'save']);
        add_filter('manage_ifp_division_posts_columns', [__CLASS__, 'columns']);
        add_action('manage_ifp_division_posts_custom_column', [__CLASS__, 'column'], 10, 2);
    }

    public static function register_post_type() {
        register_post_type('ifp_division', [
            'labels' => [
                'name' => 'Divisions', 'singular_name' => 'Division', 'menu_name' => 'Divisions',
                'add_new_item' => 'Add Division', 'edit_item' => 'Edit Division', 'search_items' => 'Search Divisions',
                'not_found' => 'No divisions found',
            ],
            'public' => false, 'show_ui' => true, 'show_in_menu' => 'ifp-dashboard',
            'show_in_rest' => true, 'supports' => ['title', 'editor', 'page-attributes'],
            'menu_icon' => 'dashicons-networking',
        ]);
    }

    public static function meta_boxes() {
        add_meta_box('ifp_division_relationships', 'Production & Dash Connection', [__CLASS__, 'render'], 'ifp_division', 'side', 'high');
    }

    public static function render($post) {
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);
        $production_id = absint(get_post_meta($post->ID, '_ifp_division_production_id', true));
        $league_id = absint(get_post_meta($post->ID, '_ifp_dash_league_id', true));
        $season_id = absint(get_post_meta($post->ID, '_ifp_dash_season_id', true));
        $last_sync = get_post_meta($post->ID, '_ifp_dash_last_sync', true);
        $registration_url = get_post_meta($post->ID, '_ifp_registration_url', true);
        echo '<p><strong>Production</strong><br>' . ($production_id ? '<a href="' . esc_url(get_edit_post_link($production_id)) . '">' . esc_html(get_the_title($production_id)) . '</a>' : '—') . '</p>';
        echo '<p><span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:' . ($league_id ? '#18a558' : '#999') . ';margin-right:6px"></span><strong>' . ($league_id ? 'Dash linked' : 'Not linked') . '</strong></p>';
        echo '<p><strong>Season ID:</strong> ' . ($season_id ?: '—') . '<br><strong>League ID:</strong> ' . ($league_id ?: '—') . '</p>';
        echo '<p><label><strong>Registration URL</strong><br><input class="widefat" type="url" name="ifp_division_registration_url" value="' . esc_attr($registration_url) . '" placeholder="Generated automatically from Dash"></label></p>';
        echo '<p class="description">A manual URL is preserved during later Dash syncs.</p>';
        if ($registration_url) echo '<p><a class="button" href="' . esc_url($registration_url) . '" target="_blank" rel="noopener noreferrer">Open Registration</a></p>';
        if ($last_sync) echo '<p><strong>Last sync:</strong><br>' . esc_html($last_sync) . '</p>';
    }

    public static function save($post_id) {
        if (!isset($_POST[self::NONCE_NAME])) return;
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE_NAME])), self::NONCE_ACTION)) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        update_post_meta(
            $post_id,
            '_ifp_registration_url',
            esc_url_raw(wp_unslash($_POST['ifp_division_registration_url'] ?? ''))
        );
    }

    public static function columns($columns) {
        return ['cb' => $columns['cb'], 'title' => 'Division', 'production' => 'Production', 'programming' => 'Programming Level', 'groups' => 'Groups', 'dash' => 'Dash', 'date' => $columns['date']];
    }

    public static function column($column, $post_id) {
        if ($column === 'production') {
            $id = absint(get_post_meta($post_id, '_ifp_division_production_id', true));
            echo $id ? '<a href="' . esc_url(get_edit_post_link($id)) . '">' . esc_html(get_the_title($id)) . '</a>' : '—';
        } elseif ($column === 'programming') {
            $id = absint(get_post_meta($post_id, '_ifp_programming_level_id', true));
            echo $id && get_post_type($id) === 'ifprog_level'
                ? '<a href="' . esc_url(get_edit_post_link($id)) . '">' . esc_html(get_the_title($id)) . '</a>'
                : '—';
        } elseif ($column === 'groups') {
            $groups = get_posts(['post_type'=>'ifp_group','post_status'=>'any','posts_per_page'=>-1,'fields'=>'ids','meta_key'=>'_ifp_group_division_id','meta_value'=>$post_id]);
            echo esc_html(count($groups));
        } elseif ($column === 'dash') {
            $id = absint(get_post_meta($post_id, '_ifp_dash_league_id', true));
            echo $id ? '<span style="color:#18713c">● Linked · League ' . esc_html($id) . '</span>' : '—';
        }
    }
}
