<?php

namespace Application\Controller\Api\Service;

use Application\Controller\Api\PictureVoteController as Controller;
use Application\Model\PictureVote;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class PictureVoteControllerFactory implements FactoryInterface
{
    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @param string $requestedName
     */
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): Controller
    {
        return new Controller(
            $container->get(PictureVote::class)
        );
    }
}
