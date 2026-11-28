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

$GLOBALS['__api_log']     = [];
$GLOBALS['__api_options'] = [];

if (!function_exists('__')) {
	/**
	 * Minimal Cacti i18n stub for isolated unit tests.
	 *
	 * @return string
	 */
	function __() {
		$args = func_get_args();

		if (count($args) === 1 || preg_match('/%(%|c|[-0-9.]*[bdeEfFgGhHosuxX])/', (string) $args[0]) !== 1) {
			return (string) $args[0];
		}

		return call_user_func_array('sprintf', $args);
	}
}

if (!function_exists('cacti_log')) {
	/**
	 * Minimal Cacti log stub for isolated unit tests.
	 *
	 * @param string $string  Log message.
	 * @param bool   $output  Unused output flag.
	 * @param string $environ Log environment tag.
	 * @param string $level   Optional severity.
	 * @return bool
	 */
	function cacti_log($string, $output = false, $environ = 'CMDPHP', $level = '') {
		$GLOBALS['__api_log'][] = ['message' => (string) $string, 'environ' => (string) $environ];

		return true;
	}
}

if (!function_exists('html_escape')) {
	/**
	 * Minimal Cacti HTML escaping stub for isolated unit tests.
	 *
	 * @param string $string Input string.
	 * @return string
	 */
	function html_escape($string) {
		return htmlspecialchars((string) $string, ENT_QUOTES, 'UTF-8', false);
	}
}

if (!function_exists('read_config_option')) {
	/**
	 * Minimal Cacti settings reader stub for isolated unit tests.
	 *
	 * @param string $config_name Setting key.
	 * @param bool   $force       Unused force flag.
	 * @return mixed
	 */
	function read_config_option($config_name, $force = false) {
		return $GLOBALS['__api_options'][$config_name] ?? '';
	}
}

$GLOBALS['config'] = [
	'base_path'    => dirname(__DIR__, 3),
	'library_path' => dirname(__DIR__, 3) . '/lib',
	'poller_id'    => 1,
];

require_once dirname(__DIR__) . '/include/autoload.php';
require_once dirname(__DIR__) . '/setup.php';
