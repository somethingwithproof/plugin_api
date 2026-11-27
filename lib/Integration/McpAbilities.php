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

namespace Somethingwithproof\Api\Integration;

use Somethingwithproof\Api\Aip\OperationManager;
use Somethingwithproof\Api\Extension\ResourceRegistry;
use Somethingwithproof\Api\OpenApi\OpenApiGenerator;
use Somethingwithproof\Api\Pages;
use Somethingwithproof\Api\Repository\ApiRepositoryInterface;
use Somethingwithproof\Api\Settings;

/**
 * Registers read-only REST API abilities (api-get-status, api-list-operations,
 * api-get-openapi) via the abilities_register hook without hard imports.
 */
final class McpAbilities {
	/**
	 * Read-only MCP tool annotations.
	 *
	 * @var array{readOnly: bool, destructive: bool, idempotent: bool}
	 */
	private const ANNOTATIONS = [
		'readOnly'    => true,
		'destructive' => false,
		'idempotent'  => true,
	];

	/**
	 * Register read-only REST API abilities onto a decoupled registrar object.
	 *
	 * @param object                             $registrar       Registrar object exposing register(string, array).
	 * @param callable(): ApiRepositoryInterface $repoFactory     Repository factory.
	 * @param callable(): Settings               $settingsFactory Settings factory.
	 * @param callable(): ResourceRegistry       $registryFactory Extension resource registry factory.
	 * @param callable(int): bool                $permissionCheck Permission callback receiving user ID.
	 * @param string                             $apiVersion      Plugin version string.
	 * @return void
	 */
	public static function register(
		object $registrar,
		callable $repoFactory,
		callable $settingsFactory,
		callable $registryFactory,
		callable $permissionCheck,
		string $apiVersion = '0.1.0',
	): void {
		if (!method_exists($registrar, 'register')) {
			return;
		}

		$registrar->register('api-get-status', [
			'label'        => 'Get REST API status',
			'description'  => 'Read plugin_api health metrics, enabled status, and registered extension collections without exposing tokens.',
			'category'     => 'system',
			'input_schema' => [
				'type'                 => 'object',
				'properties'           => new \stdClass(),
				'additionalProperties' => false,
			],
			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'enabled'              => ['type' => 'boolean'],
					'version'              => ['type' => 'string'],
					'rateLimitPerMin'      => ['type' => 'integer'],
					'requireTls'           => ['type' => 'boolean'],
					'activeTokens'         => ['type' => 'integer'],
					'pendingOperations'    => ['type' => 'integer'],
					'extensionCollections' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 256],
				],
				'required'             => ['enabled', 'version', 'rateLimitPerMin', 'requireTls', 'activeTokens', 'pendingOperations', 'extensionCollections'],
				'additionalProperties' => false,
			],
			'annotations' => self::ANNOTATIONS,
			'permission'  => $permissionCheck,
			'execute'     => static function (int $userId, \stdClass $args) use ($repoFactory, $settingsFactory, $registryFactory, $apiVersion): array {
				$settings = $settingsFactory();
				$registry = $registryFactory();
				$stats    = $repoFactory()->getHealthStats(time());

				return [
					'enabled'              => $settings->enabled,
					'version'              => $apiVersion,
					'rateLimitPerMin'      => $settings->rateLimitPerMin,
					'requireTls'           => $settings->requireTls,
					'activeTokens'         => $stats['active_tokens'],
					'pendingOperations'    => $stats['pending_operations'],
					'extensionCollections' => array_map(static fn ($d): string => $d->collection, $registry->all()),
				];
			},
		]);

		$registrar->register('api-list-operations', [
			'label'        => 'List REST API Long-Running Operations',
			'description'  => 'List AIP-151 Long-Running Operations visible to the authenticated user.',
			'category'     => 'system',
			'input_schema' => [
				'type'                 => 'object',
				'properties'           => new \stdClass(),
				'additionalProperties' => false,
			],
			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'operations' => ['type' => 'array', 'items' => ['type' => 'object'], 'maxItems' => 256],
					'totalSize'  => ['type' => 'integer'],
				],
				'required'             => ['operations', 'totalSize'],
				'additionalProperties' => false,
			],
			'annotations' => self::ANNOTATIONS,
			'permission'  => $permissionCheck,
			'execute'     => static function (int $userId, \stdClass $args) use ($repoFactory): array {
				$repo    = $repoFactory();
				$realms  = $repo->getUserRealms($userId);
				$isAdmin = in_array(Pages::ADMIN, $realms, true);
				$ops     = (new OperationManager($repo))->list($userId, $isAdmin);

				return [
					'operations' => $ops,
					'totalSize'  => count($ops),
				];
			},
		]);

		$registrar->register('api-get-openapi', [
			'label'        => 'Get REST API OpenAPI 3.1 Specification',
			'description'  => 'Generate the live OpenAPI 3.1 document including dynamically registered plugin endpoints.',
			'category'     => 'system',
			'input_schema' => [
				'type'                 => 'object',
				'properties'           => new \stdClass(),
				'additionalProperties' => false,
			],
			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'openapi'   => ['type' => 'string'],
					'pathCount' => ['type' => 'integer'],
					'paths'     => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 512],
				],
				'required'             => ['openapi', 'pathCount', 'paths'],
				'additionalProperties' => false,
			],
			'annotations' => self::ANNOTATIONS,
			'permission'  => $permissionCheck,
			'execute'     => static function (int $userId, \stdClass $args) use ($registryFactory, $apiVersion): array {
				$spec      = OpenApiGenerator::generate($registryFactory(), $apiVersion);
				$specPaths = is_array($spec['paths'] ?? null) ? array_keys($spec['paths']) : [];

				return [
					'openapi'   => is_string($spec['openapi'] ?? null) ? $spec['openapi'] : '3.1.0',
					'pathCount' => count($specPaths),
					'paths'     => array_values(array_map('strval', $specPaths)),
				];
			},
		]);
	}

	/**
	 * Default Cacti realm permission check for MCP abilities (API View or higher).
	 *
	 * @param int $userId Cacti user_auth.id.
	 * @return bool
	 */
	public static function userHasViewRealm(int $userId): bool {
		if ($userId <= 0 || !function_exists('db_fetch_cell_prepared') || !function_exists('is_realm_allowed')) {
			return false;
		}

		foreach ([Pages::VIEW, Pages::OPERATOR, Pages::ADMIN] as $file) {
			/** @var mixed $realmId */
			$realmId = db_fetch_cell_prepared(
				'SELECT id FROM plugin_realms WHERE plugin = ? AND file = ?',
				['api', $file]
			);

			if (is_numeric($realmId) && (int) $realmId > 0 && is_realm_allowed((int) $realmId + 100, $userId)) {
				return true;
			}
		}

		return false;
	}
}
