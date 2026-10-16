<?php

declare(strict_types=1);

namespace OCA\IntegrationTopdesk\Service;

/**
 * Building FIQL query strings for the TOPdesk API.
 *
 * FIQL is designed to be used in a query parameter without URL-escaping it, so the
 * escaping that matters here is FIQL's own. Per the TOPdesk tutorial the reserved
 * characters are ' " ( ) [ ] ; , = ! ~ > < — a value containing any of them, or
 * whitespace, has to be quoted, and inside a quoted value a backslash is an escape
 * character that must itself be doubled.
 */
final class Fiql {
	/**
	 * Quote a value for the right-hand side of a FIQL comparison.
	 *
	 * Always quotes, rather than only when needed: an email address is harmless
	 * either way, and quoting unconditionally means a surname with an apostrophe
	 * or a login name with a comma cannot change the shape of the query.
	 */
	public static function quote(string $value): string {
		// Backslash first — doing it after would double the backslashes that
		// escaping the quotes introduces.
		$escaped = str_replace('\\', '\\\\', $value);
		$escaped = str_replace("'", "\\'", $escaped);

		return "'" . $escaped . "'";
	}

	/**
	 * `field=='value'`
	 */
	public static function eq(string $field, string $value): string {
		return $field . '==' . self::quote($value);
	}

	/**
	 * Join conditions with FIQL's AND operator.
	 *
	 * @param string[] $conditions
	 */
	public static function all(array $conditions): string {
		return implode(';', array_filter($conditions, static fn (string $c): bool => $c !== ''));
	}
}
