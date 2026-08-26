<?php
/**
 * Plugin Name: Ice & Field Dash Connector
 * Description: Shared Dash/DaySmart authentication, API requests, diagnostics, and developer tools for Ice & Field plugins.
 * Version: 1.7.12
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Update URI: https://github.com/sarahspins/wordpress-plugins/tree/main/ice-field-dash-connector
 * Author: Ice & Field
 */

if (!defined('ABSPATH')) exit;

define('IFDC_VERSION', '1.7.12');
define('IFDC_DIR', plugin_dir_path(__FILE__));
define('IFDC_URL', plugin_dir_url(__FILE__));

require_once IFDC_DIR . 'includes/class-ifdc-client.php';
require_once IFDC_DIR . 'includes/class-ifdc-mailer.php';
require_once IFDC_DIR . 'includes/class-ifdc-admin.php';
require_once IFDC_DIR . 'includes/class-ifdc-event-assignment.php';
require_once IFDC_DIR . 'includes/class-ifdc-schedule-gaps.php';
require_once IFDC_DIR . 'includes/class-ifdc-github-updater.php';

function ifdc_boot() {
    IFDC_Client::init();
    IFDC_Admin::init();
    IFDC_Event_Assignment::init();
    IFDC_Schedule_Gaps::init();
    IFDC_GitHub_Updater::init();
}
add_action('plugins_loaded', 'ifdc_boot');

register_activation_hook(__FILE__, function() {
    if (!get_option(IFDC_Client::OPTION)) {
        add_option(IFDC_Client::OPTION, IFDC_Client::defaults());
    }
    IFDC_Admin::install_capabilities();
    IFDC_Event_Assignment::ensure_nightly_schedule();
});

register_deactivation_hook(__FILE__, ['IFDC_Event_Assignment', 'clear_nightly_schedule']);
register_deactivation_hook(__FILE__, ['IFDC_Schedule_Gaps', 'clear_report_schedules']);
