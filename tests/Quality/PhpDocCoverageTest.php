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

namespace Somethingwithproof\Api\Tests\Quality;

use PHPUnit\Framework\TestCase;

/**
 * Enforces 100% PHPDoc coverage across every PHP class, interface, trait, enum,
 * constant, property, method, and top-level function in lib/ and setup.php, and
 * verifies zero hard PHP imports of sibling plugins.
 */
final class PhpDocCoverageTest extends TestCase {
	/**
	 * Verify every declared PHP symbol in lib/ and setup.php carries a PHPDoc block.
	 *
	 * @return void
	 */
	public function testAllSymbolsHavePhpDoc(): void {
		$root  = dirname(__DIR__, 2);
		$files = [$root . '/setup.php'];

		$iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/lib'));

		foreach ($iter as $file) {
			if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
				$files[] = $file->getPathname();
			}
		}

		sort($files);
		$missing = [];

		foreach ($files as $path) {
			$source = file_get_contents($path);
			$this->assertIsString($source);
			$rel    = str_replace($root . '/', '', $path);
			$tokens = \PhpToken::tokenize($source);
			$count  = count($tokens);

			$braceDepth       = 0;
			$parenDepth       = 0;
			$typeBodyDepth    = null;
			$pendingDoc       = null;
			$lastMethodDoc    = null;
			$pendingModifiers = false;

			for ($i = 0; $i < $count; $i++) {
				$tok = $tokens[$i];

				if ($tok->id === T_WHITESPACE || $tok->id === T_COMMENT) {
					continue;
				}

				if ($tok->id === T_DOC_COMMENT) {
					$pendingDoc = $tok->text;

					continue;
				}

				if ($tok->text === '(') {
					$parenDepth++;

					continue;
				}

				if ($tok->text === ')') {
					$parenDepth       = max(0, $parenDepth - 1);
					$pendingModifiers = false;

					continue;
				}

				if ($tok->text === '{') {
					$braceDepth++;
					$pendingDoc       = null;
					$pendingModifiers = false;

					continue;
				}

				if ($tok->text === '}') {
					$braceDepth--;

					if ($typeBodyDepth !== null && $braceDepth < $typeBodyDepth) {
						$typeBodyDepth = null;
					}

					$pendingDoc       = null;
					$pendingModifiers = false;

					continue;
				}

				// Ignore anonymous classes.
				if ($tok->id === T_NEW) {
					$pendingDoc       = null;
					$pendingModifiers = false;

					continue;
				}

				// Modifiers precede class/method/property/const declarations.
				if (in_array($tok->id, [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_FINAL, T_ABSTRACT, T_READONLY], true)) {
					$pendingModifiers = true;

					continue;
				}

				// Class, interface, trait, or enum declaration.
				if (in_array($tok->id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
					$name = $this->nextIdentifier($tokens, $i + 1);

					if ($name !== null) {
						if ($pendingDoc === null) {
							$missing[] = sprintf('%s:%d missing PHPDoc on %s %s', $rel, $tok->line, $tok->text, $name);
						}

						$typeBodyDepth = $braceDepth + 1;
					}

					$pendingDoc       = null;
					$pendingModifiers = false;

					continue;
				}

				// Function or method declaration (skip closures without a name).
				if ($tok->id === T_FUNCTION) {
					$name = $this->nextIdentifier($tokens, $i + 1);

					if ($name !== null) {
						$atTopLevel  = $typeBodyDepth === null && $braceDepth === 0;
						$atClassBody = $typeBodyDepth !== null && $braceDepth === $typeBodyDepth;

						if (($atTopLevel || $atClassBody) && $pendingDoc === null) {
							$missing[] = sprintf('%s:%d missing PHPDoc on function/method %s()', $rel, $tok->line, $name);
						}

						$lastMethodDoc = $pendingDoc;
					}

					$pendingDoc       = null;
					$pendingModifiers = false;

					continue;
				}

				// Class constant declaration.
				if ($tok->id === T_CONST && $parenDepth === 0 && $typeBodyDepth !== null && $braceDepth === $typeBodyDepth) {
					$name = $this->nextIdentifier($tokens, $i + 1);

					if ($name !== null && $pendingDoc === null) {
						$missing[] = sprintf('%s:%d missing PHPDoc on constant %s', $rel, $tok->line, $name);
					}

					$pendingDoc       = null;
					$pendingModifiers = false;

					continue;
				}

				// Class property or constructor-promoted property declaration.
				if ($tok->id === T_VARIABLE && $pendingModifiers && $typeBodyDepth !== null && $braceDepth === $typeBodyDepth) {
					if ($parenDepth > 0) {
						// Promoted constructor property: must be documented either directly or via @param on __construct.
						$documented = $pendingDoc !== null || ($lastMethodDoc !== null && str_contains($lastMethodDoc, $tok->text));

						if (!$documented) {
							$missing[] = sprintf('%s:%d missing PHPDoc/@param on promoted property %s', $rel, $tok->line, $tok->text);
						}
					} elseif ($pendingDoc === null) {
						$missing[] = sprintf('%s:%d missing PHPDoc on property %s', $rel, $tok->line, $tok->text);
					}

					$pendingDoc       = null;
					$pendingModifiers = false;

					continue;
				}

				// Reset pending doc on statement terminator.
				if ($tok->text === ';') {
					$pendingDoc       = null;
					$pendingModifiers = false;
				}
			}
		}

		$this->assertSame([], $missing, "Symbols missing PHPDoc:\n" . implode("\n", $missing));
	}

	/**
	 * Verify plugin_api has zero hard PHP imports of sibling plugins.
	 *
	 * @return void
	 */
	public function testZeroHardSiblingPluginImports(): void {
		$root  = dirname(__DIR__, 2);
		$files = [
			$root . '/setup.php',
			$root . '/api.php',
			$root . '/api_view.php',
			$root . '/api_tokens.php',
			$root . '/api_admin.php',
			$root . '/cli/api.php',
		];

		$iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/lib'));

		foreach ($iter as $file) {
			if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
				$files[] = $file->getPathname();
			}
		}

		$forbidden = [
			'/^use\s+Somethingwithproof\\\\(Casc|Doctor|Mcp|Yaml|Cloud|Gpu|Fabric|Redfish)/m',
		];

		foreach ($files as $path) {
			$source = file_get_contents($path);
			$this->assertIsString($source);

			foreach ($forbidden as $pattern) {
				$this->assertDoesNotMatchRegularExpression($pattern, $source, sprintf('Forbidden hard sibling plugin import in %s', $path));
			}
		}
	}

	/**
	 * Find the next T_STRING identifier token immediately following a declaration keyword.
	 *
	 * @param list<\PhpToken> $tokens Token list.
	 * @param int             $start  Starting token index.
	 * @return string|null
	 */
	private function nextIdentifier(array $tokens, int $start): ?string {
		$count = count($tokens);

		for ($i = $start; $i < $count; $i++) {
			$t = $tokens[$i];

			if ($t->id === T_WHITESPACE || $t->id === T_COMMENT || $t->text === '&') {
				continue;
			}

			if ($t->id === T_STRING) {
				return $t->text;
			}

			return null;
		}

		return null;
	}
}
