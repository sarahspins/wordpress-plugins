<?php
/**
 * Thin integration layer for the separately installed Ice & Field Dash Connector.
 * Authentication, tenant selection, request handling, and shared API caching all
 * live in that connector plugin rather than this display plugin.
 */
class IFRD_Dash_Connector {
    const MIN_VERSION = '1.3.0';

    public static function is_available() {
        if (!class_exists('IFDC_Client')) {
            return false;
        }

        return !defined('IFDC_VERSION') || version_compare(IFDC_VERSION, self::MIN_VERSION, '>=');
    }

    public static function is_ready() {
        return self::is_available() && IFDC_Client::is_configured();
    }

    public static function company() {
        return self::is_available() ? IFDC_Client::company() : '';
    }

    public static function get($path_or_url, $query = array(), $args = array()) {
        if (!self::is_available()) {
            return new WP_Error(
                'ifrd_connector_missing',
                'Ice & Field Dash Connector v' . self::MIN_VERSION . ' or newer is required.'
            );
        }

        if (!IFDC_Client::is_configured()) {
            return new WP_Error(
                'ifrd_connector_not_ready',
                'The Ice & Field Dash Connector is installed but is not configured.'
            );
        }

        if (!isset($query['company']) && IFDC_Client::company()) {
            $query['company'] = IFDC_Client::company();
        }

        return IFDC_Client::get_data($path_or_url, $query, $args);
    }

    public static function render_settings_status() {
        if (!self::is_available()) {
            echo '<p><strong>Status:</strong> Connector not detected. Install and activate Ice &amp; Field Dash Connector v' . esc_html(self::MIN_VERSION) . ' or newer.</p>';
            return;
        }

        if (!IFDC_Client::is_configured()) {
            echo '<p><strong>Status:</strong> Connector installed; setup required. <a class="button button-primary" href="' .
                esc_url(admin_url('admin.php?page=ifdc-settings')) . '">Configure Dash Connector</a></p>';
            return;
        }

        echo '<p><strong>Status:</strong> <span style="color:#18713c">Connected and ready</span></p>';
        echo '<p><strong>Company:</strong> ' . esc_html(IFDC_Client::company()) . ' &nbsp; <a class="button" href="' .
            esc_url(admin_url('admin.php?page=ifdc-dashboard')) . '">Open Dash Connector</a></p>';
    }
}

