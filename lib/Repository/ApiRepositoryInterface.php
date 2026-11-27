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

/**
 * Storage boundary for Cacti core resources, Application Passwords, LROs,
 * idempotency records, rate limits, and RBAC/object ACL lookups.
 */
interface ApiRepositoryInterface {
	/**
	 * List Cacti sites matching an optional filter and sort directives.
	 *
	 * @param FilterNode|null                                                     $filter     Parsed AIP-160 filter AST.
	 * @param list<array{field: string, column: string, direction: 'ASC'|'DESC'}> $orderBy    Parsed sort directives.
	 * @return list<array<string, mixed>>
	 */
	public function listSites(?FilterNode $filter = null, array $orderBy = []): array;

	/**
	 * Fetch a single site by numeric ID.
	 *
	 * @param int $id Site identifier.
	 * @return array<string, mixed>|null
	 */
	public function getSite(int $id): ?array;

	/**
	 * Create a new site and return the created row.
	 *
	 * @param array<string, mixed> $data Validated site attributes.
	 * @return array<string, mixed>
	 */
	public function createSite(array $data): array;

	/**
	 * Update mutable attributes on an existing site and return the updated row.
	 *
	 * @param int                  $id   Site identifier.
	 * @param array<string, mixed> $data Validated mutable site attributes.
	 * @return array<string, mixed>|null
	 */
	public function updateSite(int $id, array $data): ?array;

	/**
	 * Delete a site by numeric ID.
	 *
	 * @param int $id Site identifier.
	 * @return bool True if deleted, false if not found.
	 */
	public function deleteSite(int $id): bool;

	/**
	 * List Cacti pollers matching an optional filter and sort directives.
	 *
	 * @param FilterNode|null                                                     $filter  Parsed AIP-160 filter AST.
	 * @param list<array{field: string, column: string, direction: 'ASC'|'DESC'}> $orderBy Parsed sort directives.
	 * @return list<array<string, mixed>>
	 */
	public function listPollers(?FilterNode $filter = null, array $orderBy = []): array;

	/**
	 * Fetch a single poller by numeric ID.
	 *
	 * @param int $id Poller identifier.
	 * @return array<string, mixed>|null
	 */
	public function getPoller(int $id): ?array;

	/**
	 * List Cacti devices (host rows) matching an optional filter and sort directives.
	 *
	 * @param FilterNode|null                                                     $filter  Parsed AIP-160 filter AST.
	 * @param list<array{field: string, column: string, direction: 'ASC'|'DESC'}> $orderBy Parsed sort directives.
	 * @return list<array<string, mixed>>
	 */
	public function listDevices(?FilterNode $filter = null, array $orderBy = []): array;

	/**
	 * Fetch a single device by numeric ID.
	 *
	 * @param int $id Device identifier.
	 * @return array<string, mixed>|null
	 */
	public function getDevice(int $id): ?array;

	/**
	 * Create a new device and return the created row.
	 *
	 * @param array<string, mixed> $data Validated device attributes.
	 * @return array<string, mixed>
	 */
	public function createDevice(array $data): array;

	/**
	 * Update mutable attributes on an existing device and return the updated row.
	 *
	 * @param int                  $id   Device identifier.
	 * @param array<string, mixed> $data Validated mutable device attributes.
	 * @return array<string, mixed>|null
	 */
	public function updateDevice(int $id, array $data): ?array;

	/**
	 * Delete a device by numeric ID.
	 *
	 * @param int $id Device identifier.
	 * @return bool
	 */
	public function deleteDevice(int $id): bool;

	/**
	 * Enable or disable a device.
	 *
	 * @param int  $id       Device identifier.
	 * @param bool $disabled True to disable, false to enable.
	 * @return array<string, mixed>|null
	 */
	public function setDeviceDisabled(int $id, bool $disabled): ?array;

	/**
	 * List graphs, optionally scoped to a parent device ID.
	 *
	 * @param int|null                                                            $deviceId Optional parent device ID.
	 * @param FilterNode|null                                                     $filter   Parsed AIP-160 filter AST.
	 * @param list<array{field: string, column: string, direction: 'ASC'|'DESC'}> $orderBy  Parsed sort directives.
	 * @return list<array<string, mixed>>
	 */
	public function listGraphs(?int $deviceId = null, ?FilterNode $filter = null, array $orderBy = []): array;

	/**
	 * Fetch a single graph by ID, optionally enforcing parent device ownership.
	 *
	 * @param int      $id       Local graph ID.
	 * @param int|null $deviceId Optional parent device ID.
	 * @return array<string, mixed>|null
	 */
	public function getGraph(int $id, ?int $deviceId = null): ?array;

	/**
	 * List Cacti data sources matching optional filter and sort directives.
	 *
	 * @param FilterNode|null                                                     $filter  Parsed AIP-160 filter AST.
	 * @param list<array{field: string, column: string, direction: 'ASC'|'DESC'}> $orderBy Parsed sort directives.
	 * @return list<array<string, mixed>>
	 */
	public function listDataSources(?FilterNode $filter = null, array $orderBy = []): array;

	/**
	 * Fetch a single data source by local_data_id.
	 *
	 * @param int $id Local data ID.
	 * @return array<string, mixed>|null
	 */
	public function getDataSource(int $id): ?array;

	/**
	 * List Cacti graph trees matching optional filter and sort directives.
	 *
	 * @param FilterNode|null                                                     $filter  Parsed AIP-160 filter AST.
	 * @param list<array{field: string, column: string, direction: 'ASC'|'DESC'}> $orderBy Parsed sort directives.
	 * @return list<array<string, mixed>>
	 */
	public function listTrees(?FilterNode $filter = null, array $orderBy = []): array;

	/**
	 * Fetch a single graph tree by ID.
	 *
	 * @param int $id Graph tree ID.
	 * @return array<string, mixed>|null
	 */
	public function getTree(int $id): ?array;

	/**
	 * List installed Cacti plugins matching optional filter and sort directives.
	 *
	 * @param FilterNode|null                                                     $filter  Parsed AIP-160 filter AST.
	 * @param list<array{field: string, column: string, direction: 'ASC'|'DESC'}> $orderBy Parsed sort directives.
	 * @return list<array<string, mixed>>
	 */
	public function listPlugins(?FilterNode $filter = null, array $orderBy = []): array;

	/**
	 * Fetch a single installed plugin by directory slug.
	 *
	 * @param string $directory Plugin directory slug.
	 * @return array<string, mixed>|null
	 */
	public function getPlugin(string $directory): ?array;

	/**
	 * Persist a new Application Password token row.
	 *
	 * @param array{id: string, user_id: int, name: string, token_prefix: string, token_hash: string, scopes: string, cidr_allowlist: string, created_at: int, expires_at: int|null, last_used_at: int|null, revoked_at: int|null} $record Token record.
	 * @return void
	 */
	public function createToken(array $record): void;

	/**
	 * Find all stored tokens matching a given 8-hex prefix.
	 *
	 * @param string $prefix 8-character hex token prefix.
	 * @return list<array{id: string, user_id: int, name: string, token_prefix: string, token_hash: string, scopes: string, cidr_allowlist: string, created_at: int, expires_at: int|null, last_used_at: int|null, revoked_at: int|null}>
	 */
	public function findTokensByPrefix(string $prefix): array;

	/**
	 * Fetch a single stored token row by UUID.
	 *
	 * @param string $id Token UUID.
	 * @return array{id: string, user_id: int, name: string, token_prefix: string, token_hash: string, scopes: string, cidr_allowlist: string, created_at: int, expires_at: int|null, last_used_at: int|null, revoked_at: int|null}|null
	 */
	public function findTokenById(string $id): ?array;

	/**
	 * List stored tokens, optionally scoped to a single owner user ID.
	 *
	 * @param int|null $userId Owner user ID or null for all users.
	 * @return list<array{id: string, user_id: int, name: string, token_prefix: string, token_hash: string, scopes: string, cidr_allowlist: string, created_at: int, expires_at: int|null, last_used_at: int|null, revoked_at: int|null}>
	 */
	public function listTokens(?int $userId = null): array;

	/**
	 * Update last_used_at timestamp for a verified token.
	 *
	 * @param string $id  Token UUID.
	 * @param int    $now Current Unix timestamp.
	 * @return void
	 */
	public function touchTokenLastUsed(string $id, int $now): void;

	/**
	 * Mark a token as revoked at the given timestamp.
	 *
	 * @param string $id  Token UUID.
	 * @param int    $now Revocation Unix timestamp.
	 * @return void
	 */
	public function revokeToken(string $id, int $now): void;

	/**
	 * Permanently delete a token row by UUID.
	 *
	 * @param string $id Token UUID.
	 * @return void
	 */
	public function deleteToken(string $id): void;

	/**
	 * Create a Long-Running Operation row.
	 *
	 * @param array{id: string, user_id: int, operation_type: string, target_resource: string, done: bool, cancelled: bool, metadata: array<string, mixed>, response: array<string, mixed>|null, error: array<string, mixed>|null, created_at: int, updated_at: int} $record Operation state.
	 * @return void
	 */
	public function createOperation(array $record): void;

	/**
	 * Fetch a single Long-Running Operation row by UUID.
	 *
	 * @param string $id Operation UUID.
	 * @return array{id: string, user_id: int, operation_type: string, target_resource: string, done: bool, cancelled: bool, metadata: array<string, mixed>, response: array<string, mixed>|null, error: array<string, mixed>|null, created_at: int, updated_at: int}|null
	 */
	public function getOperation(string $id): ?array;

	/**
	 * List Long-Running Operations, optionally scoped to an owner user ID.
	 *
	 * @param int|null $userId Owner user ID or null for all users.
	 * @return list<array{id: string, user_id: int, operation_type: string, target_resource: string, done: bool, cancelled: bool, metadata: array<string, mixed>, response: array<string, mixed>|null, error: array<string, mixed>|null, created_at: int, updated_at: int}>
	 */
	public function listOperations(?int $userId = null): array;

	/**
	 * Update an existing Long-Running Operation row.
	 *
	 * @param array{id: string, user_id: int, operation_type: string, target_resource: string, done: bool, cancelled: bool, metadata: array<string, mixed>, response: array<string, mixed>|null, error: array<string, mixed>|null, created_at: int, updated_at: int} $record Updated operation state.
	 * @return void
	 */
	public function updateOperation(array $record): void;

	/**
	 * Delete a Long-Running Operation row.
	 *
	 * @param string $id Operation UUID.
	 * @return void
	 */
	public function deleteOperation(string $id): void;

	/**
	 * Retrieve a non-expired idempotency cache record.
	 *
	 * @param string $scopedKey User-scoped request_id key.
	 * @param int    $now       Current Unix timestamp.
	 * @return array{request_id: string, user_id: int, method: string, path: string, request_hash: string, status_code: int, response_json: string, created_at: int, expires_at: int}|null
	 */
	public function getIdempotencyRecord(string $scopedKey, int $now): ?array;

	/**
	 * Persist an idempotency cache record.
	 *
	 * @param string $scopedKey    User-scoped request_id key.
	 * @param int    $userId       Owner user ID.
	 * @param string $method       HTTP method.
	 * @param string $path         Normalized API path.
	 * @param string $requestHash  SHA-256 request fingerprint.
	 * @param int    $statusCode   HTTP status code.
	 * @param string $responseJson Serialized JSON response body.
	 * @param int    $createdAt    Creation Unix timestamp.
	 * @param int    $expiresAt    Expiration Unix timestamp.
	 * @return void
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
	): void;

	/**
	 * Atomically increment a fixed-window rate-limit counter and return the new count.
	 *
	 * @param string $bucketKey   SHA-256 bucket key.
	 * @param int    $windowStart Unix timestamp of window boundary.
	 * @return int New hit count in this window.
	 */
	public function incrementRateLimitBucket(string $bucketKey, int $windowStart): int;

	/**
	 * Return operational counts for diagnostics and health checks.
	 *
	 * @param int $now Current Unix timestamp.
	 * @return array{active_tokens: int, expired_tokens: int, revoked_tokens: int, pending_operations: int, stuck_operations: int, rate_limit_rows: int}
	 */
	public function getHealthStats(int $now): array;

	/**
	 * Fetch basic Cacti user account status by ID.
	 *
	 * @param int $userId Cacti user_auth.id.
	 * @return array{id: int, username: string, enabled: bool, locked: bool}|null
	 */
	public function getUserById(int $userId): ?array;

	/**
	 * Return all Cacti realm filenames granted to a user.
	 *
	 * @param int $userId Cacti user_auth.id.
	 * @return list<string>
	 */
	public function getUserRealms(int $userId): array;

	/**
	 * Check Cacti per-object host ACL for a user.
	 *
	 * @param int $userId   Cacti user_auth.id.
	 * @param int $deviceId Cacti host.id.
	 * @return bool
	 */
	public function isDeviceAllowedForUser(int $userId, int $deviceId): bool;

	/**
	 * Check Cacti per-object graph ACL for a user.
	 *
	 * @param int $userId  Cacti user_auth.id.
	 * @param int $graphId Cacti graph_local.id.
	 * @return bool
	 */
	public function isGraphAllowedForUser(int $userId, int $graphId): bool;

	/**
	 * Check Cacti per-object graph tree ACL for a user.
	 *
	 * @param int $userId Cacti user_auth.id.
	 * @param int $treeId Cacti graph_tree.id.
	 * @return bool
	 */
	public function isTreeAllowedForUser(int $userId, int $treeId): bool;
}
