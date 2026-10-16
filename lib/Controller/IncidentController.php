<?php

declare(strict_types=1);

namespace OCA\IntegrationTopdesk\Controller;

use OCA\IntegrationTopdesk\Exception\TopdeskException;
use OCA\IntegrationTopdesk\Service\IncidentService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Tickets for the dashboard widget.
 *
 * The user id comes from the session, never from the request — so this controller
 * cannot be asked for somebody else's tickets.
 */
class IncidentController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IncidentService $incidentService,
		private IL10N $l,
		private LoggerInterface $logger,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(int $limit = 5): DataResponse {
		if ($this->userId === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$result = $this->incidentService->listRecent($this->userId, $limit);
		} catch (TopdeskException $e) {
			return new DataResponse(
				['message' => $e->getMessage()],
				$e->isTransient() ? Http::STATUS_SERVICE_UNAVAILABLE : Http::STATUS_BAD_GATEWAY,
			);
		}

		return new DataResponse([
			'link' => $result['link'],
			'incidents' => $result['incidents'],
			'portalUrl' => $this->incidentService->getPortalUrl(),
		]);
	}

	#[NoAdminRequired]
	/**
	 * `$details` rather than `$request`, which is what TOPdesk calls this field:
	 * a controller method parameter is resolved by name out of the request, and a
	 * parameter called `request` sitting next to `$this->request` is a confusion
	 * waiting to happen. IncidentService maps it back to TOPdesk's name.
	 */
	public function create(
		string $briefDescription,
		string $details = '',
		?string $categoryId = null,
		?string $subcategoryId = null,
	): DataResponse {
		if ($this->userId === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$incident = $this->incidentService->create(
				$this->userId,
				$briefDescription,
				$details,
				$categoryId,
				$subcategoryId,
			);
		} catch (TopdeskException $e) {
			// 400 and 409 are the user's to fix (too long a description, no link).
			// Anything else is the integration's problem, so log it and say so
			// plainly rather than blaming the form.
			$status = match ($e->getHttpStatus()) {
				400 => Http::STATUS_BAD_REQUEST,
				409 => Http::STATUS_CONFLICT,
				default => Http::STATUS_BAD_GATEWAY,
			};

			if ($status === Http::STATUS_BAD_GATEWAY) {
				$this->logger->error('Could not create a TOPdesk incident', [
					'user' => $this->userId,
					'exception' => $e,
				]);
			}

			return new DataResponse(['message' => $e->getMessage()], $status);
		}

		return new DataResponse($incident, Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	public function formOptions(): DataResponse {
		if ($this->userId === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		return new DataResponse($this->incidentService->getFormOptions() + [
			'briefDescriptionMax' => IncidentService::BRIEF_DESCRIPTION_MAX,
		]);
	}
}
