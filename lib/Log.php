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
 * Structured Cacti logger wrapper scoped to the API plugin environment tag.
 */
final class Log {
	/**
	 * Cacti log environment identifier.
	 */
	private const ENVIRON = 'API';

	/**
	 * Write an informational log message to Cacti's log.
	 *
	 * @param string $message Log entry text without secret material.
	 * @return void
	 */
	public static function info(string $message): void {
		if (function_exists('cacti_log')) {
			cacti_log('NOTE: ' . $message, false, self::ENVIRON);
		}
	}

	/**
	 * Write a warning log message to Cacti's log.
	 *
	 * @param string $message Warning text without secret material.
	 * @return void
	 */
	public static function warning(string $message): void {
		if (function_exists('cacti_log')) {
			cacti_log('WARNING: ' . $message, false, self::ENVIRON);
		}
	}

	/**
	 * Write an error log message to Cacti's log.
	 *
	 * @param string $message Error text without secret material.
	 * @return void
	 */
	public static function error(string $message): void {
		if (function_exists('cacti_log')) {
			cacti_log('ERROR: ' . $message, false, self::ENVIRON);
		}
	}
}
