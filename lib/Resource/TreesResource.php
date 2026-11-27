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
use Somethingwithproof\Api\Aip\Etag;
use Somethingwithproof\Api\Aip\FilterParser;
use Somethingwithproof\Api\Aip\OrderByParser;
use Somethingwithproof\Api\Aip\ResourceName;
use Somethingwithproof\Api\Auth\AccessResult;
use Somethingwithproof\Api\Auth\Principal;
use Somethingwithproof\Api\Http\Request;
use Somethingwithproof\Api\Http\Response;

/**
 * Google AIP read-only resource controller for Cacti graph trees (trees/{tree}).
 */
final class TreesResource implements ResourceHandlerInterface {
	/**
	 * Allowlisted filter/sort column map.
	 *
	 * @var array<string, string>
	 */
	private const SORT_MAP = [
		'id'        => 'id',
		'name'      => 'name',
		'enabled'   => 'enabled',
		'sort_type' => 'sort_type',
		'sequence'  => 'sequence',
	];

	/**
	 * Allowlisted readable fields accepted by read_mask.
	 *
	 * @var list<string>
	 */
	private const READABLE_FIELDS = [
		'name',
		'id',
		'display_name',
		'enabled',
		'sort_type',
		'sequence',
		'etag',
	];

	/**
	 * {@inheritdoc}
	 */
	public function collection(): string {
		return 'trees';
	}

	/**
	 * {@inheritdoc}
	 */
	public function list(Request $request, ResourceContext $ctx, ?string $parentId = null): Response {
		$ctx->enforce($ctx->principal->checkAccess(Principal::SCOPE_READ, 'tree.php'));

		$filter  = FilterParser::parse($request->queryParam('filter') ?? '');
		$orderBy = OrderByParser::parse($request->queryParam('order_by') ?? '', self::SORT_MAP);
		$rows    = $ctx->repository->listTrees($filter, $orderBy);

		$visible = [];

		foreach ($rows as $row) {
			$treeId = is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;

			if ($ctx->principal->isAdmin || $ctx->repository->isTreeAllowedForUser($ctx->principal->userId, $treeId)) {
				$visible[] = self::serialize($row);
			}
		}

		return Response::ok($ctx->paginate('trees', $visible, $request, self::READABLE_FIELDS));
	}

	/**
	 * {@inheritdoc}
	 */
	public function get(string $id, Request $request, ResourceContext $ctx, ?string $parentId = null): Response {
		$intId      = ResourceName::parse('trees/' . $id)->intId();
		$realmCheck = $ctx->principal->checkAccess(Principal::SCOPE_READ, 'tree.php');
		$aclCheck   = $ctx->principal->isAdmin
			? AccessResult::allowed()
			: AccessResult::allowedIf(
				$ctx->repository->isTreeAllowedForUser($ctx->principal->userId, $intId),
				sprintf('User does not have object access to tree "%d".', $intId)
			);
		$ctx->enforce($realmCheck->andIf($aclCheck));

		$row = $ctx->repository->getTree($intId);

		if ($row === null) {
			throw ApiException::notFound(sprintf('Tree "%d" was not found.', $intId), 'cacti.net/Tree', 'trees/' . $intId);
		}

		$serialized = self::serialize($row);
		$etag       = is_string($serialized['etag']) ? $serialized['etag'] : '';

		return Response::ok(
			$ctx->projectSingle($serialized, $request, self::READABLE_FIELDS, 'trees'),
			['ETag' => $etag]
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function create(Request $request, ResourceContext $ctx, ?string $parentId = null): Response {
		throw ApiException::unimplemented('Trees are read-only via the v1 REST API.');
	}

	/**
	 * {@inheritdoc}
	 */
	public function update(string $id, Request $request, ResourceContext $ctx, ?string $parentId = null): Response {
		throw ApiException::unimplemented('Trees are read-only via the v1 REST API.');
	}

	/**
	 * {@inheritdoc}
	 */
	public function delete(string $id, Request $request, ResourceContext $ctx, ?string $parentId = null): Response {
		throw ApiException::unimplemented('Trees are read-only via the v1 REST API.');
	}

	/**
	 * {@inheritdoc}
	 */
	public function customMethod(string $id, string $method, Request $request, ResourceContext $ctx, ?string $parentId = null): Response {
		throw ApiException::unimplemented(sprintf('Custom method ":%s" is not supported on trees.', $method));
	}

	/**
	 * Serialize a database graph_tree row into its canonical AIP resource representation.
	 *
	 * @param array<string, mixed> $row Raw repository tree row.
	 * @return array<string, mixed>
	 */
	public static function serialize(array $row): array {
		$id       = is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
		$resource = [
			'name'         => ResourceName::build('trees', $id),
			'id'           => $id,
			'display_name' => is_scalar($row['name'] ?? null) ? (string) $row['name'] : '',
			'enabled'      => ($row['enabled'] ?? '') === 'on',
			'sort_type'    => is_numeric($row['sort_type'] ?? null) ? (int) $row['sort_type'] : 1,
			'sequence'     => is_numeric($row['sequence'] ?? null) ? (int) $row['sequence'] : 1,
		];
		$resource['etag'] = Etag::compute($resource);

		return $resource;
	}
}
