<?php

declare(strict_types=1);

namespace OCA\IntegrationTopdesk\Controller;

use OCA\IntegrationTopdesk\Exception\TopdeskException;
use OCA\IntegrationTopdesk\Service\ConnectionConfig;
use OCA\IntegrationTopdesk\Service\TopdeskClient;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * The admin connection settings.
 *
 * Every route here is admin-only, which is the framework default — there is
 * deliberately no NoAdminRequired anywhere in this class.
 */
class AdminController extends Controller {
	/** Keys the admin form may write. Anything else in the payload is ignored. */
	private const ALLOWED_KEYS = [
		'topdesk_url',
		'auth_mode',
		'login_name',
		'app_token',
		'client_id',
		'client_secret',
		'match_nc_field',
		'match_td_field',
		'link_ttl',
		'debug_matching',
	];

	public function __construct(
		string $appName,
		IRequest $request,
		private ConnectionConfig $config,
		private TopdeskClient $client,
		private IL10N $l,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Save a partial settings update.
	 *
	 * Secrets sent as '' mean "keep what is stored", so the form never has to
	 * receive the current token in order to save an unrelated field.
	 *
	 * @param array<string, string|int|bool> $values
	 */
	public function setConfig(array $values): DataResponse {
		$filtered = [];
		foreach ($values as $key => $value) {
			// bool is allowed because debug_matching arrives from JSON as one.
			if (in_array($key, self::ALLOWED_KEYS, true)
				&& (is_string($value) || is_int($value) || is_bool($value))) {
				$filtered[$key] = $value;
			}
		}

		if (isset($filtered['auth_mode'])
			&& !in_array($filtered['auth_mode'], [ConnectionConfig::AUTH_APP_TOKEN, ConnectionConfig::AUTH_OAUTH_CC], true)) {
			return new DataResponse(
				['message' => $this->l->t('Unknown authentication mode')],
				Http::STATUS_BAD_REQUEST,
			);
		}

		if (isset($filtered['match_td_field'])
			&& !in_array($filtered['match_td_field'], ConnectionConfig::TD_FIELDS, true)) {
			return new DataResponse(
				['message' => $this->l->t('Unknown TOPdesk match field')],
				Http::STATUS_BAD_REQUEST,
			);
		}

		// Catch an unusable URL here rather than letting it fail later as a vague
		// "could not reach TOPdesk".
		if (isset($filtered['topdesk_url'])
			&& trim((string)$filtered['topdesk_url']) !== ''
			&& $this->config->normaliseUrl((string)$filtered['topdesk_url']) === '') {
			return new DataResponse(
				['message' => $this->l->t('That does not look like a TOPdesk address')],
				Http::STATUS_BAD_REQUEST,
			);
		}

		$this->config->update($filtered);

		return new DataResponse($this->config->toFrontendArray());
	}

	/**
	 * Make a real authenticated call against the saved connection.
	 *
	 * Tests what is stored, not what is in the form — so the admin page saves
	 * first and then tests, and a green result means the connection that the
	 * dashboard will use actually works.
	 */
	public function testConnection(): DataResponse {
		if (!$this->config->isConfigured()) {
			return new DataResponse([
				'success' => false,
				'message' => $this->l->t('Fill in the URL and the credentials first'),
			]);
		}

		try {
			$result = $this->client->ping();
		} catch (TopdeskException $e) {
			return new DataResponse([
				'success' => false,
				'message' => $e->getMessage(),
				'status' => $e->getHttpStatus(),
				'hint' => $this->hintFor($e),
			]);
		}

		return new DataResponse([
			'success' => true,
			'message' => $this->l->t('Connected to TOPdesk'),
			'apiVersion' => $result['version'],
			'productVersion' => $result['productVersion'],
		]);
	}

	/**
	 * The failures here have specific, well-known causes in TOPdesk's permission
	 * model. Naming them saves an admin a support ticket about a support tool.
	 */
	private function hintFor(TopdeskException $e): string {
		return match ($e->getHttpStatus()) {
			401 => $this->config->getAuthMode() === ConnectionConfig::AUTH_OAUTH_CC
				? $this->l->t('Check the client id and secret, and that the connected application is allowed the client credentials grant.')
				: $this->l->t('Check the login name and application token. The token is shown only once when it is created, and it expires — one year after creation by default.'),
			403 => $this->l->t('The account reached TOPdesk but is not allowed in. Its permission group needs read on "API access > REST API" and write on "API access > Use application tokens".'),
			404 => $this->l->t('The URL was reached but has no TOPdesk API on it. Check for a typo in the environment name.'),
			0 => $this->l->t('TOPdesk could not be reached at all. Check the URL, and whether this server is allowed to make outgoing connections to it.'),
			default => '',
		};
	}
}
