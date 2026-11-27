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

namespace Somethingwithproof\Api\Metrics;

use Somethingwithproof\Api\Extension\ResourceRegistry;
use Somethingwithproof\Api\Http\Router;
use Somethingwithproof\Api\Repository\ApiRepositoryInterface;

/**
 * Secret-free operational telemetry collector emitting Cacti script-server
 * field:value output for self-graphing plugin_api state counters.
 */
final class ApiMetricsCollector {
	/**
	 * Ordered list of exported Cacti Data Input Method output field names.
	 *
	 * @var list<string>
	 */
	public const METRIC_KEYS = [
		'active_tokens',
		'revoked_tokens',
		'operations_pending',
		'operations_running',
		'operations_failed',
		'idempotency_keys',
		'rate_limit_buckets',
		'registered_collections',
	];

	/**
	 * Construct an ApiMetricsCollector bound to repository and extension registry.
	 *
	 * @param ApiRepositoryInterface $repository Persistence adapter.
	 * @param ResourceRegistry       $registry   Discovered sibling plugin resources.
	 */
	public function __construct(
		private readonly ApiRepositoryInterface $repository,
		private readonly ResourceRegistry $registry,
	) {
	}

	/**
	 * Collect a secret-free operational telemetry snapshot as non-negative integer counters.
	 *
	 * @param int|null $now Optional Unix timestamp override for deterministic tests.
	 * @return array{active_tokens: int, revoked_tokens: int, operations_pending: int, operations_running: int, operations_failed: int, idempotency_keys: int, rate_limit_buckets: int, registered_collections: int}
	 */
	public function collect(?int $now = null): array {
		$timestamp = $now ?? time();
		$stats     = $this->repository->getHealthStats($timestamp);

		return [
			'active_tokens'          => max(0, $stats['active_tokens']),
			'revoked_tokens'         => max(0, $stats['revoked_tokens']),
			'operations_pending'     => max(0, $stats['operations_pending']),
			'operations_running'     => max(0, $stats['operations_running']),
			'operations_failed'      => max(0, $stats['operations_failed']),
			'idempotency_keys'       => max(0, $stats['idempotency_keys']),
			'rate_limit_buckets'     => max(0, $stats['rate_limit_rows']),
			'registered_collections' => count(Router::CORE_COLLECTIONS) + count($this->registry->all()),
		];
	}

	/**
	 * Collect metrics and format them as space-separated Cacti field:value pairs.
	 *
	 * @param int|null $now Optional Unix timestamp override for deterministic tests.
	 * @return string
	 */
	public function formatCactiOutput(?int $now = null): string {
		return self::formatSnapshot($this->collect($now));
	}

	/**
	 * Format a metric array into deterministic space-separated Cacti field:value output.
	 *
	 * Missing or invalid values are emitted as "U" (RRDtool unknown) rather than 0.
	 *
	 * @param array<string, mixed> $metrics Metric values keyed by METRIC_KEYS.
	 * @return string
	 */
	public static function formatSnapshot(array $metrics): string {
		$parts = [];

		foreach (self::METRIC_KEYS as $key) {
			$val     = $metrics[$key] ?? null;
			$encoded = is_int($val) && $val >= 0 ? (string) $val : 'U';
			$parts[] = $key . ':' . $encoded;
		}

		return implode(' ', $parts);
	}

	/**
	 * Return an all-unknown Cacti field:U line for runtime or database failures.
	 *
	 * Emitting "U" creates an honest gap in RRDtool graphs instead of injecting false zeroes.
	 *
	 * @return string
	 */
	public static function unknownOutput(): string {
		$parts = [];

		foreach (self::METRIC_KEYS as $key) {
			$parts[] = $key . ':U';
		}

		return implode(' ', $parts);
	}
}
