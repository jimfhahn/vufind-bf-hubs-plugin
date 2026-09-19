<?php

namespace BibframeHub\Command;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

class LoadHubsCommandFactory implements FactoryInterface
{
    public function __invoke(
        ContainerInterface $container,
        $requestedName,
        ?array $options = null
    ) {
        return new $requestedName(
            $container->get(\VuFind\Db\Connection::class),
            ...($options ?? [])
        );
    }
}
