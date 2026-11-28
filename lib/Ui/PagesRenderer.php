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

declare(strict_types=1);

namespace Somethingwithproof\Api\Ui;

use Somethingwithproof\Api\Extension\ExtensionResourceDescriptor;
use Somethingwithproof\Api\Settings;

/**
 * HTML-escaped Console UI renderers for api_view.php, api_tokens.php, and api_admin.php.
 */
final class PagesRenderer {
	/**
	 * Render API status, registered extension collections, and OpenAPI endpoint overview.
	 *
	 * @param Settings                           $settings   Active plugin settings.
	 * @param array<string, int>                 $stats      Health counts from repository.
	 * @param list<ExtensionResourceDescriptor>  $extensions Registered sibling plugin resources.
	 * @param list<array{plugin: string, reason: string}> $faults Isolated extension faults.
	 * @param int                                $pathCount  Total paths in live OpenAPI spec.
	 * @return string Escaped HTML snippet.
	 */
	public static function renderOverview(
		Settings $settings,
		array $stats,
		array $extensions,
		array $faults,
		int $pathCount,
	): string {
		$html = '<p><strong>' . self::esc(__('Status:', 'api')) . '</strong> '
			. self::esc($settings->enabled ? __('Enabled', 'api') : __('Disabled', 'api'))
			. ' | <strong>' . self::esc(__('OpenAPI 3.1 Paths:', 'api')) . '</strong> ' . $pathCount
			. ' | <strong>' . self::esc(__('Rate Limit:', 'api')) . '</strong> ' . $settings->rateLimitPerMin . ' req/min'
			. ' | <strong>' . self::esc(__('Require TLS:', 'api')) . '</strong> ' . self::esc($settings->requireTls ? __('Yes', 'api') : __('No', 'api'))
			. '</p>';

		$html .= '<p><strong>' . self::esc(__('Active Tokens:', 'api')) . '</strong> ' . ($stats['active_tokens'] ?? 0)
			. ' | <strong>' . self::esc(__('Pending Operations:', 'api')) . '</strong> ' . ($stats['pending_operations'] ?? 0)
			. ' | <strong>' . self::esc(__('OpenAPI Endpoint:', 'api')) . '</strong> <code>/plugins/api/api.php/v1/openapi.json</code></p>';

		$html .= '<h3>' . self::esc(__('Registered Plugin API Extensions', 'api')) . '</h3>';

		if ($extensions === []) {
			$html .= '<p>' . self::esc(__('No sibling plugin API collections are currently registered.', 'api')) . '</p>';
		} else {
			$html .= '<table class="cactiTable"><thead><tr><th>'
				. self::esc(__('Collection', 'api')) . '</th><th>'
				. self::esc(__('Plugin', 'api')) . '</th><th>'
				. self::esc(__('Read Realm', 'api')) . '</th><th>'
				. self::esc(__('Handlers', 'api')) . '</th></tr></thead><tbody>';

			foreach ($extensions as $ext) {
				$html .= '<tr><td><code>/v1/' . self::esc($ext->collection) . '</code></td><td>'
					. self::esc($ext->plugin) . '</td><td>'
					. self::esc($ext->realmRead) . '</td><td>'
					. self::esc(implode(', ', array_keys($ext->handlers))) . '</td></tr>';
			}

			$html .= '</tbody></table>';
		}

		if ($faults !== []) {
			$html .= '<h3>' . self::esc(__('Isolated Extension Faults', 'api')) . '</h3><ul>';

			foreach ($faults as $fault) {
				$html .= '<li><code>' . self::esc($fault['plugin']) . '</code>: ' . self::esc($fault['reason']) . '</li>';
			}

			$html .= '</ul>';
		}

		return $html;
	}

	/**
	 * Render Application Passwords table with optional one-time newly created secret banner.
	 *
	 * @param list<array<string, mixed>> $tokens        Serialized token metadata records.
	 * @param string|null                $newPlainToken One-time plaintext token displayed right after creation.
	 * @param string                     $csrfToken     CSRF hidden form token.
	 * @param string                     $formAction    Target page filename (api_tokens.php or api_admin.php).
	 * @return string Escaped HTML snippet.
	 */
	public static function renderTokensTable(
		array $tokens,
		?string $newPlainToken,
		string $csrfToken,
		string $formAction = 'api_tokens.php',
	): string {
		$html = '';

		if ($newPlainToken !== null && $newPlainToken !== '') {
			$html .= '<div class="deviceUp" style="padding:10px;margin-bottom:12px;">'
				. '<strong>' . self::esc(__('New Application Password (shown once):', 'api')) . '</strong> '
				. '<code>' . self::esc($newPlainToken) . '</code></div>';
		}

		if ($tokens === []) {
			return $html . '<p>' . self::esc(__('No Application Passwords have been issued.', 'api')) . '</p>';
		}

		$html .= '<table class="cactiTable"><thead><tr><th>'
			. self::esc(__('Label', 'api')) . '</th><th>'
			. self::esc(__('Prefix', 'api')) . '</th><th>'
			. self::esc(__('Scopes', 'api')) . '</th><th>'
			. self::esc(__('Expires', 'api')) . '</th><th>'
			. self::esc(__('Status', 'api')) . '</th><th>'
			. self::esc(__('Action', 'api')) . '</th></tr></thead><tbody>';

		foreach ($tokens as $tok) {
			$id      = is_scalar($tok['id'] ?? null) ? (string) $tok['id'] : '';
			$label   = is_scalar($tok['displayName'] ?? null) ? (string) $tok['displayName'] : '';
			$prefix  = is_scalar($tok['tokenPrefix'] ?? null) ? (string) $tok['tokenPrefix'] : '';
			$scopes  = is_array($tok['scopes'] ?? null)
				? implode(', ', array_map(static fn (mixed $s): string => is_scalar($s) ? (string) $s : '', $tok['scopes']))
				: '';
			$expires = is_string($tok['expireTime'] ?? null) ? $tok['expireTime'] : __('Never', 'api');
			$revoked = ($tok['revoked'] ?? false) === true;
			$status  = $revoked ? __('Revoked', 'api') : __('Active', 'api');

			$actionHtml = $revoked
				? self::esc(__('Revoked', 'api'))
				: '<form method="post" action="' . self::esc($formAction) . '" style="display:inline;">'
					. '<input type="hidden" name="api_csrf_token" value="' . self::esc($csrfToken) . '">'
					. '<input type="hidden" name="action" value="revoke">'
					. '<input type="hidden" name="token_id" value="' . self::esc($id) . '">'
					. '<button type="submit" class="ui-button ui-corner-all ui-widget">' . self::esc(__('Revoke', 'api')) . '</button>'
					. '</form>';

			$html .= '<tr><td>' . self::esc($label) . '</td><td><code>' . self::esc($prefix) . '</code></td><td>'
				. self::esc($scopes) . '</td><td>' . self::esc($expires) . '</td><td>'
				. self::esc($status) . '</td><td>' . $actionHtml . '</td></tr>';
		}

		$html .= '</tbody></table>';

		return $html;
	}

	/**
	 * Render Long-Running Operations management table for administrators.
	 *
	 * @param list<array<string, mixed>> $operations Serialized AIP-151 operations.
	 * @param string                     $csrfToken  CSRF hidden form token.
	 * @return string Escaped HTML snippet.
	 */
	public static function renderOperationsTable(array $operations, string $csrfToken): string {
		if ($operations === []) {
			return '<p>' . self::esc(__('No Long-Running Operations recorded.', 'api')) . '</p>';
		}

		$html = '<table class="cactiTable"><thead><tr><th>'
			. self::esc(__('Operation', 'api')) . '</th><th>'
			. self::esc(__('Target', 'api')) . '</th><th>'
			. self::esc(__('Done', 'api')) . '</th><th>'
			. self::esc(__('Created', 'api')) . '</th><th>'
			. self::esc(__('Action', 'api')) . '</th></tr></thead><tbody>';

		foreach ($operations as $op) {
			$id      = is_scalar($op['id'] ?? null) ? (string) $op['id'] : '';
			$meta    = is_array($op['metadata'] ?? null) ? $op['metadata'] : [];
			$target  = is_scalar($meta['targetResource'] ?? null) ? (string) $meta['targetResource'] : '';
			$done    = ($op['done'] ?? false) === true;
			$created = is_scalar($op['createTime'] ?? null) ? (string) $op['createTime'] : '';

			$btn = $done
				? self::esc(__('Completed', 'api'))
				: '<form method="post" action="api_admin.php" style="display:inline;">'
					. '<input type="hidden" name="api_csrf_token" value="' . self::esc($csrfToken) . '">'
					. '<input type="hidden" name="action" value="cancel_operation">'
					. '<input type="hidden" name="operation_id" value="' . self::esc($id) . '">'
					. '<button type="submit" class="ui-button ui-corner-all ui-widget">' . self::esc(__('Cancel', 'api')) . '</button>'
					. '</form>';

			$html .= '<tr><td><code>' . self::esc($id) . '</code></td><td>' . self::esc($target) . '</td><td>'
				. self::esc($done ? __('Yes', 'api') : __('No', 'api')) . '</td><td>'
				. self::esc($created) . '</td><td>' . $btn . '</td></tr>';
		}

		$html .= '</tbody></table>';

		return $html;
	}

	/**
	 * Escape untrusted text safely for HTML contexts.
	 *
	 * @param string $value Raw string.
	 * @return string
	 */
	private static function esc(string $value): string {
		if (function_exists('html_escape')) {
			return (string) html_escape($value);
		}

		return htmlspecialchars($value, ENT_QUOTES, 'UTF-8', false);
	}
}
