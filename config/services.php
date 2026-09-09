<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'hikvision' => [
        'host' => env('HIKVISION_ISAPI_HOST', '192.168.90.11'),
        'port' => (int) env('HIKVISION_ISAPI_PORT', 80),
        'username' => env('HIKVISION_ISAPI_USERNAME', 'admin'),
        'password' => env('HIKVISION_ISAPI_PASSWORD'),
        'use_mock' => env('HIKVISION_ISAPI_USE_MOCK', env('HIKVISION_MOCK_MODE', false)),
        'mock_base_url' => env('HIKVISION_ISAPI_MOCK_BASE_URL', null),
        'connect_timeout' => (int) env('ISAPI_CONNECT_TIMEOUT', 5),
        'request_timeout' => (int) env('ISAPI_REQUEST_TIMEOUT', 10),
        'allowed_device_ips' => env('ALLOWED_DEVICE_IPS', ''),
        'device_secret' => env('ISAPI_DEVICE_SECRET'),
        'listener_ip' => env('HIKVISION_LISTENER_IP', '192.168.90.64'),
        'listener_port' => (int) env('HIKVISION_LISTENER_PORT', 8080),
    ],

    'doors' => [
        'DOOR-A' => [
            'ip' => env('DOOR_A_IP', '192.168.90.11'),
            'gateway' => env('DOOR_A_GATEWAY', '192.168.90.1'),
            'username' => env('DOOR_A_USER', 'admin'),
            'password' => env('DOOR_A_PASS'),
            'webhook_secret' => env('DOOR_A_WEBHOOK_SECRET'),
        ],
        'DOOR-B' => [
            'ip' => env('DOOR_B_IP', '192.168.90.15'),
            'gateway' => env('DOOR_B_GATEWAY', '192.168.90.1'),
            'username' => env('DOOR_B_USER', 'admin'),
            'password' => env('DOOR_B_PASS'),
            'webhook_secret' => env('DOOR_B_WEBHOOK_SECRET'),
        ],
        'DOOR-C' => [
            'ip' => env('DOOR_C_IP', '192.168.90.13'),
            'gateway' => env('DOOR_C_GATEWAY', '192.168.90.1'),
            'username' => env('DOOR_C_USER', 'admin'),
            'password' => env('DOOR_C_PASS'),
            'webhook_secret' => env('DOOR_C_WEBHOOK_SECRET'),
        ],
        'DOOR-D' => [
            'ip' => env('DOOR_D_IP', '192.168.90.14'),
            'gateway' => env('DOOR_D_GATEWAY', '192.168.90.1'),
            'username' => env('DOOR_D_USER', 'admin'),
            'password' => env('DOOR_D_PASS'),
            'webhook_secret' => env('DOOR_D_WEBHOOK_SECRET'),
        ],
    ],

];
