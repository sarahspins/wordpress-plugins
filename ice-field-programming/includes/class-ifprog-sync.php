<?php
if (!defined('ABSPATH')) exit;

/**
 * Manually triggered synchronization with an explicit publication option.
 *
 * Dash facts are stored through IFPROG_Fields so local overrides continue to
 * win. Existing WordPress titles are replaced only through explicit per-sync
 * options. Descriptions use a three-way source comparison so Dash changes,
 * including removals, apply only while the WordPress copy still matches the
 * last Dash description that was adopted. Visibility, ordering, taxonomies,
 * links, and button wording remain protected.
 */
class IFPROG_Sync {
    const CURRENT_LEARN_TO_PLAY_LEAGUE_ID = 86;
    const PRESENTATION_DESCRIPTION_KEY = '_ifprog_dash_presentation_description';

    public static function run(
        $preview,
        $team_ids,
        $publish_imported = false,
        $season_only = false,
        $level_ids = [],
        $presentation_updates = [],
        $companion_options = []
    ) {
        if (!current_user_can('manage_options')) {
            return new WP_Error('ifprog_sync_forbidden', 'You do not have permission to synchronize Programming records.');
        }

        $preview_rows = array_values((array) ($preview['rows'] ?? []));
        $season_only = (bool) $season_only;
        if ($season_only && $preview_rows) {
            return new WP_Error(
                'ifprog_sync_season_only_unavailable',
                'Season-only import is available only when the selected Dash Season has no Team or standalone Level records.'
            );
        }

        $selected_teams = array_values(array_unique(array_filter(array_map('absint', (array) $team_ids))));
        $selected_levels = array_values(array_unique(array_filter(array_map('absint', (array) $level_ids))));
        if (!$season_only && !$selected_teams && !$selected_levels) {
            return new WP_Error('ifprog_sync_empty', 'Select at least one eligible Team or standalone Level to import.');
        }

        $available_teams = [];
        $available_levels = [];
        foreach ($preview_rows as $row) {
            if (($row['row_type'] ?? 'team') === 'level') {
                $league_id = absint($row['league_id'] ?? 0);
                if ($league_id) $available_levels[$league_id] = $row;
            } else {
                $team_id = absint($row['team_id'] ?? 0);
                if ($team_id) $available_teams[$team_id] = $row;
            }
        }

        $eligible_rows = [];
        $skipped = 0;
        if (!$season_only) {
            foreach ($selected_teams as $team_id) {
                $row = $available_teams[$team_id] ?? null;
                if (!$row || empty($row['eligible'])) {
                    $skipped++;
                    continue;
                }
                $eligible_rows[] = $row;
            }
            foreach ($selected_levels as $league_id) {
                $row = $available_levels[$league_id] ?? null;
                if (!$row || empty($row['eligible'])) {
                    $skipped++;
                    continue;
                }
                $eligible_rows[] = $row;
            }
            if (!$eligible_rows) {
                return new WP_Error('ifprog_sync_no_eligible_rows', 'None of the selected Teams or standalone Levels are eligible to import.');
            }
        }

        $publish_imported = (bool) $publish_imported;
        $presentation_updates = self::presentation_updates($presentation_updates);
        $season_result = self::sync_season(
            $preview,
            $publish_imported,
            $presentation_updates['season_title'],
            $presentation_updates['season_description']
        );
        if (is_wp_error($season_result)) return $season_result;

        $result = [
            'season_id' => $season_result['post_id'],
            'season_action' => $season_result['action'],
            'levels_created' => 0,
            'levels_updated' => 0,
            'created' => 0,
            'updated' => 0,
            'season_title_updated' => absint($season_result['title_updated'] ?? 0),
            'season_description_updated' => absint($season_result['description_updated'] ?? 0),
            'level_titles_updated' => 0,
            'level_descriptions_updated' => 0,
            'program_titles_updated' => 0,
            'program_descriptions_updated' => 0,
            'updates_requested' => $presentation_updates,
            'published' => absint($season_result['published'] ?? 0),
            'skipped' => $skipped,
            'errors' => [],
            'season_only' => $season_only,
            'synced_level_ids' => [],
            'synced_program_ids' => [],
        ];
        $level_cache = [];
        foreach ($eligible_rows as $row) {
            $league_id = absint($row['league_id'] ?? 0);
            if (!array_key_exists($league_id, $level_cache)) {
                $level_cache[$league_id] = self::sync_level(
                    $row,
                    $season_result['post_id'],
                    $publish_imported,
                    $presentation_updates['level_titles'],
                    $presentation_updates['level_descriptions']
                );
                if (is_wp_error($level_cache[$league_id])) {
                    $result['errors'][] = $level_cache[$league_id]->get_error_message();
                } else {
                    $level_key = $level_cache[$league_id]['action'] === 'created'
                        ? 'levels_created'
                        : 'levels_updated';
                    $result[$level_key]++;
                    $result['synced_level_ids'][] = absint($level_cache[$league_id]['post_id']);
                    $result['level_titles_updated'] += absint($level_cache[$league_id]['title_updated'] ?? 0);
                    $result['level_descriptions_updated'] += absint($level_cache[$league_id]['description_updated'] ?? 0);
                    $result['published'] += absint($level_cache[$league_id]['published'] ?? 0);
                }
            }
            if (is_wp_error($level_cache[$league_id])) {
                continue;
            }
            if (($row['row_type'] ?? 'team') === 'level') {
                continue;
            }

            $program_result = self::sync_program(
                $row,
                $level_cache[$league_id]['season_id'],
                $level_cache[$league_id]['post_id'],
                $publish_imported,
                !empty($level_cache[$league_id]['routed']),
                $presentation_updates['program_titles'],
                $presentation_updates['program_descriptions'],
                !empty($level_cache[$league_id]['title_updated'])
            );
            if (is_wp_error($program_result)) {
                $result['errors'][] = $program_result->get_error_message();
                continue;
            }
            $result[$program_result['action'] === 'created' ? 'created' : 'updated']++;
            $result['synced_program_ids'][] = absint($program_result['post_id']);
            $result['program_titles_updated'] += absint($program_result['title_updated'] ?? 0);
            $result['program_descriptions_updated'] += absint($program_result['description_updated'] ?? 0);
            $result['published'] += absint($program_result['published'] ?? 0);
        }

        if (!$result['errors']) {
            IFPROG_Discovery::save_sync_snapshot($result['season_id'], $preview);

            /**
             * Fires after Programming has completed a clean, protected Dash sync.
             * Companion plugins may inspect the normalized result without making
             * Programming depend on those plugins being active.
             *
             * @param array $result  Synchronization counts and WordPress IDs.
             * @param array $preview Normalized Dash preview used for the sync.
             */
            $result['synced_level_ids'] = array_values(array_unique(array_filter($result['synced_level_ids'])));
            $result['synced_program_ids'] = array_values(array_unique(array_filter($result['synced_program_ids'])));
            delete_post_meta($result['season_id'], '_ifprog_production_projection_result');
            do_action('ifprog_after_successful_sync', $result, $preview, (array) $companion_options);
            $projection = get_post_meta($result['season_id'], '_ifprog_production_projection_result', true);
            if (is_array($projection) && !empty($projection['completed_at'])) {
                $result['production_projection'] = $projection;
            }
        }

        $season_name = sanitize_text_field((string) ($preview['season']['name'] ?? 'Dash Season')) ?: 'Dash Season';
        $family = IFPROG_Monitoring::family_for_season_name($season_name);
        IFPROG_Audit::record(
            $result['errors'] ? 'sync_completed_with_warnings' : 'sync_completed',
            'Protected sync completed for ' . $season_name . '.',
            [
                'source' => 'sync',
                'severity' => $result['errors'] ? 'warning' : 'success',
                'dash_season_id' => absint($preview['season_id'] ?? 0),
                'season_id' => absint($result['season_id']),
                'family' => $family['label'] ?? '',
                'context' => [
                    'season' => $result['season_action'],
                    'levels_created' => $result['levels_created'],
                    'levels_updated' => $result['levels_updated'],
                    'classes_created' => $result['created'],
                    'classes_updated' => $result['updated'],
                    'skipped' => $result['skipped'],
                    'warnings' => count($result['errors']),
                    'published' => $result['published'],
                ],
            ]
        );

        return $result;
    }

    private static function sync_season(
        $preview,
        $publish_imported = false,
        $update_title = false,
        $update_description = false
    ) {
        $dash_season_id = absint($preview['season_id'] ?? 0);
        $season = is_array($preview['season'] ?? null) ? $preview['season'] : [];
        if (!$dash_season_id || !$season) {
            return new WP_Error('ifprog_sync_invalid_season', 'The selected Dash Season could not be synchronized.');
        }

        $post_id = self::find_season($dash_season_id);
        $is_new = !$post_id;

        if ($is_new) {
            $title = sanitize_text_field((string) ($season['name'] ?? 'Dash Season #' . $dash_season_id));
            $post_id = wp_insert_post([
                'post_type' => 'ifprog_season',
                'post_status' => 'draft',
                'post_title' => $title,
                'post_content' => wp_kses_post((string) ($season['description'] ?? '')),
                'post_author' => get_current_user_id(),
            ], true);
            if (is_wp_error($post_id)) {
                return new WP_Error('ifprog_sync_season_create', 'Season could not be created: ' . $post_id->get_error_message());
            }

            $status = self::season_lifecycle($season);
            update_post_meta($post_id, IFPROG_Status::SEASON_STATUS_KEY, $status);
            update_post_meta(
                $post_id,
                self::PRESENTATION_DESCRIPTION_KEY,
                wp_kses_post((string) ($season['description'] ?? ''))
            );
        }

        $presentation_result = ['title_updated' => false, 'description_updated' => false];
        if (!$is_new) {
            $previous_description = self::previous_dash_description($post_id, 'season');
            $presentation_result = self::update_post_presentation(
                $post_id,
                $season['name'] ?? '',
                $season['description'] ?? '',
                $update_title,
                $update_description,
                false,
                $previous_description
            );
            if (is_wp_error($presentation_result)) return $presentation_result;
        }

        update_post_meta($post_id, '_ifprog_dash_season_id', $dash_season_id);
        update_post_meta($post_id, '_ifprog_season_start_date', self::date_only($season['start_date'] ?? ''));
        update_post_meta($post_id, '_ifprog_season_end_date', self::date_only($season['end_date'] ?? ''));
        update_post_meta($post_id, '_ifprog_season_registration_open', self::datetime_local($season['signup_start'] ?? ''));
        update_post_meta($post_id, '_ifprog_season_registration_close', self::datetime_local($season['signup_end'] ?? ''));
        update_post_meta(
            $post_id,
            '_ifprog_dash_registration_url',
            IFPROG_Dash::season_registration_url(
                absint($season['program_id'] ?? 0),
                $dash_season_id,
                absint($season['facility_id'] ?? 0)
            )
        );
        update_post_meta($post_id, '_ifprog_dash_last_sync', current_time('mysql'));
        update_post_meta($post_id, '_ifprog_dash_payload', $season);
        self::assign_initial_season_terms($post_id, $season, $preview['rows'] ?? []);

        $published = self::publish_if_requested($post_id, $publish_imported);
        if (is_wp_error($published)) return $published;

        return [
            'post_id' => absint($post_id),
            'action' => $is_new ? 'created' : 'updated',
            'title_updated' => !empty($presentation_result['title_updated']) ? 1 : 0,
            'description_updated' => !empty($presentation_result['description_updated']) ? 1 : 0,
            'published' => $published ? 1 : 0,
        ];
    }

    private static function sync_level(
        $row,
        $season_post_id,
        $publish_imported = false,
        $update_title = false,
        $update_description = false
    ) {
        $league_id = absint($row['league_id'] ?? 0);
        $payload = is_array($row['source_payload'] ?? null) ? $row['source_payload'] : [];
        $league = is_array($payload['league'] ?? null) ? $payload['league'] : [];
        if (!$league_id || !$league) {
            return new WP_Error('ifprog_sync_invalid_level', 'A selected import did not include a valid Dash League.');
        }

        $assigned_season_id = self::level_season_id($row, $season_post_id);
        $has_route = $league_id === self::CURRENT_LEARN_TO_PLAY_LEAGUE_ID;
        if (!$assigned_season_id) {
            return new WP_Error(
                'ifprog_sync_level_route_unavailable',
                'Dash League #86 needs a published Current Learn to Play Season before it can be imported for the first time.'
            );
        }
        $post_id = self::find_level($league_id, $assigned_season_id);
        if (!$post_id && $league_id === self::CURRENT_LEARN_TO_PLAY_LEAGUE_ID) {
            $post_id = self::find_level_in_any_season($league_id);
        }
        $is_new = !$post_id;
        if ($is_new) {
            $post_id = wp_insert_post([
                'post_type' => 'ifprog_level',
                'post_status' => 'draft',
                'post_title' => sanitize_text_field((string) ($row['level'] ?? 'Dash League #' . $league_id)),
                'post_content' => wp_kses_post((string) ($row['level_description'] ?? '')),
                'post_author' => get_current_user_id(),
            ], true);
            if (is_wp_error($post_id)) {
                return new WP_Error(
                    'ifprog_sync_level_create',
                    sanitize_text_field((string) ($row['level'] ?? 'League #' . $league_id)) . ' could not be created: ' . $post_id->get_error_message()
                );
            }
            update_post_meta(
                $post_id,
                self::PRESENTATION_DESCRIPTION_KEY,
                wp_kses_post((string) ($row['level_description'] ?? ''))
            );
        }

        $presentation_result = ['title_updated' => false, 'description_updated' => false];
        if (!$is_new) {
            $previous_description = self::previous_dash_description($post_id, 'level');
            $presentation_result = self::update_post_presentation(
                $post_id,
                $row['level'] ?? '',
                $row['level_description'] ?? '',
                $update_title,
                $update_description,
                false,
                $previous_description
            );
            if (is_wp_error($presentation_result)) return $presentation_result;
        }

        update_post_meta($post_id, '_ifprog_level_season_id', $assigned_season_id);
        update_post_meta($post_id, '_ifprog_level_age_range', sanitize_text_field((string) ($row['age_range'] ?? '')));
        update_post_meta($post_id, '_ifprog_dash_league_id', $league_id);
        update_post_meta($post_id, '_ifprog_dash_registration_url', esc_url_raw((string) ($row['level_registration_url'] ?? '')));
        update_post_meta($post_id, '_ifprog_dash_last_sync', current_time('mysql'));
        update_post_meta($post_id, '_ifprog_dash_payload', $league);
        if ($league_id === self::CURRENT_LEARN_TO_PLAY_LEAGUE_ID) {
            update_post_meta($post_id, '_ifprog_season_route', 'current-learn-to-play');
        }

        $published = self::publish_if_requested($post_id, $publish_imported);
        if (is_wp_error($published)) return $published;

        return [
            'post_id' => absint($post_id),
            'season_id' => $assigned_season_id,
            'routed' => $has_route,
            'action' => $is_new ? 'created' : 'updated',
            'title_updated' => !empty($presentation_result['title_updated']) ? 1 : 0,
            'description_updated' => !empty($presentation_result['description_updated']) ? 1 : 0,
            'published' => $published ? 1 : 0,
        ];
    }

    private static function sync_program(
        $row,
        $season_post_id,
        $level_post_id,
        $publish_imported = false,
        $force_season_assignment = false,
        $update_program_title = false,
        $update_program_description = false,
        $refresh_special_categories = false
    ) {
        $team_id = absint($row['team_id'] ?? 0);
        if (!$team_id) return new WP_Error('ifprog_sync_invalid_team', 'A selected Team did not include a valid Dash ID.');

        $post_id = self::find_program($team_id);
        $is_new = !$post_id;

        if ($is_new) {
            $description = wp_kses_post((string) ($row['description'] ?? ''));
            $post_id = wp_insert_post([
                'post_type' => 'ifprog_program',
                'post_status' => 'draft',
                'post_title' => sanitize_text_field((string) ($row['title'] ?? 'Dash Team #' . $team_id)),
                'post_content' => $description,
                'post_excerpt' => sanitize_text_field(wp_trim_words(wp_strip_all_tags($description), 24)),
                'post_author' => get_current_user_id(),
            ], true);
            if (is_wp_error($post_id)) {
                return new WP_Error(
                    'ifprog_sync_program_create',
                    sanitize_text_field((string) ($row['title'] ?? 'Team #' . $team_id)) . ' could not be created: ' . $post_id->get_error_message()
                );
            }

            update_post_meta($post_id, IFPROG_Status::PROGRAM_STATUS_KEY, 'automatic');
            update_post_meta($post_id, self::PRESENTATION_DESCRIPTION_KEY, $description);
            self::assign_initial_terms($post_id, $row);
        }

        $presentation_result = ['title_updated' => false, 'description_updated' => false];
        if (!$is_new) {
            $previous_description = self::previous_dash_description($post_id, 'program');
            $presentation_result = self::update_post_presentation(
                $post_id,
                $row['title'] ?? '',
                $row['description'] ?? '',
                $update_program_title,
                $update_program_description,
                true,
                $previous_description
            );
            if (is_wp_error($presentation_result)) return $presentation_result;
        }
        $title_updated = !empty($presentation_result['title_updated']);
        $description_updated = !empty($presentation_result['description_updated']);

        $values = is_array($row['source_values'] ?? null) ? $row['source_values'] : [];
        $values['season_id'] = absint($season_post_id);
        $values['level_id'] = absint($level_post_id);
        if ($force_season_assignment) {
            self::remove_local_override($post_id, 'season_id');
        }
        $payload = is_array($row['source_payload'] ?? null) ? $row['source_payload'] : [];
        IFPROG_Dash::store_program_source($post_id, $values, 'team', $team_id, $payload);
        if ($is_new || $title_updated || $refresh_special_categories) {
            IFPROG_Post_Types::assign_special_categories($post_id);
        }

        $registration = is_array($payload['registration'] ?? null) ? $payload['registration'] : [];
        update_post_meta(
            $post_id,
            '_ifprog_dash_registration_status',
            sanitize_key((string) ($registration['registration_status'] ?? ($row['registration_status'] ?? '')))
        );
        update_post_meta($post_id, '_ifprog_dash_registered_customers', absint($registration['registered_customers'] ?? 0));
        update_post_meta($post_id, '_ifprog_dash_max_registered_customers', absint($registration['max_registered_customers'] ?? 0));
        update_post_meta($post_id, '_ifprog_dash_waitlisted_customers', absint($registration['waitlisted_customers'] ?? 0));
        update_post_meta($post_id, '_ifprog_dash_has_waitlist', self::dash_truthy($registration['has_waitlist'] ?? '') ? 1 : 0);

        $published = self::publish_if_requested($post_id, $publish_imported);
        if (is_wp_error($published)) return $published;

        return [
            'post_id' => absint($post_id),
            'action' => $is_new ? 'created' : 'updated',
            'title_updated' => $title_updated ? 1 : 0,
            'description_updated' => $description_updated ? 1 : 0,
            'published' => $published ? 1 : 0,
        ];
    }

    private static function presentation_updates($requested) {
        $requested = is_array($requested) ? $requested : [];
        $updates = [];
        foreach ([
            'season_title',
            'season_description',
            'level_titles',
            'level_descriptions',
            'program_titles',
            'program_descriptions',
        ] as $key) {
            $updates[$key] = !empty($requested[$key]);
        }
        return $updates;
    }

    private static function update_post_presentation(
        $post_id,
        $source_title,
        $source_description,
        $update_title,
        $update_description,
        $update_excerpt = false,
        $previous_source_description = ''
    ) {
        $result = ['title_updated' => false, 'description_updated' => false];
        if (!$update_title && !$update_description) return $result;

        $post_id = absint($post_id);
        $existing_post = get_post($post_id);
        if (!$existing_post) {
            return new WP_Error('ifprog_sync_presentation_missing', 'WordPress record #' . $post_id . ' could not be loaded for its Dash presentation update.');
        }

        $post_update = ['ID' => $post_id];
        if ($update_title) {
            $source_title = sanitize_text_field((string) $source_title);
            if ($source_title !== '' && $existing_post->post_title !== $source_title) {
                $post_update['post_title'] = $source_title;
                $result['title_updated'] = true;
            }
        }

        $adopted_description = false;
        if ($update_description) {
            $source_description = wp_kses_post((string) $source_description);
            $previous_source_description = wp_kses_post((string) $previous_source_description);
            $current_description_key = self::normalized_presentation_text($existing_post->post_content);
            $source_description_key = self::normalized_presentation_text($source_description);
            $previous_description_key = self::normalized_presentation_text($previous_source_description);
            $matches_current_source = $current_description_key === $source_description_key;
            $source_owned = $current_description_key === $previous_description_key;

            if ($matches_current_source || $source_owned) {
                $adopted_description = true;
                if (!$matches_current_source) {
                    $post_update['post_content'] = $source_description;
                    $result['description_updated'] = true;
                }

                if ($update_excerpt) {
                    $source_excerpt = self::description_excerpt($source_description);
                    $previous_excerpt = self::description_excerpt($previous_source_description);
                    $current_excerpt_key = self::normalized_presentation_text($existing_post->post_excerpt);
                    $source_excerpt_key = self::normalized_presentation_text($source_excerpt);
                    $previous_excerpt_key = self::normalized_presentation_text($previous_excerpt);
                    $excerpt_source_owned = $current_excerpt_key === $previous_excerpt_key;
                    if ($current_excerpt_key !== $source_excerpt_key && $excerpt_source_owned) {
                        $post_update['post_excerpt'] = $source_excerpt;
                        $result['description_updated'] = true;
                    }
                }
            }
        }

        if (count($post_update) === 1) {
            if ($adopted_description) {
                update_post_meta($post_id, self::PRESENTATION_DESCRIPTION_KEY, $source_description);
            }
            return $result;
        }

        $updated_post = wp_update_post($post_update, true);
        if (is_wp_error($updated_post)) {
            return new WP_Error(
                'ifprog_sync_presentation_update',
                'WordPress record #' . $post_id . ' could not update its Dash presentation: ' . $updated_post->get_error_message()
            );
        }
        if ($adopted_description) {
            update_post_meta($post_id, self::PRESENTATION_DESCRIPTION_KEY, $source_description);
        }
        return $result;
    }

    private static function previous_dash_description($post_id, $layer) {
        $post_id = absint($post_id);
        if (metadata_exists('post', $post_id, self::PRESENTATION_DESCRIPTION_KEY)) {
            return (string) get_post_meta($post_id, self::PRESENTATION_DESCRIPTION_KEY, true);
        }

        $payload = get_post_meta($post_id, '_ifprog_dash_payload', true);
        if (!is_array($payload)) return '';

        if ($layer === 'program') {
            $payload = is_array($payload['team'] ?? null) ? $payload['team'] : [];
        }

        $fields = $layer === 'season' ? ['description'] : ['description', 'best_description'];
        $description = '';
        foreach ($fields as $field) {
            if (trim((string) ($payload[$field] ?? '')) !== '') {
                $description = wp_kses_post((string) $payload[$field]);
                break;
            }
        }
        update_post_meta($post_id, self::PRESENTATION_DESCRIPTION_KEY, $description);
        return $description;
    }

    private static function normalized_presentation_text($value) {
        $value = html_entity_decode(
            wp_strip_all_tags((string) $value),
            ENT_QUOTES | ENT_HTML5,
            get_bloginfo('charset') ?: 'UTF-8'
        );
        return (string) preg_replace('/\s+/u', ' ', trim($value));
    }

    private static function description_excerpt($description) {
        return sanitize_text_field(wp_trim_words(wp_strip_all_tags((string) $description), 24));
    }

    private static function publish_if_requested($post_id, $publish_imported) {
        if (!$publish_imported) return false;

        $post_id = absint($post_id);
        $status = get_post_status($post_id);
        if ($status === 'publish') return false;
        if (!in_array($status, ['draft','pending','private','future'], true)) return false;

        $updated = wp_update_post([
            'ID' => $post_id,
            'post_status' => 'publish',
        ], true);
        if (is_wp_error($updated)) {
            return new WP_Error(
                'ifprog_sync_publish_failed',
                get_the_title($post_id) . ' was imported but could not be published: ' . $updated->get_error_message()
            );
        }
        if (get_post_status($post_id) !== 'publish') {
            return new WP_Error(
                'ifprog_sync_publish_incomplete',
                get_the_title($post_id) . ' was imported but did not reach Published status.'
            );
        }
        return true;
    }

    private static function assign_initial_terms($post_id, $row) {
        $sport = sanitize_title((string) ($row['sport']['slug'] ?? ''));
        $format = sanitize_title((string) ($row['format'] ?? ''));
        $sport_ids = IFPROG_Post_Types::assignment_term_ids('ifprog_sport', [$sport]);
        $format_ids = IFPROG_Post_Types::assignment_term_ids('ifprog_format', [$format]);
        if ($sport_ids) wp_set_object_terms($post_id, $sport_ids, 'ifprog_sport', false);
        if ($format_ids) wp_set_object_terms($post_id, $format_ids, 'ifprog_format', false);
    }

    private static function assign_initial_season_terms($post_id, $season, $rows) {
        $classification = IFPROG_Preview::season_classification($season, $rows);
        foreach ([
            'ifprog_sport' => $classification['sports'],
            'ifprog_format' => $classification['formats'],
        ] as $taxonomy => $slugs) {
            $existing = wp_get_object_terms($post_id, $taxonomy, ['fields' => 'ids']);
            if (is_wp_error($existing) || $existing || !$slugs) continue;
            $term_ids = IFPROG_Post_Types::assignment_term_ids($taxonomy, $slugs);
            if ($term_ids) wp_set_object_terms($post_id, $term_ids, $taxonomy, false);
        }
    }

    private static function find_season($dash_season_id) {
        $ids = get_posts([
            'post_type' => 'ifprog_season',
            'post_status' => array_keys(get_post_stati()),
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_key' => '_ifprog_dash_season_id',
            'meta_value' => absint($dash_season_id),
        ]);
        return $ids ? absint($ids[0]) : 0;
    }

    private static function find_program($team_id) {
        $ids = get_posts([
            'post_type' => 'ifprog_program',
            'post_status' => array_keys(get_post_stati()),
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => '_ifprog_dash_source_type',
                    'value' => 'team',
                ],
                [
                    'key' => '_ifprog_dash_source_id',
                    'value' => absint($team_id),
                ],
            ],
        ]);
        return $ids ? absint($ids[0]) : 0;
    }

    private static function find_level($league_id, $season_post_id) {
        $ids = get_posts([
            'post_type' => 'ifprog_level',
            'post_status' => array_keys(get_post_stati()),
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => '_ifprog_dash_league_id',
                    'value' => absint($league_id),
                ],
                [
                    'key' => '_ifprog_level_season_id',
                    'value' => absint($season_post_id),
                ],
            ],
        ]);
        return $ids ? absint($ids[0]) : 0;
    }

    private static function find_level_in_any_season($league_id) {
        $ids = get_posts([
            'post_type' => 'ifprog_level',
            'post_status' => array_keys(get_post_stati()),
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_key' => '_ifprog_dash_league_id',
            'meta_value' => absint($league_id),
        ]);
        return $ids ? absint($ids[0]) : 0;
    }

    private static function level_season_id($row, $source_season_id) {
        $source_season_id = absint($source_season_id);
        $league_id = absint($row['league_id'] ?? 0);
        $season_id = $source_season_id;

        if ($league_id === self::CURRENT_LEARN_TO_PLAY_LEAGUE_ID) {
            $current_season_id = self::current_learn_to_play_season_id();
            if ($current_season_id) {
                $season_id = $current_season_id;
            } else {
                $existing_level_id = self::find_level_in_any_season($league_id);
                $existing_season_id = $existing_level_id
                    ? absint(get_post_meta($existing_level_id, '_ifprog_level_season_id', true))
                    : 0;
                $season_id = self::is_learn_to_play_season($existing_season_id)
                    ? $existing_season_id
                    : 0;
            }
        }

        $filtered_season_id = absint(apply_filters(
            'ifprog_dash_level_season_route',
            $season_id,
            $league_id,
            $source_season_id,
            $row
        ));
        if ($league_id === self::CURRENT_LEARN_TO_PLAY_LEAGUE_ID) {
            return $filtered_season_id;
        }
        return $filtered_season_id ?: $source_season_id;
    }

    private static function current_learn_to_play_season_id() {
        $matches = [];
        foreach (IFPROG_Status::current_season_ids(true) as $season_id) {
            if (!self::is_learn_to_play_season($season_id)) continue;

            $matches[] = [
                'season_id' => absint($season_id),
                'start' => IFPROG_Status::timestamp(
                    get_post_meta($season_id, '_ifprog_season_start_date', true)
                ),
            ];
        }
        if (!$matches) return 0;

        usort($matches, function($a, $b) {
            if ($a['start'] !== $b['start']) return $b['start'] <=> $a['start'];
            return $b['season_id'] <=> $a['season_id'];
        });
        return absint($matches[0]['season_id']);
    }

    private static function is_learn_to_play_season($season_id) {
        $season_id = absint($season_id);
        if (!$season_id || get_post_type($season_id) !== 'ifprog_season') return false;

        $title = strtolower(wp_strip_all_tags((string) get_the_title($season_id)));
        return strpos($title, 'learn to play') !== false;
    }

    private static function remove_local_override($post_id, $field) {
        $overrides = array_values(array_diff(
            IFPROG_Fields::overrides($post_id),
            [sanitize_key($field)]
        ));
        update_post_meta($post_id, IFPROG_Fields::OVERRIDES_KEY, $overrides);
    }

    private static function season_lifecycle($season) {
        $now = current_datetime()->getTimestamp();
        $start = IFPROG_Status::timestamp($season['start_date'] ?? '');
        $end = IFPROG_Status::timestamp($season['end_date'] ?? '', true);
        if ($end && $now > $end) return 'completed';
        if ($start && $now < $start) return 'upcoming';
        return 'upcoming';
    }

    private static function date_only($value) {
        return preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $value, $match) ? $match[0] : '';
    }

    private static function datetime_local($value) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', (string) $value, $match)) return '';
        return $match[0];
    }

    private static function dash_truthy($value) {
        return in_array(strtolower(trim((string) $value)), ['1','yes','true','active','open'], true);
    }
}
