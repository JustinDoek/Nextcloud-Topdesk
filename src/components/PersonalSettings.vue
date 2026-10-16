<template>
	<div id="topdesk-personal" class="section">
		<h2>{{ t('integration_topdesk', 'TOPdesk') }}</h2>

		<NcNoteCard v-if="!configured" type="info">
			{{ t('integration_topdesk', 'This server is not connected to TOPdesk yet. Ask an administrator to set it up.') }}
		</NcNoteCard>

		<template v-else>
			<p class="settings-hint">
				{{ t('integration_topdesk', 'Your dashboard shows the tickets of the TOPdesk person you are matched to. The match is checked again every time you log in.') }}
			</p>

			<div class="topdesk-personal__state" :class="'topdesk-personal__state--' + severity">
				<p class="topdesk-personal__headline">
					{{ headline }}
				</p>
				<p class="topdesk-personal__detail">
					{{ detail }}
				</p>
			</div>

			<div class="topdesk-personal__buttons">
				<NcButton :disabled="busy" @click="refresh">
					<template v-if="refreshing" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('integration_topdesk', 'Check again') }}
				</NcButton>
				<NcButton v-if="link && link.pinned" :disabled="busy" @click="unpin">
					{{ t('integration_topdesk', 'Go back to automatic matching') }}
				</NcButton>
			</div>

			<h3>{{ t('integration_topdesk', 'Pick your TOPdesk person by hand') }}</h3>
			<p class="settings-hint">
				{{ t('integration_topdesk', 'Use this when automatic matching cannot settle it — a shared mailbox, a different address in TOPdesk, or two person cards with the same address. A hand-picked person stays until you undo it.') }}
			</p>

			<div class="topdesk-personal__field">
				<NcSelect v-model="selected"
					:options="candidates"
					:loading="searching"
					:disabled="busy"
					:placeholder="t('integration_topdesk', 'Search for your name in TOPdesk')"
					label="name"
					:filterable="false"
					@search="onSearch" />
			</div>

			<NcButton type="primary" :disabled="busy || !selected" @click="pin">
				<template v-if="pinning" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('integration_topdesk', 'Use this person') }}
			</NcButton>
		</template>
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'
// Deep imports — see the note in TicketsWidget.vue.
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'

import { api, errorMessage } from '../api.js'

const APP_ID = 'integration_topdesk'

/** How long to sit on keystrokes before asking TOPdesk. */
const SEARCH_DEBOUNCE_MS = 300

export default {
	name: 'PersonalSettings',

	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
	},

	data() {
		return {
			configured: loadState(APP_ID, 'configured', false),
			link: loadState(APP_ID, 'personal-link', null),
			candidates: [],
			selected: null,
			searching: false,
			refreshing: false,
			pinning: false,
			searchTimer: null,
		}
	},

	computed: {
		busy() {
			return this.refreshing || this.pinning
		},

		/** Drives the colour of the state block: ok, warning or neutral. */
		severity() {
			if (this.link?.usable) {
				return 'ok'
			}
			if (['unmatched', 'ambiguous', 'no_match_value'].includes(this.link?.state)) {
				return 'warning'
			}

			return 'neutral'
		},

		headline() {
			if (this.link?.usable) {
				const name = this.link.personName || this.link.personEmail || this.link.personId
				return this.link.pinned
					? t(APP_ID, 'Linked by hand to {name}', { name })
					: t(APP_ID, 'Linked to {name}', { name })
			}

			switch (this.link?.state) {
			case 'no_match_value':
				return t(APP_ID, 'Your account has no email address')
			case 'unmatched':
				return t(APP_ID, 'Not linked to anyone in TOPdesk')
			case 'ambiguous':
				return t(APP_ID, 'More than one TOPdesk person matches you')
			default:
				return t(APP_ID, 'Not linked')
			}
		},

		detail() {
			if (this.link?.state === 'unverified') {
				return t(APP_ID, 'TOPdesk could not be reached to check this, so the previous match is still being used.')
			}

			if (this.link?.usable) {
				return this.link.pinned
					? t(APP_ID, 'Automatic matching is switched off for your account.')
					: t(APP_ID, 'Matched on {value}.', { value: this.link.matchValue })
			}

			switch (this.link?.state) {
			case 'no_match_value':
				return t(APP_ID, 'Add an email address to your account, or pick your TOPdesk person below.')
			case 'unmatched':
				return t(APP_ID, 'Nobody in TOPdesk has {value}. Pick your person below, or ask your service desk to add the address to your person card.', { value: this.link.matchValue })
			case 'ambiguous':
				return t(APP_ID, 'Several person cards in TOPdesk have {value}, so none was chosen for you. Pick the right one below.', { value: this.link.matchValue })
			default:
				return ''
			}
		},
	},

	beforeUnmount() {
		clearTimeout(this.searchTimer)
	},

	methods: {
		t,

		async refresh() {
			this.refreshing = true
			try {
				this.link = await api.refreshLink()
			} catch (error) {
				showError(errorMessage(error, t(APP_ID, 'Could not check your TOPdesk link')))
			} finally {
				this.refreshing = false
			}
		},

		onSearch(term) {
			clearTimeout(this.searchTimer)

			if (!term || term.length < 2) {
				this.candidates = []
				return
			}

			this.searchTimer = setTimeout(async () => {
				this.searching = true
				try {
					this.candidates = await api.searchPersons(term)
				} catch (error) {
					showError(errorMessage(error, t(APP_ID, 'Could not search TOPdesk')))
				} finally {
					this.searching = false
				}
			}, SEARCH_DEBOUNCE_MS)
		},

		async pin() {
			if (!this.selected) {
				return
			}

			this.pinning = true
			try {
				this.link = await api.pinLink(this.selected.id)
				this.selected = null
				this.candidates = []
				showSuccess(t(APP_ID, 'Linked to {name}', { name: this.link.personName }))
			} catch (error) {
				showError(errorMessage(error, t(APP_ID, 'Could not link that person')))
			} finally {
				this.pinning = false
			}
		},

		async unpin() {
			this.pinning = true
			try {
				this.link = await api.unpinLink()
				showSuccess(t(APP_ID, 'Automatic matching switched back on'))
			} catch (error) {
				showError(errorMessage(error, t(APP_ID, 'Could not switch automatic matching back on')))
			} finally {
				this.pinning = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
#topdesk-personal {
	max-width: 700px;

	h3 {
		margin-top: 24px;
		font-weight: 600;
	}
}

.topdesk-personal {
	&__state {
		margin: 16px 0;
		padding: 12px 16px;
		border-inline-start: 4px solid var(--color-border);
		border-radius: var(--border-radius-large, 12px);
		background-color: var(--color-background-hover);

		&--ok {
			border-inline-start-color: var(--color-success, #46ba61);
		}

		&--warning {
			border-inline-start-color: var(--color-warning, #d4860a);
		}
	}

	&__headline {
		margin: 0;
		font-weight: 600;
	}

	&__detail {
		margin: 4px 0 0;
		color: var(--color-text-maxcontrast);
	}

	&__field {
		margin: 12px 0;
		max-width: 420px;
	}

	&__buttons {
		display: flex;
		flex-wrap: wrap;
		gap: 8px;
	}
}
</style>
