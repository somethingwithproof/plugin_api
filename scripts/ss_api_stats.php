#!/usr/bin/env php
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
 * Cacti script-server entry point emitting secret-free REST API telemetry in
 * Cacti field:value format. Kept PHP 8.0 parseable so script_server.php does
 * not suffer a parse error on older PHP installations.
 */

error_reporting(0);

if (!isset($called_by_script_server)) {
	$cactiCliCheck = dirname(__FILE__) . '/../../../include/cli_check.php';

	if (is_file($cactiCliCheck)) {
		include_once($cactiCliCheck);
	}

	print call_user_func('ss_api_stats') . PHP_EOL;
}

/**
 * Collect and format plugin_api operational metrics for Cacti script_server.php.
 *
 * Returns RRDtool unknown ("U") values for all fields on unsupported PHP or
 * database error rather than fabricating zeroes.
 *
 * @return string
 */
function ss_api_stats() {
	$unknown = 'active_tokens:U revoked_tokens:U operations_pending:U operations_running:U operations_failed:U idempotency_keys:U rate_limit_buckets:U registered_collections:U';

	$setupFile = dirname(__DIR__) . '/setup.php';

	if (!is_file($setupFile)) {
		return $unknown;
	}

	require_once($setupFile);

	if (!function_exists('api_boot') || !api_boot()) {
		return $unknown;
	}

	if (!function_exists('db_fetch_row_prepared') || !function_exists('db_fetch_cell_prepared')) {
		return \Somethingwithproof\Api\Metrics\ApiMetricsCollector::unknownOutput();
	}

	try {
		$repo      = new \Somethingwithproof\Api\Repository\CactiApiRepository(false);
		$registry  = api_resource_registry();
		$collector = new \Somethingwithproof\Api\Metrics\ApiMetricsCollector($repo, $registry);

		return $collector->formatCactiOutput();
	} catch (\Throwable $e) {
		if (function_exists('cacti_log')) {
			cacti_log('WARNING: ss_api_stats failed: ' . $e->getMessage(), false, 'API');
		}

		return \Somethingwithproof\Api\Metrics\ApiMetricsCollector::unknownOutput();
	}
}
