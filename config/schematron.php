<?php

return [

    // KoSIT validator daemon (layer 2 validation: EN 16931 + Peppol BIS
    // schematron). Empty url = layer disabled (e.g. in tests). Start the
    // sidecar with `php artisan schematron:serve`.
    'url' => env('SCHEMATRON_URL', 'http://localhost:8081'),

    'timeout' => (int) env('SCHEMATRON_TIMEOUT', 30),

    // Java runtime for schematron:serve.
    'java' => env('SCHEMATRON_JAVA', 'java'),

];
