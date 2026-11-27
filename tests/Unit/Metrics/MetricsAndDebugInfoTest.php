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

namespace Somethingwithproof\Api\Tests\Unit\Metrics;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Somethingwithproof\Api\Aip\ErrorStatus;
use Somethingwithproof\Api\Aip\IdempotencyStore;
use Somethingwithproof\Api\Aip\OperationManager;
use Somethingwithproof\Api\Auth\RateLimiter;
use Somethingwithproof\Api\Auth\TokenService;
use Somethingwithproof\Api\Extension\ExtensionResourceDescriptor;
use Somethingwithproof\Api\Extension\ResourceRegistry;
use Somethingwithproof\Api\Http\Router;
use Somethingwithproof\Api\Integration\DoctorChecks;
use Somethingwithproof\Api\Metrics\ApiMetricsCollector;
use Somethingwithproof\Api\Repository\InMemoryApiRepository;
use Somethingwithproof\Api\Schema;
use Somethingwithproof\Api\Settings;

/**
 * Unit tests for Cacti self-graphing script-server telemetry (ApiMetricsCollector,
 * scripts/ss_api_stats.php) and redacted doctor_register_debug_info snapshots.
 */
final class MetricsAndDebugInfoTest extends TestCase {
	/**
	 * Verify ApiMetricsCollector counts every state dimension accurately and emits valid Cacti field:value pairs.
	 *
	 * @return void
	 */
	public function testMetricsCollectorCountsAndCactiFormatting(): void {
		$now      = 1700005000;
		$repo     = InMemoryApiRepository::withDefaultFixtures();
		$tokens   = new TokenService($repo);
		$ops      = new OperationManager($repo);
		$idem     = new IdempotencyStore($repo);
		$rl       = new RateLimiter($repo);
		$registry = new ResourceRegistry();

		// 2 active tokens, 1 expired token (not active/not revoked), 1 revoked token.
		$tActive1 = $tokens->create(1, 'active-1', ['read'], $now + 3600, [], $now);
		$tokens->create(1, 'active-2', ['read', 'write'], null, [], $now);
		$tRevoked = $tokens->create(1, 'revoked-1', ['read'], null, [], $now);
		$tokens->revoke((string) $tRevoked['resource']['id'], 1, true, $now);
		$repo->tokens['expired-tok'] = [
			'id'             => 'expired-tok',
			'user_id'        => 1,
			'name'           => 'expired',
			'token_prefix'   => '11223344',
			'token_hash'     => str_repeat('f', 64),
			'scopes'         => 'read',
			'cidr_allowlist' => '',
			'created_at'     => $now - 7200,
			'expires_at'     => $now - 60,
			'last_used_at'   => null,
			'revoked_at'     => null,
		];

		// Operations: 1 pending, 2 running (one by updated_at > created_at, one by metadata.state=running),
		// 1 failed, 1 completed, 1 cancelled.
		$ops->create(1, 'device.poll', 'devices/1', [], $now);
		$opRunningMeta = $ops->create(1, 'device.reindex', 'devices/1', ['state' => 'running'], $now);
		$opRunningTime = $ops->create(1, 'device.poll', 'devices/2', [], $now - 30);
		$repo->operations[(string) $opRunningTime['id']]['updated_at'] = $now;

		$opFailed = $ops->create(1, 'device.poll', 'devices/3', [], $now - 100);
		$ops->fail((string) $opFailed['id'], new ErrorStatus(ErrorStatus::INTERNAL, 'Poller error'), $now);

		$opDone = $ops->create(1, 'device.poll', 'devices/4', [], $now - 200);
		$ops->complete((string) $opDone['id'], ['ok' => true], $now);

		$opCancelled = $ops->create(1, 'device.poll', 'devices/5', [], $now - 50);
		$ops->cancel((string) $opCancelled['id'], 1, true, $now);

		// 1 active idempotency key + 1 expired key (excluded).
		$idem->record('550e8400-e29b-41d4-a716-446655440001', 1, 'POST', '/v1/sites', str_repeat('a', 64), 201, ['id' => 1], $now);
		$repo->saveIdempotencyRecord('1:550e8400-e29b-41d4-a716-446655440002', 1, 'POST', '/v1/sites', str_repeat('b', 64), 201, '{}', $now - 90000, $now - 10);

		// 2 distinct rate-limit buckets.
		$rl->consume('token:bucket-a', 100, $now);
		$rl->consume('token:bucket-b', 100, $now);

		// 1 sibling extension collection on top of core collections.
		$registry->register(new ExtensionResourceDescriptor(
			'demo',
			'demoWidgets',
			'demoWidget',
			'Demo widgets',
			'demo_view.php',
			'demo_admin.php',
			['list' => static fn (): array => []]
		));

		$collector = new ApiMetricsCollector($repo, $registry);
		$snapshot  = $collector->collect($now);

		$this->assertSame(2, $snapshot['active_tokens']);
		$this->assertSame(1, $snapshot['revoked_tokens']);
		$this->assertSame(1, $snapshot['operations_pending']);
		$this->assertSame(2, $snapshot['operations_running']);
		$this->assertSame(1, $snapshot['operations_failed']);
		$this->assertSame(1, $snapshot['idempotency_keys']);
		$this->assertSame(2, $snapshot['rate_limit_buckets']);
		$this->assertSame(count(Router::CORE_COLLECTIONS) + 1, $snapshot['registered_collections']);

		$line = $collector->formatCactiOutput($now);
		$expectedLine = sprintf(
			'active_tokens:2 revoked_tokens:1 operations_pending:1 operations_running:2 operations_failed:1 idempotency_keys:1 rate_limit_buckets:2 registered_collections:%d',
			count(Router::CORE_COLLECTIONS) + 1
		);
		$this->assertSame($expectedLine, $line);

		// Output must never contain raw token secrets, prefixes, or hashes.
		$this->assertStringNotContainsString($tActive1['token'], $line);
		$this->assertStringNotContainsString('cacti_pat_', $line);
		$this->assertStringNotContainsString((string) $opRunningMeta['id'], $line);

		// Missing/invalid fields format as RRDtool unknown "U" rather than 0.
		$unknown = ApiMetricsCollector::unknownOutput();
		$this->assertSame(
			'active_tokens:U revoked_tokens:U operations_pending:U operations_running:U operations_failed:U idempotency_keys:U rate_limit_buckets:U registered_collections:U',
			$unknown
		);
		$this->assertSame($unknown, ApiMetricsCollector::formatSnapshot(['active_tokens' => -1, 'revoked_tokens' => 'bad']));
	}

	/**
	 * Verify DoctorChecks::debugInfo() returns a fully redacted diagnostic snapshot with zero secrets.
	 *
	 * @return void
	 */
	public function testDoctorDebugInfoIsRedactedAndSecretFree(): void {
		$now      = 1700005000;
		$repo     = InMemoryApiRepository::withDefaultFixtures();
		$tokens   = new TokenService($repo);
		$issued   = $tokens->create(1, 'prod-bot', ['read', 'write'], $now + 86400, ['10.0.0.0/8'], $now);
		$rawToken = $issued['token'];
		$stored   = $repo->findTokenById((string) $issued['resource']['id']);
		$this->assertNotNull($stored);

		$registry = new ResourceRegistry();
		$registry->register(new ExtensionResourceDescriptor(
			'inventory',
			'inventoryNodes',
			'inventoryNode',
			'Inventory nodes',
			'inv_view.php',
			'inv_admin.php',
			['list' => static fn (): array => []]
		));
		$registry->dispatchHooks([
			['name' => 'BAD_PLUGIN!', 'file' => 'setup.php', 'function' => static fn (): array => []],
		]);

		$snapshot = DoctorChecks::debugInfo(
			static fn (): InMemoryApiRepository => $repo,
			static fn (): Settings => new Settings(enabled: true, rateLimitPerMin: 240, requireTls: true, corsOrigins: ['https://ops.example.com']),
			static fn (): ResourceRegistry => $registry,
			'0.1.0',
			static fn (): int => $now
		);

		$this->assertSame('api', $snapshot['plugin']);
		$this->assertSame('0.1.0', $snapshot['version']);
		$this->assertSame(Schema::VERSION, $snapshot['schema_version']);
		$this->assertTrue($snapshot['redacted']);
		$this->assertSame('sha256_hashed_at_rest', $snapshot['secret_storage']);
		$this->assertSame(1, $snapshot['metrics']['active_tokens']);
		$this->assertSame(count(Router::CORE_COLLECTIONS) + 1, $snapshot['metrics']['registered_collections']);
		$this->assertContains('devices', $snapshot['collections']['core']);
		$this->assertSame(['inventoryNodes'], $snapshot['collections']['extension']);
		$this->assertSame(['inventory'], $snapshot['extensions']['plugins']);
		$this->assertSame(1, $snapshot['extensions']['fault_count']);

		// Serialize entire snapshot and assert zero leakage of token plaintext, prefix, or hash.
		$json = json_encode($snapshot, JSON_THROW_ON_ERROR);
		$this->assertStringNotContainsString($rawToken, $json);
		$this->assertStringNotContainsString($stored['token_hash'], $json);
		$this->assertStringNotContainsString($stored['token_prefix'], $json);
		$this->assertStringNotContainsString('cacti_pat_', $json);
		$this->assertStringNotContainsString('api_page_token_secret', $json);
	}

	/**
	 * Verify scripts/ss_api_stats.php works in Cacti script-server mode and emits field:U on DB failure.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	public function testScriptServerEntryPointHealthyAndErrorFallback(): void {
		$GLOBALS['__ss_throw'] = false;

		eval('
			function db_fetch_row_prepared($sql, $params = [], $log = true, $db_conn = false) {
				if ($GLOBALS["__ss_throw"]) {
					throw new \RuntimeException("Simulated MariaDB outage");
				}
				if (str_contains($sql, "plugin_api_tokens")) {
					return ["active_tokens" => 3, "expired_tokens" => 1, "revoked_tokens" => 2];
				}
				if (str_contains($sql, "plugin_api_operations")) {
					return [
						"pending_operations" => 4,
						"operations_pending" => 1,
						"operations_running" => 3,
						"operations_failed"  => 2,
						"stuck_operations"   => 0,
					];
				}
				return [];
			}
			function db_fetch_cell_prepared($sql, $params = [], $col = "", $log = true, $db_conn = false) {
				if ($GLOBALS["__ss_throw"]) {
					throw new \RuntimeException("Simulated MariaDB outage");
				}
				if (str_contains($sql, "plugin_api_idempotency")) {
					return 5;
				}
				if (str_contains($sql, "plugin_api_rate_limits")) {
					return 7;
				}
				return 0;
			}
			function db_fetch_assoc_prepared($sql, $params = [], $log = true, $db_conn = false) {
				return [];
			}
		');

		// Simulate Cacti script_server.php loading the script without executing CLI entrypoint.
		$called_by_script_server = true;
		require_once dirname(__DIR__, 3) . '/scripts/ss_api_stats.php';

		$out = ss_api_stats();
		$this->assertSame(
			sprintf(
				'active_tokens:3 revoked_tokens:2 operations_pending:1 operations_running:3 operations_failed:2 idempotency_keys:5 rate_limit_buckets:7 registered_collections:%d',
				count(Router::CORE_COLLECTIONS)
			),
			$out
		);

		// On DB failure, script-server must emit RRDtool "U" for every metric rather than 0.
		$GLOBALS['__ss_throw'] = true;
		$errOut                = ss_api_stats();
		$this->assertSame(ApiMetricsCollector::unknownOutput(), $errOut);
	}
}
