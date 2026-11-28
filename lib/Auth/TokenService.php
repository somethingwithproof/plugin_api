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

namespace Somethingwithproof\Api\Auth;

use Somethingwithproof\Api\Aip\ApiException;
use Somethingwithproof\Api\Aip\OperationManager;
use Somethingwithproof\Api\Aip\ResourceName;
use Somethingwithproof\Api\Pages;
use Somethingwithproof\Api\Repository\ApiRepositoryInterface;

/**
 * WordPress Application Passwords token service.
 *
 * Issues revocable, prefix-identifiable bearer tokens formatted as
 * "cacti_pat_<8-hex-prefix>_<48-hex-secret>". Only the SHA-256 digest of the
 * full token is stored at rest; verification uses constant-time hash_equals()
 * and enforces expiration, revocation, CIDR allowlists, and Cacti user status.
 */
final class TokenService {
	/**
	 * Token literal prefix for secret scanners and prefix lookup.
	 */
	public const TOKEN_PREFIX = 'cacti_pat_';

	/**
	 * Strict format regex for incoming bearer tokens.
	 */
	public const TOKEN_PATTERN = '/^cacti_pat_([0-9a-f]{8})_([0-9a-f]{48})$/';

	/**
	 * Allowed token scopes.
	 *
	 * @var list<string>
	 */
	public const ALLOWED_SCOPES = [
		Principal::SCOPE_READ,
		Principal::SCOPE_WRITE,
		Principal::SCOPE_ADMIN,
	];

	/**
	 * Construct a TokenService backed by a repository.
	 *
	 * @param ApiRepositoryInterface $repository Persistence adapter.
	 */
	public function __construct(private readonly ApiRepositoryInterface $repository) {
	}

	/**
	 * Create a new Application Password for a Cacti user and return metadata plus one-time plaintext token.
	 *
	 * @param int          $userId        Owner Cacti user ID.
	 * @param string       $name          Human-readable label for the token.
	 * @param list<string> $scopes        Requested scopes (subset of read, write, admin).
	 * @param int|null     $expiresAt     Optional Unix timestamp when token expires (null for non-expiring).
	 * @param list<string> $cidrAllowlist Optional IPv4/IPv6 CIDR restrictions.
	 * @param int          $now           Current Unix timestamp.
	 * @return array{token: string, resource: array<string, mixed>}
	 * @throws ApiException If parameters or CIDR entries are invalid.
	 */
	public function create(
		int $userId,
		string $name,
		array $scopes,
		?int $expiresAt,
		array $cidrAllowlist,
		int $now,
	): array {
		$trimmedName = trim($name);

		if ($trimmedName === '' || strlen($trimmedName) > 128) {
			throw ApiException::invalidArgument(
				'Token name must be between 1 and 128 characters.',
				[['field' => 'display_name', 'description' => 'Provide a non-empty token label up to 128 characters.']]
			);
		}

		$normalizedScopes = self::validateScopes($scopes);
		$normalizedCidrs  = self::validateCidrs($cidrAllowlist);

		if ($expiresAt !== null && $expiresAt <= $now) {
			throw ApiException::invalidArgument(
				'Token expiration must be in the future.',
				[['field' => 'expire_time', 'description' => 'Expiration timestamp must be later than current time.']]
			);
		}

		$id          = OperationManager::generateUuidV4();
		$prefix      = bin2hex(random_bytes(4));
		$secret      = bin2hex(random_bytes(24));
		$rawToken    = self::TOKEN_PREFIX . $prefix . '_' . $secret;
		$tokenHash   = hash('sha256', $rawToken);

		$record = [
			'id'             => $id,
			'user_id'        => $userId,
			'name'           => $trimmedName,
			'token_prefix'   => $prefix,
			'token_hash'     => $tokenHash,
			'scopes'         => implode(',', $normalizedScopes),
			'cidr_allowlist' => implode(',', $normalizedCidrs),
			'created_at'     => $now,
			'expires_at'     => $expiresAt,
			'last_used_at'   => null,
			'revoked_at'     => null,
		];

		$this->repository->createToken($record);

		return [
			'token'    => $rawToken,
			'resource' => $this->toAipArray($record),
		];
	}

	/**
	 * Authenticate an incoming bearer token string and resolve a Principal.
	 *
	 * @param string $rawToken Raw Authorization Bearer token value.
	 * @param string $clientIp Client IP address for CIDR allowlist validation.
	 * @param int    $now      Current Unix timestamp.
	 * @return Principal
	 * @throws ApiException With 401 UNAUTHENTICATED or 403 PERMISSION_DENIED on invalid credentials.
	 */
	public function authenticate(string $rawToken, string $clientIp, int $now): Principal {
		if (strlen($rawToken) !== 67 || preg_match(self::TOKEN_PATTERN, $rawToken, $matches) !== 1) {
			throw ApiException::unauthenticated('Invalid bearer token format.');
		}

		$prefix     = $matches[1];
		$actualHash = hash('sha256', $rawToken);
		$candidates = $this->repository->findTokensByPrefix($prefix);

		$matched = null;

		foreach ($candidates as $candidate) {
			if (hash_equals($candidate['token_hash'], $actualHash)) {
				$matched = $candidate;
			}
		}

		if ($matched === null) {
			throw ApiException::unauthenticated('Invalid or unknown bearer token.');
		}

		if ($matched['revoked_at'] !== null) {
			throw ApiException::unauthenticated('Bearer token has been revoked.');
		}

		if ($matched['expires_at'] !== null && $matched['expires_at'] <= $now) {
			throw ApiException::unauthenticated('Bearer token has expired.');
		}

		if ($matched['cidr_allowlist'] !== '') {
			$cidrs = self::splitCsv($matched['cidr_allowlist']);

			if ($cidrs !== [] && !self::ipMatchesAnyCidr($clientIp, $cidrs)) {
				throw ApiException::permissionDenied('Client IP address is outside this token\'s CIDR allowlist.');
			}
		}

		$user = $this->repository->getUserById($matched['user_id']);

		if ($user === null || !$user['enabled'] || $user['locked']) {
			throw ApiException::unauthenticated('Token owner account is disabled or locked.');
		}

		$this->repository->touchTokenLastUsed($matched['id'], $now);

		$realms  = $this->repository->getUserRealms($user['id']);
		$scopes  = self::splitCsv($matched['scopes']);
		$isAdmin = in_array(Pages::ADMIN, $realms, true) && in_array(Principal::SCOPE_ADMIN, $scopes, true);

		return new Principal(
			userId: $user['id'],
			username: $user['username'],
			authMethod: 'token',
			tokenId: $matched['id'],
			scopes: $scopes,
			realmFiles: $realms,
			isAdmin: $isAdmin,
		);
	}

	/**
	 * Revoke an existing token by ID, enforcing ownership unless caller is admin.
	 *
	 * @param string $id      Token UUID.
	 * @param int    $userId  Requesting user ID.
	 * @param bool   $isAdmin Whether caller has API Admin privileges.
	 * @param int    $now     Current Unix timestamp.
	 * @return array<string, mixed>
	 * @throws ApiException On missing token or permission denial.
	 */
	public function revoke(string $id, int $userId, bool $isAdmin, int $now): array {
		$record = $this->repository->findTokenById($id);

		if ($record === null) {
			throw ApiException::notFound(sprintf('Token "%s" was not found.', $id), 'cacti.net/Token', 'tokens/' . $id);
		}

		if (!$isAdmin && $record['user_id'] !== $userId) {
			throw ApiException::permissionDenied('You do not have permission to revoke this token.');
		}

		if ($record['revoked_at'] === null) {
			$this->repository->revokeToken($id, $now);
			$record['revoked_at'] = $now;
		}

		return $this->toAipArray($record);
	}

	/**
	 * Delete a token record permanently, enforcing ownership unless caller is admin.
	 *
	 * @param string $id      Token UUID.
	 * @param int    $userId  Requesting user ID.
	 * @param bool   $isAdmin Whether caller has API Admin privileges.
	 * @return void
	 * @throws ApiException On missing token or permission denial.
	 */
	public function delete(string $id, int $userId, bool $isAdmin): void {
		$record = $this->repository->findTokenById($id);

		if ($record === null) {
			throw ApiException::notFound(sprintf('Token "%s" was not found.', $id), 'cacti.net/Token', 'tokens/' . $id);
		}

		if (!$isAdmin && $record['user_id'] !== $userId) {
			throw ApiException::permissionDenied('You do not have permission to delete this token.');
		}

		$this->repository->deleteToken($id);
	}

	/**
	 * Serialize a stored token row into its safe AIP representation (never exposing token_hash).
	 *
	 * @param array{id: string, user_id: int, name: string, token_prefix: string, token_hash: string, scopes: string, cidr_allowlist: string, created_at: int, expires_at: int|null, last_used_at: int|null, revoked_at: int|null} $record Stored token record.
	 * @return array<string, mixed>
	 */
	public function toAipArray(array $record): array {
		$scopes = array_values(array_filter(array_map('trim', explode(',', $record['scopes']))));
		$cidrs  = $record['cidr_allowlist'] === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $record['cidr_allowlist']))));

		return [
			'name'          => ResourceName::build('tokens', $record['id']),
			'id'            => $record['id'],
			'userId'        => $record['user_id'],
			'displayName'   => $record['name'],
			'tokenPrefix'   => self::TOKEN_PREFIX . $record['token_prefix'],
			'scopes'        => $scopes,
			'cidrAllowlist' => $cidrs,
			'createTime'    => gmdate('Y-m-d\TH:i:s\Z', $record['created_at']),
			'expireTime'    => $record['expires_at'] !== null ? gmdate('Y-m-d\TH:i:s\Z', $record['expires_at']) : null,
			'lastUsedTime'  => $record['last_used_at'] !== null ? gmdate('Y-m-d\TH:i:s\Z', $record['last_used_at']) : null,
			'revoked'       => $record['revoked_at'] !== null,
			'revokeTime'    => $record['revoked_at'] !== null ? gmdate('Y-m-d\TH:i:s\Z', $record['revoked_at']) : null,
		];
	}

	/**
	 * Validate and deduplicate requested token scopes.
	 *
	 * @param list<string> $scopes Requested scopes.
	 * @return list<string>
	 * @throws ApiException If empty or containing unknown scopes.
	 */
	public static function validateScopes(array $scopes): array {
		$clean = [];

		foreach ($scopes as $scope) {
			$trimmed = strtolower(trim($scope));

			if ($trimmed === '') {
				continue;
			}

			if (!in_array($trimmed, self::ALLOWED_SCOPES, true)) {
				throw ApiException::invalidArgument(
					sprintf('Unsupported token scope "%s".', $trimmed),
					[['field' => 'scopes', 'description' => 'Allowed scopes are: read, write, admin.']]
				);
			}

			$clean[] = $trimmed;
		}

		if ($clean === []) {
			$clean = [Principal::SCOPE_READ];
		}

		return array_values(array_unique($clean));
	}

	/**
	 * Validate an optional list of IPv4/IPv6 CIDR blocks or host IPs.
	 *
	 * @param list<string> $cidrs Candidate CIDR strings.
	 * @return list<string>
	 * @throws ApiException If any CIDR entry is malformed.
	 */
	public static function validateCidrs(array $cidrs): array {
		$valid = [];

		foreach ($cidrs as $entry) {
			$trimmed = trim($entry);

			if ($trimmed === '') {
				continue;
			}

			if (!self::isValidCidr($trimmed)) {
				throw ApiException::invalidArgument(
					sprintf('Invalid CIDR allowlist entry "%s".', $trimmed),
					[['field' => 'cidr_allowlist', 'description' => 'Expected valid IPv4/IPv6 address or CIDR prefix.']]
				);
			}

			$valid[] = $trimmed;
		}

		return array_values(array_unique($valid));
	}

	/**
	 * Check whether an IP address matches any entry in a CIDR allowlist.
	 *
	 * @param string       $ip    Client IP address.
	 * @param list<string> $cidrs Allowlisted CIDR entries.
	 * @return bool
	 */
	public static function ipMatchesAnyCidr(string $ip, array $cidrs): bool {
		$ipBin = @inet_pton($ip);

		if ($ipBin === false) {
			return false;
		}

		foreach ($cidrs as $cidr) {
			$parts  = explode('/', $cidr, 2);
			$netBin = @inet_pton($parts[0]);

			if ($netBin === false || strlen($netBin) !== strlen($ipBin)) {
				continue;
			}

			$maxBits = strlen($ipBin) * 8;
			$prefix  = isset($parts[1]) ? (int) $parts[1] : $maxBits;

			if ($prefix < 0 || $prefix > $maxBits) {
				continue;
			}

			if (self::binaryPrefixMatches($ipBin, $netBin, $prefix)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Validate a single IP address or CIDR string.
	 *
	 * @param string $cidr Candidate IP or IP/prefix string.
	 * @return bool
	 */
	private static function isValidCidr(string $cidr): bool {
		$parts = explode('/', $cidr, 2);
		$bin   = @inet_pton($parts[0]);

		if ($bin === false) {
			return false;
		}

		if (!isset($parts[1])) {
			return true;
		}

		if (preg_match('/^[0-9]+$/', $parts[1]) !== 1) {
			return false;
		}

		$prefix  = (int) $parts[1];
		$maxBits = strlen($bin) * 8;

		return $prefix >= 0 && $prefix <= $maxBits;
	}

	/**
	 * Split a comma-separated string into trimmed non-empty items in a single pass.
	 *
	 * @param string $csv Comma-separated values.
	 * @return list<string>
	 */
	private static function splitCsv(string $csv): array {
		if ($csv === '') {
			return [];
		}

		$out = [];

		foreach (explode(',', $csv) as $part) {
			$trimmed = trim($part);

			if ($trimmed !== '') {
				$out[] = $trimmed;
			}
		}

		return $out;
	}

	/**
	 * Compare the leading $prefix bits of two equal-length binary IP strings.
	 *
	 * @param string $ipBin  Packed client IP bytes.
	 * @param string $netBin Packed network address bytes.
	 * @param int    $prefix Prefix bit length.
	 * @return bool
	 */
	private static function binaryPrefixMatches(string $ipBin, string $netBin, int $prefix): bool {
		$fullBytes = intdiv($prefix, 8);
		$remBits   = $prefix % 8;

		if ($fullBytes > 0 && strncmp($ipBin, $netBin, $fullBytes) !== 0) {
			return false;
		}

		if ($remBits === 0) {
			return true;
		}

		$mask = (0xFF << (8 - $remBits)) & 0xFF;

		return (ord($ipBin[$fullBytes]) & $mask) === (ord($netBin[$fullBytes]) & $mask);
	}
}
