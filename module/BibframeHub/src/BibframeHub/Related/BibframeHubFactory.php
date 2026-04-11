<?php

namespace BibframeHub\Related;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

class BibframeHubFactory implements FactoryInterface
{
    public function __invoke(
        ContainerInterface $container,
        $requestedName,
        ?array $options = null
    ) {
        $configLoader = $container->get(\VuFind\Config\PluginManager::class);
        $config = $configLoader->get('BibframeHub')->toArray();

        return new BibframeHub(
            $container->get('BibframeHub\Connection\HubClient'),
            $container->get('BibframeHub\Graph\Neo4jService'),
            new \BibframeHub\Graph\HubRdfParser($config['Connection'] ?? []),
            new \BibframeHub\Relationship\RelationshipInferrer(),
            $config
        );
    }
}
