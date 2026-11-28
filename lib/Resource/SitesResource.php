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
 * Google AIP resource controller for Cacti sites (sites/{site}).
 */
final class SitesResource implements ResourceHandlerInterface {
	/**
	 * Allowlisted filter/sort column map.
	 *
	 * @var array<string, string>
	 */
	private const SORT_MAP = [
		'id'           => 'id',
		'name'         => 'name',
		'city'         => 'city',
		'state'        => 'state',
		'country'      => 'country',
		'timezone'     => 'timezone',
		'alternate_id' => 'alternate_id',
	];

	/**
	 * Allowlisted mutable fields accepted by update_mask.
	 *
	 * @var list<string>
	 */
	private const MUTABLE_FIELDS = [
		'display_name',
		'city',
		'state',
		'country',
		'timezone',
		'alternate_id',
		'notes',
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
		'city',
		'state',
		'country',
		'timezone',
		'alternate_id',
		'notes',
		'etag',
	];

	/**
	 * {@inheritdoc}
	 */
	public function collection(): string {
		return 'sites';
	}

	/**
	 * {@inheritdoc}
	 */
	public function list(Request $request, ResourceContext $ctx, ?string $parentId = null): Response {
		$ctx->enforce($ctx->principal->checkAccess(Principal::SCOPE_READ, 'sites.php'));

		$filter  = FilterParser::parse($request->queryParam('filter') ?? '');
		$orderBy = OrderByParser::parse($request->queryParam('order_by') ?? '', self::SORT_MAP);
		$rows    = $ctx->repository->listSites($filter, $orderBy);
		$items   = array_map([self::class, 'serialize'], $rows);

		return Response::ok($ctx->paginate('sites', $items, $request, self::READABLE_FIELDS));
	}

	/**
	 * {@inheritdoc}
	 */
	public function get(string $id, Request $request, ResourceContext $ctx, ?string $parentId = null): Response {
		$ctx->enforce($ctx->principal->checkAccess(Principal::SCOPE_READ, 'sites.php'));

		$intId = ResourceName::parse('sites/' . $id)->intId();
		$row   = $ctx->repository->getSite($intId);

		if ($row === null) {
			throw ApiException::notFound(sprintf('Site "%d" was not found.', $intId), 'cacti.net/Site', 'sites/' . $intId);
		}

		$serialized = self::serialize($row);
		$etag       = is_string($serialized['etag']) ? $serialized['etag'] : '';

		return Response::ok(
			$ctx->projectSingle($serialized, $request, self::READABLE_FIELDS, 'sites'),
			['ETag' => $etag]
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function create(Request $request, ResourceContext $ctx, ?string $parentId = null): Response {
		$ctx->enforce($ctx->principal->checkAccess(Principal::SCOPE_WRITE, 'sites.php'));

		$body        = $request->jsonBody($ctx->settings->maxBodyBytes);
		$displayName = is_string($body['display_name'] ?? null) ? trim($body['display_name']) : (is_string($body['name'] ?? null) ? trim($body['name']) : '');

		if ($displayName === '' || strlen($displayName) > 100) {
			throw ApiException::invalidArgument(
				'display_name is required and must be at most 100 characters.',
				[['field' => 'display_name', 'description' => 'Provide a non-empty site name up to 100 characters.']]
			);
		}

		$created    = $ctx->repository->createSite([
			'name'         => $displayName,
			'city'         => is_scalar($body['city'] ?? null) ? (string) $body['city'] : '',
			'state'        => is_scalar($body['state'] ?? null) ? (string) $body['state'] : '',
			'country'      => is_scalar($body['country'] ?? null) ? (string) $body['country'] : '',
			'timezone'     => is_scalar($body['timezone'] ?? null) ? (string) $body['timezone'] : '',
			'alternate_id' => is_scalar($body['alternate_id'] ?? null) ? (string) $body['alternate_id'] : '',
			'notes'        => is_scalar($body['notes'] ?? null) ? (string) $body['notes'] : '',
		]);
		$serialized = self::serialize($created);
		$etag       = is_string($serialized['etag']) ? $serialized['etag'] : '';

		return Response::created($serialized, ['ETag' => $etag]);
	}

	/**
	 * {@inheritdoc}
	 */
	public function update(string $id, Request $request, ResourceContext $ctx, ?string $parentId = null): Response {
		$ctx->enforce($ctx->principal->checkAccess(Principal::SCOPE_WRITE, 'sites.php'));

		$intId    = ResourceName::parse('sites/' . $id)->intId();
		$existing = $ctx->repository->getSite($intId);

		if ($existing === null) {
			throw ApiException::notFound(sprintf('Site "%d" was not found.', $intId), 'cacti.net/Site', 'sites/' . $intId);
		}

		$body       = $request->jsonBody($ctx->settings->maxBodyBytes);
		$mask       = $ctx->resolveUpdateMask($request, $body, self::MUTABLE_FIELDS, 'sites');
		$serialized = self::serialize($existing);
		$currEtag   = is_string($serialized['etag']) ? $serialized['etag'] : '';
		$bodyEtag   = is_string($body['etag'] ?? null) ? $body['etag'] : null;

		Etag::assertMatch($currEtag, $request->header('If-Match'), $bodyEtag, 'sites/' . $intId);

		$allowed = $mask->extractAllowedFields($body);
		$patch   = [];

		foreach ($allowed as $key => $value) {
			$col = $key === 'display_name' ? 'name' : $key;

			if ($col === 'name' && (!is_string($value) || trim($value) === '' || strlen(trim($value)) > 100)) {
				throw ApiException::invalidArgument(
					'display_name must be a non-empty string up to 100 characters.',
					[['field' => 'display_name', 'description' => 'Invalid site display_name.']]
				);
			}

			$patch[$col] = $value;
		}

		$updated    = $ctx->repository->updateSite($intId, $patch);
		$out        = self::serialize($updated ?? $existing);
		$updatedTag = is_string($out['etag']) ? $out['etag'] : '';

		return Response::ok($out, ['ETag' => $updatedTag]);
	}

	/**
	 * {@inheritdoc}
	 */
	public function delete(string $id, Request $request, ResourceContext $ctx, ?string $parentId = null): Response {
		$ctx->enforce($ctx->principal->checkAccess(Principal::SCOPE_WRITE, 'sites.php'));

		$intId    = ResourceName::parse('sites/' . $id)->intId();
		$existing = $ctx->repository->getSite($intId);

		if ($existing === null) {
			throw ApiException::notFound(sprintf('Site "%d" was not found.', $intId), 'cacti.net/Site', 'sites/' . $intId);
		}

		$serialized = self::serialize($existing);
		$currEtag   = is_string($serialized['etag']) ? $serialized['etag'] : '';
		Etag::assertMatch($currEtag, $request->header('If-Match'), null, 'sites/' . $intId);

		$ctx->repository->deleteSite($intId);

		return Response::noContent();
	}

	/**
	 * {@inheritdoc}
	 */
	public function customMethod(string $id, string $method, Request $request, ResourceContext $ctx, ?string $parentId = null): Response {
		throw ApiException::unimplemented(sprintf('Custom method ":%s" is not supported on sites.', $method));
	}

	/**
	 * Serialize a database site row into its canonical AIP resource representation.
	 *
	 * @param array<string, mixed> $row Raw repository site row.
	 * @return array<string, mixed>
	 */
	public static function serialize(array $row): array {
		$id       = is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
		$resource = [
			'name'         => ResourceName::build('sites', $id),
			'id'           => $id,
			'display_name' => is_scalar($row['name'] ?? null) ? (string) $row['name'] : '',
			'city'         => is_scalar($row['city'] ?? null) ? (string) $row['city'] : '',
			'state'        => is_scalar($row['state'] ?? null) ? (string) $row['state'] : '',
			'country'      => is_scalar($row['country'] ?? null) ? (string) $row['country'] : '',
			'timezone'     => is_scalar($row['timezone'] ?? null) ? (string) $row['timezone'] : '',
			'alternate_id' => is_scalar($row['alternate_id'] ?? null) ? (string) $row['alternate_id'] : '',
			'notes'        => is_scalar($row['notes'] ?? null) ? (string) $row['notes'] : '',
		];
		$resource['etag'] = Etag::compute($resource);

		return $resource;
	}
}
