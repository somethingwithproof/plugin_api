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

if (PHP_SAPI !== 'cli') {
	exit(1);
}

$dir = __DIR__;
chdir($dir . '/../../..');

require('./include/cli_check.php');
require_once($config['base_path'] . '/plugins/api/setup.php');

if (api_refuse_unsupported_php()) {
	exit(1);
}

api_boot();

$args   = array_slice($argv, 1);
$info   = plugin_api_version();
$runner = new \Somethingwithproof\Api\Cli\CliRunner(
	new \Somethingwithproof\Api\Repository\CactiApiRepository(false),
	api_settings(),
	api_resource_registry(),
	$info['version'] ?? '0.1.0'
);
$result = $runner->run($args);

if ($result['stdout'] !== '') {
	fwrite(STDOUT, $result['stdout']);
}

if ($result['stderr'] !== '') {
	fwrite(STDERR, $result['stderr']);
}

exit($result['exitCode']);
