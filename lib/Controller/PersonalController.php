<?php

declare(strict_types=1);

namespace OCA\IntegrationTopdesk\Controller;

use OCA\IntegrationTopdesk\Exception\TopdeskException;
use OCA\IntegrationTopdesk\Service\PersonResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * The signed-in user's own TOPdesk link.
 *
 * Every route acts on $this->userId and never takes a user id from the request, so
 * one user cannot inspect or repoint another user's link.
 */
class PersonalController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private PersonResolver $resolver,
		private IL10N $l,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function getLink(): DataResponse {
		if ($this->userId === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		return new DataResponse($this->resolver->resolve($this->userId));
	}

	/**
	 * Re-check the link now, rather than waiting for the next login.
	 */
	#[NoAdminRequired]
	public function refreshLink(): DataResponse {
		if ($this->userId === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		return new DataResponse($this->resolver->resolve($this->userId, force: true));
	}

	/**
	 * Pin a TOPdesk person by hand.
	 *
	 * For the cases automatic matching cannot settle: a shared functional mailbox,
	 * a contractor whose Nextcloud address differs from their person card, or two
	 * person cards sharing an address.
	 */
	#[NoAdminRequired]
	public function pinLink(string $personId): DataResponse {
		if ($this->userId === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		$personId = trim($personId);
		if ($personId === '') {
			return new DataResponse(
				['message' => $this->l->t('No person selected')],
				Http::STATUS_BAD_REQUEST,
			);
		}

		try {
			return new DataResponse($this->resolver->pin($this->userId, $personId));
		} catch (TopdeskException $e) {
			return new DataResponse(
				['message' => $e->getMessage()],
				$e->getHttpStatus() === 404 ? Http::STATUS_NOT_FOUND : Http::STATUS_BAD_GATEWAY,
			);
		}
	}

	#[NoAdminRequired]
	public function unpinLink(): DataResponse {
		if ($this->userId === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new DataResponse($this->resolver->unpin($this->userId));
		} catch (TopdeskException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		}
	}

	/**
	 * Type-ahead for the pin picker.
	 */
	#[NoAdminRequired]
	public function searchPersons(string $term = ''): DataResponse {
		if ($this->userId === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new DataResponse(['results' => $this->resolver->searchCallers($term)]);
		} catch (TopdeskException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		}
	}
}
