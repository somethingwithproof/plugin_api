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
$api_settings   = api_settings();
$api_user_id    = isset($_SESSION['sess_user_id']) && is_numeric($_SESSION['sess_user_id']) ? (int) $_SESSION['sess_user_id'] : 0;
$api_csrf       = api_csrf_token();
$api_new_secret = null;
$api_error      = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
	$submitted_csrf = isset_request_var('api_csrf_token') ? (string) get_nfilter_request_var('api_csrf_token') : '';

	if (!hash_equals($api_csrf, $submitted_csrf)) {
		$api_error = __('Invalid CSRF token. Reload the page and retry.', 'api');
	} else {
		$action = isset_request_var('action') ? (string) get_nfilter_request_var('action') : '';

		try {
			if ($action === 'create') {
				$label  = isset_request_var('display_name') ? (string) get_nfilter_request_var('display_name') : '';
				$scopes = isset_request_var('scopes') ? explode(',', (string) get_nfilter_request_var('scopes')) : array('read');
				$cidrs  = isset_request_var('cidr_allowlist') ? explode(',', (string) get_nfilter_request_var('cidr_allowlist')) : array();
				$ttl    = $api_settings->defaultTokenTtlDays > 0 ? time() + ($api_settings->defaultTokenTtlDays * 86400) : null;

				$created        = $api_tokens_svc->create($api_user_id, $label, $scopes, $ttl, $cidrs, time());
				$api_new_secret = $created['token'];
			} elseif ($action === 'revoke') {
				$token_id = isset_request_var('token_id') ? (string) get_nfilter_request_var('token_id') : '';
				$api_tokens_svc->revoke($token_id, $api_user_id, false, time());
			}
		} catch (\Somethingwithproof\Api\Aip\ApiException $e) {
			$api_error = $e->getMessage();
		}
	}
}

$api_rows   = $api_repo->listTokens($api_user_id);
$api_tokens = array_map(static function (array $r) use ($api_tokens_svc): array {
	return $api_tokens_svc->toAipArray($r);
}, $api_rows);

top_header();
html_start_box(__('API Application Passwords', 'api'), '100%', false, 3, 'center', '');
print '<tr><td>';

if ($api_error !== null) {
	print '<div class="deviceDown" style="padding:8px;margin-bottom:10px;">' . html_escape($api_error) . '</div>';
}

print '<form method="post" action="api_tokens.php" style="margin-bottom:14px;">'
	. '<input type="hidden" name="api_csrf_token" value="' . html_escape($api_csrf) . '">'
	. '<input type="hidden" name="action" value="create">'
	. '<label for="display_name">' . html_escape(__('Label:', 'api')) . ' </label>'
	. '<input type="text" id="display_name" name="display_name" maxlength="128" required> '
	. '<label for="scopes">' . html_escape(__('Scopes:', 'api')) . ' </label>'
	. '<select id="scopes" name="scopes">'
	. '<option value="read">read</option>'
	. '<option value="read,write">read,write</option>'
	. '</select> '
	. '<label for="cidr_allowlist">' . html_escape(__('CIDR Allowlist (optional):', 'api')) . ' </label>'
	. '<input type="text" id="cidr_allowlist" name="cidr_allowlist" placeholder="10.0.0.0/8"> '
	. '<button type="submit" class="ui-button ui-corner-all ui-widget">' . html_escape(__('Create Token', 'api')) . '</button>'
	. '</form>';

print \Somethingwithproof\Api\Ui\PagesRenderer::renderTokensTable($api_tokens, $api_new_secret, $api_csrf, 'api_tokens.php');
print '</td></tr>';
html_end_box();
bottom_footer();
