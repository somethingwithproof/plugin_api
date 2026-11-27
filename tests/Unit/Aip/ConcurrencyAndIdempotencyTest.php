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
use Somethingwithproof\Api\Aip\Etag;
use Somethingwithproof\Api\Aip\IdempotencyStore;
use Somethingwithproof\Api\Aip\OperationManager;
use Somethingwithproof\Api\Auth\TokenService;
use Somethingwithproof\Api\Extension\ResourceRegistry;
use Somethingwithproof\Api\Http\Request;
use Somethingwithproof\Api\Http\Router;
use Somethingwithproof\Api\Repository\InMemoryApiRepository;
use Somethingwithproof\Api\Settings;

/**
 * Regression tests for deterministic replay, non-duplicate mutations,
 * optimistic concurrency, and Long-Running Operation state transitions
 * grounded in Helland (ACM Queue 2012, "Idempotence Is Not a Medical Condition")
 * and Fielding (2000).
 */
final class ConcurrencyAndIdempotencyTest extends TestCase {
	/**
	 * Verify AIP-154 ETags are deterministic across key reorderings and prevent lost updates (Fielding 2000).
	 *
	 * @return void
	 */
	public function testEtagDeterminismAndLostUpdatePrevention(): void {
		$tag1 = Etag::compute(['b' => 2, 'a' => 1, 'nested' => ['y' => true, 'x' => false]]);
		$tag2 = Etag::compute(['a' => 1, 'b' => 2, 'nested' => ['x' => false, 'y' => true], 'etag' => 'ignored']);
		$this->assertSame($tag1, $tag2);

		$this->assertTrue(Etag::matches($tag1, $tag2));
		$this->assertTrue(Etag::matches($tag1, 'W/' . $tag2));
		$this->assertTrue(Etag::matches($tag1, '"other", ' . $tag2));
		$this->assertTrue(Etag::matches($tag1, '*'));
		$this->assertFalse(Etag::matches($tag1, '"stale-etag"'));

		// Concurrent modification check must reject stale ETag with 409 ABORTED.
		try {
			Etag::assertMatch($tag1, '"stale-etag"', null, 'devices/1');
			$this->fail('Expected stale ETag to throw 409 ABORTED');
		} catch (ApiException $e) {
			$this->assertSame(409, $e->errorStatus->httpCode);
			$this->assertSame(ErrorStatus::ABORTED, $e->errorStatus->status);
		}
	}

	/**
	 * Verify AIP-155 deterministic replay yields identical responses with zero duplicate mutations (Helland 2012).
	 *
	 * @return void
	 */
	public function testIdempotencyDeterministicReplayAndZeroDuplicateMutations(): void {
		$repo     = InMemoryApiRepository::withDefaultFixtures();
		$tokens   = new TokenService($repo);
		$issued   = $tokens->create(1, 'idempotency-test', ['read', 'write', 'admin'], null, [], 1700000000);
		$router   = new Router($repo, new Settings(), new ResourceRegistry(), 'secret-key-0123456789abcdef01234567');
		$reqId    = '550e8400-e29b-41d4-a716-446655440000';
		$payload1 = json_encode(['display_name' => 'Edge Site', 'city' => 'Austin'], JSON_THROW_ON_ERROR);
		$payload2 = json_encode(['city' => 'Austin', 'display_name' => 'Edge Site'], JSON_THROW_ON_ERROR);

		$initialCount = count($repo->sites);

		// First execution commits the site.
		$r1 = $router->handle(new Request(
			method: 'POST',
			path: '/v1/sites',
			query: ['request_id' => $reqId],
			headers: ['Authorization' => 'Bearer ' . $issued['token'], 'Content-Type' => 'application/json'],
			rawBody: $payload1
		), 1700000100);

		$this->assertSame(201, $r1->statusCode);
		$this->assertSame($initialCount + 1, count($repo->sites));

		// Second and third retries with equivalent JSON (even with reordered keys) replay identically without creating duplicates.
		$r2 = $router->handle(new Request(
			method: 'POST',
			path: '/v1/sites',
			headers: [
				'Authorization'   => 'Bearer ' . $issued['token'],
				'Content-Type'    => 'application/json',
				'Idempotency-Key' => $reqId,
			],
			rawBody: $payload2
		), 1700000200);

		$this->assertSame(201, $r2->statusCode);
		$this->assertSame('true', $r2->headers['X-Idempotent-Replay'] ?? null);
		$this->assertSame($r1->body, $r2->body);
		$this->assertSame($initialCount + 1, count($repo->sites));

		// Reusing the same request_id with a different payload must fail closed with 409 ABORTED.
		$r3 = $router->handle(new Request(
			method: 'POST',
			path: '/v1/sites',
			query: ['request_id' => $reqId],
			headers: ['Authorization' => 'Bearer ' . $issued['token'], 'Content-Type' => 'application/json'],
			rawBody: json_encode(['display_name' => 'Different Site', 'city' => 'Dallas'], JSON_THROW_ON_ERROR)
		), 1700000300);

		$this->assertSame(409, $r3->statusCode);
		$this->assertSame(ErrorStatus::ABORTED, $r3->body['error']['status'] ?? null);
		$this->assertSame($initialCount + 1, count($repo->sites));
	}

	/**
	 * Verify invalid UUIDv4 request_id formats are rejected with 400 INVALID_ARGUMENT.
	 *
	 * @return void
	 */
	public function testIdempotencyRejectsNonUuidV4(): void {
		$this->expectException(ApiException::class);
		IdempotencyStore::validateRequestId('not-a-uuid-v4');
	}

	/**
	 * Verify AIP-151 Long-Running Operation lifecycle, idempotent cancel, and terminal transitions.
	 *
	 * @return void
	 */
	public function testOperationManagerLifecycleAndIdempotentCancel(): void {
		$repo = InMemoryApiRepository::withDefaultFixtures();
		$ops  = new OperationManager($repo);

		$created = $ops->create(1, 'device.poll', 'devices/1', ['deviceId' => 1], 1700000000);
		$opId    = (string) $created['id'];
		$this->assertFalse($created['done']);
		$this->assertSame('operations/' . $opId, $created['name']);

		// Non-owner non-admin cannot inspect another user's operation.
		try {
			$ops->get($opId, 2, false);
			$this->fail('Expected 403 PERMISSION_DENIED for foreign user');
		} catch (ApiException $e) {
			$this->assertSame(403, $e->errorStatus->httpCode);
		}

		// Cancel transitions to done=true with CANCELLED status; repeated cancel is idempotent.
		$c1 = $ops->cancel($opId, 1, false, 1700000010);
		$c2 = $ops->cancel($opId, 1, false, 1700000020);
		$this->assertTrue($c1['done']);
		$this->assertSame(ErrorStatus::CANCELLED, $c1['error']['status'] ?? null);
		$this->assertSame($c1, $c2);

		// Completed operation cannot be cancelled after completion.
		$op2       = $ops->create(1, 'device.reindex', 'devices/1', [], 1700000030);
		$completed = $ops->complete((string) $op2['id'], ['reindexed' => true], 1700000040);
		$this->assertTrue($completed['done']);
		$this->assertSame(['reindexed' => true], $completed['response']);

		try {
			$ops->cancel((string) $op2['id'], 1, true, 1700000050);
			$this->fail('Expected FAILED_PRECONDITION cancelling completed operation');
		} catch (ApiException $e) {
			$this->assertSame(ErrorStatus::FAILED_PRECONDITION, $e->errorStatus->status);
		}

		// Failed operation records canonical google.rpc.Status error.
		$op3    = $ops->create(1, 'device.poll', 'devices/1', [], 1700000060);
		$failed = $ops->fail((string) $op3['id'], new ErrorStatus(ErrorStatus::DEADLINE_EXCEEDED, 'SNMP timeout'), 1700000070);
		$this->assertTrue($failed['done']);
		$this->assertSame(ErrorStatus::DEADLINE_EXCEEDED, $failed['error']['status'] ?? null);

		$ops->delete($opId, 1, false);
		$this->assertNull($repo->getOperation($opId));
	}
}
