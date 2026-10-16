<?php

declare(strict_types=1);

namespace OCA\IntegrationTopdesk\Service;

use OCA\IntegrationTopdesk\AppInfo\Application;
use OCA\IntegrationTopdesk\Exception\TopdeskException;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Maps a Nextcloud user onto exactly one TOPdesk person card.
 *
 * ── Why this is not just a lookup ────────────────────────────────────────────
 *
 * Email addresses get reused. When somebody leaves and `j.doek@acme.nl` is handed
 * to their replacement, a naive "look up the address, show its tickets" integration
 * shows the newcomer the leaver's entire ticket history. That is a real data leak,
 * and it is the normal case rather than an edge case: it happens on every departure
 * where the address is recycled, and on every shared functional mailbox.
 *
 * So the app stores the pair (match value, person UUID) and re-verifies it, on every
 * login and whenever it goes stale:
 *
 *   - resolves to the same UUID        → nothing happened, carry on
 *   - resolves to a *different* UUID   → the identifier changed hands. Treat it as a
 *                                        new human: replace the stored UUID and show
 *                                        only the new person's tickets
 *   - resolves to nobody               → unlink. Show nothing rather than keep
 *                                        serving a person we can no longer confirm
 *   - resolves to several              → unlink. Never guess between person cards
 *   - TOPdesk unreachable or erroring  → keep the link, mark it unverified. A network
 *                                        blip is not evidence of an identity change
 *
 * Everything downstream reads tickets filtered on the stored UUID and never on the
 * email address, so a stale or reused address cannot widen what a user sees.
 *
 * A human can pin a person by hand (shared mailboxes, contractors, a name that never
 * matched). A pinned link is an explicit decision and the resolver does not overwrite
 * it — but it is shown in personal settings so it is never invisible.
 */
class PersonResolver {
	private const KEY_PERSON_ID = 'person_id';
	private const KEY_PERSON_NAME = 'person_name';
	private const KEY_PERSON_EMAIL = 'person_email';
	private const KEY_MATCH_FIELD = 'match_field';
	private const KEY_MATCH_VALUE = 'match_value';
	private const KEY_RESOLVED_AT = 'resolved_at';
	private const KEY_STATE = 'state';
	private const KEY_PINNED = 'pinned';

	/**
	 * `isCaller` is requested because a person card without the Caller box ticked
	 * cannot be the caller on an incident — they will have no tickets and creating
	 * one fails with TOPdesk's "caller does not have the Caller role". The match
	 * itself is still correct, so this is traced rather than treated as a failure.
	 */
	private const PERSON_FIELDS = 'id,dynamicName,email,isCaller';

	public function __construct(
		private ConnectionConfig $config,
		private TopdeskClient $client,
		private IConfig $userConfig,
		private IUserManager $userManager,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The user's current TOPdesk link, re-checking against TOPdesk when needed.
	 *
	 * @param bool $force Re-check even if the cached link is still inside its TTL.
	 *   The login listener passes true; the dashboard widget does not.
	 */
	public function resolve(string $userId, bool $force = false): PersonLink {
		return $this->resolveWithTrace($userId, $force)['link'];
	}

	/**
	 * The same resolution, with the step-by-step record of how it got there.
	 *
	 * @param bool $persist false runs the whole thing without writing the result to
	 *   the user's stored link. `occ topdesk:diagnose` uses that: looking at why a
	 *   user does not match should not quietly change what they are matched to.
	 *
	 * @return array{link: PersonLink, trace: MatchTrace}
	 */
	public function resolveWithTrace(string $userId, bool $force = false, bool $persist = true): array {
		$trace = new MatchTrace();
		$trace->add('Resolving Nextcloud user', ['user' => $userId, 'force' => $force, 'persist' => $persist]);

		$link = $this->doResolve($userId, $force, $persist, $trace);

		$trace->add('Result', [
			'state' => $link->state,
			'usable' => $link->isUsable(),
			'personId' => $link->personId,
		]);

		// Off by default. When on, the whole trace goes to the Nextcloud log as one
		// multi-line entry rather than a dozen separate ones, so it stays readable
		// in the Logging admin page and cannot be interleaved with another user's
		// trace under concurrent logins.
		//
		// At warning level, not info, which looks wrong and is not. Nextcloud's
		// default `loglevel` is 2 — warnings and worse — so an info entry is
		// discarded before it reaches the file on a stock instance. An admin who
		// ticks "log matching details" and then finds an empty log concludes the
		// feature is broken, and the next thing they do is stop trusting the rest
		// of the diagnostics. The entry is also genuinely warning-shaped: it only
		// exists because somebody switched on a setting that says "tell me about
		// this", and it is meant to be switched off again afterwards.
		if ($this->config->isMatchDebugEnabled()) {
			$this->logger->warning(
				"TOPdesk match trace\n  " . implode("\n  ", $trace->toLines()),
				['app' => Application::APP_ID, 'user' => $userId],
			);
		}

		return ['link' => $link, 'trace' => $trace];
	}

	private function doResolve(string $userId, bool $force, bool $persist, MatchTrace $trace): PersonLink {
		if (!$this->config->isConfigured()) {
			$trace->add('No TOPdesk connection is configured — stopping before any lookup');

			return new PersonLink(PersonLink::STATE_NOT_CONFIGURED);
		}

		$stored = $this->load($userId);
		$trace->add('Stored link', [
			'state' => $stored->state,
			'personId' => $stored->personId ?? '(none)',
			'matchedOn' => $stored->matchField === '' ? '(none)' : $stored->matchField,
			'matchedValue' => $stored->matchValue === '' ? '(none)' : $stored->matchValue,
			'pinned' => $stored->pinned,
			'resolvedAt' => $stored->resolvedAt === 0 ? 'never' : date('c', $stored->resolvedAt),
		]);

		// A hand-picked person is a decision, not a cache entry.
		if ($stored->pinned && $stored->isUsable()) {
			$trace->add('Link is pinned by hand — automatic matching is skipped entirely');

			return $stored;
		}

		$user = $this->userManager->get($userId);
		if ($user === null) {
			$trace->add('No such Nextcloud user');

			return new PersonLink(PersonLink::STATE_NO_MATCH_VALUE);
		}

		$ncField = $this->config->getNcMatchField();
		$matchValue = $this->matchValueFor($user);
		$matchField = $this->config->getTopdeskMatchField();

		$trace->add('Match rule', ['nextcloudField' => $ncField, 'topdeskField' => $matchField]);
		$trace->add('Value from the Nextcloud account', [
			'field' => $ncField,
			'value' => $matchValue === '' ? '(empty)' : MatchTrace::describeValue($matchValue),
		]);

		if ($matchValue === '') {
			$trace->add($ncField === ConnectionConfig::NC_FIELD_EMAIL
				? 'The account has no email address, so there is nothing to look up'
				: 'The account has no username to look up');

			return $this->maybeStore($persist, $userId, new PersonLink(
				state: PersonLink::STATE_NO_MATCH_VALUE,
				matchField: $matchField,
				resolvedAt: time(),
			));
		}

		if (!$force && $this->isFresh($stored, $matchField, $matchValue)) {
			$trace->add('Stored link is still fresh — reused without asking TOPdesk', [
				'ttlSeconds' => $this->config->getLinkTtl(),
				'ageSeconds' => time() - $stored->resolvedAt,
			]);

			return $stored;
		}

		if (!$force) {
			$trace->add('Stored link is stale or was matched on a different value — re-checking');
		}

		$query = Fiql::all([Fiql::eq($matchField, $matchValue), 'archived==false']);
		$trace->add('Asking TOPdesk', ['request' => 'GET /tas/api/persons?query=' . $query . '&pageSize=2']);

		try {
			$candidates = $this->lookup($matchField, $matchValue);
		} catch (TopdeskException $e) {
			// Could not ask. Keep whatever we had — including a usable UUID — and
			// say the link is unverified. Dropping the link here would blank every
			// user's widget on a five-minute TOPdesk maintenance window, and
			// re-resolve them all in a thundering herd afterwards.
			$trace->add('TOPdesk could not be asked — keeping the stored link rather than dropping it', [
				'httpStatus' => $e->getHttpStatus(),
				'transient' => $e->isTransient(),
				'error' => $e->getMessage(),
			]);

			$this->logger->warning('Could not verify the TOPdesk person link', [
				'user' => $userId,
				'transient' => $e->isTransient(),
				'exception' => $e,
			]);

			return new PersonLink(
				state: $stored->isUsable() ? PersonLink::STATE_UNVERIFIED : $stored->state,
				personId: $stored->personId,
				personName: $stored->personName,
				personEmail: $stored->personEmail,
				matchField: $matchField,
				matchValue: $matchValue,
				resolvedAt: $stored->resolvedAt,
				pinned: false,
				error: $e->getMessage(),
			);
		}

		$trace->add('TOPdesk answered', ['activePersonsMatched' => count($candidates)]);
		foreach ($candidates as $i => $candidate) {
			$trace->add('  candidate ' . ($i + 1), [
				'id' => (string)($candidate['id'] ?? '(none)'),
				'name' => (string)($candidate['dynamicName'] ?? '(none)'),
				'email' => (string)($candidate['email'] ?? '(none)'),
			]);
		}

		if (count($candidates) === 0) {
			$trace->add('Nobody active in TOPdesk carries this value — linking nothing');
			$trace->add('  Check: is the person card archived, is the value on a different '
				. 'field than the configured one, or is the API operator\'s branch filter hiding them?');

			if ($stored->isUsable()) {
				$trace->add('  A previous link is being dropped', ['previousPersonId' => $stored->personId]);
				$this->logger->info('TOPdesk person link dropped: the identifier no longer matches anyone', [
					'user' => $userId,
					'matchField' => $matchField,
					'previousPersonId' => $stored->personId,
				]);
			}

			return $this->maybeStore($persist, $userId, new PersonLink(
				state: PersonLink::STATE_UNMATCHED,
				matchField: $matchField,
				matchValue: $matchValue,
				resolvedAt: time(),
			));
		}

		if (count($candidates) > 1) {
			// Two active person cards share the identifier. Picking one would be a
			// coin flip over somebody's ticket history, so link nothing and let a
			// human resolve it in personal settings.
			$trace->add('More than one active person carries this value — refusing to guess between them');
			$trace->add('  Fix it in TOPdesk, or let the user pin the right person in their personal settings');

			$this->logger->warning('TOPdesk person link is ambiguous: several persons share the identifier', [
				'user' => $userId,
				'matchField' => $matchField,
				'count' => count($candidates),
			]);

			return $this->maybeStore($persist, $userId, new PersonLink(
				state: PersonLink::STATE_AMBIGUOUS,
				matchField: $matchField,
				matchValue: $matchValue,
				resolvedAt: time(),
			));
		}

		$person = $candidates[0];
		$personId = (string)($person['id'] ?? '');
		if ($personId === '') {
			$trace->add('The single match has no id — treating it as no match');

			return $this->maybeStore($persist, $userId, new PersonLink(
				state: PersonLink::STATE_UNMATCHED,
				matchField: $matchField,
				matchValue: $matchValue,
				resolvedAt: time(),
			));
		}

		if ($stored->isUsable() && $stored->personId !== $personId) {
			// The identifier changed hands. This is the case the whole class exists
			// for: log it at a level an admin will actually see, because somebody's
			// ticket history just stopped being visible to this account — which is
			// correct, but worth being able to explain afterwards.
			$trace->add('IDENTIFIER CHANGED HANDS — the value now belongs to a different person card', [
				'previousPersonId' => $stored->personId,
				'newPersonId' => $personId,
			]);
			$trace->add('  The previous person\'s tickets are no longer visible to this account, which is the intent');

			$this->logger->warning('TOPdesk person link replaced: the identifier now belongs to a different person', [
				'user' => $userId,
				'matchField' => $matchField,
				'previousPersonId' => $stored->personId,
				'newPersonId' => $personId,
			]);
		} elseif ($stored->isUsable()) {
			$trace->add('Same person as before — link unchanged');
		} else {
			$trace->add('Newly linked');
		}

		// A person who is not a caller is matched correctly and still cannot have
		// tickets. Saying so here is the difference between "the match is broken"
		// and "tick the Caller box on their person card".
		if (array_key_exists('isCaller', $person) && $person['isCaller'] === false) {
			$trace->add('WARNING: this person card does not have the Caller role');
			$trace->add('  They can never be the caller on an incident, so the widget will stay '
				. 'empty and creating a ticket will fail. Tick "Caller" on their person card in TOPdesk.');

			$this->logger->warning('TOPdesk person is linked but is not a caller', [
				'user' => $userId,
				'personId' => $personId,
			]);
		}

		return $this->maybeStore($persist, $userId, new PersonLink(
			state: PersonLink::STATE_LINKED,
			personId: $personId,
			personName: (string)($person['dynamicName'] ?? ''),
			personEmail: (string)($person['email'] ?? ''),
			matchField: $matchField,
			matchValue: $matchValue,
			resolvedAt: time(),
		));
	}

	/**
	 * Persons whose value on the match field *starts with* the given prefix.
	 *
	 * Only used by the diagnose command, and only once an exact match has already
	 * failed. "Nobody has j.doek@acme.nl" is a true answer that helps nobody; "but
	 * j.doek@acme.com and j.doek2@acme.nl exist" names the actual problem. Uses
	 * FIQL's `=sw=`, which is text-only — hence the match field rather than a
	 * general search.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @throws TopdeskException
	 */
	public function searchSimilar(string $matchField, string $prefix, int $limit = 5): array {
		$prefix = trim($prefix);
		if ($prefix === '') {
			return [];
		}

		$result = $this->client->get('/persons', [
			'query' => Fiql::all([
				$matchField . '=sw=' . Fiql::quote($prefix),
				'archived==false',
			]),
			'pageSize' => max(1, $limit),
			'fields' => self::PERSON_FIELDS,
		], ['Accept' => TopdeskClient::ACCEPT_PERSONS_V2]);

		return $this->extractPersons($result);
	}

	/**
	 * Diagnostic: the same value, but looking only at *archived* person cards.
	 *
	 * The matcher deliberately ignores archived people — an archived card is a
	 * former employee and resurrecting their ticket history is the bug this app is
	 * built to avoid. But "their card is archived" and "they were never in TOPdesk"
	 * produce the same empty result, and only one of them is somebody's mistake.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @throws TopdeskException
	 */
	public function findArchivedPersons(string $matchField, string $value, int $limit = 5): array {
		$result = $this->client->get('/persons', [
			'query' => Fiql::all([Fiql::eq($matchField, $value), 'archived==true']),
			'pageSize' => max(1, $limit),
			'fields' => self::PERSON_FIELDS,
		], ['Accept' => TopdeskClient::ACCEPT_PERSONS_V2]);

		return $this->extractPersons($result);
	}

	/**
	 * Diagnostic: does this value belong to an *operator* rather than a person?
	 *
	 * The classic TOPdesk confusion, and the one that bites hardest on the very
	 * first test: operators and persons are separate cards. Everyone setting this up
	 * is themselves an operator — that is how they got an application token — and
	 * their own address very often sits on an operator card and no person card at
	 * all. The matcher is then working perfectly and finding nothing.
	 *
	 * Note `page_size`, with an underscore: the operators endpoint spells it
	 * differently from the persons endpoint.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @throws TopdeskException
	 */
	public function findOperators(string $matchField, string $value, int $limit = 5): array {
		$result = $this->client->get('/operators', [
			'query' => Fiql::eq($matchField, $value),
			'page_size' => max(1, $limit),
		]);

		$rows = $result['results'] ?? $result;
		if (!is_array($rows)) {
			return [];
		}

		$out = [];
		foreach ($rows as $row) {
			if (is_array($row) && isset($row['id'])) {
				$out[] = $row;
			}
		}

		return $out;
	}

	/**
	 * The value this user would currently be matched on, without doing any lookup.
	 *
	 * @return array{ncField: string, topdeskField: string, value: string}
	 */
	public function describeMatchRule(string $userId): array {
		return [
			'ncField' => $this->config->getNcMatchField(),
			'topdeskField' => $this->config->getTopdeskMatchField(),
			'value' => $this->matchValueForId($userId),
		];
	}

	/**
	 * Pin a person by hand, bypassing the matcher from here on.
	 *
	 * @throws TopdeskException when the id is not an active person in TOPdesk — so a
	 *   typo or a copied-in UUID from another environment cannot be stored.
	 */
	public function pin(string $userId, string $personId): PersonLink {
		$result = $this->client->get('/persons', [
			'query' => Fiql::all([Fiql::eq('id', $personId), 'archived==false']),
			'pageSize' => 2,
			'fields' => self::PERSON_FIELDS,
		], ['Accept' => TopdeskClient::ACCEPT_PERSONS_V2]);

		$persons = $this->extractPersons($result);
		if ($persons === []) {
			throw new TopdeskException('That is not an active TOPdesk person', 404);
		}

		$person = $persons[0];

		return $this->store($userId, new PersonLink(
			state: PersonLink::STATE_PINNED,
			personId: (string)($person['id'] ?? ''),
			personName: (string)($person['dynamicName'] ?? ''),
			personEmail: (string)($person['email'] ?? ''),
			matchField: $this->config->getTopdeskMatchField(),
			matchValue: $this->matchValueForId($userId),
			resolvedAt: time(),
			pinned: true,
		));
	}

	/**
	 * Drop a pin and go back to automatic matching.
	 */
	public function unpin(string $userId): PersonLink {
		$this->forget($userId);

		return $this->resolve($userId, force: true);
	}

	/**
	 * Type-ahead over caller-enabled persons, for the pin picker.
	 *
	 * Uses the incident API's caller lookup rather than /persons, because it already
	 * restricts to persons who may be a caller on an incident — which is exactly the
	 * set that can be pinned usefully.
	 *
	 * @return list<array{id: string, name: string}>
	 */
	public function searchCallers(string $term): array {
		$term = trim($term);
		if ($term === '') {
			return [];
		}

		$result = $this->client->get('/incidents/callers/lookup', [
			'name' => $term,
			'archived' => 'false',
			'pageSize' => 25,
		]);

		$rows = $result['results'] ?? $result;
		if (!is_array($rows)) {
			return [];
		}

		$out = [];
		foreach ($rows as $row) {
			if (!is_array($row) || !isset($row['id'])) {
				continue;
			}
			$out[] = [
				'id' => (string)$row['id'],
				'name' => (string)($row['name'] ?? $row['dynamicName'] ?? ''),
			];
		}

		return $out;
	}

	/**
	 * Wipe a user's stored link. Called on account deletion.
	 */
	public function forget(string $userId): void {
		foreach ([
			self::KEY_PERSON_ID, self::KEY_PERSON_NAME, self::KEY_PERSON_EMAIL,
			self::KEY_MATCH_FIELD, self::KEY_MATCH_VALUE, self::KEY_RESOLVED_AT,
			self::KEY_STATE, self::KEY_PINNED,
		] as $key) {
			$this->userConfig->deleteUserValue($userId, Application::APP_ID, $key);
		}
	}

	// ── internals ───────────────────────────────────────────────────────────────

	/**
	 * @return list<array<string, mixed>>
	 *
	 * @throws TopdeskException
	 */
	private function lookup(string $matchField, string $matchValue): array {
		// pageSize 2 is deliberate: we only ever need to know "none, one, or more
		// than one", and asking for two makes the ambiguity check free.
		$result = $this->client->get('/persons', [
			'query' => Fiql::all([Fiql::eq($matchField, $matchValue), 'archived==false']),
			'pageSize' => 2,
			'fields' => self::PERSON_FIELDS,
		], ['Accept' => TopdeskClient::ACCEPT_PERSONS_V2]);

		return $this->extractPersons($result);
	}

	/**
	 * The v2 persons endpoint answers with a bare array; older shapes wrap it in
	 * `results`. Accept either rather than depending on which one a given TOPdesk
	 * serves.
	 *
	 * @param array<mixed> $result
	 *
	 * @return list<array<string, mixed>>
	 */
	private function extractPersons(array $result): array {
		$rows = $result['results'] ?? $result;
		if (!is_array($rows)) {
			return [];
		}

		$out = [];
		foreach ($rows as $row) {
			if (is_array($row) && isset($row['id'])) {
				$out[] = $row;
			}
		}

		return $out;
	}

	private function matchValueFor(IUser $user): string {
		if ($this->config->getNcMatchField() === ConnectionConfig::NC_FIELD_UID) {
			return $user->getUID();
		}

		return trim((string)($user->getEMailAddress() ?? ''));
	}

	private function matchValueForId(string $userId): string {
		$user = $this->userManager->get($userId);

		return $user === null ? '' : $this->matchValueFor($user);
	}

	/**
	 * A stored link is reusable without an API call only when it is linked, inside
	 * its TTL, and was matched on the same field *and* the same value we would use
	 * now. A changed email address on the Nextcloud account therefore forces a
	 * re-check immediately, without waiting for the TTL.
	 */
	private function isFresh(PersonLink $stored, string $matchField, string $matchValue): bool {
		return $stored->state === PersonLink::STATE_LINKED
			&& $stored->isUsable()
			&& $stored->matchField === $matchField
			&& $stored->matchValue === $matchValue
			&& $stored->resolvedAt > time() - $this->config->getLinkTtl();
	}

	private function load(string $userId): PersonLink {
		$get = fn (string $key, string $default = ''): string => $this->userConfig->getUserValue(
			$userId,
			Application::APP_ID,
			$key,
			$default,
		);

		$personId = $get(self::KEY_PERSON_ID);

		return new PersonLink(
			state: $get(self::KEY_STATE, PersonLink::STATE_UNMATCHED),
			personId: $personId === '' ? null : $personId,
			personName: $get(self::KEY_PERSON_NAME),
			personEmail: $get(self::KEY_PERSON_EMAIL),
			matchField: $get(self::KEY_MATCH_FIELD),
			matchValue: $get(self::KEY_MATCH_VALUE),
			resolvedAt: (int)$get(self::KEY_RESOLVED_AT, '0'),
			pinned: $get(self::KEY_PINNED) === '1',
		);
	}

	/**
	 * Store the link, unless this is a read-only diagnostic run.
	 *
	 * Returning the link either way means every caller gets the answer the resolver
	 * arrived at; only the side effect is conditional.
	 */
	private function maybeStore(bool $persist, string $userId, PersonLink $link): PersonLink {
		return $persist ? $this->store($userId, $link) : $link;
	}

	private function store(string $userId, PersonLink $link): PersonLink {
		// A closure body rather than an arrow function: an arrow function implicitly
		// returns its expression, which a :void return type forbids.
		$set = function (string $key, string $value) use ($userId): void {
			$this->userConfig->setUserValue($userId, Application::APP_ID, $key, $value);
		};

		$set(self::KEY_PERSON_ID, $link->personId ?? '');
		$set(self::KEY_PERSON_NAME, $link->personName);
		$set(self::KEY_PERSON_EMAIL, $link->personEmail);
		$set(self::KEY_MATCH_FIELD, $link->matchField);
		$set(self::KEY_MATCH_VALUE, $link->matchValue);
		$set(self::KEY_RESOLVED_AT, (string)$link->resolvedAt);
		$set(self::KEY_STATE, $link->state);
		$set(self::KEY_PINNED, $link->pinned ? '1' : '0');

		return $link;
	}
}
