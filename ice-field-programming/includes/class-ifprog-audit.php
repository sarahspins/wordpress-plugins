<?php
if (!defined('ABSPATH')) exit;

/**
 * Small, bounded activity history for discovery, monitoring, and protected sync.
 *
 * Entries are intentionally stored without raw Dash payloads or credentials.
 * The log is capped so it cannot grow without limit before the scheduled 1.9
 * monitoring stages are introduced.
 */
class IFPROG_Audit {
    const OPTION = 'ifprog_audit_log';
    const MAX_ENTRIES = 500;

    public static function install() {
        add_option(self::OPTION, [], '', false);
    }

    public static function record($event, $message, $args = []) {
        $event = sanitize_key((string) $event);
        $message = sanitize_text_field((string) $message);
        if ($event === '' || $message === '') return false;

        $severity = sanitize_key((string) ($args['severity'] ?? 'info'));
        if (!in_array($severity, ['info','success','warning','error'], true)) {
            $severity = 'info';
        }

        $entries = self::entries();
        array_unshift($entries, [
            'id' => function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('ifprog-', true),
            'created_at' => current_time('mysql', true),
            'event' => $event,
            'severity' => $severity,
            'source' => sanitize_key((string) ($args['source'] ?? 'system')),
            'message' => $message,
            'family' => sanitize_text_field((string) ($args['family'] ?? '')),
            'dash_season_id' => absint($args['dash_season_id'] ?? 0),
            'season_id' => absint($args['season_id'] ?? 0),
            'actor_id' => function_exists('get_current_user_id') ? absint(get_current_user_id()) : 0,
            'context' => self::sanitize_context($args['context'] ?? []),
        ]);

        $entries = self::prune($entries);
        return update_option(self::OPTION, $entries, false);
    }

    public static function entries() {
        $entries = get_option(self::OPTION, []);
        if (!is_array($entries)) return [];

        return array_values(array_filter($entries, function($entry) {
            return is_array($entry) && !empty($entry['event']) && !empty($entry['message']);
        }));
    }

    public static function filtered($filters = []) {
        $event = sanitize_key((string) ($filters['event'] ?? ''));
        $severity = sanitize_key((string) ($filters['severity'] ?? ''));
        $family = sanitize_text_field((string) ($filters['family'] ?? ''));

        return array_values(array_filter(self::entries(), function($entry) use ($event, $severity, $family) {
            if ($event !== '' && ($entry['event'] ?? '') !== $event) return false;
            if ($severity !== '' && ($entry['severity'] ?? '') !== $severity) return false;
            if ($family !== '' && ($entry['family'] ?? '') !== $family) return false;
            return true;
        }));
    }

    public static function event_labels() {
        return [
            'foundation_ready' => 'Foundation installed',
            'monitoring_settings_updated' => 'Settings updated',
            'discovery_refreshed' => 'Discovery refreshed',
            'discovery_failed' => 'Discovery failed',
            'season_excluded' => 'Season excluded',
            'season_restored' => 'Season restored',
            'sync_completed' => 'Sync completed',
            'sync_completed_with_warnings' => 'Sync completed with warnings',
            'sync_failed' => 'Sync failed',
        ];
    }

    private static function prune($entries) {
        $monitoring = get_option('ifprog_monitoring_settings', []);
        $retention_days = absint(is_array($monitoring) ? ($monitoring['audit_retention_days'] ?? 180) : 180);
        $retention_days = max(30, min(365, $retention_days));
        $cutoff = time() - ($retention_days * DAY_IN_SECONDS);

        $entries = array_values(array_filter((array) $entries, function($entry) use ($cutoff) {
            $created = strtotime((string) ($entry['created_at'] ?? '') . ' UTC');
            return !$created || $created >= $cutoff;
        }));

        return array_slice($entries, 0, self::MAX_ENTRIES);
    }

    private static function sanitize_context($value, $depth = 0) {
        if ($depth > 3) return '';
        if (is_bool($value) || is_int($value) || is_float($value)) return $value;
        if (is_string($value)) return sanitize_text_field($value);
        if (!is_array($value)) return '';

        $clean = [];
        $count = 0;
        foreach ($value as $key => $item) {
            if ($count >= 50) break;
            $clean_key = is_int($key) ? $key : sanitize_key((string) $key);
            $clean[$clean_key] = self::sanitize_context($item, $depth + 1);
            $count++;
        }
        return $clean;
    }
}
