<?php

declare(strict_types=1);

namespace OCA\IntegrationTopdesk\Listener;

use OCA\IntegrationTopdesk\Service\PersonResolver;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\BeforeUserDeletedEvent;

/**
 * Drops the stored TOPdesk person link when a Nextcloud account is deleted.
 *
 * Listens on *Before*UserDeletedEvent because the user object is still available
 * there. Nothing is deleted in TOPdesk — the person card and their ticket history
 * belong to the customer's TOPdesk, not to this integration.
 *
 * @template-implements IEventListener<BeforeUserDeletedEvent>
 */
class UserDeletedListener implements IEventListener {
	public function __construct(
		private PersonResolver $resolver,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof BeforeUserDeletedEvent) {
			return;
		}

		$this->resolver->forget($event->getUser()->getUID());
	}
}
