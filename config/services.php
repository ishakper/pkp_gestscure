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
        'password' => env('HIKVISION_ISAPI_PASSWORD', 'Hikvision@DoorA'),
        'use_mock' => env('HIKVISION_ISAPI_USE_MOCK', env('HIKVISION_MOCK_MODE', true)),
        'mock_base_url' => env('HIKVISION_ISAPI_MOCK_BASE_URL', null),
        'connect_timeout' => (int) env('ISAPI_CONNECT_TIMEOUT', 5),
        'request_timeout' => (int) env('ISAPI_REQUEST_TIMEOUT', 10),
    ],

    'doors' => [
        'DOOR-A' => [
            'ip' => env('DOOR_A_IP', '192.168.90.11'),
            'gateway' => env('DOOR_A_GATEWAY', '192.168.90.1'),
        ],
        'DOOR-B' => [
            'ip' => env('DOOR_B_IP', '192.168.90.15'),
            'gateway' => env('DOOR_B_GATEWAY', '192.168.90.1'),
        ],
        'DOOR-C' => [
            'ip' => env('DOOR_C_IP', '192.168.90.13'),
            'gateway' => env('DOOR_C_GATEWAY', '192.168.90.1'),
        ],
        'DOOR-D' => [
            'ip' => env('DOOR_D_IP', '192.168.90.14'),
            'gateway' => env('DOOR_D_GATEWAY', '192.168.90.1'),
        ],
    ],

];
