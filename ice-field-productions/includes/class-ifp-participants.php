<?php
if (!defined('ABSPATH')) exit;

class IFP_Participants {
    const NONCE_ACTION = 'ifp_save_person';
    const NONCE_NAME = 'ifp_person_nonce';

    public static function capability_names() {
        return [
            'edit_ifp_person',
            'read_ifp_person',
            'delete_ifp_person',
            'edit_ifp_people',
            'edit_others_ifp_people',
            'delete_ifp_people',
            'delete_private_ifp_people',
            'delete_published_ifp_people',
            'delete_others_ifp_people',
            'edit_private_ifp_people',
            'edit_published_ifp_people',
            'publish_ifp_people',
            'read_private_ifp_people',
            'create_ifp_people',
        ];
    }

    public static function grant_capabilities() {
        foreach (['administrator','editor'] as $role_name) {
            $role = get_role($role_name);
            if (!$role) continue;
            foreach (self::capability_names() as $capability) {
                $role->add_cap($capability);
            }
        }
        update_option('ifp_people_capabilities_version', IFP_VERSION, false);
    }

    public static function maybe_grant_capabilities() {
        $installed = (string) get_option('ifp_people_capabilities_version', '0');
        if (version_compare($installed, IFP_VERSION, '<')) self::grant_capabilities();
    }

    public static function init() {
        add_action('admin_init', [__CLASS__, 'maybe_grant_capabilities']);
        add_action('init', [__CLASS__, 'register_post_type']);
        add_action('add_meta_boxes', [__CLASS__, 'add_meta_boxes']);
        add_action('save_post_ifp_participant', [__CLASS__, 'save']);
        add_filter('manage_ifp_participant_posts_columns', [__CLASS__, 'columns']);
        add_action('manage_ifp_participant_posts_custom_column', [__CLASS__, 'column'], 10, 2);
        add_action('restrict_manage_posts', [__CLASS__, 'filters']);
        add_action('pre_get_posts', [__CLASS__, 'apply_filters']);
        add_action('admin_notices', [__CLASS__, 'toolbar']);
        add_action('admin_post_ifp_people_export', [__CLASS__, 'export_csv']);
        add_action('admin_post_ifp_person_sync', [__CLASS__, 'sync_person_action']);
        add_action('admin_post_ifp_person_raw_data', [__CLASS__, 'raw_data_page']);
        add_action('admin_notices', [__CLASS__, 'person_sync_notice']);
    }

    public static function register_post_type() {
        register_post_type('ifp_participant', [
            'labels' => [
                'name' => 'People',
                'singular_name' => 'Person',
                'add_new' => 'Add Person',
                'add_new_item' => 'Add New Person',
                'edit_item' => 'Edit Person',
                'new_item' => 'New Person',
                'view_item' => 'View Person',
                'search_items' => 'Search People',
                'not_found' => 'No people found',
                'menu_name' => 'People',
                'all_items' => 'People',
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'ifp-dashboard',
            'show_in_rest' => true,
            'supports' => ['title'],
            'capability_type' => ['ifp_person','ifp_people'],
            'map_meta_cap' => true,
            'menu_icon' => 'dashicons-id',
        ]);
    }

    public static function add_meta_boxes() {
        add_meta_box('ifp_person_contact', 'Contact Information', [__CLASS__, 'render_contact'], 'ifp_participant', 'normal', 'high');
        add_meta_box('ifp_person_emergency', 'Emergency Information', [__CLASS__, 'render_emergency'], 'ifp_participant', 'normal', 'default');
        add_meta_box('ifp_person_relationships', 'Productions & Groups', [__CLASS__, 'render_relationships'], 'ifp_participant', 'normal', 'default');
        add_meta_box('ifp_person_dash', 'Dash Connection', [__CLASS__, 'render_dash'], 'ifp_participant', 'side', 'default');
    }

    public static function render_contact($post) {
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);
        $first = get_post_meta($post->ID, '_ifp_participant_first_name', true);
        $last = get_post_meta($post->ID, '_ifp_participant_last_name', true);
        $email = get_post_meta($post->ID, '_ifp_participant_email', true);
        $phone = get_post_meta($post->ID, '_ifp_participant_phone', true);
        $birthdate = get_post_meta($post->ID, '_ifp_participant_birthdate', true);
        $notes = get_post_meta($post->ID, '_ifp_person_notes', true);
        ?>
        <div class="ifp-field-grid">
            <p><label><strong>First name</strong><br><input class="widefat" type="text" name="ifp_person_first_name" value="<?php echo esc_attr($first); ?>"></label></p>
            <p><label><strong>Last name</strong><br><input class="widefat" type="text" name="ifp_person_last_name" value="<?php echo esc_attr($last); ?>"></label></p>
            <p><label><strong>Email</strong><br><input class="widefat" type="email" name="ifp_person_email" value="<?php echo esc_attr($email); ?>"></label></p>
            <p><label><strong>Phone</strong><br><input class="widefat" type="text" name="ifp_person_phone" value="<?php echo esc_attr($phone); ?>"></label></p>
            <p><label><strong>Birthdate</strong><br><input class="widefat" type="date" name="ifp_person_birthdate" value="<?php echo esc_attr($birthdate); ?>"></label></p>
        </div>
        <p><label><strong>Internal notes</strong><br><textarea class="widefat" rows="5" name="ifp_person_notes"><?php echo esc_textarea($notes); ?></textarea></label></p>
        <p class="description">Contact details synchronized from Dash may be updated on the next roster sync. Internal notes are always preserved.</p>
        <?php
    }

    public static function render_emergency($post) {
        $emergency_contact = get_post_meta($post->ID, '_ifp_emergency_contact', true);
        $emergency_phone = get_post_meta($post->ID, '_ifp_emergency_phone', true);
        $medical_notes = get_post_meta($post->ID, '_ifp_dash_medical_notes', true);
        ?>
        <div class="ifp-field-grid">
            <p><label><strong>Emergency contact</strong><br><input class="widefat" type="text" name="ifp_emergency_contact" value="<?php echo esc_attr($emergency_contact); ?>"></label></p>
            <p><label><strong>Emergency phone</strong><br><input class="widefat" type="text" name="ifp_emergency_phone" value="<?php echo esc_attr($emergency_phone); ?>"></label></p>
        </div>
        <p><label><strong>Medical / customer notes from Dash</strong><br><textarea class="widefat" rows="5" readonly><?php echo esc_textarea($medical_notes); ?></textarea></label></p>
        <p class="description">Emergency contact fields are refreshed by roster sync or <strong>Sync Now</strong>. Customer notes are attempted by <strong>Sync Now</strong> and may be unavailable when the Dash credential cannot read them. Internal notes are never overwritten.</p>
        <?php
    }

    public static function render_relationships($post) {
        $groups = self::groups_for_person($post->ID);
        $productions = self::productions_for_groups($groups);
        echo '<div class="ifp-person-relationships">';
        echo '<p><strong>Productions</strong></p>';
        if ($productions) {
            echo '<ul>';
            foreach ($productions as $production) echo '<li><a href="' . esc_url(get_edit_post_link($production->ID)) . '">' . esc_html($production->post_title) . '</a></li>';
            echo '</ul>';
        } else echo '<p class="description">Not currently connected to a production.</p>';
        echo '<p><strong>Groups</strong></p>';
        if ($groups) {
            echo '<ul>';
            foreach ($groups as $group) echo '<li><a href="' . esc_url(get_edit_post_link($group->ID)) . '">' . esc_html($group->post_title) . '</a></li>';
            echo '</ul>';
        } else echo '<p class="description">Not currently assigned to a group.</p>';
        echo '</div>';
    }

    public static function render_dash($post) {
        $dash_id = get_post_meta($post->ID, '_ifp_dash_customer_id', true);
        $last_sync = get_post_meta($post->ID, '_ifp_dash_last_sync', true);

        if ($dash_id) {
            $dash_url = 'https://apps.daysmartrecreation.com/dash/admin/index.php?Action=CustomerInfo&&CustomerID=' . rawurlencode((string) $dash_id);
            echo '<p><span class="ifp-dash-badge">Dash linked</span></p>';
            echo '<p><strong>Customer ID</strong><br><a class="ifp-dash-customer-link" href="' . esc_url($dash_url) . '" target="_blank" rel="noopener noreferrer" title="Open this customer in Dash" aria-label="Open Dash customer ' . esc_attr($dash_id) . ' in a new tab">' . esc_html($dash_id) . '<span class="dashicons dashicons-external" aria-hidden="true"></span></a></p>';
            echo '<p><strong>Last synchronized</strong><br>' . ($last_sync ? esc_html($last_sync) : 'Never') . '</p>';

            $sync_url = wp_nonce_url(add_query_arg([
                'action' => 'ifp_person_sync',
                'person_id' => $post->ID,
            ], admin_url('admin-post.php')), 'ifp_person_sync_' . $post->ID);
            $raw_url = wp_nonce_url(add_query_arg([
                'action' => 'ifp_person_raw_data',
                'person_id' => $post->ID,
            ], admin_url('admin-post.php')), 'ifp_person_raw_data_' . $post->ID);

            echo '<hr><p><strong>Actions</strong></p>';
            echo '<p class="ifp-dash-actions"><a class="button button-primary" href="' . esc_url($sync_url) . '"><span class="dashicons dashicons-update" aria-hidden="true"></span> Sync Now</a> ';
            echo '<a class="button" href="' . esc_url($raw_url) . '"><span class="dashicons dashicons-media-code" aria-hidden="true"></span> View Raw Data</a></p>';
        } else {
            echo '<p><strong>Customer ID</strong><br>—</p>';
            echo '<p><span class="ifp-dash-badge ifp-dash-badge--unlinked">Not linked</span></p>';
        }
    }

    public static function save($post_id) {
        if (!isset($_POST[self::NONCE_NAME]) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE_NAME])), self::NONCE_ACTION)) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;
        $first = sanitize_text_field(wp_unslash($_POST['ifp_person_first_name'] ?? ''));
        $last = sanitize_text_field(wp_unslash($_POST['ifp_person_last_name'] ?? ''));
        update_post_meta($post_id, '_ifp_participant_first_name', $first);
        update_post_meta($post_id, '_ifp_participant_last_name', $last);
        update_post_meta($post_id, '_ifp_participant_email', sanitize_email(wp_unslash($_POST['ifp_person_email'] ?? '')));
        update_post_meta($post_id, '_ifp_participant_phone', sanitize_text_field(wp_unslash($_POST['ifp_person_phone'] ?? '')));
        update_post_meta($post_id, '_ifp_participant_birthdate', sanitize_text_field(wp_unslash($_POST['ifp_person_birthdate'] ?? '')));
        update_post_meta($post_id, '_ifp_person_notes', sanitize_textarea_field(wp_unslash($_POST['ifp_person_notes'] ?? '')));
        update_post_meta($post_id, '_ifp_emergency_contact', sanitize_text_field(wp_unslash($_POST['ifp_emergency_contact'] ?? '')));
        update_post_meta($post_id, '_ifp_emergency_phone', sanitize_text_field(wp_unslash($_POST['ifp_emergency_phone'] ?? '')));
        $name = trim($first . ' ' . $last);
        if ($name && get_post_field('post_title', $post_id) !== $name) {
            remove_action('save_post_ifp_participant', [__CLASS__, 'save']);
            wp_update_post(['ID' => $post_id, 'post_title' => $name]);
            add_action('save_post_ifp_participant', [__CLASS__, 'save']);
        }
    }

    public static function find_by_dash_id($dash_customer_id) {
        $dash_customer_id = sanitize_text_field((string) $dash_customer_id);
        if ($dash_customer_id === '') return 0;
        $ids = get_posts(['post_type'=>'ifp_participant','post_status'=>'any','posts_per_page'=>1,'fields'=>'ids','meta_key'=>'_ifp_dash_customer_id','meta_value'=>$dash_customer_id]);
        return $ids ? absint($ids[0]) : 0;
    }

    public static function upsert($customer) {
        $attrs = is_array($customer['attributes'] ?? null) ? $customer['attributes'] : [];
        $dash_id = sanitize_text_field((string) ($customer['id'] ?? ''));
        if ($dash_id === '') return new WP_Error('ifp_missing_customer_id', 'Dash customer ID was missing.');
        $first = self::first($attrs, ['first_name','firstName','fname','given_name']);
        $last = self::first($attrs, ['last_name','lastName','lname','family_name']);
        $display = self::first($attrs, ['name','full_name','display_name']);
        if ($display === '') $display = trim($first . ' ' . $last);
        if ($display === '') $display = 'Dash Customer ' . $dash_id;
        $post_id = self::find_by_dash_id($dash_id);
        $postarr = ['post_type'=>'ifp_participant','post_title'=>sanitize_text_field($display),'post_status'=>'publish'];
        if ($post_id) $postarr['ID'] = $post_id;
        $result = $post_id ? wp_update_post(wp_slash($postarr), true) : wp_insert_post(wp_slash($postarr), true);
        if (is_wp_error($result)) return $result;
        $post_id = absint($result);
        update_post_meta($post_id, '_ifp_dash_customer_id', $dash_id);
        update_post_meta($post_id, '_ifp_participant_first_name', sanitize_text_field($first));
        update_post_meta($post_id, '_ifp_participant_last_name', sanitize_text_field($last));
        update_post_meta($post_id, '_ifp_participant_email', sanitize_email(self::first($attrs, ['email','email_address','primary_email'])));
        update_post_meta($post_id, '_ifp_participant_phone', sanitize_text_field(self::first($attrs, ['phone_mobile','phone_day','phone_night','phone','phone_number','mobile_phone','cell_phone'])));
        update_post_meta($post_id, '_ifp_participant_birthdate', self::normalize_date(self::first($attrs, ['birth_date','birthdate','date_of_birth','dob'])));
        update_post_meta($post_id, '_ifp_emergency_contact', sanitize_text_field(self::first($attrs, ['emergency_contact','emergencyContact'])));
        update_post_meta($post_id, '_ifp_emergency_phone', sanitize_text_field(self::first($attrs, ['phone_emergency','emergency_phone','emergencyPhone'])));
        update_post_meta($post_id, '_ifp_dash_customer_payload', $customer);
        update_post_meta($post_id, '_ifp_dash_last_sync', current_time('mysql'));
        return $post_id;
    }

    private static function normalize_date($value) {
        $value = trim((string) $value);
        if ($value === '') return '';
        return preg_match('/^\d{4}-\d{2}-\d{2}/', $value, $m) ? $m[0] : sanitize_text_field($value);
    }

    private static function fetch_live_customer($dash_id) {
        if (!class_exists('IFDC_Client') || !IFDC_Client::is_configured()) {
            return new WP_Error('ifp_dash_not_ready', 'The Dash Connector is not ready.');
        }
        $payload = IFDC_Client::get_data('customers/' . absint($dash_id), [], ['force' => true, 'cache' => false]);
        if (is_wp_error($payload)) return $payload;
        $customer = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        if (!is_array($customer) || empty($customer['id'])) return new WP_Error('ifp_invalid_customer', 'Dash did not return a valid customer record.');
        return $customer;
    }

    private static function fetch_customer_notes($dash_id) {
        if (!class_exists('IFDC_Client') || !IFDC_Client::is_configured()) return [];
        $payload = IFDC_Client::get_data('customers/' . absint($dash_id) . '/customerNotes', [], ['force' => true, 'cache' => false]);
        if (is_wp_error($payload)) return $payload;
        return $payload;
    }

    private static function notes_to_text($payload) {
        $items = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        if (!is_array($items)) return '';
        if (isset($items['attributes'])) $items = [$items];
        $lines = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $attrs = is_array($item['attributes'] ?? null) ? $item['attributes'] : $item;
            $text = self::first($attrs, ['medical_notes','medical_note','note','notes','text','body','description','content']);
            if ($text === '') continue;
            $label = self::first($attrs, ['note_type','type_description','category','title','subject']);
            $lines[] = $label ? $label . ': ' . $text : $text;
        }
        return implode("\n\n", array_values(array_unique($lines)));
    }

    public static function sync_person_action() {
        $person_id = absint($_GET['person_id'] ?? 0);
        if (!$person_id || get_post_type($person_id) !== 'ifp_participant') wp_die('Invalid person.');
        if (!current_user_can('edit_post', $person_id)) wp_die('You do not have permission to synchronize this person.');
        check_admin_referer('ifp_person_sync_' . $person_id);
        $dash_id = get_post_meta($person_id, '_ifp_dash_customer_id', true);
        $redirect = get_edit_post_link($person_id, 'url');
        if (!$dash_id) {
            wp_safe_redirect(add_query_arg(['ifp_person_sync'=>'error','ifp_message'=>rawurlencode('This person is not linked to Dash.')], $redirect)); exit;
        }
        $customer = self::fetch_live_customer($dash_id);
        if (is_wp_error($customer)) {
            wp_safe_redirect(add_query_arg(['ifp_person_sync'=>'error','ifp_message'=>rawurlencode($customer->get_error_message())], $redirect)); exit;
        }
        $result = self::upsert($customer);
        if (is_wp_error($result)) {
            wp_safe_redirect(add_query_arg(['ifp_person_sync'=>'error','ifp_message'=>rawurlencode($result->get_error_message())], $redirect)); exit;
        }
        $notes = self::fetch_customer_notes($dash_id);
        if (!is_wp_error($notes)) {
            update_post_meta($person_id, '_ifp_dash_customer_notes_payload', $notes);
            update_post_meta($person_id, '_ifp_dash_medical_notes', sanitize_textarea_field(self::notes_to_text($notes)));
        } else {
            wp_safe_redirect(add_query_arg([
                'ifp_person_sync' => 'success',
                'ifp_note_warning' => rawurlencode($notes->get_error_message()),
            ], $redirect));
            exit;
        }
        wp_safe_redirect(add_query_arg('ifp_person_sync', 'success', $redirect)); exit;
    }

    public static function person_sync_notice() {
        if (empty($_GET['ifp_person_sync'])) return;
        $status = sanitize_key(wp_unslash($_GET['ifp_person_sync']));
        if ($status === 'success') {
            echo '<div class="notice notice-success is-dismissible"><p><strong>Person synchronized from Dash.</strong> Contact, emergency, and raw customer data were refreshed.</p></div>';
            if (!empty($_GET['ifp_note_warning'])) {
                echo '<div class="notice notice-warning is-dismissible"><p><strong>Customer notes were not refreshed:</strong> ' . esc_html(rawurldecode((string) $_GET['ifp_note_warning'])) . '</p></div>';
            }
        }
        elseif ($status === 'error') echo '<div class="notice notice-error is-dismissible"><p><strong>Dash synchronization failed:</strong> ' . esc_html(rawurldecode((string) ($_GET['ifp_message'] ?? 'Unknown error.'))) . '</p></div>';
    }

    public static function raw_data_page() {
        $person_id = absint($_GET['person_id'] ?? 0);
        if (!$person_id || get_post_type($person_id) !== 'ifp_participant') wp_die('Invalid person.');
        if (!current_user_can('edit_post', $person_id)) wp_die('You do not have permission to view this data.');
        check_admin_referer('ifp_person_raw_data_' . $person_id);
        $dash_id = get_post_meta($person_id, '_ifp_dash_customer_id', true);
        $stored_customer = get_post_meta($person_id, '_ifp_dash_customer_payload', true);
        $stored_notes = get_post_meta($person_id, '_ifp_dash_customer_notes_payload', true);
        $live_customer = $dash_id ? self::fetch_live_customer($dash_id) : new WP_Error('not_linked', 'This person is not linked to Dash.');
        $live_notes = $dash_id ? self::fetch_customer_notes($dash_id) : [];
        $back = get_edit_post_link($person_id, 'url');
        $json = function($value) {
            if (is_wp_error($value)) return ['error' => $value->get_error_message(), 'data' => $value->get_error_data()];
            return $value;
        };
        nocache_headers();
        echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Dash Raw Data — ' . esc_html(get_the_title($person_id)) . '</title>';
        echo '<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f0f0f1;margin:0;color:#1d2327}.wrap{max-width:1200px;margin:32px auto;padding:0 20px}.head{display:flex;justify-content:space-between;align-items:center;gap:16px}.button{display:inline-block;padding:8px 13px;border:1px solid #2271b1;border-radius:3px;background:#2271b1;color:#fff;text-decoration:none}.card{background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:20px;margin:18px 0}pre{white-space:pre-wrap;word-break:break-word;background:#1d2327;color:#f6f7f7;padding:18px;border-radius:5px;max-height:520px;overflow:auto}h2{margin-top:0}.grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}@media(max-width:800px){.grid{grid-template-columns:1fr}}</style></head><body><div class="wrap">';
        echo '<div class="head"><div><h1>Dash Raw Data</h1><p>' . esc_html(get_the_title($person_id)) . ' · Customer #' . esc_html($dash_id) . '</p></div><a class="button" href="' . esc_url($back) . '">Back to Person</a></div>';
        echo '<div class="grid"><div class="card"><h2>Stored Customer Data</h2><pre>' . esc_html(wp_json_encode($json($stored_customer), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)) . '</pre></div>';
        echo '<div class="card"><h2>Live Customer Data</h2><pre>' . esc_html(wp_json_encode($json($live_customer), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)) . '</pre></div>';
        echo '<div class="card"><h2>Stored Customer Notes</h2><pre>' . esc_html(wp_json_encode($json($stored_notes), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)) . '</pre></div>';
        echo '<div class="card"><h2>Live Customer Notes</h2><pre>' . esc_html(wp_json_encode($json($live_notes), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)) . '</pre></div></div>';
        echo '</div></body></html>'; exit;
    }

    public static function row($participant_id) {
        return ['participant_id'=>absint($participant_id),'first_name'=>(string)get_post_meta($participant_id,'_ifp_participant_first_name',true),'last_name'=>(string)get_post_meta($participant_id,'_ifp_participant_last_name',true),'email'=>(string)get_post_meta($participant_id,'_ifp_participant_email',true),'phone'=>(string)get_post_meta($participant_id,'_ifp_participant_phone',true),'notes'=>'','dash_customer_id'=>(string)get_post_meta($participant_id,'_ifp_dash_customer_id',true),'dash_registration_id'=>''];
    }

    private static function first($attrs, $keys) {
        foreach ($keys as $key) if (isset($attrs[$key]) && !is_array($attrs[$key]) && $attrs[$key] !== null && $attrs[$key] !== '') return (string)$attrs[$key];
        return '';
    }

    public static function groups_for_person($participant_id) {
        return get_posts(['post_type'=>'ifp_group','post_status'=>'any','posts_per_page'=>-1,'orderby'=>'title','order'=>'ASC','meta_query'=>[['key'=>'_ifp_group_participant_ids','value'=>'i:' . absint($participant_id) . ';','compare'=>'LIKE']]]);
    }

    private static function productions_for_groups($groups) {
        $ids = [];
        foreach ($groups as $group) { $id = absint(get_post_meta($group->ID, '_ifp_group_production_id', true)); if ($id) $ids[$id] = $id; }
        if (!$ids) return [];
        return get_posts(['post_type'=>'ifp_production','post_status'=>'any','posts_per_page'=>-1,'post__in'=>array_values($ids),'orderby'=>'title','order'=>'ASC']);
    }

    public static function columns($columns) {
        return ['cb'=>$columns['cb'] ?? '<input type="checkbox">','title'=>'Person','ifp_email'=>'Email','ifp_phone'=>'Phone','ifp_productions'=>'Productions','ifp_groups'=>'Groups','ifp_dash_id'=>'Dash','ifp_last_sync'=>'Last Sync'];
    }

    public static function column($column, $post_id) {
        if ($column === 'ifp_email') { $email=(string)get_post_meta($post_id,'_ifp_participant_email',true); echo $email ? '<a href="mailto:' . esc_attr($email) . '">' . esc_html($email) . '</a>' : '—'; }
        if ($column === 'ifp_phone') echo esc_html((string)get_post_meta($post_id,'_ifp_participant_phone',true) ?: '—');
        if ($column === 'ifp_dash_id') { $id=(string)get_post_meta($post_id,'_ifp_dash_customer_id',true); echo $id ? '<span class="ifp-dash-badge">#' . esc_html($id) . '</span>' : '—'; }
        if ($column === 'ifp_last_sync') echo esc_html((string)get_post_meta($post_id,'_ifp_dash_last_sync',true) ?: '—');
        if ($column === 'ifp_groups' || $column === 'ifp_productions') {
            $groups=self::groups_for_person($post_id);
            $items=$column==='ifp_groups' ? $groups : self::productions_for_groups($groups);
            if (!$items) { echo '—'; return; }
            $links=[]; foreach ($items as $item) $links[]='<a href="'.esc_url(get_edit_post_link($item->ID)).'">'.esc_html($item->post_title).'</a>';
            echo implode(', ', $links);
        }
    }

    public static function filters($post_type) {
        if ($post_type !== 'ifp_participant') return;
        $production = absint($_GET['ifp_people_production'] ?? 0);
        $group = absint($_GET['ifp_people_group'] ?? 0);
        $productions=get_posts(['post_type'=>'ifp_production','post_status'=>'any','posts_per_page'=>-1,'orderby'=>'title','order'=>'ASC']);
        $groups=get_posts(['post_type'=>'ifp_group','post_status'=>'any','posts_per_page'=>-1,'orderby'=>'title','order'=>'ASC']);
        echo '<select name="ifp_people_production"><option value="0">All productions</option>';
        foreach($productions as $p) echo '<option value="'.esc_attr($p->ID).'" '.selected($production,$p->ID,false).'>'.esc_html($p->post_title).'</option>';
        echo '</select><select name="ifp_people_group"><option value="0">All groups</option>';
        foreach($groups as $g) echo '<option value="'.esc_attr($g->ID).'" '.selected($group,$g->ID,false).'>'.esc_html($g->post_title).'</option>';
        echo '</select>';
    }

    public static function apply_filters($query) {
        if (!is_admin() || !$query->is_main_query() || $query->get('post_type') !== 'ifp_participant') return;
        $group_id=absint($_GET['ifp_people_group'] ?? 0); $production_id=absint($_GET['ifp_people_production'] ?? 0);
        $ids=[];
        if ($group_id) $ids=(array)get_post_meta($group_id,'_ifp_group_participant_ids',true);
        elseif ($production_id) {
            $groups=get_posts(['post_type'=>'ifp_group','post_status'=>'any','posts_per_page'=>-1,'fields'=>'ids','meta_key'=>'_ifp_group_production_id','meta_value'=>$production_id]);
            foreach($groups as $gid) $ids=array_merge($ids,(array)get_post_meta($gid,'_ifp_group_participant_ids',true));
        }
        if ($group_id || $production_id) $query->set('post__in', $ids ? array_values(array_unique(array_map('absint',$ids))) : [0]);
    }

    public static function toolbar() {
        global $pagenow, $typenow;
        if ($pagenow !== 'edit.php' || $typenow !== 'ifp_participant') return;
        if (!current_user_can('edit_ifp_people')) return;
        $args=['action'=>'ifp_people_export','ifp_people_production'=>absint($_GET['ifp_people_production'] ?? 0),'ifp_people_group'=>absint($_GET['ifp_people_group'] ?? 0)];
        $export=wp_nonce_url(add_query_arg($args,admin_url('admin-post.php')),'ifp_people_export');
        $emails=self::filtered_emails();
        echo '<div class="notice notice-info ifp-people-toolbar"><p><strong>People tools:</strong> <a class="button" href="'.esc_url($export).'">Export CSV</a> ';
        if ($emails) {
            $group_id = absint($_GET['ifp_people_group'] ?? 0);
            $production_id = absint($_GET['ifp_people_production'] ?? 0);
            if ($group_id) $compose_url = IFP_Communications::compose_url('group', $group_id);
            elseif ($production_id) $compose_url = IFP_Communications::compose_url('production', $production_id);
            else $compose_url = add_query_arg(['page'=>'ifp-communications','recipient_type'=>'people','all_people'=>1], admin_url('admin.php'));
            echo '<a class="button button-primary" href="'.esc_url($compose_url).'">Email filtered people</a>';
        }
        else echo '<span class="button disabled">Email filtered people</span>';
        echo ' <span class="description">Opens Communication Center with this audience selected for review and HTML email history.</span></p></div>';
    }

    private static function filtered_ids() {
        $group_id=absint($_GET['ifp_people_group'] ?? 0); $production_id=absint($_GET['ifp_people_production'] ?? 0);
        if ($group_id) return array_map('absint',(array)get_post_meta($group_id,'_ifp_group_participant_ids',true));
        if ($production_id) {
            $ids=[]; $groups=get_posts(['post_type'=>'ifp_group','post_status'=>'any','posts_per_page'=>-1,'fields'=>'ids','meta_key'=>'_ifp_group_production_id','meta_value'=>$production_id]);
            foreach($groups as $gid) $ids=array_merge($ids,(array)get_post_meta($gid,'_ifp_group_participant_ids',true));
            return array_values(array_unique(array_map('absint',$ids)));
        }
        return get_posts(['post_type'=>'ifp_participant','post_status'=>'any','posts_per_page'=>-1,'fields'=>'ids']);
    }

    private static function filtered_emails() {
        $emails=[]; foreach(self::filtered_ids() as $id){$email=sanitize_email(get_post_meta($id,'_ifp_participant_email',true));if($email)$emails[$email]=$email;} return array_values($emails);
    }

    public static function export_csv() {
        if (!current_user_can('edit_ifp_people')) wp_die('You do not have permission to export people.');
        check_admin_referer('ifp_people_export');
        $ids=self::filtered_ids();
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=ice-field-people-' . gmdate('Y-m-d') . '.csv');
        $out=fopen('php://output','w');
        fputcsv($out,['Name','First Name','Last Name','Email','Phone','Birthdate','Productions','Groups','Dash Customer ID','Last Sync']);
        foreach($ids as $id){$groups=self::groups_for_person($id);$productions=self::productions_for_groups($groups);$row=[get_the_title($id),get_post_meta($id,'_ifp_participant_first_name',true),get_post_meta($id,'_ifp_participant_last_name',true),get_post_meta($id,'_ifp_participant_email',true),get_post_meta($id,'_ifp_participant_phone',true),get_post_meta($id,'_ifp_participant_birthdate',true),implode('; ',wp_list_pluck($productions,'post_title')),implode('; ',wp_list_pluck($groups,'post_title')),get_post_meta($id,'_ifp_dash_customer_id',true),get_post_meta($id,'_ifp_dash_last_sync',true)];fputcsv($out,array_map([__CLASS__,'csv_cell'],$row));}
        fclose($out); exit;
    }

    public static function csv_cell($value) {
        $value = (string) $value;
        return preg_match('/^[=+\-@]/', $value) ? "'" . $value : $value;
    }
}
