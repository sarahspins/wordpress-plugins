<?php
if (!defined('ABSPATH')) exit;

$production_id = get_the_ID();
$lifecycle = IFP_Production_Status::get($production_id);
$is_upcoming = $lifecycle === 'upcoming';
$is_past = in_array($lifecycle, ['completed','archived'], true);

$tagline = IFP_Production_Pages::meta($production_id, '_ifp_tagline');
$description = trim((string) get_post_field('post_content', $production_id));
$description_html = $description !== '' ? apply_filters('the_content', $description) : '';
$season = IFP_Production_Pages::meta($production_id, '_ifp_season');
$year = IFP_Production_Pages::meta($production_id, '_ifp_year');
$accent = sanitize_hex_color(IFP_Production_Pages::meta($production_id, '_ifp_accent_color', '#ff8033')) ?: '#ff8033';
$secondary = sanitize_hex_color(IFP_Production_Pages::meta($production_id, '_ifp_secondary_color', '#003b5c')) ?: '#003b5c';
$countdown_text = sanitize_hex_color(IFP_Production_Pages::meta($production_id, '_ifp_countdown_text_color', '#ffffff')) ?: '#ffffff';
$overlay = sanitize_hex_color(IFP_Production_Pages::meta($production_id, '_ifp_overlay_color')) ?: $secondary;
$hero_overlay_value = IFP_Production_Pages::meta($production_id, '_ifp_hero_overlay_opacity');
$panel_overlay_value = IFP_Production_Pages::meta($production_id, '_ifp_panel_overlay_opacity');
$hero_overlay_opacity = max(0, min(100, $hero_overlay_value === '' ? 92 : absint($hero_overlay_value))) / 100;
$panel_overlay_opacity = max(0, min(100, $panel_overlay_value === '' ? 78 : absint($panel_overlay_value))) / 100;
$opening = IFP_Production_Pages::meta($production_id, '_ifp_opening_date');
$date_range = IFP_Production_Pages::formatted_range($production_id);
$registration_open = IFP_Production_Pages::meta($production_id, '_ifp_registration_open');
$registration_close = IFP_Production_Pages::meta($production_id, '_ifp_registration_close');

$ticket_state = IFP_Production_Pages::ticket_state($production_id);
$ticket_url = $ticket_state['status'] === 'active' ? $ticket_state['url'] : '';
$registration_url = IFP_Production_Pages::meta($production_id, '_ifp_registration_url');
$registration_state = IFP_Production_Pages::registration_state($production_id);
$registration_is_open = $registration_state['status'] === 'open';
$volunteer_url = IFP_Production_Pages::meta($production_id, '_ifp_volunteer_url');
$trailer_url = IFP_Production_Pages::meta($production_id, '_ifp_trailer_url');
$program_url = IFP_Production_Pages::meta($production_id, '_ifp_program_pdf');
$venue_name = IFP_Production_Pages::meta($production_id, '_ifp_venue_name');
$venue_address = IFP_Production_Pages::meta($production_id, '_ifp_venue_address');
$parking_notes = IFP_Production_Pages::meta($production_id, '_ifp_parking_notes');
$venue_image_id = absint(IFP_Production_Pages::meta($production_id, '_ifp_venue_image_id'));

$featured = get_the_post_thumbnail_url($production_id, 'full');
$logo_id = absint(IFP_Production_Pages::meta($production_id, '_ifp_show_logo_id'));
$logo_url = $logo_id ? wp_get_attachment_image_url($logo_id, 'full') : '';
$now = current_time('timestamp');
$opening_timestamp = $opening ? strtotime($opening) : 0;
$registration_open_timestamp = $registration_open ? strtotime($registration_open) : 0;
$registration_close_timestamp = $registration_close ? strtotime($registration_close) : 0;
$countdown_target = $opening;
$countdown_timestamp = $opening_timestamp;
$countdown_label = 'Opening Night';

if ($registration_open_timestamp && $registration_open_timestamp > $now) {
    $countdown_target = $registration_open;
    $countdown_timestamp = $registration_open_timestamp;
    $countdown_label = 'Registration Opens';
} elseif ($registration_close_timestamp && $registration_close_timestamp > $now) {
    $countdown_target = $registration_close;
    $countdown_timestamp = $registration_close_timestamp;
    $countdown_label = 'Registration Closes';
}

$show_countdown = $is_upcoming && $countdown_timestamp && $countdown_timestamp > $now;

$status_label = $is_upcoming ? 'Upcoming Production' : ($is_past ? 'Past Production' : 'Ice & Field Production');
$identity_label = trim($status_label . ($season ? ' · ' . $season : '') . ($year ? ' ' . $year : ''));
$section_visible = static function($key) use ($production_id) {
    $value = get_post_meta($production_id, '_ifp_public_show_' . $key, true);
    return $value === '' || $value === '1';
};

$quick_links = [];
if ($is_upcoming && $ticket_url) {
    $quick_links[] = ['label' => 'Buy Tickets', 'url' => $ticket_url];
} elseif ($is_upcoming && $ticket_state['status'] === 'pending') {
    $quick_links[] = [
        'label' => 'Tickets Available ' . $ticket_state['available_label'],
        'url' => '',
        'disabled' => true,
    ];
}
if ($is_upcoming && $registration_is_open && $registration_url) {
    $quick_links[] = ['label' => 'Registration', 'url' => $registration_url];
} elseif ($is_upcoming && $registration_state['status'] === 'pending') {
    $quick_links[] = [
        'label' => $registration_state['open_label']
            ? 'Registration Opens ' . $registration_state['open_label']
            : 'Registration Opens Soon',
        'url' => '',
        'disabled' => true,
    ];
}
if ($is_upcoming && $volunteer_url) $quick_links[] = ['label' => 'Volunteer', 'url' => $volunteer_url];
if ($program_url) $quick_links[] = ['label' => 'Digital Program', 'url' => $program_url];
if ($trailer_url) $quick_links[] = ['label' => 'Watch Trailer', 'url' => $trailer_url];

$overlay_hex = ltrim($overlay, '#');
if (strlen($overlay_hex) === 3) {
    $overlay_hex = $overlay_hex[0] . $overlay_hex[0]
        . $overlay_hex[1] . $overlay_hex[1]
        . $overlay_hex[2] . $overlay_hex[2];
}
$overlay_rgb = implode(',', [
    hexdec(substr($overlay_hex, 0, 2)),
    hexdec(substr($overlay_hex, 2, 2)),
    hexdec(substr($overlay_hex, 4, 2)),
]);

$style = implode(';', [
    '--ifp-production-accent:' . esc_attr($accent),
    '--ifp-production-secondary:' . esc_attr($secondary),
    '--ifp-production-countdown-text:' . esc_attr($countdown_text),
    '--ifp-production-overlay-rgb:' . esc_attr($overlay_rgb),
    '--ifp-production-hero-overlay-strong:' . esc_attr($hero_overlay_opacity),
    '--ifp-production-hero-overlay-medium:' . esc_attr($hero_overlay_opacity * .76),
    '--ifp-production-hero-overlay-light:' . esc_attr($hero_overlay_opacity * .37),
    '--ifp-production-panel-overlay-opacity:' . esc_attr($panel_overlay_opacity),
    '--ifp-production-image:' . ($featured ? 'url(' . esc_url($featured) . ')' : 'none'),
]);
?>
<main class="ifp-production-hub ifp-production-showcase ifp-production-showcase--<?php echo esc_attr($lifecycle); ?>" style="<?php echo esc_attr($style); ?>">
    <div class="ifp-production-hub__shell">
        <section class="ifp-production-hub__hero<?php echo $featured ? ' has-background' : ''; ?>">
            <div class="ifp-production-hub__overlay"></div>
            <div class="ifp-production-hub__hero-content">
                <div class="ifp-production-hub__identity">
                    <div class="ifp-production-hub__identity-grid">
                        <div class="ifp-production-hub__copy">
                            <span class="ifp-production-hub__eyebrow"><?php echo esc_html($identity_label); ?></span>
                            <h1><?php the_title(); ?></h1>
                            <?php if ($tagline): ?><p class="ifp-production-hub__tagline"><?php echo esc_html($tagline); ?></p><?php endif; ?>
                            <?php if ($date_range): ?><p class="ifp-production-hub__date"><?php echo esc_html($date_range); ?></p><?php endif; ?>
                            <?php if ($description_html): ?><div class="ifp-production-hub__hero-description"><?php echo wp_kses_post($description_html); ?></div><?php endif; ?>
                        </div>
                        <?php if ($logo_url): ?>
                            <div class="ifp-production-hub__logo-wrap">
                                <img class="ifp-production-hub__logo" src="<?php echo esc_url($logo_url); ?>" alt="<?php echo esc_attr(get_the_title()); ?>">
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($show_countdown): ?>
                    <div class="ifp-production-hub__status-grid">
                        <article class="ifp-production-hub__status-card ifp-production-hub__status-card--countdown">
                            <span class="ifp-production-hub__status-eyebrow"><?php echo esc_html($countdown_label); ?></span>
                            <?php echo IFP_Shortcodes::countdown([
                                'production_id' => $production_id,
                                'target' => $countdown_target,
                            ]); ?>
                        </article>
                        <article class="ifp-production-hub__status-card">
                            <span class="ifp-production-hub__status-eyebrow">Performance Details</span>
                            <h2><?php the_title(); ?></h2>
                            <?php if ($date_range): ?><p><?php echo esc_html($date_range); ?></p><?php endif; ?>
                            <?php if ($venue_name): ?><p><?php echo esc_html($venue_name); ?></p><?php endif; ?>
                        </article>
                    </div>
                <?php endif; ?>

                <?php if ($quick_links): ?>
                    <nav class="ifp-production-hub__quick-links" aria-label="Production links">
                        <?php $primary_used = false; ?>
                        <?php foreach ($quick_links as $link):
                            $disabled = !empty($link['disabled']);
                            $primary = !$disabled && !$primary_used;
                            if ($primary) $primary_used = true;
                        ?>
                            <?php if ($disabled): ?>
                                <span class="is-disabled" aria-disabled="true"><?php echo esc_html($link['label']); ?></span>
                            <?php else: ?>
                                <a class="<?php echo $primary ? 'is-primary' : ''; ?>" href="<?php echo esc_url($link['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($link['label']); ?></a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </nav>
                <?php endif; ?>
            </div>
        </section>

        <?php
        $registration_options = $registration_is_open
            ? IFP_Shortcodes::registration([
                'production_id' => $production_id,
                'show_heading' => '0',
                'show_status' => '0',
            ])
            : '';
        if ($section_visible('groups') && $registration_options !== ''):
        ?>
            <section class="ifp-production-hub__section ifp-production-hub__section--registration">
                <div class="ifp-production-hub__section-heading"><span>Join the Production</span><h2>Registration Options</h2></div>
                <?php echo $registration_options; ?>
            </section>
        <?php endif; ?>

        <?php
        $events = IFP_Production_Pages::event_query($production_id);
        if ($section_visible('dates') && $events->have_posts()):
        ?>
            <section class="ifp-production-hub__section">
                <div class="ifp-production-hub__section-heading"><span><?php echo $is_past ? 'From the Production' : 'Mark Your Calendar'; ?></span><h2>Important Dates</h2></div>
                <div class="ifp-production-hub__cards">
                    <?php while ($events->have_posts()):
                        $events->the_post();
                        $event_id = get_the_ID();
                        $date = get_post_meta($event_id, '_ifp_event_date', true);
                        if (!$date) continue;
                        $date_range = IFP_Production_Pages::event_date_range($event_id);
                        $time = get_post_meta($event_id, '_ifp_event_time', true);
                        $end_time = get_post_meta($event_id, '_ifp_event_end_time', true);
                        $location = get_post_meta($event_id, '_ifp_event_location', true);
                        $type = get_post_meta($event_id, '_ifp_event_type', true) ?: 'Production Date';
                        $link = get_post_meta($event_id, '_ifp_event_link', true);
                    ?>
                        <article class="ifp-production-hub__card">
                            <span class="ifp-production-hub__card-eyebrow"><?php echo esc_html($type); ?></span>
                            <strong class="ifp-production-hub__card-date"><?php echo esc_html($date_range); ?></strong>
                            <h3><?php echo esc_html(get_the_title()); ?></h3>
                            <?php if ($time):
                                $time_text = wp_date(get_option('time_format'), strtotime($time));
                                if ($end_time) $time_text .= '–' . wp_date(get_option('time_format'), strtotime($end_time));
                            ?>
                                <p><?php echo esc_html($time_text); ?></p>
                            <?php endif; ?>
                            <?php if ($location): ?><p><?php echo esc_html($location); ?></p><?php endif; ?>
                            <?php if ($link): ?><a href="<?php echo esc_url($link); ?>">More information →</a><?php endif; ?>
                        </article>
                    <?php endwhile; ?>
                </div>
            </section>
        <?php
            wp_reset_postdata();
        endif;
        ?>

        <?php if ($section_visible('groups') && $registration_options === '' && IFP_Production_Pages::has_public_groups($production_id)): ?>
            <section class="ifp-production-hub__section">
                <div class="ifp-production-hub__section-heading"><span>Cast &amp; Performers</span><h2>Performance Groups</h2></div>
                <?php echo do_shortcode('[ifp_groups production_id="' . absint($production_id) . '" visibility="public" show_registration="0"]'); ?>
            </section>
        <?php endif; ?>

        <?php
        $show_venue = $section_visible('venue') && ($venue_name || $venue_address || $parking_notes || $venue_image_id);
        $show_media = $section_visible('media') && ($trailer_url || $program_url);
        if ($show_venue || $show_media):
        ?>
            <section class="ifp-production-hub__section">
                <div class="ifp-production-hub__section-heading"><span>Production Details</span><h2><?php echo $is_past ? 'Show Information' : 'Plan Your Visit'; ?></h2></div>
                <div class="ifp-production-hub__details-grid">
                    <?php if ($show_venue): ?>
                        <article class="ifp-production-hub__content-card ifp-production-hub__venue-card<?php echo $venue_image_id ? ' has-image' : ''; ?>">
                            <div class="ifp-production-hub__venue-copy">
                                <span class="ifp-production-hub__card-eyebrow">Venue</span>
                                <?php if ($venue_name): ?><h3><?php echo esc_html($venue_name); ?></h3><?php endif; ?>
                                <?php if ($venue_address): ?><p><?php echo esc_html($venue_address); ?></p><?php endif; ?>
                                <?php if ($parking_notes): ?><div><?php echo wp_kses_post(wpautop($parking_notes)); ?></div><?php endif; ?>
                            </div>
                            <?php if ($venue_image_id): ?>
                                <div class="ifp-production-hub__venue-media">
                                    <?php echo wp_get_attachment_image($venue_image_id, 'full', false, ['class' => 'ifp-production-hub__venue-image']); ?>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endif; ?>
                    <?php if ($show_media): ?>
                        <article class="ifp-production-hub__content-card">
                            <span class="ifp-production-hub__card-eyebrow">Show Media</span><h3>Watch &amp; Remember</h3>
                            <div class="ifp-production-hub__inline-links">
                                <?php if ($trailer_url): ?><a href="<?php echo esc_url($trailer_url); ?>" target="_blank" rel="noopener noreferrer">Watch Trailer</a><?php endif; ?>
                                <?php if ($program_url): ?><a href="<?php echo esc_url($program_url); ?>" target="_blank" rel="noopener noreferrer">View Program</a><?php endif; ?>
                            </div>
                        </article>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($section_visible('sponsors') && IFP_Production_Pages::has_sponsors($production_id)): ?>
            <section class="ifp-production-hub__section">
                <div class="ifp-production-hub__section-heading"><span>Community Support</span><h2>Our Sponsors</h2></div>
                <div class="ifp-production-hub__sponsors"><?php echo do_shortcode('[ifp_sponsors production_id="' . absint($production_id) . '" group_by_level="1" show_tagline="1"]'); ?></div>
            </section>
        <?php endif; ?>
    </div>
</main>
