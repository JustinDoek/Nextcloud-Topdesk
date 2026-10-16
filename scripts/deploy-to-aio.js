#!/usr/bin/env node
/**
 * deploy-to-aio.js — push the working copy onto the local Nextcloud AIO test
 * instance running in Docker Desktop.
 *
 * Modelled on the TeamHub script of the same name (C:\Temp\npm), including the
 * three things that one learned the hard way: argv arrays rather than shell
 * strings, relative source paths so `docker cp` cannot mistake a Windows drive
 * letter for a container name, and delete-then-copy so fingerprinted Vite chunks
 * do not pile up forever. The pre-flight differs — this app has no integrity
 * manifest, no migrations and no release repo — and it gains one check TeamHub
 * does not need, on the CSS manifest stubs (see check 3).
 *
 * Why a file swap is safe:
 *   AIO's `nextcloud-aio-nextcloud` container mounts the `nextcloud_aio_nextcloud`
 *   volume at /var/www/html, and custom_apps/ lives inside it. Files written there
 *   persist across container recreation, so an AIO update does not undo the deploy
 *   and the deploy does not fight the mastercontainer.
 *
 * Usage:
 *   npm run deploy:aio                  build, then deploy
 *   node scripts/deploy-to-aio.js       deploy what is already built
 *     --dry-run          print every command, change nothing
 *     --enable           also run `occ app:enable` (needed once, on a first deploy)
 *     --restart          restart the container instead of reloading php-fpm
 *     --skip-preflight   deploy a bundle the checks reject
 *
 * Env overrides:
 *   TOPDESK_AIO_CONTAINER   default nextcloud-aio-nextcloud
 *   TOPDESK_AIO_APP_DIR     default /var/www/html/custom_apps/integration_topdesk
 */

const { spawnSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const APP_ID = 'integration_topdesk';

/** Repository root (this file lives in scripts/). */
const SRC = path.resolve(__dirname, '..');

const CONTAINER = process.env.TOPDESK_AIO_CONTAINER || 'nextcloud-aio-nextcloud';
const APP_DIR = process.env.TOPDESK_AIO_APP_DIR || `/var/www/html/custom_apps/${APP_ID}`;

const DRY_RUN = process.argv.includes('--dry-run');
const RESTART = process.argv.includes('--restart');
const ENABLE = process.argv.includes('--enable');
const SKIP_PREFLIGHT = process.argv.includes('--skip-preflight')
	|| process.env.TOPDESK_SKIP_PREFLIGHT === '1';

/**
 * Top-level paths a shipped install needs. Everything else in the working folder
 * — node_modules/, src/, scripts/, docs/, tests/, vite.config.mjs, README.md,
 * package-lock.json — is dev-only.
 *
 * When this app gains a publish-to-release step, this list becomes a contract
 * with that workflow and should move to scripts/lib/ alongside the pre-flight,
 * the way TeamHub's did once it had two consumers. One consumer, one file.
 */
const PUBLISHABLE = [
	'appinfo',
	'lib',
	'js',
	'css',
	'img',
	'l10n',
	'templates',
	'composer.json',
	// Included so the deployed app declares its real version.
	'package.json',
];

/** The three Vite entries, and therefore the files a usable deploy must contain. */
const ENTRIES = ['dashboard', 'admin', 'personal'];

// ── helpers ──────────────────────────────────────────────────────────────────

/**
 * Run docker with an argv array — never a shell string. Windows paths contain
 * backslashes and the repo path may contain spaces; letting a shell parse them is
 * how a deploy script starts deleting the wrong thing.
 *
 * cwd is SRC so every source path can be passed relative ("./js"). `docker cp`
 * splits its arguments on the first colon to find the container name, which makes
 * an absolute Windows path ("C:\Temp\topdesk\js") ambiguous. Relative paths
 * sidestep that entirely.
 *
 * @param {string[]} args argv for docker
 * @param {{capture?: boolean, allowFail?: boolean}} opts
 * @return {{status: number, stdout: string, stderr: string}}
 */
function docker(args, { capture = false, allowFail = false } = {}) {
	if (DRY_RUN) {
		console.log(`  [dry-run] docker ${args.join(' ')}`);
		return { status: 0, stdout: '', stderr: '' };
	}

	const res = spawnSync('docker', args, {
		cwd: SRC,
		encoding: 'utf8',
		stdio: capture ? 'pipe' : ['ignore', 'inherit', 'inherit'],
	});

	if (res.error) {
		console.error(`\n✗ Could not run docker: ${res.error.message}`);
		console.error('  Is Docker Desktop running and `docker` on PATH?');
		process.exit(1);
	}
	if (res.status !== 0 && !allowFail) {
		console.error(`\n✗ docker ${args.join(' ')}`);
		console.error(`  exited ${res.status}`);
		if (capture && res.stderr) {
			console.error(res.stderr.trim());
		}
		process.exit(1);
	}

	return res;
}

/** docker exec as root (for rm / chown / mkdir). */
const execRoot = (shellCmd, opts) =>
	docker(['exec', CONTAINER, 'sh', '-c', shellCmd], opts);

/** docker exec as www-data (for occ — running it as root taints file ownership). */
const execOcc = (args, opts) =>
	docker(['exec', '--user', 'www-data', CONTAINER, 'php', 'occ', ...args], opts);

/** The <version> declared in appinfo/info.xml, or null if unreadable. */
function readXmlVersion() {
	const xml = fs.readFileSync(path.join(SRC, 'appinfo', 'info.xml'), 'utf8');
	const m = xml.match(/<version>\s*([^<\s]+)\s*<\/version>/);

	return m ? m[1] : null;
}

/**
 * Newest mtime under a directory, or 0 if it does not exist.
 *
 * @param {string} dir absolute path
 * @param {string[]} skipDirs directory names to not descend into
 * @return {number} epoch ms
 */
function newestMtime(dir, skipDirs = []) {
	let newest = 0;

	const walk = (d) => {
		let entries;
		try {
			entries = fs.readdirSync(d, { withFileTypes: true });
		} catch {
			return;
		}
		for (const entry of entries) {
			if (entry.isDirectory()) {
				if (skipDirs.includes(entry.name)) {
					continue;
				}
				walk(path.join(d, entry.name));
			} else if (entry.isFile()) {
				const m = fs.statSync(path.join(d, entry.name)).mtimeMs;
				if (m > newest) {
					newest = m;
				}
			}
		}
	};

	walk(dir);

	return newest;
}

/**
 * Everything wrong with the working folder right now, as a list of strings.
 * Empty means good to go.
 *
 * These are cheap, and what they prevent is not: an afternoon spent testing a
 * stale bundle is an afternoon of conclusions about code that is not running.
 *
 * @return {string[]}
 */
function collectPreflightProblems() {
	const problems = [];

	const xmlVersion = readXmlVersion();
	const pkgVersion = JSON.parse(
		fs.readFileSync(path.join(SRC, 'package.json'), 'utf8'),
	).version;

	// 1. The two version files must agree. Nextcloud cache-busts js/css URLs on
	//    the app version, so a bump is also how a frontend change reaches the
	//    browser — and info.xml is the one Nextcloud reads.
	if (!xmlVersion) {
		problems.push('Could not read <version> from appinfo/info.xml.');
	} else if (xmlVersion !== pkgVersion) {
		problems.push(
			`Version mismatch: appinfo/info.xml says ${xmlVersion}, `
			+ `package.json says ${pkgVersion}. Bump both.`,
		);
	}

	// 2. Source newer than the bundle means the build is stale.
	const srcNewest = newestMtime(path.join(SRC, 'src'));
	const jsNewest = newestMtime(path.join(SRC, 'js'));

	if (jsNewest === 0) {
		problems.push('js/ is empty or missing — run `npm run build`.');
	} else if (srcNewest > jsNewest) {
		problems.push(
			'src/ is newer than js/ — the compiled bundle is stale. '
			+ 'Run `npm run build` first. '
			+ `(src ${new Date(srcNewest).toISOString()} > js ${new Date(jsNewest).toISOString()})`,
		);
	}

	// 3. Each entry needs its JS *and* its CSS manifest stub.
	//
	//    The stub is the one this app can lose quietly. lib/Service/ViteStyles.php
	//    does not name CSS chunks — it reads css/<entry>.css, the @import list the
	//    css-entry-points-plugin writes, and addStyle()s whatever it finds. If that
	//    stub is missing the page still renders, just with no styling at all and
	//    nothing in any log to say why. A build interrupted partway through, or an
	//    entry renamed in vite.config.mjs but not here, both land exactly there.
	for (const entry of ENTRIES) {
		if (!fs.existsSync(path.join(SRC, 'js', `${entry}.mjs`))) {
			problems.push(`js/${entry}.mjs is missing — run \`npm run build\`.`);
			continue;
		}

		const stub = path.join(SRC, 'css', `${entry}.css`);
		if (!fs.existsSync(stub)) {
			problems.push(
				`css/${entry}.css is missing — ViteStyles.php reads it to find this `
				+ 'entry\'s stylesheets, so the page would render unstyled. '
				+ 'Run `npm run build`.',
			);
			continue;
		}

		// Every chunk the stub points at must exist, or addStyle() 404s on it.
		const stubText = fs.readFileSync(stub, 'utf8');
		const referenced = [...stubText.matchAll(/@import\s+'\.\/(vite-[A-Za-z0-9_.-]+\.css)'/g)]
			.map((m) => m[1]);

		if (referenced.length === 0) {
			problems.push(`css/${entry}.css lists no stylesheets — run \`npm run build\`.`);
		}
		for (const css of referenced) {
			if (!fs.existsSync(path.join(SRC, 'css', css))) {
				problems.push(`css/${entry}.css references css/${css}, which does not exist.`);
			}
		}
	}

	// 4. The hand-written icon stylesheet is not build output and must not have
	//    been swept away by a clean.
	if (!fs.existsSync(path.join(SRC, 'css', 'icon.css'))) {
		problems.push('css/icon.css is missing — it is hand-written, not generated. Restore it from git.');
	}

	return problems;
}

// ── 1. Pre-flight ────────────────────────────────────────────────────────────

const version = readXmlVersion();

if (SKIP_PREFLIGHT) {
	console.log(`Pre-flight SKIPPED — app ${version}, bundle not verified.`);
} else {
	const problems = collectPreflightProblems();
	if (problems.length) {
		console.error('Pre-flight checks failed:\n');
		for (const p of problems) {
			console.error(`  ✗ ${p}`);
		}
		console.error('\nNothing was deployed. Fix the above, or pass --skip-preflight.');
		process.exit(1);
	}
	console.log(`Pre-flight OK — app ${version}, bundle current.`);
}

// ── 2. Is the container actually there? ──────────────────────────────────────

const running = spawnSync(
	'docker',
	['ps', '--filter', `name=^/${CONTAINER}$`, '--format', '{{.Names}}'],
	{ encoding: 'utf8' },
);

if (running.error) {
	console.error(`✗ Could not run docker: ${running.error.message}`);
	console.error('  Is Docker Desktop running and `docker` on PATH?');
	process.exit(1);
}
if (running.stdout.trim() !== CONTAINER) {
	console.error(`✗ Container "${CONTAINER}" is not running.`);
	console.error('  Start the AIO stack first, or set TOPDESK_AIO_CONTAINER.');
	process.exit(1);
}

console.log(`Container:   ${CONTAINER}`);
console.log(`App dir:     ${APP_DIR}`);
console.log(`Source:      ${SRC}`);
if (DRY_RUN) {
	console.log('Mode:        DRY RUN — nothing will be changed');
}
console.log('');

// ── 3. Copy ──────────────────────────────────────────────────────────────────

// First deploy onto a clean instance: the directory does not exist yet, and
// `docker cp` will not create the parent for us.
execRoot(`mkdir -p '${APP_DIR}'`);

let copied = 0;
let skipped = 0;

for (const entry of PUBLISHABLE) {
	const srcPath = path.join(SRC, entry);
	if (!fs.existsSync(srcPath)) {
		console.warn(`  skip     ${entry}  (not in source)`);
		skipped++;
		continue;
	}

	// Delete-then-copy, not an additive copy. Vite emits fingerprinted chunk
	// names (js/chunks/NcSelect-DK4ZWMha-0KQfeRqm.mjs), so copying over the top
	// leaves every chunk from every previous deploy in place forever.
	//
	// APP_DIR and entry are both constants from this file — no caller input
	// reaches this string.
	execRoot(`rm -rf '${APP_DIR}/${entry}'`);
	docker(['cp', `./${entry}`, `${CONTAINER}:${APP_DIR}/`]);

	console.log(`  deployed ${entry}${fs.statSync(srcPath).isDirectory() ? '/' : ''}`);
	copied++;
}

// docker cp preserves nothing useful about ownership — everything lands as root,
// and Nextcloud refuses to read an app tree it does not own.
execRoot(`chown -R www-data:www-data '${APP_DIR}'`);
console.log('  chowned  www-data:www-data');

console.log('');
console.log(`${copied} entr(ies) deployed, ${skipped} skipped.`);

// ── 4. Enable, if asked ──────────────────────────────────────────────────────

if (ENABLE) {
	console.log('');
	console.log(`Enabling ${APP_ID}…`);
	const enable = execOcc(['app:enable', APP_ID], { capture: true, allowFail: true });
	if (!DRY_RUN) {
		const out = `${enable.stdout || ''}${enable.stderr || ''}`.trim();
		if (out) {
			console.log(out.split('\n').map((l) => `  ${l}`).join('\n'));
		}
		if (enable.status !== 0) {
			console.error(`\n✗ occ app:enable exited ${enable.status} — see the output above.`);
			console.error('  The files are deployed but the app is not enabled.');
			process.exit(1);
		}
	}
}

// ── 5. Migrations ────────────────────────────────────────────────────────────

// This app has no migrations today — it keeps its state in appconfig and
// oc_preferences. `occ upgrade` is still the documented post-update step, and it
// is what would apply a migration the day one is added, so it runs regardless.
console.log('');
console.log('Running occ upgrade…');

// Exit code 3 is Nextcloud's "no upgrade required", which is the normal outcome
// of a deploy that did not change the app version. Treat it as success and say
// so, rather than failing a deploy that worked.
const upgrade = execOcc(['upgrade'], { capture: true, allowFail: true });
if (!DRY_RUN) {
	const out = `${upgrade.stdout || ''}${upgrade.stderr || ''}`.trim();
	if (out) {
		console.log(out.split('\n').map((l) => `  ${l}`).join('\n'));
	}
	if (upgrade.status !== 0 && upgrade.status !== 3) {
		console.error(`\n✗ occ upgrade exited ${upgrade.status} — see the output above.`);
		console.error('  The files are deployed; the database may not be migrated.');
		process.exit(1);
	}
}

// ── 6. Clear the cached route table ──────────────────────────────────────────

if (RESTART) {
	console.log('');
	console.log('Restarting container…');
	docker(['restart', CONTAINER]);
} else if (!DRY_RUN) {
	// Since NC 30 the web router serialises every app's compiled routes into the
	// LOCAL cache — APCu inside php-fpm — for an hour. The cache prefix hashes
	// every enabled app's installed version, so a real version bump invalidates
	// it; a redeploy of a changed routes.php under the SAME version keeps the old
	// table, and opcache revalidation does not touch it. The new route then
	// answers 404 for up to an hour while `php -r` inside the container (its own
	// APCu) matches it perfectly. SIGUSR2 makes the php-fpm master re-exec and
	// discard the segment; in-flight requests finish first.
	//
	// This app adds routes often enough during development to be worth doing
	// every time rather than remembering when it matters.
	console.log('');
	console.log('Reloading php-fpm (drops the cached route table)…');
	execRoot(
		'kill -USR2 "$(ps -eo pid,args --no-headers | grep "php-fpm: master" | grep -v grep | awk \'{print $1}\')"',
		{ allowFail: true },
	);
}

// ── 7. Report ────────────────────────────────────────────────────────────────

if (!DRY_RUN) {
	const installed = execOcc(['config:app:get', APP_ID, 'installed_version'],
		{ capture: true, allowFail: true });
	const reported = (installed.stdout || '').trim();

	console.log('');
	if (reported) {
		console.log(`Instance now reports ${APP_ID} ${reported} (working copy: ${version}).`);
	} else {
		// An empty installed_version means Nextcloud has never installed this app,
		// which on a first deploy is the expected state and the one thing that
		// would otherwise look like a successful deploy doing nothing at all.
		console.log(`Instance reports no installed version for ${APP_ID} — it is not enabled yet.`);
		console.log('  Run again with --enable, or:');
		console.log(`    docker exec --user www-data ${CONTAINER} php occ app:enable ${APP_ID}`);
	}
}

if (!RESTART && !DRY_RUN) {
	// This instance runs opcache.validate_timestamps=On with revalidate_freq=60,
	// so changed PHP is picked up within a minute on its own. If a PHP change
	// appears not to have landed, wait 60s before debugging it.
	console.log('');
	console.log('PHP changes take effect within ~60s (opcache revalidate_freq=60);');
	console.log('frontend changes are immediate. Pass --restart to skip the wait.');
}
