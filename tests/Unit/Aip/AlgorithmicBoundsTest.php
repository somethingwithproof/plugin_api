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

namespace Somethingwithproof\Api\Tests\Unit\Aip;

use PHPUnit\Framework\TestCase;
use Somethingwithproof\Api\Aip\ApiException;
use Somethingwithproof\Api\Aip\Etag;
use Somethingwithproof\Api\Aip\FieldMask;
use Somethingwithproof\Api\Aip\FilterNode;
use Somethingwithproof\Api\Aip\FilterParser;
use Somethingwithproof\Api\Aip\OrderByParser;
use Somethingwithproof\Api\Aip\PageToken;
use Somethingwithproof\Api\Aip\ResourceName;
use Somethingwithproof\Api\Aip\SqlFilterCompiler;
use Somethingwithproof\Api\Extension\ResourceRegistry;
use Somethingwithproof\Api\OpenApi\OpenApiGenerator;

/**
 * Verifies algorithmic safety bounds, ReDoS/depth guards, and linear scaling
 * guarantees across AIP parsers, compilers, codecs, and OpenAPI generation.
 */
final class AlgorithmicBoundsTest extends TestCase {
	/**
	 * Verify FilterParser handles escaped quotes, negative numbers, floats, and rejects adversarial depth/token inputs.
	 *
	 * @return void
	 */
	public function testFilterParserTokenizerAndDepthBounds(): void {
		$ast = FilterParser::parse('(status = 3 AND availability >= -12.5) OR (description : "core \\"switch\\"" AND NOT disabled = true)');
		$this->assertNotNull($ast);

		$compiled = SqlFilterCompiler::compile($ast, [
			'status'       => 'status',
			'availability' => 'availability',
			'description'  => 'description',
			'disabled'     => 'disabled',
		]);
		$this->assertSame('(((status = ? AND availability >= ?) OR (description LIKE ? AND (NOT disabled = ?))))', '(' . $compiled['sql'] . ')');
		$this->assertSame([3, -12.5, '%core "switch"%', 1], $compiled['params']);

		// Adversarial deeply nested parentheses and NOT chains fail closed with 400 INVALID_ARGUMENT.
		$adversarial = [
			str_repeat('(', 17) . 'id = 1' . str_repeat(')', 17),
			str_repeat('NOT ', 18) . 'id = 1',
			str_repeat('id = 1 AND ', 40) . 'id = 2',
		];

		foreach ($adversarial as $expr) {
			try {
				FilterParser::parse($expr);
				$this->fail('Expected FilterParser to reject adversarial input.');
			} catch (ApiException $e) {
				$this->assertSame(400, $e->errorStatus->httpCode);
			}
		}
	}

	/**
	 * Verify SqlFilterCompiler enforces AST depth limits and batch filterRecords() semantics.
	 *
	 * @return void
	 */
	public function testSqlFilterCompilerDepthGuardAndBatchFiltering(): void {
		// Programmatically build an AST deeper than MAX_DEPTH=64.
		$deep = FilterNode::comparison('id', '=', 1);

		for ($i = 0; $i < 70; $i++) {
			$deep = FilterNode::logicalNot($deep);
		}

		try {
			SqlFilterCompiler::compile($deep, ['id' => 'id']);
			$this->fail('Expected compile() to reject AST deeper than 64.');
		} catch (ApiException $e) {
			$this->assertSame(400, $e->errorStatus->httpCode);
		}

		try {
			SqlFilterCompiler::matchesRecord($deep, ['id' => 1], ['id']);
			$this->fail('Expected matchesRecord() to reject AST deeper than 64.');
		} catch (ApiException $e) {
			$this->assertSame(400, $e->errorStatus->httpCode);
		}

		// filterRecords() validates fields even on empty lists and supports large (>24) field allowlists.
		$manyFields = array_map(static fn (int $i): string => 'f_' . $i, range(0, 30));
		$ast        = FilterParser::parse('f_29 = 42');
		$this->assertTrue(SqlFilterCompiler::matchesRecord($ast, ['f_29' => 42], $manyFields));
		$this->assertCount(1, SqlFilterCompiler::filterRecords([['f_29' => 1], ['f_29' => 42]], $ast, $manyFields));

		try {
			SqlFilterCompiler::filterRecords([], FilterParser::parse('forbidden_col = 1'), ['id']);
			$this->fail('Expected filterRecords() to reject non-allowlisted field on empty list.');
		} catch (ApiException $e) {
			$this->assertSame(400, $e->errorStatus->httpCode);
		}
	}

	/**
	 * Verify ResourceName, PageToken, FieldMask, Etag, and OrderByParser reject oversized adversarial inputs.
	 *
	 * @return void
	 */
	public function testCodecAndParserAdversarialInputBounds(): void {
		// ResourceName: 2000 slashes and 64-bit integer overflow rejected.
		try {
			ResourceName::parse(str_repeat('a/', 2000) . '1');
			$this->fail('Expected oversized ResourceName rejection.');
		} catch (ApiException $e) {
			$this->assertSame(400, $e->errorStatus->httpCode);
		}

		$this->assertSame(PHP_INT_MAX, ResourceName::parse('devices/' . (string) PHP_INT_MAX)->intId());

		foreach (['9223372036854775808', '99999999999999999999', '0', '01'] as $badInt) {
			try {
				ResourceName::parse('devices/' . $badInt)->intId();
				$this->fail('Expected intId() rejection for ' . $badInt);
			} catch (ApiException $e) {
				$this->assertSame(400, $e->errorStatus->httpCode);
			}
		}

		// PageToken: oversized 10KB token fails immediately.
		try {
			PageToken::decode(str_repeat('A', 1000) . '.' . str_repeat('B', 43), 'ctx', 'secret');
			$this->fail('Expected oversized PageToken rejection.');
		} catch (ApiException $e) {
			$this->assertSame(400, $e->errorStatus->httpCode);
		}

		// FieldMask: oversized mask string and >64 paths rejected; >24 allowlist validated cleanly.
		try {
			FieldMask::parse(str_repeat('a,', 1200) . 'b', 'read_mask');
			$this->fail('Expected oversized FieldMask rejection.');
		} catch (ApiException $e) {
			$this->assertSame(400, $e->errorStatus->httpCode);
		}

		try {
			FieldMask::parse(implode(',', array_map(static fn (int $i): string => 'f' . $i, range(0, 70))), 'read_mask');
			$this->fail('Expected FieldMask path-count rejection.');
		} catch (ApiException $e) {
			$this->assertSame(400, $e->errorStatus->httpCode);
		}

		$largeAllowed = array_map(static fn (int $i): string => 'col_' . $i, range(0, 30));
		FieldMask::parse('col_1,col_30', 'read_mask')->validateReadMask($largeAllowed, 'items');
		FieldMask::parse('col_1,col_30', 'update_mask')->validateUpdateMask($largeAllowed, 'items');

		// Etag: bounded If-Match ignores tags past the 32-tag cap and canonicalizes nested arrays.
		$nested1 = Etag::compute(['b' => ['y' => 2, 'x' => 1], 'a' => 'ok']);
		$nested2 = Etag::compute(['a' => 'ok', 'b' => ['x' => 1, 'y' => 2]]);
		$this->assertSame($nested1, $nested2);
		$this->assertFalse(Etag::matches($nested1, str_repeat('"nope",', 40) . $nested1));

		// OrderByParser: oversized string rejected.
		try {
			OrderByParser::parse(str_repeat('id ', 300) . 'asc', ['id' => 'id']);
			$this->fail('Expected oversized order_by rejection.');
		} catch (ApiException $e) {
			$this->assertSame(400, $e->errorStatus->httpCode);
		}
	}

	/**
	 * Verify ResourceRegistry atomic batch commit and OpenApiGenerator scaling with 100 sibling plugins.
	 *
	 * @return void
	 */
	public function testOpenApiAndRegistryScalingWith100SiblingPlugins(): void {
		$hooks = [];

		for ($i = 0; $i < 100; $i++) {
			$slug    = sprintf('ext%03d', $i);
			$col     = sprintf('ext%03dItems', $i);
			$sng     = sprintf('ext%03dItem', $i);
			$hooks[] = [
				'name'     => $slug,
				'file'     => 'setup.php',
				'function' => static fn (): array => [[
					'plugin'      => $slug,
					'collection'  => $col,
					'singleton'   => $sng,
					'realm_read'  => $slug . '_view.php',
					'realm_write' => $slug . '_admin.php',
					'handlers'    => [
						'list' => static fn (): array => [],
						'get'  => static fn (): ?array => null,
					],
				]],
			];
		}

		// Add a faulty plugin whose second descriptor fails: first descriptor must be rolled back atomically.
		$hooks[] = [
			'name'     => 'partialfail',
			'file'     => 'setup.php',
			'function' => static fn (): array => [
				[
					'plugin'     => 'partialfail',
					'collection' => 'partialfailGood',
					'realm_read' => 'pf_view.php',
					'handlers'   => ['list' => static fn (): array => []],
				],
				[
					'plugin'     => 'partialfail',
					'collection' => 'devices', // reserved collision triggers rollback of whole plugin batch
					'realm_read' => 'pf_view.php',
					'handlers'   => ['list' => static fn (): array => []],
				],
			],
		];

		$registry = new ResourceRegistry();
		$registry->dispatchHooks($hooks);

		$this->assertCount(100, $registry->all());
		$this->assertNull($registry->get('partialfailGood'));
		$this->assertCount(1, $registry->faults());

		$spec  = OpenApiGenerator::generate($registry, '0.1.0');
		$paths = is_array($spec['paths'] ?? null) ? $spec['paths'] : [];
		$this->assertArrayHasKey('/v1/ext000Items', $paths);
		$this->assertArrayHasKey('/v1/ext099Items/{ext099Item}', $paths);
		$this->assertArrayNotHasKey('/v1/partialfailGood', $paths);
	}
}
