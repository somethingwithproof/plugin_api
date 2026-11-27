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

$api_repo       = new \Somethingwithproof\Api\Repository\CactiApiRepository(false);
$api_tokens_svc = new \Somethingwithproof\Api\Auth\TokenService($api_repo);
$api_ops_mgr    = new \Somethingwithproof\Api\Aip\OperationManager($api_repo);
$api_csrf       = api_csrf_token();
$api_user_id    = isset($_SESSION['sess_user_id']) && is_numeric($_SESSION['sess_user_id']) ? (int) $_SESSION['sess_user_id'] : 0;
$api_error      = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
	$submitted_csrf = isset_request_var('api_csrf_token') ? (string) get_nfilter_request_var('api_csrf_token') : '';

	if (!hash_equals($api_csrf, $submitted_csrf)) {
		$api_error = __('Invalid CSRF token. Reload the page and retry.', 'api');
	} else {
		$action = isset_request_var('action') ? (string) get_nfilter_request_var('action') : '';

		try {
			if ($action === 'revoke') {
				$token_id = isset_request_var('token_id') ? (string) get_nfilter_request_var('token_id') : '';
				$api_tokens_svc->revoke($token_id, $api_user_id, true, time());
			} elseif ($action === 'cancel_operation') {
				$op_id = isset_request_var('operation_id') ? (string) get_nfilter_request_var('operation_id') : '';
				$api_ops_mgr->cancel($op_id, $api_user_id, true, time());
			}
		} catch (\Somethingwithproof\Api\Aip\ApiException $e) {
			$api_error = $e->getMessage();
		}
	}
}

$all_tokens = array_map(static function (array $r) use ($api_tokens_svc): array {
	return $api_tokens_svc->toAipArray($r);
}, $api_repo->listTokens(null));

$all_ops = $api_ops_mgr->list($api_user_id, true);

top_header();
html_start_box(__('API Operations & Token Administration', 'api'), '100%', false, 3, 'center', '');
print '<tr><td>';

if ($api_error !== null) {
	print '<div class="deviceDown" style="padding:8px;margin-bottom:10px;">' . html_escape($api_error) . '</div>';
}

print '<h3>' . html_escape(__('All Application Passwords', 'api')) . '</h3>';
print \Somethingwithproof\Api\Ui\PagesRenderer::renderTokensTable($all_tokens, null, $api_csrf, 'api_admin.php');
print '<h3>' . html_escape(__('Long-Running Operations (AIP-151)', 'api')) . '</h3>';
print \Somethingwithproof\Api\Ui\PagesRenderer::renderOperationsTable($all_ops, $api_csrf);
print '</td></tr>';
html_end_box();
bottom_footer();
