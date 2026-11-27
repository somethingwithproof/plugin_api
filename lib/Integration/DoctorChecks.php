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

namespace Somethingwithproof\Api\Integration;

use Somethingwithproof\Api\Extension\ResourceRegistry;
use Somethingwithproof\Api\Repository\ApiRepositoryInterface;
use Somethingwithproof\Api\Settings;

/**
 * Diagnostic checks exposed to plugin_doctor via the doctor_register_checks hook.
 */
final class DoctorChecks {
	/**
	 * Build structured diagnostic check descriptors compatible with doctor_register_checks.
	 *
	 * @param callable(): ApiRepositoryInterface $repoFactory     Repository factory.
	 * @param callable(): Settings               $settingsFactory Settings factory.
	 * @param callable(): ResourceRegistry       $registryFactory Extension resource registry factory.
	 * @param (callable(): int)|null             $clock           Optional clock callback for deterministic testing.
	 * @return list<array{id: string, name: string, category: string, run: callable(): array{status: string, message: string, details: array<string, mixed>}}>
	 */
	public static function build(
		callable $repoFactory,
		callable $settingsFactory,
		callable $registryFactory,
		?callable $clock = null,
	): array {
		$nowFn = $clock ?? static fn (): int => time();

		return [
			[
				'id'       => 'api_token_hygiene',
				'name'     => 'REST API Token Hygiene',
				'category' => 'api',
				'run'      => static function () use ($repoFactory, $nowFn): array {
					$stats = $repoFactory()->getHealthStats($nowFn());

					if ($stats['expired_tokens'] > 50) {
						return [
							'status'  => 'warning',
							'message' => sprintf('%d expired API tokens remain in plugin_api_tokens.', $stats['expired_tokens']),
							'details' => $stats,
						];
					}

					return [
						'status'  => 'ok',
						'message' => sprintf('%d active, %d expired, %d revoked API token(s).', $stats['active_tokens'], $stats['expired_tokens'], $stats['revoked_tokens']),
						'details' => $stats,
					];
				},
			],
			[
				'id'       => 'api_stuck_operations',
				'name'     => 'REST API Long-Running Operations Queue',
				'category' => 'api',
				'run'      => static function () use ($repoFactory, $nowFn): array {
					$stats = $repoFactory()->getHealthStats($nowFn());

					if ($stats['stuck_operations'] > 0) {
						return [
							'status'  => 'warning',
							'message' => sprintf('%d Long-Running Operation(s) have been pending for over 1 hour.', $stats['stuck_operations']),
							'details' => [
								'pending_operations' => $stats['pending_operations'],
								'stuck_operations'   => $stats['stuck_operations'],
							],
						];
					}

					return [
						'status'  => 'ok',
						'message' => sprintf('%d pending operation(s), 0 stuck.', $stats['pending_operations']),
						'details' => [
							'pending_operations' => $stats['pending_operations'],
							'stuck_operations'   => 0,
						],
					];
				},
			],
			[
				'id'       => 'api_rate_limit_table',
				'name'     => 'REST API Rate-Limit Table Size',
				'category' => 'api',
				'run'      => static function () use ($repoFactory, $nowFn): array {
					$stats = $repoFactory()->getHealthStats($nowFn());

					if ($stats['rate_limit_rows'] > 50000) {
						return [
							'status'  => 'warning',
							'message' => sprintf('plugin_api_rate_limits has %d rows; prune expired windows.', $stats['rate_limit_rows']),
							'details' => ['rate_limit_rows' => $stats['rate_limit_rows']],
						];
					}

					return [
						'status'  => 'ok',
						'message' => sprintf('plugin_api_rate_limits contains %d bucket row(s).', $stats['rate_limit_rows']),
						'details' => ['rate_limit_rows' => $stats['rate_limit_rows']],
					];
				},
			],
			[
				'id'       => 'api_security_posture',
				'name'     => 'REST API TLS, CORS, and Extension Registry Health',
				'category' => 'api',
				'run'      => static function () use ($settingsFactory, $registryFactory): array {
					$settings = $settingsFactory();
					$registry = $registryFactory();
					$warnings = [];

					if ($settings->enabled && !$settings->requireTls) {
						$warnings[] = 'HTTPS enforcement (api_require_tls) is disabled';
					}

					if ($settings->corsAllowCredentials && in_array('*', $settings->corsOrigins, true)) {
						$warnings[] = 'Wildcard CORS origin "*" is configured alongside corsAllowCredentials';
					}

					if ($registry->faults() !== []) {
						$warnings[] = sprintf('%d extension registration fault(s) isolated', count($registry->faults()));
					}

					if ($warnings !== []) {
						return [
							'status'  => 'warning',
							'message' => implode('; ', $warnings) . '.',
							'details' => [
								'require_tls'   => $settings->requireTls,
								'cors_origins'  => $settings->corsOrigins,
								'fault_count'   => count($registry->faults()),
							],
						];
					}

					return [
						'status'  => 'ok',
						'message' => sprintf('TLS enforcement active; %d extension resource(s) registered with 0 faults.', count($registry->all())),
						'details' => [
							'require_tls'        => $settings->requireTls,
							'extension_count'    => count($registry->all()),
							'fault_count'        => 0,
						],
					];
				},
			],
		];
	}
}
