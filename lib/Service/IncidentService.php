<?php

declare(strict_types=1);

namespace OCA\IntegrationTopdesk\Service;

use OCA\IntegrationTopdesk\Exception\TopdeskException;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

/**
 * Reading and creating the signed-in user's own TOPdesk incidents.
 *
 * The one invariant worth stating out loud: **every read is filtered on the person
 * UUID from PersonResolver, and never on an email address.** The API account this
 * app authenticates with is an operator and can read far more than one person's
 * tickets, so that filter is the only thing standing between a user and somebody
 * else's ticket history. It is applied in exactly one place, below, and a link
 * without a usable UUID returns an empty list rather than an unfiltered query.
 */
class IncidentService {
	/** TOPdesk enforces this; validating here turns a 400 into a sentence. */
	public const BRIEF_DESCRIPTION_MAX = 80;

	/**
	 * Fast sort fields per the spec are callDate, creationDate, modificationDate,
	 * targetDate, closedDate and id — and sorting on one field only is faster than
	 * several. "Latest tickets" means most recently created.
	 */
	private const SORT = 'creationDate:desc';

	private const LIST_FIELDS = 'id,number,briefDescription,status,processingStatus,'
		. 'creationDate,targetDate,closed,completed,category,subcategory,priority';

	/** Form dropdowns change rarely and are identical for every user. */
	private const FORM_OPTIONS_TTL = 900;

	private ICache $cache;

	public function __construct(
		private TopdeskClient $client,
		private PersonResolver $resolver,
		private ConnectionConfig $config,
		private LoggerInterface $logger,
		ICacheFactory $cacheFactory,
	) {
		$this->cache = $cacheFactory->createDistributed('integration_topdesk');
	}

	/**
	 * The user's most recent incidents, newest first.
	 *
	 * @return array{link: PersonLink, incidents: list<array<string, mixed>>}
	 *
	 * @throws TopdeskException
	 */
	public function listRecent(string $userId, int $limit = 5): array {
		$link = $this->resolver->resolve($userId);

		if (!$link->isUsable()) {
			// Not linked, ambiguous, or no connection. There is no safe query to
			// make, so there is no query.
			return ['link' => $link, 'incidents' => []];
		}

		$query = [
			// The filter. Everything in this class depends on it being here.
			'query' => Fiql::eq('caller.id', (string)$link->personId),
			'sort' => self::SORT,
			'pageSize' => max(1, min(50, $limit)),
			// ISO 8601 instead of TOPdesk's legacy '2020-10-01T14:10:00.000+0000'.
			'dateFormat' => 'iso8601',
		];

		$rows = $this->fetchIncidents($query);

		$incidents = [];
		foreach ($rows as $row) {
			if (is_array($row)) {
				$incidents[] = $this->normalise($row);
			}
		}

		return ['link' => $link, 'incidents' => $incidents];
	}

	/**
	 * Report a new incident on the user's behalf.
	 *
	 * @return array<string, mixed> The created incident, normalised.
	 *
	 * @throws TopdeskException
	 */
	public function create(
		string $userId,
		string $briefDescription,
		string $details = '',
		?string $categoryId = null,
		?string $subcategoryId = null,
	): array {
		$link = $this->resolver->resolve($userId);
		if (!$link->isUsable()) {
			throw new TopdeskException('This account is not linked to a TOPdesk person', 409);
		}

		$briefDescription = trim($briefDescription);
		if ($briefDescription === '') {
			throw new TopdeskException('A short description is required', 400);
		}
		if (mb_strlen($briefDescription) > self::BRIEF_DESCRIPTION_MAX) {
			throw new TopdeskException(
				'The short description can be at most ' . self::BRIEF_DESCRIPTION_MAX . ' characters',
				400,
			);
		}

		$body = [
			// By UUID, not by email. callerLookup also accepts {"email": "..."},
			// which would be one call shorter — but it would reintroduce exactly the
			// reused-address ambiguity that PersonResolver exists to settle, and the
			// ticket would be filed against whoever holds the address right now
			// rather than against the person this account is linked to.
			'callerLookup' => ['id' => $link->personId],
			'briefDescription' => $briefDescription,
			'status' => 'firstLine',
		];

		if (trim($details) !== '') {
			// TOPdesk calls this field `request`.
			$body['request'] = $this->toTopdeskHtml($details);
		}
		if ($categoryId !== null && $categoryId !== '') {
			$body['category'] = ['id' => $categoryId];
		}
		if ($subcategoryId !== null && $subcategoryId !== '') {
			$body['subcategory'] = ['id' => $subcategoryId];
		}

		$created = $this->client->post('/incidents', $body);

		$this->logger->info('Created a TOPdesk incident', [
			'user' => $userId,
			'number' => $created['number'] ?? null,
		]);

		return $this->normalise($created);
	}

	/**
	 * Categories and subcategories for the create form.
	 *
	 * Both are optional on an incident, so a failure to read them degrades the form
	 * rather than breaking it.
	 *
	 * @return array{categories: list<array<string, mixed>>, subcategories: list<array<string, mixed>>}
	 */
	public function getFormOptions(): array {
		$cached = $this->cache->get('form_options');
		if (is_string($cached)) {
			$decoded = json_decode($cached, true);
			if (is_array($decoded)) {
				/** @var array{categories: list<array<string, mixed>>, subcategories: list<array<string, mixed>>} $decoded */
				return $decoded;
			}
		}

		$options = [
			'categories' => $this->readSearchlist('/incidents/categories'),
			'subcategories' => $this->readSearchlist('/incidents/subcategories'),
		];

		$this->cache->set('form_options', json_encode($options), self::FORM_OPTIONS_TTL);

		return $options;
	}

	/**
	 * Deep link to one incident in the Self-Service Portal.
	 *
	 * The SSP is the right destination: the Nextcloud user is an end user, not an
	 * operator, and the operator view would refuse them anyway.
	 */
	public function getIncidentUrl(string $incidentId): string {
		$base = $this->config->getBaseUrl();
		if ($base === '' || $incidentId === '') {
			return '';
		}

		return $base . '/tas/public/ssp/content/detail/ticket?unid=' . urlencode($incidentId);
	}

	/**
	 * Where "show everything" goes. The portal root rather than a deep link to a
	 * ticket list, because the list's own path depends on how the customer has laid
	 * their SSP out, while the root is always there.
	 */
	public function getPortalUrl(): string {
		$base = $this->config->getBaseUrl();

		return $base === '' ? '' : $base . '/tas/public/ssp/';
	}

	// ── internals ───────────────────────────────────────────────────────────────

	/**
	 * @param array<string, string|int|bool> $query
	 *
	 * @return array<mixed>
	 *
	 * @throws TopdeskException
	 */
	private function fetchIncidents(array $query): array {
		try {
			return $this->client->get('/incidents', $query + ['fields' => self::LIST_FIELDS]);
		} catch (TopdeskException $e) {
			// `fields` is documented but the accepted names vary a little between
			// TOPdesk versions, and the whole call 400s if one is not recognised.
			// Omitting it is explicitly slower, not wrong — so a widget that is slow
			// beats a widget that is empty.
			if ($e->getHttpStatus() !== 400) {
				throw $e;
			}

			$this->logger->info('TOPdesk rejected the incident field list; retrying without it', [
				'detail' => $e->getMessage(),
			]);

			return $this->client->get('/incidents', $query);
		}
	}

	/**
	 * Flatten an incident into what the widget actually renders.
	 *
	 * @param array<string, mixed> $row
	 *
	 * @return array<string, mixed>
	 */
	private function normalise(array $row): array {
		$id = (string)($row['id'] ?? '');

		return [
			'id' => $id,
			// TOPdesk numbers look like "I 2211 016" — keep the spacing as TOPdesk
			// formats it, that is how users will search for it.
			'number' => (string)($row['number'] ?? ''),
			'briefDescription' => (string)($row['briefDescription'] ?? ''),
			'status' => $this->name($row['processingStatus'] ?? null),
			'line' => (string)($row['status'] ?? ''),
			'category' => $this->name($row['category'] ?? null),
			'subcategory' => $this->name($row['subcategory'] ?? null),
			'priority' => $this->name($row['priority'] ?? null),
			'creationDate' => (string)($row['creationDate'] ?? ''),
			'targetDate' => (string)($row['targetDate'] ?? ''),
			'closed' => (bool)($row['closed'] ?? false),
			'completed' => (bool)($row['completed'] ?? false),
			'url' => $this->getIncidentUrl($id),
		];
	}

	/**
	 * Most TOPdesk references are {id, name}; some come back as a bare string.
	 */
	private function name(mixed $value): string {
		if (is_array($value)) {
			return (string)($value['name'] ?? '');
		}

		return is_string($value) ? $value : '';
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function readSearchlist(string $path): array {
		try {
			$rows = $this->client->get($path);
		} catch (TopdeskException $e) {
			$this->logger->info('Could not read ' . $path . ' from TOPdesk', ['exception' => $e]);

			return [];
		}

		$out = [];
		foreach ($rows as $row) {
			if (!is_array($row) || !isset($row['id'])) {
				continue;
			}
			$entry = [
				'id' => (string)$row['id'],
				'name' => (string)($row['name'] ?? ''),
			];
			// Subcategories carry their parent, which is what lets the form filter
			// the second dropdown by the first.
			if (isset($row['category']) && is_array($row['category'])) {
				$entry['categoryId'] = (string)($row['category']['id'] ?? '');
			}
			$out[] = $entry;
		}

		return $out;
	}

	/**
	 * The `request` field is rich text, but TOPdesk accepts only a short tag
	 * whitelist (i, em, b, strong, u, br, h5, h6, img). Users type plain text into
	 * the widget, so escape everything and turn newlines into the one tag we need.
	 * Passing their input through unescaped would hand them an HTML injection into
	 * the ticket an operator later reads.
	 */
	private function toTopdeskHtml(string $text): string {
		$escaped = htmlspecialchars(trim($text), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

		return nl2br($escaped, false);
	}
}
