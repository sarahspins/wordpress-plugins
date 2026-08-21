<?php
if (!defined('ABSPATH')) exit;

class IFP_Post_Types {
    const REWRITE_VERSION_OPTION = 'ifp_productions_rewrite_version';
    const REWRITE_VERSION = '3.1.4';

    public static function init() {
        add_action('init', [__CLASS__, 'register']);
        add_action('init', [__CLASS__, 'maybe_refresh_rewrite_rules'], 100);
        add_filter('rest_pre_dispatch', [__CLASS__, 'protect_private_rest_content'], 10, 3);
    }

    public static function maybe_refresh_rewrite_rules() {
        if ((string) get_option(self::REWRITE_VERSION_OPTION, '') === self::REWRITE_VERSION) {
            return;
        }

        flush_rewrite_rules(false);
        update_option(self::REWRITE_VERSION_OPTION, self::REWRITE_VERSION, false);
    }

    /**
     * Keep private production content available to authenticated editors without
     * exposing every published record through the public REST API.
     */
    public static function protect_private_rest_content($result, $server, $request) {
        if ($result !== null) return $result;

        $route = (string) $request->get_route();
        $private_types = [
            'ifp_sponsor','ifp_notice','ifp_event','ifp_contact','ifp_resource',
            'ifp_group','ifp_division','ifp_participant',
        ];

        foreach ($private_types as $post_type) {
            if (!preg_match('#^/wp/v2/' . preg_quote($post_type, '#') . '(?:/|$)#', $route)) continue;

            $object = get_post_type_object($post_type);
            $capability = $object && !empty($object->cap->edit_posts)
                ? $object->cap->edit_posts
                : 'edit_posts';

            if (current_user_can($capability)) return $result;

            return new WP_Error(
                'ifp_rest_forbidden',
                'Authentication is required to access this production content.',
                ['status' => is_user_logged_in() ? 403 : 401]
            );
        }

        return $result;
    }

    public static function register() {
        register_post_type('ifp_production', [
            'labels' => [
                'name' => 'Productions',
                'singular_name' => 'Production',
                'add_new_item' => 'Add New Production',
                'edit_item' => 'Edit Production',
                'menu_name' => 'Productions',
            ],
            'public' => true,
            'show_in_menu' => false,
            'show_in_rest' => true,
            'has_archive' => 'productions',
            'rewrite' => ['slug' => 'productions', 'with_front' => false],
            'menu_icon' => 'dashicons-tickets-alt',
            'supports' => ['title','editor','excerpt','thumbnail','revisions'],
        ]);

        register_post_type('ifp_sponsor', [
            'labels' => [
                'name' => 'Sponsors',
                'singular_name' => 'Sponsor',
                'add_new_item' => 'Add New Sponsor',
                'edit_item' => 'Edit Sponsor',
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'show_in_rest' => true,
            'menu_icon' => 'dashicons-heart',
            'supports' => ['title','editor','thumbnail'],
        ]);

        register_post_type('ifp_notice', [
            'labels' => [
                'name' => 'Participant Notices',
                'singular_name' => 'Participant Notice',
                'add_new_item' => 'Add Participant Notice',
                'edit_item' => 'Edit Participant Notice',
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'show_in_rest' => true,
            'menu_icon' => 'dashicons-megaphone',
            'supports' => ['title','editor','revisions'],
        ]);


        register_post_type('ifp_event', [
            'labels' => [
                'name' => 'Important Dates',
                'singular_name' => 'Important Date',
                'add_new_item' => 'Add Important Date',
                'edit_item' => 'Edit Important Date',
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'show_in_rest' => true,
            'menu_icon' => 'dashicons-calendar-alt',
            'supports' => ['title','editor','revisions'],
        ]);


        register_post_type('ifp_contact', [
            'labels' => [
                'name' => 'Production Contacts',
                'singular_name' => 'Production Contact',
                'add_new_item' => 'Add Production Contact',
                'edit_item' => 'Edit Production Contact',
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'show_in_rest' => true,
            'menu_icon' => 'dashicons-businessperson',
            'supports' => ['title','editor','thumbnail','revisions'],
        ]);

        register_post_type('ifp_resource', [
            'labels' => [
                'name' => 'Participant Resources',
                'singular_name' => 'Participant Resource',
                'add_new_item' => 'Add Participant Resource',
                'edit_item' => 'Edit Participant Resource',
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'show_in_rest' => true,
            'menu_icon' => 'dashicons-media-document',
            'supports' => ['title','editor','thumbnail'],
        ]);
    }
}
