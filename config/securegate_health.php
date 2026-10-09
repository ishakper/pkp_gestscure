<?php

return [
    'door_fresh_minutes' => (int) env('SECUREGATE_DOOR_HEALTH_FRESH_MINUTES', 15),
    'webhook_fresh_minutes' => (int) env('SECUREGATE_WEBHOOK_HEALTH_FRESH_MINUTES', 15),
];
