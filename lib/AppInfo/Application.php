<?php

declare(strict_types=1);

namespace OCA\IntegrationTopdesk\AppInfo;

use OCA\IntegrationTopdesk\Dashboard\TicketsWidget;
use OCA\IntegrationTopdesk\Listener\UserDeletedListener;
use OCA\IntegrationTopdesk\Listener\UserLoggedInListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\User\Events\BeforeUserDeletedEvent;
use OCP\User\Events\UserLoggedInEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'integration_topdesk';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerDashboardWidget(TicketsWidget::class);

		// Re-verify the TOPdesk person link at login. This is where a reused email
		// address gets caught: the address resolves to a different person card, the
		// stored UUID is replaced, and the user sees their own tickets instead of
		// their predecessor's.
		$context->registerEventListener(UserLoggedInEvent::class, UserLoggedInListener::class);

		// Leave nothing behind in oc_preferences when an account goes.
		$context->registerEventListener(BeforeUserDeletedEvent::class, UserDeletedListener::class);
	}

	public function boot(IBootContext $context): void {
	}
}
