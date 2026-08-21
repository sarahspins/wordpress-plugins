<?php
if (!defined('ABSPATH')) exit;

/**
 * Thin boundary around the shared Ice & Field Dash Connector.
 *
 * Programming remains local-first. These methods provide the API seam for
 * explicitly triggered imports without storing credentials here.
 */
class IFPROG_Dash {
    const MIN_VERSION = '1.3.0';

    public static function init() {}

    public static function available() {
        if (!class_exists('IFDC_Client')) return false;
        return !defined('IFDC_VERSION') || version_compare(IFDC_VERSION, self::MIN_VERSION, '>=');
    }

    public static function ready() {
        return self::available() && IFDC_Client::is_configured();
    }

    public static function diagnostics() {
        if (!self::ready()) return [];
        return (array) IFDC_Client::diagnostics();
    }

    public static function collection($path, $query = [], $args = []) {
        if (!self::available()) {
            return new WP_Error(
                'ifprog_connector_missing',
                'Ice & Field Dash Connector v' . self::MIN_VERSION . ' or newer is required for synchronization.'
            );
        }
        if (!IFDC_Client::is_configured()) {
            return new WP_Error(
                'ifprog_connector_not_ready',
                'The shared Dash Connector is installed but is not configured.'
            );
        }

        $args = wp_parse_args($args, [
            'cache_ttl' => 300,
            'max_pages' => 25,
        ]);
        return IFDC_Client::get_collection($path, $query, $args);
    }

    public static function seasons($args = []) {
        return self::collection('seasons', [], $args);
    }

    public static function teams($season_id = 0, $args = []) {
        if (!self::ready()) return self::collection('teams', [], $args);
        if (method_exists('IFDC_Client', 'get_teams')) {
            return IFDC_Client::get_teams(
                $season_id ? ['season_id' => absint($season_id)] : [],
                wp_parse_args($args, ['cache_ttl' => 300])
            );
        }
        return self::collection('teams', [], $args);
    }

    public static function leagues($args = []) {
        return self::collection('leagues', [], $args);
    }

    public static function products($args = []) {
        return self::collection('products', [], $args);
    }

    public static function registration_infos($args = []) {
        return self::collection('team-registration-infos', [], $args);
    }

    public static function company_slug() {
        if (!self::ready()) return '';

        $diagnostics = self::diagnostics();
        $company = sanitize_key((string) ($diagnostics['company'] ?? ''));

        return sanitize_key((string) apply_filters('ifprog_dash_company_slug', $company));
    }

    /**
     * DaySmart's public Program Level route uses the Dash League ID.
     */
    public static function registration_url($league_id, $facility_id = 0) {
        $league_id = absint($league_id);
        $facility_id = absint($facility_id);
        $company = self::company_slug();
        if (!$league_id || $company === '') return '';

        $url = 'https://apps.daysmartrecreation.com/dash/x/'
            . rawurlencode($company)
            . '/programs/level/'
            . $league_id;

        if ($facility_id) {
            $url .= '?facility_ids=' . $facility_id;
        }

        return esc_url_raw((string) apply_filters(
            'ifprog_dash_registration_url',
            $url,
            $league_id,
            $facility_id,
            $company
        ));
    }

    /**
     * DaySmart's Team route opens registration for one specific class.
     */
    public static function team_registration_url($team_id) {
        $team_id = absint($team_id);
        $company = self::company_slug();
        if (!$team_id || $company === '') return '';

        $url = 'https://apps.daysmartrecreation.com/dash/x/#/online/'
            . rawurlencode($company)
            . '/teams/'
            . $team_id;

        return esc_url_raw((string) apply_filters(
            'ifprog_dash_team_registration_url',
            $url,
            $team_id,
            $company
        ));
    }

    /**
     * A Season shortcut opens the matching Program catalog with that Dash
     * Season preselected.
     */
    public static function season_registration_url($program_id, $season_id, $facility_id = 0) {
        $program_id = absint($program_id);
        $season_id = absint($season_id);
        $facility_id = absint($facility_id);
        $company = self::company_slug();
        if (!$program_id || !$season_id || $company === '') return '';

        $url = 'https://apps.daysmartrecreation.com/dash/x/'
            . rawurlencode($company)
            . '/programs/'
            . $program_id
            . '/levels';

        $query = [];
        if ($facility_id) $query['facility_ids'] = $facility_id;
        $query['season_id'] = $season_id;
        $url = add_query_arg($query, $url);

        return esc_url_raw((string) apply_filters(
            'ifprog_dash_season_registration_url',
            $url,
            $program_id,
            $season_id,
            $facility_id,
            $company
        ));
    }

    /**
     * Convert Dash's year-and-month age boundaries into a concise whole-year
     * range. Starting ages round up and ending ages round down so the label
     * never suggests that an ineligible child is old enough to register.
     */
    public static function age_range_label($source) {
        $source = is_array($source) ? $source : [];
        if (isset($source['attributes']) && is_array($source['attributes'])) {
            $source = $source['attributes'];
        }

        $min = self::whole_age(
            $source['min_age'] ?? '',
            $source['min_age_months'] ?? 0,
            true
        );
        $max = self::whole_age(
            $source['max_age'] ?? '',
            $source['max_age_months'] ?? 0,
            false
        );

        if ($min !== '' && $max !== '') return 'Ages ' . $min . '–' . $max;
        if ($min !== '') return 'Ages ' . $min . '+';
        if ($max !== '') return 'Up to age ' . $max;
        return '';
    }

    /**
     * Use corrected Dash ages for untouched imported Levels while preserving
     * any age range that an administrator has customized manually.
     */
    public static function level_age_range($level_id) {
        $level_id = absint($level_id);
        if (!$level_id) return '';

        $saved = trim((string) get_post_meta($level_id, '_ifprog_level_age_range', true));
        $payload = get_post_meta($level_id, '_ifprog_dash_payload', true);
        if (!is_array($payload) || !$payload) return $saved;

        $corrected = self::age_range_label($payload);
        $legacy = self::legacy_age_range_label($payload);
        if ($corrected !== '' && ($saved === '' || $saved === $legacy)) {
            return $corrected;
        }
        return $saved;
    }

    public static function store_program_source($program_id, $values, $source_type, $source_id, $payload = []) {
        $program_id = absint($program_id);
        if (!$program_id || get_post_type($program_id) !== 'ifprog_program') {
            return new WP_Error('ifprog_invalid_program', 'A valid Programming record is required.');
        }

        IFPROG_Fields::store_dash_values($program_id, $values, $source_type, $source_id);
        if ($payload) {
            update_post_meta($program_id, '_ifprog_dash_payload', $payload);
        }
        return true;
    }

    private static function whole_age($years, $months, $round_up) {
        if ($years === '' || $years === null || !is_numeric($years)) return '';

        $years = max(0, (int) $years);
        $months = is_numeric($months) ? max(0, min(11, (int) $months)) : 0;
        $total_months = ($years * 12) + $months;

        return $round_up
            ? (int) ceil($total_months / 12)
            : (int) floor($total_months / 12);
    }

    private static function legacy_age_range_label($source) {
        $source = is_array($source) ? $source : [];
        if (isset($source['attributes']) && is_array($source['attributes'])) {
            $source = $source['attributes'];
        }

        $min = trim((string) ($source['min_age'] ?? ''));
        $max = trim((string) ($source['max_age'] ?? ''));
        if ($min !== '' && $max !== '') return 'Ages ' . $min . '–' . $max;
        if ($min !== '') return 'Ages ' . $min . '+';
        if ($max !== '') return 'Up to age ' . $max;
        return '';
    }

    public static function page() {
        if (!current_user_can('manage_options')) return;

        $available = self::available();
        $ready = self::ready();
        $diagnostics = $ready ? self::diagnostics() : [];
        ?>
        <div class="wrap ifprog-admin">
            <div class="ifprog-page-header">
                <div>
                    <p class="ifprog-kicker">Ice &amp; Field Programming</p>
                    <h1>Dash Connection</h1>
                    <p class="ifprog-lead">Programming uses the shared Connector to preview Season, League, Product, Team, and availability data. Manual records work independently.</p>
                </div>
            </div>

            <section class="ifprog-card">
                <h2>Shared Connector</h2>
                <?php if (!$available): ?>
                    <p><span class="ifprog-status ifprog-status--closed">Not detected</span></p>
                    <p>Install Ice &amp; Field Dash Connector v<?php echo esc_html(self::MIN_VERSION); ?> or newer before enabling synchronization.</p>
                <?php elseif (!$ready): ?>
                    <p><span class="ifprog-status ifprog-status--soon">Setup required</span></p>
                    <p>The Connector is installed, but it needs credentials before Programming can read Dash.</p>
                    <p><a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=ifdc-settings')); ?>">Configure Dash Connector</a></p>
                <?php else: ?>
                    <p><span class="ifprog-status ifprog-status--open">Connected and ready</span></p>
                    <div class="ifprog-facts">
                        <div><strong>Company</strong><span><?php echo esc_html($diagnostics['company'] ?? '—'); ?></span></div>
                        <div><strong>Requests today</strong><span><?php echo esc_html(absint($diagnostics['requests_today'] ?? 0)); ?></span></div>
                        <div><strong>Connector cache</strong><span><?php echo esc_html(absint($diagnostics['cache_ttl'] ?? 0)); ?> seconds</span></div>
                    </div>
                    <p class="ifprog-actions">
                        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=ifdc-explorer')); ?>">Open Object Explorer</a>
                        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=ifdc-schema')); ?>">View Learned Schema</a>
                    </p>
                <?php endif; ?>
            </section>

            <section class="ifprog-card">
                <h2>Synchronization Roadmap</h2>
                <ol class="ifprog-steps">
                    <li><strong>Discovery — complete:</strong> Teams are the registrable class choices, grouped into Level records from their parent Leagues and enriched by Season, Product, and registration-info records.</li>
                    <li><strong>Preview — complete:</strong> choose a Dash Season and review its Season details and any proposed Team-based records before changing WordPress.</li>
                    <li><strong>Protected sync — available now:</strong> import an empty upcoming Season by itself, or create Season → Level → Program records as drafts or explicitly publish them during import, while refreshing linked Dash facts and preserving local descriptions, ordering, taxonomies, links, and button text.</li>
                    <li><strong>1.8 Season Discovery Inbox — available now:</strong> list new, imported, changed, and excluded Seasons with direct Preview, Import, and Sync actions.</li>
                    <li><strong>1.9.1 monitoring foundation — available now:</strong> configure global and per-family monitoring rules and review a local activity history, with scheduling and email still off.</li>
                    <li><strong>1.9.2–1.9.3 guarded monitoring:</strong> discover new Seasons and class changes on a bounded schedule, then send deduplicated protected admin review links without importing automatically.</li>
                    <li><strong>2.0 opt-in automatic import:</strong> import only explicitly enabled Season families after validation and send a clear completion or needs-review announcement.</li>
                </ol>
                <p><a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=ifprog-preview')); ?>">Open Season Discovery &amp; Sync</a></p>
                <p class="description">The Season list loads read-only cached data when its admin screen opens. Force-fresh discovery and synchronization still run only when an administrator explicitly requests them. The 1.9.1 maintenance line stores monitoring preferences and activity history, but schedules no API refresh.</p>
            </section>
        </div>
        <?php
    }
}
