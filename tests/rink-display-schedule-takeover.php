<?php
function sanitize_text_field($value) { return trim((string) $value); }
function esc_url_raw($value) { return filter_var((string) $value, FILTER_VALIDATE_URL) ? (string) $value : ''; }

class IFRD_Scheduled_Media {
    public static function timezone_name() { return 'America/Chicago'; }
}

require dirname(__DIR__) . '/ice-field-rink-displays/includes/class-ifrd-schedule-takeover.php';

function assert_same($expected, $actual, $message) {
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$rows = array(
    array('starts_at' => '2026-10-10T08:00', 'ends_at' => '2026-10-10T12:00', 'source' => 'video_for_screens'),
    array('starts_at' => '2026-10-10T09:00', 'ends_at' => '2026-10-10T11:00', 'source' => 'custom_video', 'video_url' => 'https://example.com/competition.mp4'),
    array('starts_at' => '2026-10-11T09:00', 'ends_at' => '2026-10-11T08:00', 'source' => 'video_for_screens'),
    array('starts_at' => '2026-10-12T09:00', 'ends_at' => '2026-10-12T10:00', 'source' => 'custom_video', 'video_url' => ''),
);

$clean = IFRD_Schedule_Takeover::sanitize($rows, 'America/Chicago');
assert_same(2, count($clean), 'Invalid takeover rows should be removed.');

$before = IFRD_Schedule_Takeover::effective($clean, 'America/Chicago', new DateTimeImmutable('2026-10-10 07:59', new DateTimeZone('America/Chicago')));
assert_same(false, $before['active'], 'The schedule should remain visible before the takeover starts.');

$first = IFRD_Schedule_Takeover::effective($clean, 'America/Chicago', new DateTimeImmutable('2026-10-10 08:30', new DateTimeZone('America/Chicago')));
assert_same('video_for_screens', $first['source'], 'The first active takeover should use Video for Screens.');

$overlap = IFRD_Schedule_Takeover::effective($clean, 'America/Chicago', new DateTimeImmutable('2026-10-10 09:30', new DateTimeZone('America/Chicago')));
assert_same('custom_video', $overlap['source'], 'The latest-starting active takeover should win an overlap.');

$ended = IFRD_Schedule_Takeover::effective($clean, 'America/Chicago', new DateTimeImmutable('2026-10-10 12:00', new DateTimeZone('America/Chicago')));
assert_same(false, $ended['active'], 'The schedule should return exactly at the takeover end time.');

echo "Schedule takeover tests passed.\n";
