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
use Somethingwithproof\Api\Aip\ResourceName;
use Somethingwithproof\Api\Auth\Principal;
use Somethingwithproof\Api\Http\Request;
use Somethingwithproof\Api\Http\Response;
use Somethingwithproof\Api\Pages;

/**
 * Google AIP resource controller for WordPress-style Application Passwords (tokens/{token}).
 */
final class TokensResource implements ResourceHandlerInterface {
	/**
	 * Allowlisted readable fields accepted by read_mask.
	 *
	 * @var list<string>
	 */
	private const READABLE_FIELDS = [
		'name',
		'id',
		'userId',
		'displayName',
		'tokenPrefix',
		'scopes',
		'cidrAllowlist',
		'createTime',
		'expireTime',
		'lastUsedTime',
		'revoked',
		'revokeTime',
	];

	/**
	 * {@inheritdoc}
	 */
	public function collection(): string {
		return 'tokens';
	}

	/**
	 * {@inheritdoc}
	 */
	public function list(Request $request, ResourceContext $ctx, ?string $parentId = null): Response {
		$ctx->enforce($ctx->principal->checkAccess(Principal::SCOPE_READ, Pages::OPERATOR));

		$rows  = $ctx->repository->listTokens($ctx->principal->isAdmin ? null : $ctx->principal->userId);
		$items = array_map(static fn (array $r): array => $ctx->tokens->toAipArray($r), $rows);

		return Response::ok($ctx->paginate('tokens', $items, $request, self::READABLE_FIELDS));
	}

	/**
	 * {@inheritdoc}
	 */
	public function get(string $id, Request $request, ResourceContext $ctx, ?string $parentId = null): Response {
		$ctx->enforce($ctx->principal->checkAccess(Principal::SCOPE_READ, Pages::OPERATOR));

		$parsed = ResourceName::parse('tokens/' . $id);
		$record = $ctx->repository->findTokenById($parsed->id);

		if ($record === null) {
			throw ApiException::notFound(sprintf('Token "%s" was not found.', $parsed->id), 'cacti.net/Token', 'tokens/' . $parsed->id);
		}

		if (!$ctx->principal->isAdmin && $record['user_id'] !== $ctx->principal->userId) {
			throw ApiException::permissionDenied('You do not have permission to view this token.');
		}

		return Response::ok($ctx->projectSingle($ctx->tokens->toAipArray($record), $request, self::READABLE_FIELDS, 'tokens'));
	}

	/**
	 * {@inheritdoc}
	 */
	public function create(Request $request, ResourceContext $ctx, ?string $parentId = null): Response {
		$ctx->enforce($ctx->principal->checkAccess(Principal::SCOPE_WRITE, Pages::OPERATOR));

		$body        = $request->jsonBody($ctx->settings->maxBodyBytes);
		$displayName = is_string($body['display_name'] ?? null) ? $body['display_name'] : (is_string($body['displayName'] ?? null) ? $body['displayName'] : '');

		$scopes = [];

		if (is_array($body['scopes'] ?? null)) {
			foreach ($body['scopes'] as $s) {
				if (is_string($s)) {
					$scopes[] = $s;
				}
			}
		} else {
			$scopes = [Principal::SCOPE_READ];
		}

		// Prevent privilege escalation: a caller cannot mint a token with scopes it does not hold.
		foreach ($scopes as $requestedScope) {
			if (!$ctx->principal->hasScope(strtolower(trim($requestedScope)))) {
				throw ApiException::permissionDenied(sprintf('Cannot create a token with scope "%s" exceeding caller privileges.', $requestedScope));
			}
		}

		$cidrs = [];

		if (is_array($body['cidr_allowlist'] ?? null)) {
			foreach ($body['cidr_allowlist'] as $c) {
				if (is_string($c)) {
					$cidrs[] = $c;
				}
			}
		}

		$expiresAt = null;

		if (isset($body['ttl_days']) && is_numeric($body['ttl_days'])) {
			$ttlDays = (int) $body['ttl_days'];

			if ($ttlDays < 0 || $ttlDays > 3650) {
				throw ApiException::invalidArgument(
					'ttl_days must be between 0 and 3650.',
					[['field' => 'ttl_days', 'description' => 'Out of allowed range.']]
				);
			}

			$expiresAt = $ttlDays > 0 ? $ctx->now + ($ttlDays * 86400) : null;
		} elseif ($ctx->settings->defaultTokenTtlDays > 0) {
			$expiresAt = $ctx->now + ($ctx->settings->defaultTokenTtlDays * 86400);
		}

		$targetUserId = $ctx->principal->userId;

		if (isset($body['user_id']) && is_numeric($body['user_id']) && (int) $body['user_id'] !== $ctx->principal->userId) {
			if (!$ctx->principal->isAdmin) {
				throw ApiException::permissionDenied('Only API administrators may create tokens for other users.');
			}

			$targetUserId = (int) $body['user_id'];
		}

		$created                = $ctx->tokens->create($targetUserId, $displayName, $scopes, $expiresAt, $cidrs, $ctx->now);
		$payload                = $created['resource'];
		$payload['plainTextToken'] = $created['token'];

		return Response::created($payload);
	}

	/**
	 * {@inheritdoc}
	 */
	public function update(string $id, Request $request, ResourceContext $ctx, ?string $parentId = null): Response {
		throw ApiException::unimplemented('Tokens are immutable after creation; revoke and issue a new token.');
	}

	/**
	 * {@inheritdoc}
	 */
	public function delete(string $id, Request $request, ResourceContext $ctx, ?string $parentId = null): Response {
		$ctx->enforce($ctx->principal->checkAccess(Principal::SCOPE_WRITE, Pages::OPERATOR));

		$parsed = ResourceName::parse('tokens/' . $id);
		$ctx->tokens->delete($parsed->id, $ctx->principal->userId, $ctx->principal->isAdmin);

		return Response::noContent();
	}

	/**
	 * {@inheritdoc}
	 */
	public function customMethod(string $id, string $method, Request $request, ResourceContext $ctx, ?string $parentId = null): Response {
		if ($method !== 'revoke') {
			throw ApiException::unimplemented(sprintf('Custom method ":%s" is not supported on tokens.', $method));
		}

		$ctx->enforce($ctx->principal->checkAccess(Principal::SCOPE_WRITE, Pages::OPERATOR));

		$parsed  = ResourceName::parse('tokens/' . $id);
		$revoked = $ctx->tokens->revoke($parsed->id, $ctx->principal->userId, $ctx->principal->isAdmin, $ctx->now);

		return Response::ok($revoked);
	}
}
