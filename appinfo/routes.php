<?php

declare(strict_types=1);

return [
	'routes' => [
		// Admin
		['name' => 'admin#setConfig', 'url' => '/admin/config', 'verb' => 'PUT'],
		['name' => 'admin#testConnection', 'url' => '/admin/test-connection', 'verb' => 'POST'],

		// The signed-in user's own TOPdesk link
		['name' => 'personal#getLink', 'url' => '/personal/link', 'verb' => 'GET'],
		['name' => 'personal#refreshLink', 'url' => '/personal/link/refresh', 'verb' => 'POST'],
		['name' => 'personal#pinLink', 'url' => '/personal/link/pin', 'verb' => 'PUT'],
		['name' => 'personal#unpinLink', 'url' => '/personal/link/pin', 'verb' => 'DELETE'],
		['name' => 'personal#searchPersons', 'url' => '/personal/persons', 'verb' => 'GET'],

		// Tickets, for the dashboard widget
		['name' => 'incident#index', 'url' => '/incidents', 'verb' => 'GET'],
		['name' => 'incident#create', 'url' => '/incidents', 'verb' => 'POST'],
		['name' => 'incident#formOptions', 'url' => '/incidents/form-options', 'verb' => 'GET'],
	],
];
