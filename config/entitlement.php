<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Entitlement Operating Mode
    |--------------------------------------------------------------------------
    |
    | Supported modes:
    | - 'off':     Entitlement checks pass through without blocking or logging.
    | - 'observe': Entitlement is evaluated; would-be denials are logged but not blocked.
    | - 'enforce': Entitlement is strictly enforced; unauthorized access aborts with 403.
    |
    | Default: 'off'
    |
    */
    'mode' => env('ENTITLEMENT_MODE', 'off'),

    /*
    |--------------------------------------------------------------------------
    | Logging Channel for Entitlement Observability
    |--------------------------------------------------------------------------
    */
    'log_channel' => env('ENTITLEMENT_LOG_CHANNEL', null),
];
