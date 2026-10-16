import { createAppConfig } from '@nextcloud/vite-config'
import { defineConfig } from 'vite'

/**
 * Build configuration for integration_topdesk.
 *
 * The layout follows the TeamHub app's, which arrived at it the hard way:
 *
 *  - Entries are emitted as `js/<name>.mjs`. Nextcloud adds `type="module"` to any
 *    script whose filename ends in .mjs, and `Util::addScript()` references the name
 *    without the extension — so `Util::addScript('integration_topdesk', 'admin')`
 *    resolves to `js/admin.mjs`.
 *
 *  - CSS is **extracted**, not inlined. `inlineCSS: true` looks tempting for a
 *    multi-entry app but only attaches each component's style side-effect to the
 *    *main* entry's copy of that component, so secondary entries ship with no style
 *    injection at all and their pages render as unstyled HTML. Extraction per entry
 *    is the supported path.
 *
 *  - Extracted CSS lands in `css/`, because Nextcloud serves app styles from there
 *    and does not treat the `js/` subtree as a style root (styles under `js/css/`
 *    404). The `vite-` prefix keeps the build output from colliding with any
 *    hand-written stylesheet.
 *
 *  - Chunk CSS names are unhashed but *not* stable — Rollup names a shared chunk
 *    after one of its modules and regroups on any import-graph change. So nothing
 *    names chunks by hand: PHP reads each entry's `css/<entry>.css` manifest stub
 *    at render time. See lib/Service/ViteStyles.php.
 *
 * outDir is the app root rather than `js/`, because Rollup forbids `../` in
 * assetFileNames — there is no way to emit CSS from inside an outDir of `js/` up
 * into `css/`. Rooting the build at `.` and routing each output type by filename
 * pattern is the way to get both. emptyOutDir is false so a build never wipes the
 * app directory.
 */
export default createAppConfig(
	{
		dashboard: 'src/dashboard.js',
		admin: 'src/admin.js',
		personal: 'src/personal.js',
	},
	{
		// createAppConfig turns on REUSELicensesPlugin by default, which scans for
		// license information. In this working copy that scan never finishes: a
		// one-line entry with nothing but a console.log took over ten minutes and
		// then ran Node out of heap during "rendering chunks", while the same entry
		// with the plugin off built in 78 ms. It is not the module graph — the same
		// thing happens with the whole app or with one trivial file, and it survived
		// pinning vite, rollup, TypeScript and @nextcloud/vue to the versions that
		// build fine in the TeamHub app next door.
		//
		// The cost of switching it off is the generated `.license` sidecar files
		// (REUSE compliance metadata). The app's own licence is declared in
		// appinfo/info.xml and composer.json, so nothing a user or the App Store
		// relies on is lost. Worth retrying on a future vite-config release.
		extractLicenseInformation: false,
		config: defineConfig({
			build: {
				outDir: '.',
				emptyOutDir: false,
				cssCodeSplit: true,
				rollupOptions: {
					output: {
						entryFileNames: 'js/[name].mjs',
						chunkFileNames: 'js/chunks/[name]-[hash].mjs',
						assetFileNames: (assetInfo) => {
							const name = assetInfo.name || ''
							if (name.endsWith('.css')) {
								return 'css/vite-[name][extname]'
							}
							return 'js/assets/[name]-[hash][extname]'
						},
					},
				},
			},
		}),
	},
)
