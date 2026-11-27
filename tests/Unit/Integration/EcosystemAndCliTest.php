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

namespace Somethingwithproof\Api\Tests\Unit\Integration;

use PHPUnit\Framework\TestCase;
use Somethingwithproof\Api\Aip\OperationManager;
use Somethingwithproof\Api\Auth\TokenService;
use Somethingwithproof\Api\Cli\CliRunner;
use Somethingwithproof\Api\Extension\ExtensionResourceDescriptor;
use Somethingwithproof\Api\Extension\ResourceRegistry;
use Somethingwithproof\Api\Http\Request;
use Somethingwithproof\Api\Integration\CascProvider;
use Somethingwithproof\Api\Integration\DoctorChecks;
use Somethingwithproof\Api\Integration\McpAbilities;
use Somethingwithproof\Api\Log;
use Somethingwithproof\Api\Pages;
use Somethingwithproof\Api\Repository\InMemoryApiRepository;
use Somethingwithproof\Api\Schema;
use Somethingwithproof\Api\Settings;
use Somethingwithproof\Api\Ui\PagesRenderer;

/**
 * Unit tests for ecosystem integrations (CasC, Doctor, MCP Abilities),
 * CLI commands, Console UI renderer, Settings, Pages, Log, and setup.php helpers.
 */
final class EcosystemAndCliTest extends TestCase {
	/**
	 * Verify CascProvider exports declarative config only (zero secrets), validates, plans, and applies.
	 *
	 * @return void
	 */
	public function testCascProviderExportValidatePlanApply(): void {
		$stored   = [];
		$settings = new Settings(rateLimitPerMin: 120, corsOrigins: ['https://ops.example.com']);
		$desc     = CascProvider::descriptor(
			static fn (): Settings => $settings,
			static function (array $vals) use (&$stored): void {
				$stored = array_merge($stored, $vals);
			}
		);

		$exported = ($desc['export'])([]);
		$this->assertSame(120, $exported['api_rate_limit_per_min']);
		$this->assertArrayNotHasKey('tokens', $exported);

		$this->assertSame([], ($desc['validate'])(['api_rate_limit_per_min' => 240, 'api_require_tls' => true]));
		$badErrors = ($desc['validate'])([
			'unknown_key'                => 1,
			'api_rate_limit_per_min'     => 0,
			'api_default_token_ttl_days' => -1,
			'api_max_body_bytes'         => 100,
			'api_cors_origins'           => ['*'],
			'api_cors_allow_credentials' => true,
		]);
		$this->assertCount(5, $badErrors);

		$plan = ($desc['plan'])(['api_rate_limit_per_min' => 300, 'api_require_tls' => true], []);
		$this->assertCount(2, $plan);

		$apply = ($desc['apply'])(['api_rate_limit_per_min' => 300, 'api_require_tls' => true, 'api_cors_origins' => ['https://a.example']], 'hash123', []);
		$this->assertTrue($apply['ok']);
		$this->assertSame('300', $stored['api_rate_limit_per_min']);
		$this->assertSame('on', $stored['api_require_tls']);
		$this->assertSame('https://a.example', $stored['api_cors_origins']);

		// Register into both array registrar and object registrar cleanly.
		$arrReg = CascProvider::register([], static fn (): Settings => $settings, static function (array $v): void {
		});
		$this->assertIsArray($arrReg);
		$this->assertArrayHasKey('api', $arrReg);

		$captured = null;
		$objReg   = new class ($captured) {
			/** @var mixed */
			public mixed $captured;

			/** @param mixed $captured */
			public function __construct(mixed &$captured) {
				$this->captured = &$captured;
			}

			/** @param array<string, mixed> $d */
			public function register(array $d): void {
				$this->captured = $d;
			}
		};
		CascProvider::register($objReg, static fn (): Settings => $settings, static function (array $v): void {
		});
		$this->assertIsArray($captured);
		$this->assertSame('api', $captured['slug']);
	}

	/**
	 * Verify DoctorChecks report both healthy and degraded operational states.
	 *
	 * @return void
	 */
	public function testDoctorChecksOkAndWarningStates(): void {
		$repo     = InMemoryApiRepository::withDefaultFixtures();
		$registry = new ResourceRegistry();
		$now      = 1700005000;

		// Add a stuck operation (> 1h old) to verify stuck_operations warning.
		(new OperationManager($repo))->create(1, 'device.poll', 'devices/1', [], $now - 7200);

		$checks = DoctorChecks::build(
			static fn (): InMemoryApiRepository => $repo,
			static fn (): Settings => new Settings(requireTls: false),
			static fn (): ResourceRegistry => $registry,
			static fn (): int => $now
		);
		$this->assertCount(4, $checks);

		$results = [];

		foreach ($checks as $check) {
			$results[$check['id']] = ($check['run'])();
		}

		$this->assertSame('ok', $results['api_token_hygiene']['status']);
		$this->assertSame('warning', $results['api_stuck_operations']['status']);
		$this->assertSame('ok', $results['api_rate_limit_table']['status']);
		$this->assertSame('warning', $results['api_security_posture']['status']);

		// With requireTls=true and no faults, security posture check reports ok.
		$okChecks = DoctorChecks::build(
			static fn (): InMemoryApiRepository => new InMemoryApiRepository(),
			static fn (): Settings => new Settings(requireTls: true),
			static fn (): ResourceRegistry => new ResourceRegistry(),
			static fn (): int => $now
		);
		$this->assertSame('ok', ($okChecks[3]['run'])()['status']);
	}

	/**
	 * Verify McpAbilities registers api-get-status, api-list-operations, and api-get-openapi cleanly.
	 *
	 * @return void
	 */
	public function testMcpAbilitiesRegistrationAndExecution(): void {
		$repo     = InMemoryApiRepository::withDefaultFixtures();
		$registry = new ResourceRegistry();
		(new OperationManager($repo))->create(1, 'device.poll', 'devices/1', [], 1700000000);

		$registered = [];
		$registrar  = new class ($registered) {
			/** @var array<string, array<string, mixed>> */
			public array $registered;

			/** @param array<string, array<string, mixed>> $registered */
			public function __construct(array &$registered) {
				$this->registered = &$registered;
			}

			/**
			 * @param string               $name
			 * @param array<string, mixed> $spec
			 */
			public function register(string $name, array $spec): void {
				$this->registered[$name] = $spec;
			}
		};

		McpAbilities::register(
			$registrar,
			static fn (): InMemoryApiRepository => $repo,
			static fn (): Settings => new Settings(),
			static fn (): ResourceRegistry => $registry,
			static fn (int $uid): bool => $uid === 1,
			'0.1.0'
		);

		$this->assertSame(['api-get-status', 'api-list-operations', 'api-get-openapi'], array_keys($registered));

		$status = ($registered['api-get-status']['execute'])(1, new \stdClass());
		$this->assertTrue($status['enabled']);
		$this->assertSame(1, $status['pendingOperations']);

		$ops = ($registered['api-list-operations']['execute'])(1, new \stdClass());
		$this->assertSame(1, $ops['totalSize']);

		$openapi = ($registered['api-get-openapi']['execute'])(1, new \stdClass());
		$this->assertSame('3.1.0', $openapi['openapi']);
		$this->assertGreaterThan(15, $openapi['pathCount']);
	}

	/**
	 * Verify CliRunner executes all subcommands and validates invalid arguments.
	 *
	 * @return void
	 */
	public function testCliRunnerCommands(): void {
		$repo   = InMemoryApiRepository::withDefaultFixtures();
		$runner = new CliRunner($repo, new Settings(), new ResourceRegistry(), '0.1.0');
		$now    = 1700000000;

		$help = $runner->run(['--help'], $now);
		$this->assertSame(0, $help['exitCode']);
		$this->assertStringContainsString('token-create', $help['stdout']);

		$health = $runner->run(['health'], $now);
		$this->assertSame(0, $health['exitCode']);
		$this->assertStringContainsString('"status": "ok"', $health['stdout']);

		$spec = $runner->run(['openapi'], $now);
		$this->assertSame(0, $spec['exitCode']);
		$this->assertStringContainsString('"openapi": "3.1.0"', $spec['stdout']);

		$created = $runner->run(['token-create', '--user-id=1', '--name=cli-bot', '--scopes=read,write', '--ttl-days=30'], $now);
		$this->assertSame(0, $created['exitCode']);
		$decoded = json_decode($created['stdout'], true, 16, JSON_THROW_ON_ERROR);
		$this->assertIsArray($decoded);
		$tokenId = (string) ($decoded['id'] ?? '');
		$this->assertStringStartsWith('cacti_pat_', (string) ($decoded['plainTextToken'] ?? ''));

		$list = $runner->run(['token-list', '--user-id=1'], $now);
		$this->assertSame(0, $list['exitCode']);
		$this->assertStringContainsString($tokenId, $list['stdout']);

		$revoked = $runner->run(['token-revoke', '--id=' . $tokenId], $now + 5);
		$this->assertSame(0, $revoked['exitCode']);
		$this->assertStringContainsString('"revoked": true', $revoked['stdout']);

		$ops = $runner->run(['operations'], $now);
		$this->assertSame(0, $ops['exitCode']);

		$unknown = $runner->run(['bogus'], $now);
		$this->assertSame(2, $unknown['exitCode']);

		$badUser = $runner->run(['token-create', '--user-id=999', '--name=x'], $now);
		$this->assertSame(2, $badUser['exitCode']);
	}

	/**
	 * Verify PagesRenderer escapes untrusted content and Settings/Pages/Log/Schema/setup.php work cleanly.
	 *
	 * @return void
	 */
	public function testUiRendererSettingsPagesLogAndSetupHelpers(): void {
		$ext = new ExtensionResourceDescriptor(
			'demo',
			'demoItems',
			'demoItem',
			'Demo',
			'demo_view.php',
			'demo_admin.php',
			['list' => static fn (): array => []]
		);
		$overview = PagesRenderer::renderOverview(new Settings(), ['active_tokens' => 2, 'pending_operations' => 1], [$ext], [['plugin' => 'bad', 'reason' => '<script>x</script>']], 25);
		$this->assertStringContainsString('/v1/demoItems', $overview);
		$this->assertStringNotContainsString('<script>x</script>', $overview);
		$this->assertStringContainsString('&lt;script&gt;x&lt;/script&gt;', $overview);

		$repo = InMemoryApiRepository::withDefaultFixtures();
		$tok  = (new TokenService($repo))->create(1, '<b>XSS</b>', ['read'], null, [], 1700000000);
		$html = PagesRenderer::renderTokensTable([$tok['resource']], $tok['token'], 'csrf-123');
		$this->assertStringContainsString('&lt;b&gt;XSS&lt;/b&gt;', $html);
		$this->assertStringContainsString($tok['token'], $html);

		$op     = (new OperationManager($repo))->create(1, 'device.poll', 'devices/1', [], 1700000000);
		$opHtml = PagesRenderer::renderOperationsTable([$op], 'csrf-123');
		$this->assertStringContainsString('devices/1', $opHtml);

		$this->assertCount(3, Pages::realms());
		$this->assertCount(3, Pages::menu());
		$this->assertCount(3, Pages::navigation());
		$this->assertContains('plugins.php', Pages::upgradeTriggers());

		$settings = Settings::fromRow([
			'api_enabled'                 => 'on',
			'api_rate_limit_per_min'      => '250',
			'api_default_token_ttl_days'  => '30',
			'api_cors_origins'            => "https://a.example\nhttps://b.example",
			'api_cors_allow_credentials'  => 'on',
			'api_require_tls'             => 'on',
			'api_max_body_bytes'          => '2097152',
			'api_enable_yaml_negotiation' => 'on',
			'api_uninstall_keep_tables'   => 'on',
		]);
		$this->assertSame(250, $settings->rateLimitPerMin);
		$this->assertSame(['https://a.example', 'https://b.example'], $settings->corsOrigins);
		$this->assertTrue($settings->requireTls);
		$this->assertCount(10, Settings::form());

		$this->assertCount(4, Schema::tables());
		$this->assertTrue(api_php_supported());
		$this->assertSame('api', plugin_api_version()['name']);

		Log::info('unit info');
		Log::warning('unit warn');
		Log::error('unit err');
		$this->assertNotEmpty($GLOBALS['__api_log']);

		$this->assertSame('/v1/devices/1', Request::extractApiPath(['REQUEST_URI' => '/plugins/api/api.php/v1/devices/1?foo=bar']));
		$this->assertSame('/v1/sites', Request::extractApiPath(['REQUEST_URI' => '/api/v1/sites']));
		$this->assertSame('/v1/trees', Request::extractApiPath(['PATH_INFO' => '/v1/trees']));
	}
}
