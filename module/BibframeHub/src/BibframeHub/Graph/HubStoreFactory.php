<?php

namespace BibframeHub\Graph;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

/**
 * Builds the HubStoreInterface service according to [HubStore] backend in
 * BibframeHub.ini: "sql" (tables in VuFind's DB), "neo4j" (n10s graph) or
 * "none" (disabled). Defaults to neo4j when that section is enabled, for
 * backwards compatibility, else sql.
 */
class HubStoreFactory implements FactoryInterface
{
    public function __invoke(
        ContainerInterface $container,
        $requestedName,
        ?array $options = null
    ) {
        $config = $container->get(\VuFind\Config\PluginManager::class)
            ->get('BibframeHub')->toArray();

        $backend = strtolower((string)($config['HubStore']['backend'] ?? ''));
        if ($backend === '') {
            $backend = !empty($config['Neo4j']['enabled']) ? 'neo4j' : 'sql';
        }

        $localDir = defined('LOCAL_OVERRIDE_DIR') ? LOCAL_OVERRIDE_DIR : null;
        $freqPath = $config['HubStore']['frequencyCachePath']
            ?? ($localDir ? $localDir . '/cache/bibframehub_rel_frequencies.json' : null);

        switch ($backend) {
            case 'neo4j':
                return $container->get(Neo4jService::class);
            case 'none':
                $store = new SqlHubStore($container->get(\VuFind\Db\Connection::class), false, $freqPath);
                break;
            case 'sql':
            default:
                $store = new SqlHubStore($container->get(\VuFind\Db\Connection::class), true, $freqPath);
        }

        if ($container->has(\VuFind\Log\Logger::class)) {
            $store->setLogger($container->get(\VuFind\Log\Logger::class));
        }
        return $store;
    }
}
