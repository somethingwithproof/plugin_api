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
use Somethingwithproof\Api\Aip\ErrorStatus;
use Somethingwithproof\Api\Aip\FieldMask;
use Somethingwithproof\Api\Aip\FilterParser;
use Somethingwithproof\Api\Aip\OrderByParser;
use Somethingwithproof\Api\Aip\PageToken;
use Somethingwithproof\Api\Aip\ResourceName;
use Somethingwithproof\Api\Aip\SqlFilterCompiler;

/**
 * Unit tests for Google AIP core primitives (AIP-122, 132, 158, 160, 161, 193).
 */
final class AipCoreTest extends TestCase {
	/**
	 * Verify AIP-122 resource name parsing, building, and validation.
	 *
	 * @return void
	 */
	public function testResourceNameParseBuildAndValidation(): void {
		$simple = ResourceName::parse('devices/42');
		$this->assertSame('devices', $simple->collection);
		$this->assertSame('42', $simple->id);
		$this->assertSame(42, $simple->intId());
		$this->assertNull($simple->parent);
		$this->assertSame('devices/42', $simple->toString());

		$nested = ResourceName::parse('devices/42/graphs/7');
		$this->assertSame('graphs', $nested->collection);
		$this->assertSame('7', $nested->id);
		$this->assertSame('devices/42', $nested->parent);
		$this->assertSame('devices/42/graphs/7', $nested->toString());
		$this->assertSame('devices/42/graphs/7', ResourceName::deviceGraph(42, 7));

		$this->expectException(ApiException::class);
		ResourceName::parse('devices/../etc');
	}

	/**
	 * Verify non-integer leaf IDs fail intId() cleanly.
	 *
	 * @return void
	 */
	public function testResourceNameRejectsNonIntegerId(): void {
		$parsed = ResourceName::parse('plugins/api');
		$this->assertSame('api', $parsed->id);

		$this->expectException(ApiException::class);
		$parsed->intId();
	}

	/**
	 * Verify all 16 canonical AIP-193 gRPC status mappings and detail envelopes.
	 *
	 * @return void
	 */
	public function testErrorStatusCanonicalMappingsAndEnvelopes(): void {
		$this->assertSame(200, ErrorStatus::HTTP_CODES[ErrorStatus::OK]);
		$this->assertSame(400, ErrorStatus::HTTP_CODES[ErrorStatus::INVALID_ARGUMENT]);
		$this->assertSame(401, ErrorStatus::HTTP_CODES[ErrorStatus::UNAUTHENTICATED]);
		$this->assertSame(403, ErrorStatus::HTTP_CODES[ErrorStatus::PERMISSION_DENIED]);
		$this->assertSame(404, ErrorStatus::HTTP_CODES[ErrorStatus::NOT_FOUND]);
		$this->assertSame(409, ErrorStatus::HTTP_CODES[ErrorStatus::ALREADY_EXISTS]);
		$this->assertSame(409, ErrorStatus::HTTP_CODES[ErrorStatus::ABORTED]);
		$this->assertSame(429, ErrorStatus::HTTP_CODES[ErrorStatus::RESOURCE_EXHAUSTED]);
		$this->assertSame(499, ErrorStatus::HTTP_CODES[ErrorStatus::CANCELLED]);
		$this->assertSame(500, ErrorStatus::HTTP_CODES[ErrorStatus::INTERNAL]);
		$this->assertSame(501, ErrorStatus::HTTP_CODES[ErrorStatus::UNIMPLEMENTED]);
		$this->assertSame(503, ErrorStatus::HTTP_CODES[ErrorStatus::UNAVAILABLE]);
		$this->assertSame(504, ErrorStatus::HTTP_CODES[ErrorStatus::DEADLINE_EXCEEDED]);

		$ex  = ApiException::invalidArgument('Bad field', [['field' => 'hostname', 'description' => 'Required']]);
		$env = $ex->errorStatus->toEnvelope();

		$this->assertSame(400, $env['error']['code']);
		$this->assertSame('INVALID_ARGUMENT', $env['error']['status']);
		$this->assertSame('type.googleapis.com/google.rpc.BadRequest', $env['error']['details'][0]['@type']);

		$rl = ApiException::resourceExhausted('Slow down', 15, ['X-RateLimit-Remaining' => '0']);
		$this->assertSame('15', $rl->headers['Retry-After']);
		$this->assertSame('15s', $rl->errorStatus->details[0]['retryDelay']);

		$nf = ApiException::notFound('Missing', 'cacti.net/Device', 'devices/99');
		$this->assertSame('devices/99', $nf->errorStatus->details[0]['resourceName']);

		$ae = ApiException::alreadyExists('Duplicate', 'cacti.net/Site', 'sites/1');
		$this->assertSame(409, $ae->errorStatus->httpCode);
	}

	/**
	 * Verify AIP-158 PageToken clamping, HMAC signing, tamper rejection, and context binding.
	 *
	 * @return void
	 */
	public function testPageTokenEncodeDecodeAndTamperDetection(): void {
		$this->assertSame(50, PageToken::clampPageSize(null));
		$this->assertSame(50, PageToken::clampPageSize(0));
		$this->assertSame(25, PageToken::clampPageSize('25'));
		$this->assertSame(1000, PageToken::clampPageSize(5000));

		$ctx   = PageToken::contextHash('devices', 'status = 3', 'id asc');
		$token = PageToken::encode('42', $ctx, 'unit-test-secret-key-0123456789abcdef');

		$decoded = PageToken::decode($token, $ctx, 'unit-test-secret-key-0123456789abcdef');
		$this->assertSame('42', $decoded->afterId);

		// Forged signature must be rejected.
		try {
			PageToken::decode($token . 'A', $ctx, 'unit-test-secret-key-0123456789abcdef');
			$this->fail('Expected tampered page_token to throw');
		} catch (ApiException $e) {
			$this->assertSame(ErrorStatus::INVALID_ARGUMENT, $e->errorStatus->status);
		}

		// Context mismatch (different filter) must be rejected.
		$otherCtx = PageToken::contextHash('devices', 'status = 1', 'id asc');

		try {
			PageToken::decode($token, $otherCtx, 'unit-test-secret-key-0123456789abcdef');
			$this->fail('Expected mismatched context to throw');
		} catch (ApiException $e) {
			$this->assertSame(ErrorStatus::INVALID_ARGUMENT, $e->errorStatus->status);
		}

		// Negative page_size must be rejected.
		$this->expectException(ApiException::class);
		PageToken::clampPageSize(-1);
	}

	/**
	 * Verify AIP-160 FilterParser and SqlFilterCompiler across all operators and boolean logic.
	 *
	 * @return void
	 */
	public function testFilterParserAndSqlCompiler(): void {
		$ast = FilterParser::parse('(status = 3 AND hostname : "10.0") OR (NOT disabled = true AND snmp_port >= 161)');
		$this->assertNotNull($ast);

		$compiled = SqlFilterCompiler::compile($ast, [
			'status'    => 'h.status',
			'hostname'  => 'h.hostname',
			'disabled'  => 'h.disabled',
			'snmp_port' => 'h.snmp_port',
		]);

		$this->assertSame(
			'((h.status = ? AND h.hostname LIKE ?) OR ((NOT h.disabled = ?) AND h.snmp_port >= ?))',
			$compiled['sql']
		);
		$this->assertSame([3, '%10.0%', 1, 161], $compiled['params']);

		// In-memory evaluation must match equivalent semantics.
		$rec = ['status' => 3, 'hostname' => '10.0.0.1', 'disabled' => false, 'snmp_port' => 161];
		$this->assertTrue(SqlFilterCompiler::matchesRecord($ast, $rec, ['status', 'hostname', 'disabled', 'snmp_port']));

		// Null comparison compiles to IS NULL / IS NOT NULL.
		$nullAst      = FilterParser::parse('notes != null');
		$nullCompiled = SqlFilterCompiler::compile($nullAst, ['notes' => 'notes']);
		$this->assertSame('notes IS NOT NULL', $nullCompiled['sql']);
		$this->assertSame([], $nullCompiled['params']);

		// LIKE wildcards in filter values are escaped.
		$likeAst      = FilterParser::parse('hostname : "100%_test"');
		$likeCompiled = SqlFilterCompiler::compile($likeAst, ['hostname' => 'hostname']);
		$this->assertSame(['%100\\%\\_test%'], $likeCompiled['params']);
	}

	/**
	 * Verify AIP-132 OrderByParser allowlisting, SQL generation, and in-memory sorting.
	 *
	 * @return void
	 */
	public function testOrderByParserAndSorting(): void {
		$dirs = OrderByParser::parse('hostname desc, id asc', ['hostname' => 'h.hostname', 'id' => 'h.id']);
		$this->assertSame('h.hostname DESC, h.id ASC', OrderByParser::toSql($dirs, 'h.id'));

		$sorted = OrderByParser::sortRecords(
			[
				['id' => 1, 'hostname' => 'a.local'],
				['id' => 2, 'hostname' => 'c.local'],
				['id' => 3, 'hostname' => 'b.local'],
			],
			$dirs,
			'id'
		);
		$this->assertSame([2, 3, 1], array_column($sorted, 'id'));

		$this->expectException(ApiException::class);
		OrderByParser::parse('snmp_password desc', ['hostname' => 'h.hostname', 'id' => 'h.id']);
	}

	/**
	 * Verify AIP-161 FieldMask validation, extraction, and read_mask projection.
	 *
	 * @return void
	 */
	public function testFieldMaskUpdateAndReadProjection(): void {
		$mask = FieldMask::parse('description, hostname', 'update_mask');
		$mask->validateUpdateMask(['description', 'hostname', 'location'], 'devices');

		$extracted = $mask->extractAllowedFields([
			'id'          => 999,
			'description' => 'Updated',
			'hostname'    => '10.0.0.2',
			'status'      => 3,
		]);
		$this->assertSame(['description' => 'Updated', 'hostname' => '10.0.0.2'], $extracted);

		$readMask = FieldMask::parse('name,description', 'read_mask');
		$readMask->validateReadMask(['name', 'description', 'hostname'], 'devices');
		$projected = $readMask->project(['name' => 'devices/1', 'description' => 'Core', 'hostname' => '10.0.0.1']);
		$this->assertSame(['name' => 'devices/1', 'description' => 'Core'], $projected);

		// Wildcard "*" is rejected.
		$this->expectException(ApiException::class);
		FieldMask::parse('*', 'update_mask');
	}
}
