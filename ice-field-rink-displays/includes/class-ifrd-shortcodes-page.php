<?php
/**
 * Displays menu shortcode reference.
 */
class IFRD_Shortcodes_Page {
    public function __construct() {
        add_action('admin_menu', array($this, 'menu'), 40);
    }

    public function menu() {
        add_submenu_page(
            'ifrd-schedule-display',
            'Display Shortcodes',
            'Shortcodes',
            'manage_options',
            'ifrd-display-shortcodes',
            array($this, 'page')
        );
    }

    private function shortcode_example($shortcode) {
        return '<pre class="ifrd-shortcode-example"><code>' . esc_html($shortcode) . '</code></pre>';
    }

    public function page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap ifrd-shortcodes-page">
            <h1>Display Shortcodes</h1>
            <p class="description">Add these to a WordPress Shortcode block, page, post, or compatible page-builder shortcode element.</p>

            <style>
                .ifrd-shortcodes-page{max-width:1120px}.ifrd-shortcodes-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(330px,1fr));gap:18px;margin-top:20px}.ifrd-shortcodes-card{box-sizing:border-box;margin:0;padding:20px;border:1px solid #dcdcde;border-radius:10px;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.04)}.ifrd-shortcodes-card h2{margin-top:0}.ifrd-shortcode-example{overflow:auto;margin:12px 0;padding:12px 14px;border-radius:6px;background:#f0f0f1;white-space:pre-wrap;word-break:break-word}.ifrd-shortcodes-card table{width:100%;border-collapse:collapse}.ifrd-shortcodes-card th,.ifrd-shortcodes-card td{padding:8px;border-bottom:1px solid #e8e8e8;text-align:left;vertical-align:top}.ifrd-shortcodes-card th{font-weight:700}.ifrd-shortcodes-card ul{margin-bottom:0}.ifrd-shortcodes-wide{grid-column:1/-1}.ifrd-shortcodes-note{margin:18px 0;padding:12px 14px;border-left:4px solid #2271b1;background:#fff}
            </style>

            <div class="ifrd-shortcodes-note">
                <strong>Connection requirement:</strong> Ice &amp; Field Dash Connector v<?php echo esc_html(IFRD_Dash_Connector::MIN_VERSION); ?> or newer must be active and configured. Display colors, rink IDs, media, cache timing, and other global choices come from the Schedule Display or Participants Display settings pages rather than shortcode attributes.
            </div>

            <div class="ifrd-shortcodes-grid">
                <section class="ifrd-shortcodes-card">
                    <h2>TV Schedule Display</h2>
                    <p>The full-screen, two-rink schedule designed for a lobby or TV display.</p>
                    <?php echo $this->shortcode_example('[rink_schedule_display]'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <h3>Attributes</h3>
                    <p>None. Configure its headings, rink IDs, colors, logo, banner, timezone, visible event count, and refresh interval under <a href="<?php echo esc_url(admin_url('admin.php?page=ifrd-schedule-display')); ?>">Displays → Schedule Display</a>.</p>
                    <p><strong>Placement:</strong> A blank or full-width page template normally works best because this display is designed to fill the browser viewport.</p>
                    <p><strong>Remote video update:</strong> Saving a different schedule banner asks open TV schedule pages to reload. The same settings page also includes an <em>Update Video Now</em> button for forcing the reload without changing the selected media. Screens check for the request every 60 seconds.</p>
                </section>

                <section class="ifrd-shortcodes-card">
                    <h2>Participants Display</h2>
                    <p>Shows the current and next event for one rink, including registrants for configured qualifying sessions.</p>
                    <?php echo $this->shortcode_example('[rink_participants_display rink="gold"]'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <?php echo $this->shortcode_example('[rink_participants_display rink="silver"]'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <h3>Attributes</h3>
                    <table>
                        <thead><tr><th>Attribute</th><th>Values</th><th>Default</th></tr></thead>
                        <tbody><tr><td><code>rink</code></td><td><code>gold</code> or <code>silver</code></td><td><code>gold</code></td></tr></tbody>
                    </table>
                    <p>Qualifying keywords, privacy formatting, media, colors, and testing offset are controlled under <a href="<?php echo esc_url(admin_url('admin.php?page=ifrd-participants-display')); ?>">Displays → Participants Display</a>.</p>
                </section>

                <section class="ifrd-shortcodes-card ifrd-shortcodes-wide">
                    <h2>Selectable Schedule Calendar</h2>
                    <p>The public daily/weekly schedule with date, rink, session-type, and Day/Week controls. Both views use time-scaled event blocks, with Gold and Silver aligned to the same clock.</p>
                    <?php echo $this->shortcode_example('[rink_schedule_calendar]'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <?php echo $this->shortcode_example('[rink_schedule_calendar view="day" title="Today’s Schedule" subtitle="Choose a rink or session type."]'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <h3>Attributes</h3>
                    <table>
                        <thead><tr><th>Attribute</th><th>Values</th><th>Default</th><th>Purpose</th></tr></thead>
                        <tbody>
                            <tr><td><code>view</code></td><td><code>week</code> or <code>day</code></td><td>The Calendar Default View setting</td><td>Chooses the initially selected view. Visitors can still switch views.</td></tr>
                            <tr><td><code>title</code></td><td>Any plain text</td><td>The Schedule Display title</td><td>Overrides the heading for this shortcode instance.</td></tr>
                            <tr><td><code>subtitle</code></td><td>Any plain text</td><td>Browse Gold and Silver rink events by day or week.</td><td>Overrides the explanatory text beneath the heading.</td></tr>
                        </tbody>
                    </table>
                    <h3>Compatibility alias</h3>
                    <p><code>[rink_schedule_list]</code> is an alias for the same selectable calendar and accepts the same <code>view</code>, <code>title</code>, and <code>subtitle</code> attributes.</p>
                    <?php echo $this->shortcode_example('[rink_schedule_list view="week"]'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <p><strong>Session Type filter:</strong> The selector is generated automatically from categories found in the selected week, including Freestyle, Stick &amp; Puck, Public Skating, Private Hockey / Coaches Ice, Hockey, Learn to Skate, Camps, Specialty Classes, and Other. It can be hidden under <a href="<?php echo esc_url(admin_url('admin.php?page=ifrd-schedule-display')); ?>">Displays → Schedule Display</a>.</p>
                    <p><strong>Event colors:</strong> Adjust Calendar Event Color Strength under <a href="<?php echo esc_url(admin_url('admin.php?page=ifrd-schedule-display')); ?>">Displays → Schedule Display</a>. The default 50% setting mutes both Dash-provided colors and fallback category colors.</p>
                    <p><strong>Registration:</strong> Enable or disable calendar registration links under <a href="<?php echo esc_url(admin_url('admin.php?page=ifrd-schedule-display')); ?>">Displays → Schedule Display</a>. When enabled, an event becomes clickable when Dash supplies or resolves a public registration destination and positively reports registration open. Closed, expired, not-yet-open, and full sessions are not linked. If a combined block has multiple destinations, clicking it opens a choice list.</p>
                    <p><strong>Server cache:</strong> The current and next weeks are refreshed at local midnight and noon. The current week is embedded with the page so a warmed schedule can appear immediately, while stale data remains visible during background updates.</p>
                </section>

                <section class="ifrd-shortcodes-card">
                    <h2>Video for Screens</h2>
                    <p>Displays the selected video full-screen, muted, and on a continuous loop.</p>
                    <?php echo $this->shortcode_example('[video_for_screens]'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <h3>Attributes</h3>
                    <p>None. Choose the video under <a href="<?php echo esc_url(admin_url('admin.php?page=ifrd-video-for-screens')); ?>">Displays → Video for Screens</a>.</p>
                    <p><strong>Remote refresh:</strong> The same page includes a Refresh Screens Now button. Open screens check for that request every 60 seconds and reload automatically.</p>
                </section>

                <section class="ifrd-shortcodes-card">
                    <h2>Pricing Page</h2>
                    <p>Displays the selected pricing video full-screen, muted, and on a continuous loop.</p>
                    <?php echo $this->shortcode_example('[pricing_page]'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <p>Choose and remotely refresh its video under <a href="<?php echo esc_url(admin_url('admin.php?page=ifrd-pricing-page')); ?>">Displays → Pricing Page</a>.</p>
                </section>

                <section class="ifrd-shortcodes-card">
                    <h2>Public Skating Rules Page</h2>
                    <p>Displays the selected public skating rules video full-screen, muted, and on a continuous loop.</p>
                    <?php echo $this->shortcode_example('[public_skating_rules_page]'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <p>Choose and remotely refresh its video under <a href="<?php echo esc_url(admin_url('admin.php?page=ifrd-public-skating-rules-page')); ?>">Displays → Public Skating Rules Page</a>.</p>
                </section>

                <section class="ifrd-shortcodes-card ifrd-shortcodes-wide">
                    <h2>Usage notes</h2>
                    <ul>
                        <li>Use straight quotation marks around attribute values, as shown above.</li>
                        <li>The calendar opens on today’s date. Its on-page controls determine the selected date, rink, session type, and view after loading.</li>
                        <li>The calendar’s Day and Week views use the same cached weekly data. Current and upcoming weeks are warmed at local midnight and noon.</li>
                        <li>The TV schedule and participants display refresh automatically according to their settings.</li>
                        <li>If a display reports a connection problem, verify the shared Dash Connector first.</li>
                    </ul>
                    <div><?php IFRD_Dash_Connector::render_settings_status(); ?></div>
                </section>
            </div>
        </div>
        <?php
    }
}

