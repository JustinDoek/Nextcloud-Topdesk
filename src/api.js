import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export const APP_ID = 'integration_topdesk'

const url = (path) => generateUrl('/apps/' + APP_ID + path)

/**
 * Pull a message out of an error response.
 *
 * The controllers answer failures as `{ message }`, which is already written for a
 * human — a TOPdesk 400 arrives here as "brief description can only be 80 characters
 * long" rather than as a status code. Fall back to the axios message only when the
 * server said nothing useful.
 *
 * @param {object} error an axios error
 * @param {string} fallback shown when neither the server nor axios has anything
 * @return {string}
 */
export function errorMessage(error, fallback) {
	return error?.response?.data?.message || error?.message || fallback
}

export const api = {
	/** @return {Promise<object>} `{ link, incidents, portalUrl }` */
	async listIncidents(limit = 5) {
		const response = await axios.get(url('/incidents'), { params: { limit } })
		return response.data
	},

	/** @return {Promise<object>} the created incident */
	async createIncident(payload) {
		const response = await axios.post(url('/incidents'), payload)
		return response.data
	},

	/** @return {Promise<object>} `{ categories, subcategories, briefDescriptionMax }` */
	async formOptions() {
		const response = await axios.get(url('/incidents/form-options'))
		return response.data
	},

	// ── the signed-in user's own link ───────────────────────────────────────

	async getLink() {
		const response = await axios.get(url('/personal/link'))
		return response.data
	},

	async refreshLink() {
		const response = await axios.post(url('/personal/link/refresh'))
		return response.data
	},

	async pinLink(personId) {
		const response = await axios.put(url('/personal/link/pin'), { personId })
		return response.data
	},

	async unpinLink() {
		const response = await axios.delete(url('/personal/link/pin'))
		return response.data
	},

	async searchPersons(term) {
		const response = await axios.get(url('/personal/persons'), { params: { term } })
		return response.data.results
	},

	// ── admin ───────────────────────────────────────────────────────────────

	async setAdminConfig(values) {
		const response = await axios.put(url('/admin/config'), { values })
		return response.data
	},

	async testConnection() {
		const response = await axios.post(url('/admin/test-connection'))
		return response.data
	},
}
