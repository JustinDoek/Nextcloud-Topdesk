<?php

declare(strict_types=1);

namespace OCA\IntegrationTopdesk\Service;

use OCA\IntegrationTopdesk\AppInfo\Application;
use OCP\IAppConfig;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * The admin-level connection to one TOPdesk SaaS environment.
 *
 * Secrets (application token, OAuth client secret, cached access token) go through
 * ICrypto before they reach appconfig, so a database dump does not hand over the
 * customer's TOPdesk.
 */
class ConnectionConfig {
	public const AUTH_APP_TOKEN = 'apptoken';
	public const AUTH_OAUTH_CC = 'oauth_cc';

	/** Nextcloud side of the match. */
	public const NC_FIELD_EMAIL = 'email';
	public const NC_FIELD_UID = 'uid';

	/**
	 * TOPdesk side of the match. These are the person-card fields that can carry
	 * an identifier shared with Nextcloud; all four are queryable over FIQL.
	 * Reading `tasLoginName` needs the "Settings > Login Settings" permission on
	 * the API operator, the other three do not.
	 */
	public const TD_FIELDS = ['email', 'networkLoginName', 'employeeNumber', 'tasLoginName'];

	private const SECRET_KEYS = ['app_token', 'client_secret'];

	public function __construct(
		private IAppConfig $appConfig,
		private ICrypto $crypto,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Base URL of the TOPdesk environment, with no trailing slash and no /tas/api
	 * suffix — e.g. https://acme.topdesk.net
	 */
	public function getBaseUrl(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'topdesk_url', '');
	}

	public function getAuthMode(): string {
		$mode = $this->appConfig->getValueString(Application::APP_ID, 'auth_mode', self::AUTH_APP_TOKEN);

		return $mode === self::AUTH_OAUTH_CC ? self::AUTH_OAUTH_CC : self::AUTH_APP_TOKEN;
	}

	public function getLoginName(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'login_name', '');
	}

	public function getAppToken(): string {
		return $this->readSecret('app_token');
	}

	public function getClientId(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'client_id', '');
	}

	public function getClientSecret(): string {
		return $this->readSecret('client_secret');
	}

	public function getNcMatchField(): string {
		$field = $this->appConfig->getValueString(Application::APP_ID, 'match_nc_field', self::NC_FIELD_EMAIL);

		return $field === self::NC_FIELD_UID ? self::NC_FIELD_UID : self::NC_FIELD_EMAIL;
	}

	public function getTopdeskMatchField(): string {
		$field = $this->appConfig->getValueString(Application::APP_ID, 'match_td_field', 'email');

		return in_array($field, self::TD_FIELDS, true) ? $field : 'email';
	}

	/**
	 * Seconds a resolved person link is trusted without re-checking it against
	 * TOPdesk. The login listener re-checks regardless; this only bounds how
	 * stale a long-lived session can get.
	 */
	public function getLinkTtl(): int {
		return max(60, $this->appConfig->getValueInt(Application::APP_ID, 'link_ttl', 3600));
	}

	/**
	 * Whether to write the full matching trace to the Nextcloud log.
	 *
	 * Off by default, and deliberately not on by default even at debug level: the
	 * trace contains the address or username being matched, which is personal data,
	 * and the whole point of it is that the value is legible enough to spot a typo
	 * or a stray space in. An admin turns this on knowing that, troubleshoots, and
	 * turns it off again. `occ topdesk:diagnose` needs none of it — it prints the
	 * same trace to the console without touching the log.
	 */
	public function isMatchDebugEnabled(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, 'debug_matching', false);
	}

	/**
	 * True when enough is configured to attempt a call. It does not mean the
	 * credentials are valid — only "Test connection" establishes that.
	 */
	public function isConfigured(): bool {
		if ($this->getBaseUrl() === '') {
			return false;
		}

		if ($this->getAuthMode() === self::AUTH_OAUTH_CC) {
			return $this->getClientId() !== '' && $this->getClientSecret() !== '';
		}

		return $this->getLoginName() !== '' && $this->getAppToken() !== '';
	}

	/**
	 * Apply a partial settings update.
	 *
	 * A secret arriving as the empty string means "leave what is stored alone",
	 * which is what lets the admin form round-trip without ever sending the
	 * current token back down to the browser.
	 *
	 * @param array<string, string|int|bool> $values
	 */
	public function update(array $values): void {
		foreach ($values as $key => $value) {
			if ($key === 'topdesk_url') {
				$this->appConfig->setValueString(Application::APP_ID, $key, $this->normaliseUrl((string)$value));
				// A different environment makes any token minted against the old one useless.
				$this->clearCachedOAuthToken();
				continue;
			}

			if (in_array($key, self::SECRET_KEYS, true)) {
				if ((string)$value === '') {
					continue;
				}
				$this->appConfig->setValueString(
					Application::APP_ID,
					$key,
					$this->crypto->encrypt((string)$value),
					sensitive: true,
				);
				$this->clearCachedOAuthToken();
				continue;
			}

			if ($key === 'auth_mode') {
				$this->appConfig->setValueString(Application::APP_ID, $key, (string)$value);
				$this->clearCachedOAuthToken();
				continue;
			}

			if ($key === 'link_ttl') {
				$this->appConfig->setValueInt(Application::APP_ID, $key, (int)$value);
				continue;
			}

			if ($key === 'debug_matching') {
				$this->appConfig->setValueBool(Application::APP_ID, $key, (bool)$value);
				continue;
			}

			$this->appConfig->setValueString(Application::APP_ID, $key, (string)$value);
		}
	}

	/**
	 * Strip a trailing slash and a pasted-in /tas/api, and reject anything that is
	 * not an http(s) URL.
	 *
	 * Admins paste what is in front of them, which is usually their TOPdesk address
	 * bar or a line out of the API docs. Both are accepted here so the field does
	 * not become a guessing game. Returns '' for input we cannot make sense of,
	 * which reads downstream as "not configured".
	 */
	public function normaliseUrl(string $url): string {
		$url = trim($url);
		if ($url === '') {
			return '';
		}

		if (!preg_match('#^https?://#i', $url)) {
			$url = 'https://' . $url;
		}

		$parts = parse_url($url);
		if ($parts === false || ($parts['host'] ?? '') === '') {
			return '';
		}

		$scheme = strtolower($parts['scheme'] ?? 'https');
		if (!in_array($scheme, ['http', 'https'], true)) {
			return '';
		}

		$base = $scheme . '://' . strtolower($parts['host']);
		if (isset($parts['port'])) {
			$base .= ':' . $parts['port'];
		}

		// Keep a real sub-path (some on-premise installs live under one) but drop
		// the API prefix if it came along with the paste.
		$path = rtrim($parts['path'] ?? '', '/');
		$path = preg_replace('#/tas(/api)?$#i', '', $path) ?? '';

		return $base . $path;
	}

	// ── cached OAuth access token ────────────────────────────────────────────

	public function getCachedOAuthToken(): ?string {
		$expiry = $this->appConfig->getValueInt(Application::APP_ID, 'oauth_expires_at', 0);
		// Renew a little early rather than racing the expiry.
		if ($expiry <= time() + 30) {
			return null;
		}

		$token = $this->readSecret('oauth_access_token');

		return $token === '' ? null : $token;
	}

	public function setCachedOAuthToken(string $token, int $expiresIn): void {
		$this->appConfig->setValueString(
			Application::APP_ID,
			'oauth_access_token',
			$this->crypto->encrypt($token),
			sensitive: true,
		);
		$this->appConfig->setValueInt(Application::APP_ID, 'oauth_expires_at', time() + max(0, $expiresIn));
	}

	public function clearCachedOAuthToken(): void {
		$this->appConfig->deleteKey(Application::APP_ID, 'oauth_access_token');
		$this->appConfig->deleteKey(Application::APP_ID, 'oauth_expires_at');
	}

	/**
	 * Everything the admin page needs, with secrets reduced to a boolean.
	 *
	 * @return array<string, mixed>
	 */
	public function toFrontendArray(): array {
		return [
			'topdesk_url' => $this->getBaseUrl(),
			'auth_mode' => $this->getAuthMode(),
			'login_name' => $this->getLoginName(),
			'has_app_token' => $this->getAppToken() !== '',
			'client_id' => $this->getClientId(),
			'has_client_secret' => $this->getClientSecret() !== '',
			'match_nc_field' => $this->getNcMatchField(),
			'match_td_field' => $this->getTopdeskMatchField(),
			'debug_matching' => $this->isMatchDebugEnabled(),
			'configured' => $this->isConfigured(),
		];
	}

	private function readSecret(string $key): string {
		$stored = $this->appConfig->getValueString(Application::APP_ID, $key, '');
		if ($stored === '') {
			return '';
		}

		try {
			return $this->crypto->decrypt($stored);
		} catch (\Throwable $e) {
			// Typically a changed instance secret. Say so in the log; returning ''
			// makes isConfigured() false, which surfaces as "not connected" rather
			// than as a stream of 401s nobody can explain.
			$this->logger->error('Could not decrypt the stored TOPdesk ' . $key . '; re-enter it in the admin settings', [
				'exception' => $e,
			]);

			return '';
		}
	}
}
