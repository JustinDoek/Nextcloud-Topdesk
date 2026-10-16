<?php

declare(strict_types=1);

namespace OCA\IntegrationTopdesk\Exception;

/**
 * A TOPdesk call did not produce a usable answer.
 *
 * `$httpStatus` is 0 when we never got a response at all (DNS, TLS, timeout).
 * `$transient` separates "ask again later and it may work" (network blip, 5xx,
 * expired token) from "this keeps failing until a human changes something"
 * (401, 403, 404, 400). The person resolver leans on that distinction: it must
 * never drop a user's stored TOPdesk link because the network hiccuped.
 */
class TopdeskException extends \RuntimeException {
	public function __construct(
		string $message,
		private int $httpStatus = 0,
		private bool $transient = false,
		?\Throwable $previous = null,
	) {
		parent::__construct($message, $httpStatus, $previous);
	}

	public function getHttpStatus(): int {
		return $this->httpStatus;
	}

	public function isTransient(): bool {
		return $this->transient;
	}
}
