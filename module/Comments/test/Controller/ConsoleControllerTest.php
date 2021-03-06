<?php

namespace AutowpTest\Comments\Controller;

use Application\Test\AbstractConsoleControllerTestCase;
use Autowp\Comments\Controller\ConsoleController;

class ConsoleControllerTest extends AbstractConsoleControllerTestCase
{
    protected string $applicationConfigPath = __DIR__ . '/../../../../config/application.config.php';

    public function testRefreshRepliesCount()
    {
        $this->dispatch('comments refresh-replies-count');

        $this->assertControllerName(ConsoleController::class);
        $this->assertMatchedRouteName('comments');
        $this->assertActionName('refresh-replies-count');
        $this->assertConsoleOutputContains('ok');
    }

    public function testCleanupDeleted()
    {
        $this->dispatch('comments cleanup-deleted');

        $this->assertControllerName(ConsoleController::class);
        $this->assertMatchedRouteName('comments');
        $this->assertActionName('cleanup-deleted');
        $this->assertConsoleOutputContains('ok');
    }
}
