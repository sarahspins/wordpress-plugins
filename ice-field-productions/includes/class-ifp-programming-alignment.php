<?php
if (!defined('ABSPATH')) exit;

/**
 * Read-only Stage 2 audit for aligning Productions with Programming records.
 * This class deliberately performs no writes; Stage 3 will consume reviewed
 * matches through a separate protected migration action.
 */
class IFP_Programming_Alignment {
    const MIGRATION_ACTION = 'ifp_apply_programming_alignment';
    const ROLLBACK_ACTION = 'ifp_rollback_programming_alignment';
    const BACKUPS_OPTION = 'ifp_programming_alignment_backups';
    const MIGRATION_VERSION = '3.3.0';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu'], 30);
        add_action('admin_post_' . self::MIGRATION_ACTION, [__CLASS__, 'apply_migration']);
        add_action('admin_post_' . self::ROLLBACK_ACTION, [__CLASS__, 'rollback']);
    }

    public static function menu() {
        add_submenu_page(
            'ifp-dashboard',
            'Programming Alignment Audit',
            'Alignment Audit',
            'manage_options',
            'ifp-programming-alignment',
            [__CLASS__, 'page']
        );
    }

    public static function page() {
        if (!current_user_can('manage_options')) return;

        wp_enqueue_style('ifp-admin', IFP_URL . 'assets/admin.css', [], IFP_VERSION);
        $ready = post_type_exists('ifprog_season') && post_type_exists('ifprog_level') && post_type_exists('ifprog_program');
        $report = $ready ? self::report() : [];
        $backups = self::backups();
        ?>
        <div class="wrap ifp-dashboard">
            <div class="ifp-page-header"><div>
                <p class="ifp-kicker">Productions ↔ Programming</p>
                <h1>Alignment Audit</h1>
                <p class="ifp-lead">Review relationships, monitor recent Programming projections, and use protected migration controls when alignment work is needed.</p>
            </div></div>

            <?php if (!$ready): ?>
                <div class="notice notice-warning"><p><strong>Programming is not available.</strong> Activate Ice &amp; Field Programming before running this audit.</p></div>
            <?php else: ?>
                <?php if (!empty($_GET['ifp_aligned'])): ?>
                    <div class="notice notice-success is-dismissible"><p><strong>Alignment migration completed.</strong> <?php echo esc_html(absint($_GET['ifp_aligned'])); ?> relationship pair(s) were linked.</p></div>
                <?php endif; ?>
                <?php if (!empty($_GET['ifp_rolled_back'])): ?>
                    <div class="notice notice-success is-dismissible"><p><strong>Latest alignment migration rolled back.</strong></p></div>
                <?php endif; ?>
                <?php if (!empty($_GET['ifp_alignment_error'])): ?>
                    <div class="notice notice-error"><p><?php echo esc_html(sanitize_text_field(wp_unslash($_GET['ifp_alignment_error']))); ?></p></div>
                <?php endif; ?>
                <?php self::summary($report); ?>
                <?php self::projection_status(); ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::MIGRATION_ACTION); ?>">
                    <?php wp_nonce_field(self::MIGRATION_ACTION); ?>
                    <?php self::table('Seasons and Productions', 'Production', 'Programming Season', $report['seasons'], 'season'); ?>
                    <?php self::table('Divisions and Levels', 'Division', 'Programming Level', $report['levels'], 'level'); ?>
                    <?php self::table('Groups and Programs', 'Group', 'Programming Program', $report['programs'], 'program'); ?>
                    <section class="ifp-admin-card" style="margin-top:20px">
                        <h2>Apply reviewed relationships</h2>
                        <p>Only the selected relationship metadata will be written. Posts, titles, content, Dash identifiers, publication status, participants, and presentation fields will not change.</p>
                        <?php submit_button('Link Selected Relationships', 'primary', 'submit', false); ?>
                    </section>
                </form>

                <?php if ($backups): $latest = $backups[0]; ?>
                    <section class="ifp-admin-card" style="margin-top:20px">
                        <h2>Rollback</h2>
                        <p>The latest migration linked <?php echo esc_html(absint($latest['pair_count'] ?? 0)); ?> pair(s) on <?php echo esc_html($latest['created_at'] ?? 'an earlier date'); ?>.</p>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="<?php echo esc_attr(self::ROLLBACK_ACTION); ?>">
                            <input type="hidden" name="backup_id" value="<?php echo esc_attr($latest['id'] ?? ''); ?>">
                            <?php wp_nonce_field(self::ROLLBACK_ACTION); ?>
                            <?php submit_button('Roll Back Latest Alignment', 'secondary', 'submit', false, [
                                'onclick' => "return confirm('Roll back the latest alignment relationship changes?');",
                            ]); ?>
                        </form>
                    </section>
                <?php endif; ?>

                <section class="ifp-admin-card" style="margin-top:20px">
                    <h2>How to read this audit</h2>
                    <ul>
                        <li><strong>Exact Dash match:</strong> one record on each side shares the expected Dash identifier.</li>
                        <li><strong>Already linked:</strong> Stage 1 relationship metadata identifies the pair.</li>
                        <li><strong>Confirmed by exact children:</strong> exact Group ↔ Program matches unanimously identify the Division ↔ Level parent pair.</li>
                        <li><strong>Probable title match:</strong> normalized titles match uniquely within the expected parent.</li>
                        <li><strong>Local only:</strong> the Production-side record has no Dash identity and will be preserved without a Programming counterpart.</li>
                        <li><strong>Programming only:</strong> a historical or standalone Programming record has no exact-matched Production children and needs no migration.</li>
                        <li><strong>Missing:</strong> no safe counterpart was found.</li>
                        <li><strong>Conflict:</strong> duplicate IDs, an invalid or contradictory saved link, a parent mismatch, or multiple possible matches require review.</li>
                    </ul>
                    <p><strong>Stage 3 writes relationships only after explicit selection and stores a rollback snapshot first.</strong></p>
                </section>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function summary($report) {
        $counts = ['exact' => 0, 'linked' => 0, 'child' => 0, 'probable' => 0, 'local' => 0, 'programming_only' => 0, 'missing' => 0, 'conflict' => 0];
        foreach (['seasons','levels','programs'] as $section) {
            foreach ($report[$section] as $row) {
                if (isset($counts[$row['status']])) $counts[$row['status']]++;
            }
        }
        ?>
        <section class="ifp-admin-card">
            <h2>Audit Summary</h2>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:12px">
                <?php foreach ([
                    'exact' => 'Exact Dash',
                    'linked' => 'Already linked',
                    'child' => 'Child-confirmed',
                    'probable' => 'Probable',
                    'local' => 'Local only',
                    'programming_only' => 'Programming only',
                    'missing' => 'Missing',
                    'conflict' => 'Conflicts',
                ] as $key => $label): ?>
                    <div style="border:1px solid #dcdcde;border-radius:8px;padding:14px;background:#fff">
                        <strong style="font-size:24px;display:block"><?php echo esc_html($counts[$key]); ?></strong>
                        <span><?php echo esc_html($label); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="description">Generated <?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'))); ?> from current WordPress records. Reload this page to refresh it.</p>
        </section>
        <?php
    }

    private static function projection_status() {
        $production_ids = get_posts([
            'post_type' => 'ifp_production',
            'post_status' => 'any',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_key' => '_ifp_programming_projection_result',
        ]);
        $rows = [];
        foreach ($production_ids as $production_id) {
            $result = get_post_meta($production_id, '_ifp_programming_projection_result', true);
            if (!is_array($result) || empty($result['completed_at'])) continue;
            $rows[] = ['production_id' => absint($production_id), 'result' => $result];
        }
        usort($rows, function($a, $b) {
            return strcmp((string) $b['result']['completed_at'], (string) $a['result']['completed_at']);
        });
        $rows = array_slice($rows, 0, 10);
        ?>
        <section class="ifp-admin-card" style="margin-top:20px">
            <h2>Recent Programming Projections</h2>
            <?php if (!$rows): ?>
                <p>No Production companion projection has been recorded yet.</p>
            <?php else: ?>
                <div style="overflow:auto"><table class="widefat striped">
                    <thead><tr><th>Production</th><th>Completed</th><th>Divisions</th><th>Groups</th><th>Participants</th><th>Result</th></tr></thead>
                    <tbody><?php foreach ($rows as $row): $result = $row['result']; $warnings = array_values(array_filter((array) ($result['warnings'] ?? []))); ?>
                        <tr>
                            <td><?php self::post_link($row['production_id'], get_the_title($row['production_id'])); ?></td>
                            <td><?php echo esc_html($result['completed_at']); ?></td>
                            <td><?php echo esc_html(absint($result['divisions_created'] ?? 0)); ?> created · <?php echo esc_html(absint($result['divisions_updated'] ?? 0)); ?> updated</td>
                            <td><?php echo esc_html(absint($result['groups_created'] ?? 0)); ?> created · <?php echo esc_html(absint($result['groups_updated'] ?? 0)); ?> updated</td>
                            <td><?php echo esc_html(absint($result['participants_synchronized'] ?? 0)); ?></td>
                            <td>
                                <strong><?php echo $warnings ? 'Completed with warnings' : 'Completed'; ?></strong>
                                <?php if ($warnings): ?><ul style="margin:6px 0 0 18px"><?php foreach ($warnings as $warning): ?><li><?php echo esc_html($warning); ?></li><?php endforeach; ?></ul><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?></tbody>
                </table></div>
            <?php endif; ?>
            <p class="description">This history is diagnostic only. Repeated successful Programming syncs update linked records in place and do not create duplicates.</p>
        </section>
        <?php
    }

    private static function table($heading, $left_label, $right_label, $rows, $type) {
        ?>
        <section class="ifp-admin-card" style="margin-top:20px">
            <h2><?php echo esc_html($heading); ?></h2>
            <?php if (!$rows): ?>
                <p>No <?php echo esc_html(strtolower($left_label)); ?> records were found.</p>
            <?php else: ?>
                <div style="overflow:auto">
                    <table class="widefat striped" style="min-width:860px">
                        <thead><tr>
                            <th style="width:54px">Link</th>
                            <th><?php echo esc_html($left_label); ?></th>
                            <th>Dash identity</th>
                            <th>Proposed <?php echo esc_html($right_label); ?></th>
                            <th>Assessment</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td>
                                    <?php if (self::migratable($row)): ?>
                                        <input
                                            type="checkbox"
                                            name="ifp_alignment_pairs[]"
                                            value="<?php echo esc_attr($type . ':' . $row['left_id'] . ':' . $row['right_id']); ?>"
                                            <?php checked(in_array($row['status'], ['exact','linked','child'], true)); ?>
                                            aria-label="<?php echo esc_attr('Link ' . $row['left_title'] . ' to ' . $row['right_title']); ?>"
                                        >
                                    <?php else: ?>—<?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($row['left_id']): self::post_link($row['left_id'], $row['left_title']); ?>
                                    <?php else: ?><span class="ifp-list-muted">No <?php echo esc_html(strtolower($left_label)); ?> counterpart</span><?php endif; ?>
                                </td>
                                <td><?php echo $row['dash_id'] ? '#' . esc_html($row['dash_id']) : '<span class="ifp-list-muted">None</span>'; ?></td>
                                <td>
                                    <?php if ($row['right_id']): ?>
                                        <?php self::post_link($row['right_id'], $row['right_title']); ?>
                                    <?php elseif (!empty($row['candidates'])): ?>
                                        <?php echo esc_html(implode(', ', $row['candidates'])); ?>
                                    <?php else: ?>
                                        <span class="ifp-list-muted">None</span>
                                    <?php endif; ?>
                                </td>
                                <td><strong><?php echo esc_html(self::status_label($row['status'])); ?></strong><br><span class="description"><?php echo esc_html($row['reason']); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
        <?php
    }

    private static function post_link($post_id, $title) {
        $url = get_edit_post_link($post_id);
        if ($url) echo '<a href="' . esc_url($url) . '"><strong>' . esc_html($title) . '</strong></a> <small>#' . esc_html($post_id) . '</small>';
        else echo '<strong>' . esc_html($title) . '</strong> <small>#' . esc_html($post_id) . '</small>';
    }

    private static function status_label($status) {
        return [
            'exact' => 'Exact Dash match',
            'linked' => 'Already linked',
            'child' => 'Confirmed by exact children',
            'probable' => 'Probable title match',
            'local' => 'Local only — preserve',
            'programming_only' => 'Programming only — no migration',
            'missing' => 'Missing counterpart',
            'conflict' => 'Conflict — review required',
        ][$status] ?? ucfirst($status);
    }

    private static function migratable($row) {
        return !empty($row['left_id']) && !empty($row['right_id']) &&
            in_array($row['status'], ['exact','linked','child','probable'], true);
    }

    public static function apply_migration() {
        if (!current_user_can('manage_options')) wp_die('Permission denied.');
        check_admin_referer(self::MIGRATION_ACTION);

        $selected = array_values(array_unique(array_filter(array_map(
            'sanitize_text_field',
            (array) wp_unslash($_POST['ifp_alignment_pairs'] ?? [])
        ))));
        if (!$selected) self::redirect_error('Select at least one reviewed relationship to link.');

        $report = self::report();
        $allowed = [];
        foreach (['season' => 'seasons', 'level' => 'levels', 'program' => 'programs'] as $type => $section) {
            foreach ($report[$section] as $row) {
                if (!self::migratable($row)) continue;
                $key = $type . ':' . absint($row['left_id']) . ':' . absint($row['right_id']);
                $allowed[$key] = ['type' => $type, 'left_id' => absint($row['left_id']), 'right_id' => absint($row['right_id'])];
            }
        }

        $pairs = [];
        $seen_left = [];
        $seen_right = [];
        foreach ($selected as $key) {
            if (!isset($allowed[$key])) self::redirect_error('One selected relationship is no longer valid. Reload the audit and review it again.');
            $pair = $allowed[$key];
            $left_key = $pair['type'] . ':' . $pair['left_id'];
            $right_key = $pair['type'] . ':' . $pair['right_id'];
            if (isset($seen_left[$left_key]) || isset($seen_right[$right_key])) {
                self::redirect_error('The selection contains a one-to-many relationship. Reload the audit and resolve the conflict first.');
            }
            $seen_left[$left_key] = true;
            $seen_right[$right_key] = true;
            $pairs[] = $pair;
        }

        $changes = [];
        $timestamp = current_time('mysql');
        foreach ($pairs as $pair) {
            if ($pair['type'] === 'season') {
                self::plan_meta($changes, $pair['left_id'], '_ifp_programming_season_id', $pair['right_id']);
                self::plan_meta($changes, $pair['right_id'], '_ifprog_production_id', $pair['left_id']);
                self::plan_meta($changes, $pair['right_id'], '_ifprog_is_production', '1');
            } elseif ($pair['type'] === 'level') {
                self::plan_meta($changes, $pair['left_id'], '_ifp_programming_level_id', $pair['right_id']);
                self::plan_meta($changes, $pair['right_id'], '_ifprog_division_id', $pair['left_id']);
            } elseif ($pair['type'] === 'program') {
                self::plan_meta($changes, $pair['left_id'], '_ifp_programming_program_id', $pair['right_id']);
                self::plan_meta($changes, $pair['right_id'], '_ifprog_group_id', $pair['left_id']);
            }
            self::plan_meta($changes, $pair['left_id'], '_ifp_alignment_version', self::MIGRATION_VERSION);
            self::plan_meta($changes, $pair['left_id'], '_ifp_alignment_migrated_at', $timestamp);
            self::plan_meta($changes, $pair['right_id'], '_ifp_alignment_version', self::MIGRATION_VERSION);
            self::plan_meta($changes, $pair['right_id'], '_ifp_alignment_migrated_at', $timestamp);
        }

        $backups = self::backups();
        array_unshift($backups, [
            'id' => function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('ifp-align-', true),
            'created_at' => $timestamp,
            'pair_count' => count($pairs),
            'changes' => $changes,
        ]);
        $backups = array_slice($backups, 0, 10);
        update_option(self::BACKUPS_OPTION, $backups, false);

        foreach ($changes as $change) {
            update_post_meta($change['post_id'], $change['key'], $change['new_value']);
        }

        wp_safe_redirect(add_query_arg(
            'ifp_aligned',
            count($pairs),
            admin_url('admin.php?page=ifp-programming-alignment')
        ));
        exit;
    }

    public static function rollback() {
        if (!current_user_can('manage_options')) wp_die('Permission denied.');
        check_admin_referer(self::ROLLBACK_ACTION);
        $backup_id = sanitize_text_field(wp_unslash($_POST['backup_id'] ?? ''));
        $backups = self::backups();
        $latest = $backups[0] ?? null;
        if (!$latest || !$backup_id || !hash_equals((string) ($latest['id'] ?? ''), $backup_id)) {
            self::redirect_error('The latest rollback snapshot could not be verified. Reload the audit and try again.');
        }

        foreach (array_reverse((array) ($latest['changes'] ?? [])) as $change) {
            $post_id = absint($change['post_id'] ?? 0);
            $key = sanitize_key((string) ($change['key'] ?? ''));
            if (!$post_id || $key === '') continue;
            if (!empty($change['existed'])) update_post_meta($post_id, $key, $change['old_value']);
            else delete_post_meta($post_id, $key);
        }
        array_shift($backups);
        update_option(self::BACKUPS_OPTION, $backups, false);

        wp_safe_redirect(add_query_arg('ifp_rolled_back', 1, admin_url('admin.php?page=ifp-programming-alignment')));
        exit;
    }

    private static function plan_meta(&$changes, $post_id, $key, $new_value) {
        $post_id = absint($post_id);
        if (!$post_id || get_post_type($post_id) === false) return;
        $identity = $post_id . ':' . $key;
        if (isset($changes[$identity])) {
            $changes[$identity]['new_value'] = $new_value;
            return;
        }
        $changes[$identity] = [
            'post_id' => $post_id,
            'key' => $key,
            'existed' => metadata_exists('post', $post_id, $key),
            'old_value' => get_post_meta($post_id, $key, true),
            'new_value' => $new_value,
        ];
    }

    private static function backups() {
        $backups = get_option(self::BACKUPS_OPTION, []);
        return is_array($backups) ? array_values($backups) : [];
    }

    private static function redirect_error($message) {
        wp_safe_redirect(add_query_arg(
            'ifp_alignment_error',
            sanitize_text_field($message),
            admin_url('admin.php?page=ifp-programming-alignment')
        ));
        exit;
    }

    public static function report() {
        $productions = self::posts('ifp_production');
        $seasons = self::posts('ifprog_season');
        $season_audit = self::audit(
            $productions,
            $seasons,
            '_ifp_dash_season_id',
            '_ifprog_dash_season_id',
            '_ifp_programming_season_id',
            '_ifprog_production_id',
            null,
            null,
            null,
            function($season) {
                return get_post_meta($season->ID, '_ifprog_is_production', true) === '1' ||
                    absint(get_post_meta($season->ID, '_ifprog_production_id', true)) > 0;
            }
        );
        $season_map = self::resolved_map($season_audit);

        $divisions = self::posts('ifp_division');
        $levels = self::posts('ifprog_level');
        $groups = self::posts('ifp_group');
        $programs = self::posts('ifprog_program');
        $child_evidence = self::exact_child_parent_evidence($groups, $programs);
        $level_audit = self::audit(
            $divisions,
            $levels,
            '_ifp_dash_league_id',
            '_ifprog_dash_league_id',
            '_ifp_programming_level_id',
            '_ifprog_division_id',
            function($division) use ($season_map) {
                $production_id = absint(get_post_meta($division->ID, '_ifp_division_production_id', true));
                return absint($season_map[$production_id] ?? 0);
            },
            function($level) {
                return absint(get_post_meta($level->ID, '_ifprog_level_season_id', true));
            },
            null,
            function($level) use ($season_map) {
                return in_array(absint(get_post_meta($level->ID, '_ifprog_level_season_id', true)), array_values($season_map), true);
            }
        );
        $level_audit = self::refine_level_audit($level_audit, $levels, $child_evidence);
        $level_map = self::resolved_map($level_audit);

        $program_audit = self::audit(
            $groups,
            $programs,
            '_ifp_dash_team_id',
            '_ifprog_dash_source_id',
            '_ifp_programming_program_id',
            '_ifprog_group_id',
            function($group) use ($level_map) {
                $division_id = absint(get_post_meta($group->ID, '_ifp_group_division_id', true));
                return absint($level_map[$division_id] ?? 0);
            },
            function($program) {
                return self::programming_value($program->ID, 'level_id');
            },
            function($program) {
                $type = sanitize_key((string) get_post_meta($program->ID, '_ifprog_dash_source_type', true));
                return $type === '' || $type === 'team';
            },
            function($program) use ($level_map) {
                return in_array(self::programming_value($program->ID, 'level_id'), array_values($level_map), true);
            }
        );

        return ['seasons' => $season_audit, 'levels' => $level_audit, 'programs' => $program_audit];
    }

    private static function audit(
        $left_posts,
        $right_posts,
        $left_dash_key,
        $right_dash_key,
        $left_link_key = '',
        $right_link_key = '',
        $left_parent = null,
        $right_parent = null,
        $right_filter = null,
        $right_orphan_filter = null
    ) {
        if ($right_filter) $right_posts = array_values(array_filter($right_posts, $right_filter));
        $right_by_id = [];
        $right_by_dash = [];
        foreach ($right_posts as $right) {
            $right_by_id[$right->ID] = $right;
            $dash_id = absint(get_post_meta($right->ID, $right_dash_key, true));
            if ($dash_id) $right_by_dash[$dash_id][] = $right;
        }

        $left_dash_counts = [];
        foreach ($left_posts as $left) {
            $dash_id = absint(get_post_meta($left->ID, $left_dash_key, true));
            if ($dash_id) $left_dash_counts[$dash_id] = ($left_dash_counts[$dash_id] ?? 0) + 1;
        }

        $rows = [];
        $used_right_ids = [];
        foreach ($left_posts as $left) {
            $dash_id = absint(get_post_meta($left->ID, $left_dash_key, true));
            $linked_id = $left_link_key ? absint(get_post_meta($left->ID, $left_link_key, true)) : 0;
            $expected_parent = $left_parent ? absint($left_parent($left)) : 0;
            $row = [
                'left_id' => absint($left->ID),
                'left_title' => (string) $left->post_title,
                'dash_id' => $dash_id,
                'right_id' => 0,
                'right_title' => '',
                'status' => 'missing',
                'reason' => 'No safe counterpart was found.',
                'candidates' => [],
            ];

            if ($dash_id && (($left_dash_counts[$dash_id] ?? 0) > 1 || count($right_by_dash[$dash_id] ?? []) > 1)) {
                $row['status'] = 'conflict';
                $row['reason'] = 'The Dash identifier is duplicated on one or both sides.';
                $row['candidates'] = self::titles($right_by_dash[$dash_id] ?? []);
                $rows[] = $row;
                continue;
            }

            if ($linked_id) {
                $right = $right_by_id[$linked_id] ?? null;
                if (!$right) {
                    $row['status'] = 'conflict';
                    $row['reason'] = 'The saved Stage 1 link points to a missing or invalid record.';
                    $rows[] = $row;
                    continue;
                }
                $right_dash = absint(get_post_meta($right->ID, $right_dash_key, true));
                $reciprocal = $right_link_key ? absint(get_post_meta($right->ID, $right_link_key, true)) : 0;
                $parent_mismatch = $expected_parent && $right_parent && absint($right_parent($right)) !== $expected_parent;
                if (($dash_id && $right_dash && $dash_id !== $right_dash) || ($reciprocal && $reciprocal !== $left->ID) || $parent_mismatch) {
                    $row['status'] = 'conflict';
                    $row['reason'] = $parent_mismatch ? 'The saved link crosses the expected parent relationship.' : 'The saved link contradicts Dash identity or the reciprocal relationship.';
                } else {
                    $row['status'] = 'linked';
                    $row['reason'] = $reciprocal === $left->ID ? 'Both Stage 1 relationship fields agree.' : 'A valid one-way Stage 1 relationship is present.';
                }
                $row['right_id'] = absint($right->ID);
                $row['right_title'] = (string) $right->post_title;
                $used_right_ids[$right->ID] = true;
                $rows[] = $row;
                continue;
            }

            if ($dash_id && count($right_by_dash[$dash_id] ?? []) === 1) {
                $right = $right_by_dash[$dash_id][0];
                $parent_mismatch = $expected_parent && $right_parent && absint($right_parent($right)) !== $expected_parent;
                $row['right_id'] = absint($right->ID);
                $row['right_title'] = (string) $right->post_title;
                $used_right_ids[$right->ID] = true;
                $row['status'] = $parent_mismatch ? 'conflict' : 'exact';
                $row['reason'] = $parent_mismatch ? 'Dash identity matches, but the records belong to different proposed parents.' : 'The Dash identifier matches uniquely.';
                $rows[] = $row;
                continue;
            }

            $normalized = self::normalize_title($left->post_title);
            $title_matches = array_values(array_filter($right_posts, function($right) use ($normalized, $expected_parent, $right_parent) {
                if ($normalized === '' || self::normalize_title($right->post_title) !== $normalized) return false;
                if ($expected_parent && $right_parent && absint($right_parent($right)) !== $expected_parent) return false;
                return true;
            }));
            if (count($title_matches) === 1) {
                $right = $title_matches[0];
                $row['right_id'] = absint($right->ID);
                $row['right_title'] = (string) $right->post_title;
                $used_right_ids[$right->ID] = true;
                $row['status'] = 'probable';
                $row['reason'] = $expected_parent ? 'The normalized title matches uniquely inside the proposed parent.' : 'The normalized title matches uniquely.';
            } elseif (count($title_matches) > 1) {
                $row['status'] = 'conflict';
                $row['reason'] = 'Multiple records share the same normalized title.';
                $row['candidates'] = self::titles($title_matches);
            } elseif (!$dash_id) {
                $row['status'] = 'local';
                $row['reason'] = 'No Dash identity or unique counterpart; preserve as a Production-only record.';
            }
            $rows[] = $row;
        }

        $resolved_target_counts = [];
        foreach ($rows as $row) {
            if ($row['right_id'] && in_array($row['status'], ['exact','linked','probable'], true)) {
                $resolved_target_counts[$row['right_id']] = ($resolved_target_counts[$row['right_id']] ?? 0) + 1;
            }
        }
        foreach ($rows as &$row) {
            if ($row['right_id'] && ($resolved_target_counts[$row['right_id']] ?? 0) > 1) {
                $row['status'] = 'conflict';
                $row['reason'] = 'More than one Production-side record resolves to the same Programming record.';
            }
        }
        unset($row);

        foreach ($right_posts as $right) {
            if (isset($used_right_ids[$right->ID])) continue;
            if ($right_orphan_filter && !$right_orphan_filter($right)) continue;
            $dash_id = absint(get_post_meta($right->ID, $right_dash_key, true));
            $duplicate = $dash_id && count($right_by_dash[$dash_id] ?? []) > 1;
            $rows[] = [
                'left_id' => 0,
                'left_title' => '',
                'dash_id' => $dash_id,
                'right_id' => absint($right->ID),
                'right_title' => (string) $right->post_title,
                'status' => $duplicate ? 'conflict' : 'missing',
                'reason' => $duplicate
                    ? 'Programming contains a duplicated Dash identifier and no Production-side record is resolved to this item.'
                    : 'This Production-designated Programming record has no Production-side counterpart.',
                'candidates' => [],
            ];
        }

        usort($rows, function($a, $b) {
            $order = ['conflict' => 0, 'missing' => 1, 'probable' => 2, 'child' => 3, 'exact' => 4, 'linked' => 5, 'local' => 6, 'programming_only' => 7];
            $compare = ($order[$a['status']] ?? 9) <=> ($order[$b['status']] ?? 9);
            return $compare ?: strcasecmp($a['left_title'], $b['left_title']);
        });
        return $rows;
    }

    private static function resolved_map($rows) {
        $map = [];
        foreach ($rows as $row) {
            if ($row['left_id'] && $row['right_id'] && in_array($row['status'], ['exact','linked','child','probable'], true)) {
                $map[$row['left_id']] = $row['right_id'];
            }
        }
        return $map;
    }

    /**
     * Build parent evidence using only unique Dash Team matches. Titles are not
     * considered, so repeated names across historical shows cannot merge.
     */
    private static function exact_child_parent_evidence($groups, $programs) {
        $groups_by_dash = [];
        foreach ($groups as $group) {
            $dash_id = absint(get_post_meta($group->ID, '_ifp_dash_team_id', true));
            if ($dash_id) $groups_by_dash[$dash_id][] = $group;
        }

        $programs_by_dash = [];
        foreach ($programs as $program) {
            $source_type = sanitize_key((string) get_post_meta($program->ID, '_ifprog_dash_source_type', true));
            if ($source_type !== '' && $source_type !== 'team') continue;
            $dash_id = absint(get_post_meta($program->ID, '_ifprog_dash_source_id', true));
            if ($dash_id) $programs_by_dash[$dash_id][] = $program;
        }

        $by_division = [];
        $by_level = [];
        foreach ($groups_by_dash as $dash_id => $matching_groups) {
            $matching_programs = $programs_by_dash[$dash_id] ?? [];
            if (count($matching_groups) !== 1 || count($matching_programs) !== 1) continue;
            $division_id = absint(get_post_meta($matching_groups[0]->ID, '_ifp_group_division_id', true));
            $level_id = self::programming_value($matching_programs[0]->ID, 'level_id');
            if (!$division_id || !$level_id) continue;
            $by_division[$division_id][$level_id] = ($by_division[$division_id][$level_id] ?? 0) + 1;
            $by_level[$level_id][$division_id] = ($by_level[$level_id][$division_id] ?? 0) + 1;
        }

        return ['by_division' => $by_division, 'by_level' => $by_level];
    }

    private static function refine_level_audit($rows, $levels, $evidence) {
        $level_by_id = [];
        foreach ($levels as $level) $level_by_id[$level->ID] = $level;

        foreach ($rows as &$row) {
            if ($row['left_id']) {
                $targets = $evidence['by_division'][$row['left_id']] ?? [];
                if (!$targets) continue;
                arsort($targets, SORT_NUMERIC);
                if (count($targets) > 1) {
                    $row['status'] = 'conflict';
                    $row['reason'] = 'Exact Group/Program matches beneath this Division point to multiple Programming Levels.';
                    $row['candidates'] = array_values(array_map(function($level_id) use ($level_by_id, $targets) {
                        $title = isset($level_by_id[$level_id]) ? $level_by_id[$level_id]->post_title : 'Level';
                        return $title . ' (#' . $level_id . '; ' . $targets[$level_id] . ' exact children)';
                    }, array_keys($targets)));
                    continue;
                }

                $level_id = absint(array_key_first($targets));
                $count = absint($targets[$level_id]);
                if ($row['right_id'] && $row['right_id'] !== $level_id && in_array($row['status'], ['exact','linked','probable'], true)) {
                    $row['status'] = 'conflict';
                    $row['reason'] = $count . ' exact child ' . ($count === 1 ? 'match points' : 'matches point') . ' to a different Programming Level than the proposed parent.';
                    continue;
                }
                if (!isset($level_by_id[$level_id])) continue;
                $row['right_id'] = $level_id;
                $row['right_title'] = (string) $level_by_id[$level_id]->post_title;
                if (in_array($row['status'], ['missing','local','probable'], true)) {
                    $row['status'] = 'child';
                    $row['reason'] = $count . ' exact Group/Program ' . ($count === 1 ? 'match confirms' : 'matches confirm') . ' this parent relationship.';
                } else {
                    $row['reason'] .= ' Confirmed by ' . $count . ' exact child ' . ($count === 1 ? 'match.' : 'matches.');
                }
                continue;
            }

            $parents = $evidence['by_level'][$row['right_id']] ?? [];
            if (!$parents) {
                $row['status'] = 'programming_only';
                $row['reason'] = 'No exact-matched Group/Program children use this Programming Level; no Production migration is needed.';
            } else {
                $row['status'] = 'conflict';
                $row['reason'] = 'Exact child matches use this Level, but its Production Division relationship was not resolved.';
            }
        }
        unset($row);

        $resolved_level_ids = [];
        foreach ($rows as $row) {
            if ($row['left_id'] && $row['right_id'] && in_array($row['status'], ['exact','linked','child','probable'], true)) {
                $resolved_level_ids[$row['right_id']] = true;
            }
        }
        $rows = array_values(array_filter($rows, function($row) use ($resolved_level_ids) {
            return $row['left_id'] || !isset($resolved_level_ids[$row['right_id']]);
        }));

        usort($rows, function($a, $b) {
            $order = ['conflict' => 0, 'missing' => 1, 'probable' => 2, 'child' => 3, 'exact' => 4, 'linked' => 5, 'local' => 6, 'programming_only' => 7];
            $compare = ($order[$a['status']] ?? 9) <=> ($order[$b['status']] ?? 9);
            $a_title = $a['left_title'] ?: $a['right_title'];
            $b_title = $b['left_title'] ?: $b['right_title'];
            return $compare ?: strcasecmp($a_title, $b_title);
        });
        return $rows;
    }

    private static function posts($post_type) {
        return get_posts([
            'post_type' => $post_type,
            'post_status' => 'any',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);
    }

    private static function programming_value($post_id, $field) {
        foreach (['_ifprog_dash_' . $field, '_ifprog_' . $field] as $key) {
            if (metadata_exists('post', $post_id, $key)) return absint(get_post_meta($post_id, $key, true));
        }
        return 0;
    }

    private static function normalize_title($title) {
        $title = remove_accents(wp_strip_all_tags((string) $title));
        $title = strtolower($title);
        $title = preg_replace('/[^a-z0-9]+/', ' ', $title);
        return trim(preg_replace('/\s+/', ' ', $title));
    }

    private static function titles($posts) {
        return array_values(array_map(function($post) {
            return $post->post_title . ' (#' . $post->ID . ')';
        }, $posts));
    }
}
