<?php

declare(strict_types=1);

namespace OCA\IntegrationTopdesk\Service\Auth;

use OCA\IntegrationTopdesk\Exception\TopdeskException;
use OCA\IntegrationTopdesk\Service\ConnectionConfig;

/**
 * HTTP Basic with a TOPdesk application token.
 *
 * The token is created either on an operator's own card (My settings) or on an API
 * account card, and the account needs the permission group entries
 * "API access > REST API" (read) and "API access > Use application tokens" (write).
 * It is shown once at creation and carries an expiry date, one year out by default —
 * which is the one operational gotcha of this mode: the integration stops working on
 * a date nobody wrote down.
 */
class AppTokenAuthProvider implements IAuthProvider {
	public function __construct(
		private ConnectionConfig $config,
	) {
	}

	public function getAuthorizationHeader(): string {
		$login = $this->config->getLoginName();
		$token = $this->config->getAppToken();

		if ($login === '' || $token === '') {
			throw new TopdeskException('No TOPdesk login name and application token configured');
		}

		return 'Basic ' . base64_encode($login . ':' . $token);
	}

	public function invalidate(): void {
		// Nothing is cached: the header is derived from the stored credential every
		// time. A 401 here means the credential itself is wrong or expired, and a
		// retry would reproduce it, which is why the client does not retry this mode.
	}
}
