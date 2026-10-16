<?php

declare(strict_types=1);

/**
 * Standalone checks for Fiql, run with: php tests/fiql-test.php
 *
 * Fiql has no Nextcloud dependencies on purpose, so it can be checked without a
 * server. It is worth checking: the match value fed into Fiql::eq() is the user's
 * own email address, which on most instances the *user* can change. If quoting
 * leaked, a crafted address could append conditions to the persons query and widen
 * which person card the account resolves to.
 */

require_once __DIR__ . '/../lib/Service/Fiql.php';

use OCA\IntegrationTopdesk\Service\Fiql;

$failures = 0;
$checks = 0;

function check(string $label, string $actual, string $expected): void {
	global $failures, $checks;
	$checks++;

	if ($actual === $expected) {
		echo "  ok   $label\n";
		return;
	}

	$failures++;
	echo "  FAIL $label\n       expected: $expected\n       actual:   $actual\n";
}

echo "Fiql::quote\n";
check('plain email', Fiql::quote('j.doek@acme.nl'), "'j.doek@acme.nl'");
check('whitespace stays inside the quotes', Fiql::quote('Jan de Vries'), "'Jan de Vries'");
check("apostrophe is escaped", Fiql::quote("o'brien@acme.nl"), "'o\\'brien@acme.nl'");
check('backslash is doubled', Fiql::quote('ACME\\jdoek'), "'ACME\\\\jdoek'");
check(
	'backslash before a quote cannot escape the escape',
	Fiql::quote("a\\'b"),
	"'a\\\\\\'b'",
);

echo "\nFiql::eq\n";
check('simple', Fiql::eq('email', 'a@b.nl'), "email=='a@b.nl'");
check('uuid', Fiql::eq('caller.id', 'e127ddc1-0df3-5ee4-9144-95f751bc13ee'), "caller.id=='e127ddc1-0df3-5ee4-9144-95f751bc13ee'");

echo "\nInjection attempts — the condition must stay one condition\n";

// A FIQL ';' would AND on another condition, a ',' would OR one on. Both have to
// end up inert inside the quoted value.
$attack = "x@y.nl',archived==true,'";
$result = Fiql::eq('email', $attack);
check(
	'an OR smuggled through an apostrophe is neutralised',
	$result,
	"email=='x@y.nl\\',archived==true,\\''",
);

// Count unescaped quotes: a safe result has exactly two, the outer pair.
$unescaped = preg_match_all("/(?<!\\\\)'/", $result);
check('exactly two unescaped quotes remain', (string)$unescaped, '2');

$result = Fiql::eq('email', "a';b==c");
$unescaped = preg_match_all("/(?<!\\\\)'/", $result);
check('a semicolon cannot break out either', (string)$unescaped, '2');

echo "\nFiql::all\n";
check(
	'joins with AND',
	Fiql::all([Fiql::eq('email', 'a@b.nl'), 'archived==false']),
	"email=='a@b.nl';archived==false",
);
check('drops empty conditions', Fiql::all(['a==1', '', 'b==2']), 'a==1;b==2');

echo "\n" . ($failures === 0
	? "All $checks checks passed\n"
	: "$failures of $checks checks FAILED\n");

exit($failures === 0 ? 0 : 1);
