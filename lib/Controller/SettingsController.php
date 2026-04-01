<?php

declare(strict_types=1);

namespace OCA\TopdeskIntegration\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IRequest;
use OCP\IConfig;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;

class SettingsController extends Controller {
    private IConfig $config;

    public function __construct(string $appName, IRequest $request, IConfig $config) {
        parent::__construct($appName, $request);
        $this->config = $config;
    }

    /**
     * @AuthorizedAdminSetting(settings=OCA\TopdeskIntegration\Settings\Admin)
     * @NoCSRFRequired
     */
    public function index(): TemplateResponse {
        return new TemplateResponse('topdesk_integration', 'admin', [
            'baseUrl' => $this->config->getAppValue('topdesk_integration', 'base_url', ''),
            'appUser' => $this->config->getAppValue('topdesk_integration', 'app_user', ''),
            'appToken' => $this->config->getAppValue('topdesk_integration', 'app_token', ''),
            'newUrl' => $this->config->getAppValue('topdesk_integration', 'new_incident_url', '')
        ]);
    }

    /**
     * @AuthorizedAdminSetting(settings=OCA\TopdeskIntegration\Settings\Admin)
     * @NoCSRFRequired
     */
    public function save(): RedirectResponse {
        $this->config->setAppValue('topdesk_integration', 'base_url', $this->request->getParam('base_url', ''));
        $this->config->setAppValue('topdesk_integration', 'app_user', $this->request->getParam('app_user', ''));
        $this->config->setAppValue('topdesk_integration', 'app_token', $this->request->getParam('app_token', ''));
        $this->config->setAppValue('topdesk_integration', 'new_incident_url', $this->request->getParam('new_incident_url', ''));

        return new RedirectResponse('apps/topdesk_integration/settings');
    }
}
