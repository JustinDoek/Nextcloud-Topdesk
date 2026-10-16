<?php

declare(strict_types=1);

namespace OCA\IntegrationTopdesk\Command;

use OCA\IntegrationTopdesk\Exception\TopdeskException;
use OCA\IntegrationTopdesk\Service\ConnectionConfig;
use OCA\IntegrationTopdesk\Service\MatchTrace;
use OCA\IntegrationTopdesk\Service\PersonLink;
use OCA\IntegrationTopdesk\Service\PersonResolver;
use OCA\IntegrationTopdesk\Service\TopdeskClient;
use OCP\IUser;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ topdesk:diagnose` — why is this user not seeing their tickets?
 *
 * The dashboard widget can only ever say "no tickets", because that is all a user
 * should be told. This is the other end of that: the full matching trace, the exact
 * FIQL query, what TOPdesk answered, and — when nothing matched — which similar
 * person cards do exist, which is almost always where the answer is.
 *
 * Read-only by default. Diagnosing a user must not quietly change what they are
 * matched to, so the resolver runs with persist disabled unless `--repair` says
 * otherwise.
 */
class Diagnose extends Command {
	public function __construct(
		private ConnectionConfig $config,
		private TopdeskClient $client,
		private PersonResolver $resolver,
		private IUserManager $userManager,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('topdesk:diagnose')
			->setDescription('Show how Nextcloud users are matched to TOPdesk people')
			->addArgument(
				'user',
				InputArgument::OPTIONAL,
				'Nextcloud user ID to trace. Omit for the connection check only.',
			)
			->addOption(
				'all',
				null,
				InputOption::VALUE_NONE,
				'Summarise every user instead of tracing one.',
			)
			->addOption(
				'repair',
				null,
				InputOption::VALUE_NONE,
				'Write the result to the user\'s stored link. Without this the command changes nothing.',
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$this->showConnection($output);

		if (!$this->config->isConfigured()) {
			// Everything below would report "not configured" for every user, which
			// is noise rather than a diagnosis.
			return 1;
		}

		$userId = $input->getArgument('user');
		$all = (bool)$input->getOption('all');

		if ($all) {
			return $this->summariseAll($output, (bool)$input->getOption('repair'));
		}

		if ($userId === null) {
			$output->writeln('');
			$output->writeln('Pass a user ID to trace one user, or --all to summarise every user.');

			return 0;
		}

		return $this->traceUser($output, (string)$userId, (bool)$input->getOption('repair'));
	}

	// ── connection ───────────────────────────────────────────────────────────

	private function showConnection(OutputInterface $output): void {
		$output->writeln('<info>Connection</info>');

		$url = $this->config->getBaseUrl();
		$output->writeln('  TOPdesk URL:    ' . ($url === '' ? '<error>not set</error>' : $url));

		$mode = $this->config->getAuthMode();
		$output->writeln('  Auth mode:      ' . ($mode === ConnectionConfig::AUTH_OAUTH_CC
			? 'OAuth client credentials'
			: 'application token'));

		if ($mode === ConnectionConfig::AUTH_OAUTH_CC) {
			$output->writeln('  Client ID:      ' . ($this->config->getClientId() ?: '<error>not set</error>'));
			$output->writeln('  Client secret:  ' . ($this->config->getClientSecret() !== '' ? 'stored' : '<error>not set</error>'));
		} else {
			$output->writeln('  Login name:     ' . ($this->config->getLoginName() ?: '<error>not set</error>'));
			$output->writeln('  App token:      ' . ($this->config->getAppToken() !== '' ? 'stored' : '<error>not set</error>'));
		}

		$output->writeln('  Match rule:     Nextcloud ' . $this->config->getNcMatchField()
			. ' → TOPdesk ' . $this->config->getTopdeskMatchField());
		$output->writeln('  Link TTL:       ' . $this->config->getLinkTtl() . 's');
		$output->writeln('  Match logging:  ' . ($this->config->isMatchDebugEnabled() ? 'on' : 'off'));

		if (!$this->config->isConfigured()) {
			$output->writeln('');
			$output->writeln('<error>Not configured — fill in the admin settings first.</error>');

			return;
		}

		try {
			$ping = $this->client->ping();
			$output->writeln('  Reachable:      yes (API ' . $ping['version']
				. ($ping['productVersion'] !== null ? ', TOPdesk ' . $ping['productVersion'] : '') . ')');
		} catch (TopdeskException $e) {
			$output->writeln('  Reachable:      <error>no — ' . $e->getMessage()
				. ' (HTTP ' . $e->getHttpStatus() . ')</error>');
		}
	}

	// ── one user ─────────────────────────────────────────────────────────────

	private function traceUser(OutputInterface $output, string $userId, bool $repair): int {
		$user = $this->userManager->get($userId);
		if ($user === null) {
			$output->writeln('');
			$output->writeln('<error>No Nextcloud user "' . $userId . '".</error>');

			return 1;
		}

		$output->writeln('');
		$output->writeln('<info>Nextcloud account</info>');
		$output->writeln('  UID:            ' . $user->getUID());
		$output->writeln('  Display name:   ' . $user->getDisplayName());
		$email = (string)($user->getEMailAddress() ?? '');
		$output->writeln('  Email:          ' . ($email === ''
			? '<comment>(none set)</comment>'
			: MatchTrace::describeValue($email)));
		$output->writeln('  Backend:        ' . $user->getBackendClassName());

		$output->writeln('');
		$output->writeln('<info>Matching</info>');
		if (!$repair) {
			$output->writeln('  <comment>Read-only — the stored link is not being changed. Pass --repair to write it.</comment>');
		}

		$result = $this->resolver->resolveWithTrace($userId, force: true, persist: $repair);
		/** @var PersonLink $link */
		$link = $result['link'];

		foreach ($result['trace']->toLines() as $line) {
			$output->writeln('  ' . $line);
		}

		$output->writeln('');
		if ($link->isUsable()) {
			$output->writeln('<info>Outcome: linked</info> to ' . $link->personName
				. ' (' . $link->personId . ')');
			$output->writeln('  Tickets are read with: query=caller.id==' . $link->personId);
		} else {
			$output->writeln('<error>Outcome: not linked</error> (state: ' . $link->state . ')');
			$output->writeln('  No tickets will be shown, and no ticket can be created.');
			$this->suggestSimilar($output, $userId);
		}

		return $link->isUsable() ? 0 : 1;
	}

	/**
	 * When nothing matched, work out why.
	 *
	 * Three questions, in the order they are most often the answer:
	 * is the person card archived, does the value belong to an operator rather than
	 * a person, and does anything similar exist? "Nobody has j.doek@acme.nl" is
	 * true and useless; "that address is on an operator card, not a person card" is
	 * the answer.
	 */
	private function suggestSimilar(OutputInterface $output, string $userId): void {
		$rule = $this->resolver->describeMatchRule($userId);
		if ($rule['value'] === '') {
			return;
		}

		$field = $rule['topdeskField'];
		$value = $rule['value'];

		$output->writeln('');
		$output->writeln('<info>Why not?</info>');

		// 1. Archived person card.
		try {
			$archived = $this->resolver->findArchivedPersons($field, $value);
			if ($archived !== []) {
				$output->writeln('  <comment>An ARCHIVED person card has this ' . $field . ':</comment>');
				foreach ($archived as $person) {
					$output->writeln('    - ' . (string)($person['dynamicName'] ?? '(no name)')
						. '  ' . (string)($person['id'] ?? ''));
				}
				$output->writeln('  Archived cards are ignored on purpose — an archived card is a former');
				$output->writeln('  employee, and showing their tickets to whoever holds the address now is');
				$output->writeln('  exactly what this app refuses to do. Un-archive the card if the person');
				$output->writeln('  is still here.');

				return;
			}
			$output->writeln('  No archived person card has it either.');
		} catch (TopdeskException $e) {
			$output->writeln('  <comment>Could not check archived people: ' . $e->getMessage() . '</comment>');
		}

		// 2. Operator rather than person. The first-test classic.
		try {
			$operators = $this->resolver->findOperators($field, $value);
			if ($operators !== []) {
				$output->writeln('');
				$output->writeln('  <comment>This ' . $field . ' belongs to an OPERATOR, not a person:</comment>');
				foreach ($operators as $operator) {
					$output->writeln('    - ' . (string)($operator['dynamicName'] ?? $operator['surName'] ?? '(no name)')
						. '  ' . (string)($operator['id'] ?? ''));
				}
				$output->writeln('  In TOPdesk an operator and a person are separate cards, and only a person');
				$output->writeln('  can be the caller on an incident. This is the usual reason the very first');
				$output->writeln('  test fails: whoever sets the integration up is an operator — that is how');
				$output->writeln('  they got an application token — and may have no person card at all.');
				$output->writeln('  Either give them a person card carrying this ' . $field . ', or test with');
				$output->writeln('  an account whose address is on a person card.');

				return;
			}
			$output->writeln('  No operator has it either.');
		} catch (TopdeskException $e) {
			$output->writeln('  <comment>Could not check operators: ' . $e->getMessage() . '</comment>');
		}

		// 3. Anything that nearly matches — a typo, a different domain, a second account.
		$prefix = strtok($value, '@');
		if ($prefix === false || $prefix === '') {
			return;
		}

		$output->writeln('');
		$output->writeln('  <info>Active people whose ' . $field . ' starts with "' . $prefix . '"</info>');

		try {
			$similar = $this->resolver->searchSimilar($field, $prefix);
		} catch (TopdeskException $e) {
			$output->writeln('    <comment>Could not search: ' . $e->getMessage() . '</comment>');

			return;
		}

		if ($similar === []) {
			$output->writeln('    none.');
			$output->writeln('  So the value is nowhere in TOPdesk on that field. Either it sits on a');
			$output->writeln('  different field than the configured one — try networkLoginName if both');
			$output->writeln('  systems come from the same directory — or the API operator\'s branch,');
			$output->writeln('  category or operator filter is hiding the card. Those filters apply to');
			$output->writeln('  everything this app reads.');

			return;
		}

		foreach ($similar as $person) {
			$output->writeln('    - ' . (string)($person['dynamicName'] ?? '(no name)')
				. '  <comment>' . (string)($person['email'] ?? '') . '</comment>'
				. '  ' . (string)($person['id'] ?? ''));
		}
		$output->writeln('');
		$output->writeln('  If one of these is the right person, either correct the value on the');
		$output->writeln('  person card in TOPdesk, or have the user pin them in personal settings.');
	}

	// ── every user ───────────────────────────────────────────────────────────

	private function summariseAll(OutputInterface $output, bool $repair): int {
		$output->writeln('');
		$output->writeln('<info>All users</info>');
		if (!$repair) {
			$output->writeln('  <comment>Read-only — stored links are not being changed.</comment>');
		}
		$output->writeln('');

		$counts = [];
		$worthTracing = [];

		// State first, then the value, then the user ID last. The ID goes last
		// because it is the one column with no sane maximum width — an OIDC backend
		// hands out 64-character UIDs, which would push every other column off the
		// screen if the ID led the row. It is also the value to copy into the
		// follow-up command, so it should not be truncated.
		$output->writeln('  ' . str_pad('STATE', 17) . str_pad('MATCHED ON / PERSON', 38) . 'USER ID');

		// One TOPdesk lookup per user, so this is deliberately not something to run
		// on a whim against a large instance. force is false: the point is to report
		// the state users are actually in, not to re-resolve everyone.
		$this->userManager->callForSeenUsers(function (IUser $user) use (&$counts, &$worthTracing, $output, $repair): void {
			$result = $this->resolver->resolveWithTrace($user->getUID(), force: false, persist: $repair);
			/** @var PersonLink $link */
			$link = $result['link'];

			$counts[$link->state] = ($counts[$link->state] ?? 0) + 1;

			$detail = $link->isUsable()
				? $link->personName
				: ($link->matchValue === '' ? '(nothing to match on)' : $link->matchValue);

			$line = '  ' . str_pad($link->state, 17)
				. str_pad(mb_strimwidth($detail, 0, 37, '…'), 38)
				. $user->getUID();

			$output->writeln($link->isUsable() ? $line : '<comment>' . $line . '</comment>');

			// `no_match_value` means the Nextcloud account has no address at all,
			// which needs no trace to explain. The states worth a closer look are
			// the ones where we did ask TOPdesk and did not like the answer.
			if (!$link->isUsable() && $link->state !== PersonLink::STATE_NO_MATCH_VALUE) {
				$worthTracing[] = $user->getUID();
			}
		});

		$output->writeln('');
		$output->writeln('<info>Summary</info>');
		foreach ($counts as $state => $count) {
			$output->writeln('  ' . str_pad($state, 17) . $count);
		}

		// callForSeenUsers only visits accounts that have logged in at least once.
		// Worth saying: a freshly provisioned user missing from this list is absent
		// because they have never signed in, not because they matched.
		$output->writeln('');
		$output->writeln('  Covers accounts that have logged in at least once.');

		if ($worthTracing !== []) {
			$output->writeln('');
			$output->writeln('Trace one of them with:');
			$output->writeln('  occ topdesk:diagnose ' . $this->shellQuote($worthTracing[0]));
		}

		return 0;
	}

	/**
	 * Quote a user ID for pasting into a shell.
	 *
	 * Nextcloud happily issues user IDs containing spaces — this instance has
	 * several — and an unquoted one turns the suggested command into two arguments
	 * and a confusing "no such user" error.
	 */
	private function shellQuote(string $value): string {
		return preg_match('/^[A-Za-z0-9._@-]+$/', $value) === 1
			? $value
			: '"' . str_replace('"', '\"', $value) . '"';
	}
}
