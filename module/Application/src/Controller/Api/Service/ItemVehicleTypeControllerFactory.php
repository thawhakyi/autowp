<?php

namespace Application\Controller\Api\Service;

use Application\Controller\Api\ItemVehicleTypeController as Controller;
use Application\Model\Item;
use Application\Model\VehicleType;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class ItemVehicleTypeControllerFactory implements FactoryInterface
{
    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @param string $requestedName
     */
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): Controller
    {
        return new Controller(
            $container->get(VehicleType::class),
            $container->get(Item::class)
        );
    }
}
