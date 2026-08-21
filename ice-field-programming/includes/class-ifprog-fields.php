<?php
if (!defined('ABSPATH')) exit;

/**
 * Resolves a Program's visible values without allowing future Dash syncs to
 * overwrite locally customized presentation.
 */
class IFPROG_Fields {
    const OVERRIDES_KEY = '_ifprog_local_overrides';

    public static function names() {
        return [
            'season_id',
            'level_id',
            'start_date',
            'end_date',
            'registration_open',
            'registration_close',
            'schedule',
            'age_range',
            'level',
            'location',
            'price',
            'weeks',
            'registration_url',
            'button_label',
            'availability_note',
        ];
    }

    public static function get($post_id, $field, $default = '') {
        if (!in_array($field, self::names(), true)) return $default;

        $overrides = self::overrides($post_id);
        if (in_array($field, $overrides, true)) {
            return get_post_meta($post_id, self::local_key($field), true);
        }

        $dash_key = self::dash_key($field);
        if (metadata_exists('post', $post_id, $dash_key)) {
            return get_post_meta($post_id, $dash_key, true);
        }

        $local_key = self::local_key($field);
        if (metadata_exists('post', $post_id, $local_key)) {
            return get_post_meta($post_id, $local_key, true);
        }

        return $default;
    }

    public static function save_local($post_id, $field, $value) {
        if (!in_array($field, self::names(), true)) return false;

        update_post_meta($post_id, self::local_key($field), $value);

        $overrides = self::overrides($post_id);
        $dash_key = self::dash_key($field);
        $has_dash_value = metadata_exists('post', $post_id, $dash_key);
        $dash_value = $has_dash_value ? get_post_meta($post_id, $dash_key, true) : null;
        $is_override = $has_dash_value && (string) $value !== (string) $dash_value;

        if ($is_override && !in_array($field, $overrides, true)) {
            $overrides[] = $field;
        } elseif (!$is_override) {
            $overrides = array_values(array_diff($overrides, [$field]));
        }

        update_post_meta($post_id, self::OVERRIDES_KEY, array_values(array_unique($overrides)));
        return true;
    }

    /**
     * Future importers write source values here. Existing local overrides are
     * left untouched and continue to win in self::get().
     */
    public static function store_dash_values($post_id, $values, $source_type = '', $source_id = '') {
        $overrides = self::overrides($post_id);

        foreach ((array) $values as $field => $value) {
            if (!in_array($field, self::names(), true)) continue;

            $local_key = self::local_key($field);
            $dash_key = self::dash_key($field);
            $has_existing_dash_value = metadata_exists('post', $post_id, $dash_key);
            $has_local_value = metadata_exists('post', $post_id, $local_key);
            $local_value = $has_local_value ? get_post_meta($post_id, $local_key, true) : '';

            // When a manual record is linked for the first time, treat its
            // existing non-empty values as intentional local presentation.
            if (
                !$has_existing_dash_value &&
                $has_local_value &&
                self::has_meaningful_value($field, $local_value) &&
                (string) $local_value !== (string) $value &&
                !in_array($field, $overrides, true)
            ) {
                $overrides[] = $field;
            }

            update_post_meta($post_id, $dash_key, $value);
        }

        update_post_meta($post_id, self::OVERRIDES_KEY, array_values(array_unique($overrides)));

        if ($source_type !== '') {
            update_post_meta($post_id, '_ifprog_dash_source_type', sanitize_key($source_type));
        }
        if ($source_id !== '') {
            update_post_meta($post_id, '_ifprog_dash_source_id', sanitize_text_field((string) $source_id));
        }
        update_post_meta($post_id, '_ifprog_dash_last_sync', current_time('mysql'));

        do_action('ifprog_dash_values_stored', absint($post_id), (array) $values, $source_type, $source_id);
    }

    public static function overrides($post_id) {
        $saved = get_post_meta($post_id, self::OVERRIDES_KEY, true);
        if (!is_array($saved)) return [];
        return array_values(array_intersect(array_map('sanitize_key', $saved), self::names()));
    }

    public static function local_key($field) {
        return '_ifprog_' . sanitize_key($field);
    }

    public static function dash_key($field) {
        return '_ifprog_dash_' . sanitize_key($field);
    }

    private static function has_meaningful_value($field, $value) {
        if (in_array($field, ['season_id','level_id'], true)) return absint($value) > 0;
        return trim((string) $value) !== '';
    }
}
