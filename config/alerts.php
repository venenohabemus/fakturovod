<?php

return [

    // Comma-separated list of addresses that get an e-mail whenever an
    // invoice lands in the error queue (failed/rejected). Empty = alerting
    // off (local dev default; the log mailer catches everything anyway).
    'recipients' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ALERT_EMAIL', ''))
    ))),

];
