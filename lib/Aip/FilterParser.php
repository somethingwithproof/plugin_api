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
 * Recursive-descent parser for Google AIP-160 filter expressions.
 *
 * Grammar:
 *   expr       := term ( "OR" term )*
 *   term       := factor ( "AND" factor )*
 *   factor     := ("NOT" | "-") factor | "(" expr ")" | comparison
 *   comparison := ident ( "=" | "!=" | "<" | "<=" | ">" | ">=" | ":" ) literal
 */
final class FilterParser {
	/**
	 * Maximum accepted filter string length in bytes.
	 */
	private const MAX_LENGTH = 2048;

	/**
	 * Maximum token count accepted per filter expression.
	 */
	private const MAX_TOKENS = 128;

	/**
	 * Maximum parenthesized/negated recursion depth.
	 */
	private const MAX_DEPTH = 16;

	/**
	 * Token type: left parenthesis.
	 */
	private const TOK_LPAREN = 1;

	/**
	 * Token type: right parenthesis.
	 */
	private const TOK_RPAREN = 2;

	/**
	 * Token type: comparison operator.
	 */
	private const TOK_OP = 3;

	/**
	 * Token type: logical negation.
	 */
	private const TOK_NOT = 4;

	/**
	 * Token type: logical conjunction.
	 */
	private const TOK_AND = 5;

	/**
	 * Token type: logical disjunction.
	 */
	private const TOK_OR = 6;

	/**
	 * Token type: quoted string literal.
	 */
	private const TOK_STRING = 7;

	/**
	 * Token type: numeric literal.
	 */
	private const TOK_NUMBER = 8;

	/**
	 * Token type: identifier or bare keyword literal.
	 */
	private const TOK_IDENT = 9;

	/**
	 * ASCII whitespace mask for C-speed strspn scanning.
	 */
	private const WS_CHARS = " \t\n\r\f\v";

	/**
	 * ASCII digit mask for C-speed strspn scanning.
	 */
	private const DIGIT_CHARS = '0123456789';

	/**
	 * ASCII identifier continuation mask for C-speed strspn scanning.
	 */
	private const IDENT_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_.-';

	/**
	 * Parallel array of integer token type codes produced by lexing.
	 *
	 * @var list<int>
	 */
	private array $tokenTypes = [];

	/**
	 * Parallel array of token lexeme values produced by lexing.
	 *
	 * @var list<string>
	 */
	private array $tokenValues = [];

	/**
	 * Cached token count for O(1) cursor bounds checks.
	 *
	 * @var int
	 */
	private int $tokenCount = 0;

	/**
	 * Current token cursor index.
	 *
	 * @var int
	 */
	private int $pos = 0;

	/**
	 * Parse an AIP-160 filter expression into a FilterNode AST, or null if empty.
	 *
	 * @param string $filter Raw filter query parameter.
	 * @return FilterNode|null
	 * @throws ApiException If the expression violates AIP-160 grammar or safety bounds.
	 */
	public static function parse(string $filter): ?FilterNode {
		$trimmed = trim($filter);

		if ($trimmed === '') {
			return null;
		}

		if (strlen($trimmed) > self::MAX_LENGTH) {
			throw ApiException::invalidArgument(
				'Filter expression exceeds maximum length.',
				[['field' => 'filter', 'description' => sprintf('Filter must be at most %d bytes.', self::MAX_LENGTH)]]
			);
		}

		$parser = new self();
		$parser->tokenize($trimmed);

		if ($parser->tokenCount === 0) {
			return null;
		}

		$node = $parser->parseOr(0);

		if ($parser->pos < $parser->tokenCount) {
			$unexpected = $parser->tokenValues[$parser->pos];

			throw ApiException::invalidArgument(
				sprintf('Unexpected token "%s" in filter expression.', $unexpected),
				[['field' => 'filter', 'description' => 'Trailing or malformed syntax.']]
			);
		}

		return $node;
	}

	/**
	 * Single-pass O(N) tokenizer storing parallel type/value arrays to avoid per-token hash tables.
	 *
	 * @param string $input Trimmed filter expression.
	 * @return void
	 * @throws ApiException On invalid characters, token limit overflow, or unterminated strings.
	 */
	private function tokenize(string $input): void {
		$types  = [];
		$values = [];
		$count  = 0;
		$len    = strlen($input);
		$i      = 0;

		while ($i < $len) {
			$ws = strspn($input, self::WS_CHARS, $i);

			if ($ws > 0) {
				$i += $ws;

				if ($i >= $len) {
					break;
				}
			}

			if ($count >= self::MAX_TOKENS) {
				throw ApiException::invalidArgument(
					'Filter expression exceeds maximum token limit.',
					[['field' => 'filter', 'description' => sprintf('At most %d tokens allowed.', self::MAX_TOKENS)]]
				);
			}

			$ch = $input[$i];

			if ($ch === '(') {
				$types[]  = self::TOK_LPAREN;
				$values[] = '(';
				$count++;
				$i++;

				continue;
			}

			if ($ch === ')') {
				$types[]  = self::TOK_RPAREN;
				$values[] = ')';
				$count++;
				$i++;

				continue;
			}

			if ($ch === '=' || $ch === ':') {
				$types[]  = self::TOK_OP;
				$values[] = $ch;
				$count++;
				$i++;

				continue;
			}

			if ($ch === '!' && $i + 1 < $len && $input[$i + 1] === '=') {
				$types[]  = self::TOK_OP;
				$values[] = '!=';
				$count++;
				$i += 2;

				continue;
			}

			if ($ch === '<' || $ch === '>') {
				if ($i + 1 < $len && $input[$i + 1] === '=') {
					$types[]  = self::TOK_OP;
					$values[] = $ch . '=';
					$i        += 2;
				} else {
					$types[]  = self::TOK_OP;
					$values[] = $ch;
					$i++;
				}
				$count++;

				continue;
			}

			if ($ch === '-') {
				if ($i + 1 >= $len || $input[$i + 1] < '0' || $input[$i + 1] > '9') {
					$types[]  = self::TOK_NOT;
					$values[] = '-';
					$count++;
					$i++;

					continue;
				}
			}

			if ($ch === '"' || $ch === "'") {
				$quote  = $ch;
				$stop   = $quote . '\\';
				$i++;
				$buf    = '';
				$closed = false;

				while ($i < $len) {
					$span = strcspn($input, $stop, $i);

					if ($span > 0) {
						$buf .= substr($input, $i, $span);
						$i   += $span;
					}

					if ($i >= $len) {
						break;
					}

					$c = $input[$i];

					if ($c === '\\' && $i + 1 < $len) {
						$buf .= $input[$i + 1];
						$i   += 2;

						continue;
					}

					if ($c === $quote) {
						$closed = true;
						$i++;

						break;
					}

					$buf .= $c;
					$i++;
				}

				if (!$closed) {
					throw ApiException::invalidArgument(
						'Unterminated string literal in filter expression.',
						[['field' => 'filter', 'description' => 'Missing closing quote.']]
					);
				}

				$types[]  = self::TOK_STRING;
				$values[] = $buf;
				$count++;

				continue;
			}

			if ($ch === '-' || ($ch >= '0' && $ch <= '9')) {
				$start = $i;

				if ($ch === '-') {
					$i++;
				}

				$i += strspn($input, self::DIGIT_CHARS, $i);

				if ($i < $len && $input[$i] === '.') {
					$i++;
					$i += strspn($input, self::DIGIT_CHARS, $i);
				}

				$types[]  = self::TOK_NUMBER;
				$values[] = substr($input, $start, $i - $start);
				$count++;

				continue;
			}

			if (($ch >= 'a' && $ch <= 'z') || ($ch >= 'A' && $ch <= 'Z') || $ch === '_') {
				$span = strspn($input, self::IDENT_CHARS, $i);
				$word = substr($input, $i, $span);
				$i   += $span;

				if ($word === 'AND') {
					$types[] = self::TOK_AND;
				} elseif ($word === 'OR') {
					$types[] = self::TOK_OR;
				} elseif ($word === 'NOT') {
					$types[] = self::TOK_NOT;
				} else {
					$types[] = self::TOK_IDENT;
				}

				$values[] = $word;
				$count++;

				continue;
			}

			throw ApiException::invalidArgument(
				sprintf('Unsupported character "%s" in filter expression.', $ch),
				[['field' => 'filter', 'description' => 'Contains characters not permitted by AIP-160 grammar.']]
			);
		}

		$this->tokenTypes  = $types;
		$this->tokenValues = $values;
		$this->tokenCount  = $count;
		$this->pos         = 0;
	}

	/**
	 * Parse a disjunction (OR) expression.
	 *
	 * @param int $depth Current recursion depth.
	 * @return FilterNode
	 */
	private function parseOr(int $depth): FilterNode {
		$left = $this->parseAnd($depth);

		while ($this->pos < $this->tokenCount && $this->tokenTypes[$this->pos] === self::TOK_OR) {
			$this->pos++;
			$right = $this->parseAnd($depth);
			$left  = FilterNode::logicalOr($left, $right);
		}

		return $left;
	}

	/**
	 * Parse a conjunction (AND) expression.
	 *
	 * @param int $depth Current recursion depth.
	 * @return FilterNode
	 */
	private function parseAnd(int $depth): FilterNode {
		$left = $this->parseFactor($depth);

		while ($this->pos < $this->tokenCount && $this->tokenTypes[$this->pos] === self::TOK_AND) {
			$this->pos++;
			$right = $this->parseFactor($depth);
			$left  = FilterNode::logicalAnd($left, $right);
		}

		return $left;
	}

	/**
	 * Parse a negated, parenthesized, or comparison factor.
	 *
	 * @param int $depth Current recursion depth.
	 * @return FilterNode
	 */
	private function parseFactor(int $depth): FilterNode {
		if ($depth > self::MAX_DEPTH) {
			throw ApiException::invalidArgument(
				'Filter expression exceeds maximum nesting depth.',
				[['field' => 'filter', 'description' => sprintf('Nesting depth must not exceed %d.', self::MAX_DEPTH)]]
			);
		}

		$type = $this->pos < $this->tokenCount ? $this->tokenTypes[$this->pos] : 0;

		if ($type === self::TOK_NOT) {
			$this->pos++;

			return FilterNode::logicalNot($this->parseFactor($depth + 1));
		}

		if ($type === self::TOK_LPAREN) {
			$this->pos++;
			$node = $this->parseOr($depth + 1);

			if ($this->pos >= $this->tokenCount || $this->tokenTypes[$this->pos] !== self::TOK_RPAREN) {
				throw ApiException::invalidArgument(
					'Missing closing parenthesis in filter expression.',
					[['field' => 'filter', 'description' => 'Expected ")".']]
				);
			}

			$this->pos++;

			return $node;
		}

		return $this->parseComparison();
	}

	/**
	 * Parse a leaf field comparison (field op value).
	 *
	 * @return FilterNode
	 */
	private function parseComparison(): FilterNode {
		$field = $this->consumeValue(self::TOK_IDENT, 'Expected field identifier in filter expression.');
		$op    = $this->consumeValue(self::TOK_OP, 'Expected comparison operator (=, !=, <, <=, >, >=, :) in filter expression.');
		$value = $this->consumeLiteral();

		return FilterNode::comparison($field, $op, $value);
	}

	/**
	 * Consume and convert a scalar comparison literal token.
	 *
	 * @return string|int|float|bool|null
	 */
	private function consumeLiteral(): string|int|float|bool|null {
		if ($this->pos >= $this->tokenCount) {
			throw ApiException::invalidArgument(
				'Expected comparison value in filter expression.',
				[['field' => 'filter', 'description' => 'Unexpected end of filter expression.']]
			);
		}

		$type  = $this->tokenTypes[$this->pos];
		$value = $this->tokenValues[$this->pos];
		$this->pos++;

		if ($type === self::TOK_STRING) {
			return $value;
		}

		if ($type === self::TOK_NUMBER) {
			return str_contains($value, '.') ? (float) $value : (int) $value;
		}

		if ($type === self::TOK_IDENT) {
			$lower = strtolower($value);

			if ($lower === 'true') {
				return true;
			}

			if ($lower === 'false') {
				return false;
			}

			if ($lower === 'null') {
				return null;
			}

			return $value;
		}

		throw ApiException::invalidArgument(
			sprintf('Invalid literal value "%s" in filter expression.', $value),
			[['field' => 'filter', 'description' => 'Expected string, number, boolean, or identifier literal.']]
		);
	}

	/**
	 * Consume a token of the expected type and return its lexeme or throw INVALID_ARGUMENT.
	 *
	 * @param int    $expectedType Expected token type constant.
	 * @param string $errorMessage Message if token is missing or mismatched.
	 * @return string
	 */
	private function consumeValue(int $expectedType, string $errorMessage): string {
		if ($this->pos >= $this->tokenCount || $this->tokenTypes[$this->pos] !== $expectedType) {
			throw ApiException::invalidArgument(
				$errorMessage,
				[['field' => 'filter', 'description' => $errorMessage]]
			);
		}

		$value = $this->tokenValues[$this->pos];
		$this->pos++;

		return $value;
	}
}
