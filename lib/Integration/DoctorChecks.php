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
use Somethingwithproof\Api\Http\Router;
use Somethingwithproof\Api\Metrics\ApiMetricsCollector;
use Somethingwithproof\Api\Repository\ApiRepositoryInterface;
use Somethingwithproof\Api\Schema;
use Somethingwithproof\Api\Settings;

/**
 * Diagnostic checks and redacted debug info exposed to plugin_doctor via the
 * doctor_register_checks and doctor_register_debug_info hooks.
 */
final class DoctorChecks {
	/**
	 * Plugin slug reported in diagnostic descriptors and debug info sections.
	 */
	private const PLUGIN = 'api';

	/**
	 * Diagnostic check category displayed in plugin_doctor reports.
	 */
	private const CATEGORY = 'API';

	/**
	 * Evaluate or list structured diagnostic check descriptors compatible with
	 * plugin_doctor's doctor_register_checks contract.
	 *
	 * @param callable(): ApiRepositoryInterface $repoFactory     Repository factory.
	 * @param callable(): Settings               $settingsFactory Settings factory.
	 * @param callable(): ResourceRegistry       $registryFactory Extension resource registry factory.
	 * @param (callable(): int)|null             $clock           Optional clock callback for deterministic testing.
	 * @param array<string, mixed>               $context         Optional hook context with mode ('run'|'list').
	 * @return list<array{id: string, plugin: string, category: string, title: string, status: string, message: string, details: array<string, mixed>, remediation: string}>
	 */
	public static function build(
		callable $repoFactory,
		callable $settingsFactory,
		callable $registryFactory,
		?callable $clock = null,
		array $context = [],
	): array {
		$mode = is_string($context['mode'] ?? null) ? strtolower(trim($context['mode'])) : 'run';

		if ($mode === 'list') {
			return [
				self::descriptor(
					'api.token_hygiene',
					'REST API Token Hygiene',
					'info',
					'Inspects active, expired, and revoked Application Password counts in plugin_api_tokens.',
					['mode' => 'list'],
					''
				),
				self::descriptor(
					'api.stuck_operations',
					'REST API Long-Running Operations Queue',
					'info',
					'Checks for AIP-151 Long-Running Operations pending longer than one hour.',
					['mode' => 'list'],
					''
				),
				self::descriptor(
					'api.rate_limit_table',
					'REST API Rate-Limit Table Size',
					'info',
					'Verifies plugin_api_rate_limits row counts remain bounded.',
					['mode' => 'list'],
					''
				),
				self::descriptor(
					'api.security_posture',
					'REST API TLS, CORS, and Extension Registry Health',
					'info',
					'Validates HTTPS enforcement, CORS credential safety, and isolated extension registration faults.',
					['mode' => 'list'],
					''
				),
			];
		}

		$nowFn = $clock ?? static fn (): int => time();

		return [
			self::checkTokenHygiene($repoFactory, $nowFn),
			self::checkStuckOperations($repoFactory, $nowFn),
			self::checkRateLimitTable($repoFactory, $nowFn),
			self::checkSecurityPosture($settingsFactory, $registryFactory),
		];
	}

	/**
	 * Build redacted debug info sections compatible with doctor_register_debug_info.
	 *
	 * Never includes raw Application Passwords, token prefixes, token SHA-256 digests,
	 * page-token HMAC keys, CSRF tokens, or user credentials.
	 *
	 * @param callable(): ApiRepositoryInterface $repoFactory     Repository factory.
	 * @param callable(): Settings               $settingsFactory Settings factory.
	 * @param callable(): ResourceRegistry       $registryFactory Extension resource registry factory.
	 * @param string                             $apiVersion      Plugin version string.
	 * @param (callable(): int)|null             $clock           Optional clock callback for deterministic testing.
	 * @return list<array{id: string, plugin: string, label: string, description: string, private: bool, fields: array<string, array{label: string, value: bool|int|string}>}>
	 */
	public static function debugInfo(
		callable $repoFactory,
		callable $settingsFactory,
		callable $registryFactory,
		string $apiVersion = '0.1.0',
		?callable $clock = null,
	): array {
		$nowFn    = $clock ?? static fn (): int => time();
		$repo     = $repoFactory();
		$settings = $settingsFactory();
		$registry = $registryFactory();
		$metrics  = (new ApiMetricsCollector($repo, $registry))->collect($nowFn());

		$extensions     = $registry->all();
		$extCollections = [];
		$extPlugins     = [];

		foreach ($extensions as $descriptor) {
			$extCollections[]               = $descriptor->collection;
			$extPlugins[$descriptor->plugin] = true;
		}

		$coreCollections = array_keys(Router::CORE_COLLECTIONS);
		sort($coreCollections, SORT_STRING);

		$pluginSlugs = array_keys($extPlugins);
		sort($pluginSlugs, SORT_STRING);

		return [
			[
				'id'          => 'api.overview',
				'plugin'      => self::PLUGIN,
				'label'       => 'REST API (plugin_api)',
				'description' => 'Redacted REST API configuration, telemetry counts, and extension registration summary (zero secrets).',
				'private'     => false,
				'fields'      => [
					'version' => [
						'label' => 'Plugin Version',
						'value' => $apiVersion !== '' ? $apiVersion : '0.1.0',
					],
					'schema_version' => [
						'label' => 'Schema Version',
						'value' => Schema::VERSION,
					],
					'php_version' => [
						'label' => 'PHP Version',
						'value' => PHP_VERSION,
					],
					'redacted' => [
						'label' => 'Redacted Snapshot',
						'value' => true,
					],
					'secret_storage' => [
						'label' => 'Token Secret Storage',
						'value' => 'sha256_hashed_at_rest',
					],
					'enabled' => [
						'label' => 'API Enabled',
						'value' => $settings->enabled,
					],
					'require_tls' => [
						'label' => 'Require TLS',
						'value' => $settings->requireTls,
					],
					'rate_limit_per_min' => [
						'label' => 'Rate Limit (req/min)',
						'value' => $settings->rateLimitPerMin,
					],
					'default_token_ttl_days' => [
						'label' => 'Default Token TTL (days)',
						'value' => $settings->defaultTokenTtlDays,
					],
					'max_body_bytes' => [
						'label' => 'Max Request Body (bytes)',
						'value' => $settings->maxBodyBytes,
					],
					'enable_yaml_negotiation' => [
						'label' => 'YAML Content Negotiation',
						'value' => $settings->enableYamlNegotiation,
					],
					'uninstall_keep_tables' => [
						'label' => 'Keep Tables on Uninstall',
						'value' => $settings->uninstallKeepTables,
					],
					'cors_origin_count' => [
						'label' => 'CORS Allowed Origins',
						'value' => count($settings->corsOrigins),
					],
					'cors_allow_credentials' => [
						'label' => 'CORS Allow Credentials',
						'value' => $settings->corsAllowCredentials,
					],
					'active_tokens' => [
						'label' => 'Active Tokens',
						'value' => $metrics['active_tokens'],
					],
					'revoked_tokens' => [
						'label' => 'Revoked Tokens',
						'value' => $metrics['revoked_tokens'],
					],
					'operations_pending' => [
						'label' => 'Operations Pending',
						'value' => $metrics['operations_pending'],
					],
					'operations_running' => [
						'label' => 'Operations Running',
						'value' => $metrics['operations_running'],
					],
					'operations_failed' => [
						'label' => 'Operations Failed',
						'value' => $metrics['operations_failed'],
					],
					'idempotency_keys' => [
						'label' => 'Active Idempotency Keys',
						'value' => $metrics['idempotency_keys'],
					],
					'rate_limit_buckets' => [
						'label' => 'Rate-Limit Buckets',
						'value' => $metrics['rate_limit_buckets'],
					],
					'registered_collections' => [
						'label' => 'Registered Collections',
						'value' => $metrics['registered_collections'],
					],
					'core_collections' => [
						'label' => 'Core Collections',
						'value' => implode(', ', $coreCollections),
					],
					'extension_collections' => [
						'label' => 'Extension Collections',
						'value' => $extCollections !== [] ? implode(', ', $extCollections) : 'none',
					],
					'extension_plugins' => [
						'label' => 'Extension Plugins',
						'value' => $pluginSlugs !== [] ? implode(', ', $pluginSlugs) : 'none',
					],
					'extension_fault_count' => [
						'label' => 'Extension Registration Faults',
						'value' => count($registry->faults()),
					],
				],
			],
		];
	}

	/**
	 * Evaluate Application Password hygiene across active, expired, and revoked tokens.
	 *
	 * @param callable(): ApiRepositoryInterface $repoFactory Repository factory.
	 * @param callable(): int                    $nowFn       Clock callback.
	 * @return array{id: string, plugin: string, category: string, title: string, status: string, message: string, details: array<string, mixed>, remediation: string}
	 */
	private static function checkTokenHygiene(callable $repoFactory, callable $nowFn): array {
		$stats = $repoFactory()->getHealthStats($nowFn());

		if ($stats['expired_tokens'] > 50) {
			return self::descriptor(
				'api.token_hygiene',
				'REST API Token Hygiene',
				'warn',
				sprintf('%d expired API tokens remain in plugin_api_tokens.', $stats['expired_tokens']),
				$stats,
				'Prune expired Application Passwords using cli/api.php token-list and token-revoke or Console -> API Operations & Admin.'
			);
		}

		return self::descriptor(
			'api.token_hygiene',
			'REST API Token Hygiene',
			'ok',
			sprintf('%d active, %d expired, %d revoked API token(s).', $stats['active_tokens'], $stats['expired_tokens'], $stats['revoked_tokens']),
			$stats,
			''
		);
	}

	/**
	 * Check for AIP-151 Long-Running Operations pending longer than one hour.
	 *
	 * @param callable(): ApiRepositoryInterface $repoFactory Repository factory.
	 * @param callable(): int                    $nowFn       Clock callback.
	 * @return array{id: string, plugin: string, category: string, title: string, status: string, message: string, details: array<string, mixed>, remediation: string}
	 */
	private static function checkStuckOperations(callable $repoFactory, callable $nowFn): array {
		$stats = $repoFactory()->getHealthStats($nowFn());

		if ($stats['stuck_operations'] > 0) {
			return self::descriptor(
				'api.stuck_operations',
				'REST API Long-Running Operations Queue',
				'warn',
				sprintf('%d Long-Running Operation(s) have been pending for over 1 hour.', $stats['stuck_operations']),
				[
					'pending_operations' => $stats['pending_operations'],
					'stuck_operations'   => $stats['stuck_operations'],
				],
				'Inspect pending operations via cli/api.php operations and cancel or clear stale jobs.'
			);
		}

		return self::descriptor(
			'api.stuck_operations',
			'REST API Long-Running Operations Queue',
			'ok',
			sprintf('%d pending operation(s), 0 stuck.', $stats['pending_operations']),
			[
				'pending_operations' => $stats['pending_operations'],
				'stuck_operations'   => 0,
			],
			''
		);
	}

	/**
	 * Verify plugin_api_rate_limits table row count remains within operational bounds.
	 *
	 * @param callable(): ApiRepositoryInterface $repoFactory Repository factory.
	 * @param callable(): int                    $nowFn       Clock callback.
	 * @return array{id: string, plugin: string, category: string, title: string, status: string, message: string, details: array<string, mixed>, remediation: string}
	 */
	private static function checkRateLimitTable(callable $repoFactory, callable $nowFn): array {
		$stats = $repoFactory()->getHealthStats($nowFn());

		if ($stats['rate_limit_rows'] > 50000) {
			return self::descriptor(
				'api.rate_limit_table',
				'REST API Rate-Limit Table Size',
				'warn',
				sprintf('plugin_api_rate_limits has %d rows; prune expired windows.', $stats['rate_limit_rows']),
				['rate_limit_rows' => $stats['rate_limit_rows']],
				'Delete expired fixed-window rows from plugin_api_rate_limits to keep lookups fast.'
			);
		}

		return self::descriptor(
			'api.rate_limit_table',
			'REST API Rate-Limit Table Size',
			'ok',
			sprintf('plugin_api_rate_limits contains %d bucket row(s).', $stats['rate_limit_rows']),
			['rate_limit_rows' => $stats['rate_limit_rows']],
			''
		);
	}

	/**
	 * Check TLS enforcement, CORS credential safety, and extension registry fault counts.
	 *
	 * @param callable(): Settings         $settingsFactory Settings factory.
	 * @param callable(): ResourceRegistry $registryFactory Extension resource registry factory.
	 * @return array{id: string, plugin: string, category: string, title: string, status: string, message: string, details: array<string, mixed>, remediation: string}
	 */
	private static function checkSecurityPosture(callable $settingsFactory, callable $registryFactory): array {
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
			return self::descriptor(
				'api.security_posture',
				'REST API TLS, CORS, and Extension Registry Health',
				'warn',
				implode('; ', $warnings) . '.',
				[
					'require_tls'  => $settings->requireTls,
					'cors_origins' => $settings->corsOrigins,
					'fault_count'  => count($registry->faults()),
				],
				'Enable api_require_tls, restrict api_cors_origins to explicit trusted origins, and review Cacti logs for extension faults.'
			);
		}

		return self::descriptor(
			'api.security_posture',
			'REST API TLS, CORS, and Extension Registry Health',
			'ok',
			sprintf('TLS enforcement active; %d extension resource(s) registered with 0 faults.', count($registry->all())),
			[
				'require_tls'     => $settings->requireTls,
				'extension_count' => count($registry->all()),
				'fault_count'     => 0,
			],
			''
		);
	}

	/**
	 * Construct a contract-compliant plugin_doctor check descriptor with bounded string lengths.
	 *
	 * @param string               $id          Dot-prefixed check identifier (api.*).
	 * @param string               $title       Human-readable check title.
	 * @param string               $status      Allowed status enum (ok|warn|fail|info).
	 * @param string               $message     Summary message.
	 * @param array<string, mixed> $details     Structured diagnostic details.
	 * @param string               $remediation Actionable remediation text.
	 * @return array{id: string, plugin: string, category: string, title: string, status: string, message: string, details: array<string, mixed>, remediation: string}
	 */
	private static function descriptor(
		string $id,
		string $title,
		string $status,
		string $message,
		array $details,
		string $remediation,
	): array {
		return [
			'id'          => $id,
			'plugin'      => self::PLUGIN,
			'category'    => self::CATEGORY,
			'title'       => substr($title, 0, 256),
			'status'      => $status,
			'message'     => substr($message !== '' ? $message : $title, 0, 2048),
			'details'     => $details,
			'remediation' => substr($remediation, 0, 2048),
		];
	}
}
