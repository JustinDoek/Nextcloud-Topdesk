<?php

declare(strict_types=1);

namespace OCA\IntegrationTopdesk\Settings;

use OCA\IntegrationTopdesk\AppInfo\Application;
use OCA\IntegrationTopdesk\Service\ConnectionConfig;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\ISettings;

class AdminSettings implements ISettings {
	public function __construct(
		private IInitialState $initialState,
		private ConnectionConfig $config,
	) {
	}

	public function getForm(): TemplateResponse {
		// toFrontendArray() reduces every secret to a boolean, so the stored
		// application token and client secret never reach the browser.
		$this->initialState->provideInitialState('admin-config', $this->config->toFrontendArray());
		$this->initialState->provideInitialState('td-match-fields', ConnectionConfig::TD_FIELDS);

		return new TemplateResponse(Application::APP_ID, 'admin');
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 10;
	}
}
