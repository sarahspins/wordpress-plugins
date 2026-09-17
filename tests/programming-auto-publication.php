<?php
define('ABSPATH', __DIR__);
$meta = []; $titles = []; $statuses = [];
function get_post_meta($id, $key, $single = true) { global $meta; return $meta[$id][$key] ?? ''; }
function metadata_exists($type, $id, $key) { global $meta; return array_key_exists($key, $meta[$id] ?? []); }
function get_the_title($id) { global $titles; return $titles[$id] ?? ''; }
function get_post_status($id) { global $statuses; return $statuses[$id] ?? false; }
function absint($value) { return abs((int) $value); }
function is_wp_error($value) { return false; }
function get_term($id, $taxonomy) { return in_array($id, [20, 27], true) ? (object) ['term_id' => $id] : null; }
function wp_get_object_terms($id, $taxonomy, $args) { return $id === 100 ? [27] : []; }
class IFPROG_Post_Types {
    public static function assignment_term_ids($taxonomy, $slugs) {
        $map = ['hockey' => 20, 'figure-skating' => 27];
        return array_values(array_filter(array_map(function($slug) use ($map) { return $map[$slug] ?? 0; }, $slugs)));
    }
}
require __DIR__ . '/../ice-field-programming/includes/class-ifprog-preview.php';
require __DIR__ . '/../ice-field-programming/includes/class-ifprog-monitoring.php';
require __DIR__ . '/../ice-field-programming/includes/class-ifprog-sync.php';
function expect($value, $expected, $label) {
    if ($value !== $expected) throw new Exception($label);
    echo "PASS: $label\n";
}
$titles[1] = 'October Learn to Skate';
expect(IFPROG_Monitoring::automatic_publication_enabled(1), true, 'Learn to Skate default');
$meta[1]['_ifprog_dash_payload'] = ['name' => 'Learn-to-Skate 2026'];
expect(IFPROG_Monitoring::automatic_publication_enabled(1), true, 'Dash name and hyphens');
$meta[1]['_ifprog_is_production'] = '1';
expect(IFPROG_Monitoring::automatic_publication_enabled(1), false, 'Production default off');
$meta[1]['_ifprog_automatic_sync_publish_new'] = '1';
expect(IFPROG_Monitoring::automatic_publication_enabled(1), true, 'Explicit opt-in wins');
$meta[1]['_ifprog_automatic_sync_publish_new'] = '0';
expect(IFPROG_Monitoring::automatic_publication_enabled(1), false, 'Explicit opt-out wins');
$titles[2] = 'Learn to Play';
expect(IFPROG_Monitoring::automatic_publication_enabled(2), false, 'Other Season default off');
$guard = new ReflectionMethod('IFPROG_Sync', 'automatic_publication_allowed');
$guard->setAccessible(true);
$statuses = [1 => 'publish', 2 => 'publish', 3 => 'draft'];
expect($guard->invoke(null, true, 1, 2), true, 'New offering with published parents');
expect($guard->invoke(null, false, 1, 2), false, 'Existing offering protected');
expect($guard->invoke(null, true, 3, 2), false, 'Draft Season protected');
expect($guard->invoke(null, true, 1, 3), false, 'Draft Level protected');
expect($guard->invoke(null, true, 1, 0), true, 'Unlevelled offering with published Season');
echo "All automatic publication policy tests passed.\n";
$row_policy = new ReflectionMethod('IFPROG_Sync', 'row_classification');
$row_policy->setAccessible(true);
$row = ['team_id' => 5, 'league_id' => 1, 'sport' => ['slug' => 'hockey']];
$bulk = ['ifprog_sport' => [20], 'ifprog_format' => [30]];
expect($row_policy->invoke(null, $row, ['team_5' => 27], $bulk, false)['ifprog_sport'], [27], 'Individual Figure Skating overrides bulk Hockey');
expect($row_policy->invoke(null, $row, [], ['ifprog_sport' => [27]], false)['ifprog_sport'], [20], 'Mapped individual guess beats bulk');
$row['existing_id'] = 100;
expect($row_policy->invoke(null, $row, [], $bulk, true)['ifprog_sport'], [27], 'Automatic sync preserves existing Sport');
$row['existing_id'] = 0; $row['sport']['slug'] = '';
expect($row_policy->invoke(null, $row, [], $bulk, true)['ifprog_sport'], [], 'Automatic unknown Sport never inherits bulk');
expect($row_policy->invoke(null, $row, [], $bulk, false)['ifprog_sport'], [20], 'Manual single bulk fallback');
expect($row_policy->invoke(null, $row, [], ['ifprog_sport' => [20, 27]], false)['ifprog_sport'], [], 'Mixed bulk does not guess unresolved class');
echo "All per-row Sport policy tests passed.\n";
