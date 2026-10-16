<?php

declare(strict_types=1);

namespace OCA\IntegrationTopdesk\Service;

/**
 * One Nextcloud user's link to one TOPdesk person card.
 *
 * The pair that matters is ($matchValue, $personId) — the identifier we matched on
 * *and* the person UUID it resolved to. Storing only the identifier would be enough
 * to find tickets, but not enough to notice that the identifier now belongs to
 * somebody else. See PersonResolver for why that case is the whole point.
 */
class PersonLink implements \JsonSerializable {
	/** Matched exactly one active TOPdesk person. Tickets can be read. */
	public const STATE_LINKED = 'linked';

	/** No admin connection to TOPdesk yet. */
	public const STATE_NOT_CONFIGURED = 'not_configured';

	/** The Nextcloud side has nothing to match on (e.g. no email address set). */
	public const STATE_NO_MATCH_VALUE = 'no_match_value';

	/** Nobody in TOPdesk carries this identifier any more. */
	public const STATE_UNMATCHED = 'unmatched';

	/** Several active TOPdesk persons carry it. We refuse to guess. */
	public const STATE_AMBIGUOUS = 'ambiguous';

	/** A human chose this person by hand; the resolver leaves it alone. */
	public const STATE_PINNED = 'pinned';

	/**
	 * We hold a link but could not re-check it, because TOPdesk was unreachable or
	 * erroring. The link is still used — a network blip is not evidence that
	 * somebody changed identity.
	 */
	public const STATE_UNVERIFIED = 'unverified';

	public function __construct(
		public readonly string $state,
		public readonly ?string $personId = null,
		public readonly string $personName = '',
		public readonly string $personEmail = '',
		public readonly string $matchField = '',
		public readonly string $matchValue = '',
		public readonly int $resolvedAt = 0,
		public readonly bool $pinned = false,
		/** Set when the last attempt hit a transient failure; for display only. */
		public readonly string $error = '',
	) {
	}

	/**
	 * Whether this link may be used to read or create tickets.
	 *
	 * Deliberately keyed on $personId being present rather than on the state name:
	 * every read path funnels through here, so a new state can never accidentally
	 * become a way to query TOPdesk without a resolved UUID.
	 */
	public function isUsable(): bool {
		return $this->personId !== null && $this->personId !== '';
	}

	/**
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'state' => $this->state,
			'usable' => $this->isUsable(),
			'personId' => $this->personId,
			'personName' => $this->personName,
			'personEmail' => $this->personEmail,
			'matchField' => $this->matchField,
			'matchValue' => $this->matchValue,
			'resolvedAt' => $this->resolvedAt,
			'pinned' => $this->pinned,
			'error' => $this->error,
		];
	}
}
