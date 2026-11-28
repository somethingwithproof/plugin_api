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
use Somethingwithproof\Api\Auth\Principal;
use Somethingwithproof\Api\Http\Request;
use Somethingwithproof\Api\Http\Response;

/**
 * Google AIP read-only resource controller for Cacti pollers (pollers/{poller}).
 */
final class PollersResource implements ResourceHandlerInterface {
	/**
	 * Allowlisted filter/sort column map.
	 *
	 * @var array<string, string>
	 */
	private const SORT_MAP = [
		'id'       => 'id',
		'name'     => 'name',
		'hostname' => 'hostname',
		'status'   => 'status',
		'disabled' => 'disabled',
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
		'hostname',
		'disabled',
		'status',
		'processes',
		'threads',
		'total_time',
		'last_update',
		'etag',
	];

	/**
	 * {@inheritdoc}
	 */
	public function collection(): string {
		return 'pollers';
	}

	/**
	 * {@inheritdoc}
	 */
	public function list(Request $request, ResourceContext $ctx, ?string $parentId = null): Response {
		$ctx->enforce($ctx->principal->checkAccess(Principal::SCOPE_READ, 'pollers.php'));

		$filter  = FilterParser::parse($request->queryParam('filter') ?? '');
		$orderBy = OrderByParser::parse($request->queryParam('order_by') ?? '', self::SORT_MAP);
		$rows    = $ctx->repository->listPollers($filter, $orderBy);
		$items   = array_map([self::class, 'serialize'], $rows);

		return Response::ok($ctx->paginate('pollers', $items, $request, self::READABLE_FIELDS));
	}

	/**
	 * {@inheritdoc}
	 */
	public function get(string $id, Request $request, ResourceContext $ctx, ?string $parentId = null): Response {
		$ctx->enforce($ctx->principal->checkAccess(Principal::SCOPE_READ, 'pollers.php'));

		$intId = ResourceName::parse('pollers/' . $id)->intId();
		$row   = $ctx->repository->getPoller($intId);

		if ($row === null) {
			throw ApiException::notFound(sprintf('Poller "%d" was not found.', $intId), 'cacti.net/Poller', 'pollers/' . $intId);
		}

		$serialized = self::serialize($row);
		$etag       = is_string($serialized['etag']) ? $serialized['etag'] : '';

		return Response::ok(
			$ctx->projectSingle($serialized, $request, self::READABLE_FIELDS, 'pollers'),
			['ETag' => $etag]
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function create(Request $request, ResourceContext $ctx, ?string $parentId = null): Response {
		throw ApiException::unimplemented('Pollers are read-only via the v1 REST API.');
	}

	/**
	 * {@inheritdoc}
	 */
	public function update(string $id, Request $request, ResourceContext $ctx, ?string $parentId = null): Response {
		throw ApiException::unimplemented('Pollers are read-only via the v1 REST API.');
	}

	/**
	 * {@inheritdoc}
	 */
	public function delete(string $id, Request $request, ResourceContext $ctx, ?string $parentId = null): Response {
		throw ApiException::unimplemented('Pollers are read-only via the v1 REST API.');
	}

	/**
	 * {@inheritdoc}
	 */
	public function customMethod(string $id, string $method, Request $request, ResourceContext $ctx, ?string $parentId = null): Response {
		throw ApiException::unimplemented(sprintf('Custom method ":%s" is not supported on pollers.', $method));
	}

	/**
	 * Serialize a database poller row into its canonical AIP resource representation.
	 *
	 * Never exposes poller database credentials (dbuser/dbpass).
	 *
	 * @param array<string, mixed> $row Raw repository poller row.
	 * @return array<string, mixed>
	 */
	public static function serialize(array $row): array {
		$id       = is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
		$resource = [
			'name'         => ResourceName::build('pollers', $id),
			'id'           => $id,
			'display_name' => is_scalar($row['name'] ?? null) ? (string) $row['name'] : '',
			'hostname'     => is_scalar($row['hostname'] ?? null) ? (string) $row['hostname'] : '',
			'disabled'     => ($row['disabled'] ?? '') === 'on',
			'status'       => is_numeric($row['status'] ?? null) ? (int) $row['status'] : 0,
			'processes'    => is_numeric($row['processes'] ?? null) ? (int) $row['processes'] : 1,
			'threads'      => is_numeric($row['threads'] ?? null) ? (int) $row['threads'] : 1,
			'total_time'   => is_numeric($row['total_time'] ?? null) ? (float) $row['total_time'] : 0.0,
			'last_update'  => is_scalar($row['last_update'] ?? null) ? (string) $row['last_update'] : '',
		];
		$resource['etag'] = Etag::compute($resource);

		return $resource;
	}
}
