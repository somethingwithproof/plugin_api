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

/**
 * Drupal-inspired composable tri-state access decision (allowed, forbidden, neutral).
 *
 * Forbidden always wins under both conjunction (andIf) and disjunction (orIf)
 * so explicit denials can never be accidentally bypassed by permissive grants.
 */
final class AccessResult {
	/**
	 * Tri-state value representing an explicit allow decision.
	 */
	public const ALLOWED = 'allowed';

	/**
	 * Tri-state value representing an explicit deny decision.
	 */
	public const FORBIDDEN = 'forbidden';

	/**
	 * Tri-state value representing no opinion (fails closed if never allowed).
	 */
	public const NEUTRAL = 'neutral';

	/**
	 * Construct an immutable AccessResult.
	 *
	 * @param string $state  One of ALLOWED, FORBIDDEN, or NEUTRAL.
	 * @param string $reason Optional diagnostic reason for denial or neutrality.
	 */
	private function __construct(
		private readonly string $state,
		private readonly string $reason = '',
	) {
	}

	/**
	 * Create an explicit ALLOWED result.
	 *
	 * @return self
	 */
	public static function allowed(): self {
		return new self(self::ALLOWED);
	}

	/**
	 * Create an explicit FORBIDDEN result with an audit reason.
	 *
	 * @param string $reason Reason explaining why access was denied.
	 * @return self
	 */
	public static function forbidden(string $reason = 'Access denied.'): self {
		return new self(self::FORBIDDEN, $reason);
	}

	/**
	 * Create a NEUTRAL result (no explicit grant or denial).
	 *
	 * @param string $reason Optional explanation for neutrality.
	 * @return self
	 */
	public static function neutral(string $reason = ''): self {
		return new self(self::NEUTRAL, $reason);
	}

	/**
	 * Create an ALLOWED result when condition is true, otherwise NEUTRAL.
	 *
	 * @param bool   $condition Evaluation condition.
	 * @param string $reason    Reason if neutral.
	 * @return self
	 */
	public static function allowedIf(bool $condition, string $reason = ''): self {
		return $condition ? self::allowed() : self::neutral($reason);
	}

	/**
	 * Create a FORBIDDEN result when condition is true, otherwise NEUTRAL.
	 *
	 * @param bool   $condition Forbidden trigger condition.
	 * @param string $reason    Reason if forbidden.
	 * @return self
	 */
	public static function forbiddenIf(bool $condition, string $reason = 'Access denied.'): self {
		return $condition ? self::forbidden($reason) : self::neutral();
	}

	/**
	 * Combine two results with logical AND (both must allow; any forbidden wins).
	 *
	 * @param self $other Second access decision.
	 * @return self
	 */
	public function andIf(self $other): self {
		if ($this->isForbidden()) {
			return $this;
		}

		if ($other->isForbidden()) {
			return $other;
		}

		if ($this->isAllowed() && $other->isAllowed()) {
			return self::allowed();
		}

		$reason = $this->reason !== '' ? $this->reason : $other->reason;

		return self::neutral($reason);
	}

	/**
	 * Combine two results with logical OR (any forbidden still wins; else any allowed wins).
	 *
	 * @param self $other Alternative access decision.
	 * @return self
	 */
	public function orIf(self $other): self {
		if ($this->isForbidden()) {
			return $this;
		}

		if ($other->isForbidden()) {
			return $other;
		}

		if ($this->isAllowed() || $other->isAllowed()) {
			return self::allowed();
		}

		$reason = $this->reason !== '' ? $this->reason : $other->reason;

		return self::neutral($reason);
	}

	/**
	 * Return true only when explicitly allowed.
	 *
	 * @return bool
	 */
	public function isAllowed(): bool {
		return $this->state === self::ALLOWED;
	}

	/**
	 * Return true when explicitly forbidden.
	 *
	 * @return bool
	 */
	public function isForbidden(): bool {
		return $this->state === self::FORBIDDEN;
	}

	/**
	 * Return true when neutral (neither allowed nor forbidden).
	 *
	 * @return bool
	 */
	public function isNeutral(): bool {
		return $this->state === self::NEUTRAL;
	}

	/**
	 * Return the diagnostic reason attached to this decision.
	 *
	 * @return string
	 */
	public function reason(): string {
		return $this->reason;
	}
}
