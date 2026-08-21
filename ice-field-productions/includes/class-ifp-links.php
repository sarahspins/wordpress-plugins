<?php
if (!defined('ABSPATH')) exit;

class IFP_Links {
    const OPTION = 'ifp_site_links';

    public static function init() {
        add_action('admin_init', [__CLASS__, 'register']);
    }

    public static function definitions() {
        return [
            'tickets' => [
                'label' => 'Tickets',
                'description' => 'Used by Buy Tickets buttons and audience pathways.'
            ],
            'registration' => [
                'label' => 'Registration',
                'description' => 'Used by Join the Cast and registration links.'
            ],
            'participant_hub' => [
                'label' => 'Participant Hub',
                'description' => 'Main parent and skater information page.'
            ],
            'rehearsals' => [
                'label' => 'Rehearsal Schedule',
                'description' => 'Current rehearsal schedule or calendar.'
            ],
            'costumes' => [
                'label' => 'Costume Information',
                'description' => 'Costume measurements, fittings, delivery, and care.'
            ],
            'volunteer' => [
                'label' => 'Volunteer',
                'description' => 'Volunteer signup or information page.'
            ],
            'program_ads' => [
                'label' => 'Program Advertising',
                'description' => 'Program ad sales or submission information.'
            ],
            'sponsors' => [
                'label' => 'Sponsor Information',
                'description' => 'Sponsorship packages or community-support page.'
            ],
            'venue' => [
                'label' => 'Venue & Parking',
                'description' => 'Audience arrival, venue, and parking information.'
            ],
            'contact' => [
                'label' => 'Contact',
                'description' => 'Production contact page or external form.'
            ],
        ];
    }

    public static function register() {
        register_setting('ifp_site_links_group', self::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [__CLASS__, 'sanitize'],
            'default' => []
        ]);
    }

    public static function sanitize($input) {
        $output = [];
        foreach (self::definitions() as $key => $definition) {
            $row = isset($input[$key]) && is_array($input[$key]) ? $input[$key] : [];
            $output[$key] = [
                'page_id' => absint($row['page_id'] ?? 0),
                'url' => esc_url_raw($row['url'] ?? ''),
            ];
        }
        return $output;
    }

    public static function all() {
        $stored = get_option(self::OPTION, []);
        return is_array($stored) ? $stored : [];
    }

    public static function get($key, $fallback = '') {
        $all = self::all();
        $row = isset($all[$key]) && is_array($all[$key]) ? $all[$key] : [];

        $page_id = absint($row['page_id'] ?? 0);
        if ($page_id) {
            $permalink = get_permalink($page_id);
            if ($permalink) return $permalink;
        }

        $url = esc_url_raw($row['url'] ?? '');
        return $url ?: $fallback;
    }

    public static function is_configured($key) {
        return (bool) self::get($key);
    }

    public static function render_admin_page() {
        if (!current_user_can('manage_options')) return;

        $saved = self::all();
        ?>
        <div class="wrap ifp-dashboard">
            <div class="ifp-page-header">
                <div>
                    <p class="ifp-kicker">Website</p>
                    <h1>Site Links</h1>
                    <p class="ifp-lead">Maintain common destinations once. Production-specific links still take priority, and these links are used as automatic fallbacks.</p>
                </div>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields('ifp_site_links_group'); ?>

                <div class="ifp-link-manager">
                    <?php foreach (self::definitions() as $key => $definition):
                        $row = isset($saved[$key]) && is_array($saved[$key]) ? $saved[$key] : [];
                        $page_id = absint($row['page_id'] ?? 0);
                        $url = $row['url'] ?? '';
                    ?>
                        <section class="ifp-admin-card ifp-link-manager__card">
                            <h2><?php echo esc_html($definition['label']); ?></h2>
                            <p><?php echo esc_html($definition['description']); ?></p>

                            <label class="ifp-link-option">
                                <span>WordPress Page</span>
                                <?php
                                wp_dropdown_pages([
                                    'name' => self::OPTION.'['.$key.'][page_id]',
                                    'selected' => $page_id,
                                    'show_option_none' => '— Select a page —',
                                    'option_none_value' => '0',
                                    'class' => 'widefat'
                                ]);
                                ?>
                            </label>

                            <div class="ifp-link-or">or</div>

                            <label class="ifp-link-option">
                                <span>Custom URL</span>
                                <input
                                    class="widefat"
                                    type="url"
                                    name="<?php echo esc_attr(self::OPTION.'['.$key.'][url]'); ?>"
                                    value="<?php echo esc_attr($url); ?>"
                                    placeholder="https://"
                                >
                            </label>

                            <?php if (self::get($key)): ?>
                                <p class="ifp-link-status ifp-link-status--ready">
                                    <span class="dashicons dashicons-yes-alt"></span>
                                    <a href="<?php echo esc_url(self::get($key)); ?>" target="_blank" rel="noopener">Open current destination</a>
                                </p>
                            <?php else: ?>
                                <p class="ifp-link-status ifp-link-status--missing">
                                    <span class="dashicons dashicons-marker"></span>
                                    No destination configured
                                </p>
                            <?php endif; ?>
                        </section>
                    <?php endforeach; ?>
                </div>

                <?php submit_button('Save Site Links'); ?>
            </form>
        </div>
        <?php
    }
}
