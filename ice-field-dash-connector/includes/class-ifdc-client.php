<?php
if (!defined('ABSPATH')) exit;

class IFDC_Client {
    const OPTION = 'ifdc_settings';
    const TOKEN_TRANSIENT = 'ifdc_dash_access_token';
    const TOKEN_META_TRANSIENT = 'ifdc_dash_access_token_meta';
    const REQUEST_COUNT_TRANSIENT = 'ifdc_dash_request_count';
    const CACHE_PREFIX = 'ifdc_api_';

    public static function init() {}

    public static function defaults() {
        return [
            'enabled' => '1',
            'client_id' => '',
            'client_secret' => '',
            'company' => 'iceandfield',
            'api_base' => 'https://api.daysmartrecreation.com/api/v1/',
            'auth_url' => 'https://api.dashplatform.com/v1/auth/token',
            'timeout' => 25,
            'cache_ttl' => 300,
        ];
    }

    public static function settings() {
        return wp_parse_args(get_option(self::OPTION, []), self::defaults());
    }

    public static function is_enabled() {
        $s = self::settings();
        return ($s['enabled'] ?? '0') === '1';
    }

    public static function is_configured() {
        $s = self::settings();
        return self::is_enabled() && !empty($s['client_id']) && !empty($s['client_secret']) && !empty($s['company']);
    }

    public static function company() {
        $s = self::settings();
        return sanitize_text_field($s['company'] ?? '');
    }

    public static function clear_token() {
        delete_transient(self::TOKEN_TRANSIENT);
        delete_transient(self::TOKEN_META_TRANSIENT);
    }

    public static function get_token($force = false) {
        if (!self::is_configured()) {
            return new WP_Error('ifdc_missing_credentials', 'The shared Dash Connector is not fully configured.');
        }
        if (!$force) {
            $cached = get_transient(self::TOKEN_TRANSIENT);
            if ($cached) return $cached;
        }

        $s = self::settings();
        $started = microtime(true);
        $res = wp_remote_post($s['auth_url'], [
            'timeout' => max(5, absint($s['timeout'])),
            'body' => [
                'grant_type' => 'client_credentials',
                'client_id' => $s['client_id'],
                'client_secret' => $s['client_secret'],
            ],
        ]);
        self::increment_request_count();
        if (is_wp_error($res)) return $res;

        $code = wp_remote_retrieve_response_code($res);
        $raw = wp_remote_retrieve_body($res);
        $body = json_decode($raw, true);
        if ($code < 200 || $code >= 300 || empty($body['access_token'])) {
            return new WP_Error('ifdc_token_error', 'Unable to retrieve Dash access token.', ['status' => $code, 'body' => $raw]);
        }

        $expires = !empty($body['expires_in']) ? absint($body['expires_in']) : DAY_IN_SECONDS;
        $ttl = max(300, $expires - 300);
        $issued = time();
        set_transient(self::TOKEN_TRANSIENT, $body['access_token'], $ttl);
        set_transient(self::TOKEN_META_TRANSIENT, [
            'issued_at' => $issued,
            'expires_at' => $issued + $expires,
            'cache_expires_at' => $issued + $ttl,
            'response_ms' => round((microtime(true) - $started) * 1000),
        ], $ttl);
        return $body['access_token'];
    }

    public static function token_status() {
        $meta = get_transient(self::TOKEN_META_TRANSIENT);
        $token = get_transient(self::TOKEN_TRANSIENT);
        return [
            'cached' => (bool) $token,
            'issued_at' => is_array($meta) ? absint($meta['issued_at'] ?? 0) : 0,
            'expires_at' => is_array($meta) ? absint($meta['expires_at'] ?? 0) : 0,
            'cache_expires_at' => is_array($meta) ? absint($meta['cache_expires_at'] ?? 0) : 0,
            'response_ms' => is_array($meta) ? absint($meta['response_ms'] ?? 0) : 0,
        ];
    }

    public static function api_url($path = '', $query = []) {
        $s = self::settings();
        $url = trailingslashit($s['api_base']) . ltrim((string) $path, '/');
        if (!isset($query['company']) && self::company()) $query['company'] = self::company();
        return $query ? add_query_arg($query, $url) : $url;
    }

    private static function remote_get_with_retry($url, $args) {
        $attempts = 3;
        $retry_statuses = [429, 502, 503, 504];
        $response = null;
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $response = wp_remote_get($url, $args);
            self::increment_request_count();
            $retry = is_wp_error($response) || in_array(wp_remote_retrieve_response_code($response), $retry_statuses, true);
            if (!$retry || $attempt === $attempts) return $response;
            usleep($attempt === 1 ? 250000 : 750000);
        }
        return $response;
    }

    public static function get($path_or_url, $query = [], $args = []) {
        $use_cache = array_key_exists('cache', $args) ? (bool) $args['cache'] : true;
        $force = !empty($args['force']);
        $ttl = isset($args['cache_ttl']) ? absint($args['cache_ttl']) : absint(self::settings()['cache_ttl']);
        unset($args['cache'], $args['force'], $args['cache_ttl']);

        $url = self::normalize_url($path_or_url, $query);
        if (is_wp_error($url)) return $url;
        $cache_key = self::cache_key($url);
        if ($use_cache && !$force && $ttl > 0) {
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                $cached['_ifdc']['cached'] = true;
                return $cached;
            }
        }

        $token = self::get_token();
        if (is_wp_error($token)) return $token;
        $s = self::settings();
        $started = microtime(true);
        $res = self::remote_get_with_retry($url, wp_parse_args($args, [
            'timeout' => max(5, absint($s['timeout'])),
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/vnd.api+json',
            ],
        ]));
        if (is_wp_error($res)) return $res;

        $code = wp_remote_retrieve_response_code($res);
        $raw = wp_remote_retrieve_body($res);
        $decoded = json_decode($raw, true);
        if ($code < 200 || $code >= 300) {
            return new WP_Error('ifdc_api_error', 'Dash API error ' . $code . '.', ['status' => $code, 'body' => $raw, 'url' => $url]);
        }
        if ($decoded === null && $raw !== '' && json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('ifdc_invalid_json', 'Dash returned an invalid JSON response.', ['status' => $code, 'body' => $raw, 'url' => $url]);
        }

        $result = [
            'data' => $decoded,
            '_ifdc' => [
                'status' => $code,
                'url' => $url,
                'response_ms' => round((microtime(true) - $started) * 1000),
                'cached' => false,
            ],
        ];
        if ($use_cache && $ttl > 0) set_transient($cache_key, $result, $ttl);
        return apply_filters('ifdc_api_response', $result, $path_or_url, $query);
    }

    public static function get_data($path_or_url, $query = [], $args = []) {
        $result = self::get($path_or_url, $query, $args);
        return is_wp_error($result) ? $result : $result['data'];
    }

    public static function get_collection($path, $query = [], $args = []) {
        $all = [];
        $included = [];
        $page = 0;
        $max_pages = isset($args['max_pages']) ? max(1, absint($args['max_pages'])) : 25;
        $collect_included = !isset($args['collect_included']) || !empty($args['collect_included']);
        $collection_filter = isset($args['collection_filter']) && is_callable($args['collection_filter']) ? $args['collection_filter'] : null;
        unset($args['max_pages'], $args['collect_included'], $args['collection_filter']);
        $next = $path;
        $next_query = $query;

        while ($next && $page < $max_pages) {
            $page++;
            $result = self::get_data($next, $next_query, $args);
            if (is_wp_error($result)) return $result;
            $items = isset($result['data']) ? $result['data'] : $result;
            if (is_array($items)) {
                if ($collection_filter && self::is_list($items)) $items = array_values(array_filter($items, $collection_filter));
                if (self::is_list($items)) $all = array_merge($all, $items);
                elseif (!empty($items)) $all[] = $items;
            }
            if ($collect_included && !empty($result['included']) && is_array($result['included'])) $included = array_merge($included, $result['included']);
            $next = $result['links']['next'] ?? '';
            $next_query = [];
        }

        return ['data' => $all, 'included' => $included, 'meta' => ['pages' => $page, 'truncated' => (bool) $next]];
    }

    public static function get_teams($filters = [], $args = []) {
        $result = self::get_collection('teams', [], $args);
        if (is_wp_error($result)) return $result;
        if (!$filters) return $result;
        $result['data'] = array_values(array_filter($result['data'], function($team) use ($filters) {
            $attrs = is_array($team['attributes'] ?? null) ? $team['attributes'] : [];
            foreach ($filters as $key => $expected) {
                $actual = array_key_exists($key, $attrs) ? $attrs[$key] : ($team[$key] ?? null);
                if (is_array($expected)) {
                    if (!in_array((string) $actual, array_map('strval', $expected), true)) return false;
                } elseif ((string) $actual !== (string) $expected) return false;
            }
            return true;
        }));
        return $result;
    }

    public static function get_team($team_id, $query = [], $args = []) {
        return self::get_data('teams/' . absint($team_id), $query, $args);
    }

    public static function get_registered_customers($team_id, $args = []) {
        return self::get_collection('teams/' . absint($team_id) . '/registeredCustomers', [], $args);
    }

    public static function get_events($query = [], $args = []) {
        return self::get_collection('events', $query, $args);
    }

    /** Return configured Dash event types, preserving string IDs such as "K". */
    public static function get_event_types($args = []) {
        foreach (['event-types', 'eventTypes', 'event_types'] as $path) {
            $result = self::get_collection($path, ['page[size]' => 500], wp_parse_args($args, [
                'cache_ttl' => 12 * HOUR_IN_SECONDS,
                'max_pages' => 3,
            ]));
            if (!is_wp_error($result) && !empty($result['data'])) return $result;
        }
        return new WP_Error('ifdc_event_types_unavailable', 'Dash did not return an event-type catalog.');
    }

    private static function normalize_event_type_id($value) {
        if ($value === null) return null;
        $value = trim(sanitize_text_field((string) $value));
        return preg_match('/^[A-Za-z0-9_-]{1,32}$/', $value) ? $value : '';
    }

    /**
     * Attach an existing Dash class/team to one schedule event.
     *
     * This deliberately exposes only the one write operation currently needed by
     * Ice & Field. Other event fields cannot be changed through this helper.
     */
    public static function assign_event_team($event_id, $team_id, $capacity = null) {
        return self::update_event_assignment($event_id, $team_id, $capacity);
    }

    /**
     * Update an event's destination class, capacity, name, event type, or any combination.
     * A null team ID preserves the current class/team relationship.
     */
    public static function update_event_assignment($event_id, $team_id = null, $capacity = null, $event_name = null, $event_type_id = null) {
        $event_id = absint($event_id);
        $team_id = $team_id === null ? null : absint($team_id);
        $event_name = $event_name === null ? null : trim(sanitize_text_field($event_name));
        $event_type_supplied = $event_type_id !== null;
        $event_type_id = self::normalize_event_type_id($event_type_id);
        if ($event_name === '') $event_name = null;
        if ($event_type_supplied && $event_type_id === '') {
            return new WP_Error('ifdc_invalid_event_type', 'A valid Event Type ID is required.');
        }
        if (!$event_id || (!$team_id && $capacity === null && $event_name === null && ($event_type_id === null || $event_type_id === ''))) {
            return new WP_Error('ifdc_invalid_assignment', 'A valid event ID and at least one update are required.');
        }

        $attributes = [];
        if ($team_id) $attributes['hteam_id'] = $team_id;
        if ($capacity !== null) $attributes['register_capacity'] = max(0, absint($capacity));
        if ($event_name !== null) $attributes['desc'] = $event_name;
        if ($event_type_id !== null && $event_type_id !== '') $attributes['event_type_id'] = $event_type_id;

        $result = self::request('PATCH', 'events/' . $event_id, [
            'data' => [
                'type' => 'events',
                'id' => (string) $event_id,
                'attributes' => $attributes,
            ],
        ]);
        if (!is_wp_error($result)) self::clear_cache();
        return $result;
    }

    /** Restore the complete state captured before one automatic event update. */
    public static function restore_automatic_event_state($event_id, $team_id, $capacity, $event_name, $event_type_id = null) {
        $event_id = absint($event_id);
        if (!$event_id) return new WP_Error('ifdc_invalid_event', 'A valid event ID is required.');

        $result = self::request('PATCH', 'events/' . $event_id, [
            'data' => [
                'type' => 'events',
                'id' => (string) $event_id,
                'attributes' => array_filter([
                    'hteam_id' => absint($team_id) ?: null,
                    'register_capacity' => max(0, absint($capacity)),
                    'desc' => sanitize_text_field($event_name),
                    'event_type_id' => self::normalize_event_type_id($event_type_id),
                ], function($value, $key) { return $key !== 'event_type_id' || ($value !== null && $value !== ''); }, ARRAY_FILTER_USE_BOTH),
            ],
        ]);
        if (!is_wp_error($result)) self::clear_cache();
        return $result;
    }

    /** Make one completed Team inactive and remove it from online registration. */
    public static function update_team_visibility($team_id, $inactive) {
        $team_id = absint($team_id);
        if (!$team_id) return new WP_Error('ifdc_invalid_team', 'A valid Team ID is required.');

        $result = self::request('PATCH', 'teams/' . $team_id, [
            'data' => [
                'type' => 'teams',
                'id' => (string) $team_id,
                'attributes' => [
                    'inactive' => (bool) $inactive,
                    'online_signup' => !(bool) $inactive,
                ],
            ],
        ]);
        if (!is_wp_error($result)) self::clear_cache();
        return $result;
    }

    /**
     * Permanently delete one Dash team.
     *
     * This is deliberately limited to the teams endpoint. The calling admin
     * handler is responsible for fresh-record, roster, permission, and typed
     * confirmation checks before invoking it.
     */
    public static function delete_team($team_id) {
        $team_id = absint($team_id);
        if (!$team_id) {
            return new WP_Error('ifdc_invalid_team', 'A valid team ID is required.');
        }

        $result = self::request('DELETE', 'teams/' . $team_id, null);
        if (!is_wp_error($result)) self::clear_cache();
        return $result;
    }

    /**
     * Send a JSON:API write request to an approved Dash endpoint.
     *
     * Kept private so consumer plugins cannot issue arbitrary writes through the
     * shared credentials. Add a purpose-built public method for each future write.
     */
    private static function request($method, $path_or_url, $body = [], $args = []) {
        $url = self::normalize_url($path_or_url, []);
        if (is_wp_error($url)) return $url;

        $token = self::get_token();
        if (is_wp_error($token)) return $token;

        $s = self::settings();
        $started = microtime(true);
        $request_args = [
            'method' => strtoupper((string) $method),
            'timeout' => max(5, absint($s['timeout'])),
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/vnd.api+json',
                'Content-Type' => 'application/vnd.api+json',
            ],
        ];
        if ($body !== null) $request_args['body'] = wp_json_encode($body);
        $res = wp_remote_request($url, wp_parse_args($args, $request_args));
        self::increment_request_count();
        if (is_wp_error($res)) return $res;

        $code = wp_remote_retrieve_response_code($res);
        $raw = wp_remote_retrieve_body($res);
        $decoded = $raw === '' ? null : json_decode($raw, true);
        if ($code < 200 || $code >= 300) {
            $message = 'Dash API update error ' . $code . '.';
            if (is_array($decoded) && !empty($decoded['errors'][0]['detail'])) {
                $message .= ' ' . sanitize_text_field($decoded['errors'][0]['detail']);
            }
            return new WP_Error('ifdc_api_update_error', $message, [
                'status' => $code,
                'body' => $raw,
                'url' => $url,
            ]);
        }
        if ($decoded === null && $raw !== '' && json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('ifdc_invalid_json', 'Dash returned an invalid JSON response after the update.', [
                'status' => $code,
                'body' => $raw,
                'url' => $url,
            ]);
        }

        return [
            'data' => $decoded,
            '_ifdc' => [
                'status' => $code,
                'url' => $url,
                'response_ms' => round((microtime(true) - $started) * 1000),
            ],
        ];
    }

    public static function clear_cache() {
        global $wpdb;
        $like = '_transient_' . self::CACHE_PREFIX . '%';
        $timeout_like = '_transient_timeout_' . self::CACHE_PREFIX . '%';
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $like, $timeout_like));
    }

    public static function test_connection() {
        self::clear_token();
        $token = self::get_token(true);
        if (is_wp_error($token)) return $token;
        return ['connected' => true, 'company' => self::company(), 'token' => self::token_status()];
    }

    public static function diagnostics() {
        return [
            'enabled' => self::is_enabled(), 'configured' => self::is_configured(), 'company' => self::company(),
            'token' => self::token_status(), 'requests_today' => self::request_count(),
            'cache_ttl' => absint(self::settings()['cache_ttl']), 'php' => PHP_VERSION, 'wordpress' => get_bloginfo('version'),
        ];
    }

    private static function normalize_url($path_or_url, $query) {
        $value = trim((string) $path_or_url);
        if (preg_match('#^https?://#i', $value)) {
            $host = wp_parse_url($value, PHP_URL_HOST);
            $allowed = ['api.daysmartrecreation.com', 'api.dashplatform.com'];
            if (!in_array(strtolower((string) $host), $allowed, true)) return new WP_Error('ifdc_disallowed_host', 'Only approved Dash/DaySmart API hosts are permitted.');
            return $query ? add_query_arg($query, $value) : $value;
        }
        return self::api_url($value, $query);
    }

    private static function cache_key($url) { return self::CACHE_PREFIX . md5($url); }
    private static function is_list($array) { return function_exists('array_is_list') ? array_is_list($array) : array_keys($array) === range(0, count($array) - 1); }
    private static function request_count_key() { return self::REQUEST_COUNT_TRANSIENT . '_' . gmdate('Ymd'); }
    private static function increment_request_count() { $k = self::request_count_key(); set_transient($k, absint(get_transient($k)) + 1, 2 * DAY_IN_SECONDS); }
    public static function request_count() { return absint(get_transient(self::request_count_key())); }
}

function ice_field_dash_connector() { return 'IFDC_Client'; }
function ifdc_get_teams($filters = [], $args = []) { return IFDC_Client::get_teams($filters, $args); }
function ifdc_get_team($team_id, $query = [], $args = []) { return IFDC_Client::get_team($team_id, $query, $args); }
function ifdc_get_registered_customers($team_id, $args = []) { return IFDC_Client::get_registered_customers($team_id, $args); }
