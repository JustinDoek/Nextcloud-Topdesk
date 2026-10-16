<?php

declare(strict_types=1);

namespace OCA\IntegrationTopdesk\Service\Auth;

use OCA\IntegrationTopdesk\Service\ConnectionConfig;

/**
 * Picks the credential shape the admin configured.
 *
 * Resolved per call rather than wired once in the container, so switching the
 * auth mode in the admin settings takes effect without a restart — and so the
 * "Test connection" button can try a mode that is not the saved one.
 */
class AuthProviderFactory {
	public function __construct(
		private ConnectionConfig $config,
		private AppTokenAuthProvider $appToken,
		private OAuthClientCredentialsProvider $oauth,
	) {
	}

	public function get(?string $mode = null): IAuthProvider {
		$mode ??= $this->config->getAuthMode();

		return $mode === ConnectionConfig::AUTH_OAUTH_CC ? $this->oauth : $this->appToken;
	}
}
