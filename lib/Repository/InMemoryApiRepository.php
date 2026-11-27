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

namespace Somethingwithproof\Api\Repository;

use Somethingwithproof\Api\Aip\FilterNode;
use Somethingwithproof\Api\Aip\OrderByParser;
use Somethingwithproof\Api\Aip\SqlFilterCompiler;

/**
 * In-memory implementation of ApiRepositoryInterface for deterministic unit
 * and security testing without requiring a MariaDB instance.
 */
final class InMemoryApiRepository implements ApiRepositoryInterface {
	/**
	 * Sites keyed by numeric ID.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $sites = [];

	/**
	 * Pollers keyed by numeric ID.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $pollers = [];

	/**
	 * Devices keyed by numeric ID.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $devices = [];

	/**
	 * Graphs keyed by numeric ID.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $graphs = [];

	/**
	 * Data sources keyed by numeric ID.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $dataSources = [];

	/**
	 * Graph trees keyed by numeric ID.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $trees = [];

	/**
	 * Plugins keyed by directory slug.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $plugins = [];

	/**
	 * Tokens keyed by UUID.
	 *
	 * @var array<string, array{id: string, user_id: int, name: string, token_prefix: string, token_hash: string, scopes: string, cidr_allowlist: string, created_at: int, expires_at: int|null, last_used_at: int|null, revoked_at: int|null}>
	 */
	public array $tokens = [];

	/**
	 * Operations keyed by UUID.
	 *
	 * @var array<string, array{id: string, user_id: int, operation_type: string, target_resource: string, done: bool, cancelled: bool, metadata: array<string, mixed>, response: array<string, mixed>|null, error: array<string, mixed>|null, created_at: int, updated_at: int}>
	 */
	public array $operations = [];

	/**
	 * Idempotency records keyed by scoped key.
	 *
	 * @var array<string, array{request_id: string, user_id: int, method: string, path: string, request_hash: string, status_code: int, response_json: string, created_at: int, expires_at: int}>
	 */
	public array $idempotency = [];

	/**
	 * Rate-limit buckets keyed by bucket hash.
	 *
	 * @var array<string, array{window_start: int, hit_count: int}>
	 */
	public array $rateLimits = [];

	/**
	 * Cacti users keyed by user ID.
	 *
	 * @var array<int, array{id: int, username: string, enabled: bool, locked: bool}>
	 */
	public array $users = [];

	/**
	 * Cacti realm file lists keyed by user ID.
	 *
	 * @var array<int, list<string>>
	 */
	public array $userRealms = [];

	/**
	 * Explicit denied device IDs per user ID for ACL testing.
	 *
	 * @var array<int, list<int>>
	 */
	public array $deniedDevices = [];

	/**
	 * Explicit denied graph IDs per user ID for ACL testing.
	 *
	 * @var array<int, list<int>>
	 */
	public array $deniedGraphs = [];

	/**
	 * Explicit denied tree IDs per user ID for ACL testing.
	 *
	 * @var array<int, list<int>>
	 */
	public array $deniedTrees = [];

	/**
	 * Auto-increment counter for created sites.
	 *
	 * @var int
	 */
	private int $nextSiteId = 1;

	/**
	 * Auto-increment counter for created devices.
	 *
	 * @var int
	 */
	private int $nextDeviceId = 1;

	/**
	 * Create a pre-populated in-memory repository with realistic fixture rows.
	 *
	 * @return self
	 */
	public static function withDefaultFixtures(): self {
		$repo = new self();

		$repo->users = [
			1 => ['id' => 1, 'username' => 'admin', 'enabled' => true, 'locked' => false],
			2 => ['id' => 2, 'username' => 'viewer', 'enabled' => true, 'locked' => false],
			3 => ['id' => 3, 'username' => 'disabled_user', 'enabled' => false, 'locked' => false],
		];
		$repo->userRealms = [
			1 => ['api_admin.php', 'api_tokens.php', 'api_view.php', 'host.php', 'sites.php', 'pollers.php', 'graphs.php', 'data_sources.php', 'tree.php', 'plugins.php'],
			2 => ['api_view.php', 'api_tokens.php', 'host.php', 'graphs.php', 'tree.php'],
			3 => ['api_view.php'],
		];

		$repo->sites = [
			1 => [
				'id'           => 1,
				'name'         => 'Primary DC',
				'city'         => 'San Jose',
				'state'        => 'CA',
				'country'      => 'US',
				'timezone'     => 'UTC',
				'alternate_id' => 'dc-1',
				'notes'        => 'Main datacenter',
			],
		];
		$repo->nextSiteId = 2;

		$repo->pollers = [
			1 => [
				'id'         => 1,
				'name'       => 'Main Poller',
				'hostname'   => 'cacti-poller-1.local',
				'disabled'   => '',
				'status'     => 2,
				'processes'  => 2,
				'threads'    => 4,
				'total_time' => 1.25,
				'last_update' => '2026-01-01 00:00:00',
			],
		];

		$repo->devices = [
			1 => [
				'id'               => 1,
				'poller_id'        => 1,
				'site_id'          => 1,
				'host_template_id' => 1,
				'description'      => 'Core Switch 01',
				'hostname'         => '10.0.0.1',
				'location'         => 'Rack A1',
				'notes'            => 'Distribution switch',
				'external_id'      => 'sw-01',
				'snmp_version'     => 2,
				'snmp_port'        => 161,
				'disabled'         => '',
				'status'           => 3,
				'availability'     => 99.98,
			],
		];
		$repo->nextDeviceId = 2;

		$repo->graphs = [
			10 => [
				'id'                => 10,
				'host_id'           => 1,
				'graph_template_id' => 2,
				'title_cache'       => 'Core Switch 01 - Traffic - Gi0/1',
				'height'            => 120,
				'width'             => 500,
			],
		];

		$repo->dataSources = [
			20 => [
				'id'               => 20,
				'host_id'          => 1,
				'data_template_id' => 5,
				'name_cache'       => 'Core Switch 01 - Traffic - Gi0/1',
				'active'           => 'on',
				'rrd_step'         => 300,
			],
		];

		$repo->trees = [
			1 => [
				'id'        => 1,
				'name'      => 'Default Tree',
				'enabled'   => 'on',
				'sort_type' => 1,
				'sequence'  => 1,
			],
		];

		$repo->plugins = [
			'api' => [
				'id'        => 1,
				'directory' => 'api',
				'name'      => 'REST API',
				'status'    => 1,
				'author'    => 'Thomas Vincent (somethingwithproof)',
				'webpage'   => 'https://github.com/somethingwithproof/plugin_api',
				'version'   => '0.1.0',
			],
		];

		return $repo;
	}

	/**
	 * {@inheritdoc}
	 */
	public function listSites(?FilterNode $filter = null, array $orderBy = []): array {
		return $this->filterAndSort(array_values($this->sites), $filter, ['id', 'name', 'city', 'state', 'country', 'timezone', 'alternate_id'], $orderBy, 'id');
	}

	/**
	 * {@inheritdoc}
	 */
	public function getSite(int $id): ?array {
		return $this->sites[$id] ?? null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function createSite(array $data): array {
		$id = $this->nextSiteId++;

		while (isset($this->sites[$id])) {
			$id = $this->nextSiteId++;
		}

		$row = [
			'id'           => $id,
			'name'         => is_scalar($data['name'] ?? null) ? (string) $data['name'] : '',
			'city'         => is_scalar($data['city'] ?? null) ? (string) $data['city'] : '',
			'state'        => is_scalar($data['state'] ?? null) ? (string) $data['state'] : '',
			'country'      => is_scalar($data['country'] ?? null) ? (string) $data['country'] : '',
			'timezone'     => is_scalar($data['timezone'] ?? null) ? (string) $data['timezone'] : '',
			'alternate_id' => is_scalar($data['alternate_id'] ?? null) ? (string) $data['alternate_id'] : '',
			'notes'        => is_scalar($data['notes'] ?? null) ? (string) $data['notes'] : '',
		];

		$this->sites[$id] = $row;

		return $row;
	}

	/**
	 * {@inheritdoc}
	 */
	public function updateSite(int $id, array $data): ?array {
		if (!isset($this->sites[$id])) {
			return null;
		}

		foreach (['name', 'city', 'state', 'country', 'timezone', 'alternate_id', 'notes'] as $field) {
			if (array_key_exists($field, $data)) {
				$this->sites[$id][$field] = is_scalar($data[$field]) ? (string) $data[$field] : '';
			}
		}

		return $this->sites[$id];
	}

	/**
	 * {@inheritdoc}
	 */
	public function deleteSite(int $id): bool {
		if (!isset($this->sites[$id])) {
			return false;
		}

		unset($this->sites[$id]);

		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function listPollers(?FilterNode $filter = null, array $orderBy = []): array {
		return $this->filterAndSort(array_values($this->pollers), $filter, ['id', 'name', 'hostname', 'status', 'disabled'], $orderBy, 'id');
	}

	/**
	 * {@inheritdoc}
	 */
	public function getPoller(int $id): ?array {
		return $this->pollers[$id] ?? null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function listDevices(?FilterNode $filter = null, array $orderBy = []): array {
		return $this->filterAndSort(
			array_values($this->devices),
			$filter,
			['id', 'description', 'hostname', 'site_id', 'poller_id', 'status', 'disabled', 'location', 'external_id', 'snmp_version'],
			$orderBy,
			'id'
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function getDevice(int $id): ?array {
		return $this->devices[$id] ?? null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function createDevice(array $data): array {
		$id = $this->nextDeviceId++;

		while (isset($this->devices[$id])) {
			$id = $this->nextDeviceId++;
		}

		$row = [
			'id'               => $id,
			'poller_id'        => is_numeric($data['poller_id'] ?? null) ? (int) $data['poller_id'] : 1,
			'site_id'          => is_numeric($data['site_id'] ?? null) ? (int) $data['site_id'] : 0,
			'host_template_id' => is_numeric($data['host_template_id'] ?? null) ? (int) $data['host_template_id'] : 0,
			'description'      => is_scalar($data['description'] ?? null) ? (string) $data['description'] : '',
			'hostname'         => is_scalar($data['hostname'] ?? null) ? (string) $data['hostname'] : '',
			'location'         => is_scalar($data['location'] ?? null) ? (string) $data['location'] : '',
			'notes'            => is_scalar($data['notes'] ?? null) ? (string) $data['notes'] : '',
			'external_id'      => is_scalar($data['external_id'] ?? null) ? (string) $data['external_id'] : '',
			'snmp_version'     => is_numeric($data['snmp_version'] ?? null) ? (int) $data['snmp_version'] : 2,
			'snmp_port'        => is_numeric($data['snmp_port'] ?? null) ? (int) $data['snmp_port'] : 161,
			'disabled'         => ($data['disabled'] ?? false) === true || ($data['disabled'] ?? '') === 'on' ? 'on' : '',
			'status'           => 0,
			'availability'     => 100.0,
		];

		$this->devices[$id] = $row;

		return $row;
	}

	/**
	 * {@inheritdoc}
	 */
	public function updateDevice(int $id, array $data): ?array {
		if (!isset($this->devices[$id])) {
			return null;
		}

		$intFields    = ['poller_id', 'site_id', 'host_template_id', 'snmp_version', 'snmp_port'];
		$stringFields = ['description', 'hostname', 'location', 'notes', 'external_id'];

		foreach ($intFields as $field) {
			if (array_key_exists($field, $data) && is_numeric($data[$field])) {
				$this->devices[$id][$field] = (int) $data[$field];
			}
		}

		foreach ($stringFields as $field) {
			if (array_key_exists($field, $data)) {
				$this->devices[$id][$field] = is_scalar($data[$field]) ? (string) $data[$field] : '';
			}
		}

		if (array_key_exists('disabled', $data)) {
			$this->devices[$id]['disabled'] = ($data['disabled'] === true || $data['disabled'] === 'on') ? 'on' : '';
		}

		return $this->devices[$id];
	}

	/**
	 * {@inheritdoc}
	 */
	public function deleteDevice(int $id): bool {
		if (!isset($this->devices[$id])) {
			return false;
		}

		unset($this->devices[$id]);

		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function setDeviceDisabled(int $id, bool $disabled): ?array {
		if (!isset($this->devices[$id])) {
			return null;
		}

		$this->devices[$id]['disabled'] = $disabled ? 'on' : '';

		return $this->devices[$id];
	}

	/**
	 * {@inheritdoc}
	 */
	public function listGraphs(?int $deviceId = null, ?FilterNode $filter = null, array $orderBy = []): array {
		$rows = array_values($this->graphs);

		if ($deviceId !== null) {
			$rows = array_values(array_filter(
				$rows,
				static fn (array $r): bool => is_numeric($r['host_id'] ?? null) && (int) $r['host_id'] === $deviceId
			));
		}

		return $this->filterAndSort($rows, $filter, ['id', 'host_id', 'graph_template_id', 'title_cache'], $orderBy, 'id');
	}

	/**
	 * {@inheritdoc}
	 */
	public function getGraph(int $id, ?int $deviceId = null): ?array {
		$row = $this->graphs[$id] ?? null;

		if ($row === null) {
			return null;
		}

		if ($deviceId !== null && (!is_numeric($row['host_id'] ?? null) || (int) $row['host_id'] !== $deviceId)) {
			return null;
		}

		return $row;
	}

	/**
	 * {@inheritdoc}
	 */
	public function listDataSources(?FilterNode $filter = null, array $orderBy = []): array {
		return $this->filterAndSort(array_values($this->dataSources), $filter, ['id', 'host_id', 'data_template_id', 'name_cache', 'active'], $orderBy, 'id');
	}

	/**
	 * {@inheritdoc}
	 */
	public function getDataSource(int $id): ?array {
		return $this->dataSources[$id] ?? null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function listTrees(?FilterNode $filter = null, array $orderBy = []): array {
		return $this->filterAndSort(array_values($this->trees), $filter, ['id', 'name', 'enabled', 'sort_type', 'sequence'], $orderBy, 'id');
	}

	/**
	 * {@inheritdoc}
	 */
	public function getTree(int $id): ?array {
		return $this->trees[$id] ?? null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function listPlugins(?FilterNode $filter = null, array $orderBy = []): array {
		return $this->filterAndSort(array_values($this->plugins), $filter, ['id', 'directory', 'name', 'status', 'version'], $orderBy, 'directory');
	}

	/**
	 * {@inheritdoc}
	 */
	public function getPlugin(string $directory): ?array {
		return $this->plugins[$directory] ?? null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function createToken(array $record): void {
		$this->tokens[$record['id']] = $record;
	}

	/**
	 * {@inheritdoc}
	 */
	public function findTokensByPrefix(string $prefix): array {
		$out = [];

		foreach ($this->tokens as $token) {
			if ($token['token_prefix'] === $prefix) {
				$out[] = $token;
			}
		}

		return $out;
	}

	/**
	 * {@inheritdoc}
	 */
	public function findTokenById(string $id): ?array {
		return $this->tokens[$id] ?? null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function listTokens(?int $userId = null): array {
		$out = [];

		foreach ($this->tokens as $token) {
			if ($userId === null || $token['user_id'] === $userId) {
				$out[] = $token;
			}
		}

		return $out;
	}

	/**
	 * {@inheritdoc}
	 */
	public function touchTokenLastUsed(string $id, int $now): void {
		if (isset($this->tokens[$id])) {
			$this->tokens[$id]['last_used_at'] = $now;
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function revokeToken(string $id, int $now): void {
		if (isset($this->tokens[$id])) {
			$this->tokens[$id]['revoked_at'] = $now;
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function deleteToken(string $id): void {
		unset($this->tokens[$id]);
	}

	/**
	 * {@inheritdoc}
	 */
	public function createOperation(array $record): void {
		$this->operations[$record['id']] = $record;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getOperation(string $id): ?array {
		return $this->operations[$id] ?? null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function listOperations(?int $userId = null): array {
		$out = [];

		foreach ($this->operations as $op) {
			if ($userId === null || $op['user_id'] === $userId) {
				$out[] = $op;
			}
		}

		return $out;
	}

	/**
	 * {@inheritdoc}
	 */
	public function updateOperation(array $record): void {
		$this->operations[$record['id']] = $record;
	}

	/**
	 * {@inheritdoc}
	 */
	public function deleteOperation(string $id): void {
		unset($this->operations[$id]);
	}

	/**
	 * {@inheritdoc}
	 */
	public function getIdempotencyRecord(string $scopedKey, int $now): ?array {
		$rec = $this->idempotency[$scopedKey] ?? null;

		if ($rec === null || $rec['expires_at'] <= $now) {
			return null;
		}

		return $rec;
	}

	/**
	 * {@inheritdoc}
	 */
	public function saveIdempotencyRecord(
		string $scopedKey,
		int $userId,
		string $method,
		string $path,
		string $requestHash,
		int $statusCode,
		string $responseJson,
		int $createdAt,
		int $expiresAt,
	): void {
		$this->idempotency[$scopedKey] = [
			'request_id'    => $scopedKey,
			'user_id'       => $userId,
			'method'        => $method,
			'path'          => $path,
			'request_hash'  => $requestHash,
			'status_code'   => $statusCode,
			'response_json' => $responseJson,
			'created_at'    => $createdAt,
			'expires_at'    => $expiresAt,
		];
	}

	/**
	 * {@inheritdoc}
	 */
	public function incrementRateLimitBucket(string $bucketKey, int $windowStart): int {
		if (!isset($this->rateLimits[$bucketKey]) || $this->rateLimits[$bucketKey]['window_start'] !== $windowStart) {
			$this->rateLimits[$bucketKey] = [
				'window_start' => $windowStart,
				'hit_count'    => 1,
			];

			return 1;
		}

		$this->rateLimits[$bucketKey]['hit_count']++;

		return $this->rateLimits[$bucketKey]['hit_count'];
	}

	/**
	 * {@inheritdoc}
	 */
	public function getHealthStats(int $now): array {
		$active  = 0;
		$expired = 0;
		$revoked = 0;

		foreach ($this->tokens as $t) {
			if ($t['revoked_at'] !== null) {
				$revoked++;
			} elseif ($t['expires_at'] !== null && $t['expires_at'] <= $now) {
				$expired++;
			} else {
				$active++;
			}
		}

		$pending = 0;
		$stuck   = 0;

		foreach ($this->operations as $op) {
			if (!$op['done']) {
				$pending++;

				if ($now - $op['created_at'] > 3600) {
					$stuck++;
				}
			}
		}

		return [
			'active_tokens'      => $active,
			'expired_tokens'     => $expired,
			'revoked_tokens'     => $revoked,
			'pending_operations' => $pending,
			'stuck_operations'   => $stuck,
			'rate_limit_rows'    => count($this->rateLimits),
		];
	}

	/**
	 * {@inheritdoc}
	 */
	public function getUserById(int $userId): ?array {
		return $this->users[$userId] ?? null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getUserRealms(int $userId): array {
		return $this->userRealms[$userId] ?? [];
	}

	/**
	 * {@inheritdoc}
	 */
	public function isDeviceAllowedForUser(int $userId, int $deviceId): bool {
		return !in_array($deviceId, $this->deniedDevices[$userId] ?? [], true);
	}

	/**
	 * {@inheritdoc}
	 */
	public function isGraphAllowedForUser(int $userId, int $graphId): bool {
		return !in_array($graphId, $this->deniedGraphs[$userId] ?? [], true);
	}

	/**
	 * {@inheritdoc}
	 */
	public function isTreeAllowedForUser(int $userId, int $treeId): bool {
		return !in_array($treeId, $this->deniedTrees[$userId] ?? [], true);
	}

	/**
	 * Apply in-memory AIP-160 filtering and AIP-132 sorting to a row list.
	 *
	 * @param list<array<string, mixed>>                                          $rows          Input rows.
	 * @param FilterNode|null                                                     $filter        Optional filter AST.
	 * @param list<string>                                                        $allowedFields Filterable field names.
	 * @param list<array{field: string, column: string, direction: 'ASC'|'DESC'}> $orderBy       Parsed sort directives.
	 * @param string                                                              $tieField      Tie-breaker field name.
	 * @return list<array<string, mixed>>
	 */
	private function filterAndSort(
		array $rows,
		?FilterNode $filter,
		array $allowedFields,
		array $orderBy,
		string $tieField,
	): array {
		if ($filter !== null) {
			$rows = array_values(array_filter(
				$rows,
				static fn (array $r): bool => SqlFilterCompiler::matchesRecord($filter, $r, $allowedFields)
			));
		}

		return OrderByParser::sortRecords($rows, $orderBy, $tieField);
	}
}
