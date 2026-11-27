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
 * HTTP Front Controller for /plugins/api/api.php/v1/... (and web server rewrites
 * from /plugins/api/v1/... or /api/v1/...). Kept PHP 8.0 parseable so older
 * PHP runtimes return a clean JSON error rather than a parse fatal.
 */

$guest_account = true;

chdir('../../');
include('./include/global.php');
include_once('./plugins/api/setup.php');

if (!api_boot() || !api_plugin_is_enabled('api')) {
	http_response_code(503);
	header('Content-Type: application/json; charset=utf-8');
	header('X-Content-Type-Options: nosniff');
	header('Cache-Control: no-store');
	echo json_encode(array(
		'error' => array(
			'code'    => 503,
			'message' => 'The Cacti REST API plugin is not enabled or requires PHP 8.2+.',
			'status'  => 'UNAVAILABLE',
			'details' => array(),
		),
	));
	exit;
}

$settings = api_settings();

try {
	$request  = \Somethingwithproof\Api\Http\Request::fromGlobals($settings->maxBodyBytes);
	$response = api_router()->handle($request);
} catch (\Somethingwithproof\Api\Aip\ApiException $e) {
	$response = \Somethingwithproof\Api\Http\Response::fromError($e->errorStatus, $e->headers);
}

$response->send();
