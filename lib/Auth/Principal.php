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

use Somethingwithproof\Api\Pages;

/**
 * Authenticated caller identity binding Cacti user ID, realms, and token scopes.
 */
final class Principal {
	/**
	 * Read-only API scope.
	 */
	public const SCOPE_READ = 'read';

	/**
	 * Write/mutation API scope.
	 */
	public const SCOPE_WRITE = 'write';

	/**
	 * Administrative API scope.
	 */
	public const SCOPE_ADMIN = 'admin';

	/**
	 * Construct an authenticated Principal.
	 *
	 * @param int          $userId      Cacti user_auth.id.
	 * @param string       $username    Cacti username.
	 * @param string       $authMethod  Authentication mechanism ("token" or "session").
	 * @param string|null  $tokenId     Token UUID when authenticated via Application Password.
	 * @param list<string> $scopes      Granted token scopes (read, write, admin).
	 * @param list<string> $realmFiles  Cacti realm filenames permitted for this user.
	 * @param bool         $isAdmin     Whether user holds API Admin realm and scope.
	 */
	public function __construct(
		public readonly int $userId,
		public readonly string $username,
		public readonly string $authMethod,
		public readonly ?string $tokenId,
		public readonly array $scopes,
		public readonly array $realmFiles,
		public readonly bool $isAdmin = false,
	) {
	}

	/**
	 * Return true when this principal holds the requested scope (admin implies write and read; write implies read).
	 *
	 * @param string $scope Requested scope name.
	 * @return bool
	 */
	public function hasScope(string $scope): bool {
		if (in_array(self::SCOPE_ADMIN, $this->scopes, true)) {
			return true;
		}

		if ($scope === self::SCOPE_READ && in_array(self::SCOPE_WRITE, $this->scopes, true)) {
			return true;
		}

		return in_array($scope, $this->scopes, true);
	}

	/**
	 * Return true when the user holds the specified Cacti realm file permission.
	 *
	 * Applies 3-tier API realm inheritance (Admin > Operator > View) so users
	 * granted api_admin.php or api_tokens.php satisfy lower API realm checks
	 * regardless of token scope.
	 *
	 * @param string $realmFile Cacti realm filename (e.g. "host.php", "api_admin.php").
	 * @return bool
	 */
	public function hasRealm(string $realmFile): bool {
		if ($this->isAdmin || in_array($realmFile, $this->realmFiles, true)) {
			return true;
		}

		if ($realmFile === Pages::VIEW) {
			return in_array(Pages::OPERATOR, $this->realmFiles, true)
				|| in_array(Pages::ADMIN, $this->realmFiles, true);
		}

		if ($realmFile === Pages::OPERATOR) {
			return in_array(Pages::ADMIN, $this->realmFiles, true);
		}

		return false;
	}

	/**
	 * Evaluate combined scope and Cacti realm authorization as a composable AccessResult.
	 *
	 * @param string      $requiredScope Required token scope (read, write, admin).
	 * @param string|null $requiredRealm Optional Cacti realm file required for the resource.
	 * @return AccessResult
	 */
	public function checkAccess(string $requiredScope, ?string $requiredRealm = null): AccessResult {
		$scopeResult = $this->hasScope($requiredScope)
			? AccessResult::allowed()
			: AccessResult::forbidden(sprintf('Token lacks required "%s" scope.', $requiredScope));

		if ($requiredRealm === null || $requiredRealm === '') {
			return $scopeResult;
		}

		$realmResult = $this->hasRealm($requiredRealm)
			? AccessResult::allowed()
			: AccessResult::forbidden(sprintf('User lacks required Cacti realm "%s".', $requiredRealm));

		return $scopeResult->andIf($realmResult);
	}

	/**
	 * Return a rate-limit bucket identifier unique to this principal.
	 *
	 * @return string
	 */
	public function rateLimitSubject(): string {
		if ($this->tokenId !== null && $this->tokenId !== '') {
			return 'token:' . $this->tokenId;
		}

		return 'user:' . $this->userId;
	}
}
