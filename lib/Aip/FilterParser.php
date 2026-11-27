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
	 * Token stream produced by lexing.
	 *
	 * @var list<array{type: string, value: string}>
	 */
	private array $tokens = [];

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

		$parser         = new self();
		$parser->tokens = $parser->tokenize($trimmed);
		$parser->pos    = 0;

		if ($parser->tokens === []) {
			return null;
		}

		$node = $parser->parseOr(0);

		if ($parser->pos < count($parser->tokens)) {
			$unexpected = $parser->tokens[$parser->pos]['value'];

			throw ApiException::invalidArgument(
				sprintf('Unexpected token "%s" in filter expression.', $unexpected),
				[['field' => 'filter', 'description' => 'Trailing or malformed syntax.']]
			);
		}

		return $node;
	}

	/**
	 * Tokenize an AIP-160 filter string.
	 *
	 * @param string $input Trimmed filter expression.
	 * @return list<array{type: string, value: string}>
	 * @throws ApiException On invalid characters or unterminated string literals.
	 */
	private function tokenize(string $input): array {
		$tokens = [];
		$len    = strlen($input);
		$i      = 0;

		while ($i < $len) {
			$ch = $input[$i];

			if (ctype_space($ch)) {
				$i++;

				continue;
			}

			if (count($tokens) >= self::MAX_TOKENS) {
				throw ApiException::invalidArgument(
					'Filter expression exceeds maximum token limit.',
					[['field' => 'filter', 'description' => sprintf('At most %d tokens allowed.', self::MAX_TOKENS)]]
				);
			}

			if ($ch === '(') {
				$tokens[] = ['type' => 'LPAREN', 'value' => '('];
				$i++;

				continue;
			}

			if ($ch === ')') {
				$tokens[] = ['type' => 'RPAREN', 'value' => ')'];
				$i++;

				continue;
			}

			if ($i + 1 < $len) {
				$two = $ch . $input[$i + 1];

				if ($two === '!=' || $two === '<=' || $two === '>=') {
					$tokens[] = ['type' => 'OP', 'value' => $two];
					$i        += 2;

					continue;
				}
			}

			if ($ch === '=' || $ch === '<' || $ch === '>' || $ch === ':') {
				$tokens[] = ['type' => 'OP', 'value' => $ch];
				$i++;

				continue;
			}

			if ($ch === '-' && ($i + 1 >= $len || !ctype_digit($input[$i + 1]))) {
				$tokens[] = ['type' => 'NOT', 'value' => '-'];
				$i++;

				continue;
			}

			if ($ch === '"' || $ch === "'") {
				$quote = $ch;
				$i++;
				$buf    = '';
				$closed = false;

				while ($i < $len) {
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

				$tokens[] = ['type' => 'STRING', 'value' => $buf];

				continue;
			}

			if ($ch === '-' || ctype_digit($ch)) {
				$start = $i;

				if ($ch === '-') {
					$i++;
				}

				$dots = 0;

				while ($i < $len && (ctype_digit($input[$i]) || ($input[$i] === '.' && $dots === 0))) {
					if ($input[$i] === '.') {
						$dots++;
					}
					$i++;
				}

				$tokens[] = ['type' => 'NUMBER', 'value' => substr($input, $start, $i - $start)];

				continue;
			}

			if (ctype_alpha($ch) || $ch === '_') {
				$start = $i;

				while ($i < $len && (ctype_alnum($input[$i]) || $input[$i] === '_' || $input[$i] === '.' || $input[$i] === '-')) {
					$i++;
				}

				$word = substr($input, $start, $i - $start);

				if ($word === 'AND') {
					$tokens[] = ['type' => 'AND', 'value' => $word];
				} elseif ($word === 'OR') {
					$tokens[] = ['type' => 'OR', 'value' => $word];
				} elseif ($word === 'NOT') {
					$tokens[] = ['type' => 'NOT', 'value' => $word];
				} else {
					$tokens[] = ['type' => 'IDENT', 'value' => $word];
				}

				continue;
			}

			throw ApiException::invalidArgument(
				sprintf('Unsupported character "%s" in filter expression.', $ch),
				[['field' => 'filter', 'description' => 'Contains characters not permitted by AIP-160 grammar.']]
			);
		}

		return $tokens;
	}

	/**
	 * Parse a disjunction (OR) expression.
	 *
	 * @param int $depth Current recursion depth.
	 * @return FilterNode
	 */
	private function parseOr(int $depth): FilterNode {
		$left = $this->parseAnd($depth);

		while ($this->peekType() === 'OR') {
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

		while ($this->peekType() === 'AND') {
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

		$type = $this->peekType();

		if ($type === 'NOT') {
			$this->pos++;

			return FilterNode::logicalNot($this->parseFactor($depth + 1));
		}

		if ($type === 'LPAREN') {
			$this->pos++;
			$node = $this->parseOr($depth + 1);

			if ($this->peekType() !== 'RPAREN') {
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
		$fieldToken = $this->consume('IDENT', 'Expected field identifier in filter expression.');
		$opToken    = $this->consume('OP', 'Expected comparison operator (=, !=, <, <=, >, >=, :) in filter expression.');
		$value      = $this->consumeLiteral();

		return FilterNode::comparison($fieldToken['value'], $opToken['value'], $value);
	}

	/**
	 * Consume and convert a scalar comparison literal token.
	 *
	 * @return string|int|float|bool|null
	 */
	private function consumeLiteral(): string|int|float|bool|null {
		if ($this->pos >= count($this->tokens)) {
			throw ApiException::invalidArgument(
				'Expected comparison value in filter expression.',
				[['field' => 'filter', 'description' => 'Unexpected end of filter expression.']]
			);
		}

		$token = $this->tokens[$this->pos];
		$this->pos++;

		if ($token['type'] === 'STRING') {
			return $token['value'];
		}

		if ($token['type'] === 'NUMBER') {
			return str_contains($token['value'], '.') ? (float) $token['value'] : (int) $token['value'];
		}

		if ($token['type'] === 'IDENT') {
			$lower = strtolower($token['value']);

			if ($lower === 'true') {
				return true;
			}

			if ($lower === 'false') {
				return false;
			}

			if ($lower === 'null') {
				return null;
			}

			return $token['value'];
		}

		throw ApiException::invalidArgument(
			sprintf('Invalid literal value "%s" in filter expression.', $token['value']),
			[['field' => 'filter', 'description' => 'Expected string, number, boolean, or identifier literal.']]
		);
	}

	/**
	 * Peek the type of the next token or null at end-of-stream.
	 *
	 * @return string|null
	 */
	private function peekType(): ?string {
		return $this->tokens[$this->pos]['type'] ?? null;
	}

	/**
	 * Consume a token of the expected type or throw INVALID_ARGUMENT.
	 *
	 * @param string $expectedType Expected token type.
	 * @param string $errorMessage Message if token is missing or mismatched.
	 * @return array{type: string, value: string}
	 */
	private function consume(string $expectedType, string $errorMessage): array {
		if ($this->pos >= count($this->tokens) || $this->tokens[$this->pos]['type'] !== $expectedType) {
			throw ApiException::invalidArgument(
				$errorMessage,
				[['field' => 'filter', 'description' => $errorMessage]]
			);
		}

		$token = $this->tokens[$this->pos];
		$this->pos++;

		return $token;
	}
}
