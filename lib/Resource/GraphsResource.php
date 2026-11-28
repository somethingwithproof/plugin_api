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
 * Google AIP resource controller for graphs (graphs/{graph} and
 * devices/{device}/graphs/{graph}) including :render metadata custom method.
 */
final class GraphsResource implements ResourceHandlerInterface {
	/**
	 * Allowlisted filter/sort column map.
	 *
	 * @var array<string, string>
	 */
	private const SORT_MAP = [
		'id'                => 'gl.id',
		'host_id'           => 'gl.host_id',
		'graph_template_id' => 'gl.graph_template_id',
		'title_cache'       => 'gtg.title_cache',
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
		'graph_template_id',
		'title',
		'height',
		'width',
		'etag',
	];

	/**
	 * {@inheritdoc}
	 */
	public function collection(): string {
		return 'graphs';
	}

	/**
	 * {@inheritdoc}
	 */
	public function list(Request $request, ResourceContext $ctx, ?string $parentId = null): Response {
		$ctx->enforce($ctx->principal->checkAccess(Principal::SCOPE_READ, 'graphs.php'));

		$deviceId = $parentId !== null ? ResourceName::parse('devices/' . $parentId)->intId() : null;

		if ($deviceId !== null && !$ctx->principal->isAdmin && !$ctx->repository->isDeviceAllowedForUser($ctx->principal->userId, $deviceId)) {
			throw ApiException::permissionDenied(sprintf('User does not have access to device "%d".', $deviceId));
		}

		$filter  = FilterParser::parse($request->queryParam('filter') ?? '');
		$orderBy = OrderByParser::parse($request->queryParam('order_by') ?? '', self::SORT_MAP);
		$rows    = $ctx->repository->listGraphs($deviceId, $filter, $orderBy);

		$visible = [];

		foreach ($rows as $row) {
			$graphId = is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;

			if ($ctx->principal->isAdmin || $ctx->repository->isGraphAllowedForUser($ctx->principal->userId, $graphId)) {
				$visible[] = self::serialize($row, $deviceId !== null);
			}
		}

		return Response::ok($ctx->paginate('graphs', $visible, $request, self::READABLE_FIELDS));
	}

	/**
	 * {@inheritdoc}
	 */
	public function get(string $id, Request $request, ResourceContext $ctx, ?string $parentId = null): Response {
		$intId    = ResourceName::parse('graphs/' . $id)->intId();
		$deviceId = $parentId !== null ? ResourceName::parse('devices/' . $parentId)->intId() : null;

		$this->enforceGraphAccess($ctx, $intId, $deviceId);

		$row = $ctx->repository->getGraph($intId, $deviceId);

		if ($row === null) {
			$resName = $deviceId !== null ? ResourceName::deviceGraph($deviceId, $intId) : ResourceName::build('graphs', $intId);

			throw ApiException::notFound(sprintf('Graph "%d" was not found.', $intId), 'cacti.net/Graph', $resName);
		}

		$serialized = self::serialize($row, $deviceId !== null);
		$etag       = is_string($serialized['etag']) ? $serialized['etag'] : '';

		return Response::ok(
			$ctx->projectSingle($serialized, $request, self::READABLE_FIELDS, 'graphs'),
			['ETag' => $etag]
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function create(Request $request, ResourceContext $ctx, ?string $parentId = null): Response {
		throw ApiException::unimplemented('Graphs are read-only via the v1 REST API.');
	}

	/**
	 * {@inheritdoc}
	 */
	public function update(string $id, Request $request, ResourceContext $ctx, ?string $parentId = null): Response {
		throw ApiException::unimplemented('Graphs are read-only via the v1 REST API.');
	}

	/**
	 * {@inheritdoc}
	 */
	public function delete(string $id, Request $request, ResourceContext $ctx, ?string $parentId = null): Response {
		throw ApiException::unimplemented('Graphs are read-only via the v1 REST API.');
	}

	/**
	 * {@inheritdoc}
	 */
	public function customMethod(string $id, string $method, Request $request, ResourceContext $ctx, ?string $parentId = null): Response {
		if ($method !== 'render') {
			throw ApiException::unimplemented(sprintf('Custom method ":%s" is not supported on graphs.', $method));
		}

		$intId    = ResourceName::parse('graphs/' . $id)->intId();
		$deviceId = $parentId !== null ? ResourceName::parse('devices/' . $parentId)->intId() : null;

		$this->enforceGraphAccess($ctx, $intId, $deviceId);

		$row = $ctx->repository->getGraph($intId, $deviceId);

		if ($row === null) {
			throw ApiException::notFound(sprintf('Graph "%d" was not found.', $intId), 'cacti.net/Graph', ResourceName::build('graphs', $intId));
		}

		$body   = $request->jsonBody($ctx->settings->maxBodyBytes);
		$format = is_string($body['format'] ?? null) ? strtolower(trim($body['format'])) : 'png';

		if (!in_array($format, ['png', 'svg'], true)) {
			throw ApiException::invalidArgument(
				sprintf('Unsupported render format "%s".', $format),
				[['field' => 'format', 'description' => 'Supported formats are "png" and "svg".']]
			);
		}

		$hostId = is_numeric($row['host_id'] ?? null) ? (int) $row['host_id'] : 0;

		return Response::ok([
			'graph'       => $deviceId !== null ? ResourceName::deviceGraph($deviceId, $intId) : ResourceName::build('graphs', $intId),
			'device'      => ResourceName::build('devices', $hostId),
			'format'      => $format,
			'contentType' => $format === 'svg' ? 'image/svg+xml' : 'image/png',
			'width'       => is_numeric($row['width'] ?? null) ? (int) $row['width'] : 500,
			'height'      => is_numeric($row['height'] ?? null) ? (int) $row['height'] : 120,
			'renderUrl'   => sprintf('graph_image.php?local_graph_id=%d&graph_nolegend=false', $intId),
		]);
	}

	/**
	 * Serialize a database graph row into its canonical AIP resource representation.
	 *
	 * @param array<string, mixed> $row          Raw repository graph row.
	 * @param bool                 $nestedNaming True to emit devices/{device}/graphs/{graph} name.
	 * @return array<string, mixed>
	 */
	public static function serialize(array $row, bool $nestedNaming = false): array {
		$id       = is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
		$hostId   = is_numeric($row['host_id'] ?? null) ? (int) $row['host_id'] : 0;
		$resource = [
			'name'              => $nestedNaming ? ResourceName::deviceGraph($hostId, $id) : ResourceName::build('graphs', $id),
			'id'                => $id,
			'device'            => ResourceName::build('devices', $hostId),
			'host_id'           => $hostId,
			'graph_template_id' => is_numeric($row['graph_template_id'] ?? null) ? (int) $row['graph_template_id'] : 0,
			'title'             => is_scalar($row['title_cache'] ?? null) ? (string) $row['title_cache'] : '',
			'height'            => is_numeric($row['height'] ?? null) ? (int) $row['height'] : 120,
			'width'             => is_numeric($row['width'] ?? null) ? (int) $row['width'] : 500,
		];
		$resource['etag'] = Etag::compute($resource);

		return $resource;
	}

	/**
	 * Enforce graphs.php realm and Cacti per-graph (and optional parent device) object ACLs.
	 *
	 * @param ResourceContext $ctx      Execution context.
	 * @param int             $graphId  Target local_graph_id.
	 * @param int|null        $deviceId Optional parent device ID.
	 * @return void
	 */
	private function enforceGraphAccess(ResourceContext $ctx, int $graphId, ?int $deviceId): void {
		$decision = $ctx->principal->checkAccess(Principal::SCOPE_READ, 'graphs.php');

		if (!$ctx->principal->isAdmin) {
			$graphAcl = AccessResult::allowedIf(
				$ctx->repository->isGraphAllowedForUser($ctx->principal->userId, $graphId),
				sprintf('User does not have object access to graph "%d".', $graphId)
			);
			$decision = $decision->andIf($graphAcl);

			if ($deviceId !== null) {
				$deviceAcl = AccessResult::allowedIf(
					$ctx->repository->isDeviceAllowedForUser($ctx->principal->userId, $deviceId),
					sprintf('User does not have object access to device "%d".', $deviceId)
				);
				$decision = $decision->andIf($deviceAcl);
			}
		}

		$ctx->enforce($decision);
	}
}
