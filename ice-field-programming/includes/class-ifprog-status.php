<?php
if (!defined('ABSPATH')) exit;

class IFPROG_Status {
    const SEASON_STATUS_KEY = '_ifprog_season_status';
    const PROGRAM_STATUS_KEY = '_ifprog_registration_status';

    public static function season_values() {
        return ['draft','upcoming','current','completed','archived'];
    }

    public static function program_overrides() {
        return ['automatic','draft','coming_soon','open','closed','archived'];
    }

    public static function public_program_states() {
        return ['coming_soon','open','closed'];
    }

    public static function season_status($post_id) {
        $saved = sanitize_key((string) get_post_meta($post_id, self::SEASON_STATUS_KEY, true));
        if (in_array($saved, self::season_values(), true)) return $saved;

        $start = self::timestamp(get_post_meta($post_id, '_ifprog_season_start_date', true));
        $end = self::timestamp(get_post_meta($post_id, '_ifprog_season_end_date', true), true);
        $now = current_datetime()->getTimestamp();

        if ($start && $now < $start) return 'upcoming';
        if ($end && $now > $end) return 'completed';
        return 'upcoming';
    }

    public static function set_season_status($post_id, $status) {
        $post_id = absint($post_id);
        $status = sanitize_key($status);
        if (!$post_id || !in_array($status, self::season_values(), true)) return false;

        update_post_meta($post_id, self::SEASON_STATUS_KEY, $status);
        return true;
    }

    public static function effective_season_status($post_id, $now = null) {
        $status = self::season_status($post_id);
        if (in_array($status, ['draft','archived'], true)) return $status;

        $now = $now === null ? current_datetime()->getTimestamp() : absint($now);
        if (self::season_registration_closed($post_id, $now)) return 'completed';

        $opens = self::timestamp(get_post_meta($post_id, '_ifprog_season_registration_open', true));
        $closes = self::timestamp(get_post_meta($post_id, '_ifprog_season_registration_close', true));
        if ($opens) return $now >= $opens ? 'current' : 'upcoming';
        if ($closes && $now <= $closes) return 'current';
        return $status;
    }

    public static function current_season_ids($published_only = true) {
        $ids = array_map('absint', get_posts([
            'post_type' => 'ifprog_season',
            'post_status' => $published_only ? 'publish' : ['publish','draft','pending','private','future'],
            'posts_per_page' => -1,
            'fields' => 'ids',
            'orderby' => 'date',
            'order' => 'DESC',
        ]));
        return array_values(array_filter($ids, function($season_id) {
            return self::effective_season_status($season_id) === 'current';
        }));
    }

    public static function current_season_id($published_only = true) {
        $ids = self::current_season_ids($published_only);
        return $ids ? $ids[0] : 0;
    }

    public static function program_state($post_id, $now = null) {
        if (get_post_status($post_id) !== 'publish') return 'draft';

        $season_id = absint(IFPROG_Fields::get($post_id, 'season_id'));
        if ($season_id && self::effective_season_status($season_id) === 'completed') return 'closed';

        $override = sanitize_key((string) get_post_meta($post_id, self::PROGRAM_STATUS_KEY, true));
        if ($override !== '' && $override !== 'automatic' && in_array($override, self::program_overrides(), true)) {
            return $override;
        }

        $now = $now === null ? current_datetime()->getTimestamp() : absint($now);
        $opens = self::timestamp(self::program_registration_value($post_id, 'registration_open'));
        $closes = self::timestamp(self::program_registration_value($post_id, 'registration_close'));
        $source_status = sanitize_key((string) get_post_meta($post_id, '_ifprog_dash_registration_status', true));

        if ($opens && $now < $opens) return 'coming_soon';
        if ($closes && $now > $closes) return 'closed';
        if ($source_status === 'closed') return 'closed';
        if ($source_status === 'open') return 'open';
        if ($opens && $now >= $opens) return 'open';
        if (!$opens && $closes && $now <= $closes) return 'open';

        return 'coming_soon';
    }

    public static function season_registration_closed($post_id, $now = null) {
        $closes = self::timestamp(get_post_meta($post_id, '_ifprog_season_registration_close', true));
        if (!$closes) return self::season_status($post_id) === 'completed';
        $now = $now === null ? current_datetime()->getTimestamp() : absint($now);
        return $now > $closes;
    }

    public static function season_registration_opened($post_id, $now = null) {
        $opens = self::timestamp(get_post_meta($post_id, '_ifprog_season_registration_open', true));
        if (!$opens) return false;
        $now = $now === null ? current_datetime()->getTimestamp() : absint($now);
        return $now >= $opens && !self::season_registration_closed($post_id, $now);
    }

    public static function program_registration_value($post_id, $field) {
        if (!in_array($field, ['registration_open','registration_close'], true)) return '';

        $value = IFPROG_Fields::get($post_id, $field);
        if ($value !== '') return $value;

        $season_id = absint(IFPROG_Fields::get($post_id, 'season_id'));
        if (!$season_id || get_post_type($season_id) !== 'ifprog_season') return '';

        return (string) get_post_meta($season_id, '_ifprog_season_' . $field, true);
    }

    public static function state_label($state) {
        $settings = IFPROG_Admin::settings();
        $labels = [
            'draft' => 'Draft',
            'coming_soon' => $settings['coming_soon_label'],
            'open' => $settings['open_label'],
            'closed' => $settings['closed_label'],
            'archived' => 'Archived',
        ];
        return $labels[$state] ?? ucwords(str_replace('_', ' ', (string) $state));
    }

    public static function timestamp($value, $end_of_day = false) {
        $value = trim((string) $value);
        if ($value === '') return 0;

        if ($end_of_day && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $value .= ' 23:59:59';
        }

        try {
            $date = new DateTimeImmutable($value, wp_timezone());
            return $date->getTimestamp();
        } catch (Exception $e) {
            return 0;
        }
    }
}
