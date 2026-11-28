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

namespace Somethingwithproof\Api;

/**
 * Declarative configuration settings stored in Cacti's settings table.
 */
final class Settings {
	/**
	 * Setting keys managed by plugin_api.
	 */
	public const NAMES = [
		'api_enabled',
		'api_rate_limit_per_min',
		'api_default_token_ttl_days',
		'api_cors_origins',
		'api_cors_allow_credentials',
		'api_require_tls',
		'api_max_body_bytes',
		'api_enable_yaml_negotiation',
		'api_uninstall_keep_tables',
	];

	/**
	 * Default maximum HTTP request body size (1 MiB).
	 */
	public const DEFAULT_MAX_BODY_BYTES = 1048576;

	/**
	 * Default per-minute request limit per principal.
	 */
	public const DEFAULT_RATE_LIMIT_PER_MIN = 120;

	/**
	 * Default lifetime for new Application Passwords in days.
	 */
	public const DEFAULT_TOKEN_TTL_DAYS = 90;

	/**
	 * Construct an immutable plugin settings value object.
	 *
	 * @param bool         $enabled               Whether the REST API front controller serves requests.
	 * @param int          $rateLimitPerMin       Allowed requests per 60-second window per principal or IP.
	 * @param int          $defaultTokenTtlDays   Default expiration window for new tokens in days (0 = never).
	 * @param list<string> $corsOrigins           Explicit allowlisted CORS origins (wildcard rejected when credentials enabled).
	 * @param bool         $corsAllowCredentials  Whether to emit Access-Control-Allow-Credentials for allowlisted origins.
	 * @param bool         $requireTls            Whether non-HTTPS requests are rejected with FAILED_PRECONDITION.
	 * @param int          $maxBodyBytes          Maximum permitted HTTP request body length in bytes.
	 * @param bool         $enableYamlNegotiation Whether Accept: application/yaml triggers YAML serialization.
	 * @param bool         $uninstallKeepTables   Whether uninstall retains plugin_api_* tables.
	 */
	public function __construct(
		public readonly bool $enabled = true,
		public readonly int $rateLimitPerMin = self::DEFAULT_RATE_LIMIT_PER_MIN,
		public readonly int $defaultTokenTtlDays = self::DEFAULT_TOKEN_TTL_DAYS,
		public readonly array $corsOrigins = [],
		public readonly bool $corsAllowCredentials = false,
		public readonly bool $requireTls = false,
		public readonly int $maxBodyBytes = self::DEFAULT_MAX_BODY_BYTES,
		public readonly bool $enableYamlNegotiation = false,
		public readonly bool $uninstallKeepTables = false,
	) {
	}

	/**
	 * Build a typed Settings object from raw Cacti settings key-value pairs.
	 *
	 * @param array<string, mixed> $row Raw setting values from Cacti's settings table.
	 * @return self
	 */
	public static function fromRow(array $row): self {
		$enabledRaw = $row['api_enabled'] ?? 'on';
		$enabled    = $enabledRaw === '' ? true : ($enabledRaw === 'on' || $enabledRaw === true || $enabledRaw === '1');

		$rateRaw = $row['api_rate_limit_per_min'] ?? self::DEFAULT_RATE_LIMIT_PER_MIN;
		$rate    = is_numeric($rateRaw) ? (int) $rateRaw : self::DEFAULT_RATE_LIMIT_PER_MIN;
		$rate    = max(1, min(100000, $rate));

		$ttlRaw = $row['api_default_token_ttl_days'] ?? self::DEFAULT_TOKEN_TTL_DAYS;
		$ttl    = is_numeric($ttlRaw) ? (int) $ttlRaw : self::DEFAULT_TOKEN_TTL_DAYS;
		$ttl    = max(0, min(3650, $ttl));

		$originsRaw = $row['api_cors_origins'] ?? '';
		$origins    = [];

		if (is_array($originsRaw)) {
			foreach ($originsRaw as $entry) {
				if (is_scalar($entry)) {
					$trimmed = trim((string) $entry);

					if ($trimmed !== '') {
						$origins[] = $trimmed;
					}
				}
			}
		} elseif (is_scalar($originsRaw)) {
			foreach (preg_split('/[\r\n,]+/', (string) $originsRaw) ?: [] as $entry) {
				$trimmed = trim($entry);

				if ($trimmed !== '') {
					$origins[] = $trimmed;
				}
			}
		}

		$corsCreds = ($row['api_cors_allow_credentials'] ?? '') === 'on' || ($row['api_cors_allow_credentials'] ?? false) === true;
		$reqTls    = ($row['api_require_tls'] ?? '') === 'on' || ($row['api_require_tls'] ?? false) === true;

		$maxBodyRaw = $row['api_max_body_bytes'] ?? self::DEFAULT_MAX_BODY_BYTES;
		$maxBody    = is_numeric($maxBodyRaw) ? (int) $maxBodyRaw : self::DEFAULT_MAX_BODY_BYTES;
		$maxBody    = max(1024, min(10485760, $maxBody));

		$yamlNeg = ($row['api_enable_yaml_negotiation'] ?? '') === 'on' || ($row['api_enable_yaml_negotiation'] ?? false) === true;
		$keep    = ($row['api_uninstall_keep_tables'] ?? '') === 'on' || ($row['api_uninstall_keep_tables'] ?? false) === true;

		return new self(
			enabled: $enabled,
			rateLimitPerMin: $rate,
			defaultTokenTtlDays: $ttl,
			corsOrigins: array_values(array_unique($origins)),
			corsAllowCredentials: $corsCreds,
			requireTls: $reqTls,
			maxBodyBytes: $maxBody,
			enableYamlNegotiation: $yamlNeg,
			uninstallKeepTables: $keep,
		);
	}

	/**
	 * Convert declarative settings to an associative array suitable for CasC export.
	 *
	 * @return array<string, mixed>
	 */
	public function toExportArray(): array {
		return [
			'api_enabled'                 => $this->enabled,
			'api_rate_limit_per_min'      => $this->rateLimitPerMin,
			'api_default_token_ttl_days'  => $this->defaultTokenTtlDays,
			'api_cors_origins'            => $this->corsOrigins,
			'api_cors_allow_credentials'  => $this->corsAllowCredentials,
			'api_require_tls'             => $this->requireTls,
			'api_max_body_bytes'          => $this->maxBodyBytes,
			'api_enable_yaml_negotiation' => $this->enableYamlNegotiation,
			'api_uninstall_keep_tables'   => $this->uninstallKeepTables,
		];
	}

	/**
	 * Return Cacti settings form field definitions for the API tab.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function form(): array {
		return [
			'api_header' => [
				'friendly_name' => __('Google AIP REST API', 'api'),
				'method'        => 'spacer',
			],
			'api_enabled' => [
				'friendly_name' => __('Enable REST API', 'api'),
				'description'   => __('Allow authenticated clients to access /plugins/api/v1 endpoints.', 'api'),
				'method'        => 'checkbox',
				'default'       => 'on',
			],
			'api_rate_limit_per_min' => [
				'friendly_name' => __('Rate Limit (Requests / Minute)', 'api'),
				'description'   => __('Maximum requests allowed per 60-second window per token or client IP.', 'api'),
				'method'        => 'textbox',
				'default'       => (string) self::DEFAULT_RATE_LIMIT_PER_MIN,
				'max_length'    => '6',
				'size'          => '10',
			],
			'api_default_token_ttl_days' => [
				'friendly_name' => __('Default Token TTL (Days)', 'api'),
				'description'   => __('Default expiration period in days for newly created Application Passwords (0 disables default expiry).', 'api'),
				'method'        => 'textbox',
				'default'       => (string) self::DEFAULT_TOKEN_TTL_DAYS,
				'max_length'    => '4',
				'size'          => '10',
			],
			'api_cors_origins' => [
				'friendly_name' => __('CORS Allowlisted Origins', 'api'),
				'description'   => __('Comma- or newline-separated origins permitted to call the API from browsers (never use * with credentials).', 'api'),
				'method'        => 'textarea',
				'default'       => '',
				'textarea_rows' => '3',
				'textarea_cols' => '60',
			],
			'api_cors_allow_credentials' => [
				'friendly_name' => __('CORS Allow Credentials', 'api'),
				'description'   => __('Emit Access-Control-Allow-Credentials: true for explicitly allowlisted origins (ignored if origin is *).', 'api'),
				'method'        => 'checkbox',
				'default'       => '',
			],
			'api_require_tls' => [
				'friendly_name' => __('Require HTTPS', 'api'),
				'description'   => __('Reject non-HTTPS API requests with 400 FAILED_PRECONDITION.', 'api'),
				'method'        => 'checkbox',
				'default'       => '',
			],
			'api_max_body_bytes' => [
				'friendly_name' => __('Max Request Body Size (Bytes)', 'api'),
				'description'   => __('Upper bound on HTTP request payloads accepted by the REST API (default 1048576 = 1 MiB).', 'api'),
				'method'        => 'textbox',
				'default'       => (string) self::DEFAULT_MAX_BODY_BYTES,
				'max_length'    => '8',
				'size'          => '12',
			],
			'api_enable_yaml_negotiation' => [
				'friendly_name' => __('Enable YAML Content Negotiation', 'api'),
				'description'   => __('Allow clients sending Accept: application/yaml to receive YAML responses.', 'api'),
				'method'        => 'checkbox',
				'default'       => '',
			],
			'api_uninstall_keep_tables' => [
				'friendly_name' => __('Keep State Tables on Uninstall', 'api'),
				'description'   => __('Preserve plugin_api_tokens, plugin_api_operations, plugin_api_idempotency, and plugin_api_rate_limits on uninstall.', 'api'),
				'method'        => 'checkbox',
				'default'       => '',
			],
		];
	}
}
