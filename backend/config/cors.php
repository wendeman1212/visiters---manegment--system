<?php

return [

    'paths' => ['api/*', 'login', 'register', 'logout', 'up'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter([
        'http://localhost:5173',
        'http://127.0.0.1:5173',
        // The application's own address (LAN IP in development, domain in
        // production) plus an optional dedicated Vite origin.
        env('APP_URL'),
        env('QR_BASE_URL'),
        env('VITE_DEV_URL'),
    ])),

    // Any loopback or LAN address may talk to the API while developing,
    // e.g. http://192.168.1.100:5173 or http://192.168.1.100:8000.
    'allowed_origins_patterns' => [
        '/^https?:\/\/(?:localhost|127\.0\.0\.1|\d{1,3}(?:\.\d{1,3}){3})(?::\d+)?$/i',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [
        'X-Inertia',
        'X-Inertia-Version',
        'X-Inertia-Location',
        'X-Inertia-Redirect',
    ],

    'max_age' => 0,

    'supports_credentials' => true,

];
