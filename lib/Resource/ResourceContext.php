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

namespace Somethingwithproof\Api\Resource;

use Somethingwithproof\Api\Aip\ApiException;
use Somethingwithproof\Api\Aip\FieldMask;
use Somethingwithproof\Api\Aip\OperationManager;
use Somethingwithproof\Api\Aip\PageToken;
use Somethingwithproof\Api\Auth\AccessResult;
use Somethingwithproof\Api\Auth\Principal;
use Somethingwithproof\Api\Auth\TokenService;
use Somethingwithproof\Api\Http\Request;
use Somethingwithproof\Api\Repository\ApiRepositoryInterface;
use Somethingwithproof\Api\Settings;

/**
 * Shared execution context passed to every resource controller method.
 */
final class ResourceContext {
	/**
	 * Construct a ResourceContext.
	 *
	 * @param ApiRepositoryInterface $repository      Persistence repository.
	 * @param Principal              $principal       Authenticated caller identity.
	 * @param Settings               $settings        Active plugin settings.
	 * @param OperationManager       $operations      AIP-151 LRO manager.
	 * @param TokenService           $tokens          Application Password service.
	 * @param string                 $pageTokenSecret HMAC key for opaque AIP-158 cursors.
	 * @param int                    $now             Request Unix timestamp.
	 */
	public function __construct(
		public readonly ApiRepositoryInterface $repository,
		public readonly Principal $principal,
		public readonly Settings $settings,
		public readonly OperationManager $operations,
		public readonly TokenService $tokens,
		public readonly string $pageTokenSecret,
		public readonly int $now,
	) {
	}

	/**
	 * Enforce an AccessResult decision, throwing 403 PERMISSION_DENIED unless explicitly allowed.
	 *
	 * @param AccessResult $result Composed access decision.
	 * @return void
	 * @throws ApiException With 403 PERMISSION_DENIED when access is forbidden or neutral.
	 */
	public function enforce(AccessResult $result): void {
		if (!$result->isAllowed()) {
			$reason = $result->reason() !== '' ? $result->reason() : 'Permission denied.';

			throw ApiException::permissionDenied($reason);
		}
	}

	/**
	 * Paginate an ordered list of serialized resources using AIP-158 cursor tokens.
	 *
	 * @param string                     $collection Collection name for response field and token context.
	 * @param list<array<string, mixed>> $items      Fully filtered and sorted resource arrays (must include "id").
	 * @param Request                    $request    Incoming HTTP request.
	 * @param list<string>               $readable   Allowlisted readable fields for optional read_mask projection.
	 * @return array<string, mixed> AIP-158 list envelope with collection key, nextPageToken, and totalSize.
	 */
	public function paginate(string $collection, array $items, Request $request, array $readable): array {
		$pageSize  = PageToken::clampPageSize($request->queryParam('page_size'));
		$filterRaw = $request->queryParam('filter') ?? '';
		$orderRaw  = $request->queryParam('order_by') ?? '';
		$tokenRaw  = $request->queryParam('page_token');

		$readMask = FieldMask::parse($request->queryParam('read_mask'), 'read_mask');

		if (!$readMask->isEmpty()) {
			$readMask->validateReadMask($readable, $collection);
		}

		$totalSize  = count($items);
		$startIdx   = 0;
		$contextKey = null;

		if ($tokenRaw !== null && $tokenRaw !== '') {
			$contextKey = PageToken::contextHash($collection, $filterRaw, $orderRaw);
			$cursor     = PageToken::decode($tokenRaw, $contextKey, $this->pageTokenSecret);
			$foundAt    = null;

			foreach ($items as $idx => $item) {
				$itemId = is_scalar($item['id'] ?? null) ? (string) $item['id'] : (is_string($item['name'] ?? null) ? $item['name'] : '');

				if ($itemId === $cursor->afterId) {
					$foundAt = $idx + 1;

					break;
				}
			}

			$startIdx = $foundAt ?? $totalSize;
		}

		$slice   = array_slice($items, $startIdx, $pageSize);
		$hasMore = ($startIdx + count($slice)) < $totalSize;

		$nextPageToken = '';

		if ($hasMore && $slice !== []) {
			$contextKey    ??= PageToken::contextHash($collection, $filterRaw, $orderRaw);
			$last            = $slice[count($slice) - 1];
			$lastId          = is_scalar($last['id'] ?? null) ? (string) $last['id'] : (is_string($last['name'] ?? null) ? $last['name'] : '');
			$nextPageToken   = PageToken::encode($lastId, $contextKey, $this->pageTokenSecret);
		}

		if (!$readMask->isEmpty()) {
			$slice = array_map(static fn (array $item): array => $readMask->project($item), $slice);
		}

		return [
			$collection     => $slice,
			'nextPageToken' => $nextPageToken,
			'totalSize'     => $totalSize,
		];
	}

	/**
	 * Apply optional read_mask query projection to a single resource payload.
	 *
	 * @param array<string, mixed> $resource  Full serialized resource array.
	 * @param Request              $request   Incoming HTTP request.
	 * @param list<string>         $readable  Allowlisted readable fields.
	 * @param string               $label     Resource label for error reporting.
	 * @return array<string, mixed>
	 */
	public function projectSingle(array $resource, Request $request, array $readable, string $label): array {
		$readMask = FieldMask::parse($request->queryParam('read_mask'), 'read_mask');

		if ($readMask->isEmpty()) {
			return $resource;
		}

		$readMask->validateReadMask($readable, $label);

		return $readMask->project($resource);
	}

	/**
	 * Extract update_mask from query parameter or request body and validate against mutable fields.
	 *
	 * @param Request              $request       Incoming HTTP request.
	 * @param array<string, mixed> $body          Decoded JSON body.
	 * @param list<string>         $mutableFields Allowlisted mutable fields.
	 * @param string               $label         Resource label for error messages.
	 * @return FieldMask
	 */
	public function resolveUpdateMask(Request $request, array $body, array $mutableFields, string $label): FieldMask {
		$raw = $request->queryParam('update_mask');

		if (($raw === null || $raw === '') && isset($body['update_mask'])) {
			if (is_string($body['update_mask'])) {
				$raw = $body['update_mask'];
			} elseif (is_array($body['update_mask'])) {
				$paths = [];

				foreach ($body['update_mask'] as $p) {
					if (is_string($p)) {
						$paths[] = $p;
					}
				}
				$mask = FieldMask::parse($paths, 'update_mask');
				$mask->validateUpdateMask($mutableFields, $label);

				return $mask;
			}
		}

		$mask = FieldMask::parse($raw, 'update_mask');
		$mask->validateUpdateMask($mutableFields, $label);

		return $mask;
	}
}
