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

namespace Somethingwithproof\Api;

/**
 * 3-tier RBAC realms, Console menu items, and navigation breadcrumbs for plugin_api.
 */
final class Pages {
	/**
	 * Read-only API status and OpenAPI explorer console page.
	 */
	public const VIEW = 'api_view.php';

	/**
	 * Self-service Application Passwords management console page.
	 */
	public const OPERATOR = 'api_tokens.php';

	/**
	 * Administrative API configuration, operations, and token governance console page.
	 */
	public const ADMIN = 'api_admin.php';

	/**
	 * Return the 3-tier RBAC realm definitions registered with Cacti.
	 *
	 * @return list<array{name: string, files: list<string>, default: bool}>
	 */
	public static function realms(): array {
		return [
			['name' => __('API View', 'api'), 'files' => [self::VIEW], 'default' => false],
			['name' => __('API Tokens (Operator)', 'api'), 'files' => [self::OPERATOR], 'default' => false],
			['name' => __('API Admin', 'api'), 'files' => [self::ADMIN], 'default' => true],
		];
	}

	/**
	 * Return all Console PHP page filenames owned by this plugin.
	 *
	 * @return list<string>
	 */
	public static function files(): array {
		return [self::VIEW, self::OPERATOR, self::ADMIN];
	}

	/**
	 * Return Console sidebar menu entries keyed by filename.
	 *
	 * @return array<string, string>
	 */
	public static function menu(): array {
		return [
			self::VIEW     => __('API Explorer & Status', 'api'),
			self::OPERATOR => __('API Application Passwords', 'api'),
			self::ADMIN    => __('API Operations & Admin', 'api'),
		];
	}

	/**
	 * Return page filenames that trigger automatic schema upgrade checks.
	 *
	 * @return list<string>
	 */
	public static function upgradeTriggers(): array {
		return array_merge(['plugins.php'], self::files());
	}

	/**
	 * Return Cacti breadcrumb navigation entries for plugin pages.
	 *
	 * @return array<string, array{title: string, mapping: string, url: string, level: string}>
	 */
	public static function navigation(): array {
		$nav = [];

		foreach (self::menu() as $file => $title) {
			$nav[$file . ':'] = [
				'title'   => $title,
				'mapping' => 'index.php:',
				'url'     => $file,
				'level'   => '1',
			];
		}

		return $nav;
	}
}
