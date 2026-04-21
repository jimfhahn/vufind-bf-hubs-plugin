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

        $neo4jConfig = $config['Neo4j'] ?? [];

        // Default disk cache for the relationship frequency distribution so
        // we don't rerun the ~2s aggregation query on every record view.
        if (empty($neo4jConfig['frequencyCachePath'])) {
            $localDir = defined('LOCAL_OVERRIDE_DIR') ? LOCAL_OVERRIDE_DIR : null;
            if ($localDir) {
                $neo4jConfig['frequencyCachePath']
                    = $localDir . '/cache/bibframehub_rel_frequencies.json';
            }
        }

        $service = new Neo4jService($neo4jConfig);

        if ($container->has(\VuFind\Log\Logger::class)) {
            $service->setLogger($container->get(\VuFind\Log\Logger::class));
        }

        return $service;
    }
}
