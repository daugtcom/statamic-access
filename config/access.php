<?php

return [
    'entitlements' => [
        /*
         * Configure all target collections that can be unlocked by users
         */
        'target_collections' => [],

        /*
         * Collection and field names for entitlements
         */
        'collection' => 'entitlements',
        'fields' => [
            'user' => 'user',
            'target' => 'target',
            'validity' => 'validity',
        ],
    ]
];
