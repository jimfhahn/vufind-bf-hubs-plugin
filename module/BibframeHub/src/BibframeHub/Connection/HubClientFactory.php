<?php

namespace BibframeHub\Connection;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

class HubClientFactory implements FactoryInterface
{
    public function __invoke(
        ContainerInterface $container,
        $requestedName,
        ?array $options = null
    ) {
        $configLoader = $container->get(\VuFind\Config\PluginManager::class);
        $config = $configLoader->get('BibframeHub')->toArray();

        $httpService = $container->get(\VuFindHttp\HttpService::class);
        $client = $httpService->createClient();

        $hubClient = new HubClient($client, $config['Connection'] ?? []);

        if ($container->has(\VuFind\Log\Logger::class)) {
            $hubClient->setLogger($container->get(\VuFind\Log\Logger::class));
        }

        return $hubClient;
    }
}
