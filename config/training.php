<?php

return [
    // Both this flag and the existing loopback gateway must be explicitly enabled.
    'enabled' => env('NL_TRAINING', false),
    'source' => env('NL_COMBAT_SOURCE'),
];
