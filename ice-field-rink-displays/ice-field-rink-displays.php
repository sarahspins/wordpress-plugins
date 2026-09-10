<?php
/*
Plugin Name: Ice & Field Rink Displays
Description: Combined Dash/DaySmart schedule display and rink participants/check-in display for Ice & Field.
Version: 2.8.18
Author: Ice & Field
Requires Plugins: ice-field-dash-connector
Update URI: https://github.com/sarahspins/wordpress-plugins/tree/main/ice-field-rink-displays
*/

if (!defined('ABSPATH')) {
    exit;
}

define('IFRD_VERSION', '2.8.18');
define('IFRD_DIR', plugin_dir_path(__FILE__));
define('IFRD_URL', plugin_dir_url(__FILE__));

require_once IFRD_DIR . 'includes/class-ifrd-dash-connector.php';
require_once IFRD_DIR . 'includes/class-ifrd-static-schedule-cache.php';
require_once IFRD_DIR . 'includes/class-ifrd-banner-media.php';
require_once IFRD_DIR . 'includes/class-ifrd-scheduled-media.php';
require_once IFRD_DIR . 'includes/class-ifrd-expiring-slideshow.php';
require_once IFRD_DIR . 'includes/class-ifrd-media-cleanup.php';
require_once IFRD_DIR . 'includes/class-ifrd-video-for-screens.php';
require_once IFRD_DIR . 'includes/class-ifrd-additional-video-screen.php';
require_once IFRD_DIR . 'includes/class-ifrd-shortcodes-page.php';
require_once IFRD_DIR . 'includes/class-ifrd-schedule-display.php';
require_once IFRD_DIR . 'includes/class-ifrd-participants-display.php';
require_once IFRD_DIR . 'includes/class-ifrd-schedule-calendar.php';

$ifrd_schedule_display = new IFRD_Schedule_Display();
new IFRD_Participants_Display();
new IFRD_Schedule_Calendar($ifrd_schedule_display);
new IFRD_Video_For_Screens();
new IFRD_Additional_Video_Screen('pricing', 'Pricing Page', 'ifrd-pricing-page', 'pricing_page');
new IFRD_Additional_Video_Screen('public_skating_rules', 'Public Skating Rules Page', 'ifrd-public-skating-rules-page', 'public_skating_rules_page');
new IFRD_Shortcodes_Page();
IFRD_Media_Cleanup::init();
register_deactivation_hook(__FILE__, array('IFRD_Schedule_Calendar', 'deactivate'));
register_deactivation_hook(__FILE__, array('IFRD_Schedule_Display', 'deactivate'));
register_deactivation_hook(__FILE__, array('IFRD_Media_Cleanup', 'deactivate'));
