<?php

declare(strict_types=1);

namespace OCA\IntegrationTopdesk\Settings;

use OCA\IntegrationTopdesk\AppInfo\Application;
use OCA\IntegrationTopdesk\Service\ConnectionConfig;
use OCA\IntegrationTopdesk\Service\PersonResolver;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\ISettings;

/**
 * Shows the user which TOPdesk person they are matched to, and lets them fix it.
 *
 * This page exists because automatic matching is right most of the time and wrong
 * visibly: if it picks nobody, or two cards share an address, the dashboard widget
 * can only say "no tickets". Somewhere has to say *why*, and offer a way out.
 */
class PersonalSettings implements ISettings {
	public function __construct(
		private IInitialState $initialState,
		private ConnectionConfig $config,
		private PersonResolver $resolver,
		private ?string $userId,
	) {
	}

	public function getForm(): TemplateResponse {
		$link = $this->userId === null ? null : $this->resolver->resolve($this->userId);

		$this->initialState->provideInitialState('personal-link', $link);
		$this->initialState->provideInitialState('configured', $this->config->isConfigured());

		return new TemplateResponse(Application::APP_ID, 'personal');
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 10;
	}
}
