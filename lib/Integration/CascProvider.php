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

namespace Somethingwithproof\Api\Integration;

use Somethingwithproof\Api\Settings;

/**
 * Decoupled Configuration-as-Code provider (slug: "api") for casc_register_providers.
 *
 * Exports, validates, plans, and applies only declarative API configuration
 * (rate limits, token TTL defaults, CORS origins, TLS requirement, body limits)
 * and NEVER exports runtime state or token hashes.
 */
final class CascProvider {
	/**
	 * Provider slug registered with CasC.
	 */
	public const SLUG = 'api';

	/**
	 * Declarative schema version.
	 */
	public const SCHEMA_VERSION = '1.0';

	/**
	 * Build the plain PHP descriptor array for the "api" CasC provider.
	 *
	 * @param callable(): Settings               $settingsReader Callback returning live Settings.
	 * @param callable(array<string, string>): void $settingsWriter Callback persisting key => Cacti setting value pairs.
	 * @return array{slug: string, schemaVersion: string, export: callable(array<string, mixed>): array<string, mixed>, validate: callable(array<string, mixed>): list<string>, plan: callable(array<string, mixed>, array<string, mixed>): list<array<string, mixed>>, apply: callable(array<string, mixed>, string, array<string, mixed>): array<string, mixed>}
	 */
	public static function descriptor(callable $settingsReader, callable $settingsWriter): array {
		$export = static function (array $options = []) use ($settingsReader): array {
			return $settingsReader()->toExportArray();
		};

		$validate = static function (array $desired): array {
			$errors = [];

			foreach (array_keys($desired) as $key) {
				if (!in_array((string) $key, Settings::NAMES, true)) {
					$errors[] = sprintf('Unknown api setting "%s".', (string) $key);
				}
			}

			if (isset($desired['api_rate_limit_per_min'])) {
				$rate = $desired['api_rate_limit_per_min'];

				if (!is_int($rate) || $rate < 1 || $rate > 100000) {
					$errors[] = 'api_rate_limit_per_min must be an integer between 1 and 100000.';
				}
			}

			if (isset($desired['api_default_token_ttl_days'])) {
				$ttl = $desired['api_default_token_ttl_days'];

				if (!is_int($ttl) || $ttl < 0 || $ttl > 3650) {
					$errors[] = 'api_default_token_ttl_days must be an integer between 0 and 3650.';
				}
			}

			if (isset($desired['api_max_body_bytes'])) {
				$maxBytes = $desired['api_max_body_bytes'];

				if (!is_int($maxBytes) || $maxBytes < 1024 || $maxBytes > 10485760) {
					$errors[] = 'api_max_body_bytes must be an integer between 1024 and 10485760.';
				}
			}

			$origins = $desired['api_cors_origins'] ?? [];
			$creds   = ($desired['api_cors_allow_credentials'] ?? false) === true;

			if (!is_array($origins)) {
				$errors[] = 'api_cors_origins must be a list of origin URLs.';
			} elseif ($creds && in_array('*', $origins, true)) {
				$errors[] = 'api_cors_origins cannot include "*" when api_cors_allow_credentials is true.';
			}

			return $errors;
		};

		$plan = static function (array $desired, array $options = []) use ($settingsReader, $validate): array {
			$validationErrors = $validate($desired);

			if ($validationErrors !== []) {
				return array_map(
					static fn (string $msg): array => ['operation' => 'error', 'key' => self::SLUG, 'message' => $msg],
					$validationErrors
				);
			}

			$current = $settingsReader()->toExportArray();
			$actions = [];

			foreach (Settings::NAMES as $key) {
				if (!array_key_exists($key, $desired)) {
					continue;
				}

				if (($current[$key] ?? null) !== $desired[$key]) {
					$actions[] = [
						'operation' => 'update',
						'key'       => 'api.' . $key,
						'before'    => $current[$key] ?? null,
						'after'     => $desired[$key],
					];
				}
			}

			return $actions;
		};

		$apply = static function (array $desired, string $planHash, array $options = []) use ($settingsWriter, $validate): array {
			$validationErrors = $validate($desired);

			if ($validationErrors !== []) {
				return ['ok' => false, 'error' => implode('; ', $validationErrors)];
			}

			$toWrite = [];

			foreach (Settings::NAMES as $key) {
				if (!array_key_exists($key, $desired)) {
					continue;
				}

				$val = $desired[$key];

				if (is_bool($val)) {
					$toWrite[$key] = $val ? 'on' : '';
				} elseif (is_array($val)) {
					$parts = [];

					foreach ($val as $item) {
						if (is_scalar($item)) {
							$parts[] = trim((string) $item);
						}
					}
					$toWrite[$key] = implode("\n", array_values(array_filter($parts)));
				} elseif (is_scalar($val)) {
					$toWrite[$key] = (string) $val;
				}
			}

			$settingsWriter($toWrite);

			return ['ok' => true, 'updated' => array_keys($toWrite), 'planHash' => $planHash];
		};

		return [
			'slug'          => self::SLUG,
			'schemaVersion' => self::SCHEMA_VERSION,
			'export'        => $export,
			'validate'      => $validate,
			'plan'          => $plan,
			'apply'         => $apply,
		];
	}

	/**
	 * Register the "api" CasC provider with either a plain array registrar or an object registrar.
	 *
	 * Uses reflection on the registrar's register() signature when an object is
	 * supplied so plugin_api never imports any sibling plugin PHP classes.
	 *
	 * @param mixed                                 $registrar      Array or object registrar passed by hook caller.
	 * @param callable(): Settings                  $settingsReader Callback returning live Settings.
	 * @param callable(array<string, string>): void $settingsWriter Callback persisting key => value settings.
	 * @return mixed
	 */
	public static function register(mixed $registrar, callable $settingsReader, callable $settingsWriter): mixed {
		$desc = self::descriptor($settingsReader, $settingsWriter);

		if (is_array($registrar)) {
			$registrar[self::SLUG] = $desc;

			return $registrar;
		}

		if (is_object($registrar) && method_exists($registrar, 'register')) {
			$refMethod = new \ReflectionMethod($registrar, 'register');
			$params    = $refMethod->getParameters();
			$firstType = isset($params[0]) ? $params[0]->getType() : null;

			if ($firstType instanceof \ReflectionNamedType && !$firstType->isBuiltin()) {
				$className = $firstType->getName();

				if (class_exists($className)) {
					$refClass = new \ReflectionClass($className);
					$instance = $refClass->newInstance(
						$desc['slug'],
						$desc['schemaVersion'],
						$desc['export'],
						$desc['validate'],
						$desc['plan'],
						$desc['apply']
					);
					$registrar->register($instance);

					return $registrar;
				}
			}

			$registrar->register($desc);
		}

		return $registrar;
	}
}
