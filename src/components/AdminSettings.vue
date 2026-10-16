<template>
	<div id="topdesk-admin" class="section">
		<h2>{{ t('integration_topdesk', 'TOPdesk') }}</h2>
		<p class="settings-hint">
			{{ t('integration_topdesk', 'Connect this server to the customer\'s TOPdesk SaaS environment. Users then see their own tickets on their dashboard and can report a new one from it.') }}
		</p>

		<div class="topdesk-admin__field">
			<NcTextField v-model="form.topdesk_url"
				:label="t('integration_topdesk', 'TOPdesk URL')"
				placeholder="https://acme.topdesk.net"
				:helper-text="t('integration_topdesk', 'The address of the environment itself. A pasted-in /tas/api is removed for you.')" />
		</div>

		<h3>{{ t('integration_topdesk', 'Authentication') }}</h3>

		<NcCheckboxRadioSwitch :model-value="form.auth_mode"
			value="apptoken"
			name="topdesk-auth-mode"
			type="radio"
			@update:model-value="form.auth_mode = $event">
			{{ t('integration_topdesk', 'Application token') }}
		</NcCheckboxRadioSwitch>
		<NcCheckboxRadioSwitch :model-value="form.auth_mode"
			value="oauth_cc"
			name="topdesk-auth-mode"
			type="radio"
			@update:model-value="form.auth_mode = $event">
			{{ t('integration_topdesk', 'OAuth connected application') }}
		</NcCheckboxRadioSwitch>

		<!-- Application token: works on every TOPdesk 7.11.005 and later, which is
		     why it is the default. -->
		<template v-if="form.auth_mode === 'apptoken'">
			<p class="settings-hint">
				{{ t('integration_topdesk', 'In TOPdesk, give the API operator a permission group with read on "API access > REST API" and write on "API access > Use application tokens", then create a token on its card. The token is shown only once, and expires one year after creation unless you change the date.') }}
			</p>
			<div class="topdesk-admin__field">
				<NcTextField v-model="form.login_name"
					:label="t('integration_topdesk', 'Login name')"
					placeholder="nextcloud-api" />
			</div>
			<div class="topdesk-admin__field">
				<NcTextField v-model="form.app_token"
					type="password"
					:label="t('integration_topdesk', 'Application token')"
					:placeholder="config.has_app_token ? storedPlaceholder : ''"
					:helper-text="secretHelper(config.has_app_token)" />
			</div>
		</template>

		<!-- OAuth client credentials: an "Application integration" connected
		     application. No long-lived password, but it needs a TOPdesk new enough
		     to have the Connected applications screen. -->
		<template v-else>
			<p class="settings-hint">
				{{ t('integration_topdesk', 'In TOPdesk, go to Modules > Supporting Files > System integrations overview > Connected applications and create an application integration. The secret is shown only once — if it is lost, delete the client and make a new one.') }}
			</p>
			<div class="topdesk-admin__field">
				<NcTextField v-model="form.client_id"
					:label="t('integration_topdesk', 'Client ID')" />
			</div>
			<div class="topdesk-admin__field">
				<NcTextField v-model="form.client_secret"
					type="password"
					:label="t('integration_topdesk', 'Client secret')"
					:placeholder="config.has_client_secret ? storedPlaceholder : ''"
					:helper-text="secretHelper(config.has_client_secret)" />
			</div>
		</template>

		<h3>{{ t('integration_topdesk', 'Matching users to TOPdesk people') }}</h3>
		<p class="settings-hint">
			{{ t('integration_topdesk', 'Each Nextcloud user is matched to one TOPdesk person card, and their tickets are then read by that person\'s internal ID rather than by the address they were matched on. The pair is re-checked at every login: if the address has been handed to someone else since, the match is replaced so the new colleague never sees their predecessor\'s tickets.') }}
		</p>

		<div class="topdesk-admin__row">
			<div class="topdesk-admin__field">
				<label class="topdesk-admin__label" for="topdesk-nc-field">
					{{ t('integration_topdesk', 'Match this Nextcloud field') }}
				</label>
				<NcSelect id="topdesk-nc-field"
					v-model="ncField"
					:options="ncFieldOptions"
					label="label"
					:clearable="false" />
			</div>
			<div class="topdesk-admin__field">
				<label class="topdesk-admin__label" for="topdesk-td-field">
					{{ t('integration_topdesk', 'to this TOPdesk person field') }}
				</label>
				<NcSelect id="topdesk-td-field"
					v-model="tdField"
					:options="tdFieldOptions"
					label="label"
					:clearable="false" />
			</div>
		</div>
		<NcNoteCard v-if="ncField.id === 'email'" type="warning" class="topdesk-admin__note">
			{{ t('integration_topdesk', 'Matching on the email address assumes users cannot freely change it. On most instances they can, in their own personal settings — and an address they set is an address they get the tickets of. If your users are not provisioned from LDAP, Entra ID or SAML, either stop them changing it or match on the username instead.') }}
		</NcNoteCard>

		<p v-if="tdField.id === 'tasLoginName'" class="settings-hint topdesk-admin__warning">
			{{ t('integration_topdesk', 'Reading the TOPdesk login name needs the "Settings > Login Settings" permission on the API operator. Without it, every match will come back empty.') }}
		</p>

		<h3>{{ t('integration_topdesk', 'Troubleshooting') }}</h3>

		<NcCheckboxRadioSwitch :model-value="form.debug_matching"
			type="switch"
			@update:model-value="form.debug_matching = $event">
			{{ t('integration_topdesk', 'Log matching details') }}
		</NcCheckboxRadioSwitch>
		<p class="settings-hint">
			{{ t('integration_topdesk', 'Writes the full step-by-step match for every user to the Nextcloud log: the field used, the value it came from, the query sent to TOPdesk and what came back. That includes the email addresses or usernames being matched, which is personal data — turn it on to troubleshoot, then turn it off again.') }}
		</p>
		<p class="settings-hint">
			{{ t('integration_topdesk', 'Entries are written at warning level, so they appear at the default log level. Search the log for "TOPdesk match trace".') }}
		</p>
		<p class="settings-hint">
			{{ t('integration_topdesk', 'To trace one user without touching the log, run on the server: occ topdesk:diagnose <user>. Add --all for a summary of every user. It is read-only unless you pass --repair.') }}
		</p>

		<div class="topdesk-admin__buttons">
			<NcButton type="primary" :disabled="busy" @click="save">
				<template v-if="saving" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('integration_topdesk', 'Save') }}
			</NcButton>
			<NcButton :disabled="busy" @click="saveAndTest">
				<template v-if="testing" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('integration_topdesk', 'Save and test connection') }}
			</NcButton>
		</div>

		<NcNoteCard v-if="result" :type="result.success ? 'success' : 'error'" class="topdesk-admin__result">
			<p>{{ result.message }}</p>
			<p v-if="result.success && result.apiVersion">
				{{ t('integration_topdesk', 'API version {version}', { version: result.apiVersion }) }}
				<template v-if="result.productVersion">
					· {{ t('integration_topdesk', 'TOPdesk {version}', { version: result.productVersion }) }}
				</template>
			</p>
			<p v-if="result.hint">
				{{ result.hint }}
			</p>
		</NcNoteCard>
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'
// Deep imports — see the note in TicketsWidget.vue.
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'

import { api, errorMessage } from '../api.js'

const APP_ID = 'integration_topdesk'

/**
 * Human labels for the TOPdesk person-card fields that can carry a shared
 * identifier. The field names themselves are what the API expects; the labels are
 * what they are called on the person card an admin is looking at.
 */
const TD_FIELD_LABELS = {
	email: t(APP_ID, 'Email address'),
	networkLoginName: t(APP_ID, 'Network login name'),
	employeeNumber: t(APP_ID, 'Employee number'),
	tasLoginName: t(APP_ID, 'TOPdesk login name'),
}

export default {
	name: 'AdminSettings',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcTextField,
	},

	data() {
		const config = loadState(APP_ID, 'admin-config')
		const tdFields = loadState(APP_ID, 'td-match-fields', Object.keys(TD_FIELD_LABELS))

		const ncFieldOptions = [
			{ id: 'email', label: t(APP_ID, 'Email address') },
			{ id: 'uid', label: t(APP_ID, 'Username') },
		]
		const tdFieldOptions = tdFields.map((id) => ({ id, label: TD_FIELD_LABELS[id] ?? id }))

		return {
			config,
			ncFieldOptions,
			tdFieldOptions,
			ncField: ncFieldOptions.find((o) => o.id === config.match_nc_field) ?? ncFieldOptions[0],
			tdField: tdFieldOptions.find((o) => o.id === config.match_td_field) ?? tdFieldOptions[0],
			form: {
				topdesk_url: config.topdesk_url,
				auth_mode: config.auth_mode,
				login_name: config.login_name,
				client_id: config.client_id,
				// Secrets start empty. An empty secret on save means "keep the stored
				// one", which is what lets this page save without ever having
				// received the current token.
				app_token: '',
				client_secret: '',
				debug_matching: config.debug_matching ?? false,
			},
			saving: false,
			testing: false,
			result: null,
			storedPlaceholder: '••••••••••••••••',
		}
	},

	computed: {
		busy() {
			return this.saving || this.testing
		},
	},

	methods: {
		t,

		secretHelper(isStored) {
			return isStored
				? t(APP_ID, 'A value is stored. Leave this empty to keep it.')
				: ''
		},

		async save() {
			this.saving = true
			this.result = null

			try {
				this.config = await api.setAdminConfig({
					...this.form,
					match_nc_field: this.ncField.id,
					match_td_field: this.tdField.id,
				})
				// Clear the inputs now the values are stored, so a second save does
				// not resend them and the fields show the "stored" placeholder.
				this.form.app_token = ''
				this.form.client_secret = ''
				showSuccess(t(APP_ID, 'Saved'))

				return true
			} catch (error) {
				showError(errorMessage(error, t(APP_ID, 'Could not save the settings')))

				return false
			} finally {
				this.saving = false
			}
		},

		/**
		 * The test runs against what is *stored*, not what is in the form — so a
		 * green result means the connection the dashboard will actually use works.
		 * That is why this saves first.
		 */
		async saveAndTest() {
			if (!await this.save()) {
				return
			}

			this.testing = true
			try {
				this.result = await api.testConnection()
			} catch (error) {
				this.result = {
					success: false,
					message: errorMessage(error, t(APP_ID, 'Could not test the connection')),
				}
			} finally {
				this.testing = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
#topdesk-admin {
	max-width: 700px;

	h3 {
		margin-top: 24px;
		font-weight: 600;
	}
}

.topdesk-admin {
	&__field {
		flex: 1 1 260px;
		margin-top: 12px;
	}

	&__row {
		display: flex;
		flex-wrap: wrap;
		gap: 16px;
	}

	&__label {
		display: block;
		margin-bottom: 4px;
		font-weight: 600;
	}

	&__warning {
		color: var(--color-warning-text, var(--color-text-maxcontrast));
	}

	&__note {
		margin-top: 16px;
	}

	&__buttons {
		display: flex;
		flex-wrap: wrap;
		gap: 8px;
		margin-top: 24px;
	}

	&__result {
		margin-top: 16px;

		p {
			margin: 0;
		}
	}
}
</style>
