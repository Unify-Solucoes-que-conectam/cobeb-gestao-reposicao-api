<?php

return [
    'base_url' => env('EVOLUTION_API_URL', 'http://localhost:8080'),
    'api_key' => env('EVOLUTION_API_KEY', ''),
    'meta_webhook_url' => env('EVOLUTION_META_WEBHOOK_URL'),
    'meta_webhook_token' => env('EVOLUTION_META_WEBHOOK_TOKEN'),
    'timeout' => env('EVOLUTION_TIMEOUT', 20),
    'connect_timeout' => env('EVOLUTION_CONNECT_TIMEOUT', 5),
    'qrcode_expires_in' => env('EVOLUTION_QRCODE_EXPIRES_IN', 60),

    /*
    |--------------------------------------------------------------------------
    | Whatsapp Test Number
    |--------------------------------------------------------------------------
    |
    | This is the phone number that will be used for WhatsApp notifications
    | when the application is not in production. It allows you to test
    | WhatsApp messaging without contacting real customers.
    |
    */

    'default_number' => env('EVOLUTION_DEFAULT_NUMBER', '37999999999'),

    'rate_limit' => env('WHATSAPP_RATE_LIMIT_PER_MINUTE', 20),
];
