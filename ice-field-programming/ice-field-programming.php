<?php
/**
 * Plugin Name: Ice & Field Programming
 * Description: Manage seasons, program groups, levels, classes, leagues, camps, clinics, and public registration displays for Ice & Field.
 * Version: 1.9.1.15
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Requires Plugins: ice-field-dash-connector
 * Update URI: https://github.com/sarahspins/wordpress-plugins/tree/main/ice-field-programming
 * Author: Ice & Field
 * Text Domain: ice-field-programming
 */

if (!defined('ABSPATH')) exit;

define('IFPROG_VERSION', '1.9.1.15');
define('IFPROG_DIR', plugin_dir_path(__FILE__));
define('IFPROG_URL', plugin_dir_url(__FILE__));

require_once IFPROG_DIR . 'includes/class-ifprog-post-types.php';
require_once IFPROG_DIR . 'includes/class-ifprog-fields.php';
require_once IFPROG_DIR . 'includes/class-ifprog-status.php';
require_once IFPROG_DIR . 'includes/class-ifprog-meta.php';
require_once IFPROG_DIR . 'includes/class-ifprog-dash.php';
require_once IFPROG_DIR . 'includes/class-ifprog-audit.php';
require_once IFPROG_DIR . 'includes/class-ifprog-monitoring.php';
require_once IFPROG_DIR . 'includes/class-ifprog-discovery.php';
require_once IFPROG_DIR . 'includes/class-ifprog-sync.php';
require_once IFPROG_DIR . 'includes/class-ifprog-preview.php';
require_once IFPROG_DIR . 'includes/class-ifprog-admin.php';
require_once IFPROG_DIR . 'includes/class-ifprog-shortcodes.php';

function ifprog_boot() {
    IFPROG_Post_Types::init();
    IFPROG_Meta::init();
    IFPROG_Dash::init();
    IFPROG_Discovery::init();
    IFPROG_Preview::init();
    IFPROG_Admin::init();
    IFPROG_Monitoring::init();
    IFPROG_Shortcodes::init();
}
add_action('plugins_loaded', 'ifprog_boot');

register_activation_hook(__FILE__, function() {
    IFPROG_Post_Types::register();
    IFPROG_Post_Types::maybe_seed_terms();
    IFPROG_Admin::add_default_settings();
    IFPROG_Monitoring::install();
    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, 'flush_rewrite_rules');
