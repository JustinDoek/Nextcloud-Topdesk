<?php

declare(strict_types=1);

namespace OCA\IntegrationTopdesk\Service\Auth;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use OCA\IntegrationTopdesk\Exception\TopdeskException;
use OCA\IntegrationTopdesk\Service\ConnectionConfig;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * OAuth 2.0 client credentials against a TOPdesk "Application integration".
 *
 * The admin registers the client under
 * Modules > Supporting Files > System integrations overview > Connected applications
 * and gets a client id and a one-time secret. Access tokens are short-lived (900 s by
 * default) and this grant issues no refresh token, so we simply mint a new one when
 * the cached one is near expiry.
 *
 * The token endpoint lives on the host root, not under /tas/api, and takes no
 * Authorization header of its own beyond the client's Basic credentials — so this
 * provider does its own HTTP rather than going through TopdeskClient, which would
 * be a dependency cycle.
 */
class OAuthClientCredentialsProvider implements IAuthProvider {
	public function __construct(
		private ConnectionConfig $config,
		private IClientService $clientService,
		private LoggerInterface $logger,
	) {
	}

	public function getAuthorizationHeader(): string {
		$cached = $this->config->getCachedOAuthToken();
		if ($cached !== null) {
			return 'Bearer ' . $cached;
		}

		return 'Bearer ' . $this->fetchToken();
	}

	public function invalidate(): void {
		$this->config->clearCachedOAuthToken();
	}

	private function fetchToken(): string {
		$baseUrl = $this->config->getBaseUrl();
		$clientId = $this->config->getClientId();
		$clientSecret = $this->config->getClientSecret();

		if ($baseUrl === '' || $clientId === '' || $clientSecret === '') {
			throw new TopdeskException('No TOPdesk OAuth client id and secret configured');
		}

		try {
			$response = $this->clientService->newClient()->post($baseUrl . '/oauth/token', [
				'headers' => [
					'Accept' => 'application/json',
					// client_secret_basic. The spec allows the credentials in the body
					// instead, but then the Authorization header has to be absent
					// entirely — sending both is an error.
					'Authorization' => 'Basic ' . base64_encode($clientId . ':' . $clientSecret),
				],
				'form_params' => [
					'grant_type' => 'client_credentials',
				],
				'timeout' => 15,
			]);

			$body = json_decode((string)$response->getBody(), true);
		} catch (ClientException $e) {
			// 400/401 from the token endpoint: wrong client id or secret, or the
			// client is not registered for this grant type. A human has to fix it.
			throw new TopdeskException(
				'TOPdesk refused the OAuth client credentials',
				$e->getResponse()->getStatusCode(),
				transient: false,
				previous: $e,
			);
		} catch (ServerException $e) {
			throw new TopdeskException(
				'The TOPdesk OAuth token endpoint returned a server error',
				$e->getResponse()->getStatusCode(),
				transient: true,
				previous: $e,
			);
		} catch (\Throwable $e) {
			throw new TopdeskException(
				'Could not reach the TOPdesk OAuth token endpoint',
				0,
				transient: true,
				previous: $e,
			);
		}

		if (!is_array($body) || !isset($body['access_token']) || !is_string($body['access_token'])) {
			throw new TopdeskException('The TOPdesk OAuth token endpoint returned no access token');
		}

		$token = $body['access_token'];
		$expiresIn = isset($body['expires_in']) ? (int)$body['expires_in'] : 900;
		$this->config->setCachedOAuthToken($token, $expiresIn);

		$this->logger->debug('Minted a new TOPdesk OAuth access token', ['expires_in' => $expiresIn]);

		return $token;
	}
}
