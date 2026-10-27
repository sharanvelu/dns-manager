<?php

declare(strict_types = 1);

return [
    /*
    |--------------------------------------------------------------------------
    | Pages
    |--------------------------------------------------------------------------
    |
    | Pinned explicitly: this app keeps pages in lowercase `js/pages` and only
    | as `.tsx`. A mismatch is masked on macOS (case-insensitive filesystem) but
    | on Linux (CI, Docker) `assertInertia` component checks fail with "page
    | does not exist". Inertia 3 moved these keys out of `testing`.
    |
    */

    'pages' => [
        'ensure_pages_exist' => false,

        'paths' => [
            resource_path('js/pages'),
        ],

        'extensions' => [
            'tsx',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Testing
    |--------------------------------------------------------------------------
    */

    'testing' => [
        'ensure_pages_exist' => true,
    ],
];
