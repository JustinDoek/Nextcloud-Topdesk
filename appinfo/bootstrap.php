<?php

declare(strict_types=1);

use OCA\TopdeskIntegration\AppInfo\Application;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

$app = new Application();
$context = \OC::$server->query(IRegistrationContext::class);
$app->register($context);


