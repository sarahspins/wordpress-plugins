<?php
if (!defined('ABSPATH')) exit;

class IFP_Admin {
    public static function init() {
        add_action('admin_menu',[__CLASS__,'menu']);
        add_action('admin_menu',[__CLASS__,'trim_submenu'],999);
        add_action('admin_enqueue_scripts',[__CLASS__,'assets']);
        add_action('admin_enqueue_scripts',[__CLASS__,'navigation_assets']);
        add_action('admin_init',[__CLASS__,'register_settings']);
        add_filter('login_redirect',[__CLASS__,'login_redirect'],10,3);
        add_filter('manage_ifp_production_posts_columns',[__CLASS__,'production_columns']);
        add_action('manage_ifp_production_posts_custom_column',[__CLASS__,'production_column_content'],10,2);
        add_filter('post_row_actions',[__CLASS__,'production_row_actions'],10,2);
        add_action('admin_notices',[__CLASS__,'admin_notices']);
        add_action('restrict_manage_posts',[__CLASS__,'production_status_filter']);
        add_action('pre_get_posts',[__CLASS__,'filter_productions_by_status']);
        add_filter('option_page_capability_ifp_homepage_group', [__CLASS__, 'content_settings_capability']);
        add_filter('submenu_file', [__CLASS__, 'highlight_menu_section'], 10, 2);
    }

    public static function assets($hook) {
        global $post_type;
        $styled_pages = [
            'ifp-dashboard',
            'ifp-production-tools',
            'ifp-people-tools',
            'ifp-content-tools',
            'ifp-website-tools',
            'ifp-dash-tools',
            'ifp-production-wizard',
            'ifp-website',
            'ifp-participant-hub',
            'ifp-site-links',
            'ifp-settings',
            'ifp-dash-integration',
            'ifp-programming-alignment',
            'ifp-communications',
        ];

        $load_assets = false;
        foreach ($styled_pages as $page) {
            if (strpos((string) $hook, $page) !== false) {
                $load_assets = true;
                break;
            }
        }
        if (in_array($post_type, ['ifp_production','ifp_division','ifp_group','ifp_sponsor','ifp_notice','ifp_resource','ifp_event','ifp_contact'], true)) {
            $load_assets = true;
        }

        if ($load_assets) {
            wp_enqueue_style('ifp-admin', IFP_URL.'assets/admin.css', [], IFP_VERSION);
            wp_enqueue_script('jquery-ui-sortable');
            wp_enqueue_script('ifp-builder', IFP_URL.'assets/builder.js', ['jquery','jquery-ui-sortable'], IFP_VERSION, true);
            wp_enqueue_script('ifp-admin', IFP_URL.'assets/admin.js', ['jquery'], IFP_VERSION, true);
            self::localize_page_picker();
        }
    }

    public static function localize_page_picker() {
        $pages = get_pages(['sort_column' => 'menu_order,post_title', 'post_status' => ['publish','private','draft']]);
        wp_localize_script('ifp-admin', 'IFPPagePicker', [
            'pages' => array_map(function($page) {
                return [
                    'id' => absint($page->ID),
                    'title' => get_the_title($page->ID),
                    'url' => get_permalink($page->ID),
                    'parent' => absint($page->post_parent),
                ];
            }, $pages),
            'label' => '— Choose a WordPress page —',
        ]);
    }

    public static function navigation_assets() {
        if (!current_user_can('edit_posts')) {
            return;
        }

        wp_enqueue_style(
            'ifp-admin-navigation',
            IFP_URL . 'assets/admin-navigation.css',
            [],
            IFP_VERSION
        );
        wp_enqueue_script(
            'ifp-admin-navigation',
            IFP_URL . 'assets/admin-navigation.js',
            [],
            IFP_VERSION,
            true
        );

        $sections = [
            [
                'slug' => 'ifp-production-tools',
                'items' => [
                    ['label' => 'All Productions', 'url' => admin_url('edit.php?post_type=ifp_production')],
                    ['label' => 'Guided Setup', 'url' => admin_url('admin.php?page=ifp-production-wizard')],
                    ['label' => 'Add Manually', 'url' => admin_url('post-new.php?post_type=ifp_production')],
                ],
            ],
            [
                'slug' => 'ifp-people-tools',
                'items' => [
                    ['label' => 'People', 'url' => admin_url('edit.php?post_type=ifp_participant'), 'capability' => 'edit_ifp_people'],
                    ['label' => 'Groups', 'url' => admin_url('edit.php?post_type=ifp_group')],
                    ['label' => 'Divisions', 'url' => admin_url('edit.php?post_type=ifp_division')],
                    ['label' => 'Discover & Sync Teams', 'url' => admin_url('admin.php?page=ifp-dash-integration'), 'capability' => 'manage_options'],
                ],
            ],
            [
                'slug' => 'ifp-communications',
                'items' => [
                    ['label' => 'Compose Email', 'url' => admin_url('admin.php?page=ifp-communications')],
                    ['label' => 'Communication History', 'url' => admin_url('admin.php?page=ifp-communications&view=history')],
                    ['label' => 'Email Templates', 'url' => admin_url('edit.php?post_type=ifp_email_template')],
                ],
            ],
            [
                'slug' => 'ifp-content-tools',
                'items' => [
                    ['label' => 'Participant Hub', 'url' => admin_url('admin.php?page=ifp-participant-hub')],
                    ['label' => 'Notices', 'url' => admin_url('edit.php?post_type=ifp_notice')],
                    ['label' => 'Resources', 'url' => admin_url('edit.php?post_type=ifp_resource')],
                    ['label' => 'Important Dates', 'url' => admin_url('edit.php?post_type=ifp_event')],
                    ['label' => 'Contacts', 'url' => admin_url('edit.php?post_type=ifp_contact')],
                ],
            ],
            [
                'slug' => 'ifp-website-tools',
                'items' => [
                    ['label' => 'Homepage Builder', 'url' => admin_url('admin.php?page=ifp-website')],
                    ['label' => 'Sponsors', 'url' => admin_url('edit.php?post_type=ifp_sponsor')],
                    ['label' => 'Site Links', 'url' => admin_url('admin.php?page=ifp-site-links'), 'capability' => 'manage_options'],
                ],
            ],
            [
                'slug' => 'ifp-dash-tools',
                'items' => [
                    ['label' => 'Alignment Audit', 'url' => admin_url('admin.php?page=ifp-programming-alignment')],
                    ['label' => 'Team Discovery', 'url' => admin_url('admin.php?page=ifp-dash-integration')],
                ],
            ],
        ];

        foreach ($sections as &$section) {
            $section['items'] = array_values(array_filter($section['items'], function($item) {
                return empty($item['capability']) || current_user_can($item['capability']);
            }));
            foreach ($section['items'] as &$item) {
                unset($item['capability']);
            }
            unset($item);
        }
        unset($section);

        wp_localize_script('ifp-admin-navigation', 'IFPAdminNavigation', [
            'sections' => $sections,
            'expandLabel' => 'Show section shortcuts',
            'collapseLabel' => 'Hide section shortcuts',
            'hiddenNativePages' => [
                'edit.php?post_type=ifp_production',
                'post-new.php?post_type=ifp_production',
                'admin.php?page=ifp-production-wizard',
                'edit.php?post_type=ifp_participant',
                'edit.php?post_type=ifp_group',
                'edit.php?post_type=ifp_division',
                'admin.php?page=ifp-participant-hub',
                'edit.php?post_type=ifp_email_template',
                'edit.php?post_type=ifp_notice',
                'edit.php?post_type=ifp_resource',
                'edit.php?post_type=ifp_event',
                'edit.php?post_type=ifp_contact',
                'edit.php?post_type=ifp_sponsor',
                'admin.php?page=ifp-website',
                'admin.php?page=ifp-site-links',
                'admin.php?page=ifp-dash-integration',
                'admin.php?page=ifp-programming-alignment',
            ],
        ]);
    }

    public static function content_settings_capability($capability = '') {
        return 'edit_posts';
    }


    public static function register_settings() {
        register_setting('ifp_admin_experience_group', 'ifp_admin_experience', [
            'type' => 'array',
            'sanitize_callback' => [__CLASS__, 'sanitize_admin_experience'],
            'default' => [
                'redirect_after_login' => '0',
            ],
        ]);
    }

    public static function sanitize_admin_experience($input) {
        return [
            'redirect_after_login' => !empty($input['redirect_after_login']) ? '1' : '0',
        ];
    }

    public static function admin_experience_settings() {
        return wp_parse_args(
            get_option('ifp_admin_experience', []),
            ['redirect_after_login' => '0']
        );
    }

    public static function login_redirect($redirect_to, $requested_redirect_to, $user) {
        if (!is_object($user) || empty($user->ID)) {
            return $redirect_to;
        }

        $settings = self::admin_experience_settings();
        if (($settings['redirect_after_login'] ?? '0') !== '1') {
            return $redirect_to;
        }

        if (!user_can($user, 'edit_posts')) {
            return $redirect_to;
        }

        // Respect an explicit destination, such as logging in to edit a specific page.
        if (!empty($requested_redirect_to)) {
            return $redirect_to;
        }

        return admin_url('admin.php?page=ifp-dashboard');
    }

    public static function menu() {
        add_menu_page(
            'Ice & Field Productions',
            'Productions',
            'edit_posts',
            'ifp-dashboard',
            [__CLASS__,'dashboard'],
            'dashicons-tickets-alt',
            24
        );

        add_submenu_page('ifp-dashboard','Dashboard','Dashboard','edit_posts','ifp-dashboard',[__CLASS__,'dashboard']);
        add_submenu_page('ifp-dashboard','Production Tools','Productions','edit_posts','ifp-production-tools',[__CLASS__,'production_tools_page']);
        add_submenu_page('ifp-dashboard','People & Groups','People & Groups','edit_posts','ifp-people-tools',[__CLASS__,'people_tools_page']);
        add_submenu_page('ifp-dashboard','Communication Center','Communication','edit_ifp_people','ifp-communications',['IFP_Communications','page']);
        add_submenu_page('ifp-dashboard','Participant Content','Participant Content','edit_posts','ifp-content-tools',[__CLASS__,'content_tools_page']);
        add_submenu_page('ifp-dashboard','Website Tools','Website','edit_posts','ifp-website-tools',[__CLASS__,'website_tools_page']);
        add_submenu_page('ifp-dashboard','Dash Tools','Dash','manage_options','ifp-dash-tools',[__CLASS__,'dash_tools_page']);
        add_submenu_page('ifp-dashboard','Settings','Settings','manage_options','ifp-settings',[__CLASS__,'settings_page']);

        // Register the detailed screens under the Productions parent, then remove
        // their individual menu rows below. This keeps old bookmarks and direct
        // edit links working while the visible menu stays intentionally short.
        add_submenu_page('ifp-dashboard','All Productions','Productions','edit_posts','edit.php?post_type=ifp_production');
        add_submenu_page('ifp-dashboard','New Production Wizard','New Production','edit_posts','ifp-production-wizard',['IFP_Production_Wizard','render_page']);
        add_submenu_page('ifp-dashboard','Manual Production Editor','Add Manually','edit_posts','post-new.php?post_type=ifp_production');
        add_submenu_page('ifp-dashboard','Participant Hub','Participant Hub','edit_posts','ifp-participant-hub',['IFP_Participant_Hub','render_admin_page']);
        add_submenu_page('ifp-dashboard','Email Templates','Email Templates','edit_ifp_people','edit.php?post_type=ifp_email_template');
        add_submenu_page('ifp-dashboard','Participant Notices','Notices','edit_posts','edit.php?post_type=ifp_notice');
        add_submenu_page('ifp-dashboard','Participant Resources','Resources','edit_posts','edit.php?post_type=ifp_resource');
        add_submenu_page('ifp-dashboard','Important Dates','Important Dates','edit_posts','edit.php?post_type=ifp_event');
        add_submenu_page('ifp-dashboard','Production Contacts','Contacts','edit_posts','edit.php?post_type=ifp_contact');
        add_submenu_page('ifp-dashboard','Sponsors','Sponsors','edit_posts','edit.php?post_type=ifp_sponsor');
        add_submenu_page('ifp-dashboard','Website','Homepage Builder','edit_posts','ifp-website',[__CLASS__,'website_page']);
        add_submenu_page('ifp-dashboard','Site Links','Site Links','manage_options','ifp-site-links',['IFP_Links','render_admin_page']);

    }

    public static function trim_submenu() {
        // Run at admin_menu priority 999 so these legacy/custom-post-type menu
        // entries are removed even when WordPress or another component adds
        // them after the main Productions menu is registered.
        $duplicate_top_level_pages = [
            'edit.php?post_type=ifp_production',
            'edit.php?post_type=ifp_sponsor',
            'edit.php?post_type=ifp_notice',
            'edit.php?post_type=ifp_resource',
            'edit.php?post_type=ifp_event',
            'edit.php?post_type=ifp_contact',
        ];

        foreach ($duplicate_top_level_pages as $duplicate_top_level_page) {
            remove_menu_page($duplicate_top_level_page);
        }

        // Detailed screens must remain in WordPress's registered submenu so
        // direct admin.php links pass user_can_access_admin_page(). The admin
        // navigation script hides their native rows visually and exposes them
        // through the grouped, collapsible shortcuts instead.
    }

    public static function highlight_menu_section($submenu_file, $parent_file = '') {
        if ($parent_file !== 'ifp-dashboard') {
            return $submenu_file;
        }

        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        $post_type = isset($_GET['post_type']) ? sanitize_key(wp_unslash($_GET['post_type'])) : '';

        if (!$post_type && !empty($_GET['post'])) {
            $post_type = get_post_type(absint($_GET['post']));
        }

        if (in_array($page, ['ifp-production-wizard'], true) || $post_type === 'ifp_production') {
            return 'ifp-production-tools';
        }

        if (in_array($post_type, ['ifp_participant', 'ifp_group', 'ifp_division'], true)) {
            return 'ifp-people-tools';
        }

        if ($post_type === 'ifp_email_template') {
            return 'ifp-communications';
        }

        if ($page === 'ifp-participant-hub' || in_array($post_type, ['ifp_notice', 'ifp_resource', 'ifp_event', 'ifp_contact'], true)) {
            return 'ifp-content-tools';
        }

        if (in_array($page, ['ifp-website', 'ifp-site-links'], true) || $post_type === 'ifp_sponsor') {
            return 'ifp-website-tools';
        }

        if (in_array($page, ['ifp-dash-integration', 'ifp-programming-alignment'], true)) {
            return 'ifp-dash-tools';
        }

        return $submenu_file;
    }

    private static function current_production() {
        return IFP_Production_Status::current(false);
    }

    private static function checklist($post) {
        if (!$post) return [];
        $id = $post->ID;
        return [
            'Published' => get_post_status($id) === 'publish',
            'Featured image added' => has_post_thumbnail($id),
            'Show logo added' => (bool) get_post_meta($id,'_ifp_show_logo_id',true),
            'Tagline added' => (bool) get_post_meta($id,'_ifp_tagline',true),
            'Opening date added' => (bool) get_post_meta($id,'_ifp_opening_date',true),
            'Ticket link added' => (bool) get_post_meta($id,'_ifp_ticket_url',true),
            'Registration link added' => (bool) get_post_meta($id,'_ifp_registration_url',true),
            'Participant hub linked' => (bool) get_post_meta($id,'_ifp_participant_hub_url',true),
            'Venue information added' => (bool) get_post_meta($id,'_ifp_venue_name',true),
        ];
    }

    private static function countdown_text($post) {
        if (!$post) return 'No current production';
        $opening = get_post_meta($post->ID,'_ifp_opening_date',true);
        if (!$opening) return 'Opening date not set';

        $target = strtotime($opening);
        $now = current_time('timestamp');
        if (!$target) return 'Opening date is invalid';
        if ($target <= $now) return 'Opening night has arrived';

        $days = floor(($target - $now) / DAY_IN_SECONDS);
        return $days . ' day' . ($days === 1 ? '' : 's') . ' until opening night';
    }

    private static function render_tool_hub($title, $description, $sections) {
        ?>
        <div class="wrap ifp-dashboard ifp-tool-hub">
            <div class="ifp-page-header">
                <div>
                    <p class="ifp-kicker">Ice &amp; Field Productions</p>
                    <h1><?php echo esc_html($title); ?></h1>
                    <p class="ifp-lead"><?php echo esc_html($description); ?></p>
                </div>
            </div>

            <?php foreach ($sections as $section): ?>
                <?php
                $cards = array_values(array_filter($section['cards'], function($card) {
                    return empty($card['capability']) || current_user_can($card['capability']);
                }));
                if (!$cards) continue;
                ?>
                <section class="ifp-tool-section">
                    <div class="ifp-tool-section__heading">
                        <h2><?php echo esc_html($section['title']); ?></h2>
                        <?php if (!empty($section['description'])): ?>
                            <p><?php echo esc_html($section['description']); ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="ifp-tool-grid">
                        <?php foreach ($cards as $card): ?>
                            <a class="ifp-tool-card" href="<?php echo esc_url($card['url']); ?>">
                                <span class="ifp-card-icon"><span class="dashicons <?php echo esc_attr($card['icon']); ?>"></span></span>
                                <span class="ifp-tool-card__copy">
                                    <strong><?php echo esc_html($card['title']); ?></strong>
                                    <small><?php echo esc_html($card['description']); ?></small>
                                </span>
                                <span class="ifp-tool-card__action"><?php echo esc_html($card['action']); ?> <span aria-hidden="true">&rarr;</span></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
        <?php
    }

    public static function production_tools_page() {
        self::render_tool_hub(
            'Productions',
            'Create manual productions or review the lifecycle of productions synchronized through Programming.',
            [
                [
                    'title' => 'Create & Manage',
                    'description' => 'Use the guided setup for most productions, or open the full editor when you need every field at once.',
                    'cards' => [
                        [
                            'title' => 'All Productions',
                            'description' => 'Review Current, Upcoming, Completed, Archived, and Draft productions.',
                            'url' => admin_url('edit.php?post_type=ifp_production'),
                            'icon' => 'dashicons-tickets-alt',
                            'action' => 'View productions',
                        ],
                        [
                            'title' => 'Guided Production Setup',
                            'description' => 'Create a production with the step-by-step setup wizard.',
                            'url' => admin_url('admin.php?page=ifp-production-wizard'),
                            'icon' => 'dashicons-welcome-learn-more',
                            'action' => 'Start setup',
                        ],
                        [
                            'title' => 'Manual Production Editor',
                            'description' => 'Open the standard WordPress editor for a new production.',
                            'url' => admin_url('post-new.php?post_type=ifp_production'),
                            'icon' => 'dashicons-edit-page',
                            'action' => 'Add manually',
                        ],
                    ],
                ],
            ]
        );
    }

    public static function people_tools_page() {
        self::render_tool_hub(
            'People & Groups',
            'Manage participant records and the Production structure that connects People, Groups, and Divisions.',
            [
                [
                    'title' => 'Cast & Structure',
                    'description' => 'These records can be maintained manually or synchronized from Dash.',
                    'cards' => [
                        [
                            'title' => 'People',
                            'description' => 'Review contact details, Group assignments, Dash status, and communication history.',
                            'url' => admin_url('edit.php?post_type=ifp_participant'),
                            'icon' => 'dashicons-admin-users',
                            'action' => 'View people',
                            'capability' => 'edit_ifp_people',
                        ],
                        [
                            'title' => 'Groups',
                            'description' => 'Manage casts, teams, and rosters within each Production.',
                            'url' => admin_url('edit.php?post_type=ifp_group'),
                            'icon' => 'dashicons-groups',
                            'action' => 'View groups',
                        ],
                        [
                            'title' => 'Divisions',
                            'description' => 'Organize related Groups into larger Production divisions.',
                            'url' => admin_url('edit.php?post_type=ifp_division'),
                            'icon' => 'dashicons-networking',
                            'action' => 'View divisions',
                        ],
                        [
                            'title' => 'Discover & Sync Teams',
                            'description' => 'Find Dash teams and synchronize their People into existing Production Groups.',
                            'url' => admin_url('admin.php?page=ifp-dash-integration'),
                            'icon' => 'dashicons-update',
                            'action' => 'Open discovery',
                            'capability' => 'manage_options',
                        ],
                    ],
                ],
            ]
        );
    }

    public static function content_tools_page() {
        self::render_tool_hub(
            'Participant Content',
            'Manage the information participants need before and during a Production.',
            [
                [
                    'title' => 'Participant Hub',
                    'description' => 'Open the Hub settings or maintain one of its content collections.',
                    'cards' => [
                        [
                            'title' => 'Participant Hub',
                            'description' => 'Configure the public participant page, headings, layout, and display options.',
                            'url' => admin_url('admin.php?page=ifp-participant-hub'),
                            'icon' => 'dashicons-admin-home',
                            'action' => 'Open hub',
                        ],
                        [
                            'title' => 'Notices',
                            'description' => 'Publish urgent reminders and participant announcements.',
                            'url' => admin_url('edit.php?post_type=ifp_notice'),
                            'icon' => 'dashicons-megaphone',
                            'action' => 'Manage notices',
                        ],
                        [
                            'title' => 'Resources',
                            'description' => 'Share music, documents, downloads, and useful links.',
                            'url' => admin_url('edit.php?post_type=ifp_resource'),
                            'icon' => 'dashicons-media-document',
                            'action' => 'Manage resources',
                        ],
                        [
                            'title' => 'Important Dates',
                            'description' => 'Maintain rehearsals, deadlines, performances, and other key dates.',
                            'url' => admin_url('edit.php?post_type=ifp_event'),
                            'icon' => 'dashicons-calendar-alt',
                            'action' => 'Manage dates',
                        ],
                        [
                            'title' => 'Contacts',
                            'description' => 'Maintain the Production contacts shown to participants.',
                            'url' => admin_url('edit.php?post_type=ifp_contact'),
                            'icon' => 'dashicons-id',
                            'action' => 'Manage contacts',
                        ],
                    ],
                ],
            ]
        );
    }

    public static function website_tools_page() {
        self::render_tool_hub(
            'Website',
            'Control Production content and destinations displayed across the public website.',
            [
                [
                    'title' => 'Website Tools',
                    'description' => 'Manage reusable website content without searching through the longer WordPress menu.',
                    'cards' => [
                        [
                            'title' => 'Homepage Builder',
                            'description' => 'Arrange homepage sections and customize their content and appearance.',
                            'url' => admin_url('admin.php?page=ifp-website'),
                            'icon' => 'dashicons-admin-site-alt3',
                            'action' => 'Open builder',
                        ],
                        [
                            'title' => 'Sponsors',
                            'description' => 'Manage sponsor names, logos, levels, links, and Production assignments.',
                            'url' => admin_url('edit.php?post_type=ifp_sponsor'),
                            'icon' => 'dashicons-heart',
                            'action' => 'Manage sponsors',
                        ],
                        [
                            'title' => 'Site Links',
                            'description' => 'Maintain shared ticket, registration, participant, venue, and contact destinations.',
                            'url' => admin_url('admin.php?page=ifp-site-links'),
                            'icon' => 'dashicons-admin-links',
                            'action' => 'Manage links',
                            'capability' => 'manage_options',
                        ],
                    ],
                ],
            ]
        );
    }

    public static function dash_tools_page() {
        self::render_tool_hub(
            'Dash',
            'Programming is the primary Production sync path. Productions retains targeted participant recovery and alignment diagnostics.',
            [
                [
                    'title' => 'Dash Import & Sync',
                    'description' => 'Use Programming for Production Seasons. Alignment and targeted team tools remain available for diagnostics and participant recovery.',
                    'cards' => [
                        [
                            'title' => 'Primary Programming Sync',
                            'description' => 'Discover, preview, and safely synchronize Production-designated Seasons through Programming, then project their Production companion.',
                            'url' => admin_url('admin.php?page=ifprog-preview'),
                            'icon' => 'dashicons-update',
                            'action' => 'Open primary sync',
                        ],
                        [
                            'title' => 'Programming Alignment Audit',
                            'description' => 'Compare Productions with Programming records and review exact matches, suggestions, missing counterparts, and conflicts without changing data.',
                            'url' => admin_url('admin.php?page=ifp-programming-alignment'),
                            'icon' => 'dashicons-randomize',
                            'action' => 'Run alignment audit',
                        ],
                        [
                            'title' => 'Team Discovery',
                            'description' => 'Find selected teams and synchronize People into Groups you already manage.',
                            'url' => admin_url('admin.php?page=ifp-dash-integration'),
                            'icon' => 'dashicons-search',
                            'action' => 'Discover teams',
                        ],
                    ],
                ],
            ]
        );
    }

    public static function dashboard() {
        $current = self::current_production();
        $checklist = self::checklist($current);
        $complete = $checklist ? count(array_filter($checklist)) : 0;
        $total = count($checklist);
        $percent = $total ? round(($complete / $total) * 100) : 0;

        $notice_count = wp_count_posts('ifp_notice')->publish ?? 0;
        $resource_count = wp_count_posts('ifp_resource')->publish ?? 0;
        $sponsor_count = wp_count_posts('ifp_sponsor')->publish ?? 0;
        $contact_count = wp_count_posts('ifp_contact')->publish ?? 0;
        $production_count = wp_count_posts('ifp_production')->publish ?? 0;
        ?>
        <div class="wrap ifp-dashboard">
            <div class="ifp-page-header">
                <div>
                    <p class="ifp-kicker">Ice & Field Productions</p>
                    <h1>Production Dashboard</h1>
                    <p class="ifp-lead">Manage the current show, participant information, sponsors, and website content from one place.</p>
                </div>
                <div class="ifp-header-actions">
                    <a class="button button-primary button-hero" href="<?php echo esc_url(admin_url('admin.php?page=ifp-production-wizard')); ?>">Add Production</a>
                    <?php if ($current): ?>
                        <a class="button" href="<?php echo esc_url(get_permalink($current)); ?>" target="_blank" rel="noopener">View Production</a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($current): ?>
                <section class="ifp-current-card">
                    <div class="ifp-current-main">
                        <div class="ifp-current-art">
                            <?php
                            if (has_post_thumbnail($current->ID)) {
                                echo get_the_post_thumbnail($current->ID,'medium_large');
                            } else {
                                echo '<div class="ifp-placeholder-art"><span class="dashicons dashicons-format-image"></span><small>Add featured image</small></div>';
                            }
                            ?>
                        </div>
                        <div class="ifp-current-copy">
                            <span class="ifp-status-pill">Current Production</span>
                            <h2><?php echo esc_html($current->post_title); ?></h2>
                            <p class="ifp-countdown-copy"><?php echo esc_html(self::countdown_text($current)); ?></p>
                            <p><?php echo esc_html(get_post_meta($current->ID,'_ifp_tagline',true) ?: 'Add a tagline to introduce this production.'); ?></p>
                            <div class="ifp-actions-row">
                                <a class="button button-primary" href="<?php echo esc_url(get_edit_post_link($current->ID)); ?>">Edit Production</a>
                                <a class="button" href="<?php echo esc_url(admin_url('edit.php?post_type=ifp_notice')); ?>">Participant Notices</a>
                                <a class="button" href="<?php echo esc_url(admin_url('edit.php?post_type=ifp_sponsor')); ?>">Sponsors</a>
                            </div>
                        </div>
                    </div>
                    <div class="ifp-progress-panel">
                        <div class="ifp-progress-heading">
                            <strong>Production setup</strong>
                            <span><?php echo esc_html($percent); ?>%</span>
                        </div>
                        <div class="ifp-progress-track"><span style="width:<?php echo esc_attr($percent); ?>%"></span></div>
                        <ul class="ifp-checklist">
                            <?php foreach ($checklist as $label=>$done): ?>
                                <li class="<?php echo $done ? 'is-done' : 'is-missing'; ?>">
                                    <span class="dashicons <?php echo $done ? 'dashicons-yes-alt' : 'dashicons-marker'; ?>"></span>
                                    <?php echo esc_html($label); ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </section>
            <?php else: ?>
                <section class="ifp-empty-state">
                    <div class="dashicons dashicons-tickets-alt"></div>
                    <h2>Create your first production</h2>
                    <p>Add the show title, artwork, dates, links, participant information, and venue details.</p>
                    <a class="button button-primary button-hero" href="<?php echo esc_url(admin_url('post-new.php?post_type=ifp_production')); ?>">Create Production</a>
                </section>
            <?php endif; ?>

            <div class="ifp-stat-grid">
                <a class="ifp-stat-card" href="<?php echo esc_url(admin_url('edit.php?post_type=ifp_production')); ?>">
                    <span class="dashicons dashicons-tickets-alt"></span>
                    <strong><?php echo esc_html($production_count); ?></strong>
                    <small>Published productions</small>
                </a>
                <a class="ifp-stat-card" href="<?php echo esc_url(admin_url('edit.php?post_type=ifp_notice')); ?>">
                    <span class="dashicons dashicons-megaphone"></span>
                    <strong><?php echo esc_html($notice_count); ?></strong>
                    <small>Participant notices</small>
                </a>
                <a class="ifp-stat-card" href="<?php echo esc_url(admin_url('edit.php?post_type=ifp_resource')); ?>">
                    <span class="dashicons dashicons-media-document"></span>
                    <strong><?php echo esc_html($resource_count); ?></strong>
                    <small>Participant resources</small>
                </a>
                <a class="ifp-stat-card" href="<?php echo esc_url(admin_url('edit.php?post_type=ifp_sponsor')); ?>">
                    <span class="dashicons dashicons-heart"></span>
                    <strong><?php echo esc_html($sponsor_count); ?></strong>
                    <small>Published sponsors</small>
                </a>
            </div>

            <div class="ifp-admin-grid">
                <section class="ifp-admin-card">
                    <div class="ifp-card-icon"><span class="dashicons dashicons-groups"></span></div>
                    <h2>Participant Center</h2>
                    <p>Post urgent reminders, rehearsal information, costume details, music, downloads, and volunteer links.</p>
                    <div class="ifp-actions-row">
                        <a class="button" href="<?php echo esc_url(admin_url('post-new.php?post_type=ifp_notice')); ?>">Add Notice</a>
                        <a class="button" href="<?php echo esc_url(admin_url('post-new.php?post_type=ifp_resource')); ?>">Add Resource</a>
                    </div>
                </section>

                <section class="ifp-admin-card">
                    <div class="ifp-card-icon"><span class="dashicons dashicons-email-alt"></span></div>
                    <h2>Communication Center</h2>
                    <p>Send reviewed HTML emails to Productions, Divisions, Groups, or selected People using reusable templates and local history.</p>
                    <div class="ifp-actions-row">
                        <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=ifp-communications')); ?>">Compose Email</a>
                        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=ifp-communications&view=history')); ?>">View History</a>
                    </div>
                </section>

                <section class="ifp-admin-card">
                    <div class="ifp-card-icon"><span class="dashicons dashicons-groups"></span></div>
                    <h2>Participant Hub</h2>
                    <p>Manage the public participant page, announcements, dates, resources, contacts, and quick links.</p>
                    <div class="ifp-actions-row">
                        <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=ifp-participant-hub')); ?>">Open Participant Hub</a>
                        <a class="button" href="<?php echo esc_url(admin_url('post-new.php?post_type=ifp_notice')); ?>">Add Notice</a>
                    </div>
                </section>

                <section class="ifp-admin-card">
                    <div class="ifp-card-icon"><span class="dashicons dashicons-heart"></span></div>
                    <h2>Sponsor Management</h2>
                    <p>Add sponsor logos and websites once, assign levels, and display them automatically across the site.</p>
                    <div class="ifp-actions-row">
                        <a class="button" href="<?php echo esc_url(admin_url('post-new.php?post_type=ifp_sponsor')); ?>">Add Sponsor</a>
                        <a class="button" href="<?php echo esc_url(admin_url('edit.php?post_type=ifp_sponsor')); ?>">View Sponsors</a>
                    </div>
                </section>

                <section class="ifp-admin-card">
                    <div class="ifp-card-icon"><span class="dashicons dashicons-admin-site-alt3"></span></div>
                    <h2>Homepage Builder</h2>
                    <p>Arrange homepage sections and manage their content and appearance.</p>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=ifp-website')); ?>">Open Homepage Builder</a>
                </section>

                <section class="ifp-admin-card">
                    <div class="ifp-card-icon"><span class="dashicons dashicons-admin-links"></span></div>
                    <h2>Site Links</h2>
                    <p>Maintain tickets, registration, participant, rehearsal, costume, volunteer, sponsor, venue, and contact destinations in one place.</p>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=ifp-site-links')); ?>">Manage Site Links</a>
                </section>

                <section class="ifp-admin-card">
                    <div class="ifp-card-icon"><span class="dashicons dashicons-admin-settings"></span></div>
                    <h2>System Settings</h2>
                    <p>Review setup guidance and prepare the plugin for future homepage-builder and portal releases.</p>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=ifp-settings')); ?>">Open Settings</a>
                </section>
            </div>
        </div>
        <?php
    }

    public static function website_page() {
        $settings = IFP_Homepage::settings();
        $labels = [
            'hero'=>'Hero & Countdown','pathways'=>'Join / Watch / Support',
            'participant'=>'Participant Hub','dates'=>'Important Dates','sponsors'=>'Sponsors',
            'venue'=>'Venue / Plan Your Visit','upcoming'=>'Upcoming Productions','archive'=>'Past Productions'
        ];
        ?>
        <div class="wrap ifp-dashboard">
            <div class="ifp-page-header"><div><p class="ifp-kicker">Website</p><h1>Homepage Builder</h1><p class="ifp-lead">Enable, reorder, and edit production-specific homepage sections. Add <code>[ifp_homepage]</code> to your WordPress homepage once.</p></div></div>
            <form method="post" action="options.php">
                <?php settings_fields('ifp_homepage_group'); ?>
                <div class="ifp-builder-layout">
                    <section class="ifp-admin-card">
                        <h2>Homepage Sections</h2><p>Drag sections into the order you want. Uncheck a section to hide it.</p>
                        <ul id="ifp-sortable-sections" class="ifp-builder-list">
                        <?php foreach($settings['sections'] as $section): ?>
                            <li><span class="dashicons dashicons-menu"></span><input type="hidden" name="ifp_homepage_builder[sections][]" value="<?php echo esc_attr($section); ?>"><label><input type="checkbox" name="ifp_homepage_builder[enabled][<?php echo esc_attr($section); ?>]" value="1" <?php checked(!empty($settings['enabled'][$section])); ?>> <?php echo esc_html($labels[$section]); ?></label></li>
                        <?php endforeach; ?>
                        </ul>
                    </section>
                    <div class="ifp-builder-fields">
                        <?php self::builder_section('Hero Content', [
                            ['hero_eyebrow','Eyebrow Text'],
                            ['hero_ticket_text','Ticket Button Text'],
                            ['hero_register_text','Registration Button Text'],
                            ['hero_volunteer_text','Volunteer Button Text']
                        ],$settings); ?>

                        <?php self::builder_section('Hero Appearance', [
                            ['hero_title_color','Title Color','color'],
                            ['hero_eyebrow_color','Eyebrow Color','color'],
                            ['hero_tagline_color','Tagline Color','color'],
                            ['hero_overlay_color','Overlay Color','color'],
                            ['hero_overlay_opacity','Overlay Opacity','range','0','100'],
                            ['hero_alignment','Content Alignment','select','left|Left,center|Center,right|Right'],
                            ['hero_height','Hero Height','select','small|Small,medium|Medium,large|Large,full|Full Screen'],
                            ['hero_countdown_show_heading','Show countdown heading','checkbox'],
                            ['hero_countdown_eyebrow','Countdown Eyebrow'],
                            ['hero_countdown_heading','Countdown Heading'],
                            ['hero_use_production_countdown_color','Use Production colors for countdown boxes','checkbox'],
                            ['hero_countdown_eyebrow_color','Countdown Eyebrow Color','color'],
                            ['hero_countdown_heading_color','Countdown Heading Color','color'],
                            ['hero_countdown_bg','Countdown Box Background','color'],
                            ['hero_countdown_number_color','Countdown Number Color','color'],
                            ['hero_countdown_label_color','Countdown Label Color','color'],
                            ['hero_primary_bg','Primary Button Background','color'],
                            ['hero_primary_text','Primary Button Text','color'],
                            ['hero_secondary_bg','Secondary Button Background','color'],
                            ['hero_secondary_text','Secondary Button Text','color']
                        ],$settings); ?>

                        <?php self::builder_section('Show Logo', [
                            ['hero_show_logo','Show uploaded show logo','checkbox'],
                            ['hero_logo_width','Logo Width','number'],
                            ['hero_logo_max_height','Logo Maximum Height','number'],
                            ['hero_logo_spacing','Space Below Logo','number']
                        ],$settings); ?>

                        <?php self::builder_section('Homepage Layout', [
                            ['layout_remove_theme_spacing','Remove theme page spacing','checkbox'],
                            ['layout_full_width','Force full-width homepage','checkbox'],
                            ['layout_hide_page_title','Hide WordPress page title','checkbox'],
                            ['layout_hide_breadcrumbs','Hide breadcrumbs','checkbox'],
                            ['layout_flush_header','Place hero flush against header','checkbox'],
                            ['layout_hero_top_offset','Hero Top Offset','number'],
                            ['layout_content_top_padding','Hero Content Top Padding','number'],
                            ['layout_content_bottom_padding','Hero Content Bottom Padding','number']
                        ],$settings); ?>
                        <?php self::builder_section('Pathway Card Content', [
                            ['pathways_heading','Section Heading'],
                            ['join_title','Join Card Title'],['join_text','Join Card Content','wysiwyg'],['join_button','Join Button Text'],
                            ['watch_title','Watch Card Title'],['watch_text','Watch Card Content','wysiwyg'],['watch_button','Watch Button Text'],
                            ['support_title','Support Card Title'],['support_text','Support Card Content','wysiwyg'],['support_button','Support Button Text'],
                            ['support_link','Support Button Destination','page_or_url','support_page_id','support_url']
                        ],$settings); ?>

                        <?php self::builder_section('Pathway Card Appearance', [
                            ['pathway_card_height','Card Minimum Height — Desktop (px)','number'],
                            ['pathway_card_height_mobile','Card Minimum Height — Mobile (px)','number'],
                            ['pathway_corner_radius','Card Corner Radius','number'],
                            ['join_bg_start','Join Background Top','color'],['join_bg_end','Join Background Bottom','color'],
                            ['join_title_color','Join Title Color','color'],['join_text_color','Join Text Color','color'],['join_link_color','Join Link Color','color'],
                            ['watch_bg_start','Watch Background Top','color'],['watch_bg_end','Watch Background Bottom','color'],
                            ['watch_title_color','Watch Title Color','color'],['watch_text_color','Watch Text Color','color'],['watch_link_color','Watch Link Color','color'],
                            ['support_bg_start','Support Background Top','color'],['support_bg_end','Support Background Bottom','color'],
                            ['support_title_color','Support Title Color','color'],['support_text_color','Support Text Color','color'],['support_link_color','Support Link Color','color']
                        ],$settings); ?>
                        <?php self::builder_section('Participant Hub', [
                            ['participant_heading','Heading'],['participant_text','Introduction','wysiwyg']
                        ],$settings); ?>
                        <?php self::builder_section('Sponsor Appearance', [
                            ['sponsor_show_tagline','Show sponsor tagline','checkbox'],
                            ['sponsor_logo_radius','Logo Corner Radius','number'],
                            ['sponsor_logo_border_width','Logo Border Width','number'],
                            ['sponsor_logo_border_color','Logo Border Color','color'],
                            ['sponsor_card_background','Sponsor Card Background','color'],
                            ['sponsor_tagline_color','Sponsor Tagline Color','color']
                        ],$settings); ?>

                        <?php self::builder_section('Homepage Section Layout', [
                            ['section_padding_desktop','Section Spacing — Desktop (px)','number'],
                            ['section_padding_mobile','Section Spacing — Mobile (px)','number'],
                            ['pathways_section_bg','Join / Watch / Support Background','color'],
                            ['participant_section_bg','Participant Preview Background','color'],
                            ['dates_section_bg','Important Dates Background','color'],
                            ['dates_section_text','Important Dates Text Color','color'],
                            ['sponsors_section_bg','Sponsors Background','color'],
                            ['archive_section_bg','Past Productions Background','color']
                        ],$settings); ?>

                        <?php self::builder_section('Section Headings', [
                            ['dates_heading','Important Dates Heading'],['sponsors_heading','Sponsors Heading'],
                            ['upcoming_heading','Upcoming Productions Heading'],['upcoming_limit','Number of Upcoming Productions','number'],
                            ['archive_heading','Past Productions Heading'],['archive_limit','Number of Past Productions','number']
                        ],$settings); ?>
                    </div>
                </div>
                <?php submit_button('Save Homepage'); ?>
            </form>
        </div>
        <?php
    }

    private static function builder_section($title,$fields,$settings) {
        echo '<section class="ifp-admin-card ifp-builder-card"><h2>'.esc_html($title).'</h2>';
        foreach($fields as $field){
            [$key,$label] = $field;
            $type = $field[2] ?? 'text';
            $value = $settings[$key] ?? '';
            echo '<p class="ifp-builder-field ifp-builder-field--'.esc_attr($type).'"><label><strong>'.esc_html($label).'</strong><br>';

            if ($type === 'textarea') {
                echo '<textarea class="widefat" rows="3" name="ifp_homepage_builder['.esc_attr($key).']">'.esc_textarea($value).'</textarea>';
            } elseif ($type === 'page_or_url') {
                $page_key = $field[3] ?? '';
                $url_key = $field[4] ?? '';
                $page_value = absint($settings[$page_key] ?? 0);
                $url_value = $settings[$url_key] ?? '';

                echo '<div class="ifp-link-picker">';
                echo '<label class="ifp-link-option"><span>WordPress Page</span>';
                wp_dropdown_pages([
                    'name' => 'ifp_homepage_builder['.$page_key.']',
                    'selected' => $page_value,
                    'show_option_none' => '— Select a page —',
                    'option_none_value' => '0',
                    'class' => 'widefat'
                ]);
                echo '</label>';

                echo '<div class="ifp-link-or">or</div>';

                echo '<label class="ifp-link-option"><span>Custom URL</span>';
                echo '<input class="widefat" type="url" name="ifp_homepage_builder['.esc_attr($url_key).']" value="'.esc_attr($url_value).'" placeholder="https://">';
                echo '</label>';
                echo '<p class="description">When a WordPress page is selected, it is used instead of the custom URL.</p>';
                echo '</div>';
            } elseif ($type === 'wysiwyg') {
                $editor_id = 'ifp_editor_' . sanitize_key($key);
                wp_editor($value, $editor_id, [
                    'textarea_name' => 'ifp_homepage_builder['.$key.']',
                    'textarea_rows' => 7,
                    'media_buttons' => false,
                    'teeny' => true,
                    'quicktags' => true,
                    'tinymce' => [
                        'toolbar1' => 'bold,italic,bullist,numlist,link,unlink,undo,redo',
                        'toolbar2' => ''
                    ],
                ]);
            } elseif ($type === 'checkbox') {
                echo '<input type="hidden" name="ifp_homepage_builder['.esc_attr($key).']" value="0">';
                echo '<label class="ifp-toggle"><input type="checkbox" name="ifp_homepage_builder['.esc_attr($key).']" value="1" '.checked($value,'1',false).'><span></span></label>';
            } elseif ($type === 'select') {
                $options = explode(',', $field[3] ?? '');
                echo '<select class="widefat" name="ifp_homepage_builder['.esc_attr($key).']">';
                foreach ($options as $option) {
                    [$option_value,$option_label] = array_pad(explode('|',$option,2),2,'');
                    echo '<option value="'.esc_attr($option_value).'" '.selected($value,$option_value,false).'>'.esc_html($option_label).'</option>';
                }
                echo '</select>';
            } elseif ($type === 'range') {
                $min = $field[3] ?? '0';
                $max = $field[4] ?? '100';
                echo '<div class="ifp-range-row"><input type="range" min="'.esc_attr($min).'" max="'.esc_attr($max).'" name="ifp_homepage_builder['.esc_attr($key).']" value="'.esc_attr($value).'" oninput="this.nextElementSibling.value=this.value"><output>'.esc_html($value).'</output><span>%</span></div>';
            } else {
                echo '<input class="widefat" type="'.esc_attr($type).'" name="ifp_homepage_builder['.esc_attr($key).']" value="'.esc_attr($value).'">';
            }
            echo '</label></p>';
        }
        echo '</section>';
    }

    public static function settings_page() {
        $admin_settings = self::admin_experience_settings();
        ?>
        <div class="wrap ifp-dashboard">
            <div class="ifp-page-header">
                <div>
                    <p class="ifp-kicker">Settings</p>
                    <h1>Production System Settings</h1>
                    <p class="ifp-lead">Configure the administrative experience and review the recommended site setup.</p>
                </div>
            </div>

            <div class="ifp-settings-layout">
                <form method="post" action="options.php">
                    <?php settings_fields('ifp_admin_experience_group'); ?>

                    <section class="ifp-admin-card ifp-settings-card">
                        <div class="ifp-settings-heading">
                            <div class="ifp-card-icon"><span class="dashicons dashicons-admin-users"></span></div>
                            <div>
                                <h2>Admin Experience</h2>
                                <p>Choose where production staff land after signing into WordPress.</p>
                            </div>
                        </div>

                        <div class="ifp-setting-row">
                            <div>
                                <strong>Open the Productions Dashboard after login</strong>
                                <p>When enabled, users who can manage production content are taken to the Productions Dashboard instead of the default WordPress Dashboard.</p>
                                <p class="description">Explicit destinations are still respected. For example, signing in to edit a specific page will continue to open that page.</p>
                            </div>
                            <label class="ifp-toggle">
                                <input type="hidden" name="ifp_admin_experience[redirect_after_login]" value="0">
                                <input
                                    type="checkbox"
                                    name="ifp_admin_experience[redirect_after_login]"
                                    value="1"
                                    <?php checked($admin_settings['redirect_after_login'], '1'); ?>
                                >
                                <span></span>
                            </label>
                        </div>

                        <?php submit_button('Save Admin Experience'); ?>
                    </section>
                </form>

                <section class="ifp-admin-card ifp-settings-card">
                    <div class="ifp-settings-heading">
                        <div class="ifp-card-icon"><span class="dashicons dashicons-yes-alt"></span></div>
                        <div>
                            <h2>Recommended Setup</h2>
                            <p>These steps connect the production content to the public website.</p>
                        </div>
                    </div>

                    <ol class="ifp-steps">
                        <li>Mark one production as the current production.</li>
                        <li>Add its featured image, show logo, opening date, tagline, ticket link, and registration link.</li>
                        <li>Configure shared destinations under <strong>Productions → Site Links</strong>.</li>
                        <li>Add <code>[ifp_homepage]</code> to the website homepage.</li>
                        <li>Add <code>[ifp_participant_hub]</code> to the Participant Hub page.</li>
                        <li>Add notices, resources, Important Dates, contacts, and sponsors as needed.</li>
                    </ol>
                </section>
            </div>
        </div>
        <?php
    }

    public static function production_columns($columns) {
        $new = [];
        foreach ($columns as $key=>$value) {
            $new[$key] = $value;
            if ($key === 'title') {
                $new['ifp_current'] = 'Status';
                $new['ifp_dates'] = 'Show Dates';
                $new['ifp_programming'] = 'Programming';
                $new['ifp_setup'] = 'Setup';
            }
        }
        return $new;
    }

    public static function production_column_content($column,$post_id) {
        if ($column === 'ifp_current') {
            $status = IFP_Production_Status::get($post_id);
            $labels = ['draft'=>'📝 Draft','upcoming'=>'📅 Upcoming','current'=>'⭐ Current','completed'=>'✅ Completed','archived'=>'🗄️ Archived'];
            echo '<span class="ifp-status-badge ifp-status-'.esc_attr($status).'">'.esc_html($labels[$status] ?? ucfirst($status)).'</span>';
        }
        if ($column === 'ifp_dates') {
            $open = get_post_meta($post_id,'_ifp_opening_date',true);
            $close = get_post_meta($post_id,'_ifp_closing_date',true);
            if (!$open) {
                echo '<span class="ifp-list-muted">Not set</span>';
            } else {
                echo esc_html(wp_date(get_option('date_format'), strtotime($open)));
                if ($close) echo '<br><small>to '.esc_html(wp_date(get_option('date_format'), strtotime($close))).'</small>';
            }
        }
        if ($column === 'ifp_programming') {
            $season_id = absint(get_post_meta($post_id, '_ifp_programming_season_id', true));
            if ($season_id && get_post_type($season_id) === 'ifprog_season') {
                $url = get_edit_post_link($season_id);
                echo $url
                    ? '<a href="'.esc_url($url).'">'.esc_html(get_the_title($season_id)).'</a>'
                    : esc_html(get_the_title($season_id));
            } else {
                echo '<span class="ifp-list-muted">Not linked</span>';
            }
        }
        if ($column === 'ifp_setup') {
            $post = get_post($post_id);
            $checklist = self::checklist($post);
            $complete = count(array_filter($checklist));
            $total = count($checklist);
            echo '<strong>'.esc_html($complete).'/'.esc_html($total).'</strong><br><small>items complete</small>';
        }
    }

    public static function production_row_actions($actions,$post) {
        if ($post->post_type !== 'ifp_production') return $actions;
        $status = IFP_Production_Status::get($post->ID);
        if (!in_array($status, ['completed','archived'], true) && current_user_can('edit_post', $post->ID)) {
            $actions['ifp_guided_setup'] = '<a href="' . esc_url(add_query_arg(
                ['page' => 'ifp-production-wizard', 'production_id' => $post->ID],
                admin_url('admin.php')
            )) . '">Guided Setup</a>';
        }
        if ($status === 'current') {
            $actions = ['ifp_current_label'=>'<span class="ifp-row-current">Current production</span>'] + $actions;
        }
        return $actions;
    }

    public static function production_status_filter() {
        global $typenow;
        if ($typenow !== 'ifp_production') return;
        $selected = sanitize_key($_GET['ifp_production_status'] ?? '');
        $options = ['draft'=>'Draft','upcoming'=>'Upcoming','current'=>'Current','completed'=>'Completed','archived'=>'Archived'];
        echo '<select name="ifp_production_status"><option value="">All production statuses</option>';
        foreach ($options as $value=>$label) echo '<option value="'.esc_attr($value).'" '.selected($selected,$value,false).'>'.esc_html($label).'</option>';
        echo '</select>';
    }

    public static function filter_productions_by_status($query) {
        if (!is_admin() || !$query->is_main_query() || $query->get('post_type') !== 'ifp_production') return;
        $status = sanitize_key($_GET['ifp_production_status'] ?? '');
        if (in_array($status,['draft','upcoming','current','completed','archived'],true)) {
            $query->set('meta_key','_ifp_production_status');
            $query->set('meta_value',$status);
        }
    }

    public static function admin_notices() {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'ifp_production') return;

        $current_ids = get_posts(['post_type'=>'ifp_production','post_status'=>'any','posts_per_page'=>-1,'fields'=>'ids','meta_key'=>'_ifp_production_status','meta_value'=>'current']);
        if (count($current_ids) > 1) {
            echo '<div class="notice notice-warning"><p><strong>Multiple current productions:</strong> The website is designed to feature one Current production. Please change the others to Upcoming, Completed, or Archived.</p></div>';
        }

        if ($screen->base !== 'post') return;
        global $post;
        if (!$post || $post->post_status === 'auto-draft') return;
        if (!has_post_thumbnail($post->ID)) {
            echo '<div class="notice notice-info"><p><strong>Production artwork:</strong> Add the show poster or hero artwork using the Featured Image panel in the editor sidebar.</p></div>';
        }
    }
}
