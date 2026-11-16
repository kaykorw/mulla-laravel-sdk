<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Secret key
    |--------------------------------------------------------------------------
    |
    | Your secret API key from the Mulla dashboard (Developers → API keys).
    | sk_test_… keys talk to the sandbox, sk_live_… keys to production.
    | Never commit it: keep it in .env.
    |
    */

    'secret_key' => env('MULLA_SECRET_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Gateway URL
    |--------------------------------------------------------------------------
    |
    | Leave empty to use the official gateway for your key's environment.
    | Set it only for a local or self-hosted gateway, e.g.
    | "http://localhost:5605/gateway". It must use https outside localhost.
    |
    */

    'base_url' => env('MULLA_BASE_URL'),

    /*
    |--------------------------------------------------------------------------
    | HTTP behaviour
    |--------------------------------------------------------------------------
    |
    | timeout / connect_timeout: seconds.
    | max_retries: safe automatic retries (reads on transient errors; writes
    | only when Mulla cannot have processed them). 0 disables.
    | lang: language of API messages (en, fr, rw, sw); null = API default.
    |
    */

    'timeout' => (float) env('MULLA_TIMEOUT', 30),

    'connect_timeout' => (float) env('MULLA_CONNECT_TIMEOUT', 10),

    'max_retries' => (int) env('MULLA_MAX_RETRIES', 2),

    'lang' => env('MULLA_LANG'),

    /*
    |--------------------------------------------------------------------------
    | Device id
    |--------------------------------------------------------------------------
    |
    | UUID v4 identifying this installation (sent as x-device-id).
    | Leave empty to derive a stable id from your key.
    |
    */

    'device_id' => env('MULLA_DEVICE_ID'),

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | A log channel from config/logging.php that receives one line per API
    | request (method, URL, status, duration). Secrets are never logged.
    | null disables request logging.
    |
    */

    'log_channel' => env('MULLA_LOG_CHANNEL'),

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    |
    | secret:        shown when you create or rotate your webhook endpoint.
    | path:          where Mulla posts events. Paste the full URL in the dashboard.
    | auto_register: register the route automatically. Set false and call
    |                Route::mullaWebhooks() yourself for full control.
    | middleware:    extra middleware for the route (it has no CSRF by default).
    | tolerance:     max age in seconds of timestamped (v2) signatures.
    | dedupe_ttl:    seconds an event id is remembered, so Mulla's retries of
    |                an event you already handled are acknowledged and skipped.
    | cache_store:   cache store used for de-duplication (null = default store).
    |
    */

    'webhook' => [
        'secret' => env('MULLA_WEBHOOK_SECRET'),
        'path' => env('MULLA_WEBHOOK_PATH', 'mulla/webhook'),
        'auto_register' => true,
        'middleware' => [],
        'tolerance' => 300,
        'dedupe_ttl' => 86400,
        'cache_store' => env('MULLA_WEBHOOK_CACHE_STORE'),
    ],

];
