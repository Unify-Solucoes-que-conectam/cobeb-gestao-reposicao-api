<?php

return [
    'base_url' => env('EVOLUTION_API_URL', 'http://localhost:8080'),
    'api_key' => env('EVOLUTION_API_KEY', ''),
    'instance' => env('EVOLUTION_INSTANCE', ''),

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
];
