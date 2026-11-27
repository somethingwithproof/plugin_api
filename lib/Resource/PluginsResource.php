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
 * Google AIP read-only resource controller for installed Cacti plugins (plugins/{plugin}).
 */
final class PluginsResource implements ResourceHandlerInterface {
	/**
	 * Allowlisted filter/sort column map.
	 *
	 * @var array<string, string>
	 */
	private const SORT_MAP = [
		'id'        => 'id',
		'directory' => 'directory',
		'name'      => 'name',
		'status'    => 'status',
		'version'   => 'version',
	];

	/**
	 * Allowlisted readable fields accepted by read_mask.
	 *
	 * @var list<string>
	 */
	private const READABLE_FIELDS = [
		'name',
		'id',
		'directory',
		'display_name',
		'status',
		'enabled',
		'version',
		'author',
		'webpage',
		'etag',
	];

	/**
	 * {@inheritdoc}
	 */
	public function collection(): string {
		return 'plugins';
	}

	/**
	 * {@inheritdoc}
	 */
	public function list(Request $request, ResourceContext $ctx, ?string $parentId = null): Response {
		$ctx->enforce($ctx->principal->checkAccess(Principal::SCOPE_READ, 'plugins.php'));

		$filter  = FilterParser::parse($request->queryParam('filter') ?? '');
		$orderBy = OrderByParser::parse($request->queryParam('order_by') ?? '', self::SORT_MAP);
		$rows    = $ctx->repository->listPlugins($filter, $orderBy);
		$items   = array_map([self::class, 'serialize'], $rows);

		return Response::ok($ctx->paginate('plugins', $items, $request, self::READABLE_FIELDS));
	}

	/**
	 * {@inheritdoc}
	 */
	public function get(string $id, Request $request, ResourceContext $ctx, ?string $parentId = null): Response {
		$ctx->enforce($ctx->principal->checkAccess(Principal::SCOPE_READ, 'plugins.php'));

		$parsed = ResourceName::parse('plugins/' . $id);
		$row    = $ctx->repository->getPlugin($parsed->id);

		if ($row === null) {
			throw ApiException::notFound(sprintf('Plugin "%s" was not found.', $parsed->id), 'cacti.net/Plugin', 'plugins/' . $parsed->id);
		}

		$serialized = self::serialize($row);
		$etag       = is_string($serialized['etag']) ? $serialized['etag'] : '';

		return Response::ok(
			$ctx->projectSingle($serialized, $request, self::READABLE_FIELDS, 'plugins'),
			['ETag' => $etag]
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function create(Request $request, ResourceContext $ctx, ?string $parentId = null): Response {
		throw ApiException::unimplemented('Plugins are read-only via the v1 REST API.');
	}

	/**
	 * {@inheritdoc}
	 */
	public function update(string $id, Request $request, ResourceContext $ctx, ?string $parentId = null): Response {
		throw ApiException::unimplemented('Plugins are read-only via the v1 REST API.');
	}

	/**
	 * {@inheritdoc}
	 */
	public function delete(string $id, Request $request, ResourceContext $ctx, ?string $parentId = null): Response {
		throw ApiException::unimplemented('Plugins are read-only via the v1 REST API.');
	}

	/**
	 * {@inheritdoc}
	 */
	public function customMethod(string $id, string $method, Request $request, ResourceContext $ctx, ?string $parentId = null): Response {
		throw ApiException::unimplemented(sprintf('Custom method ":%s" is not supported on plugins.', $method));
	}

	/**
	 * Serialize a database plugin_config row into its canonical AIP resource representation.
	 *
	 * @param array<string, mixed> $row Raw repository plugin_config row.
	 * @return array<string, mixed>
	 */
	public static function serialize(array $row): array {
		$dir      = is_scalar($row['directory'] ?? null) ? (string) $row['directory'] : '';
		$status   = is_numeric($row['status'] ?? null) ? (int) $row['status'] : 0;
		$resource = [
			'name'         => ResourceName::build('plugins', $dir),
			'id'           => $dir,
			'directory'    => $dir,
			'display_name' => is_scalar($row['name'] ?? null) ? (string) $row['name'] : '',
			'status'       => $status,
			'enabled'      => $status === 1,
			'version'      => is_scalar($row['version'] ?? null) ? (string) $row['version'] : '',
			'author'       => is_scalar($row['author'] ?? null) ? (string) $row['author'] : '',
			'webpage'      => is_scalar($row['webpage'] ?? null) ? (string) $row['webpage'] : '',
		];
		$resource['etag'] = Etag::compute($resource);

		return $resource;
	}
}
