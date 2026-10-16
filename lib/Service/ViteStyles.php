<?php

declare(strict_types=1);

namespace OCA\IntegrationTopdesk\Service;

use OCA\IntegrationTopdesk\AppInfo\Application;
use OCP\Util;

/**
 * Loads a Vite entry's stylesheets by reading the manifest the build wrote.
 *
 * `@nextcloud/vite-config`'s css-entry-points-plugin emits one stub per entry —
 * `css/<entry>.css` — whose entire content is `@import './vite-<chunk>.chunk.css';`
 * lines, one per chunk that entry statically imports. Nextcloud does not resolve
 * those relative imports, so the stub cannot be handed to Util::addStyle as-is.
 *
 * The obvious workaround is to copy the chunk names into the template by hand. That
 * breaks silently: Rollup names a shared chunk after one of the modules inside it and
 * regroups chunks whenever an entry's import graph changes, so the names drift under
 * any build that touches an entry — and the symptom is an unstyled page, not an
 * error. Reading the manifest is one small file read per settings page render, and it
 * is right by construction.
 *
 * (Carried over from the TeamHub app, where three releases lost their styles to
 * hand-copied chunk names before this approach landed.)
 */
final class ViteStyles {
	public static function add(string $entry): void {
		$stub = __DIR__ . '/../../css/' . $entry . '.css';

		$lines = @file($stub, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		if ($lines === false) {
			// No build output. The page still renders; it just arrives unstyled,
			// which is what an unreadable app directory would have meant anyway.
			return;
		}

		foreach ($lines as $line) {
			// @import './vite-dashboard.chunk.css';  →  vite-dashboard.chunk
			if (preg_match("~@import\s+'\./(vite-[A-Za-z0-9_.-]+)\.css'~", $line, $m) === 1) {
				Util::addStyle(Application::APP_ID, $m[1]);
			}
		}
	}
}
