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

namespace Somethingwithproof\Api\Tests\Unit\Repository;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Somethingwithproof\Api\Aip\FilterParser;
use Somethingwithproof\Api\Aip\OrderByParser;
use Somethingwithproof\Api\Repository\CactiApiRepository;
use Somethingwithproof\Api\Schema;

/**
 * Isolated unit tests exercising CactiApiRepository and Schema SQL generation
 * against deterministic Cacti DB function stubs.
 */
final class CactiRepositoryAndSchemaTest extends TestCase {
	/**
	 * Verify CactiApiRepository prepared SQL calls across all resource tables and state tables.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	public function testCactiApiRepositoryPreparedQueries(): void {
		$GLOBALS['__db_calls'] = [];
		$GLOBALS['__db_next']  = [
			'assoc' => [],
			'row'   => [],
			'cell'  => 1,
		];

		eval('
			function db_fetch_assoc_prepared($sql, $params = [], $log = true, $db_conn = false) {
				$GLOBALS["__db_calls"][] = ["assoc", $sql, $params];
				return $GLOBALS["__db_next"]["assoc"];
			}
			function db_fetch_row_prepared($sql, $params = [], $log = true, $db_conn = false) {
				$GLOBALS["__db_calls"][] = ["row", $sql, $params];
				return $GLOBALS["__db_next"]["row"];
			}
			function db_fetch_cell_prepared($sql, $params = [], $col = "", $log = true, $db_conn = false) {
				$GLOBALS["__db_calls"][] = ["cell", $sql, $params];
				return $GLOBALS["__db_next"]["cell"];
			}
			function db_execute_prepared($sql, $params = [], $log = true, $db_conn = false) {
				$GLOBALS["__db_calls"][] = ["exec", $sql, $params];
				return true;
			}
			function db_fetch_insert_id($db_conn = false) {
				return 42;
			}
			function api_plugin_db_table_create($plugin, $table, $data) {
				$GLOBALS["__db_calls"][] = ["create_table", $plugin, $table];
				return true;
			}
		');

		$repo = new CactiApiRepository(false);

		$GLOBALS['__db_next']['assoc'] = [['id' => 1, 'name' => 'HQ', 'city' => 'Austin']];
		$GLOBALS['__db_next']['row']   = ['id' => 1, 'name' => 'HQ', 'city' => 'Austin'];

		$sites = $repo->listSites(FilterParser::parse('city = "Austin"'), OrderByParser::parse('name desc', ['name' => 'name']));
		$this->assertCount(1, $sites);
		$this->assertNotNull($repo->getSite(1));
		$this->assertSame(1, $repo->createSite(['name' => 'New'])['id']);
		$this->assertNotNull($repo->updateSite(1, ['city' => 'Dallas']));
		$this->assertTrue($repo->deleteSite(1));

		$this->assertCount(1, $repo->listPollers());
		$this->assertNotNull($repo->getPoller(1));

		$this->assertCount(1, $repo->listDevices(FilterParser::parse('hostname : "10."')));
		$this->assertNotNull($repo->getDevice(1));
		$this->assertNotNull($repo->createDevice(['description' => 'SW', 'hostname' => '10.0.0.1']));
		$this->assertNotNull($repo->updateDevice(1, ['description' => 'SW2', 'disabled' => true, 'snmp_port' => 161]));
		$this->assertNotNull($repo->setDeviceDisabled(1, true));
		$this->assertTrue($repo->deleteDevice(1));

		$this->assertCount(1, $repo->listGraphs(1, FilterParser::parse('title_cache : "Traffic"')));
		$this->assertNotNull($repo->getGraph(10, 1));
		$this->assertCount(1, $repo->listDataSources());
		$this->assertNotNull($repo->getDataSource(20));
		$this->assertCount(1, $repo->listTrees());
		$this->assertNotNull($repo->getTree(1));
		$this->assertCount(1, $repo->listPlugins());
		$this->assertNotNull($repo->getPlugin('api'));

		// Token, operation, idempotency, rate limit, health, user/ACL paths.
		$tokRow = [
			'id'             => 'tok-1',
			'user_id'        => 1,
			'name'           => 'bot',
			'token_prefix'   => 'abcd1234',
			'token_hash'     => str_repeat('a', 64),
			'scopes'         => 'read',
			'cidr_allowlist' => '',
			'created_at'     => 100,
			'expires_at'     => null,
			'last_used_at'   => null,
			'revoked_at'     => null,
		];
		$GLOBALS['__db_next']['assoc'] = [$tokRow];
		$GLOBALS['__db_next']['row']   = $tokRow;
		$repo->createToken($tokRow);
		$this->assertCount(1, $repo->findTokensByPrefix('abcd1234'));
		$this->assertNotNull($repo->findTokenById('tok-1'));
		$this->assertCount(1, $repo->listTokens(1));
		$this->assertCount(1, $repo->listTokens(null));
		$repo->touchTokenLastUsed('tok-1', 200);
		$repo->revokeToken('tok-1', 300);
		$repo->deleteToken('tok-1');

		$opDbRow = [
			'id'              => 'op-1',
			'user_id'         => 1,
			'operation_type'  => 'device.poll',
			'target_resource' => 'devices/1',
			'done'            => 0,
			'cancelled'       => 0,
			'metadata_json'   => '{"targetResource":"devices/1"}',
			'response_json'   => null,
			'error_json'      => null,
			'created_at'      => 100,
			'updated_at'      => 100,
		];
		$GLOBALS['__db_next']['assoc'] = [$opDbRow];
		$GLOBALS['__db_next']['row']   = $opDbRow;
		$opRec = [
			'id'              => 'op-1',
			'user_id'         => 1,
			'operation_type'  => 'device.poll',
			'target_resource' => 'devices/1',
			'done'            => false,
			'cancelled'       => false,
			'metadata'        => ['targetResource' => 'devices/1'],
			'response'        => null,
			'error'           => null,
			'created_at'      => 100,
			'updated_at'      => 100,
		];
		$repo->createOperation($opRec);
		$this->assertNotNull($repo->getOperation('op-1'));
		$this->assertCount(1, $repo->listOperations(1));
		$this->assertCount(1, $repo->listOperations(null));
		$repo->updateOperation($opRec);
		$repo->deleteOperation('op-1');

		$GLOBALS['__db_next']['row'] = [
			'request_id'    => '1:uuid',
			'user_id'       => 1,
			'method'        => 'POST',
			'path'          => '/v1/sites',
			'request_hash'  => str_repeat('b', 64),
			'status_code'   => 201,
			'response_json' => '{"id":1}',
			'created_at'    => 100,
			'expires_at'    => 999999,
		];
		$repo->saveIdempotencyRecord('1:uuid', 1, 'POST', '/v1/sites', str_repeat('b', 64), 201, '{"id":1}', 100, 999999);
		$this->assertNotNull($repo->getIdempotencyRecord('1:uuid', 150));

		$this->assertSame(1, $repo->incrementRateLimitBucket(str_repeat('c', 64), 60));
		$stats = $repo->getHealthStats(1000);
		$this->assertArrayHasKey('active_tokens', $stats);

		$GLOBALS['__db_next']['row'] = ['id' => 1, 'username' => 'admin', 'enabled' => 'on', 'locked' => ''];
		$this->assertTrue($repo->getUserById(1)['enabled'] ?? false);

		// Admin realm inherits Operator (api_tokens.php) and View (api_view.php).
		$GLOBALS['__db_next']['assoc'] = [['file' => 'api_admin.php', 'realm_id' => 3]];
		$realms                        = $repo->getUserRealms(1);
		$this->assertContains('api_admin.php', $realms);
		$this->assertContains('api_tokens.php', $realms);
		$this->assertContains('api_view.php', $realms);
		$this->assertContains('host.php', $realms);

		// Operator realm inherits View (api_view.php) without escalating to Admin.
		$GLOBALS['__db_next']['assoc'] = [['file' => 'api_tokens.php']];
		$operatorRealms                = $repo->getUserRealms(2);
		$this->assertContains('api_tokens.php', $operatorRealms);
		$this->assertContains('api_view.php', $operatorRealms);
		$this->assertNotContains('api_admin.php', $operatorRealms);

		// View realm does not escalate to Operator or Admin.
		$GLOBALS['__db_next']['assoc'] = [['file' => 'api_view.php']];
		$viewRealms                    = $repo->getUserRealms(3);
		$this->assertContains('api_view.php', $viewRealms);
		$this->assertNotContains('api_tokens.php', $viewRealms);
		$this->assertNotContains('api_admin.php', $viewRealms);

		// Policy 1 with listed count 1 => denied; verify ACL methods run cleanly.
		$GLOBALS['__db_next']['cell'] = 1;
		$this->assertFalse($repo->isDeviceAllowedForUser(1, 5));
		$this->assertFalse($repo->isGraphAllowedForUser(1, 5));
		$this->assertFalse($repo->isTreeAllowedForUser(1, 5));

		// Schema lifecycle methods.
		$schema = new Schema(false);
		$this->assertSame(1, $schema->storedVersion());
		$this->assertTrue($schema->isCurrent());
		$this->assertTrue($schema->migrate());
		$this->assertSame('1', $schema->recordedPluginVersion());
		$schema->recordPluginVersion('0.1.0', 'Thomas', 'https://example.com');
		$this->assertTrue($schema->pluginEnabled());
		$schema->untrackTables();
		$schema->forgetVersion();

		// Verify refusal to downgrade when stored version > Schema::VERSION.
		$GLOBALS['__db_next']['cell'] = 99;
		$this->assertFalse($schema->migrate());
	}
}
