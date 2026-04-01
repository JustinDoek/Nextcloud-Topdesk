<?php
declare(strict_types=1);

namespace OCA\TopdeskIntegration\AppInfo;

use OCA\TopdeskIntegration\Dashboard\TopdeskWidget;
use OCA\TopdeskIntegration\Service\TopdeskService;
use OCA\TopdeskIntegration\Settings\Admin;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\IAppContainer;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class Application extends App implements IBootstrap {
    public const APP_ID = 'topdesk_integration';

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void {
        $context->registerService('TopdeskService', function (IAppContainer $c) {
            return new TopdeskService(
                $c->query(IClientService::class),
                $c->query(IConfig::class),
                $c->query(IUserManager::class),    
                $c->query(IUserSession::class),
                $c->query(LoggerInterface::class)
            );
        });

        // Registratie van Dashboard Widget
        $context->registerDashboardWidget(TopdeskWidget::class);
    $context->registerDeclarativeSettings(\OCA\TopdeskIntegration\Declarative\TopdeskSettingsForm::class);

        $context->registerService('Settings.AdminSection', function (IAppContainer $c) {
            return new AdminSection(
                $c->query(IURLGenerator::class),
                $c->query(IL10N::class)
            );
        }); 

    }

    public function boot(IBootContext $context): void {
	}
} 

