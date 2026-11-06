<?php
if (!defined('HOUR_IN_SECONDS')) define('HOUR_IN_SECONDS', 3600);
function add_action() {}
function add_shortcode() {}

require_once dirname(__DIR__) . '/ice-field-rink-displays/includes/class-ifrd-field-booking-calendar.php';

class IFRD_Field_Calendar_Test_Settings {
    public function opts() {
        return array('display_timezone' => 'America/Chicago');
    }
}

function field_calendar_assert($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

$calendar = new IFRD_Field_Booking_Calendar(new IFRD_Field_Calendar_Test_Settings());
$method = new ReflectionMethod($calendar, 'normalize_bookings');
$method->setAccessible(true);
$scale_method = new ReflectionMethod($calendar, 'normalize_scale');
$scale_method->setAccessible(true);
$month = new DateTimeImmutable('2027-01-01 00:00:00', new DateTimeZone('America/Chicago'));
$records = array(
    array('id' => 'private-1', 'attributes' => array(
        'resource_id' => 3,
        'start' => '2027-01-02T09:00:00',
        'end' => '2027-01-02T10:00:00',
        'desc' => 'Secret Customer Booking',
    )),
    array('id' => 'private-2', 'attributes' => array(
        'resource_id' => 3,
        'start' => '2027-01-02T09:30:00',
        'end' => '2027-01-02T11:00:00',
        'desc' => 'Another Secret Booking',
    )),
    array('id' => 'other-resource', 'attributes' => array(
        'resource_id' => 4,
        'start' => '2027-01-02T12:00:00',
        'end' => '2027-01-02T13:00:00',
        'desc' => 'Must Not Appear',
    )),
    array('id' => 'overnight', 'attributes' => array(
        'resource_id' => 3,
        'start' => '2027-01-03T23:00:00',
        'end' => '2027-01-04T01:00:00',
        'desc' => 'Overnight Private Booking',
    )),
    array('id' => 'short-gap-1', 'attributes' => array(
        'resource_id' => 3,
        'start' => '2027-01-05T09:00:00',
        'end' => '2027-01-05T10:00:00',
    )),
    array('id' => 'short-gap-2', 'attributes' => array(
        'resource_id' => 3,
        'start' => '2027-01-05T10:59:00',
        'end' => '2027-01-05T12:00:00',
    )),
    array('id' => 'hour-gap-1', 'attributes' => array(
        'resource_id' => 3,
        'start' => '2027-01-06T09:00:00',
        'end' => '2027-01-06T10:00:00',
    )),
    array('id' => 'hour-gap-2', 'attributes' => array(
        'resource_id' => 3,
        'start' => '2027-01-06T11:00:00',
        'end' => '2027-01-06T12:00:00',
    )),
);

$bookings = $method->invoke($calendar, $records, $month, 3);
$encoded = json_encode($bookings);

field_calendar_assert(count($bookings['2027-01-02'] ?? array()) === 1, 'overlapping field events merge into one unavailable block');
field_calendar_assert(($bookings['2027-01-02'][0]['time'] ?? '') === '9:00 AM–11:00 AM', 'merged unavailable block keeps the complete occupied time');
field_calendar_assert(count($bookings['2027-01-03'] ?? array()) === 1 && count($bookings['2027-01-04'] ?? array()) === 1, 'overnight bookings split across both calendar dates');
field_calendar_assert(count($bookings['2027-01-05'] ?? array()) === 1 && ($bookings['2027-01-05'][0]['time'] ?? '') === '9:00 AM–12:00 PM', 'gaps shorter than one hour merge into one unavailable block');
field_calendar_assert(count($bookings['2027-01-06'] ?? array()) === 2, 'a gap of exactly one hour remains visible');
field_calendar_assert(strpos($encoded, 'Secret') === false && strpos($encoded, 'private-') === false, 'public booking data excludes private titles and event IDs');
field_calendar_assert(substr_count($encoded, 'Unavailable') === 6, 'every public booking segment uses the unavailable label');
field_calendar_assert($scale_method->invoke($calendar, 85) === 85, 'requested calendar scale is preserved');
field_calendar_assert($scale_method->invoke($calendar, 40) === 60 && $scale_method->invoke($calendar, 140) === 100, 'calendar scale stays within the supported 60–100 range');

echo "All field calendar tests passed.\n";
