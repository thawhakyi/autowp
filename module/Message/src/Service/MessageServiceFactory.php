<?php

namespace Autowp\Message\Service;

use Application\Service\TelegramService;
use Autowp\Message\MessageService;
use Autowp\User\Model\User;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

/**
 * @todo Unlink from Telegram
 */
class MessageServiceFactory implements FactoryInterface
{
    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @param string $requestedName
     */
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): MessageService
    {
        $tables = $container->get('TableManager');
        return new MessageService(
            $container->get(TelegramService::class),
            $tables->get('personal_messages'),
            $container->get(User::class)
        );
    }
}
