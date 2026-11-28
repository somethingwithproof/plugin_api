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
 * Immutable AST node for Google AIP-160 filter expressions.
 */
final class FilterNode {
	/**
	 * Comparison leaf node (field op value).
	 */
	public const TYPE_COMPARISON = 'comparison';

	/**
	 * Logical conjunction node (left AND right).
	 */
	public const TYPE_AND = 'and';

	/**
	 * Logical disjunction node (left OR right).
	 */
	public const TYPE_OR = 'or';

	/**
	 * Logical negation node (NOT child).
	 */
	public const TYPE_NOT = 'not';

	/**
	 * Construct a FilterNode.
	 *
	 * @param string                  $type     Node type constant.
	 * @param string|null             $field    Field identifier for comparison nodes.
	 * @param string|null             $operator Comparison operator (=, !=, <, <=, >, >=, :).
	 * @param string|int|float|bool|null $value Scalar comparison value.
	 * @param self|null               $left     Left child for AND/OR or sole child for NOT.
	 * @param self|null               $right    Right child for AND/OR.
	 */
	public function __construct(
		public readonly string $type,
		public readonly ?string $field = null,
		public readonly ?string $operator = null,
		public readonly string|int|float|bool|null $value = null,
		public readonly ?self $left = null,
		public readonly ?self $right = null,
	) {
	}

	/**
	 * Create a comparison leaf node.
	 *
	 * @param string                     $field    API field identifier.
	 * @param string                     $operator Comparison operator.
	 * @param string|int|float|bool|null $value    Literal value.
	 * @return self
	 */
	public static function comparison(string $field, string $operator, string|int|float|bool|null $value): self {
		return new self(self::TYPE_COMPARISON, $field, $operator, $value);
	}

	/**
	 * Create a logical AND node.
	 *
	 * @param self $left  Left operand.
	 * @param self $right Right operand.
	 * @return self
	 */
	public static function logicalAnd(self $left, self $right): self {
		return new self(self::TYPE_AND, null, null, null, $left, $right);
	}

	/**
	 * Create a logical OR node.
	 *
	 * @param self $left  Left operand.
	 * @param self $right Right operand.
	 * @return self
	 */
	public static function logicalOr(self $left, self $right): self {
		return new self(self::TYPE_OR, null, null, null, $left, $right);
	}

	/**
	 * Create a logical NOT node.
	 *
	 * @param self $child Negated child expression.
	 * @return self
	 */
	public static function logicalNot(self $child): self {
		return new self(self::TYPE_NOT, null, null, null, $child, null);
	}
}
