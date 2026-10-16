<?php

declare(strict_types=1);

namespace OCA\IntegrationTopdesk\Service;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use OCA\IntegrationTopdesk\Exception\TopdeskException;
use OCA\IntegrationTopdesk\Service\Auth\AuthProviderFactory;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use Psr\Log\LoggerInterface;

/**
 * Low-level HTTP against one TOPdesk environment's /tas/api.
 *
 * Everything above this class works in arrays and never sees a header, a status
 * code or a Guzzle exception.
 *
 * Two TOPdesk quirks are absorbed here because forgetting either produces a bug
 * that looks like missing data rather than an error:
 *
 *   - **204 means "nothing matched"**, with an empty body rather than `[]`. A
 *     json_decode of that is null, so a caller that trusts the decode sees a
 *     failure where TOPdesk meant "no results".
 *   - **206 means "there are more pages"**, and it arrives *with* a usable body
 *     plus an `x-next-page-url` header. It is a success, not a partial failure;
 *     for a five-item widget we simply take the first page.
 */
class TopdeskClient {
	private const API_PREFIX = '/tas/api';
	private const TIMEOUT = 20;

	/**
	 * The persons endpoint is versioned through the Accept header. Without this,
	 * `/persons` serves the 1.x shape; with it, the 2.x shape, which is the one
	 * the current spec documents.
	 */
	public const ACCEPT_PERSONS_V2 = 'application/x.topdesk-collection-person-v2+json';

	public function __construct(
		private ConnectionConfig $config,
		private AuthProviderFactory $authFactory,
		private IClientService $clientService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param array<string, string|int|bool> $query
	 * @param array<string, string> $headers
	 *
	 * @return array<mixed> Decoded JSON, or [] when TOPdesk answered 204.
	 *
	 * @throws TopdeskException
	 */
	public function get(string $path, array $query = [], array $headers = []): array {
		return $this->request('get', $path, ['query' => $query], $headers);
	}

	/**
	 * @param array<string, mixed> $body
	 * @param array<string, string> $headers
	 *
	 * @return array<mixed>
	 *
	 * @throws TopdeskException
	 */
	public function post(string $path, array $body, array $headers = []): array {
		return $this->request('post', $path, ['json' => $body], $headers);
	}

	/**
	 * A cheap authenticated round-trip, for the admin page's "Test connection".
	 *
	 * @return array{version: string, productVersion: ?string}
	 *
	 * @throws TopdeskException
	 */
	public function ping(): array {
		$version = $this->get('/version');

		$product = null;
		try {
			$pv = $this->get('/productVersion');
			if (isset($pv['major'], $pv['minor'])) {
				$product = $pv['major'] . '.' . $pv['minor'] . '.' . ($pv['patch'] ?? 0);
			}
		} catch (TopdeskException $e) {
			// Informational only — a connection that can read /version is connected
			// whether or not it may read the product version.
			$this->logger->debug('TOPdesk productVersion unavailable', ['exception' => $e]);
		}

		return [
			'version' => (string)($version['version'] ?? 'unknown'),
			'productVersion' => $product,
		];
	}

	/**
	 * @param array<string, mixed> $options
	 * @param array<string, string> $headers
	 *
	 * @return array<mixed>
	 *
	 * @throws TopdeskException
	 */
	private function request(string $method, string $path, array $options, array $headers): array {
		$baseUrl = $this->config->getBaseUrl();
		if ($baseUrl === '') {
			throw new TopdeskException('No TOPdesk URL configured');
		}

		$url = $baseUrl . self::API_PREFIX . $path;
		$authProvider = $this->authFactory->get();

		$attempt = 0;
		while (true) {
			$attempt++;

			$options['headers'] = array_merge([
				'Accept' => 'application/json',
				'Authorization' => $authProvider->getAuthorizationHeader(),
			], $headers);
			$options['timeout'] = self::TIMEOUT;

			try {
				/** @var IResponse $response */
				$response = $this->clientService->newClient()->{$method}($url, $options);

				return $this->decode($response, $path);
			} catch (ClientException $e) {
				$status = $e->getResponse()->getStatusCode();

				// A 401 on an OAuth bearer token usually means the token died before
				// its advertised expiry. Drop it and try once with a fresh one. For an
				// application token there is nothing cached to refresh, so
				// invalidate() is a no-op and the second attempt would fail
				// identically — hence the mode check rather than a blind retry.
				if ($status === 401
					&& $attempt === 1
					&& $this->config->getAuthMode() === ConnectionConfig::AUTH_OAUTH_CC) {
					$authProvider->invalidate();
					continue;
				}

				throw $this->clientError($status, $path, $e);
			} catch (ServerException $e) {
				throw new TopdeskException(
					'TOPdesk returned a server error',
					$e->getResponse()->getStatusCode(),
					transient: true,
					previous: $e,
				);
			} catch (TopdeskException $e) {
				throw $e;
			} catch (\Throwable $e) {
				// DNS, TLS, timeout, connection refused — no response at all.
				$this->logger->warning('Could not reach TOPdesk', ['path' => $path, 'exception' => $e]);

				throw new TopdeskException('Could not reach TOPdesk', 0, transient: true, previous: $e);
			}
		}
	}

	/**
	 * @return array<mixed>
	 */
	private function decode(IResponse $response, string $path): array {
		$status = $response->getStatusCode();

		// 204: matched nothing, empty body. Not an error, and not decodable.
		if ($status === 204) {
			return [];
		}

		$body = (string)$response->getBody();
		if (trim($body) === '') {
			return [];
		}

		$decoded = json_decode($body, true);
		if (!is_array($decoded)) {
			$this->logger->error('TOPdesk returned a body that is not JSON', [
				'path' => $path,
				'status' => $status,
			]);

			throw new TopdeskException('TOPdesk returned an unreadable response', $status);
		}

		return $decoded;
	}

	private function clientError(int $status, string $path, ClientException $e): TopdeskException {
		// TOPdesk puts a useful sentence in the body on a 400. Surface it: it is the
		// difference between "something went wrong" and "brief description can only
		// be 80 characters long".
		$detail = $this->extractErrorDetail($e);

		$message = match ($status) {
			400 => $detail !== '' ? $detail : 'TOPdesk rejected the request',
			401 => 'TOPdesk rejected the credentials',
			403 => 'The TOPdesk API account is not permitted to do this',
			404 => 'Not found in TOPdesk',
			default => $detail !== '' ? $detail : 'TOPdesk returned an error',
		};

		$this->logger->warning('TOPdesk API error', [
			'path' => $path,
			'status' => $status,
			'detail' => $detail,
		]);

		return new TopdeskException($message, $status, transient: false, previous: $e);
	}

	private function extractErrorDetail(ClientException $e): string {
		try {
			$body = (string)$e->getResponse()->getBody();
		} catch (\Throwable) {
			return '';
		}

		$decoded = json_decode($body, true);
		if (!is_array($decoded)) {
			return '';
		}

		// Observed shapes: {"message": "..."} and
		// {"errors":[{"errorCode":"...","message":"..."}]}
		if (isset($decoded['message']) && is_string($decoded['message'])) {
			return $decoded['message'];
		}

		if (isset($decoded['errors']) && is_array($decoded['errors'])) {
			$messages = [];
			foreach ($decoded['errors'] as $error) {
				if (is_array($error) && isset($error['message']) && is_string($error['message'])) {
					$messages[] = $error['message'];
				}
			}
			if ($messages !== []) {
				return implode('; ', $messages);
			}
		}

		return '';
	}
}
