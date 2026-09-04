<?php
/**
 * Public, shared schedule snapshots served directly by the web server.
 * WordPress builds these files; display browsers never need to boot PHP to
 * read a successful schedule refresh.
 */
class IFRD_Static_Schedule_Cache {
    const DIRECTORY = 'ice-field-schedule-cache';

    private static function location() {
        $uploads = wp_upload_dir(null, false);
        if (!empty($uploads['error']) || empty($uploads['basedir']) || empty($uploads['baseurl'])) return null;
        return array(
            'directory' => trailingslashit($uploads['basedir']) . self::DIRECTORY,
            'url' => trailingslashit($uploads['baseurl']) . self::DIRECTORY,
        );
    }

    private static function safe_name($name) {
        return sanitize_file_name(strtolower((string) $name)) . '.json';
    }

    public static function base_url() {
        $location = self::location();
        return $location ? trailingslashit($location['url']) : '';
    }

    public static function url($name) {
        $base = self::base_url();
        return $base !== '' ? $base . self::safe_name($name) : '';
    }

    public static function write($name, $payload) {
        $location = self::location();
        if (!$location || !is_array($payload)) return false;
        if (!is_dir($location['directory']) && !wp_mkdir_p($location['directory'])) return false;

        $destination = trailingslashit($location['directory']) . self::safe_name($name);
        $temporary = $destination . '.tmp-' . wp_generate_password(8, false, false);
        $json = wp_json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || file_put_contents($temporary, $json . "\n", LOCK_EX) === false) return false;
        @chmod($temporary, 0644);
        if (!@rename($temporary, $destination)) {
            @unlink($temporary);
            return false;
        }
        return true;
    }
}

