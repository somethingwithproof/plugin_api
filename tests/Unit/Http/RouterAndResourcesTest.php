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

namespace Somethingwithproof\Api\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Somethingwithproof\Api\Auth\TokenService;
use Somethingwithproof\Api\Extension\ResourceRegistry;
use Somethingwithproof\Api\Http\Request;
use Somethingwithproof\Api\Http\Router;
use Somethingwithproof\Api\Repository\InMemoryApiRepository;
use Somethingwithproof\Api\Settings;

/**
 * End-to-end HTTP Router tests covering all 9 core AIP collections, nested
 * device graphs, custom methods, OpenAPI 3.1 generation, sibling extensions,
 * CORS, and YAML content negotiation.
 */
final class RouterAndResourcesTest extends TestCase {
	/**
	 * Verify CRUD, pagination, filtering, ETags, and custom methods across core resources.
	 *
	 * @return void
	 */
	public function testCoreCollectionsEndToEnd(): void {
		$repo   = InMemoryApiRepository::withDefaultFixtures();
		$tokens = new TokenService($repo);
		$admin  = $tokens->create(1, 'admin-pat', ['read', 'write', 'admin'], null, [], 1700000000);
		$auth   = ['Authorization' => 'Bearer ' . $admin['token']];
		$router = new Router($repo, new Settings(), new ResourceRegistry(), 'secret-key-0123456789abcdef01234567');

		// Sites CRUD + pagination + read_mask + ETag.
		$sCreate = $router->handle(new Request('POST', '/v1/sites', [], $auth + ['Content-Type' => 'application/json'], json_encode(['display_name' => 'West DC', 'city' => 'Seattle'], JSON_THROW_ON_ERROR)), 1700000010);
		$this->assertSame(201, $sCreate->statusCode);
		$siteId   = (int) ($sCreate->body['id'] ?? 0);
		$siteEtag = (string) ($sCreate->headers['ETag'] ?? '');

		$sList = $router->handle(new Request('GET', '/v1/sites', ['page_size' => '1', 'order_by' => 'id asc', 'read_mask' => 'name,id,display_name'], $auth), 1700000011);
		$this->assertSame(200, $sList->statusCode);
		$this->assertSame(2, $sList->body['totalSize']);
		$this->assertNotSame('', $sList->body['nextPageToken']);

		$sPage2 = $router->handle(new Request('GET', '/v1/sites', ['page_size' => '1', 'order_by' => 'id asc', 'page_token' => (string) $sList->body['nextPageToken']], $auth), 1700000012);
		$this->assertSame(200, $sPage2->statusCode);
		$this->assertSame('', $sPage2->body['nextPageToken']);

		$sPatch = $router->handle(new Request('PATCH', '/v1/sites/' . $siteId, ['update_mask' => 'city'], $auth + ['Content-Type' => 'application/json', 'If-Match' => $siteEtag], json_encode(['city' => 'Bellevue'], JSON_THROW_ON_ERROR)), 1700000013);
		$this->assertSame(200, $sPatch->statusCode);
		$this->assertSame('Bellevue', $sPatch->body['city']);

		$sDel = $router->handle(new Request('DELETE', '/v1/sites/' . $siteId, [], $auth), 1700000014);
		$this->assertSame(204, $sDel->statusCode);

		// Pollers, DataSources, Trees, Plugins GET/List.
		$this->assertSame(200, $router->handle(new Request('GET', '/v1/pollers', [], $auth), 1700000015)->statusCode);
		$this->assertSame(200, $router->handle(new Request('GET', '/v1/pollers/1', [], $auth), 1700000016)->statusCode);
		$this->assertSame(200, $router->handle(new Request('GET', '/v1/dataSources', [], $auth), 1700000017)->statusCode);
		$this->assertSame(200, $router->handle(new Request('GET', '/v1/dataSources/20', [], $auth), 1700000018)->statusCode);
		$this->assertSame(200, $router->handle(new Request('GET', '/v1/trees', [], $auth), 1700000019)->statusCode);
		$this->assertSame(200, $router->handle(new Request('GET', '/v1/trees/1', [], $auth), 1700000020)->statusCode);
		$this->assertSame(200, $router->handle(new Request('GET', '/v1/plugins', [], $auth), 1700000021)->statusCode);
		$this->assertSame(200, $router->handle(new Request('GET', '/v1/plugins/api', [], $auth), 1700000022)->statusCode);

		// Devices CRUD + custom methods :disable, :enable, :reindex, :poll.
		$dCreate = $router->handle(new Request('POST', '/v1/devices', [], $auth + ['Content-Type' => 'application/json'], json_encode(['description' => 'Edge Router', 'hostname' => '10.1.1.1'], JSON_THROW_ON_ERROR)), 1700000023);
		$this->assertSame(201, $dCreate->statusCode);
		$devId = (int) ($dCreate->body['id'] ?? 0);

		$dDisable = $router->handle(new Request('POST', '/v1/devices/' . $devId . ':disable', [], $auth), 1700000024);
		$this->assertSame(200, $dDisable->statusCode);
		$this->assertTrue($dDisable->body['disabled']);

		$dEnable = $router->handle(new Request('POST', '/v1/devices/' . $devId . ':enable', [], $auth), 1700000025);
		$this->assertSame(200, $dEnable->statusCode);
		$this->assertFalse($dEnable->body['disabled']);

		$dPoll = $router->handle(new Request('POST', '/v1/devices/' . $devId . ':poll', [], $auth), 1700000026);
		$this->assertSame(202, $dPoll->statusCode);
		$opId = (string) ($dPoll->body['id'] ?? '');
		$this->assertFalse($dPoll->body['done']);

		// Operations GET, List, :cancel, DELETE.
		$this->assertSame(200, $router->handle(new Request('GET', '/v1/operations/' . $opId, [], $auth), 1700000027)->statusCode);
		$this->assertSame(200, $router->handle(new Request('GET', '/v1/operations', ['filter' => 'done = false'], $auth), 1700000028)->statusCode);
		$opCancel = $router->handle(new Request('POST', '/v1/operations/' . $opId . ':cancel', [], $auth), 1700000029);
		$this->assertSame(200, $opCancel->statusCode);
		$this->assertTrue($opCancel->body['done']);
		$this->assertSame(204, $router->handle(new Request('DELETE', '/v1/operations/' . $opId, [], $auth), 1700000030)->statusCode);

		// Nested device graphs + :render custom method.
		$gList = $router->handle(new Request('GET', '/v1/devices/1/graphs', [], $auth), 1700000031);
		$this->assertSame(200, $gList->statusCode);
		$this->assertSame('devices/1/graphs/10', $gList->body['graphs'][0]['name']);

		$gGet = $router->handle(new Request('GET', '/v1/devices/1/graphs/10', [], $auth), 1700000032);
		$this->assertSame(200, $gGet->statusCode);

		$gRender = $router->handle(new Request('POST', '/v1/devices/1/graphs/10:render', [], $auth + ['Content-Type' => 'application/json'], json_encode(['format' => 'svg'], JSON_THROW_ON_ERROR)), 1700000033);
		$this->assertSame(200, $gRender->statusCode);
		$this->assertSame('image/svg+xml', $gRender->body['contentType']);

		// Tokens collection CRUD + :revoke.
		$tCreate = $router->handle(new Request('POST', '/v1/tokens', [], $auth + ['Content-Type' => 'application/json'], json_encode(['display_name' => 'SubToken', 'scopes' => ['read'], 'ttl_days' => 30], JSON_THROW_ON_ERROR)), 1700000034);
		$this->assertSame(201, $tCreate->statusCode);
		$subTokId = (string) ($tCreate->body['id'] ?? '');
		$this->assertStringStartsWith('cacti_pat_', (string) ($tCreate->body['plainTextToken'] ?? ''));

		$this->assertSame(200, $router->handle(new Request('GET', '/v1/tokens/' . $subTokId, [], $auth), 1700000035)->statusCode);
		$tRevoke = $router->handle(new Request('POST', '/v1/tokens/' . $subTokId . ':revoke', [], $auth), 1700000036);
		$this->assertSame(200, $tRevoke->statusCode);
		$this->assertTrue($tRevoke->body['revoked']);
		$this->assertSame(204, $router->handle(new Request('DELETE', '/v1/tokens/' . $subTokId, [], $auth), 1700000037)->statusCode);
	}

	/**
	 * Verify decoupled api_register_resources hook mounts sibling resources and adds them to /v1/openapi.json.
	 *
	 * @return void
	 */
	public function testDecoupledExtensionHookDiscoveryRoutingAndOpenApi(): void {
		$repo     = InMemoryApiRepository::withDefaultFixtures();
		$repo->userRealms[1][] = 'widgets_view.php';
		$repo->userRealms[1][] = 'widgets_admin.php';

		$widgets = [
			'w1' => ['id' => 'w1', 'name' => 'mypluginWidgets/w1', 'label' => 'Alpha'],
			'w2' => ['id' => 'w2', 'name' => 'mypluginWidgets/w2', 'label' => 'Beta'],
		];

		$registry = new ResourceRegistry();
		$registry->dispatchHooks([
			[
				'name'     => 'myplugin',
				'file'     => 'setup.php',
				'function' => static function (array $registrar) use (&$widgets): array {
					$registrar[] = [
						'plugin'         => 'myplugin',
						'collection'     => 'mypluginWidgets',
						'singleton'      => 'mypluginWidget',
						'realm_read'     => 'widgets_view.php',
						'realm_write'    => 'widgets_admin.php',
						'filter_fields'  => ['id', 'label'],
						'order_fields'   => ['id', 'label'],
						'mutable_fields' => ['label'],
						'handlers'       => [
							'list'   => static fn (array $ctx): array => array_values($widgets),
							'get'    => static fn (string $id, array $ctx): ?array => $widgets[$id] ?? null,
							'create' => static function (array $body, array $ctx) use (&$widgets): array {
								$widgets['w3'] = ['id' => 'w3', 'name' => 'mypluginWidgets/w3', 'label' => (string) ($body['label'] ?? '')];

								return $widgets['w3'];
							},
							'update' => static function (string $id, array $patch, array $ctx) use (&$widgets): ?array {
								if (!isset($widgets[$id])) {
									return null;
								}
								$widgets[$id]['label'] = (string) ($patch['label'] ?? $widgets[$id]['label']);

								return $widgets[$id];
							},
							'delete' => static function (string $id, array $ctx) use (&$widgets): bool {
								if (!isset($widgets[$id])) {
									return false;
								}
								unset($widgets[$id]);

								return true;
							},
						],
					];

					return $registrar;
				},
			],
			// Faulty plugin trying to shadow core "devices" collection is isolated cleanly.
			[
				'name'     => 'badplugin',
				'file'     => 'setup.php',
				'function' => static fn (array $r): array => [[
					'plugin'     => 'badplugin',
					'collection' => 'devices',
					'realm_read' => 'bad.php',
					'handlers'   => ['list' => static fn (): array => []],
				]],
			],
		]);

		$this->assertCount(1, $registry->all());
		$this->assertCount(1, $registry->faults());

		$tokens = new TokenService($repo);
		$admin  = $tokens->create(1, 'ext-test', ['read', 'write', 'admin'], null, [], 1700000000);
		$auth   = ['Authorization' => 'Bearer ' . $admin['token']];
		$router = new Router($repo, new Settings(), $registry, 'secret-key-0123456789abcdef01234567');

		$list = $router->handle(new Request('GET', '/v1/mypluginWidgets', ['filter' => 'label = "Beta"'], $auth), 1700000010);
		$this->assertSame(200, $list->statusCode);
		$this->assertSame(1, $list->body['totalSize']);

		$created = $router->handle(new Request('POST', '/v1/mypluginWidgets', [], $auth + ['Content-Type' => 'application/json'], json_encode(['label' => 'Gamma'], JSON_THROW_ON_ERROR)), 1700000011);
		$this->assertSame(201, $created->statusCode);

		$updated = $router->handle(new Request('PATCH', '/v1/mypluginWidgets/w3', ['update_mask' => 'label'], $auth + ['Content-Type' => 'application/json'], json_encode(['label' => 'Gamma-2'], JSON_THROW_ON_ERROR)), 1700000012);
		$this->assertSame(200, $updated->statusCode);
		$this->assertSame('Gamma-2', $updated->body['label']);

		$deleted = $router->handle(new Request('DELETE', '/v1/mypluginWidgets/w3', [], $auth), 1700000013);
		$this->assertSame(204, $deleted->statusCode);

		// OpenAPI 3.1 spec automatically includes the dynamically registered collection.
		$openapi = $router->handle(new Request('GET', '/v1/openapi.json', [], $auth), 1700000014);
		$this->assertSame(200, $openapi->statusCode);
		$this->assertSame('3.1.0', $openapi->body['openapi']);
		$this->assertArrayHasKey('/v1/mypluginWidgets', $openapi->body['paths']);
		$this->assertArrayHasKey('/v1/devices/{device}:poll', $openapi->body['paths']);
	}

	/**
	 * Verify CORS allowlist rules, security headers, TLS enforcement, and YAML negotiation.
	 *
	 * @return void
	 */
	public function testSecurityHeadersCorsTlsAndYamlNegotiation(): void {
		$repo     = InMemoryApiRepository::withDefaultFixtures();
		$tokens   = new TokenService($repo);
		$admin    = $tokens->create(1, 'cors-test', ['read'], null, [], 1700000000);
		$settings = new Settings(
			enabled: true,
			corsOrigins: ['https://ops.example.com'],
			corsAllowCredentials: true,
			requireTls: true,
			enableYamlNegotiation: true,
		);
		$router = new Router($repo, $settings, new ResourceRegistry(), 'secret-key-0123456789abcdef01234567');

		// Preflight OPTIONS emits allowlisted CORS headers.
		$opt = $router->handle(new Request('OPTIONS', '/v1/sites', [], ['Origin' => 'https://ops.example.com'], '', '127.0.0.1', true), 1700000010);
		$this->assertSame(204, $opt->statusCode);
		$this->assertSame('https://ops.example.com', $opt->resolvedHeaders()['Access-Control-Allow-Origin']);
		$this->assertSame('true', $opt->resolvedHeaders()['Access-Control-Allow-Credentials']);

		// Non-allowlisted origin receives no Access-Control-Allow-Origin header.
		$optBad = $router->handle(new Request('OPTIONS', '/v1/sites', [], ['Origin' => 'https://evil.example.net'], '', '127.0.0.1', true), 1700000011);
		$this->assertArrayNotHasKey('Access-Control-Allow-Origin', $optBad->resolvedHeaders());

		// Non-HTTPS fails with 400 FAILED_PRECONDITION when requireTls=true.
		$httpResp = $router->handle(new Request('GET', '/v1/sites', [], ['Authorization' => 'Bearer ' . $admin['token']], '', '127.0.0.1', false), 1700000012);
		$this->assertSame(400, $httpResp->statusCode);

		// HTTPS + Accept: application/yaml emits YAML payload with security headers.
		$yamlResp = $router->handle(new Request('GET', '/v1/sites', [], ['Authorization' => 'Bearer ' . $admin['token'], 'Accept' => 'application/yaml'], '', '127.0.0.1', true), 1700000013);
		$this->assertSame(200, $yamlResp->statusCode);
		$headers = $yamlResp->resolvedHeaders();
		$this->assertSame('nosniff', $headers['X-Content-Type-Options']);
		$this->assertSame('no-store', $headers['Cache-Control']);
		$this->assertSame('application/yaml; charset=utf-8', $headers['Content-Type']);
		$this->assertStringContainsString('totalSize: 1', $yamlResp->renderBody());

		// Wildcard "*" NEVER emits Access-Control-Allow-Credentials: true.
		$wildcardSettings = new Settings(corsOrigins: ['*'], corsAllowCredentials: true);
		$wildcardRouter   = new Router($repo, $wildcardSettings, new ResourceRegistry(), 'secret-key-0123456789abcdef01234567');
		$wildResp         = $wildcardRouter->handle(new Request('OPTIONS', '/v1/sites', [], ['Origin' => 'https://any.example.com']), 1700000014);
		$this->assertArrayNotHasKey('Access-Control-Allow-Origin', $wildResp->resolvedHeaders());
		$this->assertArrayNotHasKey('Access-Control-Allow-Credentials', $wildResp->resolvedHeaders());
	}
}
