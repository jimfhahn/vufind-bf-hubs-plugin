<?php

namespace BibframeHub\Graph;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

class Neo4jServiceFactory implements FactoryInterface
{
    public function __invoke(
        ContainerInterface $container,
        $requestedName,
        ?array $options = null
    ) {
        $configLoader = $container->get(\VuFind\Config\PluginManager::class);
        $config = $configLoader->get('BibframeHub')->toArray();

        $service = new Neo4jService($config['Neo4j'] ?? []);

        if ($container->has(\VuFind\Log\Logger::class)) {
            $service->setLogger($container->get(\VuFind\Log\Logger::class));
        }

        return $service;
    }
}
