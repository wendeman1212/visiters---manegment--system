<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Explicit QR base URL (highest priority)
    |--------------------------------------------------------------------------
    |
    | When set, every generated QR code will link to this base URL.
    | Examples:
    |   QR_BASE_URL=http://192.168.1.100:8000   (LAN development)
    |   QR_BASE_URL=https://vms.example.com      (production)
    |
    | Leave empty to fall back to APP_URL, and to automatic LAN IP
    | detection while APP_URL points at localhost.
    |
    */

    'base_url' => env('QR_BASE_URL'),

    /*
    |--------------------------------------------------------------------------
    | LAN IP override
    |--------------------------------------------------------------------------
    |
    | Optional manual override for the local network address that is used
    | when the application falls back to local development detection.
    | Useful when the machine has more than one network interface.
    |
    */

    'lan_ip' => env('QR_LAN_IP'),

    /*
    |--------------------------------------------------------------------------
    | Local development port
    |--------------------------------------------------------------------------
    |
    | The port embedded in QR codes while running the local development
    | server (php artisan serve). When empty, the port is taken from
    | APP_URL, falling back to 8000.
    |
    */

    'port' => env('QR_PORT'),

    /*
    |--------------------------------------------------------------------------
    | QR path
    |--------------------------------------------------------------------------
    |
    | The route that serves the public QR landing page.
    |
    */

    'path' => env('QR_PATH', 'qr'),

];
