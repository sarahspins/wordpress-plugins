<?php
/** Removes expired display-media rows and optionally trashes their attachments. */
class IFRD_Media_Cleanup {
    const CRON_HOOK = 'ifrd_cleanup_expired_display_media';
    const RETENTION_SECONDS = 48 * HOUR_IN_SECONDS;

    public static function init() {
        add_action('init', array(__CLASS__, 'ensure_cron'));
        add_action(self::CRON_HOOK, array(__CLASS__, 'run'));
    }

    public static function ensure_cron() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK);
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public static function run() {
        $schedule = (array) get_option(IFRD_Schedule_Display::OPTION, array());
        $video = (array) get_option(IFRD_Video_For_Screens::OPTION, array());
        $timezone_name = IFRD_Scheduled_Media::timezone_name($schedule);
        $timezone = new DateTimeZone($timezone_name);
        $cutoff = (new DateTimeImmutable('now', $timezone))->modify('-48 hours');
        $trash_urls = array();

        $video['video_schedule'] = self::prune_scheduled($video['video_schedule'] ?? array(), $timezone, $cutoff, $trash_urls);
        $schedule['banner_schedule'] = self::prune_scheduled($schedule['banner_schedule'] ?? array(), $timezone, $cutoff, $trash_urls);
        $video['slideshow_images'] = self::prune_slides($video['slideshow_images'] ?? array(), $timezone, $cutoff, $trash_urls);
        $schedule['banner_slideshow_images'] = self::prune_slides($schedule['banner_slideshow_images'] ?? array(), $timezone, $cutoff, $trash_urls);

        update_option(IFRD_Video_For_Screens::OPTION, $video, false);
        update_option(IFRD_Schedule_Display::OPTION, $schedule, false);

        $remaining = wp_json_encode(array($video, $schedule));
        foreach (array_unique($trash_urls) as $url) {
            if ($remaining && strpos($remaining, $url) !== false) continue;
            $attachment_id = attachment_url_to_postid($url);
            if ($attachment_id && get_post_type($attachment_id) === 'attachment') {
                wp_trash_post($attachment_id);
            }
        }
    }

    private static function prune_scheduled($rows, $timezone, $cutoff, &$trash_urls) {
        $rows = IFRD_Scheduled_Media::sanitize($rows, $timezone->getName());
        $kept = array();
        $count = count($rows);
        foreach ($rows as $index => $row) {
            $replacement = $index + 1 < $count
                ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $rows[$index + 1]['starts_at'], $timezone)
                : null;
            if ($replacement && $replacement <= $cutoff) {
                if (!empty($row['delete_after_expiry'])) $trash_urls[] = $row['video_url'];
                continue;
            }
            $kept[] = $row;
        }
        return $kept;
    }

    private static function prune_slides($rows, $timezone, $cutoff, &$trash_urls) {
        $kept = array();
        foreach (IFRD_Expiring_Slideshow::sanitize($rows, $timezone->getName()) as $row) {
            $expires = $row['expires_at'] !== ''
                ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $row['expires_at'], $timezone)
                : null;
            if ($expires && $expires <= $cutoff) {
                if (!empty($row['delete_after_expiry'])) $trash_urls[] = $row['url'];
                continue;
            }
            $kept[] = $row;
        }
        return $kept;
    }
}
