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
use Somethingwithproof\Api\Aip\FilterParser;
use Somethingwithproof\Api\Aip\ResourceName;
use Somethingwithproof\Api\Aip\SqlFilterCompiler;
use Somethingwithproof\Api\Auth\Principal;
use Somethingwithproof\Api\Http\Request;
use Somethingwithproof\Api\Http\Response;
use Somethingwithproof\Api\Pages;

/**
 * Google AIP-151 Long-Running Operations resource controller (operations/{operation}).
 */
final class OperationsResource implements ResourceHandlerInterface {
	/**
	 * Allowlisted readable fields accepted by read_mask.
	 *
	 * @var list<string>
	 */
	private const READABLE_FIELDS = [
		'name',
		'id',
		'done',
		'metadata',
		'response',
		'error',
		'createTime',
		'updateTime',
	];

	/**
	 * {@inheritdoc}
	 */
	public function collection(): string {
		return 'operations';
	}

	/**
	 * {@inheritdoc}
	 */
	public function list(Request $request, ResourceContext $ctx, ?string $parentId = null): Response {
		$ctx->enforce($ctx->principal->checkAccess(Principal::SCOPE_READ, Pages::VIEW));

		$items  = $ctx->operations->list($ctx->principal->userId, $ctx->principal->isAdmin);
		$filter = FilterParser::parse($request->queryParam('filter') ?? '');

		if ($filter !== null) {
			$items = SqlFilterCompiler::filterRecords($items, $filter, ['id', 'done']);
		}

		return Response::ok($ctx->paginate('operations', $items, $request, self::READABLE_FIELDS));
	}

	/**
	 * {@inheritdoc}
	 */
	public function get(string $id, Request $request, ResourceContext $ctx, ?string $parentId = null): Response {
		$ctx->enforce($ctx->principal->checkAccess(Principal::SCOPE_READ, Pages::VIEW));

		$parsed = ResourceName::parse('operations/' . $id);
		$op     = $ctx->operations->get($parsed->id, $ctx->principal->userId, $ctx->principal->isAdmin);

		return Response::ok($ctx->projectSingle($op, $request, self::READABLE_FIELDS, 'operations'));
	}

	/**
	 * {@inheritdoc}
	 */
	public function create(Request $request, ResourceContext $ctx, ?string $parentId = null): Response {
		throw ApiException::unimplemented('Operations are created indirectly by asynchronous custom methods.');
	}

	/**
	 * {@inheritdoc}
	 */
	public function update(string $id, Request $request, ResourceContext $ctx, ?string $parentId = null): Response {
		throw ApiException::unimplemented('Operations cannot be directly patched; use :cancel instead.');
	}

	/**
	 * {@inheritdoc}
	 */
	public function delete(string $id, Request $request, ResourceContext $ctx, ?string $parentId = null): Response {
		$ctx->enforce($ctx->principal->checkAccess(Principal::SCOPE_WRITE, Pages::VIEW));

		$parsed = ResourceName::parse('operations/' . $id);
		$ctx->operations->delete($parsed->id, $ctx->principal->userId, $ctx->principal->isAdmin);

		return Response::noContent();
	}

	/**
	 * {@inheritdoc}
	 */
	public function customMethod(string $id, string $method, Request $request, ResourceContext $ctx, ?string $parentId = null): Response {
		if ($method !== 'cancel') {
			throw ApiException::unimplemented(sprintf('Custom method ":%s" is not supported on operations.', $method));
		}

		$ctx->enforce($ctx->principal->checkAccess(Principal::SCOPE_WRITE, Pages::VIEW));

		$parsed    = ResourceName::parse('operations/' . $id);
		$cancelled = $ctx->operations->cancel($parsed->id, $ctx->principal->userId, $ctx->principal->isAdmin, $ctx->now);

		return Response::ok($cancelled);
	}
}
