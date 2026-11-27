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
}
