<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Testing
    |--------------------------------------------------------------------------
    |
    | Published to override the package default of resource_path('js/Pages'):
    | this app keeps pages in lowercase `js/pages`. On macOS the mismatch is
    | masked by the case-insensitive filesystem, but on Linux (CI, Docker)
    | `assertInertia` component checks fail with "page does not exist".
    |
    */

    'testing' => [

        'ensure_pages_exist' => true,

        'page_paths' => [

            resource_path('js/pages'),

        ],

        'page_extensions' => [

            'tsx',

        ],

    ],

];
