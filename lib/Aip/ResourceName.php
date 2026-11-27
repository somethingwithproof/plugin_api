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

namespace Somethingwithproof\Api\Aip;

/**
 * Google AIP-122 canonical resource name parser and builder.
 */
final class ResourceName {
	/**
	 * Allowed pattern for single resource identifier segments.
	 */
	public const ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/';

	/**
	 * Allowed pattern for collection identifier segments.
	 */
	public const COLLECTION_PATTERN = '/^[a-z][A-Za-z0-9]{0,63}$/';

	/**
	 * Core resource collections mapped to their segment pattern definitions.
	 *
	 * @var array<string, list<string>>
	 */
	public const CORE_PATTERNS = [
		'sites'          => ['sites', '{site}'],
		'pollers'        => ['pollers', '{poller}'],
		'devices'        => ['devices', '{device}'],
		'device_graphs'  => ['devices', '{device}', 'graphs', '{graph}'],
		'graphs'         => ['graphs', '{graph}'],
		'dataSources'    => ['dataSources', '{data_source}'],
		'trees'          => ['trees', '{tree}'],
		'plugins'        => ['plugins', '{plugin}'],
		'operations'     => ['operations', '{operation}'],
		'tokens'         => ['tokens', '{token}'],
	];

	/**
	 * Construct a parsed AIP-122 resource name.
	 *
	 * @param string                $collection Leaf collection identifier (e.g. "devices", "graphs").
	 * @param string                $id         Leaf resource identifier (e.g. "42").
	 * @param string|null           $parent     Optional parent resource name (e.g. "devices/42").
	 * @param array<string, string> $segments   Map of collection => id for every pair in the path.
	 */
	public function __construct(
		public readonly string $collection,
		public readonly string $id,
		public readonly ?string $parent = null,
		public readonly array $segments = [],
	) {
	}

	/**
	 * Parse a canonical resource name such as "devices/42" or "devices/42/graphs/7".
	 *
	 * @param string $name Raw relative resource name without leading/trailing slashes.
	 * @return self
	 * @throws ApiException If the resource name format is invalid.
	 */
	public static function parse(string $name): self {
		$trimmed = trim($name, '/');

		if ($trimmed === '' || str_contains($trimmed, '..') || str_contains($trimmed, "\0")) {
			throw ApiException::invalidArgument(
				sprintf('Invalid resource name "%s".', $name),
				[['field' => 'name', 'description' => 'Resource name must be a non-empty relative path.']]
			);
		}

		$parts = explode('/', $trimmed);

		if (count($parts) < 2 || count($parts) % 2 !== 0 || count($parts) > 6) {
			throw ApiException::invalidArgument(
				sprintf('Invalid resource name "%s".', $name),
				[['field' => 'name', 'description' => 'Resource name must consist of collection/id segment pairs.']]
			);
		}

		$segments = [];
		$count    = count($parts);

		for ($i = 0; $i < $count; $i += 2) {
			$col = $parts[$i];
			$id  = $parts[$i + 1];

			if (preg_match(self::COLLECTION_PATTERN, $col) !== 1 || preg_match(self::ID_PATTERN, $id) !== 1) {
				throw ApiException::invalidArgument(
					sprintf('Malformed segment in resource name "%s".', $name),
					[['field' => 'name', 'description' => 'Collection and resource identifiers contain invalid characters.']]
				);
			}

			$segments[$col] = $id;
		}

		$leafCollection = $parts[$count - 2];
		$leafId         = $parts[$count - 1];
		$parent         = $count > 2 ? implode('/', array_slice($parts, 0, $count - 2)) : null;

		return new self($leafCollection, $leafId, $parent, $segments);
	}

	/**
	 * Build a canonical single-collection resource name (e.g. sites/1).
	 *
	 * @param string     $collection Collection name.
	 * @param int|string $id         Resource identifier.
	 * @return string
	 */
	public static function build(string $collection, int|string $id): string {
		return $collection . '/' . (string) $id;
	}

	/**
	 * Build a canonical device graph resource name (devices/{device}/graphs/{graph}).
	 *
	 * @param int|string $deviceId Device identifier.
	 * @param int|string $graphId  Graph identifier.
	 * @return string
	 */
	public static function deviceGraph(int|string $deviceId, int|string $graphId): string {
		return 'devices/' . (string) $deviceId . '/graphs/' . (string) $graphId;
	}

	/**
	 * Return the canonical relative resource name string.
	 *
	 * @return string
	 */
	public function toString(): string {
		if ($this->parent !== null && $this->parent !== '') {
			return $this->parent . '/' . $this->collection . '/' . $this->id;
		}

		return $this->collection . '/' . $this->id;
	}

	/**
	 * Return the integer value of the leaf identifier or throw INVALID_ARGUMENT.
	 *
	 * @return int
	 * @throws ApiException If the leaf identifier is not a positive integer.
	 */
	public function intId(): int {
		if (preg_match('/^[1-9][0-9]*$/', $this->id) !== 1) {
			throw ApiException::invalidArgument(
				sprintf('Resource identifier "%s" must be a positive integer.', $this->id),
				[['field' => 'name', 'description' => 'Expected a positive integer identifier.']]
			);
		}

		return (int) $this->id;
	}
}
