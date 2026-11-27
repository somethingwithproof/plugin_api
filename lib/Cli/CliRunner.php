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

namespace Somethingwithproof\Api\Cli;

use Somethingwithproof\Api\Aip\ApiException;
use Somethingwithproof\Api\Aip\OperationManager;
use Somethingwithproof\Api\Auth\TokenService;
use Somethingwithproof\Api\Extension\ResourceRegistry;
use Somethingwithproof\Api\OpenApi\OpenApiGenerator;
use Somethingwithproof\Api\Repository\ApiRepositoryInterface;
use Somethingwithproof\Api\Settings;

/**
 * CLI command dispatcher for cli/api.php supporting token-create, token-list,
 * token-revoke, openapi, operations, and health commands.
 */
final class CliRunner {
	/**
	 * Construct a CliRunner bound to repository, settings, and extension registry.
	 *
	 * @param ApiRepositoryInterface $repository Persistence adapter.
	 * @param Settings               $settings   Active plugin settings.
	 * @param ResourceRegistry       $registry   Discovered extension resources.
	 * @param string                 $apiVersion Plugin version string.
	 */
	public function __construct(
		private readonly ApiRepositoryInterface $repository,
		private readonly Settings $settings,
		private readonly ResourceRegistry $registry,
		private readonly string $apiVersion = '0.1.0',
	) {
	}

	/**
	 * Execute a CLI command argument vector and return stdout, stderr, and exit code.
	 *
	 * @param list<string> $argv Command arguments excluding script filename.
	 * @param int|null     $now  Optional Unix timestamp override for tests.
	 * @return array{exitCode: int, stdout: string, stderr: string}
	 */
	public function run(array $argv, ?int $now = null): array {
		$timestamp = $now ?? time();

		if ($argv === [] || in_array($argv[0], ['help', '--help', '-h'], true)) {
			return [
				'exitCode' => 0,
				'stdout'   => self::usage(),
				'stderr'   => '',
			];
		}

		$subcommand = $argv[0];
		$options    = self::parseOptions(array_slice($argv, 1));

		try {
			return match ($subcommand) {
				'token-create' => $this->tokenCreate($options, $timestamp),
				'token-list'   => $this->tokenList($options),
				'token-revoke' => $this->tokenRevoke($options, $timestamp),
				'openapi'      => $this->openapi(),
				'operations'   => $this->operations($options),
				'health'       => $this->health($timestamp),
				default        => [
					'exitCode' => 2,
					'stdout'   => '',
					'stderr'   => sprintf("Unknown command \"%s\".\n%s", $subcommand, self::usage()),
				],
			};
		} catch (ApiException $e) {
			return [
				'exitCode' => 1,
				'stdout'   => '',
				'stderr'   => sprintf("ERROR [%s]: %s\n", $e->errorStatus->status, $e->getMessage()),
			];
		}
	}

	/**
	 * Issue a new Application Password token from CLI.
	 *
	 * @param array<string, string> $options Parsed CLI options.
	 * @param int                   $now     Current Unix timestamp.
	 * @return array{exitCode: int, stdout: string, stderr: string}
	 */
	private function tokenCreate(array $options, int $now): array {
		$userId = isset($options['user-id']) && is_numeric($options['user-id']) ? (int) $options['user-id'] : 0;
		$name   = $options['name'] ?? '';
		$scopes = isset($options['scopes']) && $options['scopes'] !== ''
			? array_values(array_filter(array_map('trim', explode(',', $options['scopes']))))
			: ['read'];
		$cidrs = isset($options['cidr']) && $options['cidr'] !== ''
			? array_values(array_filter(array_map('trim', explode(',', $options['cidr']))))
			: [];

		if ($userId <= 0 || $this->repository->getUserById($userId) === null) {
			return ['exitCode' => 2, 'stdout' => '', 'stderr' => "ERROR: Valid --user-id is required.\n"];
		}

		$ttlDays   = isset($options['ttl-days']) && is_numeric($options['ttl-days'])
			? (int) $options['ttl-days']
			: $this->settings->defaultTokenTtlDays;
		$expiresAt = $ttlDays > 0 ? $now + ($ttlDays * 86400) : null;

		$service = new TokenService($this->repository);
		$created = $service->create($userId, $name, $scopes, $expiresAt, $cidrs, $now);

		$payload                   = $created['resource'];
		$payload['plainTextToken'] = $created['token'];

		return [
			'exitCode' => 0,
			'stdout'   => json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
			'stderr'   => '',
		];
	}

	/**
	 * List stored Application Password token metadata.
	 *
	 * @param array<string, string> $options Parsed CLI options.
	 * @return array{exitCode: int, stdout: string, stderr: string}
	 */
	private function tokenList(array $options): array {
		$userId  = isset($options['user-id']) && is_numeric($options['user-id']) ? (int) $options['user-id'] : null;
		$service = new TokenService($this->repository);
		$items   = array_map(static fn (array $r): array => $service->toAipArray($r), $this->repository->listTokens($userId));

		return [
			'exitCode' => 0,
			'stdout'   => json_encode(['tokens' => $items, 'totalSize' => count($items)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
			'stderr'   => '',
		];
	}

	/**
	 * Revoke an Application Password token by UUID.
	 *
	 * @param array<string, string> $options Parsed CLI options.
	 * @param int                   $now     Current Unix timestamp.
	 * @return array{exitCode: int, stdout: string, stderr: string}
	 */
	private function tokenRevoke(array $options, int $now): array {
		$id = trim($options['id'] ?? '');

		if ($id === '') {
			return ['exitCode' => 2, 'stdout' => '', 'stderr' => "ERROR: --id=<token-uuid> is required.\n"];
		}

		$service = new TokenService($this->repository);
		$revoked = $service->revoke($id, 0, true, $now);

		return [
			'exitCode' => 0,
			'stdout'   => json_encode($revoked, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
			'stderr'   => '',
		];
	}

	/**
	 * Output the live OpenAPI 3.1 specification as formatted JSON.
	 *
	 * @return array{exitCode: int, stdout: string, stderr: string}
	 */
	private function openapi(): array {
		$spec = OpenApiGenerator::generate($this->registry, $this->apiVersion);

		return [
			'exitCode' => 0,
			'stdout'   => json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
			'stderr'   => '',
		];
	}

	/**
	 * List Long-Running Operations.
	 *
	 * @param array<string, string> $options Parsed CLI options.
	 * @return array{exitCode: int, stdout: string, stderr: string}
	 */
	private function operations(array $options): array {
		$userId  = isset($options['user-id']) && is_numeric($options['user-id']) ? (int) $options['user-id'] : 0;
		$isAdmin = $userId === 0;
		$ops     = (new OperationManager($this->repository))->list($userId, $isAdmin);

		return [
			'exitCode' => 0,
			'stdout'   => json_encode(['operations' => $ops, 'totalSize' => count($ops)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
			'stderr'   => '',
		];
	}

	/**
	 * Emit JSON health summary for monitoring and CI smoke tests.
	 *
	 * @param int $now Current Unix timestamp.
	 * @return array{exitCode: int, stdout: string, stderr: string}
	 */
	private function health(int $now): array {
		$stats = $this->repository->getHealthStats($now);
		$out   = [
			'status'               => $this->settings->enabled ? 'ok' : 'disabled',
			'version'              => $this->apiVersion,
			'enabled'              => $this->settings->enabled,
			'rateLimitPerMin'      => $this->settings->rateLimitPerMin,
			'requireTls'           => $this->settings->requireTls,
			'extensionCollections' => array_map(static fn ($d): string => $d->collection, $this->registry->all()),
			'extensionFaults'      => $this->registry->faults(),
			'stats'                => $stats,
		];

		return [
			'exitCode' => 0,
			'stdout'   => json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
			'stderr'   => '',
		];
	}

	/**
	 * Parse --key=value CLI flags into an associative array.
	 *
	 * @param list<string> $args Raw flag tokens.
	 * @return array<string, string>
	 */
	private static function parseOptions(array $args): array {
		$out = [];
		$len = count($args);

		for ($i = 0; $i < $len; $i++) {
			$arg = $args[$i];

			if (!str_starts_with($arg, '--')) {
				continue;
			}

			$body = substr($arg, 2);
			$eq   = strpos($body, '=');

			if ($eq !== false) {
				$out[substr($body, 0, $eq)] = substr($body, $eq + 1);
			} elseif ($i + 1 < $len && !str_starts_with($args[$i + 1], '--')) {
				$out[$body] = $args[$i + 1];
				$i++;
			} else {
				$out[$body] = '1';
			}
		}

		return $out;
	}

	/**
	 * Return CLI usage help text.
	 *
	 * @return string
	 */
	private static function usage(): string {
		return "Usage: php plugins/api/cli/api.php <command> [options]\n"
			. "Commands:\n"
			. "  token-create --user-id=<id> --name=<label> [--scopes=read,write] [--ttl-days=90] [--cidr=10.0.0.0/8]\n"
			. "  token-list [--user-id=<id>]\n"
			. "  token-revoke --id=<token-uuid>\n"
			. "  openapi\n"
			. "  operations [--user-id=<id>]\n"
			. "  health\n";
	}
}
