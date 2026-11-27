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

/**
 * Google AIP-158 opaque HMAC-signed base64url cursor pagination codec.
 */
final class PageToken {
	/**
	 * Default page size when omitted by the client.
	 */
	public const DEFAULT_PAGE_SIZE = 50;

	/**
	 * Maximum permitted page size per AIP-158.
	 */
	public const MAX_PAGE_SIZE = 1000;

	/**
	 * Construct a decoded pagination cursor.
	 *
	 * @param string $afterId    Last seen resource ID cursor boundary.
	 * @param string $contextKey Hash of filter and order_by to prevent cursor reuse across mismatched queries.
	 */
	public function __construct(
		public readonly string $afterId,
		public readonly string $contextKey,
	) {
	}

	/**
	 * Validate and clamp a client-requested page_size to [1, MAX_PAGE_SIZE].
	 *
	 * @param int|string|null $raw     Raw query parameter value.
	 * @param int             $default Default page size when omitted or zero.
	 * @param int             $max     Upper bound on returned resources.
	 * @return int
	 * @throws ApiException If page_size is negative or non-numeric.
	 */
	public static function clampPageSize(int|string|null $raw, int $default = self::DEFAULT_PAGE_SIZE, int $max = self::MAX_PAGE_SIZE): int {
		if ($raw === null || $raw === '') {
			return $default;
		}

		if (is_string($raw) && preg_match('/^-?[0-9]+$/', trim($raw)) !== 1) {
			throw ApiException::invalidArgument(
				'page_size must be a non-negative integer.',
				[['field' => 'page_size', 'description' => 'Expected an integer value.']]
			);
		}

		$size = (int) $raw;

		if ($size < 0) {
			throw ApiException::invalidArgument(
				'page_size must not be negative.',
				[['field' => 'page_size', 'description' => 'Must be >= 0.']]
			);
		}

		if ($size === 0) {
			return $default;
		}

		return min($max, $size);
	}

	/**
	 * Compute a deterministic context hash binding filter and order_by to a page token.
	 *
	 * @param string $collection Collection identifier.
	 * @param string $filter     Raw AIP-160 filter expression.
	 * @param string $orderBy    Raw AIP-132 order_by expression.
	 * @return string
	 */
	public static function contextHash(string $collection, string $filter, string $orderBy): string {
		return hash('sha256', $collection . "\0" . $filter . "\0" . $orderBy);
	}

	/**
	 * Encode and HMAC-sign a pagination cursor as an opaque base64url token.
	 *
	 * @param string $afterId    Last returned item identifier.
	 * @param string $contextKey Context hash from contextHash().
	 * @param string $secret     Server-side HMAC key.
	 * @return string
	 */
	public static function encode(string $afterId, string $contextKey, string $secret): string {
		$payload = json_encode(['a' => $afterId, 'c' => substr($contextKey, 0, 16)], JSON_THROW_ON_ERROR);
		$sig     = hash_hmac('sha256', $payload, $secret, true);

		return self::base64UrlEncode($payload) . '.' . self::base64UrlEncode($sig);
	}

	/**
	 * Decode and verify an HMAC-signed opaque page_token against the current query context.
	 *
	 * @param string $token      Opaque page_token received from client.
	 * @param string $contextKey Expected context hash for the current query.
	 * @param string $secret     Server-side HMAC key.
	 * @return self
	 * @throws ApiException If the token is malformed, forged, or used with different query parameters.
	 */
	public static function decode(string $token, string $contextKey, string $secret): self {
		$parts = explode('.', $token, 2);

		if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
			throw ApiException::invalidArgument(
				'Invalid page_token.',
				[['field' => 'page_token', 'description' => 'Token is malformed.']]
			);
		}

		$payload = self::base64UrlDecode($parts[0]);
		$sig     = self::base64UrlDecode($parts[1]);

		if ($payload === null || $sig === null) {
			throw ApiException::invalidArgument(
				'Invalid page_token.',
				[['field' => 'page_token', 'description' => 'Token encoding is invalid.']]
			);
		}

		$expectedSig = hash_hmac('sha256', $payload, $secret, true);

		if (!hash_equals($expectedSig, $sig)) {
			throw ApiException::invalidArgument(
				'Invalid page_token.',
				[['field' => 'page_token', 'description' => 'Token signature verification failed.']]
			);
		}

		try {
			$decoded = json_decode($payload, true, 8, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			throw ApiException::invalidArgument(
				'Invalid page_token.',
				[['field' => 'page_token', 'description' => 'Token payload is corrupted.']]
			);
		}

		if (!is_array($decoded) || !is_string($decoded['a'] ?? null) || !is_string($decoded['c'] ?? null)) {
			throw ApiException::invalidArgument(
				'Invalid page_token.',
				[['field' => 'page_token', 'description' => 'Token payload is incomplete.']]
			);
		}

		$expectedCtx = substr($contextKey, 0, 16);

		if (!hash_equals($expectedCtx, $decoded['c'])) {
			throw ApiException::invalidArgument(
				'page_token does not match the current filter or order_by parameters.',
				[['field' => 'page_token', 'description' => 'Cursor context mismatch.']]
			);
		}

		return new self($decoded['a'], $decoded['c']);
	}

	/**
	 * Encode raw bytes to unpadded base64url.
	 *
	 * @param string $data Binary input string.
	 * @return string
	 */
	private static function base64UrlEncode(string $data): string {
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}

	/**
	 * Decode an unpadded base64url string or return null if invalid.
	 *
	 * @param string $data Base64url string.
	 * @return string|null
	 */
	private static function base64UrlDecode(string $data): ?string {
		if (preg_match('/^[A-Za-z0-9_-]+$/', $data) !== 1) {
			return null;
		}

		$pad     = (4 - (strlen($data) % 4)) % 4;
		$decoded = base64_decode(strtr($data, '-_', '+/') . str_repeat('=', $pad), true);

		return $decoded === false ? null : $decoded;
	}
}
