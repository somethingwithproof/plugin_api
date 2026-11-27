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
 * Compiles parsed AIP-160 FilterNode ASTs strictly against allowlisted columns
 * into parameterized SQL predicates or evaluates them in memory.
 */
final class SqlFilterCompiler {
	/**
	 * Safe SQL column identifier regex preventing injection even from misconfigured maps.
	 */
	private const SAFE_COLUMN_PATTERN = '/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)?$/';

	/**
	 * Compile a FilterNode AST into a parameterized SQL WHERE clause fragment.
	 *
	 * @param FilterNode|null       $node       Parsed filter AST or null.
	 * @param array<string, string> $columnMap  Allowlist mapping API field names to SQL column identifiers.
	 * @return array{sql: string, params: list<string|int|float|null>}
	 * @throws ApiException If a filter references a field outside $columnMap or uses an invalid operator.
	 */
	public static function compile(?FilterNode $node, array $columnMap): array {
		if ($node === null) {
			return ['sql' => '', 'params' => []];
		}

		$params = [];
		$sql    = self::compileNode($node, $columnMap, $params);

		return ['sql' => $sql, 'params' => $params];
	}

	/**
	 * Evaluate a FilterNode AST against an in-memory associative resource array.
	 *
	 * @param FilterNode|null      $node           Parsed filter AST or null.
	 * @param array<string, mixed> $record         Resource payload map.
	 * @param list<string>         $allowedFields  Allowlisted filterable API field names.
	 * @return bool
	 * @throws ApiException If the filter references a non-allowlisted field.
	 */
	public static function matchesRecord(?FilterNode $node, array $record, array $allowedFields): bool {
		if ($node === null) {
			return true;
		}

		if ($node->type === FilterNode::TYPE_AND && $node->left !== null && $node->right !== null) {
			return self::matchesRecord($node->left, $record, $allowedFields)
				&& self::matchesRecord($node->right, $record, $allowedFields);
		}

		if ($node->type === FilterNode::TYPE_OR && $node->left !== null && $node->right !== null) {
			return self::matchesRecord($node->left, $record, $allowedFields)
				|| self::matchesRecord($node->right, $record, $allowedFields);
		}

		if ($node->type === FilterNode::TYPE_NOT && $node->left !== null) {
			return !self::matchesRecord($node->left, $record, $allowedFields);
		}

		$field = $node->field ?? '';

		if (!in_array($field, $allowedFields, true)) {
			throw ApiException::invalidArgument(
				sprintf('Filter field "%s" is not allowed.', $field),
				[['field' => 'filter', 'description' => sprintf('Field "%s" is not filterable on this resource.', $field)]]
			);
		}

		$actual = $record[$field] ?? null;
		$target = $node->value;
		$op     = $node->operator ?? '=';

		if ($op === ':') {
			if ($actual === null || $target === null) {
				return false;
			}

			if (is_array($actual)) {
				return in_array($target, $actual, false);
			}

			$actualStr = is_bool($actual) ? ($actual ? 'true' : 'false') : (is_scalar($actual) ? (string) $actual : '');
			$targetStr = is_bool($target) ? ($target ? 'true' : 'false') : (string) $target;

			return str_contains(strtolower($actualStr), strtolower($targetStr));
		}

		if ($op === '=') {
			return self::equalsLoose($actual, $target);
		}

		if ($op === '!=') {
			return !self::equalsLoose($actual, $target);
		}

		if (is_numeric($actual) && is_numeric($target)) {
			$a = (float) $actual;
			$b = (float) $target;

			return match ($op) {
				'<'     => $a < $b,
				'<='    => $a <= $b,
				'>'     => $a > $b,
				'>='    => $a >= $b,
				default => false,
			};
		}

		$aStr = is_scalar($actual) ? (string) $actual : '';
		$bStr = is_scalar($target) ? (string) $target : '';

		return match ($op) {
			'<'     => $aStr < $bStr,
			'<='    => $aStr <= $bStr,
			'>'     => $aStr > $bStr,
			'>='    => $aStr >= $bStr,
			default => false,
		};
	}

	/**
	 * Recursively compile a single AST node into bound SQL.
	 *
	 * @param FilterNode                  $node      AST node to compile.
	 * @param array<string, string>       $columnMap Allowlisted API field => SQL column mapping.
	 * @param list<string|int|float|null> $params    Bound parameter accumulator.
	 * @return string
	 */
	private static function compileNode(FilterNode $node, array $columnMap, array &$params): string {
		if ($node->type === FilterNode::TYPE_AND && $node->left !== null && $node->right !== null) {
			return '(' . self::compileNode($node->left, $columnMap, $params) . ' AND ' . self::compileNode($node->right, $columnMap, $params) . ')';
		}

		if ($node->type === FilterNode::TYPE_OR && $node->left !== null && $node->right !== null) {
			return '(' . self::compileNode($node->left, $columnMap, $params) . ' OR ' . self::compileNode($node->right, $columnMap, $params) . ')';
		}

		if ($node->type === FilterNode::TYPE_NOT && $node->left !== null) {
			return '(NOT ' . self::compileNode($node->left, $columnMap, $params) . ')';
		}

		$field = $node->field ?? '';

		if (!array_key_exists($field, $columnMap)) {
			throw ApiException::invalidArgument(
				sprintf('Filter field "%s" is not allowed.', $field),
				[['field' => 'filter', 'description' => sprintf('Field "%s" is not filterable on this resource.', $field)]]
			);
		}

		$column = $columnMap[$field];

		if (preg_match(self::SAFE_COLUMN_PATTERN, $column) !== 1) {
			throw ApiException::internal('Invalid internal filter column configuration.');
		}

		$op    = $node->operator ?? '=';
		$value = $node->value;

		if ($value === null) {
			if ($op === '=') {
				return $column . ' IS NULL';
			}

			if ($op === '!=') {
				return $column . ' IS NOT NULL';
			}

			throw ApiException::invalidArgument(
				sprintf('Operator "%s" cannot be used with null.', $op),
				[['field' => 'filter', 'description' => 'Only = and != support null values.']]
			);
		}

		if ($op === ':') {
			$escaped  = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], is_bool($value) ? ($value ? '1' : '0') : (string) $value);
			$params[] = '%' . $escaped . '%';

			return $column . ' LIKE ?';
		}

		if (!in_array($op, ['=', '!=', '<', '<=', '>', '>='], true)) {
			throw ApiException::invalidArgument(
				sprintf('Unsupported filter operator "%s".', $op),
				[['field' => 'filter', 'description' => 'Operator is not supported.']]
			);
		}

		$params[] = is_bool($value) ? ($value ? 1 : 0) : $value;

		return $column . ' ' . $op . ' ?';
	}

	/**
	 * Compare an in-memory field value against a filter literal.
	 *
	 * @param mixed                      $actual Record field value.
	 * @param string|int|float|bool|null $target Filter literal value.
	 * @return bool
	 */
	private static function equalsLoose(mixed $actual, string|int|float|bool|null $target): bool {
		if ($target === null) {
			return $actual === null;
		}

		if (is_bool($target)) {
			if (is_bool($actual)) {
				return $actual === $target;
			}

			if (is_int($actual) || is_string($actual)) {
				$normalized = in_array(strtolower((string) $actual), ['1', 'true', 'on'], true);

				return $normalized === $target;
			}

			return false;
		}

		if (is_numeric($actual) && is_numeric($target)) {
			return (float) $actual === (float) $target;
		}

		return is_scalar($actual) && (string) $actual === (string) $target;
	}
}
