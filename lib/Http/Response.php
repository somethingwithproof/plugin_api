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

use Somethingwithproof\Api\Aip\ErrorStatus;
use Somethingwithproof\Api\Settings;

/**
 * Immutable HTTP response carrying status code, JSON/YAML payload, and security headers.
 */
final class Response {
	/**
	 * Mandatory baseline security headers applied to every API response.
	 *
	 * @var array<string, string>
	 */
	public const SECURITY_HEADERS = [
		'X-Content-Type-Options' => 'nosniff',
		'Cache-Control'          => 'no-store',
		'X-Frame-Options'        => 'DENY',
	];

	/**
	 * Construct an immutable API HTTP Response.
	 *
	 * @param int                       $statusCode HTTP status code.
	 * @param array<string, mixed>|null $body       Structured response body (null for 204 No Content).
	 * @param array<string, string>     $headers    Additional response headers.
	 * @param string                    $format     Serialization format ("json" or "yaml").
	 */
	public function __construct(
		public readonly int $statusCode,
		public readonly ?array $body = null,
		public readonly array $headers = [],
		public readonly string $format = 'json',
	) {
	}

	/**
	 * Create a 200 OK response.
	 *
	 * @param array<string, mixed>  $body    Response payload.
	 * @param array<string, string> $headers Extra headers.
	 * @return self
	 */
	public static function ok(array $body, array $headers = []): self {
		return new self(200, $body, $headers);
	}

	/**
	 * Create a 201 Created response.
	 *
	 * @param array<string, mixed>  $body    Created resource payload.
	 * @param array<string, string> $headers Extra headers.
	 * @return self
	 */
	public static function created(array $body, array $headers = []): self {
		return new self(201, $body, $headers);
	}

	/**
	 * Create a 202 Accepted response for AIP-151 Long-Running Operations.
	 *
	 * @param array<string, mixed>  $operation LRO resource payload.
	 * @param array<string, string> $headers   Extra headers.
	 * @return self
	 */
	public static function accepted(array $operation, array $headers = []): self {
		return new self(202, $operation, $headers);
	}

	/**
	 * Create a 204 No Content response.
	 *
	 * @param array<string, string> $headers Extra headers.
	 * @return self
	 */
	public static function noContent(array $headers = []): self {
		return new self(204, null, $headers);
	}

	/**
	 * Create an AIP-193 error response from an ErrorStatus.
	 *
	 * @param ErrorStatus           $errorStatus Canonical error status.
	 * @param array<string, string> $headers     Extra headers (e.g. Retry-After).
	 * @return self
	 */
	public static function fromError(ErrorStatus $errorStatus, array $headers = []): self {
		return new self($errorStatus->httpCode, $errorStatus->toEnvelope(), $headers);
	}

	/**
	 * Return a copy of this response with additional headers merged in.
	 *
	 * @param array<string, string> $extra Headers to add or override.
	 * @return self
	 */
	public function withHeaders(array $extra): self {
		return new self($this->statusCode, $this->body, array_merge($this->headers, $extra), $this->format);
	}

	/**
	 * Return a copy of this response configured for the requested output format.
	 *
	 * @param string $format Serialization format ("json" or "yaml").
	 * @return self
	 */
	public function withFormat(string $format): self {
		return new self($this->statusCode, $this->body, $this->headers, $format === 'yaml' ? 'yaml' : 'json');
	}

	/**
	 * Apply allowlisted CORS headers according to plugin Settings.
	 *
	 * Never emits Access-Control-Allow-Credentials: true with wildcard "*".
	 *
	 * @param string|null $origin   Incoming Origin request header.
	 * @param Settings    $settings Plugin configuration settings.
	 * @return self
	 */
	public function withCors(?string $origin, Settings $settings): self {
		if ($origin === null || $origin === '' || $settings->corsOrigins === []) {
			return $this;
		}

		$matchedOrigin = null;

		if (in_array($origin, $settings->corsOrigins, true)) {
			$matchedOrigin = $origin;
		} elseif (in_array('*', $settings->corsOrigins, true) && !$settings->corsAllowCredentials) {
			$matchedOrigin = '*';
		}

		if ($matchedOrigin === null) {
			return $this;
		}

		$corsHeaders = [
			'Access-Control-Allow-Origin'  => $matchedOrigin,
			'Access-Control-Allow-Methods' => 'GET, POST, PATCH, DELETE, OPTIONS',
			'Access-Control-Allow-Headers' => 'Authorization, Content-Type, If-Match, Idempotency-Key, Accept',
			'Access-Control-Expose-Headers' => 'ETag, Retry-After, X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset',
			'Vary'                         => 'Origin',
		];

		if ($settings->corsAllowCredentials && $matchedOrigin !== '*') {
			$corsHeaders['Access-Control-Allow-Credentials'] = 'true';
		}

		return $this->withHeaders($corsHeaders);
	}

	/**
	 * Return all resolved HTTP headers including mandatory security and Content-Type headers.
	 *
	 * @return array<string, string>
	 */
	public function resolvedHeaders(): array {
		$headers = array_merge(self::SECURITY_HEADERS, $this->headers);

		if ($this->body !== null) {
			$headers['Content-Type'] = $this->format === 'yaml'
				? 'application/yaml; charset=utf-8'
				: 'application/json; charset=utf-8';
		}

		return $headers;
	}

	/**
	 * Render the response body as JSON or YAML string.
	 *
	 * @return string
	 */
	public function renderBody(): string {
		if ($this->body === null) {
			return '';
		}

		if ($this->format === 'yaml') {
			return self::encodeYaml($this->body) . "\n";
		}

		return json_encode($this->body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}

	/**
	 * Emit status code, headers, and serialized body to the active PHP SAPI output stream.
	 *
	 * @return void
	 */
	public function send(): void {
		http_response_code($this->statusCode);

		foreach ($this->resolvedHeaders() as $name => $value) {
			header($name . ': ' . $value, true);
		}

		$rendered = $this->renderBody();

		if ($rendered !== '') {
			echo $rendered;
		}
	}

	/**
	 * Encode an associative array or list into safe, zero-dependency YAML 1.2 output.
	 *
	 * @param mixed $value Data structure to serialize.
	 * @param int   $indent Current indentation depth.
	 * @return string
	 */
	public static function encodeYaml(mixed $value, int $indent = 0): string {
		$pad = str_repeat('  ', $indent);

		if ($value === null) {
			return 'null';
		}

		if (is_bool($value)) {
			return $value ? 'true' : 'false';
		}

		if (is_int($value) || is_float($value)) {
			return (string) $value;
		}

		if (is_string($value)) {
			return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		}

		if (!is_array($value)) {
			return 'null';
		}

		if ($value === []) {
			return '[]';
		}

		$lines = [];

		if (array_is_list($value)) {
			foreach ($value as $item) {
				if (is_array($item) && $item !== []) {
					$lines[] = $pad . "-\n" . self::encodeYaml($item, $indent + 1);
				} else {
					$lines[] = $pad . '- ' . self::encodeYaml($item, $indent + 1);
				}
			}

			return implode("\n", $lines);
		}

		foreach ($value as $k => $v) {
			$keyStr = (string) $k;

			if (is_array($v) && $v !== []) {
				$lines[] = $pad . $keyStr . ":\n" . self::encodeYaml($v, $indent + 1);
			} else {
				$lines[] = $pad . $keyStr . ': ' . self::encodeYaml($v, $indent + 1);
			}
		}

		return implode("\n", $lines);
	}
}
