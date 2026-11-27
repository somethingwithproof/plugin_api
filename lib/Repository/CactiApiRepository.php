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
 * Live MariaDB/Cacti 1.2 repository backed exclusively by Cacti prepared-statement APIs.
 */
final class CactiApiRepository implements ApiRepositoryInterface {
	/**
	 * Construct a CactiApiRepository bound to an optional PDO connection.
	 *
	 * @param \PDO|false $connection Cacti database connection handle, or false for default.
	 */
	public function __construct(private readonly \PDO|false $connection = false) {
	}

	/**
	 * {@inheritdoc}
	 */
	public function listSites(?FilterNode $filter = null, array $orderBy = []): array {
		$map      = ['id' => 'id', 'name' => 'name', 'city' => 'city', 'state' => 'state', 'country' => 'country', 'timezone' => 'timezone', 'alternate_id' => 'alternate_id'];
		$compiled = SqlFilterCompiler::compile($filter, $map);
		$where    = $compiled['sql'] !== '' ? ' WHERE ' . $compiled['sql'] : '';
		$orderSql = OrderByParser::toSql($orderBy, 'id');

		return $this->fetchAssoc(
			'SELECT id, name, city, state, country, timezone, alternate_id, notes FROM sites' . $where . ' ORDER BY ' . $orderSql,
			$compiled['params']
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function getSite(int $id): ?array {
		return $this->fetchRow(
			'SELECT id, name, city, state, country, timezone, alternate_id, notes FROM sites WHERE id = ?',
			[$id]
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function createSite(array $data): array {
		db_execute_prepared(
			'INSERT INTO sites (name, city, state, country, timezone, alternate_id, notes) VALUES (?, ?, ?, ?, ?, ?, ?)',
			[
				self::str($data['name'] ?? ''),
				self::str($data['city'] ?? ''),
				self::str($data['state'] ?? ''),
				self::str($data['country'] ?? ''),
				self::str($data['timezone'] ?? ''),
				self::str($data['alternate_id'] ?? ''),
				self::str($data['notes'] ?? ''),
			],
			true,
			$this->connection
		);

		$id  = (int) db_fetch_insert_id($this->connection);
		$row = $this->getSite($id);

		return $row ?? ['id' => $id, 'name' => self::str($data['name'] ?? '')];
	}

	/**
	 * {@inheritdoc}
	 */
	public function updateSite(int $id, array $data): ?array {
		if ($this->getSite($id) === null) {
			return null;
		}

		$allowed = ['name', 'city', 'state', 'country', 'timezone', 'alternate_id', 'notes'];
		$sets    = [];
		$params  = [];

		foreach ($allowed as $col) {
			if (array_key_exists($col, $data)) {
				$sets[]   = $col . ' = ?';
				$params[] = self::str($data[$col]);
			}
		}

		if ($sets !== []) {
			$params[] = $id;
			db_execute_prepared(
				'UPDATE sites SET ' . implode(', ', $sets) . ' WHERE id = ?',
				$params,
				true,
				$this->connection
			);
		}

		return $this->getSite($id);
	}

	/**
	 * {@inheritdoc}
	 */
	public function deleteSite(int $id): bool {
		if ($this->getSite($id) === null) {
			return false;
		}

		db_execute_prepared('DELETE FROM sites WHERE id = ?', [$id], true, $this->connection);

		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function listPollers(?FilterNode $filter = null, array $orderBy = []): array {
		$map      = ['id' => 'id', 'name' => 'name', 'hostname' => 'hostname', 'status' => 'status', 'disabled' => 'disabled'];
		$compiled = SqlFilterCompiler::compile($filter, $map);
		$where    = $compiled['sql'] !== '' ? ' WHERE ' . $compiled['sql'] : '';
		$orderSql = OrderByParser::toSql($orderBy, 'id');

		return $this->fetchAssoc(
			'SELECT id, name, hostname, disabled, status, processes, threads, total_time, last_update FROM poller' . $where . ' ORDER BY ' . $orderSql,
			$compiled['params']
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function getPoller(int $id): ?array {
		return $this->fetchRow(
			'SELECT id, name, hostname, disabled, status, processes, threads, total_time, last_update FROM poller WHERE id = ?',
			[$id]
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function listDevices(?FilterNode $filter = null, array $orderBy = []): array {
		$map = [
			'id'           => 'id',
			'description'  => 'description',
			'hostname'     => 'hostname',
			'site_id'      => 'site_id',
			'poller_id'    => 'poller_id',
			'status'       => 'status',
			'disabled'     => 'disabled',
			'location'     => 'location',
			'external_id'  => 'external_id',
			'snmp_version' => 'snmp_version',
		];
		$compiled = SqlFilterCompiler::compile($filter, $map);
		$where    = $compiled['sql'] !== '' ? ' WHERE deleted = \'\' AND (' . $compiled['sql'] . ')' : ' WHERE deleted = \'\'';
		$orderSql = OrderByParser::toSql($orderBy, 'id');

		return $this->fetchAssoc(
			'SELECT id, poller_id, site_id, host_template_id, description, hostname, location, notes, external_id, snmp_version, snmp_port, disabled, status, availability FROM host' . $where . ' ORDER BY ' . $orderSql,
			$compiled['params']
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function getDevice(int $id): ?array {
		return $this->fetchRow(
			'SELECT id, poller_id, site_id, host_template_id, description, hostname, location, notes, external_id, snmp_version, snmp_port, disabled, status, availability FROM host WHERE id = ? AND deleted = \'\'',
			[$id]
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function createDevice(array $data): array {
		$disabled = ($data['disabled'] ?? false) === true || ($data['disabled'] ?? '') === 'on' ? 'on' : '';

		db_execute_prepared(
			'INSERT INTO host (poller_id, site_id, host_template_id, description, hostname, location, notes, external_id, snmp_version, snmp_port, disabled) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
			[
				self::intVal($data['poller_id'] ?? 1, 1),
				self::intVal($data['site_id'] ?? 0, 0),
				self::intVal($data['host_template_id'] ?? 0, 0),
				self::str($data['description'] ?? ''),
				self::str($data['hostname'] ?? ''),
				self::str($data['location'] ?? ''),
				self::str($data['notes'] ?? ''),
				self::str($data['external_id'] ?? ''),
				self::intVal($data['snmp_version'] ?? 2, 2),
				self::intVal($data['snmp_port'] ?? 161, 161),
				$disabled,
			],
			true,
			$this->connection
		);

		$id  = (int) db_fetch_insert_id($this->connection);
		$row = $this->getDevice($id);

		return $row ?? ['id' => $id, 'description' => self::str($data['description'] ?? '')];
	}

	/**
	 * {@inheritdoc}
	 */
	public function updateDevice(int $id, array $data): ?array {
		if ($this->getDevice($id) === null) {
			return null;
		}

		$intCols    = ['poller_id', 'site_id', 'host_template_id', 'snmp_version', 'snmp_port'];
		$stringCols = ['description', 'hostname', 'location', 'notes', 'external_id'];
		$sets       = [];
		$params     = [];

		foreach ($intCols as $col) {
			if (array_key_exists($col, $data)) {
				$sets[]   = $col . ' = ?';
				$params[] = self::intVal($data[$col], 0);
			}
		}

		foreach ($stringCols as $col) {
			if (array_key_exists($col, $data)) {
				$sets[]   = $col . ' = ?';
				$params[] = self::str($data[$col]);
			}
		}

		if (array_key_exists('disabled', $data)) {
			$sets[]   = 'disabled = ?';
			$params[] = ($data['disabled'] === true || $data['disabled'] === 'on') ? 'on' : '';
		}

		if ($sets !== []) {
			$params[] = $id;
			db_execute_prepared(
				'UPDATE host SET ' . implode(', ', $sets) . ' WHERE id = ?',
				$params,
				true,
				$this->connection
			);
		}

		return $this->getDevice($id);
	}

	/**
	 * {@inheritdoc}
	 */
	public function deleteDevice(int $id): bool {
		if ($this->getDevice($id) === null) {
			return false;
		}

		db_execute_prepared('DELETE FROM host WHERE id = ?', [$id], true, $this->connection);

		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function setDeviceDisabled(int $id, bool $disabled): ?array {
		if ($this->getDevice($id) === null) {
			return null;
		}

		db_execute_prepared(
			'UPDATE host SET disabled = ? WHERE id = ?',
			[$disabled ? 'on' : '', $id],
			true,
			$this->connection
		);

		return $this->getDevice($id);
	}

	/**
	 * {@inheritdoc}
	 */
	public function listGraphs(?int $deviceId = null, ?FilterNode $filter = null, array $orderBy = []): array {
		$map = [
			'id'                => 'gl.id',
			'host_id'           => 'gl.host_id',
			'graph_template_id' => 'gl.graph_template_id',
			'title_cache'       => 'gtg.title_cache',
		];
		$compiled = SqlFilterCompiler::compile($filter, $map);
		$clauses  = [];
		$params   = [];

		if ($deviceId !== null) {
			$clauses[] = 'gl.host_id = ?';
			$params[]  = $deviceId;
		}

		if ($compiled['sql'] !== '') {
			$clauses[] = '(' . $compiled['sql'] . ')';
			$params    = array_merge($params, $compiled['params']);
		}

		$where    = $clauses !== [] ? ' WHERE ' . implode(' AND ', $clauses) : '';
		$orderSql = OrderByParser::toSql($orderBy, 'gl.id');

		return $this->fetchAssoc(
			'SELECT gl.id, gl.host_id, gl.graph_template_id, gtg.title_cache, gtg.height, gtg.width FROM graph_local AS gl LEFT JOIN graph_templates_graph AS gtg ON gtg.local_graph_id = gl.id' . $where . ' ORDER BY ' . $orderSql,
			$params
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function getGraph(int $id, ?int $deviceId = null): ?array {
		$sql    = 'SELECT gl.id, gl.host_id, gl.graph_template_id, gtg.title_cache, gtg.height, gtg.width FROM graph_local AS gl LEFT JOIN graph_templates_graph AS gtg ON gtg.local_graph_id = gl.id WHERE gl.id = ?';
		$params = [$id];

		if ($deviceId !== null) {
			$sql     .= ' AND gl.host_id = ?';
			$params[] = $deviceId;
		}

		return $this->fetchRow($sql, $params);
	}

	/**
	 * {@inheritdoc}
	 */
	public function listDataSources(?FilterNode $filter = null, array $orderBy = []): array {
		$map = [
			'id'               => 'dl.id',
			'host_id'          => 'dl.host_id',
			'data_template_id' => 'dl.data_template_id',
			'name_cache'       => 'dtd.name_cache',
			'active'           => 'dtd.active',
		];
		$compiled = SqlFilterCompiler::compile($filter, $map);
		$where    = $compiled['sql'] !== '' ? ' WHERE ' . $compiled['sql'] : '';
		$orderSql = OrderByParser::toSql($orderBy, 'dl.id');

		return $this->fetchAssoc(
			'SELECT dl.id, dl.host_id, dl.data_template_id, dtd.name_cache, dtd.active, dtd.rrd_step FROM data_local AS dl LEFT JOIN data_template_data AS dtd ON dtd.local_data_id = dl.id' . $where . ' ORDER BY ' . $orderSql,
			$compiled['params']
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function getDataSource(int $id): ?array {
		return $this->fetchRow(
			'SELECT dl.id, dl.host_id, dl.data_template_id, dtd.name_cache, dtd.active, dtd.rrd_step FROM data_local AS dl LEFT JOIN data_template_data AS dtd ON dtd.local_data_id = dl.id WHERE dl.id = ?',
			[$id]
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function listTrees(?FilterNode $filter = null, array $orderBy = []): array {
		$map      = ['id' => 'id', 'name' => 'name', 'enabled' => 'enabled', 'sort_type' => 'sort_type', 'sequence' => 'sequence'];
		$compiled = SqlFilterCompiler::compile($filter, $map);
		$where    = $compiled['sql'] !== '' ? ' WHERE ' . $compiled['sql'] : '';
		$orderSql = OrderByParser::toSql($orderBy, 'id');

		return $this->fetchAssoc(
			'SELECT id, name, enabled, sort_type, sequence FROM graph_tree' . $where . ' ORDER BY ' . $orderSql,
			$compiled['params']
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function getTree(int $id): ?array {
		return $this->fetchRow(
			'SELECT id, name, enabled, sort_type, sequence FROM graph_tree WHERE id = ?',
			[$id]
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function listPlugins(?FilterNode $filter = null, array $orderBy = []): array {
		$map      = ['id' => 'id', 'directory' => 'directory', 'name' => 'name', 'status' => 'status', 'version' => 'version'];
		$compiled = SqlFilterCompiler::compile($filter, $map);
		$where    = $compiled['sql'] !== '' ? ' WHERE ' . $compiled['sql'] : '';
		$orderSql = OrderByParser::toSql($orderBy, 'directory');

		return $this->fetchAssoc(
			'SELECT id, directory, name, status, author, webpage, version FROM plugin_config' . $where . ' ORDER BY ' . $orderSql,
			$compiled['params']
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function getPlugin(string $directory): ?array {
		return $this->fetchRow(
			'SELECT id, directory, name, status, author, webpage, version FROM plugin_config WHERE directory = ?',
			[$directory]
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function createToken(array $record): void {
		db_execute_prepared(
			'INSERT INTO plugin_api_tokens (id, user_id, name, token_prefix, token_hash, scopes, cidr_allowlist, created_at, expires_at, last_used_at, revoked_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
			[
				$record['id'],
				$record['user_id'],
				$record['name'],
				$record['token_prefix'],
				$record['token_hash'],
				$record['scopes'],
				$record['cidr_allowlist'],
				$record['created_at'],
				$record['expires_at'],
				$record['last_used_at'],
				$record['revoked_at'],
			],
			true,
			$this->connection
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function findTokensByPrefix(string $prefix): array {
		$rows = $this->fetchAssoc(
			'SELECT id, user_id, name, token_prefix, token_hash, scopes, cidr_allowlist, created_at, expires_at, last_used_at, revoked_at FROM plugin_api_tokens WHERE token_prefix = ?',
			[$prefix]
		);

		return array_map([self::class, 'hydrateToken'], $rows);
	}

	/**
	 * {@inheritdoc}
	 */
	public function findTokenById(string $id): ?array {
		$row = $this->fetchRow(
			'SELECT id, user_id, name, token_prefix, token_hash, scopes, cidr_allowlist, created_at, expires_at, last_used_at, revoked_at FROM plugin_api_tokens WHERE id = ?',
			[$id]
		);

		return $row !== null ? self::hydrateToken($row) : null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function listTokens(?int $userId = null): array {
		if ($userId !== null) {
			$rows = $this->fetchAssoc(
				'SELECT id, user_id, name, token_prefix, token_hash, scopes, cidr_allowlist, created_at, expires_at, last_used_at, revoked_at FROM plugin_api_tokens WHERE user_id = ? ORDER BY created_at DESC',
				[$userId]
			);
		} else {
			$rows = $this->fetchAssoc(
				'SELECT id, user_id, name, token_prefix, token_hash, scopes, cidr_allowlist, created_at, expires_at, last_used_at, revoked_at FROM plugin_api_tokens ORDER BY created_at DESC',
				[]
			);
		}

		return array_map([self::class, 'hydrateToken'], $rows);
	}

	/**
	 * {@inheritdoc}
	 */
	public function touchTokenLastUsed(string $id, int $now): void {
		db_execute_prepared(
			'UPDATE plugin_api_tokens SET last_used_at = ? WHERE id = ?',
			[$now, $id],
			true,
			$this->connection
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function revokeToken(string $id, int $now): void {
		db_execute_prepared(
			'UPDATE plugin_api_tokens SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL',
			[$now, $id],
			true,
			$this->connection
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function deleteToken(string $id): void {
		db_execute_prepared(
			'DELETE FROM plugin_api_tokens WHERE id = ?',
			[$id],
			true,
			$this->connection
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function createOperation(array $record): void {
		db_execute_prepared(
			'INSERT INTO plugin_api_operations (id, user_id, operation_type, target_resource, done, cancelled, metadata_json, response_json, error_json, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
			[
				$record['id'],
				$record['user_id'],
				$record['operation_type'],
				$record['target_resource'],
				$record['done'] ? 1 : 0,
				$record['cancelled'] ? 1 : 0,
				json_encode($record['metadata'], JSON_THROW_ON_ERROR),
				$record['response'] !== null ? json_encode($record['response'], JSON_THROW_ON_ERROR) : null,
				$record['error'] !== null ? json_encode($record['error'], JSON_THROW_ON_ERROR) : null,
				$record['created_at'],
				$record['updated_at'],
			],
			true,
			$this->connection
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function getOperation(string $id): ?array {
		$row = $this->fetchRow(
			'SELECT id, user_id, operation_type, target_resource, done, cancelled, metadata_json, response_json, error_json, created_at, updated_at FROM plugin_api_operations WHERE id = ?',
			[$id]
		);

		return $row !== null ? self::hydrateOperation($row) : null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function listOperations(?int $userId = null): array {
		if ($userId !== null) {
			$rows = $this->fetchAssoc(
				'SELECT id, user_id, operation_type, target_resource, done, cancelled, metadata_json, response_json, error_json, created_at, updated_at FROM plugin_api_operations WHERE user_id = ? ORDER BY created_at DESC',
				[$userId]
			);
		} else {
			$rows = $this->fetchAssoc(
				'SELECT id, user_id, operation_type, target_resource, done, cancelled, metadata_json, response_json, error_json, created_at, updated_at FROM plugin_api_operations ORDER BY created_at DESC',
				[]
			);
		}

		return array_map([self::class, 'hydrateOperation'], $rows);
	}

	/**
	 * {@inheritdoc}
	 */
	public function updateOperation(array $record): void {
		db_execute_prepared(
			'UPDATE plugin_api_operations SET done = ?, cancelled = ?, metadata_json = ?, response_json = ?, error_json = ?, updated_at = ? WHERE id = ?',
			[
				$record['done'] ? 1 : 0,
				$record['cancelled'] ? 1 : 0,
				json_encode($record['metadata'], JSON_THROW_ON_ERROR),
				$record['response'] !== null ? json_encode($record['response'], JSON_THROW_ON_ERROR) : null,
				$record['error'] !== null ? json_encode($record['error'], JSON_THROW_ON_ERROR) : null,
				$record['updated_at'],
				$record['id'],
			],
			true,
			$this->connection
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function deleteOperation(string $id): void {
		db_execute_prepared(
			'DELETE FROM plugin_api_operations WHERE id = ?',
			[$id],
			true,
			$this->connection
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function getIdempotencyRecord(string $scopedKey, int $now): ?array {
		$row = $this->fetchRow(
			'SELECT request_id, user_id, method, path, request_hash, status_code, response_json, created_at, expires_at FROM plugin_api_idempotency WHERE request_id = ? AND expires_at > ?',
			[$scopedKey, $now]
		);

		if ($row === null) {
			return null;
		}

		return [
			'request_id'    => self::str($row['request_id'] ?? ''),
			'user_id'       => self::intVal($row['user_id'] ?? 0, 0),
			'method'        => self::str($row['method'] ?? ''),
			'path'          => self::str($row['path'] ?? ''),
			'request_hash'  => self::str($row['request_hash'] ?? ''),
			'status_code'   => self::intVal($row['status_code'] ?? 200, 200),
			'response_json' => self::str($row['response_json'] ?? '{}'),
			'created_at'    => self::intVal($row['created_at'] ?? 0, 0),
			'expires_at'    => self::intVal($row['expires_at'] ?? 0, 0),
		];
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
		db_execute_prepared(
			'REPLACE INTO plugin_api_idempotency (request_id, user_id, method, path, request_hash, status_code, response_json, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
			[$scopedKey, $userId, $method, $path, $requestHash, $statusCode, $responseJson, $createdAt, $expiresAt],
			true,
			$this->connection
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function incrementRateLimitBucket(string $bucketKey, int $windowStart): int {
		db_execute_prepared(
			'INSERT INTO plugin_api_rate_limits (bucket_key, window_start, hit_count) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE hit_count = IF(window_start = VALUES(window_start), hit_count + 1, 1), window_start = VALUES(window_start)',
			[$bucketKey, $windowStart],
			true,
			$this->connection
		);

		$count = db_fetch_cell_prepared(
			'SELECT hit_count FROM plugin_api_rate_limits WHERE bucket_key = ?',
			[$bucketKey],
			'',
			true,
			$this->connection
		);

		return is_numeric($count) ? (int) $count : 1;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getHealthStats(int $now): array {
		$activeTokens  = $this->fetchIntCell('SELECT COUNT(*) FROM plugin_api_tokens WHERE revoked_at IS NULL AND (expires_at IS NULL OR expires_at > ?)', [$now]);
		$expiredTokens = $this->fetchIntCell('SELECT COUNT(*) FROM plugin_api_tokens WHERE revoked_at IS NULL AND expires_at IS NOT NULL AND expires_at <= ?', [$now]);
		$revokedTokens = $this->fetchIntCell('SELECT COUNT(*) FROM plugin_api_tokens WHERE revoked_at IS NOT NULL', []);
		$pendingOps    = $this->fetchIntCell('SELECT COUNT(*) FROM plugin_api_operations WHERE done = 0', []);
		$stuckOps      = $this->fetchIntCell('SELECT COUNT(*) FROM plugin_api_operations WHERE done = 0 AND created_at < ?', [$now - 3600]);
		$rateRows      = $this->fetchIntCell('SELECT COUNT(*) FROM plugin_api_rate_limits', []);

		return [
			'active_tokens'      => $activeTokens,
			'expired_tokens'     => $expiredTokens,
			'revoked_tokens'     => $revokedTokens,
			'pending_operations' => $pendingOps,
			'stuck_operations'   => $stuckOps,
			'rate_limit_rows'    => $rateRows,
		];
	}

	/**
	 * {@inheritdoc}
	 */
	public function getUserById(int $userId): ?array {
		$row = $this->fetchRow(
			'SELECT id, username, enabled, locked FROM user_auth WHERE id = ?',
			[$userId]
		);

		if ($row === null) {
			return null;
		}

		return [
			'id'       => self::intVal($row['id'] ?? 0, 0),
			'username' => self::str($row['username'] ?? ''),
			'enabled'  => self::str($row['enabled'] ?? '') === 'on',
			'locked'   => self::str($row['locked'] ?? '') === 'on',
		];
	}

	/**
	 * {@inheritdoc}
	 */
	public function getUserRealms(int $userId): array {
		$realms = [];

		$pluginRows = $this->fetchAssoc(
			'SELECT pr.file FROM plugin_realms AS pr INNER JOIN user_auth_realm AS uar ON uar.realm_id = (pr.id + 100) WHERE uar.user_id = ?',
			[$userId]
		);

		foreach ($pluginRows as $row) {
			$files = self::str($row['file'] ?? '');

			foreach (explode(',', $files) as $file) {
				$trimmed = trim($file);

				if ($trimmed !== '') {
					$realms[] = $trimmed;
				}
			}
		}

		$coreRealmIds = $this->fetchAssoc(
			'SELECT realm_id FROM user_auth_realm WHERE user_id = ? AND realm_id < 100',
			[$userId]
		);

		$coreMap = [
			3  => ['host.php', 'sites.php', 'pollers.php'],
			4  => ['tree.php'],
			5  => ['graphs.php'],
			7  => ['graphs.php', 'tree.php'],
			8  => ['data_sources.php'],
			15 => ['settings.php', 'plugins.php'],
		];

		foreach ($coreRealmIds as $row) {
			$rid = self::intVal($row['realm_id'] ?? 0, 0);

			if (isset($coreMap[$rid])) {
				foreach ($coreMap[$rid] as $file) {
					$realms[] = $file;
				}
			}
		}

		return array_values(array_unique($realms));
	}

	/**
	 * {@inheritdoc}
	 */
	public function isDeviceAllowedForUser(int $userId, int $deviceId): bool {
		return $this->checkObjectPolicy($userId, $deviceId, 3, 'policy_hosts');
	}

	/**
	 * {@inheritdoc}
	 */
	public function isGraphAllowedForUser(int $userId, int $graphId): bool {
		return $this->checkObjectPolicy($userId, $graphId, 1, 'policy_graphs');
	}

	/**
	 * {@inheritdoc}
	 */
	public function isTreeAllowedForUser(int $userId, int $treeId): bool {
		return $this->checkObjectPolicy($userId, $treeId, 2, 'policy_trees');
	}

	/**
	 * Evaluate Cacti's user_auth policy + user_auth_perms table for an object type.
	 *
	 * @param int    $userId       Cacti user ID.
	 * @param int    $itemId       Object identifier.
	 * @param int    $permType     Cacti perm type (1=graph, 2=tree, 3=host).
	 * @param string $policyColumn Allowlisted user_auth policy column name.
	 * @return bool
	 */
	private function checkObjectPolicy(int $userId, int $itemId, int $permType, string $policyColumn): bool {
		if (!in_array($policyColumn, ['policy_hosts', 'policy_graphs', 'policy_trees'], true)) {
			return false;
		}

		$policy = $this->fetchIntCell('SELECT ' . $policyColumn . ' FROM user_auth WHERE id = ?', [$userId]);
		$listed = $this->fetchIntCell(
			'SELECT COUNT(*) FROM user_auth_perms WHERE user_id = ? AND item_id = ? AND type = ?',
			[$userId, $itemId, $permType]
		) > 0;

		// Cacti policy 1 = default allow (perms table contains explicit denials); policy 2 = default deny.
		return $policy === 2 ? $listed : !$listed;
	}

	/**
	 * Fetch multiple associative rows via Cacti's prepared-statement helper.
	 *
	 * @param string                       $sql    Parameterized SQL query.
	 * @param list<string|int|float|null>  $params Bound parameters.
	 * @return list<array<string, mixed>>
	 */
	private function fetchAssoc(string $sql, array $params): array {
		$rows = db_fetch_assoc_prepared($sql, $params, true, $this->connection);

		if (!is_array($rows)) {
			return [];
		}

		$out = [];

		foreach ($rows as $row) {
			if (is_array($row)) {
				$assoc = [];

				foreach ($row as $k => $v) {
					$assoc[(string) $k] = $v;
				}
				$out[] = $assoc;
			}
		}

		return $out;
	}

	/**
	 * Fetch a single associative row via Cacti's prepared-statement helper.
	 *
	 * @param string                      $sql    Parameterized SQL query.
	 * @param list<string|int|float|null> $params Bound parameters.
	 * @return array<string, mixed>|null
	 */
	private function fetchRow(string $sql, array $params): ?array {
		$row = db_fetch_row_prepared($sql, $params, true, $this->connection);

		if (!is_array($row) || $row === []) {
			return null;
		}

		$assoc = [];

		foreach ($row as $k => $v) {
			$assoc[(string) $k] = $v;
		}

		return $assoc;
	}

	/**
	 * Fetch a single integer scalar cell via Cacti's prepared-statement helper.
	 *
	 * @param string                      $sql    Parameterized SQL query.
	 * @param list<string|int|float|null> $params Bound parameters.
	 * @return int
	 */
	private function fetchIntCell(string $sql, array $params): int {
		$val = db_fetch_cell_prepared($sql, $params, '', true, $this->connection);

		return is_numeric($val) ? (int) $val : 0;
	}

	/**
	 * Hydrate a database token row into the strict token record shape.
	 *
	 * @param array<string, mixed> $row Raw SQL row.
	 * @return array{id: string, user_id: int, name: string, token_prefix: string, token_hash: string, scopes: string, cidr_allowlist: string, created_at: int, expires_at: int|null, last_used_at: int|null, revoked_at: int|null}
	 */
	private static function hydrateToken(array $row): array {
		return [
			'id'             => self::str($row['id'] ?? ''),
			'user_id'        => self::intVal($row['user_id'] ?? 0, 0),
			'name'           => self::str($row['name'] ?? ''),
			'token_prefix'   => self::str($row['token_prefix'] ?? ''),
			'token_hash'     => self::str($row['token_hash'] ?? ''),
			'scopes'         => self::str($row['scopes'] ?? 'read'),
			'cidr_allowlist' => self::str($row['cidr_allowlist'] ?? ''),
			'created_at'     => self::intVal($row['created_at'] ?? 0, 0),
			'expires_at'     => is_numeric($row['expires_at'] ?? null) ? (int) $row['expires_at'] : null,
			'last_used_at'   => is_numeric($row['last_used_at'] ?? null) ? (int) $row['last_used_at'] : null,
			'revoked_at'     => is_numeric($row['revoked_at'] ?? null) ? (int) $row['revoked_at'] : null,
		];
	}

	/**
	 * Hydrate a database operation row into the strict operation record shape.
	 *
	 * @param array<string, mixed> $row Raw SQL row.
	 * @return array{id: string, user_id: int, operation_type: string, target_resource: string, done: bool, cancelled: bool, metadata: array<string, mixed>, response: array<string, mixed>|null, error: array<string, mixed>|null, created_at: int, updated_at: int}
	 */
	private static function hydrateOperation(array $row): array {
		return [
			'id'              => self::str($row['id'] ?? ''),
			'user_id'         => self::intVal($row['user_id'] ?? 0, 0),
			'operation_type'  => self::str($row['operation_type'] ?? ''),
			'target_resource' => self::str($row['target_resource'] ?? ''),
			'done'            => self::intVal($row['done'] ?? 0, 0) === 1,
			'cancelled'       => self::intVal($row['cancelled'] ?? 0, 0) === 1,
			'metadata'        => self::decodeJsonMap($row['metadata_json'] ?? null) ?? [],
			'response'        => self::decodeJsonMap($row['response_json'] ?? null),
			'error'           => self::decodeJsonMap($row['error_json'] ?? null),
			'created_at'      => self::intVal($row['created_at'] ?? 0, 0),
			'updated_at'      => self::intVal($row['updated_at'] ?? 0, 0),
		];
	}

	/**
	 * Safely decode a nullable JSON string into a string-keyed map.
	 *
	 * @param mixed $raw Raw JSON string or null.
	 * @return array<string, mixed>|null
	 */
	private static function decodeJsonMap(mixed $raw): ?array {
		if (!is_string($raw) || $raw === '') {
			return null;
		}

		try {
			$decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return null;
		}

		if (!is_array($decoded)) {
			return null;
		}

		$out = [];

		foreach ($decoded as $k => $v) {
			$out[(string) $k] = $v;
		}

		return $out;
	}

	/**
	 * Convert a mixed scalar value safely to string.
	 *
	 * @param mixed $val Raw value.
	 * @return string
	 */
	private static function str(mixed $val): string {
		return is_scalar($val) ? (string) $val : '';
	}

	/**
	 * Convert a mixed numeric value safely to integer.
	 *
	 * @param mixed $val     Raw value.
	 * @param int   $default Default when non-numeric.
	 * @return int
	 */
	private static function intVal(mixed $val, int $default): int {
		return is_numeric($val) ? (int) $val : $default;
	}
}
