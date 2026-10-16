<?php

declare(strict_types=1);

namespace OCA\IntegrationTopdesk\Dashboard;

use OCA\IntegrationTopdesk\AppInfo\Application;
use OCA\IntegrationTopdesk\Service\ConnectionConfig;
use OCA\IntegrationTopdesk\Service\IncidentService;
use OCA\IntegrationTopdesk\Service\PersonLink;
use OCA\IntegrationTopdesk\Service\ViteStyles;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IButtonWidget;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\IOptionWidget;
use OCP\Dashboard\Model\WidgetButton;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\Dashboard\Model\WidgetOptions;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Util;
use Psr\Log\LoggerInterface;

/**
 * "My TOPdesk tickets" on the Nextcloud dashboard.
 *
 * The widget is registered twice over, on purpose:
 *
 *   - `load()` mounts a Vue component, which is what the browser shows. It needs to
 *     be custom because the create-ticket form opens in a modal on the dashboard,
 *     and the generic list rendering has no room for that.
 *   - `getItemsV2()` serves the same five tickets through
 *     /apps/dashboard/api/v2/widget-items, which is what the mobile and desktop
 *     clients read. Implementing it costs almost nothing here and is the difference
 *     between a widget that exists everywhere and one that exists in a browser.
 */
class TicketsWidget implements IAPIWidgetV2, IButtonWidget, IIconWidget, IOptionWidget {
	public function __construct(
		private IL10N $l,
		private IURLGenerator $urlGenerator,
		private IncidentService $incidentService,
		private ConnectionConfig $config,
		private LoggerInterface $logger,
	) {
	}

	public function getId(): string {
		return 'integration_topdesk_tickets';
	}

	public function getTitle(): string {
		return $this->l->t('My TOPdesk tickets');
	}

	public function getOrder(): int {
		return 20;
	}

	public function getIconClass(): string {
		return 'icon-topdesk';
	}

	public function getIconUrl(): string {
		return $this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg'),
		);
	}

	public function getUrl(): ?string {
		$url = $this->incidentService->getPortalUrl();

		return $url === '' ? null : $url;
	}

	public function load(): void {
		Util::addScript(Application::APP_ID, 'dashboard');
		Util::addStyle(Application::APP_ID, 'icon');
		// Not addStyle('dashboard') — that would load the Vite @import stub, which
		// Nextcloud cannot resolve. See ViteStyles.
		ViteStyles::add('dashboard');
	}

	public function getWidgetOptions(): WidgetOptions {
		// Ticket rows carry a status glyph, not a person's avatar.
		return new WidgetOptions(roundItemIcons: false);
	}

	/**
	 * @return list<WidgetButton>
	 */
	public function getWidgetButtons(string $userId): array {
		$portal = $this->incidentService->getPortalUrl();
		if ($portal === '') {
			return [];
		}

		return [
			new WidgetButton(WidgetButton::TYPE_MORE, $portal, $this->l->t('All my tickets')),
		];
	}

	public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
		if (!$this->config->isConfigured()) {
			return new WidgetItems(
				emptyContentMessage: $this->l->t('TOPdesk is not set up on this server yet'),
			);
		}

		try {
			$result = $this->incidentService->listRecent($userId, $limit);
		} catch (\Throwable $e) {
			$this->logger->warning('Could not load TOPdesk tickets for the dashboard', [
				'user' => $userId,
				'exception' => $e,
			]);

			return new WidgetItems(
				emptyContentMessage: $this->l->t('Could not reach TOPdesk'),
			);
		}

		/** @var PersonLink $link */
		$link = $result['link'];

		$items = [];
		foreach ($result['incidents'] as $incident) {
			$items[] = new WidgetItem(
				title: $incident['briefDescription'] !== ''
					? $incident['briefDescription']
					: $incident['number'],
				subtitle: $this->subtitle($incident),
				link: $incident['url'],
				iconUrl: $this->getIconUrl(),
				// WidgetItem sorts and de-duplicates on this. creationDate is the
				// field the list is sorted by, so it is the right choice.
				sinceId: $incident['creationDate'],
			);
		}

		return new WidgetItems(
			items: $items,
			emptyContentMessage: $this->emptyMessage($link),
		);
	}

	/**
	 * @param array<string, mixed> $incident
	 */
	private function subtitle(array $incident): string {
		$parts = array_filter([
			(string)$incident['number'],
			(string)$incident['status'],
		], static fn (string $p): bool => $p !== '');

		return implode(' · ', $parts);
	}

	/**
	 * An empty widget should say which kind of empty it is. "No tickets" and "we
	 * could not work out who you are in TOPdesk" are very different problems, and
	 * only one of them is good news.
	 */
	private function emptyMessage(PersonLink $link): string {
		return match ($link->state) {
			PersonLink::STATE_NOT_CONFIGURED => $this->l->t('TOPdesk is not set up on this server yet'),
			PersonLink::STATE_NO_MATCH_VALUE => $this->l->t('Your account has no email address, so it cannot be matched to TOPdesk'),
			PersonLink::STATE_UNMATCHED => $this->l->t('No TOPdesk person found for your account'),
			PersonLink::STATE_AMBIGUOUS => $this->l->t('Several TOPdesk persons share your email address, so none was chosen'),
			default => $this->l->t('You have no tickets in TOPdesk'),
		};
	}
}
