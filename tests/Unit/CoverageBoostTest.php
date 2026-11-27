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

namespace Somethingwithproof\Api\Tests\Unit;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Somethingwithproof\Api\Aip\ApiException;
use Somethingwithproof\Api\Aip\FieldMask;
use Somethingwithproof\Api\Aip\FilterParser;
use Somethingwithproof\Api\Aip\OrderByParser;
use Somethingwithproof\Api\Aip\PageToken;
use Somethingwithproof\Api\Aip\ResourceName;
use Somethingwithproof\Api\Aip\SqlFilterCompiler;
use Somethingwithproof\Api\Auth\TokenService;
use Somethingwithproof\Api\Extension\ExtensionResourceDescriptor;
use Somethingwithproof\Api\Extension\ResourceRegistry;
use Somethingwithproof\Api\Http\Request;
use Somethingwithproof\Api\Http\Response;
use Somethingwithproof\Api\Http\Router;
use Somethingwithproof\Api\Repository\InMemoryApiRepository;
use Somethingwithproof\Api\Settings;

/**
 * Targeted edge-case, error-branch, and setup.php lifecycle tests ensuring
 * thorough verification across all core AIP, HTTP, extension, and hook paths.
 */
final class CoverageBoostTest extends TestCase {
	/**
	 * Exercise setup.php Cacti lifecycle hooks, realm inheritance, and ecosystem callbacks.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	public function testSetupLifecycleHooksAndRealmInheritance(): void {
		$GLOBALS['__setup_calls']             = [];
		$GLOBALS['__db_cell']                 = 0;
		$GLOBALS['__current_page']            = 'api_view.php';
		$GLOBALS['menu']                      = [];
		$GLOBALS['tabs']                      = [];
		$GLOBALS['settings']                  = [];
		$GLOBALS['current_user']              = ['enabled' => 'on'];
		$GLOBALS['user_auth_realm_filenames'] = [
			'api_admin.php'  => 103,
			'api_tokens.php' => 102,
			'api_view.php'   => 101,
		];
		$_SESSION = ['sess_user_id' => 1];

		eval('
			function db_fetch_cell_prepared($sql, $params = [], $col = "", $log = true, $db_conn = false) {
				if (str_contains($sql, "information_schema.TABLES")) { return 1; }
				return $GLOBALS["__db_cell"];
			}
			function db_fetch_assoc_prepared($sql, $params = [], $log = true, $db_conn = false) { return []; }
			function db_execute_prepared($sql, $params = [], $log = true, $db_conn = false) {
				$GLOBALS["__setup_calls"][] = $sql;
				return true;
			}
			function api_plugin_db_table_create($plugin, $table, $data) { return true; }
			function api_plugin_register_hook($plugin, $hook, $fn, $file, $enable = false) {
				$GLOBALS["__setup_calls"][] = "hook:" . $hook;
			}
			function api_plugin_register_realm($plugin, $files, $display, $default = false) {
				$GLOBALS["__setup_calls"][] = "realm:" . $files;
			}
			function api_plugin_is_enabled($plugin) { return true; }
			function api_plugin_user_realm_auth($file) { return true; }
			function is_realm_allowed($realm, $user = 0) { return $realm === 103; }
			function get_current_page() { return $GLOBALS["__current_page"]; }
		');

		plugin_api_install();
		$this->assertTrue(plugin_api_check_config());
		$this->assertContains('hook:config_arrays', $GLOBALS['__setup_calls']);
		$this->assertContains('realm:api_admin.php', $GLOBALS['__setup_calls']);

		api_config_arrays();
		$this->assertNotEmpty($GLOBALS['menu']);

		$GLOBALS['__current_page'] = 'settings.php';
		$nav                       = api_draw_navigation_text([]);
		$this->assertArrayHasKey('api_view.php:', $nav);
		$this->assertArrayHasKey('api', $GLOBALS['tabs']);

		$GLOBALS['__current_page'] = 'api_view.php';
		$this->assertTrue(api_page_allowed());
		$this->assertTrue(api_page_start());

		// Unauthenticated session defers non-admin realms cleanly.
		$_SESSION = [];
		api_auth_alternate_realms();
		$this->assertSame(-1, $GLOBALS['user_auth_realm_filenames']['api_view.php']);
		$_SESSION = ['sess_user_id' => 1];
		api_auth_alternate_realms();

		$csrf = api_csrf_token();
		$this->assertGreaterThanOrEqual(64, strlen($csrf));
		$this->assertSame($csrf, api_csrf_token());

		$secret = api_page_token_secret();
		$this->assertGreaterThanOrEqual(64, strlen($secret));

		$router = api_router();
		$this->assertInstanceOf(Router::class, $router);

		$casc = api_casc_register_providers([]);
		$this->assertIsArray($casc);
		$this->assertArrayHasKey('api', $casc);

		$checks = api_doctor_register_checks([]);
		$this->assertIsArray($checks);
		$this->assertCount(4, $checks);

		$captured = [];
		$abReg    = new class ($captured) {
			/** @var array<string, mixed> */
			public array $captured;

			/** @param array<string, mixed> $captured */
			public function __construct(array &$captured) {
				$this->captured = &$captured;
			}

			/**
			 * @param string               $name
			 * @param array<string, mixed> $spec
			 */
			public function register(string $name, array $spec): void {
				$this->captured[$name] = $spec;
			}
		};
		api_abilities_register($abReg);
		$this->assertCount(3, $captured);

		$GLOBALS['__api_options']['api_uninstall_keep_tables'] = 'on';
		plugin_api_uninstall();
		$GLOBALS['__api_options']['api_uninstall_keep_tables'] = '';
		plugin_api_uninstall();
	}

	/**
	 * Exercise 404, 501, 400, and 503 error paths across all core resources and Router.
	 *
	 * @return void
	 */
	public function testRouterAndResourceErrorBranches(): void {
		$repo   = InMemoryApiRepository::withDefaultFixtures();
		$admin  = (new TokenService($repo))->create(1, 'err-test', ['read', 'write', 'admin'], null, [], 1700000000);
		$auth   = ['Authorization' => 'Bearer ' . $admin['token'], 'Content-Type' => 'application/json'];
		$router = new Router($repo, new Settings(), new ResourceRegistry(), 'secret-key-0123456789abcdef01234567');

		// Disabled API returns 503 UNAVAILABLE.
		$disabledRouter = new Router($repo, new Settings(enabled: false), new ResourceRegistry(), 'secret');
		$this->assertSame(503, $disabledRouter->handle(new Request('GET', '/v1/sites', [], $auth), 1700000010)->statusCode);

		// Missing paths, bad methods, and unknown collections.
		$this->assertSame(404, $router->handle(new Request('GET', '/v2/sites', [], $auth), 1700000010)->statusCode);
		$this->assertSame(404, $router->handle(new Request('GET', '/v1/', [], $auth), 1700000010)->statusCode);
		$this->assertSame(404, $router->handle(new Request('GET', '/v1/unknownCollection', [], $auth), 1700000010)->statusCode);
		$this->assertSame(501, $router->handle(new Request('POST', '/v1/openapi.json', [], $auth), 1700000010)->statusCode);
		$this->assertSame(501, $router->handle(new Request('GET', '/v1/devices/1:enable', [], $auth), 1700000010)->statusCode);
		$this->assertSame(501, $router->handle(new Request('POST', '/v1/devices:bulk', [], $auth), 1700000010)->statusCode);
		$this->assertSame(400, $router->handle(new Request('POST', '/v1/devices/1:BAD!', [], $auth), 1700000010)->statusCode);

		// Read-only resources return 501 on POST, PATCH, DELETE, and unsupported custom methods.
		foreach (['pollers', 'graphs', 'dataSources', 'trees', 'plugins'] as $col) {
			$this->assertSame(501, $router->handle(new Request('POST', '/v1/' . $col, [], $auth, '{}'), 1700000010)->statusCode);
			$this->assertSame(501, $router->handle(new Request('PATCH', '/v1/' . $col . '/1', ['update_mask' => 'name'], $auth, '{}'), 1700000010)->statusCode);
			$this->assertSame(501, $router->handle(new Request('DELETE', '/v1/' . $col . '/1', [], $auth), 1700000010)->statusCode);
			$this->assertSame(501, $router->handle(new Request('POST', '/v1/' . $col . '/1:bogus', [], $auth, '{}'), 1700000010)->statusCode);
		}

		// 404 Not Found across resources.
		foreach (['/v1/sites/999', '/v1/pollers/999', '/v1/devices/999', '/v1/graphs/999', '/v1/devices/1/graphs/999', '/v1/dataSources/999', '/v1/trees/999', '/v1/plugins/nonexistent', '/v1/operations/missing', '/v1/tokens/missing'] as $path) {
			$this->assertSame(404, $router->handle(new Request('GET', $path, [], $auth), 1700000010)->statusCode, 'Expected 404 for ' . $path);
		}

		// Operations and tokens unsupported verbs + invalid payloads.
		$this->assertSame(501, $router->handle(new Request('POST', '/v1/operations', [], $auth, '{}'), 1700000010)->statusCode);
		$this->assertSame(501, $router->handle(new Request('PATCH', '/v1/operations/x', [], $auth, '{}'), 1700000010)->statusCode);
		$this->assertSame(501, $router->handle(new Request('PATCH', '/v1/tokens/x', [], $auth, '{}'), 1700000010)->statusCode);
		$this->assertSame(501, $router->handle(new Request('POST', '/v1/sites/1:bogus', [], $auth, '{}'), 1700000010)->statusCode);
		$this->assertSame(501, $router->handle(new Request('POST', '/v1/devices/1:bogus', [], $auth, '{}'), 1700000010)->statusCode);
		$this->assertSame(400, $router->handle(new Request('POST', '/v1/graphs/10:render', [], $auth, '{"format":"bmp"}'), 1700000010)->statusCode);
		$this->assertSame(400, $router->handle(new Request('POST', '/v1/devices', [], $auth, '{}'), 1700000010)->statusCode);
		$this->assertSame(400, $router->handle(new Request('POST', '/v1/sites', [], $auth, '{}'), 1700000010)->statusCode);
		$this->assertSame(400, $router->handle(new Request('POST', '/v1/sites', [], $auth, '[1,2,3]'), 1700000010)->statusCode);
		$this->assertSame(400, $router->handle(new Request('POST', '/v1/sites', [], $auth, '{bad-json'), 1700000010)->statusCode);

		// Device reindex returns 202 Accepted.
		$this->assertSame(202, $router->handle(new Request('POST', '/v1/devices/1:reindex', [], $auth), 1700000010)->statusCode);
	}

	/**
	 * Exercise parser, serializer, and extension descriptor validation edge cases.
	 *
	 * @return void
	 */
	public function testParserYamlAndExtensionValidationEdges(): void {
		// YAML serializer handles scalars, nested lists, maps, null, booleans, floats, empty arrays.
		$yaml = Response::encodeYaml([
			'empty'  => [],
			'flag'   => true,
			'off'    => false,
			'nil'    => null,
			'ratio'  => 1.5,
			'items'  => [['id' => 1], 'plain'],
		]);
		$this->assertStringContainsString('flag: true', $yaml);
		$this->assertStringContainsString('off: false', $yaml);
		$this->assertStringContainsString('nil: null', $yaml);
		$this->assertStringContainsString('empty: []', $yaml);

		// AIP parser error branches.
		$badFilters = [
			str_repeat('a', 2100),
			'name = "unterminated',
			'name = 1 @ 2',
			'(name = "a"',
			'name = "a" extra',
			'name = (',
		];

		foreach ($badFilters as $f) {
			try {
				FilterParser::parse($f);
				$this->fail('Expected FilterParser to reject: ' . substr($f, 0, 30));
			} catch (ApiException $e) {
				$this->assertSame(400, $e->errorStatus->httpCode);
			}
		}

		// Filter null with > operator rejected by SqlFilterCompiler.
		try {
			SqlFilterCompiler::compile(FilterParser::parse('id > null'), ['id' => 'id']);
			$this->fail('Expected > null rejection');
		} catch (ApiException $e) {
			$this->assertSame(400, $e->errorStatus->httpCode);
		}

		// OrderByParser edge rejections.
		foreach (['id asc, , name', 'id sideways', 'a,b,c,d,e,f,g,h,i'] as $ob) {
			try {
				OrderByParser::parse($ob, ['id' => 'id', 'name' => 'name']);
				$this->fail('Expected OrderByParser rejection for ' . $ob);
			} catch (ApiException $e) {
				$this->assertSame(400, $e->errorStatus->httpCode);
			}
		}

		// FieldMask empty update_mask and invalid read_mask.
		try {
			FieldMask::parse('', 'update_mask')->validateUpdateMask(['name'], 'sites');
			$this->fail('Expected empty update_mask rejection');
		} catch (ApiException $e) {
			$this->assertSame(400, $e->errorStatus->httpCode);
		}

		try {
			FieldMask::parse('secret_col', 'read_mask')->validateReadMask(['name'], 'sites');
			$this->fail('Expected invalid read_mask rejection');
		} catch (ApiException $e) {
			$this->assertSame(400, $e->errorStatus->httpCode);
		}

		// PageToken malformed inputs.
		foreach (['no-dot', '@@@.@@@', 'notjson.sig'] as $badTok) {
			try {
				PageToken::decode($badTok, 'ctx', 'secret');
				$this->fail('Expected PageToken rejection');
			} catch (ApiException $e) {
				$this->assertSame(400, $e->errorStatus->httpCode);
			}
		}

		// ResourceName malformed inputs.
		foreach (['', 'onlyone', '123bad/1', 'a/1/b/2/c/3/d/4'] as $badRes) {
			try {
				ResourceName::parse($badRes);
				$this->fail('Expected ResourceName rejection');
			} catch (ApiException $e) {
				$this->assertSame(400, $e->errorStatus->httpCode);
			}
		}

		// ExtensionResourceDescriptor & ResourceRegistry fault isolation.
		$reg = new ResourceRegistry();
		$reg->dispatchHooks([
			['name' => 'BAD_SLUG!', 'file' => 'setup.php', 'function' => static fn (): array => []],
			['name' => 'goodslug', 'file' => '../escape.php', 'function' => static fn (): array => []],
			['name' => 'goodslug2', 'file' => 'setup.php', 'function' => 'nonexistent_fn_xyz'],
			['name' => 'goodslug3', 'file' => 'setup.php', 'function' => static fn (): string => 'not-array'],
			['name' => 'goodslug4', 'file' => 'setup.php', 'function' => static fn (): array => ['not-descriptor']],
		]);
		$this->assertCount(5, $reg->faults());

		$this->expectException(\InvalidArgumentException::class);
		ExtensionResourceDescriptor::fromArray(['plugin' => 'other', 'collection' => 'fooItems', 'realm_read' => 'a.php', 'handlers' => ['list' => static fn (): array => []]], 'expected');
	}

	/**
	 * Exercise Request::fromGlobals, SqlFilterCompiler in-memory operators,
	 * TokenService validation branches, CascProvider/DoctorChecks warning branches,
	 * CliRunner options, and Router/Resource error edges to exceed 90% coverage.
	 *
	 * @return void
	 */
	public function testExtendedBranchCoverageAcrossCoreModules(): void {
		// Request::fromGlobals with mocked superglobals.
		$origServer  = $_SERVER;
		$origGet     = $_GET;
		$origSession = $_SESSION ?? [];

		$_SERVER = [
			'REQUEST_METHOD'        => 'GET',
			'REQUEST_URI'           => '/plugins/api/v1/devices?page_size=10',
			'HTTP_AUTHORIZATION'    => 'Bearer test',
			'HTTP_X_FORWARDED_PROTO' => 'https',
			'CONTENT_TYPE'          => 'application/json',
			'CONTENT_LENGTH'        => '0',
			'REMOTE_ADDR'           => '10.1.2.3',
		];
		$_GET     = ['page_size' => '10'];
		$_SESSION = ['sess_user_id' => 1];

		try {
			$req = Request::fromGlobals(1024);
			$this->assertSame('GET', $req->method);
			$this->assertSame('/v1/devices', $req->path);
			$this->assertSame('10', $req->queryParam('page_size'));
			$this->assertTrue($req->isHttps);
			$this->assertSame('10.1.2.3', $req->clientIp);
			$this->assertSame(1, $req->sessionUserId);

			$_SERVER['CONTENT_LENGTH'] = '2048';

			try {
				Request::fromGlobals(1024);
				$this->fail('Expected oversized Content-Length rejection');
			} catch (ApiException $e) {
				$this->assertSame(400, $e->errorStatus->httpCode);
			}
		} finally {
			$_SERVER  = $origServer;
			$_GET     = $origGet;
			$_SESSION = $origSession;
		}

		// SqlFilterCompiler in-memory operators: <, <=, >, >=, !=, :, arrays, booleans, null.
		$rec    = ['n' => 10, 's' => 'beta', 'b' => true, 'tags' => ['core', 'edge'], 'nil' => null];
		$fields = ['n', 's', 'b', 'tags', 'nil'];
		$this->assertTrue(SqlFilterCompiler::matchesRecord(FilterParser::parse('n < 20 AND n <= 10 AND n > 5 AND n >= 10'), $rec, $fields));
		$this->assertTrue(SqlFilterCompiler::matchesRecord(FilterParser::parse('s > "alpha" AND s >= "beta" AND s < "gamma" AND s <= "beta"'), $rec, $fields));
		$this->assertTrue(SqlFilterCompiler::matchesRecord(FilterParser::parse('s != "alpha" AND b = true AND nil = null AND tags : "edge"'), $rec, $fields));
		$this->assertFalse(SqlFilterCompiler::matchesRecord(FilterParser::parse('nil : "x"'), $rec, $fields));
		$this->assertTrue(SqlFilterCompiler::matchesRecord(FilterParser::parse('b = false'), ['b' => ''], $fields));
		$this->assertFalse(SqlFilterCompiler::matchesRecord(FilterParser::parse('b = true'), ['b' => ['not-bool']], $fields));

		// PageToken non-numeric page_size and valid-HMAC corrupted-JSON payload.
		try {
			PageToken::clampPageSize('abc');
			$this->fail('Expected non-numeric page_size rejection');
		} catch (ApiException $e) {
			$this->assertSame(400, $e->errorStatus->httpCode);
		}

		$badPayload = rtrim(strtr(base64_encode('not-json'), '+/', '-_'), '=');
		$badSig     = rtrim(strtr(base64_encode(hash_hmac('sha256', 'not-json', 'sec', true)), '+/', '-_'), '=');

		try {
			PageToken::decode($badPayload . '.' . $badSig, 'ctx', 'sec');
			$this->fail('Expected corrupted JSON token rejection');
		} catch (ApiException $e) {
			$this->assertSame(400, $e->errorStatus->httpCode);
		}

		// TokenService validation branches.
		$repo   = InMemoryApiRepository::withDefaultFixtures();
		$tokSvc = new TokenService($repo);

		foreach ([
			static fn () => $tokSvc->create(1, '', ['read'], null, [], 100),
			static fn () => $tokSvc->create(1, 'lbl', ['superuser'], null, [], 100),
			static fn () => $tokSvc->create(1, 'lbl', ['read'], 50, [], 100),
			static fn () => $tokSvc->create(1, 'lbl', ['read'], null, ['not-a-cidr'], 100),
			static fn () => $tokSvc->create(1, 'lbl', ['read'], null, ['10.0.0.1/99'], 100),
			static fn () => $tokSvc->create(1, 'lbl', ['read'], null, ['10.0.0.1/abc'], 100),
			static fn () => $tokSvc->authenticate('bad-format', '127.0.0.1', 100),
			static fn () => $tokSvc->delete('missing', 1, true),
			static fn () => $tokSvc->revoke('missing', 1, true, 100),
		] as $fn) {
			try {
				$fn();
				$this->fail('Expected TokenService ApiException');
			} catch (ApiException $e) {
				$this->assertContains($e->errorStatus->httpCode, [400, 401, 404]);
			}
		}

		$this->assertFalse(TokenService::ipMatchesAnyCidr('not-an-ip', ['10.0.0.0/8']));
		$this->assertFalse(TokenService::ipMatchesAnyCidr('10.0.0.1', ['2001:db8::/32']));
		$this->assertTrue(TokenService::ipMatchesAnyCidr('10.0.0.5', ['10.0.0.5']));
		$this->assertTrue(TokenService::ipMatchesAnyCidr('10.0.0.5', ['10.0.0.0/29']));

		// ResourceContext update_mask in body (string and array) + Router/Resource edge branches.
		$admin  = $tokSvc->create(1, 'cov-admin', ['read', 'write', 'admin'], null, [], 1700000000);
		$auth   = ['Authorization' => 'Bearer ' . $admin['token'], 'Content-Type' => 'application/json'];
		$router = new Router(
			$repo,
			new Settings(),
			new ResourceRegistry(),
			'secret-key-0123456789abcdef01234567',
			'0.1.0',
			static fn (string $t): bool => $t === 'ok-csrf'
		);

		// Session auth valid write with CSRF + disabled session user.
		$sessOk = $router->handle(new Request('POST', '/v1/sites', [], ['Content-Type' => 'application/json', 'X-CSRF-Token' => 'ok-csrf'], '{"display_name":"SessionSite"}', '127.0.0.1', false, 1), 1700000010);
		$this->assertSame(201, $sessOk->statusCode);
		$sessDisabled = $router->handle(new Request('GET', '/v1/sites', [], [], '', '127.0.0.1', false, 3), 1700000011);
		$this->assertSame(401, $sessDisabled->statusCode);

		// Body update_mask as array & as string + empty display_name/description/hostname rejections.
		$this->assertSame(200, $router->handle(new Request('PATCH', '/v1/sites/1', [], $auth, '{"update_mask":["city"],"city":"SJC"}'), 1700000012)->statusCode);
		$this->assertSame(400, $router->handle(new Request('PATCH', '/v1/sites/1', [], $auth, '{"update_mask":"display_name","display_name":""}'), 1700000013)->statusCode);
		$this->assertSame(404, $router->handle(new Request('PATCH', '/v1/sites/999', ['update_mask' => 'city'], $auth, '{"city":"X"}'), 1700000014)->statusCode);
		$this->assertSame(404, $router->handle(new Request('DELETE', '/v1/sites/999', [], $auth), 1700000015)->statusCode);
		$this->assertSame(400, $router->handle(new Request('PATCH', '/v1/devices/1', ['update_mask' => 'description'], $auth, '{"description":""}'), 1700000016)->statusCode);
		$this->assertSame(400, $router->handle(new Request('PATCH', '/v1/devices/1', ['update_mask' => 'hostname'], $auth, '{"hostname":""}'), 1700000017)->statusCode);
		$this->assertSame(404, $router->handle(new Request('PATCH', '/v1/devices/999', ['update_mask' => 'location'], $auth, '{"location":"Z"}'), 1700000018)->statusCode);
		$this->assertSame(404, $router->handle(new Request('DELETE', '/v1/devices/999', [], $auth), 1700000019)->statusCode);
		$this->assertSame(404, $router->handle(new Request('POST', '/v1/devices/999:enable', [], $auth), 1700000020)->statusCode);

		// TokensResource list, cidr_allowlist, ttl_days bounds, admin cross-user creation.
		$this->assertSame(200, $router->handle(new Request('GET', '/v1/tokens', [], $auth), 1700000021)->statusCode);
		$this->assertSame(201, $router->handle(new Request('POST', '/v1/tokens', [], $auth, '{"display_name":"ForUser2","user_id":2,"scopes":["read"],"cidr_allowlist":["10.0.0.0/8"],"ttl_days":0}'), 1700000022)->statusCode);
		$this->assertSame(400, $router->handle(new Request('POST', '/v1/tokens', [], $auth, '{"display_name":"BadTtl","ttl_days":99999}'), 1700000023)->statusCode);

		// DoctorChecks warning branches: expired tokens > 50, rate_limit_rows > 50000, wildcard+creds, faults.
		$bloatedRepo = new InMemoryApiRepository();

		for ($i = 0; $i < 55; $i++) {
			$bloatedRepo->tokens['t' . $i] = [
				'id' => 't' . $i, 'user_id' => 1, 'name' => 'exp', 'token_prefix' => '00000000',
				'token_hash' => str_repeat('a', 64), 'scopes' => 'read', 'cidr_allowlist' => '',
				'created_at' => 100, 'expires_at' => 200, 'last_used_at' => null, 'revoked_at' => null,
			];
		}

		$faultReg = new ResourceRegistry();
		$faultReg->dispatchHooks([['name' => 'BAD!', 'file' => 'x.php', 'function' => static fn (): array => []]]);

		$docChecks = \Somethingwithproof\Api\Integration\DoctorChecks::build(
			static fn (): InMemoryApiRepository => $bloatedRepo,
			static fn (): Settings => new Settings(corsOrigins: ['*'], corsAllowCredentials: true),
			static fn (): ResourceRegistry => $faultReg,
			static fn (): int => 500
		);
		$this->assertSame('warning', ($docChecks[0]['run'])()['status']);
		$this->assertSame('warning', ($docChecks[3]['run'])()['status']);

		// CascProvider error plan/apply + typed object registrar reflection path.
		$cascDesc = \Somethingwithproof\Api\Integration\CascProvider::descriptor(
			static fn (): Settings => new Settings(),
			static function (array $v): void {
			}
		);
		$errPlan = ($cascDesc['plan'])(['api_rate_limit_per_min' => -5], []);
		$this->assertSame('error', $errPlan[0]['operation']);
		$errApply = ($cascDesc['apply'])(['api_rate_limit_per_min' => -5], 'h', []);
		$this->assertFalse($errApply['ok']);

		// CliRunner space-separated flags, missing --id, and ApiException stderr formatting.
		$cli = new \Somethingwithproof\Api\Cli\CliRunner($repo, new Settings(), new ResourceRegistry());
		$this->assertSame(0, $cli->run(['token-create', '--user-id', '1', '--name', 'spaced', '--cidr', '10.0.0.0/8'], 1700000000)['exitCode']);
		$this->assertSame(2, $cli->run(['token-revoke'], 1700000000)['exitCode']);
		$this->assertSame(1, $cli->run(['token-revoke', '--id=missing-id'], 1700000000)['exitCode']);
	}
}
