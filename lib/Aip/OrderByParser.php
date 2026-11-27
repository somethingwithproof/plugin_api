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
 * Google AIP-132 order_by parser and allowlist validator.
 */
final class OrderByParser {
	/**
	 * Safe SQL column identifier regex.
	 */
	private const SAFE_COLUMN_PATTERN = '/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)?$/';

	/**
	 * Parse an AIP-132 order_by clause into allowlisted field/direction directives.
	 *
	 * @param string                $orderBy   Raw order_by query string (e.g. "hostname asc, id desc").
	 * @param array<string, string> $columnMap Map of allowed API field names to SQL column identifiers.
	 * @return list<array{field: string, column: string, direction: 'ASC'|'DESC'}>
	 * @throws ApiException If any clause is malformed or references a non-allowlisted field.
	 */
	public static function parse(string $orderBy, array $columnMap): array {
		$trimmed = trim($orderBy);

		if ($trimmed === '') {
			return [];
		}

		$clauses = explode(',', $trimmed);

		if (count($clauses) > 8) {
			throw ApiException::invalidArgument(
				'order_by contains too many sort clauses.',
				[['field' => 'order_by', 'description' => 'At most 8 sort fields are permitted.']]
			);
		}

		$result = [];

		foreach ($clauses as $rawClause) {
			$clause = trim($rawClause);

			if ($clause === '') {
				throw ApiException::invalidArgument(
					'Empty sort clause in order_by.',
					[['field' => 'order_by', 'description' => 'Remove trailing or consecutive commas.']]
				);
			}

			$parts = preg_split('/\s+/', $clause) ?: [];

			if (count($parts) > 2) {
				throw ApiException::invalidArgument(
					sprintf('Malformed order_by clause "%s".', $clause),
					[['field' => 'order_by', 'description' => 'Expected "field" or "field asc|desc".']]
				);
			}

			$field     = $parts[0];
			$dirRaw    = isset($parts[1]) ? strtolower($parts[1]) : 'asc';
			$direction = match ($dirRaw) {
				'asc'   => 'ASC',
				'desc'  => 'DESC',
				default => throw ApiException::invalidArgument(
					sprintf('Invalid sort direction "%s" in order_by.', $parts[1]),
					[['field' => 'order_by', 'description' => 'Direction must be "asc" or "desc".']]
				),
			};

			if (!array_key_exists($field, $columnMap)) {
				throw ApiException::invalidArgument(
					sprintf('Field "%s" is not sortable.', $field),
					[['field' => 'order_by', 'description' => sprintf('Allowed sort fields: %s.', implode(', ', array_keys($columnMap)))]]
				);
			}

			$column = $columnMap[$field];

			if (preg_match(self::SAFE_COLUMN_PATTERN, $column) !== 1) {
				throw ApiException::internal('Invalid internal sort column configuration.');
			}

			$result[] = [
				'field'     => $field,
				'column'    => $column,
				'direction' => $direction,
			];
		}

		return $result;
	}

	/**
	 * Render parsed sort directives into a safe SQL ORDER BY clause fragment.
	 *
	 * @param list<array{field: string, column: string, direction: 'ASC'|'DESC'}> $directives Parsed sort directives.
	 * @param string                                                               $tieBreaker Default SQL column for deterministic ordering.
	 * @return string
	 */
	public static function toSql(array $directives, string $tieBreaker = 'id'): string {
		$parts     = [];
		$hasTieKey = false;

		foreach ($directives as $d) {
			$parts[] = $d['column'] . ' ' . $d['direction'];

			if ($d['column'] === $tieBreaker || str_ends_with($d['column'], '.' . $tieBreaker)) {
				$hasTieKey = true;
			}
		}

		if (!$hasTieKey && preg_match(self::SAFE_COLUMN_PATTERN, $tieBreaker) === 1) {
			$parts[] = $tieBreaker . ' ASC';
		}

		return implode(', ', $parts);
	}

	/**
	 * Sort an in-memory list of resource arrays according to parsed sort directives.
	 *
	 * @param list<array<string, mixed>>                                           $records    Records to sort.
	 * @param list<array{field: string, column: string, direction: 'ASC'|'DESC'}> $directives Parsed sort directives.
	 * @param string                                                               $tieField   Fallback key for deterministic ordering.
	 * @return list<array<string, mixed>>
	 */
	public static function sortRecords(array $records, array $directives, string $tieField = 'id'): array {
		usort($records, static function (array $a, array $b) use ($directives, $tieField): int {
			foreach ($directives as $d) {
				$cmp = self::compareValues($a[$d['field']] ?? null, $b[$d['field']] ?? null);

				if ($cmp !== 0) {
					return $d['direction'] === 'DESC' ? -$cmp : $cmp;
				}
			}

			return self::compareValues($a[$tieField] ?? null, $b[$tieField] ?? null);
		});

		return $records;
	}

	/**
	 * Compare two scalar or nullable field values consistently.
	 *
	 * @param mixed $a Left value.
	 * @param mixed $b Right value.
	 * @return int
	 */
	private static function compareValues(mixed $a, mixed $b): int {
		if ($a === $b) {
			return 0;
		}

		if ($a === null) {
			return -1;
		}

		if ($b === null) {
			return 1;
		}

		if (is_numeric($a) && is_numeric($b)) {
			return ((float) $a) <=> ((float) $b);
		}

		$sa = is_scalar($a) ? (string) $a : '';
		$sb = is_scalar($b) ? (string) $b : '';

		return strcmp($sa, $sb);
	}
}
