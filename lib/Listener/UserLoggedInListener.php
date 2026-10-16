<?php

declare(strict_types=1);

namespace OCA\IntegrationTopdesk\Listener;

use OCA\IntegrationTopdesk\Service\ConnectionConfig;
use OCA\IntegrationTopdesk\Service\PersonResolver;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserLoggedInEvent;
use Psr\Log\LoggerInterface;

/**
 * Re-checks the user's TOPdesk person link at login.
 *
 * Login is the right moment: it is the one point where we know the account is in
 * active use by whoever holds it now, and it is infrequent enough that one extra
 * API call per login is unnoticeable. Checking per dashboard render instead would
 * put a TOPdesk round-trip on every page load; checking only on a timer would leave
 * a window in which a newly-arrived colleague sees their predecessor's tickets.
 *
 * @template-implements IEventListener<UserLoggedInEvent>
 */
class UserLoggedInListener implements IEventListener {
	public function __construct(
		private PersonResolver $resolver,
		private ConnectionConfig $config,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof UserLoggedInEvent) {
			return;
		}

		if (!$this->config->isConfigured()) {
			return;
		}

		try {
			$this->resolver->resolve($event->getUser()->getUID(), force: true);
		} catch (\Throwable $e) {
			// Never let this get between a user and their login. The resolver already
			// keeps the previous link on a transient failure, and the next login (or
			// the TTL expiring) will try again.
			$this->logger->warning('Could not refresh the TOPdesk person link at login', [
				'user' => $event->getUser()->getUID(),
				'exception' => $e,
			]);
		}
	}
}
