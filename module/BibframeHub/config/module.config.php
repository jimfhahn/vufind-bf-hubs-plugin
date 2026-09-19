<?php

namespace BibframeHub\Module\Config;

$config = [
    'service_manager' => [
        'factories' => [
            'BibframeHub\Connection\HubClient'    => 'BibframeHub\Connection\HubClientFactory',
            'BibframeHub\Graph\Neo4jService'      => 'BibframeHub\Graph\Neo4jServiceFactory',
            'BibframeHub\Graph\HubStoreInterface' => 'BibframeHub\Graph\HubStoreFactory',
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
            'command' => [
                'factories' => [
                    'BibframeHub\Command\LoadHubsCommand' => 'BibframeHub\Command\LoadHubsCommandFactory',
                ],
                'aliases' => [
                    'bibframehub/load-hubs' => 'BibframeHub\Command\LoadHubsCommand',
                ],
            ],
        ],
    ],
];

return $config;
