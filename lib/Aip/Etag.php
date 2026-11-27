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
 * Google AIP-154 deterministic ETag generator and optimistic concurrency checker.
 */
final class Etag {
	/**
	 * Maximum number of comma-separated tags evaluated in an If-Match header.
	 */
	private const MAX_IF_MATCH_TAGS = 32;

	/**
	 * Compute a canonical strong ETag for a resource state array.
	 *
	 * Excludes any pre-existing "etag" key before canonical JSON hashing so
	 * repeated computation over the same state produces identical ETags.
	 *
	 * @param array<string, mixed> $resource Resource representation.
	 * @return string Quoted strong ETag value (e.g. "\"a1b2c3...\"").
	 */
	public static function compute(array $resource): string {
		unset($resource['etag']);
		$canonical = self::canonicalize($resource);
		$json      = json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

		return '"' . substr(hash('sha256', $json), 0, 32) . '"';
	}

	/**
	 * Strip weak prefix (W/) and surrounding quotes for constant-time comparison.
	 *
	 * @param string $etag Raw ETag header or field value.
	 * @return string
	 */
	public static function normalize(string $etag): string {
		$trimmed = trim($etag);

		if (str_starts_with($trimmed, 'W/')) {
			$trimmed = substr($trimmed, 2);
		}

		return trim($trimmed, '"');
	}

	/**
	 * Return true when a candidate If-Match or body etag matches the current ETag.
	 *
	 * Supports comma-separated If-Match lists and wildcard "*".
	 *
	 * @param string $expected Current canonical ETag for the resource.
	 * @param string $provided Client-provided If-Match header or body etag value.
	 * @return bool
	 */
	public static function matches(string $expected, string $provided): bool {
		$trimmed = trim($provided);

		if ($trimmed === '') {
			return false;
		}

		if ($trimmed === '*') {
			return true;
		}

		$normExpected = self::normalize($expected);
		$candidates   = explode(',', $trimmed, self::MAX_IF_MATCH_TAGS + 1);
		$limit        = min(count($candidates), self::MAX_IF_MATCH_TAGS);

		for ($i = 0; $i < $limit; $i++) {
			$normCandidate = self::normalize($candidates[$i]);

			if ($normCandidate !== '' && hash_equals($normExpected, $normCandidate)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Assert optimistic concurrency when either If-Match header or body etag is supplied.
	 *
	 * @param string      $currentEtag  Current computed ETag of the stored resource.
	 * @param string|null $ifMatch      Optional HTTP If-Match header value.
	 * @param string|null $bodyEtag     Optional etag field from the request body.
	 * @param string      $resourceName Canonical resource name for ResourceInfo detail.
	 * @return void
	 * @throws ApiException With 409 ABORTED when the supplied ETag does not match current state.
	 */
	public static function assertMatch(string $currentEtag, ?string $ifMatch, ?string $bodyEtag, string $resourceName): void {
		$candidate = null;

		if ($ifMatch !== null && trim($ifMatch) !== '') {
			$candidate = $ifMatch;
		} elseif ($bodyEtag !== null && trim($bodyEtag) !== '') {
			$candidate = $bodyEtag;
		}

		if ($candidate === null) {
			return;
		}

		if (!self::matches($currentEtag, $candidate)) {
			throw ApiException::aborted(
				sprintf('ETag mismatch for resource "%s". Reload the resource and retry.', $resourceName),
				[ErrorStatus::resourceInfoDetail('cacti.net/Resource', $resourceName, 'Optimistic concurrency check failed (ETag mismatch).')]
			);
		}
	}

	/**
	 * Recursively sort associative array keys for deterministic JSON hashing.
	 *
	 * @param mixed $value Arbitrary resource field value.
	 * @return mixed
	 */
	private static function canonicalize(mixed $value): mixed {
		if (!is_array($value)) {
			return $value;
		}

		if (!array_is_list($value)) {
			ksort($value, SORT_STRING);
		}

		foreach ($value as $k => $v) {
			if (is_array($v)) {
				$value[$k] = self::canonicalize($v);
			}
		}

		return $value;
	}
}
