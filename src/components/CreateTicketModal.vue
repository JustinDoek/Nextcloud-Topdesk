<template>
	<NcModal :name="t('integration_topdesk', 'Report a ticket')" size="normal" @close="$emit('close')">
		<form class="topdesk-create" @submit.prevent="submit">
			<h3 class="topdesk-create__heading">
				{{ t('integration_topdesk', 'Report a ticket') }}
			</h3>

			<div class="topdesk-create__field">
				<NcTextField v-model="briefDescription"
					:label="t('integration_topdesk', 'What is going on?')"
					:placeholder="t('integration_topdesk', 'e.g. My laptop will not start')"
					:maxlength="briefDescriptionMax"
					:error="remaining < 0"
					:helper-text="counterText"
					:disabled="submitting"
					required />
			</div>

			<div class="topdesk-create__field">
				<NcTextArea v-model="details"
					:label="t('integration_topdesk', 'Details (optional)')"
					:placeholder="t('integration_topdesk', 'Anything that helps whoever picks this up')"
					:rows="5"
					:disabled="submitting" />
			</div>

			<div v-if="categories.length > 0" class="topdesk-create__field">
				<label class="topdesk-create__label" for="topdesk-create-category">
					{{ t('integration_topdesk', 'Category (optional)') }}
				</label>
				<NcSelect id="topdesk-create-category"
					v-model="category"
					:options="categories"
					:disabled="submitting"
					:placeholder="t('integration_topdesk', 'No category')"
					label="name"
					:clearable="true" />
			</div>

			<div v-if="availableSubcategories.length > 0" class="topdesk-create__field">
				<label class="topdesk-create__label" for="topdesk-create-subcategory">
					{{ t('integration_topdesk', 'Subcategory (optional)') }}
				</label>
				<NcSelect id="topdesk-create-subcategory"
					v-model="subcategory"
					:options="availableSubcategories"
					:disabled="submitting"
					:placeholder="t('integration_topdesk', 'No subcategory')"
					label="name"
					:clearable="true" />
			</div>

			<NcNoteCard v-if="error" type="error" class="topdesk-create__error">
				{{ error }}
			</NcNoteCard>

			<div class="topdesk-create__buttons">
				<NcButton :disabled="submitting" @click="$emit('close')">
					{{ t('integration_topdesk', 'Cancel') }}
				</NcButton>
				<NcButton type="primary" :disabled="!canSubmit" native-type="submit">
					<template v-if="submitting" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('integration_topdesk', 'Report') }}
				</NcButton>
			</div>
		</form>
	</NcModal>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
// Deep imports — see the note in TicketsWidget.vue.
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'

import { api, errorMessage } from '../api.js'

export default {
	name: 'CreateTicketModal',

	components: {
		NcButton,
		NcLoadingIcon,
		NcModal,
		NcNoteCard,
		NcSelect,
		NcTextArea,
		NcTextField,
	},

	emits: ['close', 'created'],

	data() {
		return {
			briefDescription: '',
			details: '',
			category: null,
			subcategory: null,
			categories: [],
			subcategories: [],
			// Mirrors IncidentService::BRIEF_DESCRIPTION_MAX. The server sends the
			// real value along with the form options so the two cannot drift.
			briefDescriptionMax: 80,
			submitting: false,
			error: '',
		}
	},

	computed: {
		remaining() {
			return this.briefDescriptionMax - this.briefDescription.length
		},

		/**
		 * TOPdesk rejects a brief description over 80 characters outright, so the
		 * limit is worth showing rather than discovering on submit.
		 *
		 * @return {string}
		 */
		counterText() {
			return n(
				'integration_topdesk',
				'{count} character left',
				'{count} characters left',
				this.remaining,
				{ count: this.remaining },
			)
		},

		canSubmit() {
			return !this.submitting
				&& this.briefDescription.trim() !== ''
				&& this.remaining >= 0
		},

		/**
		 * Subcategories belonging to the chosen category, or all of them when no
		 * category is chosen — TOPdesk fills the category in itself from a
		 * subcategory, as long as that subcategory is not ambiguous.
		 *
		 * @return {Array} subcategory options
		 */
		availableSubcategories() {
			if (!this.category) {
				return this.subcategories
			}

			return this.subcategories.filter((sub) => sub.categoryId === this.category.id)
		},
	},

	watch: {
		category() {
			// A subcategory left over from the previous category would be rejected.
			if (this.subcategory && this.subcategory.categoryId !== this.category?.id) {
				this.subcategory = null
			}
		},
	},

	mounted() {
		this.loadOptions()
	},

	methods: {
		t,
		n,

		async loadOptions() {
			try {
				const options = await api.formOptions()
				this.categories = options.categories ?? []
				this.subcategories = options.subcategories ?? []
				if (options.briefDescriptionMax) {
					this.briefDescriptionMax = options.briefDescriptionMax
				}
			} catch (error) {
				// Both fields are optional on an incident, so a form without the
				// dropdowns still files a usable ticket. Not worth blocking on.
				console.debug('integration_topdesk: could not load form options', error)
			}
		},

		async submit() {
			if (!this.canSubmit) {
				return
			}

			this.submitting = true
			this.error = ''

			try {
				const incident = await api.createIncident({
					briefDescription: this.briefDescription.trim(),
					details: this.details,
					categoryId: this.category?.id ?? null,
					subcategoryId: this.subcategory?.id ?? null,
				})
				this.$emit('created', incident)
			} catch (error) {
				this.error = errorMessage(error, t('integration_topdesk', 'Could not report the ticket'))
			} finally {
				this.submitting = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.topdesk-create {
	display: flex;
	flex-direction: column;
	padding: 20px;

	&__heading {
		margin: 0 0 12px;
	}

	&__field {
		margin-bottom: 12px;
	}

	&__label {
		display: block;
		margin-bottom: 4px;
		font-weight: 600;
	}

	&__error {
		margin-top: 4px;
	}

	&__buttons {
		display: flex;
		justify-content: flex-end;
		gap: 8px;
		margin-top: 12px;
	}
}
</style>
