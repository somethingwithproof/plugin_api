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
 * Google AIP read-only resource controller for Cacti data sources (dataSources/{data_source}).
 */
final class DataSourcesResource implements ResourceHandlerInterface {
	/**
	 * Allowlisted filter/sort column map.
	 *
	 * @var array<string, string>
	 */
	private const SORT_MAP = [
		'id'               => 'dl.id',
		'host_id'          => 'dl.host_id',
		'data_template_id' => 'dl.data_template_id',
		'name_cache'       => 'dtd.name_cache',
		'active'           => 'dtd.active',
	];

	/**
	 * Allowlisted readable fields accepted by read_mask.
	 *
	 * @var list<string>
	 */
	private const READABLE_FIELDS = [
		'name',
		'id',
		'device',
		'host_id',
		'data_template_id',
		'display_name',
		'active',
		'rrd_step',
		'etag',
	];

	/**
	 * {@inheritdoc}
	 */
	public function collection(): string {
		return 'dataSources';
	}

	/**
	 * {@inheritdoc}
	 */
	public function list(Request $request, ResourceContext $ctx, ?string $parentId = null): Response {
		$ctx->enforce($ctx->principal->checkAccess(Principal::SCOPE_READ, 'data_sources.php'));

		$filter  = FilterParser::parse($request->queryParam('filter') ?? '');
		$orderBy = OrderByParser::parse($request->queryParam('order_by') ?? '', self::SORT_MAP);
		$rows    = $ctx->repository->listDataSources($filter, $orderBy);
		$items   = array_map([self::class, 'serialize'], $rows);

		return Response::ok($ctx->paginate('dataSources', $items, $request, self::READABLE_FIELDS));
	}

	/**
	 * {@inheritdoc}
	 */
	public function get(string $id, Request $request, ResourceContext $ctx, ?string $parentId = null): Response {
		$ctx->enforce($ctx->principal->checkAccess(Principal::SCOPE_READ, 'data_sources.php'));

		$intId = ResourceName::parse('dataSources/' . $id)->intId();
		$row   = $ctx->repository->getDataSource($intId);

		if ($row === null) {
			throw ApiException::notFound(sprintf('Data source "%d" was not found.', $intId), 'cacti.net/DataSource', 'dataSources/' . $intId);
		}

		$serialized = self::serialize($row);
		$etag       = is_string($serialized['etag']) ? $serialized['etag'] : '';

		return Response::ok(
			$ctx->projectSingle($serialized, $request, self::READABLE_FIELDS, 'dataSources'),
			['ETag' => $etag]
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function create(Request $request, ResourceContext $ctx, ?string $parentId = null): Response {
		throw ApiException::unimplemented('Data sources are read-only via the v1 REST API.');
	}

	/**
	 * {@inheritdoc}
	 */
	public function update(string $id, Request $request, ResourceContext $ctx, ?string $parentId = null): Response {
		throw ApiException::unimplemented('Data sources are read-only via the v1 REST API.');
	}

	/**
	 * {@inheritdoc}
	 */
	public function delete(string $id, Request $request, ResourceContext $ctx, ?string $parentId = null): Response {
		throw ApiException::unimplemented('Data sources are read-only via the v1 REST API.');
	}

	/**
	 * {@inheritdoc}
	 */
	public function customMethod(string $id, string $method, Request $request, ResourceContext $ctx, ?string $parentId = null): Response {
		throw ApiException::unimplemented(sprintf('Custom method ":%s" is not supported on dataSources.', $method));
	}

	/**
	 * Serialize a database data source row into its canonical AIP resource representation.
	 *
	 * @param array<string, mixed> $row Raw repository data source row.
	 * @return array<string, mixed>
	 */
	public static function serialize(array $row): array {
		$id       = is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
		$hostId   = is_numeric($row['host_id'] ?? null) ? (int) $row['host_id'] : 0;
		$resource = [
			'name'             => ResourceName::build('dataSources', $id),
			'id'               => $id,
			'device'           => ResourceName::build('devices', $hostId),
			'host_id'          => $hostId,
			'data_template_id' => is_numeric($row['data_template_id'] ?? null) ? (int) $row['data_template_id'] : 0,
			'display_name'     => is_scalar($row['name_cache'] ?? null) ? (string) $row['name_cache'] : '',
			'active'           => ($row['active'] ?? '') === 'on',
			'rrd_step'         => is_numeric($row['rrd_step'] ?? null) ? (int) $row['rrd_step'] : 300,
		];
		$resource['etag'] = Etag::compute($resource);

		return $resource;
	}
}
