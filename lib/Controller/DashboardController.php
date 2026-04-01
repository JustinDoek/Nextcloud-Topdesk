<?php
declare(strict_types=1);

namespace OCA\TopdeskIntegration\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCA\TopdeskIntegration\Service\TopdeskService;

class DashboardController extends Controller {
    private TopdeskService $topdeskService;

    public function __construct(string $appName, IRequest $request, TopdeskService $topdeskService) {
        parent::__construct($appName, $request);
        $this->topdeskService = $topdeskService;
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function data(): DataResponse {
        $incidents = $this->topdeskService->getUserIncidents();
        return new DataResponse([
            'incidents' => $incidents,
            'newIncidentUrl' => $this->topdeskService->getNewIncidentUrl()
        ]);
    }
}