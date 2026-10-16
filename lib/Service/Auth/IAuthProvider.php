<?php

declare(strict_types=1);

namespace OCA\IntegrationTopdesk\Service\Auth;

/**
 * Supplies the Authorization header for a TOPdesk API call.
 *
 * TOPdesk offers two credential shapes that suit a server-to-server integration,
 * and which one a customer can use depends on their TOPdesk: application tokens
 * work on every 7.11.005+, OAuth connected applications only on releases that
 * have the "Connected applications" screen. Both end up as one header, so the
 * rest of the app never has to know which is in play.
 */
interface IAuthProvider {
	/**
	 * e.g. "Basic dXNlcjp0b2tlbg==" or "Bearer eyJhbGci..."
	 *
	 * @throws \OCA\IntegrationTopdesk\Exception\TopdeskException when no credential
	 *   can be produced — for OAuth that includes the token endpoint refusing the
	 *   client id and secret.
	 */
	public function getAuthorizationHeader(): string;

	/**
	 * Throw away anything cached, so the next call mints a fresh credential.
	 *
	 * Called after a 401, which for OAuth means the access token died earlier than
	 * its advertised lifetime, and for an application token means the credential is
	 * genuinely wrong (nothing to clear, and the retry will fail the same way).
	 */
	public function invalidate(): void;
}
