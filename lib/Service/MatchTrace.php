<?php

declare(strict_types=1);

namespace OCA\IntegrationTopdesk\Service;

/**
 * A step-by-step record of one matching attempt.
 *
 * PersonResolver makes a handful of decisions — which field, which value, what
 * TOPdesk answered, what that means — and when a user says "the widget is empty"
 * the only useful question is which of those steps went differently than expected.
 * Reconstructing that from the stored PersonLink is guesswork: a `state` of
 * `unmatched` looks identical whether the address was empty, had a trailing space,
 * belonged to an archived person card, or was simply never in TOPdesk.
 *
 * So the resolver records the steps as it goes, and the same trace is rendered two
 * ways: into the Nextcloud log when match debugging is switched on, and onto the
 * console by `occ topdesk:diagnose`.
 */
final class MatchTrace {
	/** @var list<array{message: string, context: array<string, scalar|null>}> */
	private array $steps = [];

	/**
	 * @param array<string, scalar|null> $context
	 */
	public function add(string $message, array $context = []): void {
		$this->steps[] = ['message' => $message, 'context' => $context];
	}

	/**
	 * @return list<array{message: string, context: array<string, scalar|null>}>
	 */
	public function getSteps(): array {
		return $this->steps;
	}

	/**
	 * One line per step, each as `message (key=value, key=value)`.
	 *
	 * @return list<string>
	 */
	public function toLines(): array {
		$lines = [];

		foreach ($this->steps as $step) {
			$line = $step['message'];
			if ($step['context'] !== []) {
				$pairs = [];
				foreach ($step['context'] as $key => $value) {
					$pairs[] = $key . '=' . self::scalarToString($value);
				}
				$line .= ' (' . implode(', ', $pairs) . ')';
			}
			$lines[] = $line;
		}

		return $lines;
	}

	/**
	 * Render a matched-on value so the problems that are invisible in a log stay
	 * visible.
	 *
	 * A trailing space, a non-breaking space pasted out of a spreadsheet, or a
	 * capital letter are the most common reasons an address that "looks right"
	 * does not match — and all three print identically to the correct value. So the
	 * value is quoted, its length is given, and whitespace at either end is called
	 * out by name rather than left for the reader to spot between the quotes.
	 */
	public static function describeValue(string $value): string {
		$described = '"' . $value . '" (' . mb_strlen($value) . ' chars';

		if ($value !== trim($value)) {
			$described .= ', HAS LEADING/TRAILING WHITESPACE';
		}
		if ($value !== mb_strtolower($value)) {
			// Not a problem on its own — TOPdesk's FIQL `==` on text is
			// case-insensitive in practice — but worth seeing when a match fails.
			$described .= ', mixed case';
		}

		return $described . ')';
	}

	private static function scalarToString(mixed $value): string {
		if ($value === null) {
			return 'null';
		}
		if (is_bool($value)) {
			return $value ? 'true' : 'false';
		}

		return (string)$value;
	}
}
