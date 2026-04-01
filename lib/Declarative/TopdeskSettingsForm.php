<?php
declare(strict_types=1);

namespace OCA\TopdeskIntegration\Declarative;

use OCP\IConfig;
use OCP\IL10N;
use OCP\Settings\IDeclarativeSettingsForm;
use OCP\Settings\DeclarativeSettingsTypes;

class TopdeskSettingsForm implements IDeclarativeSettingsForm {
    private IConfig $config;
    private IL10N $l10n;

    public function __construct(IConfig $config, IL10N $l10n) {
        $this->config = $config;
        $this->l10n = $l10n;
    }

    public function getId(): string {
        // unieke id voor je form/schema
        return 'topdesk_integration-settings-form';
    }

    public function getName(): string {
        return $this->l10n->t('Topdesk Settings');
    }

    public function getSection(): string {
        return 'topdesk_integration';
    }

    public function getSchema(): array {
        return [
            'id' => $this->getId(),
            'priority' => 10,
            'section_type' => DeclarativeSettingsTypes::SECTION_TYPE_ADMIN,
            'section_id' => 'topdesk_integration',
            'storage_type' => DeclarativeSettingsTypes::STORAGE_TYPE_INTERNAL,
            'title' => $this->l10n->t('Topdesk Settings'),
            'description' => $this->l10n->t('Configure Topdesk settings. See the github page for more information'),
            'fields' => [
                [
                    'id' => 'base_url',
                    'title' => $this->l10n->t('Topdesk Base URL'),
                    'description' => $this->l10n->t('Format: https://company.topdesk.net'),
                    'type' => DeclarativeSettingsTypes::URL,
                    'placeholder' => 'https://company.topdesk.net',
                ],
                [
                    'id' => 'app_user',
                    'title' => $this->l10n->t('Application User'),
                    'type' => DeclarativeSettingsTypes::TEXT,
		    'description' => $this->l10n->t('Use the Topdesk operator loginname')
                ],
                [
                    'id' => 'app_token',
                    'title' => $this->l10n->t('Application Token'),
                    'type' => DeclarativeSettingsTypes::PASSWORD,
                    'sensitive' => false,
		    'description'=> $this->l10n->t('Generate an application token from the operators settings'),
                ],
                [
                    'id' => 'new_incident_url',
                    'title' => $this->l10n->t('URL new incident. (optional)'),
                    'type' => DeclarativeSettingsTypes::URL,
		    'description' => $this->l10n->t('Add the full url for creating a new incident. This will add the option to create an incident from the widget'), 
                ],
            ],
        ];
    }
}
