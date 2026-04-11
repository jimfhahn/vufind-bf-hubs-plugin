<?php

namespace BibframeHub\Module\Config;

$config = [
    'service_manager' => [
        'factories' => [
            'BibframeHub\Connection\HubClient' => 'BibframeHub\Connection\HubClientFactory',
            'BibframeHub\Graph\Neo4jService'   => 'BibframeHub\Graph\Neo4jServiceFactory',
        ],
    ],
    'vufind' => [
        'plugin_managers' => [
            'related' => [
                'factories' => [
                    'BibframeHub\Related\BibframeHub' => 'BibframeHub\Related\BibframeHubFactory',
                ],
                'aliases' => [
                    'bibframehub' => 'BibframeHub\Related\BibframeHub',
                ],
            ],
        ],
    ],
];

return $config;
