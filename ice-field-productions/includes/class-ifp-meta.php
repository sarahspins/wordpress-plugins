<?php
if (!defined('ABSPATH')) exit;

class IFP_Meta {
    public static function init() {
        add_action('add_meta_boxes', [__CLASS__, 'boxes']);
        add_action('save_post_ifp_production', [__CLASS__, 'save_production']);
        add_action('save_post_ifp_sponsor', [__CLASS__, 'save_sponsor']);
        add_action('save_post_ifp_notice', [__CLASS__, 'save_notice']);
        add_action('save_post_ifp_resource', [__CLASS__, 'save_resource']);
        add_action('save_post_ifp_event', [__CLASS__, 'save_event']);
        add_action('save_post_ifp_contact', [__CLASS__, 'save_contact']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
    }

    public static function assets($hook) {
        global $post_type;
        if (in_array($post_type, ['ifp_production','ifp_sponsor','ifp_notice','ifp_resource','ifp_event','ifp_contact'], true)) {
            wp_enqueue_style('ifp-admin', IFP_URL . 'assets/admin.css', [], IFP_VERSION);
            wp_enqueue_media();
            wp_enqueue_script('ifp-admin', IFP_URL . 'assets/admin.js', ['jquery'], IFP_VERSION, true);
            if (class_exists('IFP_Admin')) IFP_Admin::localize_page_picker();
        }
    }

    public static function boxes() {
        add_meta_box('ifp_production_tabs', 'Production Setup', [__CLASS__, 'production_box'], 'ifp_production', 'normal', 'high');
        add_meta_box('ifp_current_box', 'Production Status', [__CLASS__, 'current_box'], 'ifp_production', 'side', 'high');
        add_meta_box('ifp_sponsor_box', 'Sponsor Details', [__CLASS__, 'sponsor_box'], 'ifp_sponsor', 'normal', 'high');
        add_meta_box('ifp_notice_box', 'Notice Details', [__CLASS__, 'notice_box'], 'ifp_notice', 'normal', 'high');
        add_meta_box('ifp_resource_box', 'Resource Details', [__CLASS__, 'resource_box'], 'ifp_resource', 'normal', 'high');
        add_meta_box('ifp_event_box', 'Important Date Details', [__CLASS__, 'event_box'], 'ifp_event', 'normal', 'high');
        add_meta_box('ifp_contact_box', 'Contact Details', [__CLASS__, 'contact_box'], 'ifp_contact', 'normal', 'high');
    }

    private static function field($post_id, $key, $default='') {
        $v = get_post_meta($post_id, $key, true);
        return $v !== '' ? $v : $default;
    }

    public static function production_box($post) {
        wp_nonce_field('ifp_save_production', 'ifp_nonce');
        $fields = [
            'season','year','tagline','opening_date','closing_date','current_start_date','ticket_available_date','ticket_url','registration_url',
            'trailer_url','program_pdf','accent_color','secondary_color','countdown_text_color','overlay_color','hero_overlay_opacity','panel_overlay_opacity',
            'participant_hub_url','venue_name','venue_address','parking_notes','venue_image_id',
            'rehearsal_url','costume_url','volunteer_url','program_ads_url','show_logo_id',
            'registration_open','registration_close','website_url','shared_folder_url','music_folder_url'
        ];
        foreach ($fields as $f) $$f = esc_attr(self::field($post->ID, '_ifp_'.$f));
        $programming_season_id = absint(self::field($post->ID, '_ifp_programming_season_id'));

        echo '<div class="ifp-tabs">';
        echo '<button type="button" class="ifp-tab is-active" data-tab="general">General</button>';
        echo '<button type="button" class="ifp-tab" data-tab="branding">Branding</button>';
        echo '<button type="button" class="ifp-tab" data-tab="media">Media</button>';
        echo '<button type="button" class="ifp-tab" data-tab="tickets">Tickets</button>';
        echo '<button type="button" class="ifp-tab" data-tab="participants">Participants</button>';
        echo '<button type="button" class="ifp-tab" data-tab="venue">Venue</button>';
        echo '<button type="button" class="ifp-tab" data-tab="public-page">Public Page</button>';
        echo '</div>';

        echo '<div class="ifp-panel is-active" data-panel="general">';
        echo '<div class="ifp-field-grid">';
        self::input('season','Season',$season);
        self::input('year','Year',$year,'number');
        echo '</div>';
        self::input('tagline','Tagline',$tagline);
        self::input('opening_date','Opening Date & Time',$opening_date,'datetime-local');
        self::input('closing_date','Closing Date & Time',$closing_date,'datetime-local');
        self::input('current_start_date','Become Current On',$current_start_date,'date');
        echo '<p class="description">A published Upcoming Production becomes Current automatically on this date. After its Closing Date &amp; Time passes, a Current Production becomes Completed.</p>';
        self::input('registration_open','Registration Opens',$registration_open,'datetime-local');
        self::input('registration_close','Registration Closes',$registration_close,'datetime-local');
        echo '<p class="description">Use the main editor above for the full production story and overview.</p>';
        echo '<hr><h3>Programming Alignment</h3>';
        echo '<p><label><strong>Linked Programming Season</strong><br>';
        if (post_type_exists('ifprog_season')) {
            echo '<select class="widefat" name="ifp_programming_season_id">';
            echo '<option value="0">— Not linked yet —</option>';
            $programming_seasons = get_posts([
                'post_type' => 'ifprog_season',
                'post_status' => 'any',
                'posts_per_page' => -1,
                'orderby' => 'title',
                'order' => 'ASC',
            ]);
            foreach ($programming_seasons as $programming_season) {
                echo '<option value="'.esc_attr($programming_season->ID).'" '.selected($programming_season_id, $programming_season->ID, false).'>'.esc_html($programming_season->post_title).'</option>';
            }
            echo '</select>';
        } else {
            echo '<input class="widefat" type="number" min="0" name="ifp_programming_season_id" value="'.esc_attr($programming_season_id).'">';
            echo '<span class="description">Programming is not currently active. The relationship ID can still be retained.</span>';
        }
        echo '</label></p>';
        echo '<p class="description">Stage 1 records the relationship only. Programming does not drive this Production automatically yet.</p>';
        echo '</div>';

        echo '<div class="ifp-panel" data-panel="branding">';
        self::input('accent_color','Accent Color',$accent_color ?: '#ff8033','color');
        self::input('secondary_color','Secondary Color',$secondary_color ?: '#003b5c','color');
        self::input('countdown_text_color','Countdown Text Color',$countdown_text_color ?: '#ffffff','color');
        self::input('overlay_color','Overlay Color',$overlay_color ?: ($secondary_color ?: '#003b5c'),'color');
        self::input('hero_overlay_opacity','Hero Image Overlay Opacity (0–100%)',$hero_overlay_opacity !== '' ? $hero_overlay_opacity : '92','number');
        self::input('panel_overlay_opacity','Information Box Opacity (0–100%)',$panel_overlay_opacity !== '' ? $panel_overlay_opacity : '78','number');
        echo '<p class="description">Set the hero overlay to 0 for untouched artwork. The countdown and performance-detail boxes keep their own independent transparency.</p>';

        $logo_id = absint(self::field($post->ID, '_ifp_show_logo_id'));
        $logo_url = $logo_id ? wp_get_attachment_image_url($logo_id, 'medium') : '';

        echo '<div class="ifp-media-field" data-media-title="Choose Show Logo" data-media-button="Use This Logo" data-empty-label="No show logo selected">';
        echo '<p><strong>Show Logo</strong></p>';
        echo '<input type="hidden" class="ifp-media-id" name="ifp_show_logo_id" value="'.esc_attr($logo_id).'">';
        echo '<div class="ifp-media-preview">';
        if ($logo_url) {
            echo '<img src="'.esc_url($logo_url).'" alt="">';
        } else {
            echo '<span>No show logo selected</span>';
        }
        echo '</div>';
        echo '<p><button type="button" class="button ifp-select-media">Choose Show Logo</button> ';
        echo '<button type="button" class="button-link-delete ifp-remove-media"'.($logo_id ? '' : ' style="display:none"').'>Remove</button></p>';
        echo '<p class="description">Use a transparent PNG or SVG-style logo image when possible. The Featured Image remains the hero background.</p>';
        echo '</div>';

        echo '<p><strong>Hero Background / Poster Image:</strong> use the Featured Image panel in the right sidebar.</p>';
        echo '</div>';

        echo '<div class="ifp-panel" data-panel="media">';
        self::media_url_input('trailer_url','Trailer URL',$trailer_url,'video','Choose Video','Use This Video');
        echo '<p class="description">Choose an uploaded video or paste an external trailer URL, such as YouTube or Vimeo.</p>';
        self::media_url_input('program_pdf','Program PDF URL',$program_pdf,'application/pdf','Choose PDF','Use This PDF');
        echo '</div>';

        echo '<div class="ifp-panel" data-panel="tickets">';
        self::input('ticket_url','Ticket URL',$ticket_url,'url');
        self::input('ticket_available_date','Tickets Available On',$ticket_available_date,'date');
        echo '<p class="description">Ticket buttons become active on this date and are removed automatically after the Closing Date &amp; Time.</p>';
        self::input('registration_url','Registration URL',$registration_url,'url');
        echo '<p class="description">Dash imports fill the Registration URL automatically. If you replace it here, your manual URL is preserved during later syncs.</p>';
        echo '</div>';

        echo '<div class="ifp-panel" data-panel="participants">';
        self::input('participant_hub_url','Participant Hub URL',$participant_hub_url,'url');
        self::media_url_input('rehearsal_url','Rehearsal Schedule URL',$rehearsal_url,'','Choose Schedule File','Use This File');
        self::media_url_input('costume_url','Costume Information URL',$costume_url,'','Choose Costume File','Use This File');
        self::input('volunteer_url','Volunteer URL',$volunteer_url,'url');
        self::media_url_input('program_ads_url','Program Advertising URL',$program_ads_url,'','Choose Advertising File','Use This File');
        echo '<hr><h3>Production Quick Links</h3>';
        self::input('website_url','Production Website URL',$website_url,'url');
        self::input('shared_folder_url','Shared Folder URL',$shared_folder_url,'url');
        self::input('music_folder_url','Music Folder URL',$music_folder_url,'url');
        echo '</div>';

        echo '<div class="ifp-panel" data-panel="venue">';
        self::input('venue_name','Venue Name',$venue_name);
        self::input('venue_address','Venue Address',$venue_address);
        self::textarea('parking_notes','Parking / Arrival Notes',$parking_notes);
        $venue_image_id = absint($venue_image_id);
        $venue_image_url = $venue_image_id ? wp_get_attachment_image_url($venue_image_id, 'medium_large') : '';
        echo '<div class="ifp-media-field" data-media-title="Choose Venue Image" data-media-button="Use This Image" data-empty-label="No venue image selected">';
        echo '<p><strong>Venue Image</strong></p>';
        echo '<input type="hidden" class="ifp-media-id" name="ifp_venue_image_id" value="'.esc_attr($venue_image_id).'">';
        echo '<div class="ifp-media-preview">';
        if ($venue_image_url) {
            echo '<img src="'.esc_url($venue_image_url).'" alt="">';
        } else {
            echo '<span>No venue image selected</span>';
        }
        echo '</div>';
        echo '<p><button type="button" class="button ifp-select-media">Choose Venue Image</button> ';
        echo '<button type="button" class="button-link-delete ifp-remove-media"'.($venue_image_id ? '' : ' style="display:none"').'>Remove</button></p>';
        echo '<p class="description">This image appears with the venue details on the public Production page.</p>';
        echo '</div>';
        echo '</div>';

        echo '<div class="ifp-panel" data-panel="public-page">';
        echo '<p class="description">Choose which production-specific sections appear on the public production page. The Participant Hub keeps its own participant-facing content.</p>';
        $section_defaults = [
            'dates' => 'Important Dates',
            'groups' => 'Registration Options / Performance Groups',
            'venue' => 'Venue / Plan Your Visit',
            'media' => 'Trailer / Digital Program',
            'sponsors' => 'Sponsors',
        ];
        foreach ($section_defaults as $key => $label) {
            $saved = get_post_meta($post->ID, '_ifp_public_show_'.$key, true);
            $checked = $saved === '' ? true : $saved === '1';
            echo '<p><label><input type="checkbox" name="ifp_public_show_'.$key.'" value="1" '.checked($checked, true, false).'> <strong>'.esc_html($label).'</strong></label></p>';
        }
        $registration_shortcode = '[ifp_registration production_id="' . absint($post->ID) . '"]';
        echo '<hr><p><label><strong>Registration Options Shortcode</strong><br><input class="widefat" type="text" readonly value="'.esc_attr($registration_shortcode).'" onclick="this.select();"></label></p>';
        echo '<p class="description">Paste this into any page to display only this Production’s registration accordion and automatic registration-window status.</p>';
        echo '</div>';
    }

    public static function current_box($post) {
        $status = IFP_Production_Status::get($post->ID);
        $options = [
            'draft' => '📝 Draft',
            'upcoming' => '📅 Upcoming',
            'current' => '⭐ Current',
            'completed' => '✅ Completed',
            'archived' => '🗄️ Archived',
        ];
        echo '<p><label><strong>Website status</strong><br><select class="widefat" name="ifp_production_status">';
        foreach ($options as $value => $label) echo '<option value="'.esc_attr($value).'" '.selected($status,$value,false).'>'.esc_html($label).'</option>';
        echo '</select></label></p>';
        echo '<p class="description"><strong>Current</strong> leads the website. <strong>Upcoming</strong> follows it. Completed and Archived appear in Memory Lane. Draft is hidden.</p>';
        $current_start = get_post_meta($post->ID, IFP_Production_Status::CURRENT_START_KEY, true);
        if ($current_start && $status === 'upcoming') {
            $timestamp = strtotime($current_start);
            echo '<p><strong>Scheduled:</strong><br>Becomes Current ' . esc_html($timestamp ? wp_date(get_option('date_format'), $timestamp) : $current_start) . '</p>';
        }
        if ($status === 'current' && get_post_meta($post->ID, '_ifp_closing_date', true)) {
            echo '<p class="description">This Production will become Completed automatically after its Closing Date &amp; Time.</p>';
        }
    }

    private static function input($key,$label,$value,$type='text') {
        echo '<p><label><strong>'.esc_html($label).'</strong><br><input class="widefat" type="'.esc_attr($type).'" name="ifp_'.$key.'" value="'.esc_attr($value).'"></label></p>';
    }
    private static function media_url_input($key,$label,$value,$library_type='',$title='Choose Media',$button='Use This Media') {
        echo '<div class="ifp-media-url-field" data-library-type="'.esc_attr($library_type).'" data-media-title="'.esc_attr($title).'" data-media-button="'.esc_attr($button).'">';
        echo '<label><strong>'.esc_html($label).'</strong></label>';
        echo '<div class="ifp-media-url-control">';
        echo '<input class="widefat ifp-media-url" type="url" name="ifp_'.$key.'" value="'.esc_attr($value).'">';
        echo '<button type="button" class="button ifp-select-media-url">'.esc_html($title).'</button>';
        echo '</div></div>';
    }
    private static function textarea($key,$label,$value) {
        echo '<p><label><strong>'.esc_html($label).'</strong><br><textarea class="widefat" rows="4" name="ifp_'.$key.'">'.esc_textarea($value).'</textarea></label></p>';
    }

    public static function sponsor_box($post) {
        wp_nonce_field('ifp_save_sponsor','ifp_sponsor_nonce');
        if (class_exists('IFP_Relationships')) IFP_Relationships::render($post->ID);
        self::input('sponsor_url','Website URL',self::field($post->ID,'_ifp_sponsor_url'),'url');
        self::input('sponsor_tagline','Tagline',self::field($post->ID,'_ifp_sponsor_tagline'));
        $level = self::field($post->ID,'_ifp_sponsor_level','Community');
        echo '<p><label><strong>Level</strong><br><select class="widefat" name="ifp_sponsor_level">';
        foreach (['Presenting','Gold','Silver','Bronze','Community'] as $opt) {
            echo '<option '.selected($level,$opt,false).'>'.esc_html($opt).'</option>';
        }
        echo '</select></label></p>';
        echo '<p><label><input type="checkbox" name="ifp_sponsor_active" value="1" '.checked(self::field($post->ID,'_ifp_sponsor_active','1'),'1',false).'> Active</label></p>';
        echo '<p class="description">Use the Featured Image panel for the sponsor logo.</p>';
    }

    public static function notice_box($post) {
        wp_nonce_field('ifp_save_notice','ifp_notice_nonce');
        if (class_exists('IFP_Relationships')) IFP_Relationships::render($post->ID);

        self::input('notice_starts','Start Date',self::field($post->ID,'_ifp_notice_starts'),'date');
        self::input('notice_expires','End / Expiration Date',self::field($post->ID,'_ifp_notice_expires'),'date');

        $priority = self::field($post->ID,'_ifp_notice_priority','Information');
        echo '<p><label><strong>Notice Type</strong><br><select class="widefat" name="ifp_notice_priority">';
        foreach (['Information','Success','Reminder','Important','Urgent'] as $opt) {
            echo '<option '.selected($priority,$opt,false).'>'.esc_html($opt).'</option>';
        }
        echo '</select></label></p>';

        self::input('notice_icon','Optional Icon / Emoji',self::field($post->ID,'_ifp_notice_icon'));
        echo '<p><label><input type="checkbox" name="ifp_notice_sticky" value="1" '
            .checked(self::field($post->ID,'_ifp_notice_sticky','0'),'1',false)
            .'> Pin this notice to the top</label></p>';
    }

    public static function resource_box($post) {
        wp_nonce_field('ifp_save_resource','ifp_resource_nonce');
        if (class_exists('IFP_Relationships')) IFP_Relationships::render($post->ID);

        $page_id = absint(self::field($post->ID,'_ifp_resource_page_id'));
        $media_id = absint(self::field($post->ID,'_ifp_resource_media_id'));
        $url = self::field($post->ID,'_ifp_resource_url');

        $source = self::field($post->ID,'_ifp_resource_source','');
        if (!$source) {
            if ($media_id) $source = 'media';
            elseif ($page_id) $source = 'page';
            elseif ($url) $source = 'url';
            else $source = 'page';
        }

        echo '<div class="ifp-resource-source-picker">';
        echo '<p><strong>Resource Destination</strong></p>';

        foreach ([
            'page' => 'WordPress Page',
            'url' => 'External Link',
            'media' => 'Media Library File'
        ] as $value => $label) {
            echo '<label class="ifp-resource-source-option">';
            echo '<input type="radio" name="ifp_resource_source" value="'.esc_attr($value).'" '.checked($source,$value,false).'> ';
            echo esc_html($label);
            echo '</label>';
        }

        echo '<div class="ifp-resource-source-panel" data-source="page">';
        echo '<p><label><strong>WordPress Page</strong><br>';
        wp_dropdown_pages([
            'name' => 'ifp_resource_page_id',
            'selected' => $page_id,
            'show_option_none' => '— Select a page —',
            'option_none_value' => '0',
            'class' => 'widefat'
        ]);
        echo '</label></p>';
        echo '<p class="description">Choose an existing WordPress page. Renaming the page will not break the resource.</p>';
        echo '</div>';

        echo '<div class="ifp-resource-source-panel" data-source="url">';
        self::input('resource_url','External URL',$url,'url');
        echo '<p class="description">Use this for Dash, signup forms, external websites, or another direct web address.</p>';
        echo '</div>';

        $media_url = $media_id ? wp_get_attachment_url($media_id) : '';
        $media_name = $media_id ? get_the_title($media_id) : '';
        if (!$media_name && $media_url) $media_name = wp_basename($media_url);

        echo '<div class="ifp-resource-source-panel" data-source="media">';
        echo '<div class="ifp-resource-media-field">';
        echo '<input type="hidden" class="ifp-resource-media-id" name="ifp_resource_media_id" value="'.esc_attr($media_id).'">';
        echo '<div class="ifp-resource-media-preview">';
        if ($media_id && $media_url) {
            echo '<span class="dashicons dashicons-media-document"></span>';
            echo '<div><strong>'.esc_html($media_name ?: 'Selected file').'</strong><br><small>'.esc_html(wp_basename($media_url)).'</small></div>';
        } else {
            echo '<span class="dashicons dashicons-upload"></span><span>No media file selected</span>';
        }
        echo '</div>';
        echo '<p><button type="button" class="button ifp-select-resource-media">'.($media_id ? 'Change File' : 'Select File').'</button> ';
        echo '<button type="button" class="button-link-delete ifp-remove-resource-media" '.($media_id ? '' : 'style="display:none"').'>Remove File</button></p>';
        echo '</div>';
        echo '<p class="description">Choose a PDF, document, spreadsheet, image, ZIP, audio, video, or other file accepted by WordPress.</p>';
        echo '</div>';

        echo '</div>';

        $type = self::field($post->ID,'_ifp_resource_type','General');
        echo '<p><label><strong>Resource Group</strong><br><select class="widefat" name="ifp_resource_type">';
        foreach ([
            'General','Featured','Handbook','Schedule','Rehearsal','Costume',
            'Music','Parent Info','Volunteer','Forms','Staff','Downloads','External Link'
        ] as $opt) {
            echo '<option '.selected($type,$opt,false).'>'.esc_html($opt).'</option>';
        }
        echo '</select></label></p>';

        self::input('resource_button_text','Button Text',self::field($post->ID,'_ifp_resource_button_text','Open Resource'));

        echo '<p><label><input type="checkbox" name="ifp_resource_featured" value="1" '
            .checked(self::field($post->ID,'_ifp_resource_featured','0'),'1',false)
            .'> Feature this resource near the top of the hub</label></p>';

        echo '<p><label><input type="checkbox" name="ifp_resource_password_label" value="1" '
            .checked(self::field($post->ID,'_ifp_resource_password_label','0'),'1',false)
            .'> Label as password required / staff resource</label></p>';
    }


    public static function event_box($post) {
        wp_nonce_field('ifp_save_event','ifp_event_nonce');
        if (class_exists('IFP_Relationships')) IFP_Relationships::render($post->ID);
        self::input('event_date','Start Date',self::field($post->ID,'_ifp_event_date'),'date');
        self::input('event_end_date','End Date (optional)',self::field($post->ID,'_ifp_event_end_date'),'date');
        echo '<p class="description">Leave End Date blank for a one-day item.</p>';
        self::input('event_time','Start Time',self::field($post->ID,'_ifp_event_time'),'time');
        self::input('event_end_time','End Time',self::field($post->ID,'_ifp_event_end_time'),'time');
        self::input('event_location','Location',self::field($post->ID,'_ifp_event_location'));

        $type = self::field($post->ID,'_ifp_event_type','General');
        echo '<p><label><strong>Event Type</strong><br><select class="widefat" name="ifp_event_type">';
        foreach ([
            'General','Registration','Costume Fitting','Costume Delivery',
            'Photo Day','Rehearsal','Dress Rehearsal','Performance',
            'Ticket Deadline','Volunteer Deadline','Other'
        ] as $opt) {
            echo '<option '.selected($type,$opt,false).'>'.esc_html($opt).'</option>';
        }
        echo '</select></label></p>';

        self::input('event_link','Optional Link',self::field($post->ID,'_ifp_event_link'),'url');
        echo '<p><label><input type="checkbox" name="ifp_event_featured" value="1" '
            .checked(self::field($post->ID,'_ifp_event_featured','1'),'1',false)
            .'> Show in Important Dates</label></p>';
        echo '<p class="description">Use the main editor for additional details.</p>';
    }


    public static function contact_box($post) {
        wp_nonce_field('ifp_save_contact','ifp_contact_nonce');
        if (class_exists('IFP_Relationships')) IFP_Relationships::render($post->ID);

        self::input('contact_role','Role / Position',self::field($post->ID,'_ifp_contact_role'));
        self::input('contact_email','Email',self::field($post->ID,'_ifp_contact_email'),'email');
        self::input('contact_phone','Phone (optional)',self::field($post->ID,'_ifp_contact_phone'),'tel');

        $category = self::field($post->ID,'_ifp_contact_category','Production Team');
        $categories = class_exists('IFP_Participant_Hub')
            ? IFP_Participant_Hub::contact_categories()
            : ['Production Team','Administration','Costumes','Volunteers','Technical','Front Desk','Other'];

        // Preserve a previously saved category even when it has been removed from settings.
        if ($category && !in_array($category, $categories, true)) {
            $categories[] = $category;
        }

        echo '<p><label><strong>Category</strong><br><select class="widefat" name="ifp_contact_category">';
        foreach ($categories as $opt) {
            echo '<option '.selected($category,$opt,false).'>'.esc_html($opt).'</option>';
        }
        echo '</select></label></p>';

        self::input('contact_order','Display Order',self::field($post->ID,'_ifp_contact_order','10'),'number');

        echo '<p><label><input type="checkbox" name="ifp_contact_active" value="1" '
            .checked(self::field($post->ID,'_ifp_contact_active','1'),'1',false)
            .'> Show in Participant Hub</label></p>';

        echo '<p class="description">Use the Featured Image panel for an optional contact photo. Use the main editor for a short note or responsibility description.</p>';
    }

    public static function save_production($post_id) {
        if (!isset($_POST['ifp_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ifp_nonce'])),'ifp_save_production')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;
        $keys = ['season','year','tagline','opening_date','closing_date','current_start_date','registration_open','registration_close','ticket_available_date','ticket_url','registration_url','trailer_url','program_pdf','accent_color','secondary_color','countdown_text_color','overlay_color','hero_overlay_opacity','panel_overlay_opacity','participant_hub_url','venue_name','venue_address','parking_notes','venue_image_id','rehearsal_url','costume_url','volunteer_url','program_ads_url','website_url','shared_folder_url','music_folder_url','show_logo_id'];
        foreach ($keys as $key) {
            if (!isset($_POST['ifp_'.$key])) continue;
            $value = wp_unslash($_POST['ifp_'.$key]);
            if (in_array($key, ['show_logo_id','venue_image_id'], true)) {
                $value = absint($value);
            } elseif (in_array($key, ['hero_overlay_opacity','panel_overlay_opacity'], true)) {
                $value = max(0, min(100, absint($value)));
            } elseif (in_array($key, ['accent_color','secondary_color','countdown_text_color','overlay_color'], true)) {
                $value = sanitize_hex_color($value) ?: '';
            } elseif (strpos($key, 'url') !== false || $key === 'program_pdf') {
                $value = esc_url_raw($value);
            } else {
                $value = sanitize_textarea_field($value);
            }
            update_post_meta($post_id,'_ifp_'.$key,$value);
        }

        $programming_season_id = absint($_POST['ifp_programming_season_id'] ?? 0);
        if ($programming_season_id && post_type_exists('ifprog_season') && get_post_type($programming_season_id) !== 'ifprog_season') {
            $programming_season_id = 0;
        }
        update_post_meta($post_id, '_ifp_programming_season_id', $programming_season_id);

        $status = sanitize_key($_POST['ifp_production_status'] ?? 'upcoming');
        if (!in_array($status, IFP_Production_Status::values(), true)) $status = 'upcoming';
        IFP_Production_Status::set($post_id, $status, $status === 'current' ? 'completed' : 'upcoming');
        IFP_Production_Status::reconcile(true);

        foreach (['dates','groups','venue','media','sponsors'] as $section) {
            update_post_meta(
                $post_id,
                '_ifp_public_show_'.$section,
                isset($_POST['ifp_public_show_'.$section]) ? '1' : '0'
            );
        }
    }

    public static function save_sponsor($post_id) {
        if (!isset($_POST['ifp_sponsor_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ifp_sponsor_nonce'])),'ifp_save_sponsor')) return;
        if (!current_user_can('edit_post', $post_id)) return;
        if (class_exists('IFP_Relationships')) IFP_Relationships::save($post_id);
        update_post_meta($post_id,'_ifp_sponsor_url',esc_url_raw($_POST['ifp_sponsor_url'] ?? ''));
        update_post_meta($post_id,'_ifp_sponsor_tagline',sanitize_text_field($_POST['ifp_sponsor_tagline'] ?? ''));
        update_post_meta($post_id,'_ifp_sponsor_level',sanitize_text_field($_POST['ifp_sponsor_level'] ?? 'Community'));
        update_post_meta($post_id,'_ifp_sponsor_active',isset($_POST['ifp_sponsor_active']) ? '1' : '0');
    }

    public static function save_notice($post_id) {
        if (!isset($_POST['ifp_notice_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ifp_notice_nonce'])),'ifp_save_notice')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;
        if (class_exists('IFP_Relationships')) IFP_Relationships::save($post_id);

        update_post_meta($post_id,'_ifp_notice_starts',sanitize_text_field($_POST['ifp_notice_starts'] ?? ''));
        update_post_meta($post_id,'_ifp_notice_expires',sanitize_text_field($_POST['ifp_notice_expires'] ?? ''));
        update_post_meta($post_id,'_ifp_notice_priority',sanitize_text_field($_POST['ifp_notice_priority'] ?? 'Information'));
        update_post_meta($post_id,'_ifp_notice_icon',sanitize_text_field($_POST['ifp_notice_icon'] ?? ''));
        update_post_meta($post_id,'_ifp_notice_sticky',isset($_POST['ifp_notice_sticky']) ? '1' : '0');
    }

    public static function save_resource($post_id) {
        if (!isset($_POST['ifp_resource_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ifp_resource_nonce'])),'ifp_save_resource')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;
        if (class_exists('IFP_Relationships')) IFP_Relationships::save($post_id);

        $source = sanitize_text_field(wp_unslash($_POST['ifp_resource_source'] ?? 'page'));
        if (!in_array($source, ['page','url','media'], true)) $source = 'page';

        update_post_meta($post_id,'_ifp_resource_source',$source);
        update_post_meta($post_id,'_ifp_resource_page_id',absint($_POST['ifp_resource_page_id'] ?? 0));
        update_post_meta($post_id,'_ifp_resource_url',esc_url_raw(wp_unslash($_POST['ifp_resource_url'] ?? '')));
        update_post_meta($post_id,'_ifp_resource_media_id',absint($_POST['ifp_resource_media_id'] ?? 0));
        update_post_meta($post_id,'_ifp_resource_type',sanitize_text_field(wp_unslash($_POST['ifp_resource_type'] ?? 'General')));
        update_post_meta($post_id,'_ifp_resource_button_text',sanitize_text_field(wp_unslash($_POST['ifp_resource_button_text'] ?? 'Open Resource')));
        update_post_meta($post_id,'_ifp_resource_featured',isset($_POST['ifp_resource_featured']) ? '1' : '0');
        update_post_meta($post_id,'_ifp_resource_password_label',isset($_POST['ifp_resource_password_label']) ? '1' : '0');
    }


    public static function save_event($post_id) {
        if (!isset($_POST['ifp_event_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ifp_event_nonce'])),'ifp_save_event')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;
        if (class_exists('IFP_Relationships')) IFP_Relationships::save($post_id);

        $event_date = sanitize_text_field(wp_unslash($_POST['ifp_event_date'] ?? ''));
        $event_end_date = sanitize_text_field(wp_unslash($_POST['ifp_event_end_date'] ?? ''));
        if ($event_date && $event_end_date && $event_end_date < $event_date) {
            $event_end_date = $event_date;
        }

        update_post_meta($post_id,'_ifp_event_date',$event_date);
        update_post_meta($post_id,'_ifp_event_end_date',$event_end_date);
        update_post_meta($post_id,'_ifp_event_time',sanitize_text_field($_POST['ifp_event_time'] ?? ''));
        update_post_meta($post_id,'_ifp_event_end_time',sanitize_text_field($_POST['ifp_event_end_time'] ?? ''));
        update_post_meta($post_id,'_ifp_event_location',sanitize_text_field($_POST['ifp_event_location'] ?? ''));
        update_post_meta($post_id,'_ifp_event_type',sanitize_text_field($_POST['ifp_event_type'] ?? 'General'));
        update_post_meta($post_id,'_ifp_event_link',esc_url_raw($_POST['ifp_event_link'] ?? ''));
        update_post_meta($post_id,'_ifp_event_featured',isset($_POST['ifp_event_featured']) ? '1' : '0');
    }


    public static function save_contact($post_id) {
        if (!isset($_POST['ifp_contact_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ifp_contact_nonce'])),'ifp_save_contact')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;
        if (class_exists('IFP_Relationships')) IFP_Relationships::save($post_id);

        update_post_meta($post_id,'_ifp_contact_role',sanitize_text_field($_POST['ifp_contact_role'] ?? ''));
        update_post_meta($post_id,'_ifp_contact_email',sanitize_email($_POST['ifp_contact_email'] ?? ''));
        update_post_meta($post_id,'_ifp_contact_phone',sanitize_text_field($_POST['ifp_contact_phone'] ?? ''));
        update_post_meta($post_id,'_ifp_contact_category',sanitize_text_field($_POST['ifp_contact_category'] ?? 'Production Team'));
        update_post_meta($post_id,'_ifp_contact_order',intval($_POST['ifp_contact_order'] ?? 10));
        update_post_meta($post_id,'_ifp_contact_active',isset($_POST['ifp_contact_active']) ? '1' : '0');
    }

}
