<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Ninja Lost visual asset registry
    |--------------------------------------------------------------------------
    |
    | The application never fabricates game visuals. UI surfaces may only show
    | assets that have been explicitly imported into the private preview asset
    | root and described by the manifest below. Missing assets render as a
    | neutral placeholder; mechanics are never inferred from artwork.
    |
    */
    'manifest' => env(
        'NL_ASSET_MANIFEST',
        storage_path('app/ninja-lost/assets/manifest.json'),
    ),

    'public_root' => env(
        'NL_ASSET_PUBLIC_ROOT',
        public_path('game-assets'),
    ),

    'public_prefix' => env('NL_ASSET_PUBLIC_PREFIX', '/game-assets'),

    // File extensions the web registry is allowed to expose directly.
    // SWF files remain source inputs and are not exposed by this registry.
    'web_extensions' => ['png', 'webp', 'jpg', 'jpeg'],

    // Source labels accepted by the first-party importer. Rights/licensing
    // review is intentionally separate from technical recognition.
    'source_types' => [
        'operator-import',
        'ninja-saga-swf',
        'metadata-pack',
    ],
];
