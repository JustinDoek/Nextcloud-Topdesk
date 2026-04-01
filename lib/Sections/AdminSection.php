<?php
declare(strict_types=1);

namespace OCA\TopdeskIntegration\Sections;

use OCP\Settings\IIconSection;
use OCP\IURLGenerator;
use OCP\IL10N;

class AdminSection implements IIconSection {
    private IL10N $l10n;
    private IURLGenerator $urlGenerator;

    public function __construct(IL10N $l10n, IURLGenerator $urlGenerator) {
        $this->l10n = $l10n;
        $this->urlGenerator = $urlGenerator;
    }

    public function getID(): string {
        return 'topdesk_integration';
    }

    public function getName(): string {
        return $this->l10n->t('Topdesk Integration');
    }

    public function getPriority(): int {
        return 10;
    }

    public function getSectionId(): string {
        return 'topdesk_integration';
    }

    public function getIcon(): string {
        return $this->urlGenerator->imagePath('topdesk_integration', 'app.svg');
    }
}
