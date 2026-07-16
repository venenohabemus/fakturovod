<?php

return [

    // Active poštár adapter. Only ePošťák for now; the interface keeps us
    // free to add another provider without touching calling code.
    'default' => env('POSTAR_DRIVER', 'epostak'),

    'epostak' => [
        // Sandbox: https://dev.epostak.sk/api/v1 — production: https://epostak.sk/api/v1
        'base_url' => env('EPOSTAK_BASE_URL', 'https://dev.epostak.sk/api/v1'),

        'client_id' => env('EPOSTAK_CLIENT_ID'),
        'client_secret' => env('EPOSTAK_CLIENT_SECRET'),

        // Integrator (sk_int_*) tokens address the managed firm via X-Firm-Id
        // on Enterprise endpoints. Leave null once we move to Connector
        // endpoints with a portal-configured customerRef.
        'firm_id' => env('EPOSTAK_FIRM_ID'),

        'timeout' => (int) env('EPOSTAK_TIMEOUT', 30),
    ],

];
