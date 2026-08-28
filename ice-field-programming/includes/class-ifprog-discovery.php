<?php
if (!defined('ABSPATH')) exit;

/**
 * Manual Dash Season discovery and comparison state for the 1.8 inbox.
 *
 * This layer never imports on its own. It identifies new and changed source
 * records, stores reversible exclusions by immutable Dash Season ID, and
 * leaves every write to the existing protected preview/sync workflow.
 */
class IFPROG_Discovery {
    const EXCLUDED_OPTION = 'ifprog_dash_excluded_seasons';
    const CHECKS_TRANSIENT = 'ifprog_dash_discovery_checks';
    const SNAPSHOT_KEY = '_ifprog_dash_discovery_snapshot';
    const SNAPSHOT_HASH_KEY = '_ifprog_dash_discovery_snapshot_hash';
    const LAST_CHECKED_KEY = '_ifprog_dash_discovery_last_checked';

    public static function init() {}

    public static function excluded() {
        $saved = get_option(self::EXCLUDED_OPTION, []);
        if (!is_array($saved)) return [];

        $excluded = [];
        foreach ($saved as $dash_id => $details) {
            $dash_id = absint($dash_id);
            if (!$dash_id) continue;
            $details = is_array($details) ? $details : [];
            $excluded[$dash_id] = [
                'name' => sanitize_text_field((string) ($details['name'] ?? 'Dash Season #' . $dash_id)),
                'excluded_at' => sanitize_text_field((string) ($details['excluded_at'] ?? '')),
            ];
        }
        return $excluded;
    }

    public static function exclude($dash_id, $season_record = []) {
        $dash_id = absint($dash_id);
        if (!$dash_id) return false;

        $attrs = self::attributes($season_record);
        $excluded = self::excluded();
        $excluded[$dash_id] = [
            'name' => sanitize_text_field((string) ($attrs['name'] ?? 'Dash Season #' . $dash_id)),
            'excluded_at' => current_time('mysql'),
        ];
        update_option(self::EXCLUDED_OPTION, $excluded, false);
        $family = IFPROG_Monitoring::family_for_season_name($excluded[$dash_id]['name']);
        IFPROG_Audit::record(
            'season_excluded',
            ($excluded[$dash_id]['name'] ?? 'Dash Season #' . $dash_id) . ' was marked Never Import.',
            [
                'source' => 'discovery',
                'severity' => 'info',
                'dash_season_id' => $dash_id,
                'family' => $family['label'] ?? '',
            ]
        );
        return true;
    }

    public static function restore($dash_id) {
        $dash_id = absint($dash_id);
        if (!$dash_id) return false;

        $excluded = self::excluded();
        if (!isset($excluded[$dash_id])) return false;
        $name = $excluded[$dash_id]['name'] ?? 'Dash Season #' . $dash_id;
        unset($excluded[$dash_id]);
        update_option(self::EXCLUDED_OPTION, $excluded, false);
        $family = IFPROG_Monitoring::family_for_season_name($name);
        IFPROG_Audit::record(
            'season_restored',
            $name . ' was restored to discovery.',
            [
                'source' => 'discovery',
                'severity' => 'success',
                'dash_season_id' => $dash_id,
                'family' => $family['label'] ?? '',
            ]
        );
        return true;
    }

    public static function cached_checks() {
        $checks = get_transient(self::CHECKS_TRANSIENT);
        return is_array($checks) ? $checks : [];
    }

    public static function has_cached_checks() {
        return get_transient(self::CHECKS_TRANSIENT) !== false;
    }

    /**
     * Compare imported Current and Upcoming Seasons to their last successful
     * sync snapshot. New Seasons do not require the heavier hierarchy lookup.
     */
    public static function refresh($seasons, $force = true, $force_shared = null) {
        $existing = self::existing_seasons();
        $excluded = self::excluded();
        $checks = self::cached_checks();
        $force_shared = $force_shared === null ? (bool) $force : (bool) $force_shared;

        foreach ((array) $seasons as $season_record) {
            $dash_id = absint($season_record['id'] ?? 0);
            $wp_id = absint($existing[$dash_id] ?? 0);
            if (
                !$dash_id ||
                !$wp_id ||
                isset($excluded[$dash_id]) ||
                !in_array(self::lifecycle($season_record), ['current','upcoming'], true)
            ) {
                continue;
            }

            $preview = IFPROG_Preview::build($season_record, (bool) $force, $force_shared);
            $force_shared = false;
            if (is_wp_error($preview)) {
                $checks[$dash_id] = [
                    'error' => $preview->get_error_message(),
                    'checked_at' => current_time('mysql'),
                ];
                continue;
            }

            $checks[$dash_id] = self::comparison_check($wp_id, $preview);
        }

        set_transient(self::CHECKS_TRANSIENT, $checks, 15 * MINUTE_IN_SECONDS);
        return $checks;
    }

    public static function record_comparison($wp_season_id, $preview) {
        if (!is_array($preview)) return [];
        $wp_season_id = absint($wp_season_id);
        $dash_id = absint($preview['season_id'] ?? 0);
        if (!$wp_season_id || !$dash_id) return [];

        $checks = self::cached_checks();
        $checks[$dash_id] = self::comparison_check($wp_season_id, $preview);
        set_transient(self::CHECKS_TRANSIENT, $checks, 15 * MINUTE_IN_SECONDS);
        return $checks;
    }

    private static function comparison_check($wp_season_id, $preview) {
        $snapshot = self::snapshot($preview);
        $baseline_created = false;
        if (!self::saved_snapshot($wp_season_id)) {
            self::store_snapshot($wp_season_id, $snapshot);
            $baseline_created = true;
        }
        update_post_meta($wp_season_id, self::LAST_CHECKED_KEY, current_time('mysql'));
        return [
            'snapshot' => $snapshot,
            'hash' => self::snapshot_hash($snapshot),
            'checked_at' => current_time('mysql'),
            'baseline_created' => $baseline_created ? 1 : 0,
        ];
    }

    public static function save_sync_snapshot($wp_season_id, $preview) {
        $wp_season_id = absint($wp_season_id);
        if (!$wp_season_id || !is_array($preview)) return;

        $snapshot = self::snapshot($preview);
        self::store_snapshot($wp_season_id, $snapshot);
        update_post_meta($wp_season_id, self::LAST_CHECKED_KEY, current_time('mysql'));

        $dash_id = absint(get_post_meta($wp_season_id, '_ifprog_dash_season_id', true));
        if (!$dash_id) return;

        $checks = self::cached_checks();
        $checks[$dash_id] = [
            'snapshot' => $snapshot,
            'hash' => self::snapshot_hash($snapshot),
            'checked_at' => current_time('mysql'),
            'baseline_created' => 0,
        ];
        set_transient(self::CHECKS_TRANSIENT, $checks, 15 * MINUTE_IN_SECONDS);
    }

    public static function rows($seasons, $checks = []) {
        $existing = self::existing_seasons();
        $excluded = self::excluded();
        $protected_counts = self::protected_field_counts(array_values($existing));
        $rows = [];
        $seen = [];

        foreach ((array) $seasons as $season_record) {
            $dash_id = absint($season_record['id'] ?? 0);
            if (!$dash_id) continue;
            $seen[$dash_id] = true;

            $attrs = self::attributes($season_record);
            $wp_id = absint($existing[$dash_id] ?? 0);
            $is_excluded = isset($excluded[$dash_id]);
            $saved_snapshot = $wp_id ? self::saved_snapshot($wp_id) : [];
            $current_snapshot = is_array($checks[$dash_id]['snapshot'] ?? null)
                ? $checks[$dash_id]['snapshot']
                : [];
            $changed = $wp_id && $saved_snapshot && $current_snapshot &&
                !hash_equals(self::snapshot_hash($saved_snapshot), self::snapshot_hash($current_snapshot));
            $state = $is_excluded ? 'excluded' : ($wp_id ? ($changed ? 'changed' : 'imported') : 'new');

            $rows[] = [
                'dash_id' => $dash_id,
                'record' => $season_record,
                'name' => sanitize_text_field((string) ($attrs['name'] ?? 'Dash Season #' . $dash_id)),
                'start_date' => self::date_only($attrs['start_date'] ?? ''),
                'end_date' => self::date_only($attrs['end_date'] ?? ''),
                'registration_open' => self::datetime_local($attrs['signup_start'] ?? ''),
                'registration_close' => self::datetime_local($attrs['signup_end'] ?? ''),
                'lifecycle' => self::lifecycle($season_record),
                'state' => $state,
                'wp_id' => $wp_id,
                'wp_status' => $wp_id ? sanitize_key((string) get_post_status($wp_id)) : '',
                'wp_lifecycle' => $wp_id ? IFPROG_Status::effective_season_status($wp_id) : '',
                'last_sync' => $wp_id ? sanitize_text_field((string) get_post_meta($wp_id, '_ifprog_dash_last_sync', true)) : '',
                'protected_fields' => absint($protected_counts[$wp_id] ?? 0),
                'changes' => $changed ? self::diff_summary($saved_snapshot, $current_snapshot) : [],
                'check_error' => sanitize_text_field((string) ($checks[$dash_id]['error'] ?? '')),
                'checked_at' => sanitize_text_field((string) (
                    $checks[$dash_id]['checked_at'] ??
                    ($wp_id ? get_post_meta($wp_id, self::LAST_CHECKED_KEY, true) : '')
                )),
                'baseline_created' => !empty($checks[$dash_id]['baseline_created']),
                'excluded_at' => sanitize_text_field((string) ($excluded[$dash_id]['excluded_at'] ?? '')),
            ];
        }

        // Keep every exclusion reversible even if Dash temporarily omits the
        // Season from its collection response.
        foreach ($excluded as $dash_id => $details) {
            if (isset($seen[$dash_id])) continue;
            $wp_id = absint($existing[$dash_id] ?? 0);
            $rows[] = [
                'dash_id' => absint($dash_id),
                'record' => [],
                'name' => sanitize_text_field((string) ($details['name'] ?? 'Dash Season #' . $dash_id)),
                'start_date' => '',
                'end_date' => '',
                'registration_open' => '',
                'registration_close' => '',
                'lifecycle' => 'unknown',
                'state' => 'excluded',
                'wp_id' => $wp_id,
                'wp_status' => $wp_id ? sanitize_key((string) get_post_status($wp_id)) : '',
                'wp_lifecycle' => $wp_id ? IFPROG_Status::effective_season_status($wp_id) : '',
                'last_sync' => $wp_id ? sanitize_text_field((string) get_post_meta($wp_id, '_ifprog_dash_last_sync', true)) : '',
                'protected_fields' => absint($protected_counts[$wp_id] ?? 0),
                'changes' => [],
                'check_error' => '',
                'checked_at' => '',
                'baseline_created' => false,
                'excluded_at' => sanitize_text_field((string) ($details['excluded_at'] ?? '')),
            ];
        }

        usort($rows, function($a, $b) {
            $state_order = ['new' => 0, 'changed' => 1, 'imported' => 2, 'excluded' => 3];
            $a_state_order = $state_order[$a['state']] ?? 4;
            $b_state_order = $state_order[$b['state']] ?? 4;
            if ($a_state_order !== $b_state_order) return $a_state_order <=> $b_state_order;

            $order = ['current' => 0, 'upcoming' => 1, 'completed' => 2, 'unknown' => 3];
            $a_order = $order[$a['lifecycle']] ?? 3;
            $b_order = $order[$b['lifecycle']] ?? 3;
            if ($a_order !== $b_order) return $a_order <=> $b_order;

            $a_start = IFPROG_Status::timestamp($a['start_date']);
            $b_start = IFPROG_Status::timestamp($b['start_date']);
            if ($a['lifecycle'] === 'completed') {
                if ($a_start !== $b_start) return $b_start <=> $a_start;
            } elseif ($a_start !== $b_start) {
                return $a_start <=> $b_start;
            }
            return strcasecmp($a['name'], $b['name']);
        });
        return $rows;
    }

    public static function lifecycle($season_record) {
        $attrs = self::attributes($season_record);
        $now = current_datetime()->getTimestamp();
        $start = IFPROG_Status::timestamp($attrs['start_date'] ?? '');
        $end = IFPROG_Status::timestamp($attrs['end_date'] ?? '', true);

        if ($end && $now > $end) return 'completed';
        if ($start && $now < $start) return 'upcoming';
        if ($start || $end) return 'current';
        return 'unknown';
    }

    public static function snapshot($preview) {
        $season = is_array($preview['season'] ?? null) ? $preview['season'] : [];
        $levels = [];
        $programs = [];

        foreach ((array) ($preview['rows'] ?? []) as $row) {
            $league_id = absint($row['league_id'] ?? 0);
            if ($league_id) {
                $level_facts = [
                    'name' => sanitize_text_field((string) ($row['level'] ?? '')),
                    'description' => self::content_hash($row['level_description'] ?? ''),
                    'age_range' => sanitize_text_field((string) ($row['age_range'] ?? '')),
                    'registration_url' => esc_url_raw((string) ($row['level_registration_url'] ?? '')),
                ];
                if (!isset($levels[$league_id]) || $level_facts['registration_url'] !== '') {
                    $levels[$league_id] = self::fact_hash($level_facts);
                }
            }

            $team_id = absint($row['team_id'] ?? 0);
            if (!$team_id) continue;
            $price = is_array($row['session_price'] ?? null) ? $row['session_price'] : [];
            $program_facts = [
                'league_id' => $league_id,
                'name' => sanitize_text_field((string) ($row['title'] ?? '')),
                'description' => self::content_hash($row['description'] ?? ''),
                'sport' => sanitize_title((string) ($row['sport']['slug'] ?? '')),
                'format' => sanitize_title((string) ($row['format'] ?? '')),
                'schedule' => sanitize_text_field((string) ($row['schedule'] ?? '')),
                'start_date' => self::date_only($row['start_date'] ?? ''),
                'end_date' => self::date_only($row['end_date'] ?? ''),
                'registration_open' => self::datetime_local($row['registration_open'] ?? ''),
                'registration_close' => self::datetime_local($row['registration_close'] ?? ''),
                'age_range' => sanitize_text_field((string) ($row['age_range'] ?? '')),
                'price' => sanitize_text_field((string) ($price['total'] ?? '')),
                'weeks' => absint($price['weeks'] ?? 0),
                'registration_url' => esc_url_raw((string) ($row['registration_url'] ?? '')),
                'registration_status' => sanitize_key((string) ($row['registration_status'] ?? '')),
                'eligible' => !empty($row['eligible']) ? 1 : 0,
            ];
            $programs[$team_id] = self::fact_hash($program_facts);
        }

        ksort($levels, SORT_NUMERIC);
        ksort($programs, SORT_NUMERIC);
        return [
            'season' => [
                'name' => sanitize_text_field((string) ($season['name'] ?? '')),
                'description' => self::content_hash($season['description'] ?? ''),
                'start_date' => self::date_only($season['start_date'] ?? ''),
                'end_date' => self::date_only($season['end_date'] ?? ''),
                'registration_open' => self::datetime_local($season['signup_start'] ?? ''),
                'registration_close' => self::datetime_local($season['signup_end'] ?? ''),
                'program_id' => absint($season['program_id'] ?? 0),
                'facility_id' => absint($season['facility_id'] ?? 0),
                'sport_id' => absint($season['sport_id'] ?? 0),
            ],
            'levels' => $levels,
            'programs' => $programs,
        ];
    }

    public static function snapshot_hash($snapshot) {
        return hash('sha256', (string) wp_json_encode((array) $snapshot));
    }

    private static function store_snapshot($wp_season_id, $snapshot) {
        update_post_meta($wp_season_id, self::SNAPSHOT_KEY, $snapshot);
        update_post_meta($wp_season_id, self::SNAPSHOT_HASH_KEY, self::snapshot_hash($snapshot));
    }

    private static function saved_snapshot($wp_season_id) {
        $snapshot = get_post_meta(absint($wp_season_id), self::SNAPSHOT_KEY, true);
        return is_array($snapshot) ? $snapshot : [];
    }

    private static function diff_summary($saved, $current) {
        $changes = [];
        if (($saved['season'] ?? []) !== ($current['season'] ?? [])) {
            $changes[] = 'Season details changed';
        }
        $changes = array_merge(
            $changes,
            self::map_diff_summary($saved['levels'] ?? [], $current['levels'] ?? [], 'Level'),
            self::map_diff_summary($saved['programs'] ?? [], $current['programs'] ?? [], 'class')
        );
        return $changes ?: ['Dash data changed'];
    }

    private static function map_diff_summary($saved, $current, $label) {
        $saved = (array) $saved;
        $current = (array) $current;
        $added = count(array_diff(array_keys($current), array_keys($saved)));
        $removed = count(array_diff(array_keys($saved), array_keys($current)));
        $changed = 0;
        foreach (array_intersect(array_keys($saved), array_keys($current)) as $id) {
            if (!hash_equals((string) $saved[$id], (string) $current[$id])) $changed++;
        }

        $summary = [];
        if ($added) $summary[] = $added . ' ' . self::plural($label, $added) . ' added';
        if ($changed) $summary[] = $changed . ' ' . self::plural($label, $changed) . ' changed';
        if ($removed) $summary[] = $removed . ' ' . self::plural($label, $removed) . ' no longer returned by Dash';
        return $summary;
    }

    private static function plural($label, $count) {
        if ($count === 1) return $label;
        return strtolower($label) === 'class' ? 'classes' : $label . 's';
    }

    private static function existing_seasons() {
        $ids = get_posts([
            'post_type' => 'ifprog_season',
            'post_status' => array_keys(get_post_stati()),
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_query' => [[
                'key' => '_ifprog_dash_season_id',
                'compare' => 'EXISTS',
            ]],
        ]);
        $mapped = [];
        foreach ($ids as $wp_id) {
            $dash_id = absint(get_post_meta($wp_id, '_ifprog_dash_season_id', true));
            if ($dash_id) $mapped[$dash_id] = absint($wp_id);
        }
        return $mapped;
    }

    private static function protected_field_counts($wp_season_ids) {
        $wp_season_ids = array_values(array_filter(array_map('absint', (array) $wp_season_ids)));
        if (!$wp_season_ids) return [];

        $program_ids = get_posts([
            'post_type' => 'ifprog_program',
            'post_status' => array_keys(get_post_stati()),
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_query' => [[
                'key' => IFPROG_Fields::dash_key('season_id'),
                'compare' => 'EXISTS',
            ]],
        ]);
        $counts = [];
        foreach ($program_ids as $program_id) {
            $season_id = absint(get_post_meta($program_id, IFPROG_Fields::dash_key('season_id'), true));
            if (!$season_id || !in_array($season_id, $wp_season_ids, true)) continue;
            if (!isset($counts[$season_id])) $counts[$season_id] = 0;
            $counts[$season_id] += count(IFPROG_Fields::overrides($program_id));
        }
        return $counts;
    }

    private static function attributes($record) {
        return is_array($record['attributes'] ?? null) ? $record['attributes'] : [];
    }

    private static function content_hash($value) {
        $value = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags((string) $value)));
        return $value === '' ? '' : hash('sha256', $value);
    }

    private static function fact_hash($facts) {
        return hash('sha256', (string) wp_json_encode((array) $facts));
    }

    private static function date_only($value) {
        return preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $value, $match) ? $match[0] : '';
    }

    private static function datetime_local($value) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', (string) $value, $match)) return '';
        return $match[0];
    }
}
