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

namespace Somethingwithproof\Api\Extension;

/**
 * Validated descriptor for a sibling plugin REST collection registered via the
 * api_register_resources Cacti hook using plain PHP arrays and callables.
 */
final class ExtensionResourceDescriptor {
	/**
	 * Valid plugin slug format.
	 */
	public const SLUG_PATTERN = '/^[a-z][a-z0-9_]{1,31}$/';

	/**
	 * Valid AIP collection slug format (lowerCamelCase).
	 */
	public const COLLECTION_PATTERN = '/^[a-z][A-Za-z0-9]{2,63}$/';

	/**
	 * Core collection names reserved by plugin_api that sibling plugins may not shadow.
	 *
	 * @var list<string>
	 */
	public const RESERVED_COLLECTIONS = [
		'sites',
		'pollers',
		'devices',
		'graphs',
		'dataSources',
		'trees',
		'plugins',
		'operations',
		'tokens',
		'openapi.json',
	];

	/**
	 * Construct a validated ExtensionResourceDescriptor.
	 *
	 * @param string                    $plugin        Owning plugin slug.
	 * @param string                    $collection    AIP collection identifier (e.g. "mypluginWidgets").
	 * @param string                    $singleton     Singular resource name (e.g. "mypluginWidget").
	 * @param string                    $description   Human-readable OpenAPI collection description.
	 * @param string                    $realmRead     Cacti realm filename required for read operations.
	 * @param string                    $realmWrite    Cacti realm filename required for write operations.
	 * @param array<string, callable>   $handlers      Map of handler verbs ("list", "get", "create", "update", "delete") to callables.
	 * @param list<string>              $filterFields  Allowlisted AIP-160 filter fields.
	 * @param list<string>              $orderFields   Allowlisted AIP-132 sort fields.
	 * @param list<string>              $mutableFields Allowlisted AIP-161 update_mask fields.
	 * @param string|null               $schemaPath    Optional path to static schema/*.yaml file.
	 */
	public function __construct(
		public readonly string $plugin,
		public readonly string $collection,
		public readonly string $singleton,
		public readonly string $description,
		public readonly string $realmRead,
		public readonly string $realmWrite,
		public readonly array $handlers,
		public readonly array $filterFields = ['id', 'name'],
		public readonly array $orderFields = ['id', 'name'],
		public readonly array $mutableFields = [],
		public readonly ?string $schemaPath = null,
	) {
	}

	/**
	 * Validate a plain PHP array descriptor returned by a sibling plugin hook.
	 *
	 * @param array<mixed, mixed> $raw            Raw descriptor array from hook callback.
	 * @param string              $expectedPlugin Owning plugin slug from plugin_hooks.
	 * @return self
	 * @throws \InvalidArgumentException If the descriptor fails validation or tries to claim a foreign/reserved name.
	 */
	public static function fromArray(array $raw, string $expectedPlugin): self {
		$plugin     = is_string($raw['plugin'] ?? null) ? trim($raw['plugin']) : $expectedPlugin;
		$collection = is_string($raw['collection'] ?? null) ? trim($raw['collection']) : '';
		$singleton  = is_string($raw['singleton'] ?? null) ? trim($raw['singleton']) : '';
		$desc       = is_string($raw['description'] ?? null) ? trim($raw['description']) : sprintf('Resources provided by plugin "%s".', $plugin);
		$realmRead  = is_string($raw['realm_read'] ?? null) ? trim($raw['realm_read']) : '';
		$realmWrite = is_string($raw['realm_write'] ?? null) ? trim($raw['realm_write']) : $realmRead;
		$schemaPath = is_string($raw['schema'] ?? null) && trim($raw['schema']) !== '' ? trim($raw['schema']) : null;

		if ($plugin !== $expectedPlugin || preg_match(self::SLUG_PATTERN, $plugin) !== 1) {
			throw new \InvalidArgumentException(sprintf('Plugin slug "%s" does not match hook owner "%s".', $plugin, $expectedPlugin));
		}

		if (preg_match(self::COLLECTION_PATTERN, $collection) !== 1) {
			throw new \InvalidArgumentException(sprintf('Invalid extension collection name "%s".', $collection));
		}

		if (in_array($collection, self::RESERVED_COLLECTIONS, true)) {
			throw new \InvalidArgumentException(sprintf('Extension collection "%s" collides with a reserved core collection.', $collection));
		}

		if ($singleton === '' || preg_match(self::COLLECTION_PATTERN, $singleton) !== 1) {
			$singleton = rtrim($collection, 's');
		}

		if ($realmRead === '') {
			throw new \InvalidArgumentException(sprintf('Extension collection "%s" must declare realm_read.', $collection));
		}

		$rawHandlers = $raw['handlers'] ?? null;

		if (!is_array($rawHandlers) || $rawHandlers === []) {
			throw new \InvalidArgumentException(sprintf('Extension collection "%s" must provide at least one callable handler.', $collection));
		}

		$allowedVerbs = ['list', 'get', 'create', 'update', 'delete'];
		$handlers     = [];

		foreach ($rawHandlers as $verb => $callable) {
			$verbStr = (string) $verb;

			if (!in_array($verbStr, $allowedVerbs, true)) {
				throw new \InvalidArgumentException(sprintf('Unsupported handler verb "%s".', $verbStr));
			}

			if (!is_callable($callable)) {
				throw new \InvalidArgumentException(sprintf('Handler "%s" for collection "%s" is not callable.', $verbStr, $collection));
			}

			$handlers[$verbStr] = $callable;
		}

		$filterFields  = self::extractStringList($raw['filter_fields'] ?? ['id', 'name']);
		$orderFields   = self::extractStringList($raw['order_fields'] ?? ['id', 'name']);
		$mutableFields = self::extractStringList($raw['mutable_fields'] ?? []);

		return new self(
			plugin: $plugin,
			collection: $collection,
			singleton: $singleton,
			description: $desc,
			realmRead: $realmRead,
			realmWrite: $realmWrite !== '' ? $realmWrite : $realmRead,
			handlers: $handlers,
			filterFields: $filterFields !== [] ? $filterFields : ['id', 'name'],
			orderFields: $orderFields !== [] ? $orderFields : ['id', 'name'],
			mutableFields: $mutableFields,
			schemaPath: $schemaPath,
		);
	}

	/**
	 * Extract a clean list of non-empty field names from raw descriptor metadata.
	 *
	 * @param mixed $raw Raw field list value.
	 * @return list<string>
	 */
	private static function extractStringList(mixed $raw): array {
		if (!is_array($raw)) {
			return [];
		}

		$out = [];

		foreach ($raw as $entry) {
			if (is_string($entry) && preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $entry) === 1) {
				$out[] = $entry;
			}
		}

		return array_values(array_unique($out));
	}
}
