<?php
define('ABSPATH', __DIR__);

require dirname(__DIR__) . '/ice-field-dash-connector/includes/class-ifdc-event-assignment.php';

function expect_guard($event, $expected, $label) {
    $method = new ReflectionMethod('IFDC_Event_Assignment', 'event_requires_automatic_update');
    $method->setAccessible(true);
    $actual = $method->invoke(null, $event);
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "PASS: {$label}\n";
}

expect_guard(
    ['assignment_update' => false, 'name_mismatch' => true],
    false,
    'A preserved special event name does not trigger an automatic write'
);
expect_guard(
    ['assignment_update' => true, 'name_mismatch' => false],
    true,
    'A Team or capacity mismatch remains actionable'
);
expect_guard(
    ['assignment_update' => true, 'name_mismatch' => true],
    true,
    'An enabled automatic rename remains actionable'
);

echo "All automatic event guard tests passed.\n";
