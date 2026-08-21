<?php
/**
 * Plugin Name: Ice & Field Productions
 * Description: Production management, participant resources, sponsors, show archives, and front-end displays for Ice & Field skating productions.
 * Version: 3.6.5
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Requires Plugins: ice-field-dash-connector, ice-field-programming
 * Update URI: https://github.com/sarahspins/wordpress-plugins/tree/main/ice-field-productions
 * Author: Ice & Field
 * Text Domain: ice-field-productions
 */

if (!defined('ABSPATH')) exit;

define('IFP_VERSION', '3.6.5');
define('IFP_DIR', plugin_dir_path(__FILE__));
define('IFP_URL', plugin_dir_url(__FILE__));

require_once IFP_DIR . 'includes/class-ifp-post-types.php';
require_once IFP_DIR . 'includes/class-ifp-production-status.php';
require_once IFP_DIR . 'includes/class-ifp-meta.php';
require_once IFP_DIR . 'includes/class-ifp-admin.php';
require_once IFP_DIR . 'includes/class-ifp-shortcodes.php';
require_once IFP_DIR . 'includes/class-ifp-links.php';
require_once IFP_DIR . 'includes/class-ifp-homepage.php';
require_once IFP_DIR . 'includes/class-ifp-participant-hub.php';
require_once IFP_DIR . 'includes/class-ifp-relationships.php';
require_once IFP_DIR . 'includes/class-ifp-production-wizard.php';
require_once IFP_DIR . 'includes/class-ifp-page-layout.php';
require_once IFP_DIR . 'includes/class-ifp-content-dates.php';
require_once IFP_DIR . 'includes/class-ifp-groups.php';
require_once IFP_DIR . 'includes/class-ifp-production-pages.php';
require_once IFP_DIR . 'includes/class-ifp-dash-integration.php';
require_once IFP_DIR . 'includes/class-ifp-divisions.php';
require_once IFP_DIR . 'includes/class-ifp-dash-production-import.php';
require_once IFP_DIR . 'includes/class-ifp-participants.php';
require_once IFP_DIR . 'includes/class-ifp-communications.php';
require_once IFP_DIR . 'includes/class-ifp-programming-alignment.php';
require_once IFP_DIR . 'includes/class-ifp-programming-projection.php';

function ifp_boot() {
    IFP_Post_Types::init();
    IFP_Production_Status::init();
    IFP_Meta::init();
    IFP_Admin::init();
    IFP_Shortcodes::init();
    IFP_Links::init();
    IFP_Homepage::init();
    IFP_Participant_Hub::init();
    IFP_Relationships::init();
    IFP_Production_Wizard::init();
    IFP_Page_Layout::init();
    IFP_Content_Dates::init();
    IFP_Groups::init();
    IFP_Production_Pages::init();
    IFP_Dash_Integration::init();
    IFP_Divisions::init();
    IFP_Dash_Production_Import::init();
    IFP_Participants::init();
    IFP_Communications::init();
    IFP_Programming_Alignment::init();
    IFP_Programming_Projection::init();
}
add_action('plugins_loaded', 'ifp_boot');

register_activation_hook(__FILE__, function() {
    IFP_Post_Types::register();
    IFP_Participants::register_post_type();
    IFP_Divisions::register_post_type();
    IFP_Groups::register_post_type();
    IFP_Communications::register_post_types();
    IFP_Participants::grant_capabilities();
    IFP_Production_Status::maybe_schedule();
    flush_rewrite_rules();
});
register_deactivation_hook(__FILE__, function() {
    IFP_Production_Status::unschedule();
    flush_rewrite_rules();
});
