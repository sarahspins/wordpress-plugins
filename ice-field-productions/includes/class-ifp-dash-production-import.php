<?php
if (!defined('ABSPATH')) exit;

class IFP_Dash_Production_Import {
    public static function init() {
        add_action('admin_post_ifp_dash_link_production', [__CLASS__, 'link_production']);
        add_action('add_meta_boxes', [__CLASS__, 'production_box']);
        add_action('admin_footer-post.php', [__CLASS__, 'relink_form_shell']);
    }

    private static function ready() { return class_exists('IFDC_Client') && IFDC_Client::is_configured(); }
    private static function collection($path, $force = false) {
        return IFDC_Client::get_collection($path, [], ['force'=>$force, 'cache_ttl'=>300, 'max_pages'=>50]);
    }
    private static function attrs($item) { return is_array($item['attributes'] ?? null) ? $item['attributes'] : []; }
    private static function name($item, $fallback) {
        $a = self::attrs($item);
        foreach (['name','title','description','season_name','league_name'] as $key) if (!empty($a[$key])) return (string)$a[$key];
        return $fallback . ' ' . absint($item['id'] ?? 0);
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
            echo '<p class="description">This Production is managed through Programming.</p>';
        } else {
            echo '<p><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=ifprog-preview')).'">Open Programming Season Discovery &amp; Sync</a></p>';
            echo '<p class="description">Align this Dash Season through Programming before synchronizing Production data.</p>';
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
