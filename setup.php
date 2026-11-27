<?php
/*
 * SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * This file is part of plugin_api, a plugin for Cacti.
 * https://github.com/somethingwithproof/plugin_api
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation; either version 2 of the License, or (at your option)
 * any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 */

/*
 * Cacti includes setup.php on every request regardless of PHP version. Keep
 * this file PHP 8.0 parseable so sites running PHP 8.0 or 8.1 receive a clean
 * refusal instead of a fatal parse error.
 */

/**
 * Return true when the active PHP runtime satisfies the plugin's PHP 8.2 floor.
 *
 * @return bool
 */
function api_php_supported(): bool {
	return version_compare(PHP_VERSION, '8.2.0', '>=');
}

/**
 * Boot the plugin PSR-4 autoloader when running on a supported PHP runtime.
 *
 * @return bool
 */
function api_boot(): bool {
	if (!api_php_supported()) {
		return false;
	}

	require_once(__DIR__ . '/include/autoload.php');

	return true;
}

/**
 * Log and surface a clean refusal message on unsupported PHP runtimes.
 *
 * @return bool True if runtime is unsupported and execution must stop.
 */
function api_refuse_unsupported_php(): bool {
	if (api_php_supported()) {
		return false;
	}

	$message = __('The REST API plugin requires PHP %s or later. This server runs PHP %s, so the plugin was not installed or enabled.', '8.2.0', PHP_VERSION, 'api');

	cacti_log('ERROR: ' . $message, false, 'API');

	if (PHP_SAPI === 'cli') {
		fwrite(STDERR, $message . PHP_EOL);
	} elseif (function_exists('raise_message')) {
		raise_message('api_php', $message, MESSAGE_LEVEL_ERROR);
	}

	return true;
}

/**
 * Parse and return plugin metadata from INFO.
 *
 * @return array<string, string>
 */
function plugin_api_version(): array {
	$info = parse_ini_file(__DIR__ . '/INFO', true);

	if (!is_array($info) || !isset($info['info']) || !is_array($info['info'])) {
		return array();
	}

	$version = array();

	foreach ($info['info'] as $key => $value) {
		$version[(string) $key] = is_scalar($value) ? (string) $value : '';
	}

	return $version;
}

/**
 * Cacti plugin installation lifecycle callback.
 *
 * @return void
 */
function plugin_api_install(): void {
	if (api_refuse_unsupported_php()) {
		return;
	}

	plugin_api_upgrade();
}

/**
 * Cacti plugin configuration validation callback invoked before enabling.
 *
 * @return bool
 */
function plugin_api_check_config(): bool {
	if (api_refuse_unsupported_php()) {
		return false;
	}

	return plugin_api_upgrade();
}

/**
 * Idempotently run schema migrations and register hooks and RBAC realms.
 *
 * @return bool
 */
function plugin_api_upgrade(): bool {
	if (!api_boot()) {
		return false;
	}

	$info   = plugin_api_version();
	$schema = new \Somethingwithproof\Api\Schema(false);

	if ($schema->isCurrent() && $schema->recordedPluginVersion() === ($info['version'] ?? '') && $schema->hooksRegistered()) {
		return true;
	}

	if (!$schema->migrate()) {
		return false;
	}

	api_setup_hooks();
	$schema->recordPluginVersion($info['version'] ?? '0.1.0', $info['author'] ?? '', $info['homepage'] ?? '');

	return true;
}

/**
 * Register Cacti hooks and 3-tier RBAC realms.
 *
 * Kept procedural with *_setup_* naming because Cacti's
 * api_plugin_register_hook() and api_plugin_register_realm() validate the
 * caller's function name.
 *
 * @return void
 */
function api_setup_hooks(): void {
	global $config;

	if (!function_exists('replicate_out') && isset($config['library_path']) && is_file($config['library_path'] . '/poller.php')) {
		include_once($config['library_path'] . '/poller.php');
	}

	$enabled = (new \Somethingwithproof\Api\Schema(false))->pluginEnabled();

	foreach (\Somethingwithproof\Api\Schema::HOOKS as $hook => $function) {
		api_plugin_register_hook('api', $hook, $function, 'setup.php', $enabled);
	}

	foreach (\Somethingwithproof\Api\Pages::realms() as $realm) {
		api_plugin_register_realm('api', implode(',', $realm['files']), $realm['name'], $realm['default']);
	}
}

/**
 * Cacti plugin uninstall lifecycle callback honoring api_uninstall_keep_tables.
 *
 * @return void
 */
function plugin_api_uninstall(): void {
	if (!api_boot()) {
		return;
	}

	$schema = new \Somethingwithproof\Api\Schema(false);

	if (read_config_option('api_uninstall_keep_tables') == 'on') {
		$schema->untrackTables();
		cacti_log('NOTE: Uninstall kept plugin_api tables as configured', false, 'API');
	} else {
		$schema->forgetVersion();
	}
}

/**
 * Read declarative plugin settings from Cacti's settings table.
 *
 * @return \Somethingwithproof\Api\Settings
 */
function api_settings(): \Somethingwithproof\Api\Settings {
	$row = array();

	foreach (\Somethingwithproof\Api\Settings::NAMES as $name) {
		$row[$name] = read_config_option($name);
	}

	return \Somethingwithproof\Api\Settings::fromRow($row);
}

/**
 * Derive or retrieve the server-side HMAC key used for AIP-158 opaque page tokens.
 *
 * @return string
 */
function api_page_token_secret(): string {
	$existing = read_config_option('api_page_token_secret');

	if (is_string($existing) && strlen($existing) >= 32) {
		return $existing;
	}

	$generated = bin2hex(random_bytes(32));

	if (function_exists('db_execute_prepared')) {
		db_execute_prepared(
			'REPLACE INTO settings (name, value) VALUES (?, ?)',
			array('api_page_token_secret', $generated)
		);
	}

	return $generated;
}

/**
 * Discover sibling plugin REST resources registered via api_register_resources.
 *
 * @return \Somethingwithproof\Api\Extension\ResourceRegistry
 */
function api_resource_registry(): \Somethingwithproof\Api\Extension\ResourceRegistry {
	global $config;

	$registry = new \Somethingwithproof\Api\Extension\ResourceRegistry();

	if (!function_exists('db_fetch_assoc_prepared')) {
		return $registry;
	}

	$rows = db_fetch_assoc_prepared(
		'SELECT h.name, h.file, h.function FROM plugin_hooks AS h INNER JOIN plugin_config AS c ON c.directory = h.name WHERE h.hook = ? AND h.status = 1 AND c.status = 1 ORDER BY h.name',
		array('api_register_resources')
	);

	if (!is_array($rows)) {
		return $registry;
	}

	$hooks = array();

	foreach ($rows as $row) {
		if (is_array($row) && is_string($row['name'] ?? null) && is_string($row['file'] ?? null) && is_string($row['function'] ?? null)) {
			$hooks[] = array(
				'name'     => $row['name'],
				'file'     => $row['file'],
				'function' => $row['function'],
			);
		}
	}

	$basePath = is_string($config['base_path'] ?? null) ? $config['base_path'] : '';

	$registry->dispatchHooks($hooks, static function (string $slug, string $file) use ($basePath) {
		if (function_exists('cacti_plugin_path')) {
			return cacti_plugin_path($slug, $file);
		}

		if ($basePath === '') {
			return false;
		}

		return $basePath . '/plugins/' . $slug . '/' . $file;
	});

	return $registry;
}

/**
 * Build a live API Router bound to MariaDB, active Settings, and discovered sibling resources.
 *
 * @return \Somethingwithproof\Api\Http\Router
 */
function api_router(): \Somethingwithproof\Api\Http\Router {
	$repo     = new \Somethingwithproof\Api\Repository\CactiApiRepository(false);
	$settings = api_settings();
	$registry = api_resource_registry();
	$info     = plugin_api_version();

	$csrfValidator = static function (string $token): bool {
		if ($token === '') {
			return false;
		}

		if (isset($_SESSION['api_csrf_token']) && is_string($_SESSION['api_csrf_token']) && hash_equals($_SESSION['api_csrf_token'], $token)) {
			return true;
		}

		return false;
	};

	return new \Somethingwithproof\Api\Http\Router(
		$repo,
		$settings,
		$registry,
		api_page_token_secret(),
		$info['version'] ?? '0.1.0',
		$csrfValidator
	);
}

/**
 * Obtain or create a per-session CSRF token for Console UI forms and session-auth writes.
 *
 * @return string
 */
function api_csrf_token(): string {
	if (!isset($_SESSION['api_csrf_token']) || !is_string($_SESSION['api_csrf_token']) || strlen($_SESSION['api_csrf_token']) < 32) {
		$_SESSION['api_csrf_token'] = bin2hex(random_bytes(32));
	}

	return $_SESSION['api_csrf_token'];
}

/**
 * Cacti config_arrays hook registering Console sidebar menu entries.
 *
 * @return void
 */
function api_config_arrays(): void {
	global $menu;

	if (!api_boot()) {
		return;
	}

	foreach (\Somethingwithproof\Api\Pages::menu() as $file => $title) {
		$menu[__('Configuration')]['plugins/api/' . $file] = $title;
	}

	if (in_array(get_current_page(), \Somethingwithproof\Api\Pages::upgradeTriggers(), true)) {
		plugin_api_upgrade();
	}
}

/**
 * Cacti config_settings hook registering the API settings tab.
 *
 * @return void
 */
function api_config_settings(): void {
	global $tabs, $settings;

	if (PHP_SAPI !== 'cli' && !api_plugin_user_realm_auth(\Somethingwithproof\Api\Pages::ADMIN)) {
		return;
	}

	if (!api_boot()) {
		return;
	}

	$tabs['api']     = __('REST API', 'api');
	$settings['api'] = \Somethingwithproof\Api\Settings::form();
}

/**
 * Cacti draw_navigation_text hook supplying Console breadcrumbs.
 *
 * @param array<string, mixed> $nav Existing navigation map.
 * @return array<string, mixed>
 */
function api_draw_navigation_text($nav) {
	global $current_user;

	if (!api_boot()) {
		return $nav;
	}

	if (get_current_page() === 'settings.php' && (PHP_SAPI === 'cli' || ($current_user['enabled'] ?? '') === 'on')) {
		api_config_settings();
	}

	return array_merge($nav, \Somethingwithproof\Api\Pages::navigation());
}

/**
 * Resolve 3-tier realm inheritance: Admin > Operator (Tokens) > View.
 *
 * @return void
 */
function api_auth_alternate_realms(): void {
	global $user_auth_realm_filenames;

	static $deferred = array();

	foreach ($deferred as $file => $realm) {
		$user_auth_realm_filenames[$file] = $realm;
	}
	$deferred = array();

	if (!api_plugin_is_enabled('api')) {
		return;
	}

	if (empty($_SESSION['sess_user_id'])) {
		$page = get_current_page();

		if (in_array($page, array('api_view.php', 'api_tokens.php'), true) && isset($user_auth_realm_filenames[$page])) {
			$deferred[$page]                  = $user_auth_realm_filenames[$page];
			$user_auth_realm_filenames[$page] = -1;
		}

		return;
	}

	$adminRealm    = $user_auth_realm_filenames['api_admin.php'] ?? 0;
	$operatorRealm = $user_auth_realm_filenames['api_tokens.php'] ?? 0;

	if ($adminRealm > 0 && is_realm_allowed($adminRealm)) {
		$user_auth_realm_filenames['api_tokens.php'] = $adminRealm;
		$user_auth_realm_filenames['api_view.php']   = $adminRealm;
	} elseif ($operatorRealm > 0 && is_realm_allowed($operatorRealm)) {
		$user_auth_realm_filenames['api_view.php'] = $operatorRealm;
	}
}

/**
 * Check whether the current session user is allowed to access the active plugin page.
 *
 * @return bool
 */
function api_page_allowed(): bool {
	global $user_auth_realm_filenames;

	api_auth_alternate_realms();
	$page  = get_current_page();
	$realm = $user_auth_realm_filenames[$page] ?? 0;

	return $realm > 0 && is_realm_allowed($realm);
}

/**
 * Guard plugin Console pages against disabled plugin state, unsupported PHP, or missing realms.
 *
 * @return bool
 */
function api_page_start(): bool {
	if (api_boot() && api_plugin_is_enabled('api')) {
		if (api_page_allowed()) {
			return true;
		}

		http_response_code(403);

		return false;
	}

	top_header();
	html_start_box(__('Google AIP REST API', 'api'), '100%', false, 3, 'center', '');
	print '<tr><td>' . html_escape(api_php_supported() ? __('The REST API plugin is not enabled.', 'api') : __('The REST API plugin requires PHP %s or later.', '8.2.0', 'api')) . '</td></tr>';
	html_end_box();
	bottom_footer();

	return false;
}

/**
 * Decoupled casc_register_providers hook exposing declarative API settings (never token hashes).
 *
 * @param mixed $registrar CasC provider registrar object or array.
 * @return mixed
 */
function api_casc_register_providers($registrar) {
	if (!api_boot()) {
		return $registrar;
	}

	return \Somethingwithproof\Api\Integration\CascProvider::register(
		$registrar,
		static function (): \Somethingwithproof\Api\Settings {
			return api_settings();
		},
		static function (array $values): void {
			if (!function_exists('db_execute_prepared')) {
				return;
			}

			foreach ($values as $name => $value) {
				db_execute_prepared(
					'REPLACE INTO settings (name, value) VALUES (?, ?)',
					array($name, $value)
				);
			}
		}
	);
}

/**
 * Decoupled doctor_register_checks hook contributing REST API health diagnostics.
 *
 * @param mixed $checks Existing diagnostic checks array.
 * @return mixed
 */
function api_doctor_register_checks($checks) {
	if (!api_boot()) {
		return $checks;
	}

	$built = \Somethingwithproof\Api\Integration\DoctorChecks::build(
		static function (): \Somethingwithproof\Api\Repository\ApiRepositoryInterface {
			return new \Somethingwithproof\Api\Repository\CactiApiRepository(false);
		},
		static function (): \Somethingwithproof\Api\Settings {
			return api_settings();
		},
		static function (): \Somethingwithproof\Api\Extension\ResourceRegistry {
			return api_resource_registry();
		}
	);

	if (is_array($checks)) {
		return array_merge($checks, $built);
	}

	return $built;
}

/**
 * Decoupled doctor_register_debug_info hook returning a redacted diagnostic snapshot.
 *
 * @param mixed $info Existing diagnostic debug info sections map.
 * @return mixed
 */
function api_doctor_register_debug_info($info) {
	if (!api_boot()) {
		return $info;
	}

	$version  = plugin_api_version();
	$snapshot = \Somethingwithproof\Api\Integration\DoctorChecks::debugInfo(
		static function (): \Somethingwithproof\Api\Repository\ApiRepositoryInterface {
			return new \Somethingwithproof\Api\Repository\CactiApiRepository(false);
		},
		static function (): \Somethingwithproof\Api\Settings {
			return api_settings();
		},
		static function (): \Somethingwithproof\Api\Extension\ResourceRegistry {
			return api_resource_registry();
		},
		$version['version'] ?? '0.1.0'
	);

	if (is_array($info)) {
		$info['api'] = $snapshot;

		return $info;
	}

	return array('api' => $snapshot);
}

/**
 * Decoupled abilities_register hook exposing api-get-status, api-list-operations, and api-get-openapi.
 *
 * @param mixed $registrar Abilities registrar object.
 * @return void
 */
function api_abilities_register($registrar): void {
	if (!api_boot() || !is_object($registrar)) {
		return;
	}

	$info = plugin_api_version();

	\Somethingwithproof\Api\Integration\McpAbilities::register(
		$registrar,
		static function (): \Somethingwithproof\Api\Repository\ApiRepositoryInterface {
			return new \Somethingwithproof\Api\Repository\CactiApiRepository(false);
		},
		static function (): \Somethingwithproof\Api\Settings {
			return api_settings();
		},
		static function (): \Somethingwithproof\Api\Extension\ResourceRegistry {
			return api_resource_registry();
		},
		static function (int $userId): bool {
			return \Somethingwithproof\Api\Integration\McpAbilities::userHasViewRealm($userId);
		},
		$info['version'] ?? '0.1.0'
	);
}
