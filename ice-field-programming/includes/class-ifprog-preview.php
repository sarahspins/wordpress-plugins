<?php
if (!defined('ABSPATH')) exit;

/**
 * Dash import preview and explicitly triggered protected synchronization.
 *
 * Live discovery established this hierarchy:
 * Season -> League -> Team, with Team representing the registrable class time.
 * Products provide pricing and team-registration-infos provide availability.
 */
class IFPROG_Preview {
    const SEASONS_TRANSIENT = 'ifprog_preview_seasons';

    public static function init() {
        add_action('wp_ajax_ifprog_warm_preview_stage', [__CLASS__, 'ajax_warm_preview_stage']);
    }

    public static function ajax_warm_preview_stage() {
        check_ajax_referer('ifprog_preview_batch', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'You do not have permission to prepare a protected import.'], 403);
        }
        if (!IFPROG_Dash::ready()) {
            wp_send_json_error(['message' => 'The shared Dash Connector is not connected.'], 400);
        }

        $stage = sanitize_key(wp_unslash($_POST['stage'] ?? ''));
        $season_id = absint($_POST['season_id'] ?? 0);
        $dropin_team_id = absint($_POST['dropin_team_id'] ?? 0);
        $event_week = min(60, absint($_POST['event_week'] ?? 0));
        if (!$season_id) {
            wp_send_json_error(['message' => 'A Dash Season ID is required.'], 400);
        }

        $seasons = get_transient(self::SEASONS_TRANSIENT);
        $season_record = is_array($seasons) ? self::find_record($seasons, $season_id) : null;
        if (!$season_record) {
            $season_result = IFPROG_Dash::seasons(['cache_ttl' => 900, 'force' => false]);
            if (is_wp_error($season_result)) {
                wp_send_json_error(['message' => $season_result->get_error_message()], 502);
            }
            $seasons = self::collection_data($season_result);
            set_transient(self::SEASONS_TRANSIENT, $seasons, 15 * MINUTE_IN_SECONDS);
            $season_record = self::find_record($seasons, $season_id);
        }
        if (!$season_record) {
            wp_send_json_error(['message' => 'The selected Dash Season was not found.'], 404);
        }

        $args = ['cache_ttl' => 900, 'force' => true];
        switch ($stage) {
            case 'teams':
                $result = IFPROG_Dash::teams($season_id, $args);
                break;
            case 'leagues':
                $result = IFPROG_Dash::leagues($args);
                break;
            case 'products':
                $result = IFPROG_Dash::products($args);
                break;
            case 'availability':
                $result = IFPROG_Dash::registration_infos($args);
                break;
            case 'events':
                $season = self::attributes($season_record);
                $start = self::date_only($season['start_date'] ?? '');
                $end = self::date_only($season['end_date'] ?? '');
                if ($start === '' || $end === '') {
                    $result = [];
                    break;
                }
                $window_start = (new DateTimeImmutable($start))->modify('+' . ($event_week * 7) . ' days');
                $season_end = new DateTimeImmutable($end);
                if ($window_start > $season_end) {
                    $result = [];
                    break;
                }
                $window_end = $window_start->modify('+6 days');
                if ($window_end > $season_end) $window_end = $season_end;
                $result = IFPROG_Dash::events([
                    'filter[start__gte]' => $window_start->format('Y-m-d') . 'T00:00:00',
                    'filter[start__lte]' => $window_end->format('Y-m-d') . 'T23:59:59',
                    'sort' => 'start',
                    'page[size]' => 100,
                ], wp_parse_args($args, ['max_pages' => 10]));
                break;
            case 'dropin':
                if (!$dropin_team_id) {
                    wp_send_json_error(['message' => 'A drop-in Team ID is required for this stage.'], 400);
                }
                $result = IFPROG_Dash::team($dropin_team_id, $args);
                break;
            default:
                wp_send_json_error(['message' => 'Unknown preparation stage.'], 400);
        }

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 502);
        }
        $response = [
            'stage' => $stage,
            'peak_memory_bytes' => memory_get_peak_usage(true),
        ];
        if ($stage === 'events' && isset($window_end, $season_end)) {
            $response['event_week'] = $event_week + 1;
            $response['more_event_weeks'] = $window_end < $season_end;
        }
        wp_send_json_success($response);
    }

    public static function page() {
        if (!current_user_can('manage_options')) return;

        $error = null;
        $preview = null;
        $sync_result = null;
        $action_notice = '';
        $selected_season_id = absint($_POST['ifprog_dash_season_id'] ?? ($_GET['ifprog_dash_season_id'] ?? 0));
        $dropin_team_id = absint($_POST['ifprog_dropin_team_id'] ?? 0);
        $batched_preview = !empty($_POST['ifprog_batched_preview']);
        $view = sanitize_key(wp_unslash($_POST['ifprog_discovery_view'] ?? ($_GET['ifprog_view'] ?? 'inbox')));
        $view = $view === 'all' ? 'all' : 'inbox';
        $filter = sanitize_key(wp_unslash($_POST['ifprog_discovery_filter'] ?? ($_GET['ifprog_filter'] ?? 'all')));
        if (!in_array($filter, ['all','current','upcoming','completed','imported','new','changed','excluded'], true)) {
            $filter = 'all';
        }
        $seasons = get_transient(self::SEASONS_TRANSIENT);
        if (!is_array($seasons)) $seasons = [];
        $checks = IFPROG_Discovery::cached_checks();

        $action = sanitize_key(wp_unslash($_POST['ifprog_preview_action'] ?? ''));
        if ($action !== '') {
            check_admin_referer('ifprog_dash_preview', 'ifprog_preview_nonce');

            if (in_array($action, ['exclude_season','restore_season','bulk_exclude_seasons'], true)) {
                if ($action === 'bulk_exclude_seasons') {
                    $bulk_ids = array_values(array_unique(array_filter(array_map(
                        'absint',
                        (array) wp_unslash($_POST['ifprog_bulk_season_ids'] ?? [])
                    ))));
                    if (!$bulk_ids) {
                        $error = new WP_Error('ifprog_discovery_bulk_empty', 'Select at least one Dash Season to exclude.');
                    } else {
                        foreach ($bulk_ids as $dash_id) {
                            $season_record = self::find_record($seasons, $dash_id);
                            IFPROG_Discovery::exclude($dash_id, $season_record ?: []);
                        }
                        $action_notice = count($bulk_ids) . ' ' . (count($bulk_ids) === 1 ? 'Season was' : 'Seasons were') . ' excluded from discovery.';
                    }
                } elseif (!$selected_season_id) {
                    $error = new WP_Error('ifprog_discovery_season_required', 'The Dash Season could not be identified.');
                } elseif ($action === 'exclude_season') {
                    $selected_season = self::find_record($seasons, $selected_season_id);
                    IFPROG_Discovery::exclude($selected_season_id, $selected_season ?: []);
                    $action_notice = 'The Season will no longer appear in the Current & Upcoming inbox or future suggestions.';
                    $selected_season_id = 0;
                } else {
                    IFPROG_Discovery::restore($selected_season_id);
                    $action_notice = 'The Season was restored to discovery.';
                    $selected_season_id = 0;
                }
            } else {
                if (!IFPROG_Dash::ready()) {
                    $error = new WP_Error('ifprog_dash_not_ready', 'The shared Dash Connector must be connected before discovery or preview can be loaded.');
                } else {
                    $season_result = IFPROG_Dash::seasons([
                        'cache_ttl' => $batched_preview ? 900 : 300,
                        'force' => !$batched_preview,
                    ]);
                    if (is_wp_error($season_result)) {
                        $error = $season_result;
                        IFPROG_Audit::record(
                            'discovery_failed',
                            'Dash Season discovery could not be refreshed: ' . $season_result->get_error_message(),
                            ['source' => 'discovery', 'severity' => 'error']
                        );
                    } else {
                        $seasons = self::collection_data($season_result);
                        set_transient(self::SEASONS_TRANSIENT, $seasons, 15 * MINUTE_IN_SECONDS);
                        if ($action === 'load_seasons') {
                            $checks = IFPROG_Discovery::cached_checks();
                            $action_notice = 'Season discovery refreshed. Use Check Changes on an imported Season to refresh its hierarchy comparison without overloading the server.';
                            IFPROG_Audit::record(
                                'discovery_refreshed',
                                'The Dash Season list was refreshed manually.',
                                [
                                    'source' => 'discovery',
                                    'severity' => 'success',
                                    'context' => [
                                        'seasons_found' => count($seasons),
                                        'hierarchy_comparisons' => 0,
                                    ],
                                ]
                            );
                        }
                    }
                }

                if (!$error && in_array($action, ['preview', 'import', 'check_season'], true)) {
                    if (!$selected_season_id) {
                        $error = new WP_Error('ifprog_season_required', 'Choose a Dash Season to preview.');
                    } else {
                        $selected_season = self::find_record($seasons, $selected_season_id);
                        if (!$selected_season) {
                            $error = new WP_Error('ifprog_invalid_dash_season', 'The selected Dash Season was not found.');
                        } else {
                            $started = microtime(true);
                            $memory_before = memory_get_usage(true);
                            $preview = self::build($selected_season, !$batched_preview, false, $dropin_team_id ? [$dropin_team_id] : []);
                            if (is_wp_error($preview)) {
                                $error = $preview;
                                $preview = null;
                            } elseif ($action === 'check_season') {
                                $checks = IFPROG_Discovery::record_comparison(
                                    absint($preview['existing_season_id'] ?? 0),
                                    $preview
                                );
                                $preview = null;
                                $action_notice = 'The selected Season comparison was refreshed.';
                                IFPROG_Audit::record(
                                    'season_comparison_refreshed',
                                    'One imported Season hierarchy comparison was refreshed.',
                                    [
                                        'source' => 'discovery',
                                        'severity' => 'success',
                                        'dash_season_id' => $selected_season_id,
                                        'context' => [
                                            'elapsed_ms' => round((microtime(true) - $started) * 1000),
                                            'memory_growth_bytes' => max(0, memory_get_usage(true) - $memory_before),
                                            'peak_memory_bytes' => memory_get_peak_usage(true),
                                        ],
                                    ]
                                );
                            } elseif ($action === 'import') {
                                $team_ids = array_map(
                                    'absint',
                                    (array) wp_unslash($_POST['ifprog_team_ids'] ?? [])
                                );
                                $level_ids = array_map(
                                    'absint',
                                    (array) wp_unslash($_POST['ifprog_level_ids'] ?? [])
                                );
                                $publish_imported = !empty($_POST['ifprog_publish_imported']);
                                $presentation_updates = [
                                    'season_title' => !empty($_POST['ifprog_update_season_title']),
                                    'season_description' => !empty($_POST['ifprog_update_season_description']),
                                    'level_titles' => !empty($_POST['ifprog_update_level_titles']),
                                    'level_descriptions' => !empty($_POST['ifprog_update_level_descriptions']),
                                    'program_titles' => !empty($_POST['ifprog_update_program_titles']),
                                    'program_descriptions' => !empty($_POST['ifprog_update_program_descriptions']),
                                ];
                                $season_only = !empty($_POST['ifprog_season_only']);
                                $companion_options = [
                                    'sync_participants' => !empty($_POST['ifprog_sync_production_participants']),
                                ];
                                $classification = (array) wp_unslash($_POST['ifprog_classification'] ?? []);
                                $classification['row_sports'] = (array) wp_unslash($_POST['ifprog_row_sports'] ?? []);
                                $sync_result = IFPROG_Sync::run(
                                    $preview,
                                    $team_ids,
                                    $publish_imported,
                                    $season_only,
                                    $level_ids,
                                    $presentation_updates,
                                    $companion_options,
                                    $classification
                                );
                                if (is_wp_error($sync_result)) {
                                    $error = $sync_result;
                                    $family = IFPROG_Monitoring::family_for_season_name($preview['season']['name'] ?? '');
                                    IFPROG_Audit::record(
                                        'sync_failed',
                                        'Protected sync could not start: ' . $sync_result->get_error_message(),
                                        [
                                            'source' => 'sync',
                                            'severity' => 'error',
                                            'dash_season_id' => $selected_season_id,
                                            'family' => $family['label'] ?? '',
                                        ]
                                    );
                                    $sync_result = null;
                                } else {
                                    $checks = IFPROG_Discovery::cached_checks();
                                    // Refresh WordPress linkage shown in the preview.
                                    $preview = self::build($selected_season, false, false, $dropin_team_id ? [$dropin_team_id] : []);
                                    if (is_wp_error($preview)) {
                                        $error = $preview;
                                        $preview = null;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        if ($action === '' && IFPROG_Dash::ready()) {
            if (!$seasons) {
                $season_result = IFPROG_Dash::seasons([
                    'cache_ttl' => 300,
                    'force' => false,
                ]);
                if (is_wp_error($season_result)) {
                    $error = $season_result;
                } else {
                    $seasons = self::collection_data($season_result);
                    set_transient(self::SEASONS_TRANSIENT, $seasons, 15 * MINUTE_IN_SECONDS);
                }
            }
            $checks = IFPROG_Discovery::cached_checks();
        }

        self::render_page($seasons, $selected_season_id, $preview, $error, $sync_result, $action_notice, $view, $filter, $checks);
    }

    public static function build($season_record, $force = false, $force_shared = null, $additional_team_ids = []) {
        $season_id = absint($season_record['id'] ?? 0);
        $force_shared = $force_shared === null ? (bool) $force : (bool) $force_shared;
        $team_args = [
            'cache_ttl' => 300,
            'force' => (bool) $force,
        ];
        $shared_args = [
            'cache_ttl' => 300,
            'force' => $force_shared,
        ];
        $requests = [
            'teams' => IFPROG_Dash::teams($season_id, $team_args),
            'leagues' => IFPROG_Dash::leagues($shared_args),
            'products' => IFPROG_Dash::products($shared_args),
            'availability' => IFPROG_Dash::registration_infos($shared_args),
        ];

        foreach ($requests as $name => $result) {
            if (is_wp_error($result)) {
                return new WP_Error(
                    'ifprog_preview_' . sanitize_key($name),
                    'Dash preview could not load ' . $name . ': ' . $result->get_error_message()
                );
            }
        }

        $teams = self::collection_data($requests['teams']);
        $loaded_team_ids = [];
        foreach ($teams as $loaded_team) {
            $loaded_team_id = absint($loaded_team['id'] ?? 0);
            if ($loaded_team_id) $loaded_team_ids[$loaded_team_id] = true;
        }
        $additional_team_ids = array_values(array_unique(array_filter(array_map('absint', (array) $additional_team_ids))));
        $additional_team_lookup = array_fill_keys($additional_team_ids, true);
        foreach ($additional_team_ids as $additional_team_id) {
            if (!empty($loaded_team_ids[$additional_team_id])) continue;
            $additional = IFPROG_Dash::team($additional_team_id, $team_args);
            if (is_wp_error($additional)) {
                return new WP_Error('ifprog_preview_additional_team', 'The requested Adult Development Camp Team could not be loaded: ' . $additional->get_error_message());
            }
            $record = is_array($additional['data'] ?? null) ? $additional['data'] : $additional;
            if (!is_array($record) || absint($record['id'] ?? 0) !== $additional_team_id) {
                return new WP_Error('ifprog_preview_additional_team_invalid', 'Dash Team #' . $additional_team_id . ' could not be verified.');
            }
            $record_attributes = self::attributes($record);
            if (absint($record_attributes['league_id'] ?? 0) !== IFPROG_Sync::CURRENT_LEARN_TO_PLAY_LEAGUE_ID) {
                return new WP_Error('ifprog_preview_additional_team_league', 'Dash Team #' . $additional_team_id . ' is not assigned to Adult Development Camp League #86.');
            }
            $teams[] = $record;
        }
        $leagues = self::index_records(self::collection_data($requests['leagues']));
        $products = self::index_records(self::collection_data($requests['products']));
        $availability_by_team = self::index_records(self::collection_data($requests['availability']));
        $season_attrs = self::attributes($season_record);
        $events_by_team = self::season_events_by_team($season_attrs, $shared_args);
        $existing = self::existing_team_programs();
        $existing_season_id = self::existing_season($season_id);
        $existing_levels = self::existing_levels($existing_season_id);
        $rows = [];
        $represented_league_ids = [];

        foreach ($teams as $team_record) {
            $team_id = absint($team_record['id'] ?? 0);
            $team = self::attributes($team_record);
            $is_additional_team = !empty($additional_team_lookup[$team_id]);
            if (absint($team['season_id'] ?? 0) !== $season_id && !$is_additional_team) continue;

            $league_id = absint($team['league_id'] ?? 0);
            if ($league_id) $represented_league_ids[$league_id] = true;
            $product_id = absint($team['product_id'] ?? 0);
            $league = isset($leagues[$league_id]) ? self::attributes($leagues[$league_id]) : [];
            $product = isset($products[$product_id]) ? self::attributes($products[$product_id]) : [];
            $registration = isset($availability_by_team[$team_id]) ? self::attributes($availability_by_team[$team_id]) : [];
            $sport = self::sport(absint($team['sport_id'] ?? ($league['sport_id'] ?? 0)));
            $is_active = strtolower((string) ($team['status'] ?? '')) === 'active' && !self::dash_truthy($team['inactive'] ?? '');
            $online_signup = self::dash_truthy($team['online_signup'] ?? '');
            $level = sanitize_text_field((string) ($league['name'] ?? ''));
            $format = self::format($season_attrs, $league, $team);
            $schedule = self::schedule($team);
            $team_start_date = self::date_only($team['start_date'] ?? '');
            $team_end_date = self::date_only($team['end_date'] ?? '');
            $event_starts = $events_by_team[$team_id] ?? [];
            if (!$event_starts && sanitize_title($format) === 'camp') {
                $event_starts = self::team_event_starts($team_id, $season_attrs, $shared_args);
            }
            $event_date_range = self::event_date_range($event_starts);
            $start_date = $event_date_range['start'] ?: $team_start_date;
            $end_date = $event_date_range['end'] ?: self::program_end_date($team, $format);
            $registration_open = self::datetime_local($season_attrs['signup_start'] ?? '');
            $registration_close = self::datetime_local($season_attrs['signup_end'] ?? '');
            $age_range = IFPROG_Dash::age_range_label($league);
            $session_price = self::session_price($product, $team, $league, $event_starts);
            $availability_label = self::availability($registration);
            $availability_note = self::availability_note($registration);
            $registration_status = sanitize_key((string) ($registration['registration_status'] ?? ''));
            $facility_id = absint($team['facility_id'] ?? ($league['facility_id'] ?? ($season_attrs['facility_id'] ?? 0)));
            $level_registration_url = $online_signup
                ? IFPROG_Dash::registration_url($league_id, $facility_id)
                : '';
            $registration_url = $online_signup
                ? IFPROG_Dash::team_registration_url($team_id)
                : '';
            $warnings = [];

            if (!$is_active) $warnings[] = 'Inactive Team';
            if ($sport['slug'] === '') $warnings[] = 'Sport needs mapping';
            if (!$league) $warnings[] = 'Parent League not found';
            if (!$registration) $warnings[] = 'Availability not found';
            if (!$online_signup) $warnings[] = 'Online signup is not enabled';
            if ($online_signup && !$registration_url) $warnings[] = 'Registration link could not be generated';
            if (
                $event_date_range['start'] !== '' &&
                (
                    ($team_start_date !== '' && $team_start_date !== $event_date_range['start']) ||
                    ($team_end_date !== '' && $team_end_date !== $event_date_range['end'])
                )
            ) {
                $warnings[] = 'Team dates differ from scheduled Events; Event dates will be used';
            }
            if ($league_id === IFPROG_Sync::CURRENT_LEARN_TO_PLAY_LEAGUE_ID) {
                $warnings[] = 'Season routing: Current Learn to Play';
            }
            if ($is_additional_team) $warnings[] = 'Manually included recurring drop-in Team';

            $rows[] = [
                'row_type' => 'team',
                'team_id' => $team_id,
                'league_id' => $league_id,
                'facility_id' => $facility_id,
                'title' => sanitize_text_field((string) ($team['name'] ?? 'Team #' . $team_id)),
                'description' => self::description($team, []),
                'level_description' => self::description([], $league),
                'sport' => $sport,
                'format' => $format,
                'level' => $level,
                'schedule' => $schedule,
                'start_date' => $start_date,
                'end_date' => $end_date,
                'date_source' => $event_date_range['start'] !== '' ? 'events' : 'team',
                'team_start_date' => $team_start_date,
                'team_end_date' => $team_end_date,
                'registration_open' => $registration_open,
                'registration_close' => $registration_close,
                'age_range' => $age_range,
                'session_price' => $session_price,
                'availability' => $availability_label,
                'registration_status' => $registration_status,
                'registration_url' => $registration_url,
                'level_registration_url' => $level_registration_url,
                'existing_id' => absint($existing[$team_id] ?? 0),
                'existing_level_id' => absint($existing_levels[$league_id] ?? 0),
                'eligible' => $is_active && !empty($league),
                'sport_mapping_required' => $sport['slug'] === '',
                'warnings' => $warnings,
                'source_values' => [
                    'start_date' => $start_date,
                    'end_date' => $end_date,
                    'registration_open' => $registration_open,
                    'registration_close' => $registration_close,
                    'schedule' => $schedule,
                    'age_range' => $age_range,
                    'level' => $level,
                    'price' => $session_price['total'],
                    'weeks' => $session_price['weeks'],
                    'duration_unit' => $session_price['unit'],
                    'registration_url' => $registration_url,
                    'availability_note' => $availability_note,
                ],
                'source_payload' => [
                    'season' => $season_attrs,
                    'team' => $team,
                    'league' => $league,
                    'product' => $product,
                    'registration' => $registration,
                ],
            ];
        }

        foreach ($leagues as $league_id => $league_record) {
            $league = self::attributes($league_record);
            if (
                absint($league['season_id'] ?? 0) !== $season_id ||
                !empty($represented_league_ids[$league_id])
            ) {
                continue;
            }

            $sport = self::sport(absint($league['sport_id'] ?? ($season_attrs['sport_id'] ?? 0)));
            if ($sport['slug'] === '') {
                $classification = self::season_classification($season_attrs);
                $fallback_sport = sanitize_title((string) ($classification['sports'][0] ?? ''));
                if ($fallback_sport !== '') {
                    $sport = [
                        'slug' => $fallback_sport,
                        'label' => ucwords(str_replace('-', ' ', $fallback_sport)),
                    ];
                }
            }
            $level = sanitize_text_field((string) ($league['name'] ?? 'League #' . $league_id));
            $facility_id = absint($league['facility_id'] ?? ($season_attrs['facility_id'] ?? 0));
            $level_registration_url = IFPROG_Dash::registration_url($league_id, $facility_id);
            $registration_status = self::season_registration_status($season_attrs);
            $explicitly_inactive = self::dash_truthy($league['inactive'] ?? '') ||
                strtolower(trim((string) ($league['status'] ?? ''))) === 'inactive';
            $warnings = ['Standalone League — imports as a Level without a Program/class'];
            if ($explicitly_inactive) $warnings[] = 'Inactive League';
            if ($sport['slug'] === '') $warnings[] = 'Sport needs mapping';
            if ($level_registration_url === '') $warnings[] = 'Registration link could not be generated';
            if ($league_id === IFPROG_Sync::CURRENT_LEARN_TO_PLAY_LEAGUE_ID) {
                $warnings[] = 'Season routing: Current Learn to Play';
            }

            $rows[] = [
                'row_type' => 'level',
                'team_id' => 0,
                'league_id' => absint($league_id),
                'facility_id' => $facility_id,
                'title' => $level,
                'description' => '',
                'level_description' => self::description([], $league),
                'sport' => $sport,
                'format' => self::format($season_attrs, $league, []),
                'level' => $level,
                'schedule' => '',
                'start_date' => self::date_only($season_attrs['start_date'] ?? ''),
                'end_date' => self::date_only($season_attrs['end_date'] ?? ''),
                'registration_open' => self::datetime_local($season_attrs['signup_start'] ?? ''),
                'registration_close' => self::datetime_local($season_attrs['signup_end'] ?? ''),
                'age_range' => IFPROG_Dash::age_range_label($league),
                'session_price' => [
                    'total' => '',
                    'weeks' => 0,
                    'detail' => '',
                ],
                'availability' => 'Level registration',
                'registration_status' => $registration_status,
                'registration_url' => '',
                'level_registration_url' => $level_registration_url,
                'existing_id' => 0,
                'existing_level_id' => absint($existing_levels[$league_id] ?? 0),
                'eligible' => !$explicitly_inactive,
                'sport_mapping_required' => $sport['slug'] === '',
                'warnings' => $warnings,
                'source_values' => [],
                'source_payload' => [
                    'season' => $season_attrs,
                    'team' => [],
                    'league' => $league,
                    'product' => [],
                    'registration' => [],
                ],
            ];
        }

        usort($rows, function($a, $b) {
            $sport = strcmp($a['sport']['label'], $b['sport']['label']);
            if ($sport !== 0) return $sport;
            $level = strcasecmp($a['level'], $b['level']);
            if ($level !== 0) return $level;
            return strcasecmp($a['title'], $b['title']);
        });

        return [
            'season_id' => $season_id,
            'season' => $season_attrs,
            'existing_season_id' => $existing_season_id,
            'rows' => $rows,
            'dropin_team_id' => $additional_team_ids ? absint($additional_team_ids[0]) : 0,
        ];
    }

    private static function render_page($seasons, $selected_season_id, $preview, $error, $sync_result, $action_notice, $view, $filter, $checks) {
        $connected = IFPROG_Dash::ready();
        ?>
        <div class="wrap ifprog-admin">
            <div class="ifprog-page-header">
                <div>
                    <p class="ifprog-kicker">Ice &amp; Field Programming</p>
                    <h1>Season Discovery &amp; Sync</h1>
                    <p class="ifprog-lead">Find new Dash Seasons, see what is already imported, review meaningful source changes, and open the protected import workflow from one place.</p>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="notice notice-error"><p><?php echo esc_html($error->get_error_message()); ?></p></div>
            <?php endif; ?>
            <?php if ($action_notice): ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html($action_notice); ?></p></div>
            <?php endif; ?>
            <?php if ($sync_result): ?>
                <div class="notice <?php echo $sync_result['errors'] ? 'notice-warning' : 'notice-success'; ?>">
                    <p>
                        <strong>Protected sync complete.</strong>
                        WordPress Season #<?php echo esc_html($sync_result['season_id']); ?> was <?php echo esc_html($sync_result['season_action']); ?>.
                        <?php if (!empty($sync_result['season_only'])): ?>
                            No Levels or Programs were imported.
                        <?php else: ?>
                            <?php echo esc_html($sync_result['levels_created']); ?> Levels created,
                            <?php echo esc_html($sync_result['levels_updated']); ?> Levels updated.
                            <?php echo esc_html($sync_result['created']); ?> Programs created,
                            <?php echo esc_html($sync_result['updated']); ?> updated,
                            <?php echo esc_html($sync_result['skipped']); ?> skipped.
                            <?php if (!empty($sync_result['updates_requested']['level_titles'])): ?>
                                <?php echo esc_html($sync_result['level_titles_updated']); ?> Level <?php echo absint($sync_result['level_titles_updated']) === 1 ? 'name' : 'names'; ?> replaced from Dash.
                            <?php endif; ?>
                            <?php if (!empty($sync_result['updates_requested']['level_descriptions'])): ?>
                                <?php echo esc_html($sync_result['level_descriptions_updated']); ?> Level <?php echo absint($sync_result['level_descriptions_updated']) === 1 ? 'description' : 'descriptions'; ?> safely updated from Dash.
                            <?php endif; ?>
                            <?php if (!empty($sync_result['updates_requested']['program_titles'])): ?>
                                <?php echo esc_html($sync_result['program_titles_updated']); ?> class <?php echo absint($sync_result['program_titles_updated']) === 1 ? 'name' : 'names'; ?> replaced from Dash.
                            <?php endif; ?>
                            <?php if (!empty($sync_result['updates_requested']['program_descriptions'])): ?>
                                <?php echo esc_html($sync_result['program_descriptions_updated']); ?> class <?php echo absint($sync_result['program_descriptions_updated']) === 1 ? 'description' : 'descriptions'; ?> safely updated from Dash.
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if (!empty($sync_result['updates_requested']['season_title'])): ?>
                            <?php echo !empty($sync_result['season_title_updated']) ? 'Season name replaced from Dash.' : 'Season name already matched Dash.'; ?>
                        <?php endif; ?>
                        <?php if (!empty($sync_result['updates_requested']['season_description'])): ?>
                            <?php echo !empty($sync_result['season_description_updated']) ? 'Season description safely updated from Dash.' : 'No safe Season description change was needed.'; ?>
                        <?php endif; ?>
                        <?php if (!empty($sync_result['published'])): ?>
                            <?php echo esc_html($sync_result['published']); ?> imported <?php echo absint($sync_result['published']) === 1 ? 'record' : 'records'; ?> published.
                        <?php endif; ?>
                        <?php if (!empty($sync_result['production_projection'])): $projection = $sync_result['production_projection']; ?>
                            Production companion updated:
                            <?php echo esc_html(absint($projection['divisions_created'] ?? 0)); ?> Divisions created,
                            <?php echo esc_html(absint($projection['divisions_updated'] ?? 0)); ?> updated;
                            <?php echo esc_html(absint($projection['groups_created'] ?? 0)); ?> Groups created,
                            <?php echo esc_html(absint($projection['groups_updated'] ?? 0)); ?> updated.
                        <?php endif; ?>
                    </p>
                    <?php if ($sync_result['errors']): ?>
                        <ul class="ifprog-sync-errors">
                            <?php foreach ($sync_result['errors'] as $message): ?>
                                <li><?php echo esc_html($message); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <?php if (!empty($sync_result['production_projection']['warnings'])): ?>
                        <p><strong>Production companion warnings:</strong></p>
                        <ul class="ifprog-sync-errors">
                            <?php foreach ($sync_result['production_projection']['warnings'] as $message): ?>
                                <li><?php echo esc_html($message); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php self::render_discovery($seasons, $checks, $view, $filter, $connected); ?>

            <?php if ($preview): self::render_preview($preview); endif; ?>

            <section class="ifprog-card">
                <h2>Discovery Notes</h2>
                <div class="ifprog-preview-map">
                    <div><strong>Season</strong><span>Registration opening/closing and overall start/end dates</span></div>
                    <div><strong>League → Level</strong><span>Creates the Season’s Level record with its description and age range; a League without Teams can be the registrable item itself</span></div>
                    <div><strong>Team</strong><span>Actual registrable class, day/time, status, and schedule notes</span></div>
                    <div><strong>Product</strong><span>Per-class price multiplied by the Team’s class count to produce the full session price</span></div>
                    <div><strong>Registration Info</strong><span>Open/closed status, enrollment, capacity, and waitlist availability</span></div>
                    <div><strong>Registration Link</strong><span>Generated from the Team’s parent League, facility, and the Connector’s Dash company</span></div>
                </div>
                <p class="description">Preview requests are read-only. Import runs only when you explicitly choose Import Selected Safely. Nothing is deleted, and publication occurs only when you select the publish option.</p>
            </section>
        </div>
        <?php
    }

    private static function render_discovery($seasons, $checks, $view, $filter, $connected) {
        $rows = IFPROG_Discovery::rows($seasons, $checks);
        $counts = ['new' => 0, 'imported' => 0, 'changed' => 0, 'excluded' => 0];
        foreach ($rows as $row) {
            if (isset($counts[$row['state']])) $counts[$row['state']]++;
        }

        $visible = array_values(array_filter($rows, function($row) use ($view, $filter) {
            if ($view === 'inbox') {
                return $row['state'] !== 'excluded' && in_array($row['lifecycle'], ['current','upcoming'], true);
            }
            if ($filter === 'all') return true;
            if (in_array($filter, ['current','upcoming','completed'], true)) {
                return $row['lifecycle'] === $filter;
            }
            if ($filter === 'imported') {
                return $row['wp_id'] && $row['state'] !== 'excluded';
            }
            return $row['state'] === $filter;
        }));
        $bulk_count = count(array_filter($visible, function($row) {
            return $row['state'] !== 'excluded';
        }));
        $group_labels = [
            'new' => [
                'title' => 'New Seasons',
                'description' => 'Found in Dash and ready to preview or import.',
            ],
            'changed' => [
                'title' => 'Needs Re-sync',
                'description' => 'Imported Seasons with meaningful changes in Dash.',
            ],
            'imported' => [
                'title' => 'Imported Seasons',
                'description' => 'Already imported and matching the last successful sync.',
            ],
            'excluded' => [
                'title' => 'Never Import',
                'description' => 'Excluded Seasons remain available here to restore.',
            ],
        ];
        $visible_groups = [];
        foreach (array_keys($group_labels) as $state) {
            $visible_groups[$state] = [];
        }
        foreach ($visible as $row) {
            $state = $row['state'] ?? '';
            if (isset($visible_groups[$state])) $visible_groups[$state][] = $row;
        }
        $visible_new_count = count($visible_groups['new']);
        $base_url = admin_url('admin.php?page=ifprog-preview');
        ?>
        <section class="ifprog-card ifprog-discovery-card">
            <div class="ifprog-discovery-header">
                <div>
                    <p class="ifprog-kicker">Version 1.8</p>
                    <h2>Season Discovery Inbox</h2>
                    <p>Current and Upcoming Seasons stay visible here unless you exclude them. Imported Seasons are compared with the snapshot from their last successful sync.</p>
                </div>
                <?php if ($connected): ?>
                    <form method="post">
                        <?php wp_nonce_field('ifprog_dash_preview', 'ifprog_preview_nonce'); ?>
                        <input type="hidden" name="ifprog_discovery_view" value="<?php echo esc_attr($view); ?>">
                        <input type="hidden" name="ifprog_discovery_filter" value="<?php echo esc_attr($filter); ?>">
                        <button class="button button-primary" type="submit" name="ifprog_preview_action" value="load_seasons" data-ifprog-discovery-refresh>
                            <?php echo $seasons ? 'Refresh Discovery' : 'Discover Dash Seasons'; ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <?php if (!$connected): ?>
                <p><span class="ifprog-status ifprog-status--closed">Connector not ready</span></p>
                <p><a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=ifdc-settings')); ?>">Configure Dash Connector</a></p>
            <?php else: ?>
                <div class="ifprog-discovery-stats" aria-label="Discovery summary">
                    <span><strong><?php echo esc_html($counts['new']); ?></strong> New</span>
                    <span><strong><?php echo esc_html($counts['changed']); ?></strong> Needs Re-sync</span>
                    <span><strong><?php echo esc_html($counts['imported']); ?></strong> Imported</span>
                    <span><strong><?php echo esc_html($counts['excluded']); ?></strong> Excluded</span>
                </div>

                <?php if ($visible_new_count): ?>
                    <div class="ifprog-new-season-alert" role="status">
                        <span class="dashicons dashicons-megaphone" aria-hidden="true"></span>
                        <div>
                            <strong>
                                <?php echo esc_html($visible_new_count); ?> new <?php echo $visible_new_count === 1 ? 'Season was' : 'Seasons were'; ?> found. Would you like to import <?php echo $visible_new_count === 1 ? 'it' : 'them'; ?>?
                            </strong>
                            <p>Review the new source details first, then choose exactly what to import and whether to publish it.</p>
                        </div>
                        <a class="button button-primary" href="#ifprog-discovery-group-new">Review New <?php echo $visible_new_count === 1 ? 'Season' : 'Seasons'; ?></a>
                    </div>
                <?php endif; ?>

                <?php if ($counts['changed']): ?>
                    <div class="ifprog-resync-alert">
                        <span class="dashicons dashicons-update" aria-hidden="true"></span>
                        <div><strong><?php echo esc_html($counts['changed']); ?> imported <?php echo $counts['changed'] === 1 ? 'Season has' : 'Seasons have'; ?> Dash updates.</strong><br>Open Review Changes and re-sync the records you want to refresh.</div>
                    </div>
                <?php endif; ?>

                <nav class="nav-tab-wrapper ifprog-discovery-tabs" aria-label="Season discovery views">
                    <a class="nav-tab <?php echo $view === 'inbox' ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url($base_url); ?>">Current &amp; Upcoming</a>
                    <a class="nav-tab <?php echo $view === 'all' ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(add_query_arg(['ifprog_view' => 'all'], $base_url)); ?>">All Seasons</a>
                </nav>

                <?php if ($view === 'all'): ?>
                    <form class="ifprog-discovery-filter" method="get">
                        <input type="hidden" name="page" value="ifprog-preview">
                        <input type="hidden" name="ifprog_view" value="all">
                        <label for="ifprog-discovery-filter"><strong>Show</strong></label>
                        <select id="ifprog-discovery-filter" name="ifprog_filter">
                            <?php foreach ([
                                'all' => 'All Seasons',
                                'current' => 'Current',
                                'upcoming' => 'Upcoming',
                                'completed' => 'Completed',
                                'imported' => 'Imported',
                                'new' => 'New',
                                'changed' => 'Needs Re-sync',
                                'excluded' => 'Excluded',
                            ] as $value => $label): ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected($filter, $value); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="button" type="submit">Apply Filter</button>
                    </form>
                <?php endif; ?>

                <?php if ($bulk_count): ?>
                    <form id="ifprog-discovery-bulk-form" class="ifprog-discovery-bulk" method="post" data-ifprog-bulk-exclude-form>
                        <?php wp_nonce_field('ifprog_dash_preview', 'ifprog_preview_nonce'); ?>
                        <input type="hidden" name="ifprog_discovery_view" value="<?php echo esc_attr($view); ?>">
                        <input type="hidden" name="ifprog_discovery_filter" value="<?php echo esc_attr($filter); ?>">
                        <strong data-ifprog-bulk-count>0 Seasons selected</strong>
                        <span>
                            <button class="button button-small" type="button" data-ifprog-bulk-select="all">Select all shown</button>
                            <button class="button button-small" type="button" data-ifprog-bulk-select="none">Deselect all</button>
                            <button class="button button-secondary" type="submit" name="ifprog_preview_action" value="bulk_exclude_seasons">Exclude Selected</button>
                        </span>
                    </form>
                <?php endif; ?>

                <?php if (!$seasons && !$rows): ?>
                    <div class="ifprog-discovery-empty">
                        <h3>Ready to look for Seasons</h3>
                        <p>Choose Discover Dash Seasons to load the current source list and establish comparison snapshots for existing imports.</p>
                    </div>
                <?php elseif (!$visible): ?>
                    <div class="ifprog-discovery-empty">
                        <h3>No Seasons match this view</h3>
                        <p>Refresh discovery or choose another All Seasons filter.</p>
                    </div>
                <?php else: ?>
                    <div class="ifprog-discovery-list">
                        <?php foreach ($group_labels as $state => $group): ?>
                            <?php if (empty($visible_groups[$state])) continue; ?>
                            <section class="ifprog-discovery-group ifprog-discovery-group--<?php echo esc_attr($state); ?>" id="ifprog-discovery-group-<?php echo esc_attr($state); ?>">
                                <div class="ifprog-discovery-group__header">
                                    <div>
                                        <h3><?php echo esc_html($group['title']); ?></h3>
                                        <p><?php echo esc_html($group['description']); ?></p>
                                    </div>
                                    <span><?php echo esc_html(count($visible_groups[$state])); ?></span>
                                </div>
                                <div class="ifprog-discovery-group__rows">
                                    <?php foreach ($visible_groups[$state] as $row): self::render_discovery_row($row, $view, $filter); endforeach; ?>
                                </div>
                            </section>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <p class="description">Never Import exclusions are reversible and keyed to the Dash Season ID, so renaming a Season in Dash does not make it reappear.</p>
            <?php endif; ?>
        </section>
        <?php
    }

    private static function render_discovery_row($row, $view, $filter) {
        $state_labels = [
            'new' => 'New',
            'imported' => 'Imported',
            'changed' => 'Needs Re-sync',
            'excluded' => 'Never Import',
        ];
        $state_classes = [
            'new' => 'soon',
            'imported' => 'open',
            'changed' => 'changed',
            'excluded' => 'closed',
        ];
        $action_labels = [
            'new' => 'Preview / Import',
            'imported' => 'Sync',
            'changed' => 'Review Changes',
        ];
        $date_range = self::date_range($row['start_date'], $row['end_date']);
        $has_source_removals = false;
        foreach ($row['changes'] as $change) {
            if (strpos($change, 'no longer returned by Dash') !== false) {
                $has_source_removals = true;
                break;
            }
        }
        ?>
        <article class="ifprog-discovery-row ifprog-discovery-row--<?php echo esc_attr($row['state']); ?>">
            <div class="ifprog-discovery-select">
                <?php if ($row['state'] !== 'excluded'): ?>
                    <input
                        type="checkbox"
                        name="ifprog_bulk_season_ids[]"
                        value="<?php echo esc_attr($row['dash_id']); ?>"
                        form="ifprog-discovery-bulk-form"
                        aria-label="<?php echo esc_attr('Select ' . $row['name'] . ' for bulk exclusion'); ?>"
                    >
                <?php endif; ?>
            </div>
            <div class="ifprog-discovery-main">
                <div class="ifprog-discovery-title">
                    <h3><?php echo esc_html($row['name']); ?></h3>
                    <div class="ifprog-discovery-badges">
                        <span class="ifprog-status ifprog-status--<?php echo esc_attr($state_classes[$row['state']] ?? 'closed'); ?>"><?php echo esc_html($state_labels[$row['state']] ?? ucfirst($row['state'])); ?></span>
                        <span class="ifprog-status ifprog-status--plain"><?php echo esc_html(ucfirst($row['lifecycle'])); ?></span>
                        <?php if ($row['wp_id'] && get_post_meta($row['wp_id'], '_ifprog_is_production', true) === '1'): ?>
                            <span class="ifprog-status ifprog-status--plain">Production</span>
                        <?php endif; ?>
                    </div>
                </div>
                <p class="ifprog-discovery-dates"><?php echo esc_html($date_range ?: 'Season dates not supplied'); ?></p>
                <?php if ($row['registration_open'] || $row['registration_close']): ?>
                    <p class="ifprog-discovery-registration">
                        Registration <?php echo $row['registration_open'] ? 'opens ' . esc_html(self::date_label($row['registration_open'])) : 'opening date not supplied'; ?>
                        <?php if ($row['registration_close']): ?> · closes <?php echo esc_html(self::date_label($row['registration_close'])); ?><?php endif; ?>
                    </p>
                <?php endif; ?>

                <?php if ($row['wp_id']): ?>
                    <div class="ifprog-discovery-meta">
                        <span><strong>Dash:</strong> Season #<?php echo esc_html($row['dash_id']); ?></span>
                        <span><strong>WordPress:</strong> <a href="<?php echo esc_url(get_edit_post_link($row['wp_id'])); ?>"><?php echo esc_html(get_the_title($row['wp_id'])); ?></a> (#<?php echo esc_html($row['wp_id']); ?>)</span>
                        <span><strong>Publication:</strong> <?php echo esc_html(ucfirst($row['wp_status'] ?: 'unknown')); ?></span>
                        <span><strong>Lifecycle:</strong> <?php echo esc_html(ucfirst($row['wp_lifecycle'] ?: 'unknown')); ?></span>
                        <?php if (get_post_meta($row['wp_id'], '_ifprog_is_production', true) === '1'):
                            $production_id = absint(get_post_meta($row['wp_id'], '_ifprog_production_id', true)); ?>
                            <span><strong>Production:</strong>
                                <?php if ($production_id && get_post_type($production_id) === 'ifp_production'): ?>
                                    <a href="<?php echo esc_url(get_edit_post_link($production_id)); ?>"><?php echo esc_html(get_the_title($production_id)); ?></a>
                                <?php else: ?>Not linked yet<?php endif; ?>
                            </span>
                        <?php endif; ?>
                        <span><strong>Last sync:</strong> <?php echo esc_html(self::admin_datetime_label($row['last_sync'])); ?></span>
                        <?php if ($row['checked_at']): ?><span><strong>Last checked:</strong> <?php echo esc_html(self::admin_datetime_label($row['checked_at'])); ?></span><?php endif; ?>
                        <span><strong>Protection:</strong> <?php echo $row['protected_fields'] ? esc_html($row['protected_fields'] . ' local field overrides') : 'local presentation preserved'; ?></span>
                    </div>
                <?php else: ?>
                    <p class="ifprog-preview-sub">Dash Season #<?php echo esc_html($row['dash_id']); ?> has not been imported.</p>
                <?php endif; ?>

                <?php if ($row['changes']): ?>
                    <ul class="ifprog-discovery-changes">
                        <?php foreach ($row['changes'] as $change): ?><li><?php echo esc_html($change); ?></li><?php endforeach; ?>
                    </ul>
                    <?php if ($has_source_removals): ?><p class="ifprog-preview-sub">Source removals require review; protected sync never deletes or unpublishes local records.</p><?php endif; ?>
                <?php endif; ?>
                <?php if ($row['check_error']): ?>
                    <p class="ifprog-preview-warning">Change check could not finish: <?php echo esc_html($row['check_error']); ?></p>
                <?php elseif ($row['baseline_created']): ?>
                    <p class="ifprog-preview-sub">Initial 1.8 comparison snapshot established.</p>
                <?php endif; ?>
            </div>

            <div class="ifprog-discovery-actions">
                <?php if ($row['state'] === 'excluded'): ?>
                    <form method="post">
                        <?php wp_nonce_field('ifprog_dash_preview', 'ifprog_preview_nonce'); ?>
                        <input type="hidden" name="ifprog_dash_season_id" value="<?php echo esc_attr($row['dash_id']); ?>">
                        <input type="hidden" name="ifprog_discovery_view" value="<?php echo esc_attr($view); ?>">
                        <input type="hidden" name="ifprog_discovery_filter" value="<?php echo esc_attr($filter); ?>">
                        <button class="button button-primary" type="submit" name="ifprog_preview_action" value="restore_season">Restore</button>
                    </form>
                    <?php if ($row['excluded_at']): ?><small>Excluded <?php echo esc_html(self::admin_datetime_label($row['excluded_at'])); ?></small><?php endif; ?>
                <?php else: ?>
                    <?php if ($row['wp_id']): ?>
                        <form method="post">
                            <?php wp_nonce_field('ifprog_dash_preview', 'ifprog_preview_nonce'); ?>
                            <input type="hidden" name="ifprog_dash_season_id" value="<?php echo esc_attr($row['dash_id']); ?>">
                            <input type="hidden" name="ifprog_discovery_view" value="<?php echo esc_attr($view); ?>">
                            <input type="hidden" name="ifprog_discovery_filter" value="<?php echo esc_attr($filter); ?>">
                            <button class="button" type="submit" name="ifprog_preview_action" value="check_season">Check Changes</button>
                        </form>
                    <?php endif; ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=ifprog-preview#ifprog-protected-preview')); ?>">
                        <?php wp_nonce_field('ifprog_dash_preview', 'ifprog_preview_nonce'); ?>
                        <input type="hidden" name="ifprog_dash_season_id" value="<?php echo esc_attr($row['dash_id']); ?>">
                        <input type="hidden" name="ifprog_discovery_view" value="<?php echo esc_attr($view); ?>">
                        <input type="hidden" name="ifprog_discovery_filter" value="<?php echo esc_attr($filter); ?>">
                        <button class="button button-primary" type="submit" name="ifprog_preview_action" value="preview"><?php echo esc_html($action_labels[$row['state']] ?? 'Preview'); ?></button>
                    </form>
                    <form method="post" data-ifprog-exclude-form>
                        <?php wp_nonce_field('ifprog_dash_preview', 'ifprog_preview_nonce'); ?>
                        <input type="hidden" name="ifprog_dash_season_id" value="<?php echo esc_attr($row['dash_id']); ?>">
                        <input type="hidden" name="ifprog_discovery_view" value="<?php echo esc_attr($view); ?>">
                        <input type="hidden" name="ifprog_discovery_filter" value="<?php echo esc_attr($filter); ?>">
                        <button class="button button-link-delete" type="submit" name="ifprog_preview_action" value="exclude_season"><?php echo $row['wp_id'] ? 'Exclude from Discovery' : 'Never Import'; ?></button>
                    </form>
                <?php endif; ?>
            </div>
        </article>
        <?php
    }

    private static function render_preview($preview) {
        $season = $preview['season'];
        $existing_season_id = absint($preview['existing_season_id'] ?? 0);
        $rows = $preview['rows'];
        $eligible = count(array_filter($rows, function($row) { return $row['eligible']; }));
        $team_rows = array_values(array_filter($rows, function($row) {
            return ($row['row_type'] ?? 'team') === 'team';
        }));
        $standalone_rows = array_values(array_filter($rows, function($row) {
            return ($row['row_type'] ?? 'team') === 'level';
        }));
        $updates = count(array_filter($team_rows, function($row) { return $row['eligible'] && $row['existing_id']; }));
        $creates = count(array_filter($team_rows, function($row) { return $row['eligible'] && !$row['existing_id']; }));
        $levels = [];
        foreach ($rows as $row) {
            if (!$row['eligible']) continue;
            $levels[absint($row['league_id'])] = absint($row['existing_level_id']);
        }
        $level_updates = count(array_filter($levels));
        $level_creates = count($levels) - $level_updates;
        $companion = (array) apply_filters('ifprog_production_companion_preview', [], $preview);
        ?>
        <section id="ifprog-protected-preview" class="ifprog-card">
            <div class="ifprog-preview-heading">
                <div>
                    <p class="ifprog-kicker">Protected sync</p>
                    <h2><?php echo esc_html($season['name'] ?? 'Selected Season'); ?></h2>
                    <p><?php echo esc_html(self::date_range($season['start_date'] ?? '', $season['end_date'] ?? '')); ?></p>
                    <p>
                        <span class="ifprog-status ifprog-status--<?php echo $existing_season_id ? 'open' : 'soon'; ?>">
                            Season would <?php echo $existing_season_id ? 'update' : 'be created'; ?>
                        </span>
                        <?php if ($existing_season_id): ?><span class="ifprog-preview-sub">WordPress Season #<?php echo esc_html($existing_season_id); ?></span><?php endif; ?>
                    </p>
                </div>
                <div class="ifprog-preview-summary">
                    <span><strong><?php echo esc_html(count($team_rows)); ?></strong> Teams found</span>
                    <?php if ($standalone_rows): ?>
                        <span><strong><?php echo esc_html(count($standalone_rows)); ?></strong> standalone Levels</span>
                    <?php endif; ?>
                    <span><strong><?php echo esc_html(count($levels)); ?></strong> Levels found</span>
                    <span><strong><?php echo esc_html($level_creates); ?></strong> new Levels</span>
                    <span><strong><?php echo esc_html($level_updates); ?></strong> linked Levels</span>
                    <span><strong><?php echo esc_html($creates); ?></strong> would create</span>
                    <span><strong><?php echo esc_html($updates); ?></strong> would update</span>
                </div>
            </div>
            <p>
                Registration:
                <strong><?php echo esc_html(self::date_time_label($season['signup_start'] ?? '')); ?></strong>
                through
                <strong><?php echo esc_html(self::date_time_label($season['signup_end'] ?? '')); ?></strong>
            </p>
            <?php if ($rows): ?>
                <p class="ifprog-preview-callout">Draft-first remains the default. The optional publish setting publishes the selected Programs, standalone or parent Levels, and Season during this import. Refreshing linked records preserves publication status along with local titles, descriptions, ordering, taxonomies, registration links, and button text unless you explicitly select a presentation replacement below.</p>
            <?php else: ?>
                <p class="ifprog-preview-callout">No Team classes have been added to this Dash Season yet. You can import the Season by itself now and return later to import its Levels and Programs.</p>
            <?php endif; ?>
            <?php if (!empty($companion['enabled'])): ?>
                <div class="ifprog-preview-callout">
                    <strong>Production companion:</strong>
                    <?php echo esc_html($companion['message'] ?? 'The linked Production hierarchy will be updated after a clean Programming sync.'); ?>
                </div>
            <?php endif; ?>
        </section>

        <?php if (stripos((string) ($season['name'] ?? ''), 'Learn to Play') !== false): ?>
            <section class="ifprog-card">
                <h2>Adult Development Camp drop-in</h2>
                <p>This recurring Team lives outside the Learn to Play Dash Season. Enter its current Dash Team ID to include it in this preview and keep its Program routed to the current Learn to Play Season.</p>
                <form method="post">
                    <?php wp_nonce_field('ifprog_dash_preview', 'ifprog_preview_nonce'); ?>
                    <input type="hidden" name="ifprog_dash_season_id" value="<?php echo esc_attr($preview['season_id']); ?>">
                    <label><strong>Current drop-in Dash Team ID</strong><br>
                        <input class="regular-text" type="number" min="1" name="ifprog_dropin_team_id" value="<?php echo esc_attr(absint($preview['dropin_team_id'] ?? 0)); ?>" placeholder="Team ID">
                    </label>
                    <button class="button" type="submit" name="ifprog_preview_action" value="preview">Load Team in Preview</button>
                </form>
            </section>
        <?php endif; ?>

        <section class="ifprog-card ifprog-preview-table-card">
            <h2>2. <?php echo $rows ? 'Review Proposed Imports' : 'Review Season'; ?></h2>
            <?php if (!$rows): ?>
                <p>No Team or Level records were found for this Season. The Season name, dates, registration window, Dash link, and lifecycle can still be synchronized.</p>
                <form method="post">
                    <?php wp_nonce_field('ifprog_dash_preview', 'ifprog_preview_nonce'); ?>
                    <input type="hidden" name="ifprog_dash_season_id" value="<?php echo esc_attr($preview['season_id']); ?>">
                    <?php if (!empty($preview['dropin_team_id'])): ?><input type="hidden" name="ifprog_dropin_team_id" value="<?php echo esc_attr(absint($preview['dropin_team_id'])); ?>"><?php endif; ?>
                    <input type="hidden" name="ifprog_season_only" value="1">
                    <div class="ifprog-sync-box">
                        <div>
                            <h3>3. Import Season safely</h3>
                            <p>The Season will be created as a draft or its linked Dash facts will be refreshed. No placeholder Levels or Programs will be created.</p>
                            <?php self::render_classification_choices($preview); ?>
                            <fieldset class="ifprog-sync-options">
                                <legend>Optional Dash presentation updates</legend>
                                <div class="ifprog-sync-options__grid">
                                    <label class="ifprog-sync-choice">
                                        <input type="checkbox" name="ifprog_update_season_title" value="1">
                                        <span><strong>Season name</strong><small>Use the current Dash Season name.</small></span>
                                    </label>
                                    <label class="ifprog-sync-choice">
                                        <input type="checkbox" name="ifprog_update_season_description" value="1">
                                        <span><strong>Season description</strong><small>Safely adopt Dash changes, including a removed description.</small></span>
                                    </label>
                                </div>
                                <p class="description">Both options are unchecked by default. Dash description changes apply only while the website copy still matches the previously imported Dash text; WordPress edits remain protected.</p>
                            </fieldset>
                            <label class="ifprog-publish-option">
                                <input type="checkbox" name="ifprog_publish_imported" value="1">
                                <span>
                                    <strong>Publish the Season immediately</strong>
                                    <small>Leave unchecked to create it as a draft or preserve the publication status of an existing Season.</small>
                                </span>
                            </label>
                            <?php if (!empty($companion['enabled'])): ?>
                                <label class="ifprog-publish-option">
                                    <input type="checkbox" name="ifprog_sync_production_participants" value="1">
                                    <span><strong>Synchronize Production participants</strong><small>Optional: refresh registered People after the Production companion is updated.</small></span>
                                </label>
                            <?php endif; ?>
                        </div>
                        <button class="button button-primary button-hero" type="submit" name="ifprog_preview_action" value="import">Import Season Only</button>
                    </div>
                </form>
            <?php else: ?>
                <form method="post">
                    <?php wp_nonce_field('ifprog_dash_preview', 'ifprog_preview_nonce'); ?>
                    <input type="hidden" name="ifprog_dash_season_id" value="<?php echo esc_attr($preview['season_id']); ?>">
                    <?php if (!empty($preview['dropin_team_id'])): ?><input type="hidden" name="ifprog_dropin_team_id" value="<?php echo esc_attr(absint($preview['dropin_team_id'])); ?>"><?php endif; ?>
                    <div class="ifprog-selection-tools">
                        <span data-ifprog-selection-count><?php echo esc_html($eligible); ?> of <?php echo esc_html($eligible); ?> selected</span>
                        <div>
                            <button class="button button-small" type="button" data-ifprog-select="all">Select all</button>
                            <button class="button button-small" type="button" data-ifprog-select="none">Deselect all</button>
                        </div>
                    </div>
                    <div class="ifprog-table-scroll">
                        <table class="widefat striped ifprog-preview-table">
                            <thead>
                                <tr>
                                    <th class="ifprog-preview-select"><span class="screen-reader-text">Select</span></th>
                                    <th>Proposed Import</th>
                                    <th>Sport / Level / Format</th>
                                    <th>Schedule</th>
                                    <th>Registration</th>
                                    <th>Session Price</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $row): ?>
                                    <?php $standalone_level = ($row['row_type'] ?? 'team') === 'level'; ?>
                                    <tr class="<?php echo $row['eligible'] ? '' : 'ifprog-preview-row--muted'; ?>">
                                        <td class="ifprog-preview-select">
                                            <?php if ($row['eligible']): ?>
                                                <input
                                                    type="checkbox"
                                                    name="<?php echo $standalone_level ? 'ifprog_level_ids[]' : 'ifprog_team_ids[]'; ?>"
                                                    value="<?php echo esc_attr($standalone_level ? $row['league_id'] : $row['team_id']); ?>"
                                                    checked
                                                    aria-label="<?php echo esc_attr('Import ' . $row['title']); ?>"
                                                >
                                            <?php else: ?>
                                                <span aria-hidden="true">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong><?php echo esc_html($row['title']); ?></strong>
                                            <?php if ($standalone_level): ?>
                                                <span class="ifprog-preview-sub">Standalone Level · League #<?php echo esc_html($row['league_id']); ?></span>
                                            <?php else: ?>
                                                <span class="ifprog-preview-sub">Team #<?php echo esc_html($row['team_id']); ?><?php echo $row['level'] ? ' · ' . esc_html($row['level']) : ''; ?></span>
                                            <?php endif; ?>
                                            <?php if ($row['age_range']): ?><span class="ifprog-preview-sub"><?php echo esc_html($row['age_range']); ?></span><?php endif; ?>
                                        </td>
                                        <td>
                                            <?php self::render_row_sport($row); ?><br>
                                            <strong><?php echo esc_html($row['level'] ?: 'Needs Level'); ?></strong>
                                            <span class="ifprog-preview-sub">League #<?php echo esc_html($row['league_id']); ?> · Level would <?php echo $row['existing_level_id'] ? 'update' : 'be created'; ?></span>
                                            <span class="ifprog-preview-sub"><?php echo esc_html($row['format']); ?></span>
                                        </td>
                                        <td>
                                            <?php echo esc_html($row['schedule'] ?: ($standalone_level ? 'No class schedule' : 'Not supplied')); ?>
                                            <?php if ($row['start_date']): ?>
                                                <span class="ifprog-preview-sub">
                                                    <?php echo esc_html(self::date_range($row['start_date'], $row['end_date'])); ?>
                                                    <?php if (($row['date_source'] ?? '') === 'events'): ?> · Scheduled Events<?php endif; ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="ifprog-status ifprog-status--<?php echo esc_attr(self::registration_status_class($row['registration_status'])); ?>"><?php echo esc_html($row['registration_status'] ? ucwords(str_replace('_', ' ', $row['registration_status'])) : 'Unknown'); ?></span>
                                            <span class="ifprog-preview-sub"><?php echo esc_html($row['availability']); ?></span>
                                            <?php $preview_registration_url = $standalone_level ? $row['level_registration_url'] : $row['registration_url']; ?>
                                            <?php if ($preview_registration_url): ?>
                                                <span class="ifprog-preview-sub"><a href="<?php echo esc_url($preview_registration_url); ?>" target="_blank" rel="noopener noreferrer"><?php echo $standalone_level ? 'Level registration link ready' : 'Registration link ready'; ?></a></span>
                                            <?php else: ?>
                                                <span class="ifprog-preview-sub">Registration link unavailable</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($standalone_level): ?>
                                                <strong>Not applicable</strong>
                                            <?php elseif ($row['session_price']['total']): ?>
                                                <strong><?php echo esc_html($row['session_price']['total']); ?></strong>
                                            <?php else: ?>
                                                <strong>Review manually</strong>
                                            <?php endif; ?>
                                            <?php if ($row['session_price']['detail']): ?><span class="ifprog-preview-sub"><?php echo esc_html($row['session_price']['detail']); ?></span><?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($row['eligible']): ?>
                                                <?php if ($standalone_level): ?>
                                                    <strong><?php echo $row['existing_level_id'] ? 'Update Level' : 'Create Level'; ?></strong>
                                                    <?php if ($row['existing_level_id']): ?><span class="ifprog-preview-sub">Level #<?php echo esc_html($row['existing_level_id']); ?></span><?php endif; ?>
                                                <?php else: ?>
                                                    <strong><?php echo $row['existing_id'] ? 'Update' : 'Create'; ?></strong>
                                                    <?php if ($row['existing_id']): ?><span class="ifprog-preview-sub">Program #<?php echo esc_html($row['existing_id']); ?></span><?php endif; ?>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <strong>Skip</strong>
                                            <?php endif; ?>
                                            <?php if ($row['warnings']): ?><span class="ifprog-preview-warning"><?php echo esc_html(implode(' · ', $row['warnings'])); ?></span><?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="ifprog-sync-box">
                        <div>
                            <h3>3. Import selected safely</h3>
                            <p>Linked records receive refreshed Dash facts while your local presentation remains protected. Optional replacements are unchecked by default and apply only to existing records included in this sync.</p>
                            <?php self::render_classification_choices($preview); ?>
                            <fieldset class="ifprog-sync-options">
                                <legend>Optional Dash presentation updates</legend>
                                <div class="ifprog-sync-options__grid">
                                    <label class="ifprog-sync-choice">
                                        <input type="checkbox" name="ifprog_update_season_title" value="1">
                                        <span><strong>Season name</strong><small>Use the current Dash Season name.</small></span>
                                    </label>
                                    <label class="ifprog-sync-choice">
                                        <input type="checkbox" name="ifprog_update_season_description" value="1">
                                        <span><strong>Season description</strong><small>Safely adopt Dash changes, including a removed description.</small></span>
                                    </label>
                                    <label class="ifprog-sync-choice">
                                        <input type="checkbox" name="ifprog_update_level_titles" value="1">
                                        <span><strong>Level names</strong><small>Use current Dash League names for selected parent or standalone Levels.</small></span>
                                    </label>
                                    <label class="ifprog-sync-choice">
                                        <input type="checkbox" name="ifprog_update_level_descriptions" value="1">
                                        <span><strong>Level descriptions</strong><small>Safely adopt changed or removed Dash League descriptions.</small></span>
                                    </label>
                                    <label class="ifprog-sync-choice">
                                        <input type="checkbox" name="ifprog_update_program_titles" value="1">
                                        <span><strong>Class names</strong><small>Use current Dash Team names for selected classes.</small></span>
                                    </label>
                                    <label class="ifprog-sync-choice">
                                        <input type="checkbox" name="ifprog_update_program_descriptions" value="1">
                                        <span><strong>Class descriptions</strong><small>Safely adopt changed or removed Dash Team descriptions and excerpts.</small></span>
                                    </label>
                                </div>
                                <p class="description">Dash description changes—including removals—apply only where the website copy still matches the previously imported Dash text. WordPress edits, Groups, categories, ordering, button text, and local registration links remain protected.</p>
                            </fieldset>
                            <label class="ifprog-publish-option">
                                <input type="checkbox" name="ifprog_publish_imported" value="1">
                                <span>
                                    <strong>Publish imported records immediately</strong>
                                    <small>Publishes the selected Programs, parent or standalone Levels, and the Season. Leave unchecked to preserve the current draft-first workflow.</small>
                                </span>
                            </label>
                            <?php if (!empty($companion['enabled'])): ?>
                                <label class="ifprog-publish-option">
                                    <input type="checkbox" name="ifprog_sync_production_participants" value="1">
                                    <span><strong>Synchronize Production participants</strong><small>Optional: refresh registered People for the synchronized Production Groups.</small></span>
                                </label>
                            <?php endif; ?>
                        </div>
                        <button class="button button-primary button-hero" type="submit" name="ifprog_preview_action" value="import">Import Selected Safely</button>
                    </div>
                </form>
            <?php endif; ?>
        </section>
        <?php
    }

    public static function row_sport_ids($row) {
        $existing_id = absint(($row['row_type'] ?? 'team') === 'level' ? ($row['existing_level_id'] ?? 0) : ($row['existing_id'] ?? 0));
        if ($existing_id) {
            $ids = wp_get_object_terms($existing_id, 'ifprog_sport', ['fields' => 'ids']);
            if (!is_wp_error($ids) && $ids) return array_map('absint', $ids);
        }
        return IFPROG_Post_Types::assignment_term_ids('ifprog_sport', array_filter([(string) ($row['sport']['slug'] ?? '')]));
    }

    private static function render_row_sport($row) {
        $key = ($row['row_type'] ?? 'team') === 'level' ? 'level_' . absint($row['league_id']) : 'team_' . absint($row['team_id']);
        $ids = self::row_sport_ids($row);
        $terms = get_terms(['taxonomy' => 'ifprog_sport', 'hide_empty' => false]);
        if (is_wp_error($terms)) $terms = [];
        ?>
        <label>Sport
            <select name="ifprog_row_sports[<?php echo esc_attr($key); ?>]" aria-label="<?php echo esc_attr('Sport for ' . $row['title']); ?>">
                <option value="0">Choose Sport / single bulk fallback</option>
                <?php if (count($ids) > 1): ?><option value="preserve" selected>Keep existing Sports</option><?php endif; ?>
                <?php foreach ($terms as $term): ?>
                    <option value="<?php echo esc_attr($term->term_id); ?>" <?php selected(count($ids) === 1 && in_array(absint($term->term_id), $ids, true)); ?>><?php echo esc_html($term->name); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php
    }

    private static function render_classification_choices($preview) {
        $guesses = self::classification_guesses($preview);
        $taxonomies = [
            'sport' => ['taxonomy' => 'ifprog_sport', 'label' => 'Sport'],
            'format' => ['taxonomy' => 'ifprog_format', 'label' => 'Format'],
            'category' => ['taxonomy' => 'ifprog_category', 'label' => 'Category'],
        ];
        ?>
        <fieldset class="ifprog-classification-options">
            <legend>Bulk classification</legend>
            <p class="description">Bulk Sport applies to the Season and is a fallback only for rows without an individual Sport when exactly one bulk Sport is selected. Each class's Sport choice takes precedence. Existing parent Level Sports are preserved. Bulk Format and Category still apply to selected records.</p>
            <div class="ifprog-classification-options__grid">
                <?php foreach ($taxonomies as $key => $config): ?>
                    <?php $terms = get_terms(['taxonomy' => $config['taxonomy'], 'hide_empty' => false]); ?>
                    <div class="ifprog-classification-group">
                        <strong><?php echo esc_html($config['label']); ?></strong>
                        <?php if (is_wp_error($terms) || !$terms): ?>
                            <span class="description">No choices available.</span>
                        <?php else: ?>
                            <?php foreach ($terms as $term): ?>
                                <label><input type="checkbox" name="ifprog_classification[<?php echo esc_attr($key); ?>][]" value="<?php echo esc_attr($term->term_id); ?>" <?php checked(in_array($term->term_id, $guesses[$key], true)); ?>> <?php echo esc_html($term->name); ?></label>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <?php
    }

    private static function classification_guesses($preview) {
        $season = (array) ($preview['season'] ?? []);
        $rows = (array) ($preview['rows'] ?? []);
        $classification = self::season_classification($season, $rows);
        $category_slugs = self::category_guesses($season, $rows, $classification['formats']);
        $guesses = [
            'sport' => IFPROG_Post_Types::assignment_term_ids('ifprog_sport', $classification['sports']),
            'format' => IFPROG_Post_Types::assignment_term_ids('ifprog_format', $classification['formats']),
            'category' => IFPROG_Post_Types::assignment_term_ids('ifprog_category', $category_slugs),
        ];
        $existing_season_id = absint($preview['existing_season_id'] ?? 0);
        if ($existing_season_id) {
            foreach (['sport' => 'ifprog_sport', 'format' => 'ifprog_format', 'category' => 'ifprog_category'] as $key => $taxonomy) {
                $existing = wp_get_object_terms($existing_season_id, $taxonomy, ['fields' => 'ids']);
                if (!is_wp_error($existing) && $existing) $guesses[$key] = array_map('absint', $existing);
            }
        }
        return $guesses;
    }

    private static function category_guesses($season, $rows, $formats) {
        $parts = [(string) ($season['name'] ?? ''), (string) ($season['description'] ?? '')];
        foreach ((array) $rows as $row) {
            $parts[] = (string) ($row['title'] ?? '');
            $parts[] = (string) ($row['level'] ?? '');
        }
        $haystack = strtolower(implode(' ', $parts));
        $slugs = [];
        if (strpos($haystack, 'learn to skate') !== false || strpos($haystack, 'snowplow') !== false || preg_match('/\bbasic\s*[1-8]?\b/i', $haystack)) $slugs[] = 'learn-to-skate';
        if (strpos($haystack, 'learn to play') !== false) $slugs[] = 'learn-to-play';
        if (strpos($haystack, 'specialty') !== false) $slugs[] = 'specialty-classes';
        if (in_array('camp', (array) $formats, true) || in_array('clinic', (array) $formats, true)) $slugs[] = 'camps-clinics';
        if (strpos($haystack, 'homeschool') !== false || preg_match('/(^|\s)hs\b/i', $haystack)) $slugs[] = 'homeschool';
        if (strpos($haystack, 'adaptive') !== false) $slugs[] = 'adaptive';
        return array_values(array_unique($slugs));
    }

    private static function collection_data($result) {
        return is_array($result['data'] ?? null) ? $result['data'] : [];
    }

    private static function attributes($record) {
        return is_array($record['attributes'] ?? null) ? $record['attributes'] : [];
    }

    private static function index_records($records) {
        $indexed = [];
        foreach ((array) $records as $record) {
            $id = absint($record['id'] ?? 0);
            if ($id) $indexed[$id] = $record;
        }
        return $indexed;
    }

    private static function find_record($records, $id) {
        foreach ((array) $records as $record) {
            if (absint($record['id'] ?? 0) === absint($id)) return $record;
        }
        return null;
    }

    private static function existing_team_programs() {
        $ids = get_posts([
            'post_type' => 'ifprog_program',
            'post_status' => array_keys(get_post_stati()),
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_key' => '_ifprog_dash_source_type',
            'meta_value' => 'team',
        ]);
        $mapped = [];
        foreach ($ids as $post_id) {
            $source_id = absint(get_post_meta($post_id, '_ifprog_dash_source_id', true));
            if ($source_id) $mapped[$source_id] = absint($post_id);
        }
        return $mapped;
    }

    private static function existing_season($dash_season_id) {
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

    private static function existing_levels($season_post_id) {
        $ids = $season_post_id ? get_posts([
            'post_type' => 'ifprog_level',
            'post_status' => array_keys(get_post_stati()),
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_key' => '_ifprog_level_season_id',
            'meta_value' => absint($season_post_id),
        ]) : [];
        $mapped = [];
        foreach ($ids as $post_id) {
            $league_id = absint(get_post_meta($post_id, '_ifprog_dash_league_id', true));
            if ($league_id) $mapped[$league_id] = absint($post_id);
        }

        if (empty($mapped[IFPROG_Sync::CURRENT_LEARN_TO_PLAY_LEAGUE_ID])) {
            $routed_ids = get_posts([
                'post_type' => 'ifprog_level',
                'post_status' => array_keys(get_post_stati()),
                'posts_per_page' => 1,
                'fields' => 'ids',
                'meta_key' => '_ifprog_dash_league_id',
                'meta_value' => IFPROG_Sync::CURRENT_LEARN_TO_PLAY_LEAGUE_ID,
            ]);
            if ($routed_ids) {
                $mapped[IFPROG_Sync::CURRENT_LEARN_TO_PLAY_LEAGUE_ID] = absint($routed_ids[0]);
            }
        }
        return $mapped;
    }

    public static function sport($sport_id) {
        $map = apply_filters('ifprog_dash_sport_map', [
            20 => ['slug' => 'hockey', 'label' => 'Hockey'],
            27 => ['slug' => 'figure-skating', 'label' => 'Figure Skating'],
        ]);
        return $map[$sport_id] ?? ['slug' => '', 'label' => ''];
    }

    public static function format($season, $league = [], $team = []) {
        $haystack = strtolower(implode(' ', [
            $season['name'] ?? '',
            $league['name'] ?? '',
            $team['name'] ?? '',
        ]));
        if (strpos($haystack, 'drop-in') !== false || strpos($haystack, 'drop in') !== false) return 'Drop-In';
        if (strpos($haystack, 'camp') !== false) return 'Camp';
        if (strpos($haystack, 'clinic') !== false || strpos($haystack, 'seminar') !== false) return 'Clinic';
        if (strpos($haystack, 'league') !== false) return 'League';
        return 'Class';
    }

    /**
     * Classify a Season even when Dash has not created its League or Team
     * records yet. Imported row classifications win; saved Season fields and
     * conservative name matching provide the Season-only fallback.
     */
    public static function season_classification($season, $rows = []) {
        $season = is_array($season) ? $season : [];
        $sports = [];
        $formats = [];

        foreach ((array) $rows as $row) {
            $sport = sanitize_title((string) ($row['sport']['slug'] ?? ''));
            $format = sanitize_title((string) ($row['format'] ?? ''));
            if ($sport !== '') $sports[] = $sport;
            if ($format !== '') $formats[] = $format;
        }

        if (!$sports) {
            $mapped_sport = self::sport(absint($season['sport_id'] ?? 0));
            $sport = sanitize_title((string) ($mapped_sport['slug'] ?? ''));
            if ($sport !== '') $sports[] = $sport;
        }

        $haystack = strtolower(implode(' ', array_filter([
            $season['name'] ?? '',
            $season['title'] ?? '',
            $season['description'] ?? '',
            $season['program_name'] ?? '',
            $season['sport_name'] ?? '',
            $season['sport'] ?? '',
            $season['type'] ?? '',
        ], 'is_scalar')));

        if (!$formats) {
            $formats[] = sanitize_title(self::format($season));
        }

        if (!$sports) {
            if (
                strpos($haystack, 'hockey') !== false ||
                strpos($haystack, 'learn to play') !== false ||
                in_array('league', $formats, true)
            ) {
                $sports[] = 'hockey';
            } elseif (
                strpos($haystack, 'figure') !== false ||
                strpos($haystack, 'learn to skate') !== false ||
                strpos($haystack, 'skating') !== false ||
                strpos($haystack, 'freestyle') !== false ||
                strpos($haystack, 'snowplow') !== false
            ) {
                $sports[] = 'figure-skating';
            }
        }

        return [
            'sports' => array_values(array_unique(array_filter($sports))),
            'formats' => array_values(array_unique(array_filter($formats))),
        ];
    }

    private static function description($team, $league) {
        foreach ([
            $team['description'] ?? '',
            $team['best_description'] ?? '',
            $league['description'] ?? '',
            $league['best_description'] ?? '',
        ] as $description) {
            if (trim((string) $description) !== '') return wp_kses_post((string) $description);
        }
        return '';
    }

    private static function schedule($team) {
        $start = IFPROG_Status::timestamp($team['start_date'] ?? '');
        if (!$start) return '';

        $day_map = [
            'su' => 'Sunday',
            'mo' => 'Monday',
            'tu' => 'Tuesday',
            'we' => 'Wednesday',
            'th' => 'Thursday',
            'fr' => 'Friday',
            'sa' => 'Saturday',
        ];
        preg_match_all('/su|mo|tu|we|th|fr|sa/', strtolower((string) ($team['days_of_week'] ?? '')), $matches);
        $days = array_values(array_unique(array_map(function($code) use ($day_map) {
            return $day_map[$code] ?? '';
        }, $matches[0] ?? [])));
        $days = array_values(array_filter($days));
        if (!$days) $days[] = wp_date('l', $start);

        $label = implode(' & ', $days) . ', ' . wp_date(get_option('time_format'), $start);
        $length = absint($team['event_length'] ?? 0);
        if ($length) $label .= '–' . wp_date(get_option('time_format'), $start + ($length * MINUTE_IN_SECONDS));
        return $label;
    }

    private static function session_price($product, $team, $league, $event_starts = []) {
        $price = $product['price'] ?? '';
        $class_count = absint($team['num_games'] ?? ($league['num_games'] ?? 0));
        $unit = self::duration_unit($event_starts, $team, $league, $class_count);
        if ($price === '' || !is_numeric($price)) {
            return [
                'total' => '',
                'detail' => 'No Product price supplied',
                'weeks' => $class_count,
                'unit' => $unit,
            ];
        }

        $unit_price = (float) $price;
        $is_flat_fee = self::dash_truthy($team['is_flat_fee'] ?? ($league['is_flat_fee'] ?? ''));

        if ($is_flat_fee) {
            return [
                'total' => self::currency($unit_price),
                'detail' => 'Flat session price',
                'weeks' => $class_count,
                'unit' => $unit,
            ];
        }

        if (!$class_count) {
            return [
                'total' => '',
                'detail' => self::currency($unit_price) . ' per class; class count unavailable',
                'weeks' => 0,
                'unit' => $unit,
            ];
        }

        return [
            'total' => self::currency($unit_price * $class_count),
            'detail' => sprintf(
                '%d %s × %s',
                $class_count,
                $class_count === 1 ? $unit : $unit . 's',
                self::currency($unit_price)
            ),
            'weeks' => $class_count,
            'unit' => $unit,
        ];
    }

    private static function season_events_by_team($season, $args = []) {
        $start = self::date_only($season['start_date'] ?? '');
        $end = self::date_only($season['end_date'] ?? '');
        if ($start === '' || $end === '') return [];

        $indexed = [];
        $cursor = new DateTimeImmutable($start);
        $season_end = new DateTimeImmutable($end);
        while ($cursor <= $season_end) {
            $window_end = $cursor->modify('+6 days');
            if ($window_end > $season_end) $window_end = $season_end;
            $result = IFPROG_Dash::events([
                'filter[start__gte]' => $cursor->format('Y-m-d') . 'T00:00:00',
                'filter[start__lte]' => $window_end->format('Y-m-d') . 'T23:59:59',
                'sort' => 'start',
                'page[size]' => 100,
            ], wp_parse_args($args, ['max_pages' => 10]));
            if (is_wp_error($result)) return [];
            foreach (self::collection_data($result) as $record) {
                $event = self::attributes($record);
                $team_id = absint($event['hteam_id'] ?? 0);
                $timestamp = IFPROG_Status::timestamp($event['start'] ?? '');
                if ($team_id && $timestamp) $indexed[$team_id][] = $timestamp;
            }
            $cursor = $cursor->modify('+7 days');
        }
        foreach ($indexed as &$starts) {
            $starts = array_values(array_unique(array_map('intval', $starts)));
            sort($starts, SORT_NUMERIC);
        }
        unset($starts);
        return $indexed;
    }

    private static function duration_unit($event_starts, $team = [], $league = [], $class_count = 0) {
        $starts = array_values(array_unique(array_map('intval', (array) $event_starts)));
        sort($starts, SORT_NUMERIC);
        if (count($starts) >= 2) {
            $gaps = [];
            for ($index = 1; $index < count($starts); $index++) {
                $days = (int) round(($starts[$index] - $starts[$index - 1]) / DAY_IN_SECONDS);
                if ($days > 0) $gaps[] = $days;
            }
            if ($gaps) {
                sort($gaps, SORT_NUMERIC);
                $typical_gap = $gaps[(int) floor((count($gaps) - 1) / 2)];
                return $typical_gap <= 3 ? 'day' : 'week';
            }
        }

        $identity = strtolower(implode(' ', [
            (string) ($team['name'] ?? ''),
            (string) ($team['description'] ?? ''),
            (string) ($league['name'] ?? ''),
            (string) ($league['description'] ?? ''),
        ]));
        if (preg_match('/\b(camp|clinic)\b/', $identity)) return 'day';

        $start = IFPROG_Status::timestamp($team['start_date'] ?? '');
        $end = IFPROG_Status::timestamp($team['end_date'] ?? '');
        if ($start && $end && $end >= $start && $class_count > 1) {
            $span_days = (int) floor(($end - $start) / DAY_IN_SECONDS) + 1;
            if ($span_days <= $class_count + 1) return 'day';
            if ($span_days >= (($class_count - 1) * 5) + 1) return 'week';
        }

        preg_match_all('/su|mo|tu|we|th|fr|sa/', strtolower((string) ($team['days_of_week'] ?? '')), $matches);
        $scheduled_days = array_values(array_unique($matches[0] ?? []));
        if (count($scheduled_days) > 1) return 'day';

        return 'week';
    }

    private static function team_event_starts($team_id, $season, $args = []) {
        $team_id = absint($team_id);
        $start = self::date_only($season['start_date'] ?? '');
        $end = self::date_only($season['end_date'] ?? '');
        if (!$team_id || $start === '' || $end === '') return [];

        $result = IFPROG_Dash::events([
            'filter[hteam_id]' => $team_id,
            'filter[start__gte]' => $start . 'T00:00:00',
            'filter[start__lte]' => $end . 'T23:59:59',
            'sort' => 'start',
            'page[size]' => 100,
        ], wp_parse_args($args, ['max_pages' => 5]));
        if (is_wp_error($result)) return [];

        $starts = [];
        foreach (self::collection_data($result) as $record) {
            $event = self::attributes($record);
            if (absint($event['hteam_id'] ?? 0) !== $team_id) continue;
            $timestamp = IFPROG_Status::timestamp($event['start'] ?? '');
            if ($timestamp) $starts[] = $timestamp;
        }
        $starts = array_values(array_unique(array_map('intval', $starts)));
        sort($starts, SORT_NUMERIC);
        return $starts;
    }

    private static function program_end_date($team, $format, $event_starts = []) {
        $starts = array_values(array_unique(array_filter(array_map('intval', (array) $event_starts))));
        sort($starts, SORT_NUMERIC);
        if ($starts) return wp_date('Y-m-d', end($starts));

        $team_end = self::date_only($team['end_date'] ?? '');
        if ($team_end !== '') return $team_end;
        if (sanitize_title((string) $format) !== 'camp') return '';

        $start = IFPROG_Status::timestamp($team['start_date'] ?? '');
        $count = absint($team['num_games'] ?? 0);
        if (!$start || !$count) return '';
        return wp_date('Y-m-d', $start + (($count - 1) * DAY_IN_SECONDS));
    }

    private static function event_date_range($event_starts) {
        $starts = array_values(array_unique(array_filter(array_map('intval', (array) $event_starts))));
        sort($starts, SORT_NUMERIC);
        if (!$starts) return ['start' => '', 'end' => ''];

        return [
            'start' => wp_date('Y-m-d', reset($starts)),
            'end' => wp_date('Y-m-d', end($starts)),
        ];
    }

    private static function availability($registration) {
        if (!$registration) return 'Availability unavailable';
        $registered = absint($registration['registered_customers'] ?? 0);
        $max = absint($registration['max_registered_customers'] ?? 0);
        $waitlisted = absint($registration['waitlisted_customers'] ?? 0);
        $has_waitlist = self::dash_truthy($registration['has_waitlist'] ?? '');

        if ($max > 0) {
            $label = $registered . ' of ' . $max . ' registered';
            if ($registered >= $max) $label .= $has_waitlist ? ' · waitlist available' : ' · full';
        } else {
            $label = $registered . ' registered';
        }
        if ($waitlisted) $label .= ' · ' . $waitlisted . ' waitlisted';
        return $label;
    }

    private static function availability_note($registration) {
        if (!$registration) return '';

        $registered = absint($registration['registered_customers'] ?? 0);
        $max = absint($registration['max_registered_customers'] ?? 0);
        $waitlisted = absint($registration['waitlisted_customers'] ?? 0);
        $has_waitlist = self::dash_truthy($registration['has_waitlist'] ?? '');
        $status = sanitize_key((string) ($registration['registration_status'] ?? ''));

        if ($max > 0 && $registered >= $max) {
            return $has_waitlist
                ? 'This class is full. Waitlist registration is available.'
                : 'This class is full.';
        }
        if ($status === 'closed') return 'Registration is currently closed.';
        if ($waitlisted > 0) return 'A waitlist is currently active.';
        return '';
    }

    private static function default_season_id($seasons) {
        $now = current_datetime()->getTimestamp();
        $future = [];
        $current = [];
        foreach ($seasons as $season) {
            $attrs = self::attributes($season);
            $start = IFPROG_Status::timestamp($attrs['start_date'] ?? '');
            $end = IFPROG_Status::timestamp($attrs['end_date'] ?? '', true);
            $candidate = ['start' => $start, 'id' => absint($season['id'] ?? 0)];
            if ($start >= $now) $future[] = $candidate;
            elseif (!$end || $end >= $now) $current[] = $candidate;
        }
        if ($future) {
            usort($future, function($a, $b) {
                $date = $a['start'] <=> $b['start'];
                return $date !== 0 ? $date : ($a['id'] <=> $b['id']);
            });
            return absint($future[0]['id']);
        }
        if ($current) {
            usort($current, function($a, $b) {
                return $b['start'] <=> $a['start'];
            });
            return absint($current[0]['id']);
        }
        $first = reset($seasons);
        return absint($first['id'] ?? 0);
    }

    private static function sorted_seasons($seasons) {
        $now = current_datetime()->getTimestamp();
        usort($seasons, function($a, $b) use ($now) {
            $a_start = IFPROG_Status::timestamp(self::attributes($a)['start_date'] ?? '');
            $b_start = IFPROG_Status::timestamp(self::attributes($b)['start_date'] ?? '');
            $a_end = IFPROG_Status::timestamp(self::attributes($a)['end_date'] ?? '', true);
            $b_end = IFPROG_Status::timestamp(self::attributes($b)['end_date'] ?? '', true);
            $a_past = $a_end && $a_end < $now;
            $b_past = $b_end && $b_end < $now;
            if ($a_past !== $b_past) return $a_past ? 1 : -1;
            return $a_past ? ($b_start <=> $a_start) : ($a_start <=> $b_start);
        });
        return $seasons;
    }

    private static function season_option_label($season) {
        $name = sanitize_text_field((string) ($season['name'] ?? 'Unnamed Season'));
        $range = self::date_range($season['start_date'] ?? '', $season['end_date'] ?? '');
        return $range ? $name . ' — ' . $range : $name;
    }

    private static function date_only($value) {
        return preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $value, $match) ? $match[0] : '';
    }

    private static function datetime_local($value) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', (string) $value, $match)) return '';
        return $match[0];
    }

    private static function date_range($start, $end) {
        $start_ts = IFPROG_Status::timestamp($start);
        $end_ts = IFPROG_Status::timestamp($end);
        if (!$start_ts && !$end_ts) return '';
        if ($start_ts && $end_ts) {
            return wp_date(get_option('date_format'), $start_ts) . ' – ' . wp_date(get_option('date_format'), $end_ts);
        }
        return wp_date(get_option('date_format'), $start_ts ?: $end_ts);
    }

    private static function date_label($value) {
        $timestamp = IFPROG_Status::timestamp($value);
        return $timestamp ? wp_date(get_option('date_format'), $timestamp) : '';
    }

    private static function date_time_label($value) {
        $timestamp = IFPROG_Status::timestamp($value);
        return $timestamp ? wp_date(get_option('date_format') . ' \a\t ' . get_option('time_format'), $timestamp) : 'Not supplied';
    }

    private static function admin_datetime_label($value) {
        $timestamp = IFPROG_Status::timestamp($value);
        return $timestamp
            ? wp_date(get_option('date_format') . ' \a\t ' . get_option('time_format'), $timestamp)
            : 'Never';
    }

    private static function registration_status_class($status) {
        if ($status === 'open') return 'open';
        if ($status === 'closed') return 'closed';
        return 'soon';
    }

    private static function season_registration_status($season) {
        $now = current_datetime()->getTimestamp();
        $opens = IFPROG_Status::timestamp($season['signup_start'] ?? '');
        $closes = IFPROG_Status::timestamp($season['signup_end'] ?? '');

        if ($closes && $now > $closes) return 'closed';
        if ($opens && $now < $opens) return 'coming_soon';
        if (($opens && $now >= $opens) || ($closes && $now <= $closes)) return 'open';
        return 'unknown';
    }

    private static function currency($amount) {
        $amount = (float) $amount;
        $decimals = abs($amount - round($amount)) < 0.005 ? 0 : 2;
        return '$' . number_format_i18n($amount, $decimals);
    }

    private static function dash_truthy($value) {
        return in_array(strtolower(trim((string) $value)), ['1','yes','true','active','open'], true);
    }
}
