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
 * Typed runtime exception carrying an AIP-193 ErrorStatus and optional response headers.
 */
final class ApiException extends \RuntimeException {
	/**
	 * Construct an ApiException from a canonical ErrorStatus.
	 *
	 * @param ErrorStatus           $errorStatus Structured AIP-193 status object.
	 * @param array<string, string> $headers     Optional HTTP response headers (e.g. Retry-After).
	 * @param \Throwable|null       $previous    Optional previous exception.
	 */
	public function __construct(
		public readonly ErrorStatus $errorStatus,
		public readonly array $headers = [],
		?\Throwable $previous = null,
	) {
		parent::__construct($errorStatus->message, $errorStatus->httpCode, $previous);
	}

	/**
	 * Create a 400 INVALID_ARGUMENT exception with optional BadRequest field violations.
	 *
	 * @param string                                          $message    Human-readable error description.
	 * @param list<array{field: string, description: string}> $violations Optional field violations.
	 * @return self
	 */
	public static function invalidArgument(string $message, array $violations = []): self {
		$details = $violations !== [] ? [ErrorStatus::badRequestDetail($violations)] : [];

		return new self(new ErrorStatus(ErrorStatus::INVALID_ARGUMENT, $message, $details));
	}

	/**
	 * Create a 401 UNAUTHENTICATED exception.
	 *
	 * @param string $message Human-readable authentication failure message.
	 * @return self
	 */
	public static function unauthenticated(string $message = 'Authentication is required.'): self {
		return new self(
			new ErrorStatus(ErrorStatus::UNAUTHENTICATED, $message),
			['WWW-Authenticate' => 'Bearer realm="Cacti API"']
		);
	}

	/**
	 * Create a 403 PERMISSION_DENIED exception.
	 *
	 * @param string $message Human-readable authorization failure message.
	 * @return self
	 */
	public static function permissionDenied(string $message = 'Permission denied.'): self {
		return new self(new ErrorStatus(ErrorStatus::PERMISSION_DENIED, $message));
	}

	/**
	 * Create a 404 NOT_FOUND exception with optional ResourceInfo detail.
	 *
	 * @param string $message      Human-readable not-found description.
	 * @param string $resourceType Optional resource type.
	 * @param string $resourceName Optional canonical resource name.
	 * @return self
	 */
	public static function notFound(string $message, string $resourceType = '', string $resourceName = ''): self {
		$details = ($resourceType !== '' && $resourceName !== '')
			? [ErrorStatus::resourceInfoDetail($resourceType, $resourceName, $message)]
			: [];

		return new self(new ErrorStatus(ErrorStatus::NOT_FOUND, $message, $details));
	}

	/**
	 * Create a 409 ALREADY_EXISTS exception.
	 *
	 * @param string $message      Human-readable conflict description.
	 * @param string $resourceType Optional resource type.
	 * @param string $resourceName Optional canonical resource name.
	 * @return self
	 */
	public static function alreadyExists(string $message, string $resourceType = '', string $resourceName = ''): self {
		$details = ($resourceType !== '' && $resourceName !== '')
			? [ErrorStatus::resourceInfoDetail($resourceType, $resourceName, $message)]
			: [];

		return new self(new ErrorStatus(ErrorStatus::ALREADY_EXISTS, $message, $details));
	}

	/**
	 * Create a 409 ABORTED exception (used for ETag and idempotency conflicts).
	 *
	 * @param string                     $message Human-readable conflict message.
	 * @param list<array<string, mixed>> $details Optional structured details.
	 * @return self
	 */
	public static function aborted(string $message, array $details = []): self {
		return new self(new ErrorStatus(ErrorStatus::ABORTED, $message, $details));
	}

	/**
	 * Create a 400 FAILED_PRECONDITION exception.
	 *
	 * @param string $message Human-readable precondition failure message.
	 * @return self
	 */
	public static function failedPrecondition(string $message): self {
		return new self(new ErrorStatus(ErrorStatus::FAILED_PRECONDITION, $message));
	}

	/**
	 * Create a 429 RESOURCE_EXHAUSTED exception with Retry-After and RetryInfo detail.
	 *
	 * @param string                $message           Human-readable rate-limit message.
	 * @param int                   $retryAfterSeconds Seconds until the caller may retry.
	 * @param array<string, string> $extraHeaders      Additional rate-limit headers.
	 * @return self
	 */
	public static function resourceExhausted(string $message, int $retryAfterSeconds, array $extraHeaders = []): self {
		$delay   = max(1, $retryAfterSeconds);
		$headers = array_merge($extraHeaders, ['Retry-After' => (string) $delay]);

		return new self(
			new ErrorStatus(ErrorStatus::RESOURCE_EXHAUSTED, $message, [ErrorStatus::retryInfoDetail($delay)]),
			$headers
		);
	}

	/**
	 * Create a 501 UNIMPLEMENTED exception.
	 *
	 * @param string $message Human-readable unsupported operation message.
	 * @return self
	 */
	public static function unimplemented(string $message): self {
		return new self(new ErrorStatus(ErrorStatus::UNIMPLEMENTED, $message));
	}

	/**
	 * Create a 503 UNAVAILABLE exception.
	 *
	 * @param string $message Human-readable service unavailability message.
	 * @return self
	 */
	public static function unavailable(string $message): self {
		return new self(new ErrorStatus(ErrorStatus::UNAVAILABLE, $message));
	}

	/**
	 * Create a 500 INTERNAL exception without leaking stack traces.
	 *
	 * @param string          $message  Safe human-readable error message.
	 * @param \Throwable|null $previous Optional internal cause.
	 * @return self
	 */
	public static function internal(string $message = 'Internal server error.', ?\Throwable $previous = null): self {
		return new self(new ErrorStatus(ErrorStatus::INTERNAL, $message), [], $previous);
	}
}
