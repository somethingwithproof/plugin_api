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

namespace Somethingwithproof\Api\Aip;

use Somethingwithproof\Api\Repository\ApiRepositoryInterface;

/**
 * Google AIP-155 request_id / Idempotency-Key replay store with SHA-256
 * payload fingerprinting and 24-hour TTL.
 *
 * Implements deterministic replay semantics grounded in Helland (2012):
 * identical retries return the previously committed response without
 * re-executing side effects, while mismatched payloads under the same
 * request_id fail closed with 409 ABORTED.
 */
final class IdempotencyStore {
	/**
	 * Strict RFC 4122 UUIDv4 pattern required by AIP-155.
	 */
	public const UUID_V4_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

	/**
	 * Default retention window for idempotency records (24 hours).
	 */
	public const DEFAULT_TTL_SECONDS = 86400;

	/**
	 * Construct an IdempotencyStore backed by an API repository.
	 *
	 * @param ApiRepositoryInterface $repository Persistence adapter.
	 * @param int                    $ttlSeconds Retention period in seconds.
	 */
	public function __construct(
		private readonly ApiRepositoryInterface $repository,
		private readonly int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
	) {
	}

	/**
	 * Validate that a client-provided request_id conforms to RFC 4122 UUIDv4.
	 *
	 * @param string $requestId Candidate UUIDv4 string.
	 * @return string Normalized lowercase UUIDv4.
	 * @throws ApiException With 400 INVALID_ARGUMENT if not a valid UUIDv4.
	 */
	public static function validateRequestId(string $requestId): string {
		$trimmed = trim($requestId);

		if (strlen($trimmed) !== 36 || preg_match(self::UUID_V4_PATTERN, $trimmed) !== 1) {
			throw ApiException::invalidArgument(
				'request_id must be a valid RFC 4122 UUIDv4.',
				[['field' => 'request_id', 'description' => 'Expected lowercase/uppercase UUID version 4 format.']]
			);
		}

		return strtolower($trimmed);
	}

	/**
	 * Compute a deterministic SHA-256 fingerprint of an API mutation request.
	 *
	 * @param int                  $userId Authenticated user ID scoping the key.
	 * @param string               $method HTTP method (POST, PATCH, DELETE).
	 * @param string               $path   Normalized request path.
	 * @param array<string, mixed> $body   Decoded JSON body array.
	 * @return string 64-character lowercase SHA-256 hex digest.
	 */
	public static function fingerprint(int $userId, string $method, string $path, array $body): string {
		$canonicalBody = self::sortRecursive($body);
		$encoded       = json_encode([
			'u' => $userId,
			'm' => strtoupper($method),
			'p' => $path,
			'b' => $canonicalBody,
		], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

		return hash('sha256', $encoded);
	}

	/**
	 * Look up a prior response for a request_id and verify fingerprint equality.
	 *
	 * @param string $requestId   Validated UUIDv4 key.
	 * @param int    $userId      Authenticated user ID.
	 * @param string $method      HTTP method.
	 * @param string $path        Normalized API path.
	 * @param string $requestHash SHA-256 request fingerprint.
	 * @param int    $now         Current Unix timestamp.
	 * @return array{status_code: int, body: array<string, mixed>}|null Cached response on replay, or null if new.
	 * @throws ApiException With 409 ABORTED if the same request_id was used with a different user/method/path/payload.
	 */
	public function lookup(string $requestId, int $userId, string $method, string $path, string $requestHash, int $now): ?array {
		$scopedKey = self::scopedKey($userId, $requestId);
		$existing  = $this->repository->getIdempotencyRecord($scopedKey, $now);

		if ($existing === null) {
			return null;
		}

		if (
			!hash_equals($existing['request_hash'], $requestHash)
			|| strtoupper($existing['method']) !== strtoupper($method)
			|| $existing['path'] !== $path
		) {
			throw ApiException::aborted(
				sprintf('request_id "%s" was already used with different request parameters or payload.', $requestId),
				[ErrorStatus::badRequestDetail([['field' => 'request_id', 'description' => 'Idempotency key reused with conflicting payload.']])]
			);
		}

		try {
			$decoded = json_decode($existing['response_json'], true, 32, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			throw ApiException::internal('Corrupted idempotency cache record.');
		}

		return [
			'status_code' => $existing['status_code'],
			'body'        => is_array($decoded) ? self::normalizeAssoc($decoded) : [],
		];
	}

	/**
	 * Store a committed mutation response for future deterministic replay.
	 *
	 * @param string               $requestId    Validated UUIDv4 key.
	 * @param int                  $userId       Authenticated user ID.
	 * @param string               $method       HTTP method.
	 * @param string               $path         Normalized API path.
	 * @param string               $requestHash  SHA-256 request fingerprint.
	 * @param int                  $statusCode   HTTP status code returned by the mutation.
	 * @param array<string, mixed> $responseBody Response JSON payload.
	 * @param int                  $now          Current Unix timestamp.
	 * @return void
	 */
	public function record(
		string $requestId,
		int $userId,
		string $method,
		string $path,
		string $requestHash,
		int $statusCode,
		array $responseBody,
		int $now,
	): void {
		$scopedKey = self::scopedKey($userId, $requestId);
		$json      = json_encode($responseBody, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

		$this->repository->saveIdempotencyRecord(
			$scopedKey,
			$userId,
			strtoupper($method),
			$path,
			$requestHash,
			$statusCode,
			$json,
			$now,
			$now + $this->ttlSeconds
		);
	}

	/**
	 * Namespace idempotency keys per user so one caller cannot probe another user's cached responses.
	 *
	 * @param int    $userId    Authenticated user ID.
	 * @param string $requestId Normalized UUIDv4 string.
	 * @return string
	 */
	private static function scopedKey(int $userId, string $requestId): string {
		return $userId . ':' . strtolower($requestId);
	}

	/**
	 * Recursively sort associative array keys for stable request hashing.
	 *
	 * @param mixed $value Arbitrary JSON-compatible value.
	 * @return mixed
	 */
	private static function sortRecursive(mixed $value): mixed {
		if (!is_array($value)) {
			return $value;
		}

		if (!array_is_list($value)) {
			ksort($value, SORT_STRING);
		}

		foreach ($value as $k => $v) {
			if (is_array($v)) {
				$value[$k] = self::sortRecursive($v);
			}
		}

		return $value;
	}

	/**
	 * Normalize a decoded JSON array into a string-keyed associative map.
	 *
	 * @param array<mixed, mixed> $decoded Decoded JSON array.
	 * @return array<string, mixed>
	 */
	private static function normalizeAssoc(array $decoded): array {
		$out = [];

		foreach ($decoded as $k => $v) {
			$out[(string) $k] = $v;
		}

		return $out;
	}
}
