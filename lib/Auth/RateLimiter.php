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

namespace Somethingwithproof\Api\Auth;

use Somethingwithproof\Api\Aip\ApiException;
use Somethingwithproof\Api\Repository\ApiRepositoryInterface;

/**
 * Fixed-window per-token and per-IP API rate limiter.
 */
final class RateLimiter {
	/**
	 * Window length in seconds.
	 */
	public const WINDOW_SECONDS = 60;

	/**
	 * Construct a RateLimiter backed by a repository.
	 *
	 * @param ApiRepositoryInterface $repository Persistence adapter.
	 */
	public function __construct(private readonly ApiRepositoryInterface $repository) {
	}

	/**
	 * Consume one request token for a subject bucket and return X-RateLimit headers.
	 *
	 * @param string $subject Subject key (e.g. "token:<uuid>" or "ip:<addr>").
	 * @param int    $limit   Maximum requests permitted per 60-second window.
	 * @param int    $now     Current Unix timestamp.
	 * @return array<string, string> Rate limit response headers.
	 * @throws ApiException With 429 RESOURCE_EXHAUSTED when the limit is exceeded.
	 */
	public function consume(string $subject, int $limit, int $now): array {
		$effectiveLimit = max(1, $limit);
		$windowStart    = $now - ($now % self::WINDOW_SECONDS);
		$resetAt        = $windowStart + self::WINDOW_SECONDS;
		$bucketKey      = hash('sha256', $subject . ':' . $windowStart);

		$count     = $this->repository->incrementRateLimitBucket($bucketKey, $windowStart);
		$remaining = max(0, $effectiveLimit - $count);

		$headers = [
			'X-RateLimit-Limit'     => (string) $effectiveLimit,
			'X-RateLimit-Remaining' => (string) $remaining,
			'X-RateLimit-Reset'     => (string) $resetAt,
		];

		if ($count > $effectiveLimit) {
			$retryAfter = max(1, $resetAt - $now);

			throw ApiException::resourceExhausted(
				sprintf('Rate limit of %d requests per minute exceeded.', $effectiveLimit),
				$retryAfter,
				$headers
			);
		}

		return $headers;
	}
}
