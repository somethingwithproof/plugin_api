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

namespace Somethingwithproof\Api\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Somethingwithproof\Api\Aip\ApiException;
use Somethingwithproof\Api\Aip\ErrorStatus;
use Somethingwithproof\Api\Auth\AccessResult;
use Somethingwithproof\Api\Auth\Principal;
use Somethingwithproof\Api\Auth\RateLimiter;
use Somethingwithproof\Api\Auth\TokenService;
use Somethingwithproof\Api\Repository\InMemoryApiRepository;

/**
 * Unit tests for Drupal-style AccessResult, Application Passwords TokenService,
 * and fixed-window RateLimiter.
 */
final class AuthAndRateLimitTest extends TestCase {
	/**
	 * Verify Drupal AccessResult composition semantics (forbidden always dominates).
	 *
	 * @return void
	 */
	public function testAccessResultComposition(): void {
		$a = AccessResult::allowed();
		$f = AccessResult::forbidden('Explicit deny');
		$n = AccessResult::neutral('No opinion');

		$this->assertTrue($a->andIf($a)->isAllowed());
		$this->assertTrue($a->andIf($n)->isNeutral());
		$this->assertTrue($a->andIf($f)->isForbidden());
		$this->assertTrue($f->andIf($a)->isForbidden());

		$this->assertTrue($n->orIf($a)->isAllowed());
		$this->assertTrue($a->orIf($f)->isForbidden());
		$this->assertTrue($f->orIf($a)->isForbidden());
		$this->assertTrue($n->orIf($n)->isNeutral());
		$this->assertSame('Explicit deny', $a->orIf($f)->reason());

		$this->assertTrue(AccessResult::allowedIf(true)->isAllowed());
		$this->assertTrue(AccessResult::allowedIf(false)->isNeutral());
		$this->assertTrue(AccessResult::forbiddenIf(true, 'Blocked')->isForbidden());
		$this->assertTrue(AccessResult::forbiddenIf(false)->isNeutral());
	}

	/**
	 * Verify Application Password lifecycle: issue, SHA-256 at rest, CIDR allowlist, expiry, revocation.
	 *
	 * @return void
	 */
	public function testTokenServiceLifecycleAndSecurityControls(): void {
		$repo    = InMemoryApiRepository::withDefaultFixtures();
		$service = new TokenService($repo);
		$now     = 1700000000;

		$created = $service->create(
			1,
			'CI Automation',
			['read', 'write'],
			$now + 3600,
			['10.0.0.0/8', '2001:db8::/32'],
			$now
		);

		$rawToken = $created['token'];
		$tokenId  = (string) $created['resource']['id'];
		$this->assertMatchesRegularExpression(TokenService::TOKEN_PATTERN, $rawToken);

		// Raw token must never be stored in plaintext; only SHA-256 hash is stored.
		$stored = $repo->findTokenById($tokenId);
		$this->assertNotNull($stored);
		$this->assertSame(hash('sha256', $rawToken), $stored['token_hash']);
		$this->assertArrayNotHasKey('token_hash', $created['resource']);

		// Valid IPv4 and IPv6 clients authenticate and update last_used_at.
		$p1 = $service->authenticate($rawToken, '10.24.1.5', $now + 10);
		$this->assertSame(1, $p1->userId);
		$this->assertTrue($p1->hasScope(Principal::SCOPE_WRITE));
		$this->assertTrue($p1->hasScope(Principal::SCOPE_READ));
		$this->assertFalse($p1->hasScope(Principal::SCOPE_ADMIN));
		$this->assertSame($now + 10, $repo->findTokenById($tokenId)['last_used_at'] ?? null);

		$p2 = $service->authenticate($rawToken, '2001:db8::1', $now + 20);
		$this->assertSame(1, $p2->userId);

		// Outside CIDR allowlist fails with 403 PERMISSION_DENIED.
		try {
			$service->authenticate($rawToken, '192.168.1.50', $now + 30);
			$this->fail('Expected CIDR rejection');
		} catch (ApiException $e) {
			$this->assertSame(403, $e->errorStatus->httpCode);
		}

		// Expired token fails with 401 UNAUTHENTICATED.
		try {
			$service->authenticate($rawToken, '10.0.0.1', $now + 7200);
			$this->fail('Expected expiry rejection');
		} catch (ApiException $e) {
			$this->assertSame(401, $e->errorStatus->httpCode);
		}

		// Revoked token fails with 401 UNAUTHENTICATED.
		$service->revoke($tokenId, 1, false, $now + 50);

		try {
			$service->authenticate($rawToken, '10.0.0.1', $now + 60);
			$this->fail('Expected revoked token rejection');
		} catch (ApiException $e) {
			$this->assertSame(401, $e->errorStatus->httpCode);
		}

		// Disabled Cacti user cannot authenticate even with an otherwise active token.
		$disabledTok = $service->create(3, 'Disabled User Token', ['read'], null, [], $now);

		try {
			$service->authenticate($disabledTok['token'], '127.0.0.1', $now + 1);
			$this->fail('Expected disabled account rejection');
		} catch (ApiException $e) {
			$this->assertSame(401, $e->errorStatus->httpCode);
		}
	}

	/**
	 * Verify RateLimiter tracks per-window hits and returns 429 RESOURCE_EXHAUSTED with Retry-After.
	 *
	 * @return void
	 */
	public function testRateLimiterWindowEnforcement(): void {
		$repo    = new InMemoryApiRepository();
		$limiter = new RateLimiter($repo);
		$now     = 1700000015;

		$h1 = $limiter->consume('token:abc', 2, $now);
		$this->assertSame('2', $h1['X-RateLimit-Limit']);
		$this->assertSame('1', $h1['X-RateLimit-Remaining']);

		$h2 = $limiter->consume('token:abc', 2, $now + 5);
		$this->assertSame('0', $h2['X-RateLimit-Remaining']);

		try {
			$limiter->consume('token:abc', 2, $now + 10);
			$this->fail('Expected 429 RESOURCE_EXHAUSTED');
		} catch (ApiException $e) {
			$this->assertSame(429, $e->errorStatus->httpCode);
			$this->assertSame(ErrorStatus::RESOURCE_EXHAUSTED, $e->errorStatus->status);
			$this->assertArrayHasKey('Retry-After', $e->headers);
		}

		// Next 60-second window resets the counter cleanly.
		$hNext = $limiter->consume('token:abc', 2, $now + 65);
		$this->assertSame('1', $hNext['X-RateLimit-Remaining']);
	}
}
