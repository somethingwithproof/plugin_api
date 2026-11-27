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

namespace Somethingwithproof\Api\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Somethingwithproof\Api\Aip\ErrorStatus;
use Somethingwithproof\Api\Auth\TokenService;
use Somethingwithproof\Api\Http\Request;
use Somethingwithproof\Api\Pages;
use Somethingwithproof\Api\Repository\CactiApiRepository;
use Somethingwithproof\Api\Schema;

/**
 * Live MariaDB 11.8 + Cacti 1.2 integration tests verifying schema installation,
 * hook/realm registration, Application Passwords, AIP-154 ETags, AIP-155
 * idempotent replay, AIP-151 Long-Running Operations, and OpenAPI 3.1.
 */
final class MariaDbIntegrationTest extends TestCase {
	/**
	 * Verify schema, hooks, realms, and full REST API workflows against live MariaDB.
	 *
	 * @return void
	 */
	public function testSchemaHooksRealmsAndEndToEndApiFlow(): void {
		$schema = new Schema(false);
		$this->assertTrue($schema->isCurrent());
		$this->assertTrue($schema->hooksRegistered());

		$realmCount = (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_realms WHERE plugin = ?', ['api']);
		$this->assertSame(count(Pages::realms()), $realmCount);

		foreach (array_keys(Schema::tables()) as $table) {
			$exists = (int) db_fetch_cell_prepared(
				'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
				[$table]
			);
			$this->assertSame(1, $exists, sprintf('Expected table %s to exist in MariaDB', $table));
		}

		// Issue an Application Password for Cacti admin user (id=1).
		$repo    = new CactiApiRepository(false);
		$tokens  = new TokenService($repo);
		$issued  = $tokens->create(1, 'ci-integration-pat', ['read', 'write', 'admin'], time() + 3600, [], time());
		$tokenId = (string) $issued['resource']['id'];
		$auth    = ['Authorization' => 'Bearer ' . $issued['token'], 'Content-Type' => 'application/json'];
		$router  = api_router();

		// OpenAPI 3.1 discovery works over live router.
		$specResp = $router->handle(new Request('GET', '/v1/openapi.json', [], $auth));
		$this->assertSame(200, $specResp->statusCode);
		$this->assertSame('3.1.0', $specResp->body['openapi'] ?? null);

		// Create a site with AIP-155 request_id idempotency key and replay it twice.
		$reqId   = '770e8400-e29b-41d4-a716-446655440099';
		$payload = json_encode(['display_name' => 'CI Integration Site', 'city' => 'San Jose', 'country' => 'US'], JSON_THROW_ON_ERROR);

		$create1 = $router->handle(new Request('POST', '/v1/sites', ['request_id' => $reqId], $auth, $payload));
		$this->assertSame(201, $create1->statusCode);
		$siteId   = (int) ($create1->body['id'] ?? 0);
		$siteEtag = (string) ($create1->headers['ETag'] ?? '');
		$this->assertGreaterThan(0, $siteId);

		$create2 = $router->handle(new Request('POST', '/v1/sites', ['request_id' => $reqId], $auth, $payload));
		$this->assertSame(201, $create2->statusCode);
		$this->assertSame('true', $create2->headers['X-Idempotent-Replay'] ?? null);
		$this->assertSame($siteId, $create2->body['id'] ?? 0);

		$siteDbCount = (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM sites WHERE name = ?', ['CI Integration Site']);
		$this->assertSame(1, $siteDbCount);

		// Stale ETag update fails with 409 ABORTED; matching ETag succeeds.
		$stalePatch = $router->handle(new Request(
			'PATCH',
			'/v1/sites/' . $siteId,
			['update_mask' => 'city'],
			$auth + ['If-Match' => '"stale00000000"'],
			json_encode(['city' => 'Oakland'], JSON_THROW_ON_ERROR)
		));
		$this->assertSame(409, $stalePatch->statusCode);
		$this->assertSame(ErrorStatus::ABORTED, $stalePatch->body['error']['status'] ?? null);

		$goodPatch = $router->handle(new Request(
			'PATCH',
			'/v1/sites/' . $siteId,
			['update_mask' => 'city'],
			$auth + ['If-Match' => $siteEtag],
			json_encode(['city' => 'Oakland'], JSON_THROW_ON_ERROR)
		));
		$this->assertSame(200, $goodPatch->statusCode);
		$this->assertSame('Oakland', $goodPatch->body['city'] ?? null);

		// Create a device, queue an AIP-151 :poll LRO, and cancel it in MariaDB.
		$devCreate = $router->handle(new Request(
			'POST',
			'/v1/devices',
			[],
			$auth,
			json_encode(['description' => 'CI Switch', 'hostname' => '127.0.0.1', 'site_id' => $siteId], JSON_THROW_ON_ERROR)
		));
		$this->assertSame(201, $devCreate->statusCode);
		$devId = (int) ($devCreate->body['id'] ?? 0);

		$pollOp = $router->handle(new Request('POST', '/v1/devices/' . $devId . ':poll', [], $auth));
		$this->assertSame(202, $pollOp->statusCode);
		$opId = (string) ($pollOp->body['id'] ?? '');

		$cancelOp = $router->handle(new Request('POST', '/v1/operations/' . $opId . ':cancel', [], $auth));
		$this->assertSame(200, $cancelOp->statusCode);
		$this->assertTrue($cancelOp->body['done'] ?? false);

		// Verify self-graphing script-server telemetry and redacted doctor_register_debug_info on live MariaDB.
		$called_by_script_server = true;
		require_once dirname(__DIR__, 2) . '/scripts/ss_api_stats.php';
		$metricsLine = ss_api_stats();
		$this->assertStringContainsString('active_tokens:1', $metricsLine);
		$this->assertStringContainsString('idempotency_keys:1', $metricsLine);
		$this->assertStringContainsString('registered_collections:', $metricsLine);
		$this->assertStringNotContainsString('cacti_pat_', $metricsLine);

		$debugInfo = api_doctor_register_debug_info([]);
		$this->assertIsArray($debugInfo);
		$this->assertTrue($debugInfo['api']['redacted'] ?? false);
		$this->assertSame(1, $debugInfo['api']['metrics']['active_tokens'] ?? 0);
		$debugJson = json_encode($debugInfo, JSON_THROW_ON_ERROR);
		$this->assertStringNotContainsString($issued['token'], $debugJson);

		// Clean up created records and revoke token; verify revoked token returns 401.
		$this->assertSame(204, $router->handle(new Request('DELETE', '/v1/devices/' . $devId, [], $auth))->statusCode);
		$this->assertSame(204, $router->handle(new Request('DELETE', '/v1/sites/' . $siteId, [], $auth))->statusCode);
		$this->assertSame(200, $router->handle(new Request('POST', '/v1/tokens/' . $tokenId . ':revoke', [], $auth))->statusCode);
		$this->assertSame(401, $router->handle(new Request('GET', '/v1/sites', [], $auth))->statusCode);
	}
}
