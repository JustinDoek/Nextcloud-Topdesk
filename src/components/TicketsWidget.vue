<template>
	<div class="topdesk-widget">
		<NcLoadingIcon v-if="loading" class="topdesk-widget__loading" :size="32" />

		<NcEmptyContent v-else-if="emptyMessage" :name="emptyMessage" :description="emptyDescription">
			<template #icon>
				<AlertCircleOutlineIcon v-if="isProblem" />
				<TicketOutlineIcon v-else />
			</template>
			<template #action>
				<!-- A matching problem is the one empty state the user can act on,
				     and personal settings is where they act on it. -->
				<NcButton v-if="isProblem" :href="personalSettingsUrl">
					{{ t('integration_topdesk', 'Check your TOPdesk link') }}
				</NcButton>
				<NcButton v-else-if="canCreate" type="primary" @click="showModal = true">
					<template #icon>
						<PlusIcon :size="20" />
					</template>
					{{ t('integration_topdesk', 'Report a ticket') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<template v-else>
			<ul class="topdesk-widget__list">
				<li v-for="incident in incidents" :key="incident.id" class="topdesk-ticket">
					<a class="topdesk-ticket__link"
						:href="incident.url"
						target="_blank"
						rel="noopener noreferrer"
						:title="incident.briefDescription">
						<span class="topdesk-ticket__state" :class="stateClass(incident)" aria-hidden="true" />
						<span class="topdesk-ticket__body">
							<span class="topdesk-ticket__title">
								{{ incident.briefDescription || incident.number }}
							</span>
							<span class="topdesk-ticket__meta">
								{{ metaFor(incident) }}
							</span>
						</span>
					</a>
				</li>
			</ul>

			<div class="topdesk-widget__actions">
				<NcButton type="primary" :wide="true" @click="showModal = true">
					<template #icon>
						<PlusIcon :size="20" />
					</template>
					{{ t('integration_topdesk', 'Report a ticket') }}
				</NcButton>
			</div>
		</template>

		<CreateTicketModal v-if="showModal"
			@close="showModal = false"
			@created="onCreated" />
	</div>
</template>

<script>
import { showSuccess } from '@nextcloud/dialogs'
import { getCanonicalLocale, translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
// Deep imports, not `from '@nextcloud/vue'`. The package root is a barrel that
// re-exports every component, so importing three things through it drags all ~150
// into the module graph — enough to run the build out of heap during chunk
// rendering. The ./components/* subpath export exists for this.
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import AlertCircleOutlineIcon from 'vue-material-design-icons/AlertCircleOutline.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import TicketOutlineIcon from 'vue-material-design-icons/TicketOutline.vue'

import { api, errorMessage } from '../api.js'
import CreateTicketModal from './CreateTicketModal.vue'

/** Link states where the user has something to fix, rather than simply no tickets. */
const PROBLEM_STATES = ['unmatched', 'ambiguous', 'no_match_value']

export default {
	name: 'TicketsWidget',

	components: {
		AlertCircleOutlineIcon,
		CreateTicketModal,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		PlusIcon,
		TicketOutlineIcon,
	},

	data() {
		return {
			loading: true,
			incidents: [],
			link: null,
			loadError: '',
			showModal: false,
		}
	},

	computed: {
		isProblem() {
			return PROBLEM_STATES.includes(this.link?.state) || this.loadError !== ''
		},

		canCreate() {
			return this.link?.usable === true
		},

		/**
		 * The headline for an empty widget. Returns '' when there are tickets to
		 * show, which is what switches the template over to the list.
		 *
		 * @return {string}
		 */
		emptyMessage() {
			if (this.loadError !== '') {
				return t('integration_topdesk', 'Could not load your tickets')
			}
			if (this.incidents.length > 0) {
				return ''
			}

			switch (this.link?.state) {
			case 'not_configured':
				return t('integration_topdesk', 'TOPdesk is not set up yet')
			case 'no_match_value':
				return t('integration_topdesk', 'Your account has no email address')
			case 'unmatched':
				return t('integration_topdesk', 'No TOPdesk person found for you')
			case 'ambiguous':
				return t('integration_topdesk', 'Your email address matches several people')
			default:
				return t('integration_topdesk', 'No tickets')
			}
		},

		emptyDescription() {
			if (this.loadError !== '') {
				return this.loadError
			}

			switch (this.link?.state) {
			case 'not_configured':
				return t('integration_topdesk', 'Ask an administrator to connect this server to TOPdesk.')
			case 'no_match_value':
				return t('integration_topdesk', 'Add an email address to your account, or pick your TOPdesk person by hand.')
			case 'unmatched':
				return t('integration_topdesk', 'Nobody in TOPdesk has your email address. You can pick your TOPdesk person by hand.')
			case 'ambiguous':
				return t('integration_topdesk', 'More than one person in TOPdesk has your email address, so none was chosen for you. Pick the right one by hand.')
			default:
				return t('integration_topdesk', 'Nothing reported yet.')
			}
		},

		personalSettingsUrl() {
			return generateUrl('/settings/user/integration_topdesk')
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		async load() {
			try {
				const data = await api.listIncidents(5)
				this.incidents = data.incidents
				this.link = data.link
				this.loadError = ''
			} catch (error) {
				this.loadError = errorMessage(error, t('integration_topdesk', 'Could not reach TOPdesk'))
			} finally {
				this.loading = false
			}
		},

		onCreated(incident) {
			showSuccess(t('integration_topdesk', 'Ticket {number} reported', { number: incident.number }))
			this.showModal = false
			// Re-read rather than unshifting the new ticket in: the list is "the five
			// latest", and the server is the one that decides what that is.
			this.load()
		},

		/**
		 * `I 2510 042 · In progress · 2 days ago`
		 *
		 * @param {object} incident a normalised incident
		 * @return {string}
		 */
		metaFor(incident) {
			return [incident.number, incident.status, this.relativeDate(incident.creationDate)]
				.filter(Boolean)
				.join(' · ')
		},

		stateClass(incident) {
			if (incident.closed) {
				return 'topdesk-ticket__state--closed'
			}
			if (incident.completed) {
				return 'topdesk-ticket__state--completed'
			}
			return 'topdesk-ticket__state--open'
		},

		/**
		 * "3 days ago", in the user's locale.
		 *
		 * @param {string} iso an ISO 8601 timestamp, as the API returns with
		 *   dateFormat=iso8601
		 * @return {string} '' when the value is missing or unparseable, so the
		 *   caller's filter(Boolean) drops it
		 */
		relativeDate(iso) {
			if (!iso) {
				return ''
			}

			const then = new Date(iso)
			if (Number.isNaN(then.getTime())) {
				return ''
			}

			const seconds = (then.getTime() - Date.now()) / 1000
			const units = [
				['year', 31536000],
				['month', 2592000],
				['week', 604800],
				['day', 86400],
				['hour', 3600],
				['minute', 60],
			]

			const formatter = new Intl.RelativeTimeFormat(getCanonicalLocale(), { numeric: 'auto' })
			for (const [unit, size] of units) {
				if (Math.abs(seconds) >= size) {
					return formatter.format(Math.round(seconds / size), unit)
				}
			}

			return formatter.format(Math.round(seconds), 'second')
		},
	},
}
</script>

<style scoped lang="scss">
.topdesk-widget {
	display: flex;
	flex-direction: column;
	gap: 8px;
	min-height: 0;

	&__loading {
		margin: 24px auto;
	}

	&__list {
		display: flex;
		flex-direction: column;
	}

	&__actions {
		padding: 4px 8px 8px;
	}
}

.topdesk-ticket {
	&__link {
		display: flex;
		align-items: center;
		gap: 10px;
		padding: 6px 8px;
		border-radius: var(--border-radius-large, 12px);
		color: var(--color-main-text);

		&:hover,
		&:focus-visible {
			background-color: var(--color-background-hover);
		}
	}

	&__state {
		flex: 0 0 auto;
		width: 8px;
		height: 8px;
		border-radius: 50%;
		background-color: var(--color-primary-element);

		&--completed {
			background-color: var(--color-success, #46ba61);
		}

		&--closed {
			background-color: var(--color-text-maxcontrast);
		}
	}

	&__body {
		display: flex;
		flex-direction: column;
		min-width: 0;
	}

	&__title {
		overflow: hidden;
		white-space: nowrap;
		text-overflow: ellipsis;
	}

	&__meta {
		overflow: hidden;
		white-space: nowrap;
		text-overflow: ellipsis;
		color: var(--color-text-maxcontrast);
		font-size: 0.85em;
	}
}
</style>
