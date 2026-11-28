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

chdir('../../');
include('./include/auth.php');
include_once('./plugins/api/setup.php');

if (!api_page_start()) {
	exit;
}

$api_settings   = api_settings();
$api_repo       = new \Somethingwithproof\Api\Repository\CactiApiRepository(false);
$api_registry   = api_resource_registry();
$api_stats      = $api_repo->getHealthStats(time());
$api_info       = plugin_api_version();
$api_spec       = \Somethingwithproof\Api\OpenApi\OpenApiGenerator::generate($api_registry, $api_info['version'] ?? '0.1.0');
$api_path_count = is_array($api_spec['paths'] ?? null) ? count($api_spec['paths']) : 0;

top_header();
html_start_box(__('Google AIP REST API Explorer & Status', 'api'), '100%', false, 3, 'center', '');
print '<tr><td>' . \Somethingwithproof\Api\Ui\PagesRenderer::renderOverview(
	$api_settings,
	$api_stats,
	$api_registry->all(),
	$api_registry->faults(),
	$api_path_count
) . '</td></tr>';
html_end_box();
bottom_footer();
