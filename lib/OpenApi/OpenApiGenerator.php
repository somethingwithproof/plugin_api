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

namespace Somethingwithproof\Api\OpenApi;

use Somethingwithproof\Api\Extension\ExtensionResourceDescriptor;
use Somethingwithproof\Api\Extension\ResourceRegistry;

/**
 * Dynamic OpenAPI 3.1.0 specification generator covering all core Cacti AIP
 * resources plus every sibling plugin resource registered via api_register_resources.
 */
final class OpenApiGenerator {
	/**
	 * Generate a complete OpenAPI 3.1.0 document as an associative array.
	 *
	 * @param ResourceRegistry $registry   Active extension resource registry.
	 * @param string           $apiVersion Plugin version string.
	 * @param string           $serverUrl  Base URL prefix for the v1 API.
	 * @return array<string, mixed>
	 */
	public static function generate(
		ResourceRegistry $registry,
		string $apiVersion = '0.1.0',
		string $serverUrl = '/plugins/api',
	): array {
		$paths = self::corePaths();

		foreach ($registry->all() as $descriptor) {
			$paths = array_merge($paths, self::extensionPaths($descriptor));
		}

		return [
			'openapi' => '3.1.0',
			'info'    => [
				'title'       => 'Cacti Google AIP REST API',
				'version'     => $apiVersion,
				'description' => 'Resource-oriented HTTP/JSON API for Cacti 1.2 following Google API Improvement Proposals (AIP-121 through AIP-193).',
				'license'     => [
					'name'       => 'GPL-2.0-or-later',
					'identifier' => 'GPL-2.0-or-later',
				],
			],
			'servers' => [
				['url' => rtrim($serverUrl, '/')],
			],
			'security' => [
				['bearerAuth' => []],
			],
			'paths'      => $paths,
			'components' => [
				'securitySchemes' => [
					'bearerAuth' => [
						'type'         => 'http',
						'scheme'       => 'bearer',
						'bearerFormat' => 'cacti_pat_<prefix>_<secret>',
						'description'  => 'Per-user revocable Application Password scoped to Cacti RBAC realms.',
					],
				],
				'schemas' => [
					'GoogleRpcStatus' => [
						'type'       => 'object',
						'required'   => ['error'],
						'properties' => [
							'error' => [
								'type'       => 'object',
								'required'   => ['code', 'message', 'status', 'details'],
								'properties' => [
									'code'    => ['type' => 'integer'],
									'message' => ['type' => 'string'],
									'status'  => ['type' => 'string'],
									'details' => [
										'type'  => 'array',
										'items' => ['type' => 'object'],
									],
								],
							],
						],
					],
					'Operation' => [
						'type'       => 'object',
						'required'   => ['name', 'id', 'done', 'metadata'],
						'properties' => [
							'name'       => ['type' => 'string'],
							'id'         => ['type' => 'string'],
							'done'       => ['type' => 'boolean'],
							'metadata'   => ['type' => 'object'],
							'response'   => ['type' => 'object'],
							'error'      => ['type' => 'object'],
							'createTime' => ['type' => 'string', 'format' => 'date-time'],
							'updateTime' => ['type' => 'string', 'format' => 'date-time'],
						],
					],
				],
			],
		];
	}

	/**
	 * Build OpenAPI path items for all core Cacti AIP collections and custom methods.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function corePaths(): array {
		$errRef = ['description' => 'AIP-193 canonical error', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/GoogleRpcStatus']]]];

		$listParams = [
			['name' => 'page_size', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000, 'default' => 50]],
			['name' => 'page_token', 'in' => 'query', 'schema' => ['type' => 'string']],
			['name' => 'filter', 'in' => 'query', 'schema' => ['type' => 'string']],
			['name' => 'order_by', 'in' => 'query', 'schema' => ['type' => 'string']],
			['name' => 'read_mask', 'in' => 'query', 'schema' => ['type' => 'string']],
		];

		return [
			'/v1/openapi.json' => [
				'get' => [
					'summary'   => 'Get live OpenAPI 3.1 specification',
					'responses' => ['200' => ['description' => 'OpenAPI 3.1 JSON document'], '401' => $errRef],
				],
			],
			'/v1/sites' => [
				'get'  => ['summary' => 'List sites', 'parameters' => $listParams, 'responses' => ['200' => ['description' => 'Site list'], 'default' => $errRef]],
				'post' => ['summary' => 'Create site', 'responses' => ['201' => ['description' => 'Created site'], 'default' => $errRef]],
			],
			'/v1/sites/{site}' => [
				'get'    => ['summary' => 'Get site', 'responses' => ['200' => ['description' => 'Site resource'], 'default' => $errRef]],
				'patch'  => ['summary' => 'Update site (AIP-161 update_mask + AIP-154 ETag)', 'responses' => ['200' => ['description' => 'Updated site'], 'default' => $errRef]],
				'delete' => ['summary' => 'Delete site', 'responses' => ['204' => ['description' => 'Deleted'], 'default' => $errRef]],
			],
			'/v1/pollers' => [
				'get' => ['summary' => 'List pollers', 'parameters' => $listParams, 'responses' => ['200' => ['description' => 'Poller list'], 'default' => $errRef]],
			],
			'/v1/pollers/{poller}' => [
				'get' => ['summary' => 'Get poller', 'responses' => ['200' => ['description' => 'Poller resource'], 'default' => $errRef]],
			],
			'/v1/devices' => [
				'get'  => ['summary' => 'List devices', 'parameters' => $listParams, 'responses' => ['200' => ['description' => 'Device list'], 'default' => $errRef]],
				'post' => ['summary' => 'Create device', 'responses' => ['201' => ['description' => 'Created device'], 'default' => $errRef]],
			],
			'/v1/devices/{device}' => [
				'get'    => ['summary' => 'Get device', 'responses' => ['200' => ['description' => 'Device resource'], 'default' => $errRef]],
				'patch'  => ['summary' => 'Update device (AIP-161 update_mask + AIP-154 ETag)', 'responses' => ['200' => ['description' => 'Updated device'], 'default' => $errRef]],
				'delete' => ['summary' => 'Delete device', 'responses' => ['204' => ['description' => 'Deleted'], 'default' => $errRef]],
			],
			'/v1/devices/{device}:enable' => [
				'post' => ['summary' => 'Enable device (AIP-136)', 'responses' => ['200' => ['description' => 'Enabled device'], 'default' => $errRef]],
			],
			'/v1/devices/{device}:disable' => [
				'post' => ['summary' => 'Disable device (AIP-136)', 'responses' => ['200' => ['description' => 'Disabled device'], 'default' => $errRef]],
			],
			'/v1/devices/{device}:reindex' => [
				'post' => ['summary' => 'Queue device reindex LRO (AIP-136 / AIP-151)', 'responses' => ['202' => ['description' => 'Long-Running Operation'], 'default' => $errRef]],
			],
			'/v1/devices/{device}:poll' => [
				'post' => ['summary' => 'Queue device poll LRO (AIP-136 / AIP-151)', 'responses' => ['202' => ['description' => 'Long-Running Operation'], 'default' => $errRef]],
			],
			'/v1/devices/{device}/graphs' => [
				'get' => ['summary' => 'List graphs for a device', 'parameters' => $listParams, 'responses' => ['200' => ['description' => 'Graph list'], 'default' => $errRef]],
			],
			'/v1/devices/{device}/graphs/{graph}' => [
				'get' => ['summary' => 'Get device graph', 'responses' => ['200' => ['description' => 'Graph resource'], 'default' => $errRef]],
			],
			'/v1/devices/{device}/graphs/{graph}:render' => [
				'post' => ['summary' => 'Get graph render metadata (PNG/SVG)', 'responses' => ['200' => ['description' => 'Render metadata'], 'default' => $errRef]],
			],
			'/v1/graphs' => [
				'get' => ['summary' => 'List graphs', 'parameters' => $listParams, 'responses' => ['200' => ['description' => 'Graph list'], 'default' => $errRef]],
			],
			'/v1/graphs/{graph}' => [
				'get' => ['summary' => 'Get graph', 'responses' => ['200' => ['description' => 'Graph resource'], 'default' => $errRef]],
			],
			'/v1/graphs/{graph}:render' => [
				'post' => ['summary' => 'Get graph render metadata (PNG/SVG)', 'responses' => ['200' => ['description' => 'Render metadata'], 'default' => $errRef]],
			],
			'/v1/dataSources' => [
				'get' => ['summary' => 'List data sources', 'parameters' => $listParams, 'responses' => ['200' => ['description' => 'Data source list'], 'default' => $errRef]],
			],
			'/v1/dataSources/{data_source}' => [
				'get' => ['summary' => 'Get data source', 'responses' => ['200' => ['description' => 'Data source resource'], 'default' => $errRef]],
			],
			'/v1/trees' => [
				'get' => ['summary' => 'List graph trees', 'parameters' => $listParams, 'responses' => ['200' => ['description' => 'Graph tree list'], 'default' => $errRef]],
			],
			'/v1/trees/{tree}' => [
				'get' => ['summary' => 'Get graph tree', 'responses' => ['200' => ['description' => 'Graph tree resource'], 'default' => $errRef]],
			],
			'/v1/plugins' => [
				'get' => ['summary' => 'List installed Cacti plugins', 'parameters' => $listParams, 'responses' => ['200' => ['description' => 'Plugin list'], 'default' => $errRef]],
			],
			'/v1/plugins/{plugin}' => [
				'get' => ['summary' => 'Get installed Cacti plugin', 'responses' => ['200' => ['description' => 'Plugin resource'], 'default' => $errRef]],
			],
			'/v1/operations' => [
				'get' => ['summary' => 'List Long-Running Operations (AIP-151)', 'parameters' => $listParams, 'responses' => ['200' => ['description' => 'Operation list'], 'default' => $errRef]],
			],
			'/v1/operations/{operation}' => [
				'get'    => ['summary' => 'Get Long-Running Operation', 'responses' => ['200' => ['description' => 'Operation resource'], 'default' => $errRef]],
				'delete' => ['summary' => 'Delete Long-Running Operation', 'responses' => ['204' => ['description' => 'Deleted'], 'default' => $errRef]],
			],
			'/v1/operations/{operation}:cancel' => [
				'post' => ['summary' => 'Cancel Long-Running Operation (AIP-151)', 'responses' => ['200' => ['description' => 'Cancelled operation'], 'default' => $errRef]],
			],
			'/v1/tokens' => [
				'get'  => ['summary' => 'List Application Password tokens', 'parameters' => $listParams, 'responses' => ['200' => ['description' => 'Token list'], 'default' => $errRef]],
				'post' => ['summary' => 'Create Application Password token', 'responses' => ['201' => ['description' => 'Created token (secret shown once)'], 'default' => $errRef]],
			],
			'/v1/tokens/{token}' => [
				'get'    => ['summary' => 'Get token metadata', 'responses' => ['200' => ['description' => 'Token metadata'], 'default' => $errRef]],
				'delete' => ['summary' => 'Delete token', 'responses' => ['204' => ['description' => 'Deleted'], 'default' => $errRef]],
			],
			'/v1/tokens/{token}:revoke' => [
				'post' => ['summary' => 'Revoke Application Password token', 'responses' => ['200' => ['description' => 'Revoked token'], 'default' => $errRef]],
			],
		];
	}

	/**
	 * Build OpenAPI path items for a dynamically registered sibling plugin collection.
	 *
	 * @param ExtensionResourceDescriptor $descriptor Validated sibling plugin descriptor.
	 * @return array<string, array<string, mixed>>
	 */
	private static function extensionPaths(ExtensionResourceDescriptor $descriptor): array {
		$errRef     = ['description' => 'AIP-193 canonical error', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/GoogleRpcStatus']]]];
		$collection = $descriptor->collection;
		$singleton  = $descriptor->singleton;
		$paths      = [];

		$colOps = [];

		if (isset($descriptor->handlers['list'])) {
			$colOps['get'] = [
				'summary'     => sprintf('List %s (%s plugin)', $collection, $descriptor->plugin),
				'description' => $descriptor->description,
				'tags'        => ['plugin:' . $descriptor->plugin],
				'responses'   => ['200' => ['description' => 'Resource list'], 'default' => $errRef],
			];
		}

		if (isset($descriptor->handlers['create'])) {
			$colOps['post'] = [
				'summary'     => sprintf('Create %s (%s plugin)', $singleton, $descriptor->plugin),
				'description' => $descriptor->description,
				'tags'        => ['plugin:' . $descriptor->plugin],
				'responses'   => ['201' => ['description' => 'Created resource'], 'default' => $errRef],
			];
		}

		if ($colOps !== []) {
			$paths['/v1/' . $collection] = $colOps;
		}

		$itemOps = [];

		if (isset($descriptor->handlers['get'])) {
			$itemOps['get'] = [
				'summary'   => sprintf('Get %s (%s plugin)', $singleton, $descriptor->plugin),
				'tags'      => ['plugin:' . $descriptor->plugin],
				'responses' => ['200' => ['description' => 'Resource item'], 'default' => $errRef],
			];
		}

		if (isset($descriptor->handlers['update'])) {
			$itemOps['patch'] = [
				'summary'   => sprintf('Update %s (%s plugin)', $singleton, $descriptor->plugin),
				'tags'      => ['plugin:' . $descriptor->plugin],
				'responses' => ['200' => ['description' => 'Updated resource'], 'default' => $errRef],
			];
		}

		if (isset($descriptor->handlers['delete'])) {
			$itemOps['delete'] = [
				'summary'   => sprintf('Delete %s (%s plugin)', $singleton, $descriptor->plugin),
				'tags'      => ['plugin:' . $descriptor->plugin],
				'responses' => ['204' => ['description' => 'Deleted'], 'default' => $errRef],
			];
		}

		if ($itemOps !== []) {
			$paths['/v1/' . $collection . '/{' . $singleton . '}'] = $itemOps;
		}

		return $paths;
	}
}
