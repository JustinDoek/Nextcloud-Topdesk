import { createApp } from 'vue'
import TicketsWidget from './components/TicketsWidget.vue'

/**
 * Mounts the widget into the slot the Dashboard app hands us.
 *
 * The widget is also exposed through the dashboard's items API by the PHP side
 * (TicketsWidget::getItemsV2), which is what the mobile and desktop clients read.
 * This custom rendering exists for the browser, where the create-ticket form opens
 * in a modal that the generic list rendering has no room for.
 */
document.addEventListener('DOMContentLoaded', () => {
	OCA.Dashboard.register('integration_topdesk_tickets', (el) => {
		const app = createApp(TicketsWidget)
		app.mount(el)
	})
})
