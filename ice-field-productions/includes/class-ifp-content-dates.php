<?php
if (!defined('ABSPATH')) exit;

class IFP_Content_Dates {
    public static function init() {
        foreach (['ifp_notice','ifp_resource'] as $type) {
            add_filter("manage_{$type}_posts_columns", [__CLASS__, 'columns'], 30);
            add_action("manage_{$type}_posts_custom_column", [__CLASS__, 'column_content'], 10, 2);
            add_filter("manage_edit-{$type}_sortable_columns", [__CLASS__, 'sortable_columns']);
        }

        add_action('pre_get_posts', [__CLASS__, 'sort_admin_queries']);
    }

    public static function columns($columns) {
        $new = [];

        foreach ($columns as $key => $label) {
            if ($key === 'date') continue;

            $new[$key] = $label;

            if ($key === 'ifp_production_scope' || ($key === 'title' && !isset($columns['ifp_production_scope']))) {
                $new['ifp_published'] = 'Published';
                $new['ifp_updated'] = 'Updated';
            }
        }

        if (!isset($new['ifp_published'])) {
            $new['ifp_published'] = 'Published';
            $new['ifp_updated'] = 'Updated';
        }

        return $new;
    }

    public static function column_content($column, $post_id) {
        if ($column === 'ifp_published') {
            echo esc_html(get_the_date(get_option('date_format'), $post_id));
        }

        if ($column === 'ifp_updated') {
            echo esc_html(get_the_modified_date(get_option('date_format'), $post_id));
        }
    }

    public static function sortable_columns($columns) {
        $columns['ifp_published'] = 'date';
        $columns['ifp_updated'] = 'modified';
        return $columns;
    }

    public static function sort_admin_queries($query) {
        if (!is_admin() || !$query->is_main_query()) return;

        $post_type = $query->get('post_type');
        if (!in_array($post_type, ['ifp_notice','ifp_resource'], true)) return;

        $orderby = $query->get('orderby');
        if ($orderby === 'modified') {
            $query->set('orderby', 'modified');
        } elseif ($orderby === 'date') {
            $query->set('orderby', 'date');
        }
    }
}
