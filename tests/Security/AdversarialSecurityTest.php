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

namespace Somethingwithproof\Api\Tests\Security;

use PHPUnit\Framework\TestCase;
use Somethingwithproof\Api\Aip\ErrorStatus;
use Somethingwithproof\Api\Auth\TokenService;
use Somethingwithproof\Api\Extension\ResourceRegistry;
use Somethingwithproof\Api\Http\Request;
use Somethingwithproof\Api\Http\Router;
use Somethingwithproof\Api\Repository\InMemoryApiRepository;
use Somethingwithproof\Api\Settings;

/**
 * Adversarial security test suite covering SQL injection in AIP-160/132,
 * mass assignment bypassing update_mask, optimistic concurrency conflicts,
 * idempotency tampering, privilege escalation, IDOR across Cacti realms and
 * object ACLs, session CSRF enforcement, oversized payloads, and rate limits.
 */
final class AdversarialSecurityTest extends TestCase {
	/**
	 * Verify SQL injection attempts in AIP-160 filter and AIP-132 order_by fail closed.
	 *
	 * @return void
	 */
	public function testSqlInjectionInFilterAndOrderByBlocked(): void {
		$repo   = InMemoryApiRepository::withDefaultFixtures();
		$admin  = (new TokenService($repo))->create(1, 'sec', ['read', 'write', 'admin'], null, [], 1700000000);
		$auth   = ['Authorization' => 'Bearer ' . $admin['token']];
		$router = new Router($repo, new Settings(), new ResourceRegistry(), 'secret-key-0123456789abcdef01234567');

		$payloads = [
			'hostname = "10.0.0.1"; DROP TABLE host; --',
			'snmp_community = "public"',
			'snmp_password : "secret"',
			'1 = 1 OR description = "x"',
			str_repeat('(', 25) . 'id = 1' . str_repeat(')', 25),
		];

		foreach ($payloads as $filter) {
			$resp = $router->handle(new Request('GET', '/v1/devices', ['filter' => $filter], $auth), 1700000010);
			$this->assertSame(400, $resp->statusCode, 'Filter payload must fail closed: ' . $filter);
			$this->assertSame(ErrorStatus::INVALID_ARGUMENT, $resp->body['error']['status'] ?? null);
		}

		$orderResp = $router->handle(new Request('GET', '/v1/devices', ['order_by' => 'id; DROP TABLE host'], $auth), 1700000011);
		$this->assertSame(400, $orderResp->statusCode);
	}

	/**
	 * Verify mass-assignment fields outside update_mask are ignored and immutable fields are rejected.
	 *
	 * @return void
	 */
	public function testMassAssignmentBypassingUpdateMaskBlocked(): void {
		$repo   = InMemoryApiRepository::withDefaultFixtures();
		$admin  = (new TokenService($repo))->create(1, 'sec', ['read', 'write', 'admin'], null, [], 1700000000);
		$auth   = ['Authorization' => 'Bearer ' . $admin['token'], 'Content-Type' => 'application/json'];
		$router = new Router($repo, new Settings(), new ResourceRegistry(), 'secret-key-0123456789abcdef01234567');

		// Immutable field in update_mask fails with 400 INVALID_ARGUMENT + BadRequest fieldViolations.
		$immResp = $router->handle(new Request(
			'PATCH',
			'/v1/devices/1',
			['update_mask' => 'id,status'],
			$auth,
			json_encode(['id' => 999, 'status' => 99], JSON_THROW_ON_ERROR)
		), 1700000010);
		$this->assertSame(400, $immResp->statusCode);
		$this->assertSame(ErrorStatus::INVALID_ARGUMENT, $immResp->body['error']['status'] ?? null);

		// Extra fields not listed in update_mask must be silently dropped (not mutated).
		$patchResp = $router->handle(new Request(
			'PATCH',
			'/v1/devices/1',
			['update_mask' => 'location'],
			$auth,
			json_encode(['location' => 'Rack B2', 'hostname' => 'evil.attacker.net', 'poller_id' => 99], JSON_THROW_ON_ERROR)
		), 1700000011);
		$this->assertSame(200, $patchResp->statusCode);
		$this->assertSame('Rack B2', $patchResp->body['location']);
		$this->assertSame('10.0.0.1', $patchResp->body['hostname']);
		$this->assertSame(1, $patchResp->body['poller_id']);
	}

	/**
	 * Verify stale ETag returns 409 ABORTED and prevents lost updates on PATCH and DELETE.
	 *
	 * @return void
	 */
	public function testStaleEtagReturns409Aborted(): void {
		$repo   = InMemoryApiRepository::withDefaultFixtures();
		$admin  = (new TokenService($repo))->create(1, 'sec', ['read', 'write', 'admin'], null, [], 1700000000);
		$auth   = ['Authorization' => 'Bearer ' . $admin['token'], 'Content-Type' => 'application/json'];
		$router = new Router($repo, new Settings(), new ResourceRegistry(), 'secret-key-0123456789abcdef01234567');

		$patch = $router->handle(new Request(
			'PATCH',
			'/v1/devices/1',
			['update_mask' => 'location'],
			$auth + ['If-Match' => '"stale000000000000"'],
			json_encode(['location' => 'Rack Z'], JSON_THROW_ON_ERROR)
		), 1700000010);
		$this->assertSame(409, $patch->statusCode);
		$this->assertSame(ErrorStatus::ABORTED, $patch->body['error']['status'] ?? null);

		$del = $router->handle(new Request('DELETE', '/v1/devices/1', [], $auth + ['If-Match' => '"stale000000000000"']), 1700000011);
		$this->assertSame(409, $del->statusCode);
		$this->assertNotNull($repo->getDevice(1));
	}

	/**
	 * Verify RBAC realm enforcement, Cacti per-object ACL isolation, and token scope escalation prevention.
	 *
	 * @return void
	 */
	public function testRbacObjectAclIdorAndPrivilegeEscalationBlocked(): void {
		$repo                   = InMemoryApiRepository::withDefaultFixtures();
		$repo->deniedDevices[2] = [1];
		$repo->deniedGraphs[2]  = [10];
		$repo->deniedTrees[2]   = [1];

		$tokens = new TokenService($repo);
		$admin  = $tokens->create(1, 'admin-secret', ['read', 'write', 'admin'], null, [], 1700000000);
		$viewer = $tokens->create(2, 'viewer-read', ['read'], null, [], 1700000000);
		$vWrite = $tokens->create(2, 'viewer-write', ['read', 'write'], null, [], 1700000000);
		$router = new Router($repo, new Settings(), new ResourceRegistry(), 'secret-key-0123456789abcdef01234567');

		// User 2 lacks sites.php realm -> 403 PERMISSION_DENIED.
		$sitesResp = $router->handle(new Request('GET', '/v1/sites', [], ['Authorization' => 'Bearer ' . $viewer['token']]), 1700000010);
		$this->assertSame(403, $sitesResp->statusCode);

		// User 2 has host.php realm, but object ACL denies device 1 -> hidden in list, 403 on direct get.
		$devList = $router->handle(new Request('GET', '/v1/devices', [], ['Authorization' => 'Bearer ' . $viewer['token']]), 1700000011);
		$this->assertSame(200, $devList->statusCode);
		$this->assertSame(0, $devList->body['totalSize']);

		$devGet = $router->handle(new Request('GET', '/v1/devices/1', [], ['Authorization' => 'Bearer ' . $viewer['token']]), 1700000012);
		$this->assertSame(403, $devGet->statusCode);

		// User 2 cannot access denied graph or tree directly.
		$this->assertSame(403, $router->handle(new Request('GET', '/v1/graphs/10', [], ['Authorization' => 'Bearer ' . $viewer['token']]), 1700000013)->statusCode);
		$this->assertSame(403, $router->handle(new Request('GET', '/v1/trees/1', [], ['Authorization' => 'Bearer ' . $viewer['token']]), 1700000014)->statusCode);

		// User 2 cannot view or revoke User 1's token (IDOR blocked).
		$adminTokenId = (string) $admin['resource']['id'];
		$this->assertSame(403, $router->handle(new Request('GET', '/v1/tokens/' . $adminTokenId, [], ['Authorization' => 'Bearer ' . $vWrite['token']]), 1700000015)->statusCode);
		$this->assertSame(403, $router->handle(new Request('POST', '/v1/tokens/' . $adminTokenId . ':revoke', [], ['Authorization' => 'Bearer ' . $vWrite['token']]), 1700000016)->statusCode);

		// User 2 with write scope cannot mint an admin-scoped token (privilege escalation blocked).
		$esc = $router->handle(new Request(
			'POST',
			'/v1/tokens',
			[],
			['Authorization' => 'Bearer ' . $vWrite['token'], 'Content-Type' => 'application/json'],
			json_encode(['display_name' => 'Escalate', 'scopes' => ['admin']], JSON_THROW_ON_ERROR)
		), 1700000017);
		$this->assertSame(403, $esc->statusCode);

		// Read-only token cannot perform write mutations.
		$roWrite = $router->handle(new Request(
			'POST',
			'/v1/devices',
			[],
			['Authorization' => 'Bearer ' . $viewer['token'], 'Content-Type' => 'application/json'],
			json_encode(['description' => 'Unauthorized', 'hostname' => '10.9.9.9'], JSON_THROW_ON_ERROR)
		), 1700000018);
		$this->assertSame(403, $roWrite->statusCode);
	}

	/**
	 * Verify CSRF protection on session-authenticated writes, oversized body rejection,
	 * strict Content-Type enforcement, and IP brute-force rate limiting.
	 *
	 * @return void
	 */
	public function testSessionCsrfBodyLimitsContentTypeAndBruteForceRateLimit(): void {
		$repo     = InMemoryApiRepository::withDefaultFixtures();
		$settings = new Settings(rateLimitPerMin: 2, maxBodyBytes: 128);
		$router   = new Router(
			$repo,
			$settings,
			new ResourceRegistry(),
			'secret-key-0123456789abcdef01234567',
			'0.1.0',
			static fn (string $token): bool => hash_equals('valid-csrf-token', $token)
		);

		// Session-authenticated write without CSRF token fails with 403.
		$noCsrf = $router->handle(new Request(
			method: 'POST',
			path: '/v1/sites',
			headers: ['Content-Type' => 'application/json'],
			rawBody: json_encode(['display_name' => 'CSRF Site'], JSON_THROW_ON_ERROR),
			sessionUserId: 1
		), 1700000010);
		$this->assertSame(403, $noCsrf->statusCode);

		// Session-authenticated write with wrong Content-Type fails with 400.
		$badCt = $router->handle(new Request(
			method: 'POST',
			path: '/v1/sites',
			headers: ['Content-Type' => 'text/plain', 'X-CSRF-Token' => 'valid-csrf-token'],
			rawBody: '{"display_name":"Text"}',
			sessionUserId: 1
		), 1700000011);
		$this->assertSame(400, $badCt->statusCode);

		// Oversized body (> 128 bytes) fails with 400.
		$tooBig = $router->handle(new Request(
			method: 'POST',
			path: '/v1/sites',
			headers: ['Content-Type' => 'application/json', 'X-CSRF-Token' => 'valid-csrf-token'],
			rawBody: json_encode(['display_name' => str_repeat('A', 200)], JSON_THROW_ON_ERROR),
			sessionUserId: 1
		), 1700000012);
		$this->assertSame(400, $tooBig->statusCode);

		// Repeated invalid bearer tokens from the same IP trigger 429 RESOURCE_EXHAUSTED.
		$badTok = 'cacti_pat_deadbeef_' . str_repeat('0', 48);
		$this->assertSame(401, $router->handle(new Request('GET', '/v1/sites', [], ['Authorization' => 'Bearer ' . $badTok], '', '203.0.113.10'), 1700000020)->statusCode);
		$this->assertSame(401, $router->handle(new Request('GET', '/v1/sites', [], ['Authorization' => 'Bearer ' . $badTok], '', '203.0.113.10'), 1700000021)->statusCode);
		$rlResp = $router->handle(new Request('GET', '/v1/sites', [], ['Authorization' => 'Bearer ' . $badTok], '', '203.0.113.10'), 1700000022);
		$this->assertSame(429, $rlResp->statusCode);
		$this->assertArrayHasKey('Retry-After', $rlResp->resolvedHeaders());
	}
}
