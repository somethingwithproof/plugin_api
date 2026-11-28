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
 * Google AIP-161 field mask parser, validator, and response projector for
 * update_mask and read_mask parameters.
 */
final class FieldMask {
	/**
	 * Valid field path syntax.
	 */
	private const PATH_PATTERN = '/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)*$/';

	/**
	 * Maximum accepted raw field mask string length in bytes.
	 */
	private const MAX_LENGTH = 2048;

	/**
	 * Maximum permitted number of distinct field paths in a mask.
	 */
	private const MAX_PATHS = 64;

	/**
	 * Construct a validated FieldMask instance.
	 *
	 * @param list<string> $paths Validated field paths.
	 */
	public function __construct(public readonly array $paths) {
	}

	/**
	 * Parse a comma-separated field mask string or list of field names.
	 *
	 * @param string|list<string>|null $raw       Raw mask from query parameter or request body.
	 * @param string                   $paramName Parameter name for BadRequest error reporting.
	 * @return self
	 * @throws ApiException If any path has invalid syntax or exceeds safety bounds.
	 */
	public static function parse(string|array|null $raw, string $paramName = 'update_mask'): self {
		if ($raw === null || $raw === '' || $raw === []) {
			return new self([]);
		}

		if (is_string($raw)) {
			if (strlen($raw) > self::MAX_LENGTH) {
				throw ApiException::invalidArgument(
					sprintf('%s exceeds maximum length.', $paramName),
					[['field' => $paramName, 'description' => sprintf('Field mask must be at most %d bytes.', self::MAX_LENGTH)]]
				);
			}

			$rawParts = explode(',', $raw, self::MAX_PATHS + 2);
		} else {
			$rawParts = $raw;
		}

		if (count($rawParts) > self::MAX_PATHS) {
			throw ApiException::invalidArgument(
				sprintf('%s exceeds maximum field count.', $paramName),
				[['field' => $paramName, 'description' => sprintf('At most %d field paths are permitted.', self::MAX_PATHS)]]
			);
		}

		$paths = [];
		$seen  = [];

		foreach ($rawParts as $part) {
			$trimmed = trim($part);

			if ($trimmed === '') {
				continue;
			}

			if ($trimmed === '*') {
				throw ApiException::invalidArgument(
					sprintf('Wildcard "*" is not permitted in %s.', $paramName),
					[['field' => $paramName, 'description' => 'Specify explicit field paths rather than "*".']]
				);
			}

			if (preg_match(self::PATH_PATTERN, $trimmed) !== 1) {
				throw ApiException::invalidArgument(
					sprintf('Invalid field path "%s" in %s.', $trimmed, $paramName),
					[['field' => $paramName, 'description' => 'Field paths must use alphanumeric and underscore characters.']]
				);
			}

			if (!isset($seen[$trimmed])) {
				$seen[$trimmed] = true;
				$paths[]        = $trimmed;
			}
		}

		return new self($paths);
	}

	/**
	 * Validate an update_mask against a resource's mutable field allowlist.
	 *
	 * @param list<string> $mutableFields Allowed mutable fields for the target resource.
	 * @param string       $resourceLabel Resource label for error messages.
	 * @return void
	 * @throws ApiException If the mask is empty or includes any immutable or unknown field.
	 */
	public function validateUpdateMask(array $mutableFields, string $resourceLabel): void {
		if ($this->paths === []) {
			throw ApiException::invalidArgument(
				'update_mask is required for partial updates.',
				[['field' => 'update_mask', 'description' => 'Provide one or more comma-separated mutable field names.']]
			);
		}

		$useMap     = count($mutableFields) > 24;
		$allowedMap = $useMap ? array_fill_keys($mutableFields, true) : [];
		$violations = [];

		foreach ($this->paths as $path) {
			$ok = $useMap ? isset($allowedMap[$path]) : in_array($path, $mutableFields, true);

			if (!$ok) {
				$violations[] = [
					'field'       => 'update_mask',
					'description' => sprintf('Field "%s" is not mutable on %s.', $path, $resourceLabel),
				];
			}
		}

		if ($violations !== []) {
			throw ApiException::invalidArgument(
				sprintf('Invalid update_mask field "%s".', $this->paths[0]),
				$violations
			);
		}
	}

	/**
	 * Validate a read_mask against a resource's readable field allowlist.
	 *
	 * @param list<string> $readableFields Allowed readable fields for the target resource.
	 * @param string       $resourceLabel  Resource label for error messages.
	 * @return void
	 * @throws ApiException If any requested field is outside the readable allowlist.
	 */
	public function validateReadMask(array $readableFields, string $resourceLabel): void {
		$useMap     = count($readableFields) > 24;
		$allowedMap = $useMap ? array_fill_keys($readableFields, true) : [];
		$violations = [];

		foreach ($this->paths as $path) {
			$ok = $useMap ? isset($allowedMap[$path]) : in_array($path, $readableFields, true);

			if (!$ok) {
				$violations[] = [
					'field'       => 'read_mask',
					'description' => sprintf('Field "%s" is not readable on %s.', $path, $resourceLabel),
				];
			}
		}

		if ($violations !== []) {
			throw ApiException::invalidArgument(
				sprintf('Invalid read_mask field "%s".', $this->paths[0]),
				$violations
			);
		}
	}

	/**
	 * Extract only fields explicitly permitted by this update_mask from a request payload.
	 *
	 * Prevents mass-assignment of immutable or unauthorized attributes.
	 *
	 * @param array<string, mixed> $payload Decoded JSON request body.
	 * @return array<string, mixed>
	 */
	public function extractAllowedFields(array $payload): array {
		$filtered = [];

		foreach ($this->paths as $path) {
			if (array_key_exists($path, $payload)) {
				$filtered[$path] = $payload[$path];
			}
		}

		return $filtered;
	}

	/**
	 * Project a resource payload to only the fields requested in a read_mask.
	 *
	 * When the mask is empty, returns the full payload unchanged. Always keeps
	 * "name" when present if requested explicitly or when mask is empty.
	 *
	 * @param array<string, mixed> $resource Full serialized resource array.
	 * @return array<string, mixed>
	 */
	public function project(array $resource): array {
		if ($this->paths === []) {
			return $resource;
		}

		$projected = [];

		foreach ($this->paths as $path) {
			if (array_key_exists($path, $resource)) {
				$projected[$path] = $resource[$path];
			}
		}

		return $projected;
	}

	/**
	 * Return true when no field paths were specified.
	 *
	 * @return bool
	 */
	public function isEmpty(): bool {
		return $this->paths === [];
	}
}
