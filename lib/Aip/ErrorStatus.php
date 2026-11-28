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
 * Canonical Google AIP-193 error status model mapping all 16 gRPC status codes
 * to HTTP status codes and structured google.rpc.Status payloads.
 */
final class ErrorStatus {
	/**
	 * Canonical gRPC status code OK (HTTP 200).
	 */
	public const OK = 'OK';

	/**
	 * Canonical gRPC status code CANCELLED (HTTP 499).
	 */
	public const CANCELLED = 'CANCELLED';

	/**
	 * Canonical gRPC status code UNKNOWN (HTTP 500).
	 */
	public const UNKNOWN = 'UNKNOWN';

	/**
	 * Canonical gRPC status code INVALID_ARGUMENT (HTTP 400).
	 */
	public const INVALID_ARGUMENT = 'INVALID_ARGUMENT';

	/**
	 * Canonical gRPC status code DEADLINE_EXCEEDED (HTTP 504).
	 */
	public const DEADLINE_EXCEEDED = 'DEADLINE_EXCEEDED';

	/**
	 * Canonical gRPC status code NOT_FOUND (HTTP 404).
	 */
	public const NOT_FOUND = 'NOT_FOUND';

	/**
	 * Canonical gRPC status code ALREADY_EXISTS (HTTP 409).
	 */
	public const ALREADY_EXISTS = 'ALREADY_EXISTS';

	/**
	 * Canonical gRPC status code PERMISSION_DENIED (HTTP 403).
	 */
	public const PERMISSION_DENIED = 'PERMISSION_DENIED';

	/**
	 * Canonical gRPC status code RESOURCE_EXHAUSTED (HTTP 429).
	 */
	public const RESOURCE_EXHAUSTED = 'RESOURCE_EXHAUSTED';

	/**
	 * Canonical gRPC status code FAILED_PRECONDITION (HTTP 400).
	 */
	public const FAILED_PRECONDITION = 'FAILED_PRECONDITION';

	/**
	 * Canonical gRPC status code ABORTED (HTTP 409).
	 */
	public const ABORTED = 'ABORTED';

	/**
	 * Canonical gRPC status code OUT_OF_RANGE (HTTP 400).
	 */
	public const OUT_OF_RANGE = 'OUT_OF_RANGE';

	/**
	 * Canonical gRPC status code UNIMPLEMENTED (HTTP 501).
	 */
	public const UNIMPLEMENTED = 'UNIMPLEMENTED';

	/**
	 * Canonical gRPC status code INTERNAL (HTTP 500).
	 */
	public const INTERNAL = 'INTERNAL';

	/**
	 * Canonical gRPC status code UNAVAILABLE (HTTP 503).
	 */
	public const UNAVAILABLE = 'UNAVAILABLE';

	/**
	 * Canonical gRPC status code DATA_LOSS (HTTP 500).
	 */
	public const DATA_LOSS = 'DATA_LOSS';

	/**
	 * Canonical gRPC status code UNAUTHENTICATED (HTTP 401).
	 */
	public const UNAUTHENTICATED = 'UNAUTHENTICATED';

	/**
	 * Mapping from canonical gRPC status names to HTTP status codes per AIP-193.
	 *
	 * @var array<string, int>
	 */
	public const HTTP_CODES = [
		self::OK                  => 200,
		self::CANCELLED           => 499,
		self::UNKNOWN             => 500,
		self::INVALID_ARGUMENT    => 400,
		self::DEADLINE_EXCEEDED   => 504,
		self::NOT_FOUND           => 404,
		self::ALREADY_EXISTS      => 409,
		self::PERMISSION_DENIED   => 403,
		self::RESOURCE_EXHAUSTED  => 429,
		self::FAILED_PRECONDITION => 400,
		self::ABORTED             => 409,
		self::OUT_OF_RANGE        => 400,
		self::UNIMPLEMENTED       => 501,
		self::INTERNAL            => 500,
		self::UNAVAILABLE         => 503,
		self::DATA_LOSS           => 500,
		self::UNAUTHENTICATED     => 401,
	];

	/**
	 * Construct a canonical AIP-193 ErrorStatus.
	 *
	 * @param string                     $status  Canonical gRPC status name (e.g. INVALID_ARGUMENT).
	 * @param string                     $message Human-readable error message.
	 * @param list<array<string, mixed>> $details Structured google.rpc.* detail payloads.
	 * @param int|null                   $code    Optional explicit HTTP code override; defaults to canonical mapping.
	 */
	public function __construct(
		public readonly string $status,
		public readonly string $message,
		public readonly array $details = [],
		?int $code = null,
	) {
		$this->httpCode = $code ?? (self::HTTP_CODES[$status] ?? 500);
	}

	/**
	 * Resolved HTTP status code corresponding to this error status.
	 *
	 * @var int
	 */
	public readonly int $httpCode;

	/**
	 * Build a google.rpc.BadRequest detail entry containing field violations.
	 *
	 * @param list<array{field: string, description: string}> $violations List of field validation violations.
	 * @return array<string, mixed>
	 */
	public static function badRequestDetail(array $violations): array {
		return [
			'@type'           => 'type.googleapis.com/google.rpc.BadRequest',
			'fieldViolations' => $violations,
		];
	}

	/**
	 * Build a google.rpc.RetryInfo detail entry with a retry delay in seconds.
	 *
	 * @param int $retryAfterSeconds Delay before client retry in whole seconds.
	 * @return array<string, mixed>
	 */
	public static function retryInfoDetail(int $retryAfterSeconds): array {
		return [
			'@type'      => 'type.googleapis.com/google.rpc.RetryInfo',
			'retryDelay' => max(1, $retryAfterSeconds) . 's',
		];
	}

	/**
	 * Build a google.rpc.ResourceInfo detail entry describing an affected resource.
	 *
	 * @param string $resourceType Canonical resource type URI or collection name.
	 * @param string $resourceName Canonical resource name (e.g. devices/42).
	 * @param string $description  Description of the error involving this resource.
	 * @return array<string, mixed>
	 */
	public static function resourceInfoDetail(string $resourceType, string $resourceName, string $description): array {
		return [
			'@type'        => 'type.googleapis.com/google.rpc.ResourceInfo',
			'resourceType' => $resourceType,
			'resourceName' => $resourceName,
			'description'  => $description,
		];
	}

	/**
	 * Serialize this error status into an AIP-193 JSON error envelope array.
	 *
	 * @return array{error: array{code: int, message: string, status: string, details: list<array<string, mixed>>}}
	 */
	public function toEnvelope(): array {
		return [
			'error' => [
				'code'    => $this->httpCode,
				'message' => $this->message,
				'status'  => $this->status,
				'details' => $this->details,
			],
		];
	}

	/**
	 * Serialize this status as a standalone google.rpc.Status object (for LRO error fields).
	 *
	 * @return array{code: int, message: string, status: string, details: list<array<string, mixed>>}
	 */
	public function toStatusObject(): array {
		return [
			'code'    => $this->httpCode,
			'message' => $this->message,
			'status'  => $this->status,
			'details' => $this->details,
		];
	}
}
