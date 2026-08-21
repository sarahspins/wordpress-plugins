<?php
if (!defined('ABSPATH')) exit;

class IFP_Dash_Production_Import {
    const NONCE = 'ifp_dash_import_production';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu'], 25);
        add_action('admin_post_ifp_dash_import_production', [__CLASS__, 'import']);
        add_action('admin_post_ifp_dash_link_production', [__CLASS__, 'link_production']);
        add_action('add_meta_boxes', [__CLASS__, 'production_box']);
        add_action('admin_footer-post.php', [__CLASS__, 'relink_form_shell']);
    }

    public static function menu() {
        add_submenu_page('ifp-dashboard', 'Legacy Production Import', 'Legacy Import', 'manage_options', 'ifp-dash-production-import', [__CLASS__, 'page']);
    }

    private static function ready() { return class_exists('IFDC_Client') && IFDC_Client::is_configured(); }
    private static function managed_programming_season($dash_season_id) {
        if (!post_type_exists('ifprog_season')) return 0;
        $ids = get_posts([
            'post_type' => 'ifprog_season', 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids',
            'meta_key' => '_ifprog_dash_season_id', 'meta_value' => absint($dash_season_id),
        ]);
        if (!$ids) return 0;
        $season_id = absint($ids[0]);
        $production_id = absint(get_post_meta($season_id, '_ifprog_production_id', true));
        return get_post_meta($season_id, '_ifprog_is_production', true) === '1' &&
            $production_id && get_post_type($production_id) === 'ifp_production'
            ? $season_id
            : 0;
    }
    private static function collection($path, $force = false) {
        return IFDC_Client::get_collection($path, [], ['force'=>$force, 'cache_ttl'=>300, 'max_pages'=>50]);
    }
    private static function attrs($item) { return is_array($item['attributes'] ?? null) ? $item['attributes'] : []; }
    private static function name($item, $fallback) {
        $a = self::attrs($item);
        foreach (['name','title','description','season_name','league_name'] as $key) if (!empty($a[$key])) return (string)$a[$key];
        return $fallback . ' ' . absint($item['id'] ?? 0);
    }
    private static function season_id_for($item) {
        $a = self::attrs($item);
        return absint($a['season_id'] ?? $a['seasonId'] ?? 0);
    }

    private static function company_slug() {
        static $company = null;
        if ($company !== null) return $company;
        if (!self::ready()) return '';

        $diagnostics = (array) IFDC_Client::diagnostics();
        $company = sanitize_key((string) ($diagnostics['company'] ?? ''));

        $company = sanitize_key((string) apply_filters('ifp_dash_company_slug', $company));
        return $company;
    }

    private static function explicit_registration_url($item) {
        $attributes = self::attrs($item);
        foreach (['registration_url','online_registration_url','signup_url','public_registration_url'] as $key) {
            if (!empty($attributes[$key])) return esc_url_raw((string) $attributes[$key]);
        }
        return '';
    }

    private static function dash_truthy($value) {
        if (is_bool($value)) return $value;
        if (is_numeric($value)) return (int) $value === 1;
        return in_array(strtolower(trim((string) $value)), ['1','true','yes','on','active','enabled'], true);
    }

    private static function season_registration_url($season) {
        $explicit = self::explicit_registration_url($season);
        if ($explicit !== '') return $explicit;

        $season_id = absint($season['id'] ?? 0);
        $attributes = self::attrs($season);
        $program_id = absint($attributes['program_id'] ?? $attributes['programId'] ?? 0);
        $facility_id = absint($attributes['facility_id'] ?? $attributes['facilityId'] ?? 0);
        $company = self::company_slug();
        if (!$season_id || !$program_id || $company === '') return '';

        $url = 'https://apps.daysmartrecreation.com/dash/x/'
            . rawurlencode($company)
            . '/programs/'
            . $program_id
            . '/levels';

        $query = ['season_id' => $season_id];
        if ($facility_id) $query['facility_ids'] = $facility_id;

        return esc_url_raw((string) apply_filters(
            'ifp_dash_season_registration_url',
            add_query_arg($query, $url),
            $program_id,
            $season_id,
            $facility_id,
            $company
        ));
    }

    private static function division_registration_url($league) {
        $explicit = self::explicit_registration_url($league);
        if ($explicit !== '') return $explicit;

        $league_id = absint($league['id'] ?? 0);
        $attributes = self::attrs($league);
        $facility_id = absint($attributes['facility_id'] ?? $attributes['facilityId'] ?? 0);
        $company = self::company_slug();
        if (!$league_id || $company === '') return '';

        $url = 'https://apps.daysmartrecreation.com/dash/x/'
            . rawurlencode($company)
            . '/programs/level/'
            . $league_id;
        if ($facility_id) $url .= '?facility_ids=' . $facility_id;

        return esc_url_raw((string) apply_filters(
            'ifp_dash_division_registration_url',
            $url,
            $league_id,
            $facility_id,
            $company
        ));
    }

    private static function group_registration_url($team) {
        $explicit = self::explicit_registration_url($team);
        if ($explicit !== '') return $explicit;

        $team_id = absint($team['id'] ?? 0);
        $attributes = self::attrs($team);
        if (array_key_exists('online_signup', $attributes) && !self::dash_truthy($attributes['online_signup'])) return '';
        $company = self::company_slug();
        if (!$team_id || $company === '') return '';

        return esc_url_raw((string) apply_filters(
            'ifp_dash_group_registration_url',
            'https://apps.daysmartrecreation.com/dash/x/#/online/'
                . rawurlencode($company)
                . '/teams/'
                . $team_id,
            $team_id,
            $company
        ));
    }

    /**
     * Refresh an automatically managed URL without overwriting a URL that was
     * deliberately changed in WordPress after the previous Dash sync.
     */
    private static function sync_registration_url($post_id, $dash_url) {
        $dash_url = esc_url_raw((string) $dash_url);
        $local_url = esc_url_raw((string) get_post_meta($post_id, '_ifp_registration_url', true));
        $previous_dash_url = esc_url_raw((string) get_post_meta($post_id, '_ifp_dash_registration_url', true));

        if ($local_url === '' || $local_url === $previous_dash_url) {
            update_post_meta($post_id, '_ifp_registration_url', $dash_url);
        }
        update_post_meta($post_id, '_ifp_dash_registration_url', $dash_url);
    }

    public static function sync_group_registration_url($post_id, $team) {
        $post_id = absint($post_id);
        if (!$post_id || !is_array($team)) return;
        self::sync_registration_url($post_id, self::group_registration_url($team));
    }

    public static function page() {
        if (!current_user_can('manage_options')) return;
        wp_enqueue_style('ifp-admin', IFP_URL . 'assets/admin.css', [], IFP_VERSION);
        $recovery_mode = !empty($_GET['ifp_legacy_recovery']);
        if (!$recovery_mode) {
            ?>
            <div class="wrap ifp-dashboard">
                <div class="ifp-page-header"><div>
                    <p class="ifp-kicker">Retired workflow</p>
                    <h1>Legacy Production Import</h1>
                    <p class="ifp-lead">Full Production imports now run through Programming Season Discovery &amp; Sync.</p>
                </div></div>
                <section class="ifp-current-card" style="display:block;padding:26px">
                    <h2>Use Programming for Production synchronization</h2>
                    <p>Programming provides the protected Dash preview and projects Production companions after a clean sync.</p>
                    <p><a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=ifprog-preview')); ?>">Open Programming Season Discovery &amp; Sync</a></p>
                    <p class="description">For this release, administrators can still enter recovery mode from the Dash panel of an unaligned Production. Aligned Seasons remain blocked.</p>
                </section>
            </div>
            <?php
            return;
        }
        $selected = absint($_GET['season_id'] ?? 0);
        $force = !empty($_GET['refresh']);
        $seasons = $leagues = $teams = [];
        $error = null;
        if (self::ready()) {
            $res = self::collection('seasons', $force);
            if (is_wp_error($res)) $error = $res; else $seasons = $res['data'] ?? [];
            if ($selected && !$error) {
                $lr = self::collection('leagues', $force);
                $tr = IFDC_Client::get_teams(['season_id'=>$selected], ['force'=>$force, 'cache_ttl'=>300]);
                if (is_wp_error($lr)) $error = $lr; elseif (is_wp_error($tr)) $error = $tr;
                else {
                    $leagues = array_values(array_filter($lr['data'] ?? [], function($x) use ($selected) { return self::season_id_for($x) === $selected; }));
                    $teams = $tr['data'] ?? [];
                }
            }
        }
        usort($seasons, function($a,$b){ return strcasecmp(self::name($b,'Season'), self::name($a,'Season')); });
        $managed_season_id = $selected ? self::managed_programming_season($selected) : 0;
        ?>
        <div class="wrap ifp-dashboard">
          <div class="ifp-page-header"><div><p class="ifp-kicker">Legacy recovery tool · Ice &amp; Field Productions <?php echo esc_html(IFP_VERSION); ?></p><h1>Legacy Production Import</h1><p class="ifp-lead">Use this only for Productions that have not been aligned with Programming. Programming Season Discovery &amp; Sync is the primary workflow.</p></div></div>
          <div class="notice notice-info"><p><strong>Primary sync moved to Programming.</strong> <a href="<?php echo esc_url(admin_url('admin.php?page=ifprog-preview')); ?>">Open Programming Season Discovery &amp; Sync</a>.</p></div>
          <?php if (!empty($_GET['imported'])): ?><div class="notice notice-success is-dismissible"><p><strong>Production imported.</strong> <?php echo esc_html(absint($_GET['divisions'] ?? 0)); ?> divisions, <?php echo esc_html(absint($_GET['groups'] ?? 0)); ?> groups, and <?php echo esc_html(absint($_GET['people'] ?? 0)); ?> roster records synchronized.</p></div><?php endif; ?>
          <?php if (!empty($_GET['error'])): ?><div class="notice notice-error"><p><?php echo esc_html(sanitize_text_field(wp_unslash($_GET['error']))); ?></p></div><?php endif; ?>
          <?php if (!self::ready()): ?><div class="notice notice-warning"><p>The shared Dash Connector must be installed and configured first.</p></div>
          <?php elseif ($error): ?><div class="notice notice-error"><p><?php echo esc_html($error->get_error_message()); ?></p></div>
          <?php else: ?>
          <section class="ifp-current-card" style="display:block;padding:26px">
            <h2>1. Choose a Dash Season</h2>
            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
              <input type="hidden" name="page" value="ifp-dash-production-import">
              <input type="hidden" name="ifp_legacy_recovery" value="1">
              <select name="season_id" style="min-width:360px">
                <option value="0">— Select a season —</option>
                <?php foreach ($seasons as $season): $id=absint($season['id']??0); ?><option value="<?php echo esc_attr($id); ?>" <?php selected($selected,$id); ?>><?php echo esc_html(self::name($season,'Season') . ' (#' . $id . ')'); ?></option><?php endforeach; ?>
              </select>
              <button class="button button-primary">Preview Production</button>
              <?php if ($selected): ?><a class="button" href="<?php echo esc_url(add_query_arg(['page'=>'ifp-dash-production-import','season_id'=>$selected,'refresh'=>1,'ifp_legacy_recovery'=>1],admin_url('admin.php'))); ?>">Refresh from Dash</a><?php endif; ?>
            </form>
          </section>
          <?php if ($selected):
            $season = null; foreach ($seasons as $s) if (absint($s['id']??0)===$selected) {$season=$s;break;}
            $season_name = $season ? self::name($season,'Season') : 'Season '.$selected;
            $league_names=[]; foreach($leagues as $l)$league_names[absint($l['id']??0)]=self::name($l,'League');
            $season_link_ready = $season && self::season_registration_url($season) !== '';
            $division_link_count = count(array_filter($leagues, function($league) { return self::division_registration_url($league) !== ''; }));
            $group_link_count = count(array_filter($teams, function($team) { return self::group_registration_url($team) !== ''; }));
          ?>
          <section class="ifp-current-card" style="display:block;padding:26px;margin-top:22px">
            <h2>2. Review Import</h2><h3><?php echo esc_html($season_name); ?></h3>
            <p><strong><?php echo count($leagues); ?></strong> division(s) · <strong><?php echo count($teams); ?></strong> group(s)</p>
            <p><strong>Registration links:</strong> <?php echo $season_link_ready ? 'Production ready' : 'Production unavailable'; ?> · <?php echo esc_html($division_link_count); ?> division(s) ready · <?php echo esc_html($group_link_count); ?> group(s) ready</p>
            <?php if ($leagues): ?><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px;margin:20px 0">
              <?php foreach($leagues as $league): $lid=absint($league['id']??0); $count=0; foreach($teams as $team) if(absint((self::attrs($team)['league_id']??0))===$lid)$count++; ?>
              <div style="border:1px solid #dcdcde;border-radius:8px;padding:16px;background:#fff"><strong><?php echo esc_html(self::name($league,'League')); ?></strong><br><span class="description">Dash League <?php echo esc_html($lid); ?> · <?php echo esc_html($count); ?> groups</span></div>
              <?php endforeach; ?></div><?php endif; ?>
            <details><summary><strong>View groups</strong></summary><ul style="columns:2;max-width:900px"><?php foreach($teams as $team): $a=self::attrs($team); ?><li><?php echo esc_html(self::name($team,'Team')); ?> <span class="description">— <?php echo esc_html($league_names[absint($a['league_id']??0)] ?? ('League '.absint($a['league_id']??0))); ?></span></li><?php endforeach; ?></ul></details>
            <?php if ($managed_season_id): ?>
              <div class="notice notice-warning inline"><p><strong>Legacy import blocked.</strong> This Dash Season is already managed by <a href="<?php echo esc_url(get_edit_post_link($managed_season_id)); ?>"><?php echo esc_html(get_the_title($managed_season_id)); ?></a> in Programming. Use the primary Programming sync to prevent competing updates.</p></div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:24px">
              <input type="hidden" name="action" value="ifp_dash_import_production"><input type="hidden" name="season_id" value="<?php echo esc_attr($selected); ?>"><input type="hidden" name="ifp_legacy_recovery" value="1"><?php wp_nonce_field(self::NONCE); ?>
              <h3>3. Import Options</h3>
              <p><label><input type="checkbox" name="import_people" value="1" checked> Import/synchronize registered People</label></p>
              <p class="description">Records already linked by Dash ID are updated automatically. Their WordPress publication status and local visibility settings are preserved.</p>
              <p><strong>New item status:</strong> <label><input type="radio" name="status" value="draft" checked> Draft</label> &nbsp; <label><input type="radio" name="status" value="private"> Private</label> &nbsp; <label><input type="radio" name="status" value="publish"> Public</label></p>
              <?php submit_button($managed_season_id ? 'Managed by Programming' : 'Run Legacy Import', 'primary button-hero', 'submit', false, $managed_season_id ? ['disabled'=>'disabled'] : []); ?>
            </form>
          </section><?php endif; endif; ?>
        </div><?php
    }

    public static function import() {
        if (!current_user_can('manage_options')) wp_die('Permission denied.');
        check_admin_referer(self::NONCE);
        if (empty($_POST['ifp_legacy_recovery'])) wp_die('Legacy recovery mode was not explicitly enabled.');
        $season_id=absint($_POST['season_id']??0); $status=sanitize_key($_POST['status']??'draft');
        if(!in_array($status,['draft','private','publish'],true))$status='draft';
        $redirect=admin_url('admin.php?page=ifp-dash-production-import&ifp_legacy_recovery=1&season_id='.$season_id);
        if(!$season_id||!self::ready()){wp_safe_redirect(add_query_arg('error','Missing season or Dash Connector is unavailable.',$redirect));exit;}
        if (self::managed_programming_season($season_id)) {
            wp_safe_redirect(add_query_arg('error', 'Legacy import is blocked because this Season is already managed through Programming.', $redirect));
            exit;
        }
        $sr=IFDC_Client::get_data('seasons/'.$season_id,[],['force'=>true,'cache'=>false]);
        $lr=self::collection('leagues',true); $tr=IFDC_Client::get_teams(['season_id'=>$season_id],['force'=>true,'cache_ttl'=>1]);
        if(is_wp_error($sr)||is_wp_error($lr)||is_wp_error($tr)){ $e=is_wp_error($sr)?$sr:(is_wp_error($lr)?$lr:$tr); wp_safe_redirect(add_query_arg('error',$e->get_error_message(),$redirect));exit; }
        $season=is_array($sr['data']??null)?$sr['data']:$sr; $production_id=self::upsert_production($season,$status);
        if(is_wp_error($production_id)){wp_safe_redirect(add_query_arg('error',$production_id->get_error_message(),$redirect));exit;}
        $leagues=array_values(array_filter($lr['data']??[],function($x)use($season_id){return self::season_id_for($x)===$season_id;}));
        $division_map=[]; foreach($leagues as $league){$did=self::upsert_division($league,$production_id,$status);if(!is_wp_error($did))$division_map[absint($league['id']??0)]=$did;}
        $group_count=0;$people_count=0; foreach($tr['data']??[] as $team){$a=self::attrs($team);$gid=self::upsert_group($team,$production_id,$division_map[absint($a['league_id']??0)]??0,$status);if(!is_wp_error($gid)){$group_count++;if(!empty($_POST['import_people'])){$sync=IFP_Dash_Integration::sync_group_participants($gid,true);if(!is_wp_error($sync))$people_count+=absint($sync['count']??0);}}}
        update_post_meta($production_id,'_ifp_dash_last_sync',current_time('mysql'));
        wp_safe_redirect(add_query_arg(['imported'=>1,'divisions'=>count($division_map),'groups'=>$group_count,'people'=>$people_count],$redirect));exit;
    }

    private static function find($type, $key, $value) {
        $ids = get_posts([
            'post_type' => $type,
            'post_status' => 'any',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_key' => $key,
            'meta_value' => $value,
        ]);
        return $ids ? absint($ids[0]) : 0;
    }

    private static function imported_post_status($post_id, $new_status) {
        if (!$post_id) return $new_status;
        $existing = get_post_status($post_id);
        return in_array($existing, ['draft','pending','private','publish','future'], true)
            ? $existing
            : $new_status;
    }

    private static function upsert_production($season, $status) {
        $dash_id = absint($season['id'] ?? 0);
        $attributes = self::attrs($season);
        $post_id = self::find('ifp_production', '_ifp_dash_season_id', $dash_id);
        $postarr = [
            'post_type' => 'ifp_production',
            'post_title' => sanitize_text_field(self::name($season, 'Production')),
            'post_status' => self::imported_post_status($post_id, $status),
        ];
        if ($post_id) $postarr['ID'] = $post_id;

        $result = $post_id
            ? wp_update_post(wp_slash($postarr), true)
            : wp_insert_post(wp_slash($postarr), true);
        if (is_wp_error($result)) return $result;

        $post_id = absint($result);
        update_post_meta($post_id, '_ifp_dash_season_id', $dash_id);
        update_post_meta($post_id, '_ifp_dash_season_payload', $season);
        update_post_meta($post_id, '_ifp_dash_last_sync', current_time('mysql'));
        update_post_meta($post_id, '_ifp_dash_program_id', absint($attributes['program_id'] ?? $attributes['programId'] ?? 0));
        update_post_meta($post_id, '_ifp_dash_facility_id', absint($attributes['facility_id'] ?? $attributes['facilityId'] ?? 0));
        self::sync_registration_url($post_id, self::season_registration_url($season));

        if (!get_post_meta($post_id, '_ifp_production_status', true)) {
            IFP_Production_Status::set($post_id, 'upcoming');
        }

        foreach (['start_date','end_date','registration_start','registration_end'] as $key) {
            if (!empty($attributes[$key])) {
                update_post_meta($post_id, '_ifp_dash_' . $key, sanitize_text_field($attributes[$key]));
            }
        }
        if (!get_post_meta($post_id, '_ifp_opening_date', true) && !empty($attributes['start_date'])) {
            update_post_meta($post_id, '_ifp_opening_date', sanitize_text_field($attributes['start_date']));
        }
        if (!get_post_meta($post_id, '_ifp_closing_date', true) && !empty($attributes['end_date'])) {
            update_post_meta($post_id, '_ifp_closing_date', sanitize_text_field($attributes['end_date']));
        }
        return $post_id;
    }

    private static function upsert_division($league, $production_id, $status) {
        $dash_id = absint($league['id'] ?? 0);
        $post_id = self::find('ifp_division', '_ifp_dash_league_id', $dash_id);
        $postarr = [
            'post_type' => 'ifp_division',
            'post_title' => sanitize_text_field(self::name($league, 'Division')),
            'post_status' => self::imported_post_status($post_id, $status),
        ];
        if ($post_id) $postarr['ID'] = $post_id;

        $result = $post_id
            ? wp_update_post(wp_slash($postarr), true)
            : wp_insert_post(wp_slash($postarr), true);
        if (is_wp_error($result)) return $result;

        $post_id = absint($result);
        update_post_meta($post_id, '_ifp_division_production_id', $production_id);
        update_post_meta($post_id, '_ifp_dash_league_id', $dash_id);
        update_post_meta($post_id, '_ifp_dash_season_id', self::season_id_for($league));
        update_post_meta($post_id, '_ifp_dash_league_payload', $league);
        update_post_meta($post_id, '_ifp_dash_last_sync', current_time('mysql'));
        self::sync_registration_url($post_id, self::division_registration_url($league));
        return $post_id;
    }

    private static function upsert_group($team, $production_id, $division_id, $status) {
        $dash_id = absint($team['id'] ?? 0);
        $attributes = self::attrs($team);
        $post_id = self::find('ifp_group', '_ifp_dash_team_id', $dash_id);
        $is_new = !$post_id;
        $postarr = [
            'post_type' => 'ifp_group',
            'post_title' => sanitize_text_field(self::name($team, 'Group')),
            'post_status' => self::imported_post_status($post_id, $status),
        ];
        if ($post_id) $postarr['ID'] = $post_id;

        $result = $post_id
            ? wp_update_post(wp_slash($postarr), true)
            : wp_insert_post(wp_slash($postarr), true);
        if (is_wp_error($result)) return $result;

        $post_id = absint($result);
        update_post_meta($post_id, '_ifp_group_production_id', $production_id);
        update_post_meta($post_id, '_ifp_group_division_id', $division_id);
        if ($is_new || get_post_meta($post_id, '_ifp_group_visibility', true) === '') {
            update_post_meta($post_id, '_ifp_group_visibility', $status === 'publish' ? 'public' : 'internal');
        }
        if ($is_new || get_post_meta($post_id, '_ifp_group_type', true) === '') {
            update_post_meta($post_id, '_ifp_group_type', self::infer_group_type(self::name($team, 'Group')));
        }
        update_post_meta($post_id, '_ifp_dash_team_id', $dash_id);
        update_post_meta($post_id, '_ifp_dash_season_id', absint($attributes['season_id'] ?? 0));
        update_post_meta($post_id, '_ifp_dash_league_id', absint($attributes['league_id'] ?? 0));
        update_post_meta($post_id, '_ifp_dash_product_id', absint($attributes['product_id'] ?? 0));
        update_post_meta($post_id, '_ifp_dash_program_type_id', absint($attributes['program_type_id'] ?? 0));
        update_post_meta($post_id, '_ifp_dash_status', sanitize_text_field($attributes['status'] ?? ''));
        update_post_meta($post_id, '_ifp_dash_payload', $team);
        update_post_meta($post_id, '_ifp_dash_last_sync', current_time('mysql'));
        self::sync_registration_url($post_id, self::group_registration_url($team));
        return $post_id;
    }

    private static function infer_group_type($name) {
        $name = strtolower((string) $name);
        $map = [
            'large group' => 'Large Group', 'small group' => 'Small Group',
            'solo' => 'Soloist', 'duet' => 'Duet', 'trio' => 'Trio',
            'ensemble' => 'Ensemble', 'opening' => 'Opening Number',
            'closing' => 'Closing Number', 'guest' => 'Guest Performance',
        ];
        foreach ($map as $needle => $type) {
            if (strpos($name, $needle) !== false) return $type;
        }
        return 'Other';
    }

    public static function production_box() {
        add_meta_box('ifp_dash_production', 'Dash Production', [__CLASS__, 'render_production_box'], 'ifp_production', 'side', 'high');
    }

    private static function suggested_season_id($production_id) {
        $ids = [];
        foreach ([
            ['type' => 'ifp_division', 'key' => '_ifp_division_production_id'],
            ['type' => 'ifp_group', 'key' => '_ifp_group_production_id'],
        ] as $source) {
            $posts = get_posts([
                'post_type' => $source['type'],
                'post_status' => 'any',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'meta_key' => $source['key'],
                'meta_value' => absint($production_id),
            ]);
            foreach ($posts as $post_id) {
                $season_id = absint(get_post_meta($post_id, '_ifp_dash_season_id', true));
                if ($season_id) $ids[] = $season_id;
            }
        }
        if (!$ids) return 0;
        $counts = array_count_values($ids);
        arsort($counts, SORT_NUMERIC);
        return absint(array_key_first($counts));
    }

    private static function season_choices() {
        if (!self::ready()) return [];
        $result = self::collection('seasons');
        if (is_wp_error($result)) return [];
        $seasons = is_array($result['data'] ?? null) ? $result['data'] : [];
        usort($seasons, function($a, $b) {
            return strcasecmp(self::name($b, 'Season'), self::name($a, 'Season'));
        });
        return $seasons;
    }

    private static function relink_form($production_id, $current_season_id = 0) {
        if (!current_user_can('manage_options')) return;
        if (!self::ready()) {
            echo '<p class="description">The Dash Connector must be connected before this Production can be relinked.</p>';
            return;
        }

        $suggested = $current_season_id ?: self::suggested_season_id($production_id);
        $form_id = 'ifp-dash-link-production-' . absint($production_id);
        $seasons = self::season_choices();
        $found_suggestion = false;
        foreach ($seasons as $season) {
            if (absint($season['id'] ?? 0) === $suggested) {
                $found_suggestion = true;
                break;
            }
        }

        echo '<div class="ifp-dash-relink-control">';
        echo '<p><label><strong>Dash Season</strong><br>';
        if ($seasons) {
            echo '<select class="widefat" name="season_id" form="'.esc_attr($form_id).'" required>';
            echo '<option value="">— Select a Dash season —</option>';
            if ($suggested && !$found_suggestion) {
                echo '<option value="'.esc_attr($suggested).'" selected>Season #'.esc_html($suggested).' — suggested from existing records</option>';
            }
            foreach ($seasons as $season) {
                $season_id = absint($season['id'] ?? 0);
                if (!$season_id) continue;
                $label = self::name($season, 'Season') . ' (#' . $season_id . ')';
                if ($season_id === $suggested && !$current_season_id) $label .= ' — suggested';
                echo '<option value="'.esc_attr($season_id).'" '.selected($suggested, $season_id, false).'>'.esc_html($label).'</option>';
            }
            echo '</select>';
        } else {
            echo '<input class="widefat" type="number" min="1" name="season_id" value="'.esc_attr($suggested).'" form="'.esc_attr($form_id).'" required>';
        }
        echo '</label></p>';
        if ($suggested && !$current_season_id) {
            echo '<p class="description">Season #'.esc_html($suggested).' was suggested from the Divisions or Groups already attached to this Production.</p>';
        }
        echo '<button type="submit" class="button button-secondary" form="'.esc_attr($form_id).'">'.esc_html($current_season_id ? 'Change Dash Season' : 'Relink Dash Season').'</button>';
        echo '</div>';
    }

    /**
     * WordPress wraps the post editor in its own form, so the visible relink
     * controls target this separate footer form instead of creating invalid
     * nested-form markup inside the Dash meta box.
     */
    public static function relink_form_shell() {
        $post_id = absint($_GET['post'] ?? 0);
        $post = $post_id ? get_post($post_id) : null;
        if (!$post instanceof WP_Post || $post->post_type !== 'ifp_production') return;
        if (!current_user_can('manage_options') || !current_user_can('edit_post', $post->ID)) return;

        $form_id = 'ifp-dash-link-production-' . absint($post->ID);
        echo '<form id="'.esc_attr($form_id).'" method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:none">';
        echo '<input type="hidden" name="action" value="ifp_dash_link_production">';
        echo '<input type="hidden" name="production_id" value="'.esc_attr($post->ID).'">';
        wp_nonce_field('ifp_dash_link_production_' . $post->ID);
        echo '</form>';
    }

    public static function render_production_box($post) {
        $season_id = absint(get_post_meta($post->ID, '_ifp_dash_season_id', true));
        $linked = !empty($_GET['ifp_dash_linked']);
        $error = isset($_GET['ifp_dash_link_error'])
            ? sanitize_text_field(wp_unslash($_GET['ifp_dash_link_error']))
            : '';

        if ($linked) {
            echo '<div class="notice notice-success inline"><p><strong>Dash season linked.</strong></p></div>';
        }
        if ($error !== '') {
            echo '<div class="notice notice-error inline"><p>'.esc_html($error).'</p></div>';
        }

        if (!$season_id) {
            echo '<p><span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:#b32d2e;margin-right:6px"></span><strong>Not linked to a Dash Season</strong></p>';
            echo '<p class="description">Relinking restores synchronization without changing this Production’s local title, description, artwork, dates, or presentation.</p>';
            self::relink_form($post->ID);
            return;
        }

        $divisions = get_posts([
            'post_type' => 'ifp_division',
            'post_status' => 'any',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_key' => '_ifp_division_production_id',
            'meta_value' => $post->ID,
        ]);
        $groups = get_posts([
            'post_type' => 'ifp_group',
            'post_status' => 'any',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_key' => '_ifp_group_production_id',
            'meta_value' => $post->ID,
        ]);
        $people = [];
        foreach ($groups as $group_id) {
            $people = array_merge($people, (array) get_post_meta($group_id, '_ifp_group_participant_ids', true));
        }
        $registration = get_post_meta($post->ID, '_ifp_registration_url', true);

        echo '<p><span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:#18a558;margin-right:6px"></span><strong>Dash linked</strong></p>';
        echo '<p><strong>Season ID:</strong> '.esc_html($season_id).'</p>';
        echo '<p><strong>Divisions:</strong> '.esc_html(count($divisions)).'<br><strong>Groups:</strong> '.esc_html(count($groups)).'<br><strong>People:</strong> '.esc_html(count(array_unique(array_filter(array_map('absint', $people))))).'</p>';
        if ($registration) {
            echo '<p><a class="button" href="'.esc_url($registration).'" target="_blank" rel="noopener noreferrer">Open Registration</a></p>';
        }
        $programming_season_id = absint(get_post_meta($post->ID, '_ifp_programming_season_id', true));
        if ($programming_season_id && get_post_type($programming_season_id) === 'ifprog_season') {
            echo '<p><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=ifprog-preview')).'">Sync in Programming</a> ';
            echo '<a class="button" href="'.esc_url(get_edit_post_link($programming_season_id)).'">Edit Programming Season</a></p>';
            echo '<p class="description">This Production is managed through Programming. The legacy importer is blocked for its Dash Season.</p>';
        } else {
            echo '<p><a class="button button-secondary" href="'.esc_url(admin_url('admin.php?page=ifp-dash-production-import&ifp_legacy_recovery=1&season_id='.$season_id)).'">Open One-Release Recovery Mode</a></p>';
            echo '<p class="description">This temporary recovery route is available only to administrators and will be removed after the transition release.</p>';
        }
        echo '<details><summary>Change linked season</summary><div style="padding-top:8px">';
        self::relink_form($post->ID, $season_id);
        echo '</div></details>';
    }

    public static function link_production() {
        if (!current_user_can('manage_options')) wp_die('Permission denied.');

        $production_id = absint($_POST['production_id'] ?? 0);
        check_admin_referer('ifp_dash_link_production_' . $production_id);
        $season_id = absint($_POST['season_id'] ?? 0);
        $redirect = $production_id ? get_edit_post_link($production_id, 'url') : '';
        if (!$redirect) $redirect = admin_url('edit.php?post_type=ifp_production');

        $fail = static function($message) use ($redirect) {
            wp_safe_redirect(add_query_arg('ifp_dash_link_error', $message, $redirect));
            exit;
        };

        if (!$production_id || get_post_type($production_id) !== 'ifp_production' || !current_user_can('edit_post', $production_id)) {
            $fail('Select a valid Production.');
        }
        if (!$season_id) $fail('Select a Dash season.');
        if (!self::ready()) $fail('The Dash Connector is not connected.');

        $conflicts = get_posts([
            'post_type' => 'ifp_production',
            'post_status' => 'any',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'post__not_in' => [$production_id],
            'meta_key' => '_ifp_dash_season_id',
            'meta_value' => $season_id,
        ]);
        if ($conflicts) {
            $fail('That Dash season is already linked to “' . get_the_title($conflicts[0]) . '”.');
        }

        $result = IFDC_Client::get_data('seasons/' . $season_id, [], ['force' => true, 'cache' => false]);
        if (is_wp_error($result)) $fail($result->get_error_message());
        $season = is_array($result['data'] ?? null) ? $result['data'] : $result;
        if (!is_array($season) || absint($season['id'] ?? 0) !== $season_id) {
            $fail('Dash did not return the selected season.');
        }

        $attributes = self::attrs($season);
        update_post_meta($production_id, '_ifp_dash_season_id', $season_id);
        update_post_meta($production_id, '_ifp_dash_season_payload', $season);
        update_post_meta($production_id, '_ifp_dash_last_sync', current_time('mysql'));
        update_post_meta($production_id, '_ifp_dash_program_id', absint($attributes['program_id'] ?? $attributes['programId'] ?? 0));
        update_post_meta($production_id, '_ifp_dash_facility_id', absint($attributes['facility_id'] ?? $attributes['facilityId'] ?? 0));
        self::sync_registration_url($production_id, self::season_registration_url($season));

        wp_safe_redirect(add_query_arg('ifp_dash_linked', 1, $redirect));
        exit;
    }
}
