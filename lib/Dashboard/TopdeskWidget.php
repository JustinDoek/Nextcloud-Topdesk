<?php
declare(strict_types=1);

namespace OCA\TopdeskIntegration\Dashboard;

use OCA\TopdeskIntegration\Service\TopdeskService;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\IReloadableWidget;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

class TopdeskWidget implements IIconWidget, IReloadableWidget {
    private IL10N $l10n;
    private TopdeskService $topdeskService;
    private IURLGenerator $urlGenerator;
    private IConfig $config;
    private LoggerInterface $logger;

    public function __construct(
        IL10N $l10n,
        TopdeskService $topdeskService,
        IURLGenerator $urlGenerator,
        IConfig $config,
        LoggerInterface $logger
    ) {
        $this->l10n = $l10n;
        $this->topdeskService = $topdeskService;
        $this->urlGenerator = $urlGenerator;
	$this->config = $config;
        $this->logger = $logger;
    }

    public function getId(): string {
        return 'topdesk_integration';
    }

    public function getTitle(): string {
        return $this->l10n->t('Topdesk Incidents');
    }

    public function getOrder(): int {
        return 10;
    }

    public function getIconClass(): string {
        return 'icon-wrench';
    }

    public function getIconUrl(): string {
        return $this->urlGenerator->getAbsoluteURL(
            $this->urlGenerator->imagePath('topdesk_integration', 'app.svg')
        );
    }

    public function getUrl(): ?string {
       $newIncidentUrl = $this->config->getAppValue('topdesk_integration', 'new_incident_url', '');
	return !empty($newIncidentUrl) ? $newIncidentUrl : null;
    }

    public function load(): void {
    }

public function getItemsV2(string $userId, ?string $since = null, int $limit = 5): WidgetItems {
    $baseUrl = rtrim($this->config->getAppValue('topdesk_integration', 'base_url', ''), '/');
    $newIncidentUrl = $this->config->getAppValue('topdesk_integration', 'new_incident_url', '');
    $incidents = $this->topdeskService->getUserIncidents($userId);

    $widgetItems = [];
    
    foreach ($incidents as $incident) {
        $incidentId = $incident['id'] ?? '';
        $creationDate = '';
        
        if (!empty($incident['creationDate'])) {
            $dateTime = new \DateTime($incident['creationDate']);
            $creationDate = $dateTime->format('Y-m-d');
        }
        
        $isClosed = strtolower((string)$incident['closed']) === 'true' || $incident['closed'] === true;
        $statusIcon = $this->getStatusIconUrl($isClosed);
        $secondLine = sprintf('%s | %s', $incident['number'] ?? 'unknown', $creationDate);

        $widgetItems[] = new WidgetItem(
            $incident['briefDescription'] ?? $this->l10n->t('Geen omschrijving'),
            $secondLine,
            $incidentId ? "$baseUrl/tas/public/ssp/content/detail/incident?unid=$incidentId" : '',
            $statusIcon,
            $incidentId
        );
    }

    if (!empty($newIncidentUrl)) {
        $widgetItems[] = new WidgetItem(
            $this->l10n->t('Nieuw incident aanmaken'),
            '', 
            $newIncidentUrl,
            $this->urlGenerator->imagePath('core', 'actions/add.svg'),
            'new-incident-btn'  
        );
    }

    $emptyText = empty($incidents) ? $this->l10n->t('Geen open incidents') : '';
    return new WidgetItems($widgetItems, $emptyText);
}


private function getStatusIconUrl(bool $isClosed): string {
    $isDarkTheme = false; // Of gebruik: $this->config->getSystemValueBool('theme', false);
    
    if ($isClosed) {
        $iconFile = $isDarkTheme ? 'closed-dark.svg' : 'closed.svg';
    } else {
        $iconFile = $isDarkTheme ? 'open-dark.svg' : 'open.svg';
    }
    
    return $this->urlGenerator->getAbsoluteURL(
        $this->urlGenerator->imagePath('topdesk_integration', $iconFile)
    );
}

    public function getReloadInterval(): int {
        return 600;
    }
}

