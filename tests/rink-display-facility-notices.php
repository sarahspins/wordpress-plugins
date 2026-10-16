<?php
define('ABSPATH', __DIR__);

function wp_strip_all_tags($value) {
    return strip_tags($value);
}

require dirname(__DIR__) . '/ice-field-rink-displays/includes/class-ifrd-schedule-calendar.php';

function expect_facility_notice($events, $expected_count, $expected_notice, $label) {
    $class = new ReflectionClass('IFRD_Schedule_Calendar');
    $calendar = $class->newInstanceWithoutConstructor();
    $method = $class->getMethod('merge_simultaneous');
    $method->setAccessible(true);
    $actual = $method->invoke($calendar, $events);

    if (count($actual) !== $expected_count || !empty($actual[0]['facilityNotice']) !== $expected_notice) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "PASS: {$label}\n";
}

$gold = array(
    'id' => '1',
    'title' => 'Closing Early on Christmas Eve',
    'date' => '2026-12-24',
    'start' => '2026-12-24T17:00:00-06:00',
    'end' => '2026-12-24T22:00:00-06:00',
    'startMinutes' => 1020,
    'endMinutes' => 1320,
    'rinkKey' => 'gold',
    'rink' => 'Gold Rink',
    'dashColor' => '#aaaaaa',
    'registrationLinks' => array(),
);
$silver = $gold;
$silver['id'] = '2';
$silver['rinkKey'] = 'silver';
$silver['rink'] = 'Silver Rink';

expect_facility_notice(array($gold, $silver), 1, true, 'Matching two-rink closing notice is combined');

$silver['title'] = 'Public Skating';
expect_facility_notice(array($gold, $silver), 2, false, 'Different titles remain separate');

$gold['title'] = 'Public Skating';
$silver['title'] = 'Public Skating';
expect_facility_notice(array($gold, $silver), 2, false, 'Ordinary matching events remain separate');

echo "All facility notice tests passed.\n";
