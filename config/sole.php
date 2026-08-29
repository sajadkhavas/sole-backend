<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Prototype boundary
    |--------------------------------------------------------------------------
    |
    | This switch exists only to make local experiments explicit. The
    | application refuses to boot in production while it is enabled.
    |
    */
    'prototype_mode' => (bool) env('APP_PROTOTYPE_MODE', false),
];
