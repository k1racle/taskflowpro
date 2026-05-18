<?php

require_once __DIR__ . '/../api/booking-schema.php';

return [
    'id' => '202605070007',
    'name' => 'booking_module',
    'description' => 'Create booking tables for public requests and admin approvals.',
    'up' => static function (PDO $pdo): void {
        ensureBookingModuleSchema($pdo);
    },
];
