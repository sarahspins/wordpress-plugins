<?php
if (!defined('ABSPATH')) exit;

/**
 * Projects a clean Programming sync into the Production-specific companion
 * hierarchy without issuing a second Seasons/Leagues/Teams Dash import.
 */
class IFP_Programming_Projection {
    public static function init() {
        add_filter('ifprog_production_companion_preview', [__CLASS__, 'preview'], 10, 2);
        add_action('ifprog_after_successful_sync', [__CLASS__, 'sync'], 10, 3);
    }

    public static function preview($plan, $preview) {
        $season_id = absint($preview['existing_season_id'] ?? 0);
        if (!$season_id || get_post_meta($season_id, '_ifprog_is_production', true) !== '1') return $plan;
        $production_id = absint(get_post_meta($season_id, '_ifprog_production_id', true));
        $eligible = count(array_filter((array) ($preview['rows'] ?? []), function($row) { return !empty($row['eligible']); }));
        return [
            'enabled' => true,
            'production_id' => $production_id,
            'message' => ($production_id ? 'Update “' . get_the_title($production_id) . '”' : 'Create a draft companion Production') .
                ' and project up to ' . $eligible . ' selected Level/Program record(s) after the Programming sync succeeds.',
        ];
    }

    public static function sync($result, $preview, $options = []) {
        $season_id = absint($result['season_id'] ?? 0);
        if (!$season_id || get_post_type($season_id) !== 'ifprog_season') return;
        if (get_post_meta($season_id, '_ifprog_is_production', true) !== '1') return;

        $warnings = [];
        $production_id = self::production($season_id, $preview, $warnings);
        if (!$production_id) {
            self::store_result($season_id, 0, ['status' => 'failed', 'warnings' => $warnings]);
            return;
        }

        $division_map = [];
        $divisions_created = 0;
        $divisions_updated = 0;
        foreach (array_values(array_unique(array_filter(array_map('absint', (array) ($result['synced_level_ids'] ?? []))))) as $level_id) {
            $division = self::division($level_id, $production_id, $season_id, $warnings);
            if (!$division) continue;
            $division_map[$level_id] = $division['post_id'];
            ${'divisions_' . $division['action']}++;
        }

        $groups_created = 0;
        $groups_updated = 0;
        $participant_count = 0;
        foreach (array_values(array_unique(array_filter(array_map('absint', (array) ($result['synced_program_ids'] ?? []))))) as $program_id) {
            $level_id = self::programming_value($program_id, 'level_id');
            if (!$level_id) continue;
            if (!isset($division_map[$level_id])) {
                $division = self::division($level_id, $production_id, $season_id, $warnings);
                if (!$division) continue;
                $division_map[$level_id] = $division['post_id'];
                ${'divisions_' . $division['action']}++;
            }
            $group = self::group($program_id, $production_id, $division_map[$level_id], $warnings);
            if (!$group) continue;
            ${'groups_' . $group['action']}++;
            if (!empty($options['sync_participants']) && class_exists('IFP_Dash_Integration')) {
                $synced = IFP_Dash_Integration::sync_group_participants($group['post_id'], true);
                if (is_wp_error($synced)) $warnings[] = get_the_title($group['post_id']) . ': ' . $synced->get_error_message();
                else $participant_count += absint($synced['count'] ?? 0);
            }
        }

        $projection = [
            'status' => $warnings ? 'completed_with_warnings' : 'completed',
            'production_id' => $production_id,
            'divisions_created' => $divisions_created,
            'divisions_updated' => $divisions_updated,
            'groups_created' => $groups_created,
            'groups_updated' => $groups_updated,
            'participants_synchronized' => $participant_count,
            'warnings' => $warnings,
            'completed_at' => current_time('mysql'),
        ];
        self::store_result($season_id, $production_id, $projection);
        do_action('ifp_programming_projection_completed', $projection, $result, $preview);
    }

    private static function production($season_id, $preview, &$warnings) {
        $dash_id = absint(get_post_meta($season_id, '_ifprog_dash_season_id', true));
        $production_id = absint(get_post_meta($season_id, '_ifprog_production_id', true));
        if ($production_id && get_post_type($production_id) !== 'ifp_production') $production_id = 0;
        if (!$production_id && $dash_id) $production_id = self::find('ifp_production', '_ifp_dash_season_id', $dash_id);
        $is_new = !$production_id;
        if ($is_new) {
            $production_id = wp_insert_post([
                'post_type' => 'ifp_production',
                'post_status' => 'draft',
                'post_title' => sanitize_text_field(get_the_title($season_id)),
                'post_content' => wp_kses_post(get_post_field('post_content', $season_id)),
            ], true);
            if (is_wp_error($production_id)) {
                $warnings[] = 'Production could not be created: ' . $production_id->get_error_message();
                return 0;
            }
            IFP_Production_Status::set($production_id, 'upcoming');
        }

        update_post_meta($production_id, '_ifp_programming_season_id', $season_id);
        update_post_meta($season_id, '_ifprog_production_id', $production_id);
        update_post_meta($production_id, '_ifp_dash_season_id', $dash_id);
        update_post_meta($production_id, '_ifp_dash_last_sync', current_time('mysql'));
        update_post_meta($production_id, '_ifp_dash_season_payload', (array) ($preview['season'] ?? []));
        self::fill_empty($production_id, '_ifp_opening_date', get_post_meta($season_id, '_ifprog_season_start_date', true));
        self::fill_empty($production_id, '_ifp_closing_date', get_post_meta($season_id, '_ifprog_season_end_date', true));
        self::fill_empty($production_id, '_ifp_registration_open', get_post_meta($season_id, '_ifprog_season_registration_open', true));
        self::fill_empty($production_id, '_ifp_registration_close', get_post_meta($season_id, '_ifprog_season_registration_close', true));
        $season_url = get_post_meta($season_id, '_ifprog_registration_url', true);
        if ($season_url === '') $season_url = get_post_meta($season_id, '_ifprog_dash_registration_url', true);
        self::sync_url($production_id, $season_url);
        return absint($production_id);
    }

    private static function division($level_id, $production_id, $season_id, &$warnings) {
        if (get_post_type($level_id) !== 'ifprog_level') return null;
        $dash_id = absint(get_post_meta($level_id, '_ifprog_dash_league_id', true));
        $post_id = absint(get_post_meta($level_id, '_ifprog_division_id', true));
        if ($post_id && get_post_type($post_id) !== 'ifp_division') $post_id = 0;
        if (!$post_id && $dash_id) $post_id = self::find('ifp_division', '_ifp_dash_league_id', $dash_id);
        if ($post_id) {
            $parent = absint(get_post_meta($post_id, '_ifp_division_production_id', true));
            if ($parent && $parent !== $production_id) {
                $warnings[] = get_the_title($level_id) . ' already resolves to a Division in another Production.';
                return null;
            }
        }
        $is_new = !$post_id;
        if ($is_new) {
            $post_id = wp_insert_post(['post_type'=>'ifp_division','post_status'=>'draft','post_title'=>sanitize_text_field(get_the_title($level_id))], true);
            if (is_wp_error($post_id)) { $warnings[] = get_the_title($level_id) . ': ' . $post_id->get_error_message(); return null; }
        }
        update_post_meta($post_id, '_ifp_division_production_id', $production_id);
        update_post_meta($post_id, '_ifp_programming_level_id', $level_id);
        update_post_meta($level_id, '_ifprog_division_id', $post_id);
        update_post_meta($post_id, '_ifp_dash_league_id', $dash_id);
        update_post_meta($post_id, '_ifp_dash_season_id', absint(get_post_meta($season_id, '_ifprog_dash_season_id', true)));
        update_post_meta($post_id, '_ifp_dash_last_sync', current_time('mysql'));
        update_post_meta($post_id, '_ifp_dash_league_payload', get_post_meta($level_id, '_ifprog_dash_payload', true));
        $level_url = get_post_meta($level_id, '_ifprog_registration_url', true);
        if ($level_url === '') $level_url = get_post_meta($level_id, '_ifprog_dash_registration_url', true);
        self::sync_url($post_id, $level_url);
        return ['post_id'=>absint($post_id), 'action'=>$is_new?'created':'updated'];
    }

    private static function group($program_id, $production_id, $division_id, &$warnings) {
        if (get_post_type($program_id) !== 'ifprog_program') return null;
        $team_id = absint(get_post_meta($program_id, '_ifprog_dash_source_id', true));
        if (!$team_id) return null;
        $post_id = absint(get_post_meta($program_id, '_ifprog_group_id', true));
        if ($post_id && get_post_type($post_id) !== 'ifp_group') $post_id = 0;
        if (!$post_id) $post_id = self::find('ifp_group', '_ifp_dash_team_id', $team_id);
        if ($post_id) {
            $parent = absint(get_post_meta($post_id, '_ifp_group_production_id', true));
            if ($parent && $parent !== $production_id) {
                $warnings[] = get_the_title($program_id) . ' already resolves to a Group in another Production.';
                return null;
            }
        }
        $is_new = !$post_id;
        if ($is_new) {
            $post_id = wp_insert_post([
                'post_type'=>'ifp_group', 'post_status'=>'draft',
                'post_title'=>sanitize_text_field(get_the_title($program_id)),
                'post_excerpt'=>sanitize_text_field(get_post_field('post_excerpt', $program_id)),
            ], true);
            if (is_wp_error($post_id)) { $warnings[] = get_the_title($program_id) . ': ' . $post_id->get_error_message(); return null; }
            update_post_meta($post_id, '_ifp_group_visibility', 'internal');
            update_post_meta($post_id, '_ifp_group_type', self::group_type(get_the_title($program_id)));
        }
        $payload = get_post_meta($program_id, '_ifprog_dash_payload', true);
        $team = is_array($payload['team'] ?? null) ? $payload['team'] : [];
        update_post_meta($post_id, '_ifp_group_production_id', $production_id);
        update_post_meta($post_id, '_ifp_group_division_id', $division_id);
        update_post_meta($post_id, '_ifp_programming_program_id', $program_id);
        update_post_meta($program_id, '_ifprog_group_id', $post_id);
        update_post_meta($post_id, '_ifp_dash_team_id', $team_id);
        update_post_meta($post_id, '_ifp_dash_league_id', absint($team['league_id'] ?? 0));
        update_post_meta($post_id, '_ifp_dash_season_id', absint($team['season_id'] ?? 0));
        update_post_meta($post_id, '_ifp_dash_product_id', absint($team['product_id'] ?? 0));
        update_post_meta($post_id, '_ifp_dash_status', sanitize_text_field($team['status'] ?? ''));
        update_post_meta($post_id, '_ifp_dash_payload', $payload);
        update_post_meta($post_id, '_ifp_dash_last_sync', current_time('mysql'));
        self::sync_url($post_id, self::programming_value($program_id, 'registration_url'));
        return ['post_id'=>absint($post_id), 'action'=>$is_new?'created':'updated'];
    }

    private static function sync_url($post_id, $url) {
        $url = esc_url_raw((string) $url);
        $local = esc_url_raw((string) get_post_meta($post_id, '_ifp_registration_url', true));
        $previous = esc_url_raw((string) get_post_meta($post_id, '_ifp_dash_registration_url', true));
        if ($local === '' || $local === $previous) update_post_meta($post_id, '_ifp_registration_url', $url);
        update_post_meta($post_id, '_ifp_dash_registration_url', $url);
    }

    private static function fill_empty($post_id, $key, $value) {
        if (get_post_meta($post_id, $key, true) === '' && $value !== '') update_post_meta($post_id, $key, sanitize_text_field($value));
    }

    private static function find($type, $key, $value) {
        $ids = get_posts(['post_type'=>$type,'post_status'=>'any','posts_per_page'=>1,'fields'=>'ids','meta_key'=>$key,'meta_value'=>$value]);
        return $ids ? absint($ids[0]) : 0;
    }

    private static function programming_value($post_id, $field) {
        if (class_exists('IFPROG_Fields')) return IFPROG_Fields::get($post_id, $field);
        foreach (['_ifprog_dash_'.$field, '_ifprog_'.$field] as $key) if (metadata_exists('post',$post_id,$key)) return get_post_meta($post_id,$key,true);
        return '';
    }

    private static function group_type($name) {
        $name = strtolower((string) $name);
        foreach (['large group'=>'Large Group','small group'=>'Small Group','solo'=>'Soloist','duet'=>'Duet','trio'=>'Trio','ensemble'=>'Ensemble','opening'=>'Opening Number','closing'=>'Closing Number'] as $needle=>$label) {
            if (strpos($name,$needle)!==false) return $label;
        }
        return 'Other';
    }

    private static function store_result($season_id, $production_id, $result) {
        update_post_meta($season_id, '_ifprog_production_projection_result', $result);
        if ($production_id) update_post_meta($production_id, '_ifp_programming_projection_result', $result);
    }
}
