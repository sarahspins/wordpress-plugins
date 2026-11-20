<?php
/*
Plugin Name: Ice & Field Display Cloud
Description: Authenticated cloud backup storage for Ice & Field Pi display content.
Version: 0.3.0
Author: Ice & Field
Requires at least: 6.4
Requires PHP: 7.4
Update URI: false
*/

if (!defined('ABSPATH')) {
    exit;
}

final class Ice_Field_Display_Cloud {
    const VERSION = '0.3.0';
    const REST_NAMESPACE = 'ice-field-display-cloud/v1';
    const MENU_SLUG = 'ifdc-cloud-storage';
    const MAX_MANIFEST_BYTES = 1048576;

    private static $allowed_extensions = array(
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
    );

    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
        add_action('admin_menu', array(__CLASS__, 'register_menu'), 60);
    }

    public static function activate() {
        self::ensure_directories();
    }

    public static function register_menu() {
        if (defined('IFRD_VERSION')) {
            add_submenu_page(
                'ifrd-schedule-display',
                'Pi Cloud Storage',
                'Pi Cloud Storage',
                'manage_options',
                self::MENU_SLUG,
                array(__CLASS__, 'render_page')
            );
            return;
        }

        add_menu_page(
            'Pi Cloud Storage',
            'Display Cloud',
            'manage_options',
            self::MENU_SLUG,
            array(__CLASS__, 'render_page'),
            'dashicons-cloud-saved',
            27
        );
    }

    public static function register_routes() {
        register_rest_route(self::REST_NAMESPACE, '/status', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'rest_status'),
            'permission_callback' => array(__CLASS__, 'can_publish'),
        ));

        register_rest_route(self::REST_NAMESPACE, '/objects/(?P<hash>[a-f0-9]{64})', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'rest_upload_object'),
            'permission_callback' => array(__CLASS__, 'can_publish'),
            'args' => array(
                'hash' => array('required' => true, 'validate_callback' => array(__CLASS__, 'valid_hash')),
            ),
        ));

        register_rest_route(self::REST_NAMESPACE, '/manifest', array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array(__CLASS__, 'rest_get_manifest'),
                'permission_callback' => '__return_true',
            ),
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array(__CLASS__, 'rest_publish_manifest'),
                'permission_callback' => array(__CLASS__, 'can_publish'),
            ),
        ));

        register_rest_route(self::REST_NAMESPACE, '/slideshow', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'rest_get_slideshow'),
            'permission_callback' => array(__CLASS__, 'can_publish'),
        ));

        register_rest_route(self::REST_NAMESPACE, '/master-slideshow', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'rest_get_master_slideshow'),
            'permission_callback' => array(__CLASS__, 'can_publish'),
        ));

        register_rest_route(self::REST_NAMESPACE, '/fleet', array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array(__CLASS__, 'rest_get_fleet'),
                'permission_callback' => array(__CLASS__, 'can_publish'),
            ),
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array(__CLASS__, 'rest_publish_fleet'),
                'permission_callback' => array(__CLASS__, 'can_publish'),
            ),
        ));
    }

    public static function can_publish() {
        return current_user_can('upload_files') && current_user_can('edit_pages');
    }

    public static function valid_hash($value) {
        return is_string($value) && (bool) preg_match('/^[a-f0-9]{64}$/', $value);
    }

    private static function paths() {
        $uploads = wp_upload_dir();
        $base = trailingslashit($uploads['basedir']) . 'ice-field-display-cloud';
        return array(
            'base' => $base,
            'objects' => $base . '/objects',
            'manifest' => $base . '/manifest.json',
            'previous' => $base . '/manifest.previous.json',
            'fleet' => $base . '/fleet.json',
            'url' => trailingslashit($uploads['baseurl']) . 'ice-field-display-cloud',
        );
    }

    private static function ensure_directories() {
        $paths = self::paths();
        if (!wp_mkdir_p($paths['objects'])) {
            return new WP_Error('ifdc_storage_directory', 'WordPress could not create the display-cloud storage directory.', array('status' => 500));
        }
        $index = $paths['base'] . '/index.html';
        if (!file_exists($index)) {
            @file_put_contents($index, '');
        }
        $object_index = $paths['objects'] . '/index.html';
        if (!file_exists($object_index)) {
            @file_put_contents($object_index, '');
        }
        $htaccess = $paths['base'] . '/.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "<FilesMatch \"\\.(php[0-9]?|phtml|phar)$\">\nRequire all denied\n</FilesMatch>\nOptions -Indexes\n");
        }
        return $paths;
    }

    private static function object_filename($hash, $original_name) {
        $extension = strtolower(pathinfo(sanitize_file_name($original_name), PATHINFO_EXTENSION));
        if (!isset(self::$allowed_extensions[$extension])) {
            return new WP_Error('ifdc_file_type', 'That file type is not supported.', array('status' => 400));
        }
        return $hash . '.' . $extension;
    }

    public static function rest_upload_object(WP_REST_Request $request) {
        $paths = self::ensure_directories();
        if (is_wp_error($paths)) {
            return $paths;
        }

        $files = $request->get_file_params();
        if (empty($files['file']) || !is_array($files['file'])) {
            return new WP_Error('ifdc_missing_file', 'Upload the media in the multipart field named file.', array('status' => 400));
        }
        $file = $files['file'];
        if (!empty($file['error'])) {
            return new WP_Error('ifdc_upload_error', 'WordPress rejected the uploaded file.', array('status' => 400));
        }
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return new WP_Error('ifdc_upload_error', 'The uploaded temporary file is unavailable.', array('status' => 400));
        }

        $expected_hash = (string) $request['hash'];
        $actual_hash = hash_file('sha256', $file['tmp_name']);
        if (!$actual_hash || !hash_equals($expected_hash, $actual_hash)) {
            return new WP_Error('ifdc_hash_mismatch', 'The uploaded file did not match its SHA-256 checksum.', array('status' => 400));
        }

        $filename = self::object_filename($expected_hash, isset($file['name']) ? $file['name'] : '');
        if (is_wp_error($filename)) {
            return $filename;
        }
        $destination = $paths['objects'] . '/' . $filename;
        if (!file_exists($destination)) {
            if (!@move_uploaded_file($file['tmp_name'], $destination)) {
                return new WP_Error('ifdc_store_failed', 'WordPress could not store the uploaded display file.', array('status' => 500));
            }
            @chmod($destination, 0644);
        }

        return new WP_REST_Response(array(
            'ok' => true,
            'hash' => $expected_hash,
            'file' => $filename,
            'size' => filesize($destination),
            'url' => trailingslashit($paths['url']) . 'objects/' . rawurlencode($filename),
        ), 201);
    }

    private static function validate_manifest($manifest, $paths) {
        if (!is_array($manifest) || (int) ($manifest['schema'] ?? 0) !== 1) {
            return new WP_Error('ifdc_manifest_schema', 'The display manifest schema is invalid.', array('status' => 400));
        }
        if (!isset($manifest['revision']) || !is_numeric($manifest['revision'])) {
            return new WP_Error('ifdc_manifest_revision', 'The display manifest revision is missing.', array('status' => 400));
        }
        if (!isset($manifest['channels']) || !is_array($manifest['channels'])) {
            return new WP_Error('ifdc_manifest_channels', 'The display manifest channels are missing.', array('status' => 400));
        }
        if (!isset($manifest['media']) || !is_array($manifest['media'])) {
            return new WP_Error('ifdc_manifest_media', 'The display manifest media list is missing.', array('status' => 400));
        }
        foreach ($manifest['media'] as $item) {
            $hash = is_array($item) ? (string) ($item['sha256'] ?? '') : '';
            $file = is_array($item) ? sanitize_file_name((string) ($item['file'] ?? '')) : '';
            if (!self::valid_hash($hash) || !preg_match('/^' . preg_quote($hash, '/') . '\\.(mp4|webm|png|jpe?g|webp)$/', $file)) {
                return new WP_Error('ifdc_manifest_object', 'The display manifest contains an invalid media object.', array('status' => 400));
            }
            if (!is_file($paths['objects'] . '/' . $file)) {
                return new WP_Error('ifdc_manifest_missing_object', 'The manifest references a media object that has not been uploaded.', array('status' => 409));
            }
        }
        return true;
    }

    public static function rest_publish_manifest(WP_REST_Request $request) {
        $paths = self::ensure_directories();
        if (is_wp_error($paths)) {
            return $paths;
        }
        $body = $request->get_body();
        if (strlen($body) > self::MAX_MANIFEST_BYTES) {
            return new WP_Error('ifdc_manifest_size', 'The display manifest is too large.', array('status' => 413));
        }
        $manifest = json_decode($body, true);
        if (!is_array($manifest)) {
            return new WP_Error('ifdc_manifest_json', 'The display manifest is not valid JSON.', array('status' => 400));
        }
        $valid = self::validate_manifest($manifest, $paths);
        if (is_wp_error($valid)) {
            return $valid;
        }

        $manifest['object_base_url'] = trailingslashit($paths['url']) . 'objects/';
        $manifest['published_at'] = gmdate('c');
        $encoded = wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!function_exists('wp_tempnam')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $temporary = wp_tempnam('ifdc-manifest');
        if (!$temporary || false === file_put_contents($temporary, $encoded . "\n", LOCK_EX)) {
            return new WP_Error('ifdc_manifest_write', 'WordPress could not prepare the display manifest.', array('status' => 500));
        }
        if (is_file($paths['manifest'])) {
            @copy($paths['manifest'], $paths['previous']);
        }
        if (!@rename($temporary, $paths['manifest'])) {
            @unlink($temporary);
            return new WP_Error('ifdc_manifest_publish', 'WordPress could not publish the display manifest.', array('status' => 500));
        }
        @chmod($paths['manifest'], 0644);
        return new WP_REST_Response(array(
            'ok' => true,
            'revision' => (int) $manifest['revision'],
            'manifest_url' => rest_url(self::REST_NAMESPACE . '/manifest'),
        ), 201);
    }

    public static function rest_get_manifest() {
        $paths = self::paths();
        if (!is_file($paths['manifest'])) {
            return new WP_Error('ifdc_no_manifest', 'No display backup has been published yet.', array('status' => 404));
        }
        $manifest = json_decode((string) file_get_contents($paths['manifest']), true);
        if (!is_array($manifest)) {
            return new WP_Error('ifdc_manifest_broken', 'The stored display manifest is invalid.', array('status' => 500));
        }
        $response = new WP_REST_Response($manifest, 200);
        $response->header('Cache-Control', 'no-cache, must-revalidate');
        return $response;
    }

    public static function rest_get_slideshow() {
        if (!class_exists('IFRD_Video_For_Screens') || !class_exists('IFRD_Expiring_Slideshow')) {
            return new WP_Error('ifdc_slideshow_unavailable', 'Ice & Field Rink Displays is not active.', array('status' => 409));
        }

        $settings = wp_parse_args(get_option(IFRD_Video_For_Screens::OPTION, array()), array(
            'slideshow_images' => array(),
            'slideshow_seconds' => '10',
        ));
        $timezone = class_exists('IFRD_Scheduled_Media') ? IFRD_Scheduled_Media::timezone_name() : wp_timezone_string();
        $rows = IFRD_Expiring_Slideshow::sanitize($settings['slideshow_images'] ?? array(), $timezone);
        $slides = array();
        foreach (array_slice($rows, 0, 100) as $row) {
            $url = esc_url_raw((string) ($row['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $slides[] = array(
                'url' => $url,
                'starts_at' => sanitize_text_field((string) ($row['starts_at'] ?? '')),
                'expires_at' => sanitize_text_field((string) ($row['expires_at'] ?? '')),
            );
        }

        $response = new WP_REST_Response(array(
            'schema' => 1,
            'revision' => (string) IFRD_Video_For_Screens::current_refresh_version(),
            'generated_at' => gmdate('c'),
            'timezone' => $timezone,
            'seconds' => min(120, max(2, (int) ($settings['slideshow_seconds'] ?? 10))),
            'slides' => $slides,
        ), 200);
        $response->header('Cache-Control', 'no-cache, must-revalidate');
        return $response;
    }

    public static function master_slideshow_data() {
        $paths = self::paths();
        if (!is_file($paths['manifest'])) {
            return new WP_Error('ifdc_no_manifest', 'Display 01 has not published a cloud manifest yet.', array('status' => 404));
        }
        $manifest = json_decode((string) file_get_contents($paths['manifest']), true);
        if (!is_array($manifest)) {
            return new WP_Error('ifdc_manifest_broken', 'The stored display manifest is invalid.', array('status' => 500));
        }
        $channel = $manifest['channels']['video_for_screens'] ?? array();
        $settings = array();
        if (($channel['kind'] ?? '') === 'slideshow') {
            $settings = json_decode((string) ($channel['source'] ?? ''), true);
        }
        $media = array();
        foreach ((array) ($manifest['media'] ?? array()) as $item) {
            if (is_array($item) && !empty($item['id']) && !empty($item['file'])) {
                $media[(string) $item['id']] = $item;
            }
        }
        $base_url = trailingslashit((string) ($manifest['object_base_url'] ?? (trailingslashit($paths['url']) . 'objects/')));
        $slides = array();
        foreach (array_slice((array) ($settings['slides'] ?? array()), 0, 100) as $slide) {
            $source = is_array($slide) ? (string) ($slide['source'] ?? '') : (string) $slide;
            if (!preg_match('#^/media/([a-f0-9]{32})$#', $source, $match) || empty($media[$match[1]])) {
                continue;
            }
            $slides[] = array(
                'url' => $base_url . rawurlencode((string) $media[$match[1]]['file']),
                'starts_at' => is_array($slide) ? sanitize_text_field((string) ($slide['start'] ?? '')) : '',
                'expires_at' => is_array($slide) ? sanitize_text_field((string) ($slide['end'] ?? '')) : '',
                'name' => sanitize_text_field((string) ($media[$match[1]]['name'] ?? 'Slide image')),
            );
        }
        return array(
            'schema' => 1,
            'revision' => (int) ($manifest['revision'] ?? 0),
            'published_at' => sanitize_text_field((string) ($manifest['published_at'] ?? '')),
            'seconds' => min(120, max(2, (int) ($settings['seconds'] ?? 10))),
            'slides' => $slides,
            'kind' => sanitize_key((string) ($channel['kind'] ?? 'standby')),
        );
    }

    public static function rest_get_master_slideshow() {
        $data = self::master_slideshow_data();
        if (is_wp_error($data)) {
            return $data;
        }
        $response = new WP_REST_Response($data, 200);
        $response->header('Cache-Control', 'no-cache, must-revalidate');
        return $response;
    }

    public static function fleet_status_data() {
        $paths = self::paths();
        if (!is_file($paths['fleet'])) {
            return new WP_Error('ifdc_no_fleet', 'Display 01 has not published fleet status yet.', array('status' => 404));
        }
        $fleet = json_decode((string) file_get_contents($paths['fleet']), true);
        if (!is_array($fleet) || (int) ($fleet['schema'] ?? 0) !== 1 || !is_array($fleet['nodes'] ?? null)) {
            return new WP_Error('ifdc_fleet_broken', 'The stored fleet status is invalid.', array('status' => 500));
        }
        return $fleet;
    }

    public static function rest_get_fleet() {
        $data = self::fleet_status_data();
        if (is_wp_error($data)) {
            return $data;
        }
        $response = new WP_REST_Response($data, 200);
        $response->header('Cache-Control', 'no-cache, must-revalidate');
        return $response;
    }

    public static function rest_publish_fleet(WP_REST_Request $request) {
        $paths = self::ensure_directories();
        if (is_wp_error($paths)) {
            return $paths;
        }
        $body = $request->get_body();
        if (strlen($body) > 65536) {
            return new WP_Error('ifdc_fleet_size', 'The fleet status is too large.', array('status' => 413));
        }
        $payload = json_decode($body, true);
        if (!is_array($payload) || (int) ($payload['schema'] ?? 0) !== 1 || !is_array($payload['nodes'] ?? null)) {
            return new WP_Error('ifdc_fleet_schema', 'The fleet status is invalid.', array('status' => 400));
        }
        $nodes = array();
        foreach (array_slice($payload['nodes'], 0, 50) as $node) {
            if (!is_array($node)) continue;
            $hostname = sanitize_text_field((string) ($node['hostname'] ?? ''));
            if ($hostname === '') continue;
            $screens = array();
            foreach (array_slice((array) ($node['screens'] ?? array()), 0, 8) as $screen) {
                if (!is_array($screen)) continue;
                $name = sanitize_text_field((string) ($screen['name'] ?? ''));
                if ($name === '') continue;
                $screens[] = array(
                    'name' => $name,
                    'channel' => sanitize_key((string) ($screen['channel'] ?? '')),
                    'kind' => sanitize_key((string) ($screen['kind'] ?? 'standby')),
                );
            }
            $nodes[] = array(
                'hostname' => $hostname,
                'role' => (($node['role'] ?? '') === 'master') ? 'master' : 'node',
                'last_updated' => max(0, (int) ($node['last_updated'] ?? 0)),
                'screens' => $screens,
            );
        }
        $fleet = array(
            'schema' => 1,
            'generated_at' => max(0, (int) ($payload['generated_at'] ?? time())),
            'nodes' => $nodes,
        );
        if (!function_exists('wp_tempnam')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $temporary = wp_tempnam('ifdc-fleet');
        if (!$temporary || false === file_put_contents($temporary, wp_json_encode($fleet, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX)) {
            return new WP_Error('ifdc_fleet_write', 'WordPress could not prepare the fleet status.', array('status' => 500));
        }
        if (!@rename($temporary, $paths['fleet'])) {
            @unlink($temporary);
            return new WP_Error('ifdc_fleet_publish', 'WordPress could not publish the fleet status.', array('status' => 500));
        }
        @chmod($paths['fleet'], 0644);
        return new WP_REST_Response(array('ok' => true, 'nodes' => count($nodes)), 201);
    }

    public static function rest_status() {
        $paths = self::ensure_directories();
        if (is_wp_error($paths)) {
            return $paths;
        }
        $manifest = is_file($paths['manifest']) ? json_decode((string) file_get_contents($paths['manifest']), true) : null;
        $files = glob($paths['objects'] . '/*.*');
        $bytes = 0;
        $count = 0;
        foreach (is_array($files) ? $files : array() as $file) {
            if (is_file($file) && basename($file) !== 'index.html') {
                $count++;
                $bytes += (int) filesize($file);
            }
        }
        return array(
            'version' => self::VERSION,
            'revision' => is_array($manifest) ? (int) ($manifest['revision'] ?? 0) : 0,
            'objects' => $count,
            'bytes' => $bytes,
            'manifest_url' => rest_url(self::REST_NAMESPACE . '/manifest'),
        );
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $paths = self::ensure_directories();
        $status = is_wp_error($paths) ? null : self::rest_status();
        ?>
        <div class="wrap">
            <h1>Pi Cloud Storage</h1>
            <p>This separate plugin receives authenticated backups from the main Ice &amp; Field display controller. Display nodes can retrieve the published manifest and media when display-01 is unavailable.</p>
            <?php if (is_wp_error($paths)) : ?>
                <div class="notice notice-error"><p><?php echo esc_html($paths->get_error_message()); ?></p></div>
            <?php else : ?>
                <table class="widefat striped" style="max-width:900px">
                    <tbody>
                        <tr><th style="width:220px">Published revision</th><td><?php echo esc_html((string) $status['revision']); ?></td></tr>
                        <tr><th>Stored media objects</th><td><?php echo esc_html(number_format_i18n($status['objects'])); ?></td></tr>
                        <tr><th>Stored media size</th><td><?php echo esc_html(size_format($status['bytes'], 1)); ?></td></tr>
                        <tr><th>Public manifest</th><td><code><?php echo esc_html($status['manifest_url']); ?></code></td></tr>
                        <tr><th>Authenticated slideshow feed</th><td><code><?php echo esc_html(rest_url(self::REST_NAMESPACE . '/slideshow')); ?></code></td></tr>
                        <tr><th>Display 01 slideshow</th><td><code><?php echo esc_html(rest_url(self::REST_NAMESPACE . '/master-slideshow')); ?></code></td></tr>
                        <tr><th>Fleet status feed</th><td><code><?php echo esc_html(rest_url(self::REST_NAMESPACE . '/fleet')); ?></code></td></tr>
                        <tr><th>Storage folder</th><td><code><?php echo esc_html($paths['base']); ?></code></td></tr>
                        <tr><th>WordPress upload limit</th><td><?php echo esc_html((string) ini_get('upload_max_filesize')); ?> per file; <?php echo esc_html((string) ini_get('post_max_size')); ?> per request</td></tr>
                    </tbody>
                </table>
                <h2>Controller connection</h2>
                <p>Create a dedicated WordPress application password for a user who can upload files and edit pages. Enter that username and application password only in the main controller Pi. Do not paste the password into this page, GitHub, or the public manifest.</p>
                <p><strong>API base:</strong> <code><?php echo esc_html(rest_url(self::REST_NAMESPACE)); ?></code></p>
            <?php endif; ?>
        </div>
        <?php
    }
}

register_activation_hook(__FILE__, array('Ice_Field_Display_Cloud', 'activate'));
Ice_Field_Display_Cloud::init();
