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

use Somethingwithproof\Api\Http\Request;
use Somethingwithproof\Api\Http\Response;

/**
 * Contract implemented by each core AIP resource controller.
 */
interface ResourceHandlerInterface {
	/**
	 * Return the AIP collection slug owned by this handler (e.g. "devices").
	 *
	 * @return string
	 */
	public function collection(): string;

	/**
	 * Handle List (GET /v1/{collection}) requests.
	 *
	 * @param Request         $request  Incoming HTTP request.
	 * @param ResourceContext $ctx      Execution context.
	 * @param string|null     $parentId Optional parent resource identifier for nested collections.
	 * @return Response
	 */
	public function list(Request $request, ResourceContext $ctx, ?string $parentId = null): Response;

	/**
	 * Handle Get (GET /v1/{collection}/{id}) requests.
	 *
	 * @param string          $id       Leaf resource identifier.
	 * @param Request         $request  Incoming HTTP request.
	 * @param ResourceContext $ctx      Execution context.
	 * @param string|null     $parentId Optional parent resource identifier for nested collections.
	 * @return Response
	 */
	public function get(string $id, Request $request, ResourceContext $ctx, ?string $parentId = null): Response;

	/**
	 * Handle Create (POST /v1/{collection}) requests.
	 *
	 * @param Request         $request  Incoming HTTP request.
	 * @param ResourceContext $ctx      Execution context.
	 * @param string|null     $parentId Optional parent resource identifier.
	 * @return Response
	 */
	public function create(Request $request, ResourceContext $ctx, ?string $parentId = null): Response;

	/**
	 * Handle Update (PATCH /v1/{collection}/{id}) requests.
	 *
	 * @param string          $id       Leaf resource identifier.
	 * @param Request         $request  Incoming HTTP request.
	 * @param ResourceContext $ctx      Execution context.
	 * @param string|null     $parentId Optional parent resource identifier.
	 * @return Response
	 */
	public function update(string $id, Request $request, ResourceContext $ctx, ?string $parentId = null): Response;

	/**
	 * Handle Delete (DELETE /v1/{collection}/{id}) requests.
	 *
	 * @param string          $id       Leaf resource identifier.
	 * @param Request         $request  Incoming HTTP request.
	 * @param ResourceContext $ctx      Execution context.
	 * @param string|null     $parentId Optional parent resource identifier.
	 * @return Response
	 */
	public function delete(string $id, Request $request, ResourceContext $ctx, ?string $parentId = null): Response;

	/**
	 * Handle AIP-136 custom methods (POST /v1/{collection}/{id}:{method}).
	 *
	 * @param string          $id       Leaf resource identifier.
	 * @param string          $method   Custom method name without leading colon.
	 * @param Request         $request  Incoming HTTP request.
	 * @param ResourceContext $ctx      Execution context.
	 * @param string|null     $parentId Optional parent resource identifier.
	 * @return Response
	 */
	public function customMethod(string $id, string $method, Request $request, ResourceContext $ctx, ?string $parentId = null): Response;
}
