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

namespace Somethingwithproof\Api\Http;

use Somethingwithproof\Api\Aip\ApiException;

/**
 * Immutable HTTP request value object with strict Content-Type validation
 * and bounded body parsing.
 */
final class Request {
	/**
	 * Lowercase-keyed HTTP headers map.
	 *
	 * @var array<string, string>
	 */
	private readonly array $headers;

	/**
	 * Construct an immutable HTTP Request.
	 *
	 * @param string                $method    HTTP method (GET, POST, PATCH, DELETE, OPTIONS).
	 * @param string                $path      Normalized API path starting with /v1/...
	 * @param array<string, string> $query     Query parameters map.
	 * @param array<string, string> $headers   HTTP request headers.
	 * @param string                $rawBody   Raw HTTP request body bytes.
	 * @param string                $clientIp  Resolved client IP address.
	 * @param bool                  $isHttps   Whether the request arrived over TLS.
	 * @param int|null              $sessionUserId Optional authenticated Cacti session user ID.
	 */
	public function __construct(
		public readonly string $method,
		public readonly string $path,
		public readonly array $query = [],
		array $headers = [],
		public readonly string $rawBody = '',
		public readonly string $clientIp = '127.0.0.1',
		public readonly bool $isHttps = false,
		public readonly ?int $sessionUserId = null,
	) {
		$normalized = [];

		foreach ($headers as $name => $value) {
			$normalized[strtolower($name)] = trim($value);
		}

		$this->headers = $normalized;
	}

	/**
	 * Build a Request from PHP SAPI superglobals with bounded stdin reading.
	 *
	 * @param int $maxBodyBytes Maximum permitted body size in bytes.
	 * @return self
	 * @throws ApiException If the request body exceeds $maxBodyBytes.
	 */
	public static function fromGlobals(int $maxBodyBytes = 1048576): self {
		$method = strtoupper(is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET');
		$path   = self::extractApiPath($_SERVER);

		$query = [];

		foreach ($_GET as $k => $v) {
			if (is_scalar($v)) {
				$query[(string) $k] = (string) $v;
			}
		}

		$headers = self::extractHeaders($_SERVER);

		$contentLength = $headers['content-length'] ?? null;

		if ($contentLength !== null && is_numeric($contentLength) && (int) $contentLength > $maxBodyBytes) {
			throw ApiException::invalidArgument(
				sprintf('Request body exceeds maximum allowed size of %d bytes.', $maxBodyBytes),
				[['field' => 'body', 'description' => 'Payload too large.']]
			);
		}

		$rawBody = '';
		$stream  = fopen('php://input', 'rb');

		$readLimit = max(1, $maxBodyBytes) + 1;

		if (is_resource($stream)) {
			$chunk = fread($stream, $readLimit);
			fclose($stream);

			if (is_string($chunk)) {
				if (strlen($chunk) > $maxBodyBytes) {
					throw ApiException::invalidArgument(
						sprintf('Request body exceeds maximum allowed size of %d bytes.', $maxBodyBytes),
						[['field' => 'body', 'description' => 'Payload too large.']]
					);
				}

				$rawBody = $chunk;
			}
		}

		$clientIp = is_string($_SERVER['REMOTE_ADDR'] ?? null) && $_SERVER['REMOTE_ADDR'] !== ''
			? $_SERVER['REMOTE_ADDR']
			: '127.0.0.1';

		$httpsVal = is_string($_SERVER['HTTPS'] ?? null) ? strtolower($_SERVER['HTTPS']) : '';
		$proto    = strtolower($headers['x-forwarded-proto'] ?? '');
		$isHttps  = ($httpsVal !== '' && $httpsVal !== 'off') || $proto === 'https';

		$sessionUserId = isset($_SESSION['sess_user_id']) && is_numeric($_SESSION['sess_user_id'])
			? (int) $_SESSION['sess_user_id']
			: null;

		return new self($method, $path, $query, $headers, $rawBody, $clientIp, $isHttps, $sessionUserId);
	}

	/**
	 * Retrieve a header value case-insensitively.
	 *
	 * @param string $name Header name.
	 * @return string|null
	 */
	public function header(string $name): ?string {
		return $this->headers[strtolower($name)] ?? null;
	}

	/**
	 * Retrieve a query string parameter value.
	 *
	 * @param string $name Query parameter name.
	 * @return string|null
	 */
	public function queryParam(string $name): ?string {
		return $this->query[$name] ?? null;
	}

	/**
	 * Decode the request JSON body after validating body size and Content-Type.
	 *
	 * @param int $maxBodyBytes Maximum permitted body size in bytes.
	 * @return array<string, mixed>
	 * @throws ApiException If body is oversized, Content-Type is not JSON, or JSON is invalid.
	 */
	public function jsonBody(int $maxBodyBytes = 1048576): array {
		if (strlen($this->rawBody) > $maxBodyBytes) {
			throw ApiException::invalidArgument(
				sprintf('Request body exceeds maximum allowed size of %d bytes.', $maxBodyBytes),
				[['field' => 'body', 'description' => 'Payload too large.']]
			);
		}

		if (trim($this->rawBody) === '') {
			return [];
		}

		$contentType = strtolower($this->header('Content-Type') ?? '');
		$mediaType   = trim(explode(';', $contentType, 2)[0]);

		if ($mediaType !== 'application/json' && !str_ends_with($mediaType, '+json')) {
			throw ApiException::invalidArgument(
				'Content-Type must be application/json for request bodies.',
				[['field' => 'Content-Type', 'description' => 'Expected application/json media type.']]
			);
		}

		try {
			$decoded = json_decode($this->rawBody, true, 32, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			throw ApiException::invalidArgument(
				'Malformed JSON request body.',
				[['field' => 'body', 'description' => 'Request body is not valid JSON.']]
			);
		}

		if (!is_array($decoded) || array_is_list($decoded)) {
			throw ApiException::invalidArgument(
				'JSON request body must be an object.',
				[['field' => 'body', 'description' => 'Expected a JSON object at top level.']]
			);
		}

		$out = [];

		foreach ($decoded as $k => $v) {
			$out[(string) $k] = $v;
		}

		return $out;
	}

	/**
	 * Extract Bearer token from Authorization header if present.
	 *
	 * @return string|null
	 */
	public function bearerToken(): ?string {
		$auth = $this->header('Authorization');

		if ($auth === null || !str_starts_with($auth, 'Bearer ')) {
			return null;
		}

		$token = trim(substr($auth, 7));

		return $token !== '' ? $token : null;
	}

	/**
	 * Return true when client requested application/yaml in the Accept header.
	 *
	 * @return bool
	 */
	public function prefersYaml(): bool {
		$accept = strtolower($this->header('Accept') ?? '');

		return str_contains($accept, 'application/yaml') || str_contains($accept, 'text/yaml');
	}

	/**
	 * Extract the normalized /v1/... API path from PHP $_SERVER variables.
	 *
	 * @param array<mixed, mixed> $server PHP $_SERVER array.
	 * @return string
	 */
	public static function extractApiPath(array $server): string {
		if (is_string($server['PATH_INFO'] ?? null) && $server['PATH_INFO'] !== '') {
			return '/' . ltrim($server['PATH_INFO'], '/');
		}

		$uri  = is_string($server['REQUEST_URI'] ?? null) ? $server['REQUEST_URI'] : '/';
		$path = parse_url($uri, PHP_URL_PATH);
		$path = is_string($path) ? $path : '/';

		foreach (['/plugins/api/api.php', '/plugins/api', '/api'] as $prefix) {
			if (str_starts_with($path, $prefix . '/')) {
				return substr($path, strlen($prefix));
			}
		}

		return $path;
	}

	/**
	 * Extract HTTP headers from PHP $_SERVER array.
	 *
	 * @param array<mixed, mixed> $server PHP $_SERVER array.
	 * @return array<string, string>
	 */
	private static function extractHeaders(array $server): array {
		$headers = [];

		foreach ($server as $key => $value) {
			if (!is_string($key) || !is_scalar($value)) {
				continue;
			}

			if (str_starts_with($key, 'HTTP_')) {
				$name           = strtolower(str_replace('_', '-', substr($key, 5)));
				$headers[$name] = (string) $value;
			} elseif ($key === 'CONTENT_TYPE') {
				$headers['content-type'] = (string) $value;
			} elseif ($key === 'CONTENT_LENGTH') {
				$headers['content-length'] = (string) $value;
			}
		}

		return $headers;
	}
}
