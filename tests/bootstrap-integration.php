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
 * PHPUnit loads bootstrap files inside a method scope, whereas Cacti's
 * include/global.php expects top-level file scope for its globals. Bind
 * Cacti's globals explicitly before loading include/cli_check.php.
 */
global $config, $cnn_id, $database_sessions, $database_type, $database_default,
	$database_hostname, $database_username, $database_password, $database_port,
	$database_retries, $database_ssl, $database_ssl_key, $database_ssl_cert,
	$database_ssl_ca, $database_ssl_capath, $database_ssl_verify_server_cert,
	$database_persist, $rdatabase_type, $rdatabase_default, $rdatabase_hostname,
	$rdatabase_username, $rdatabase_password, $rdatabase_port, $rdatabase_retries,
	$rdatabase_ssl, $rdatabase_ssl_key, $rdatabase_ssl_cert, $rdatabase_ssl_ca,
	$poller_id, $url_path, $cacti_session_name, $cacti_db_session,
	$disable_log_rotation, $proxy_headers, $no_http_headers, $cacti_version,
	$plugin_hooks, $plugin_realms, $plugins, $plugins_integrated,
	$user_auth_realm_filenames, $menu, $tabs, $settings, $current_user,
	$messages, $colors;

$cactiRoot = rtrim((string) getenv('CACTI_PATH'), '/');

if ($cactiRoot === '' || !is_file($cactiRoot . '/include/cli_check.php')) {
	fwrite(STDERR, "Set CACTI_PATH to a configured Cacti checkout for integration tests.\n");
	exit(2);
}

$previousCwd = getcwd();
chdir($cactiRoot);

require_once $cactiRoot . '/include/cli_check.php';
require_once dirname(__DIR__) . '/setup.php';
api_boot();

if (is_string($previousCwd)) {
	chdir($previousCwd);
}
