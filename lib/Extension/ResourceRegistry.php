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

namespace Somethingwithproof\Api\Extension;

use Somethingwithproof\Api\Log;

/**
 * Fault-isolated registry discovering sibling plugin REST resources via the
 * api_register_resources Cacti hook using plain PHP array descriptors.
 */
final class ResourceRegistry {
	/**
	 * Maximum total extension collections allowed to guard against resource exhaustion.
	 */
	private const MAX_RESOURCES = 128;

	/**
	 * Registered extension resources keyed by collection name.
	 *
	 * @var array<string, ExtensionResourceDescriptor>
	 */
	private array $resources = [];

	/**
	 * Isolated registration faults captured during hook dispatch.
	 *
	 * @var list<array{plugin: string, reason: string}>
	 */
	private array $faults = [];

	/**
	 * Register a validated ExtensionResourceDescriptor directly.
	 *
	 * @param ExtensionResourceDescriptor $descriptor Validated extension descriptor.
	 * @return void
	 * @throws \LogicException If collection already registered or capacity exceeded.
	 */
	public function register(ExtensionResourceDescriptor $descriptor): void {
		if (isset($this->resources[$descriptor->collection])) {
			throw new \LogicException(sprintf('Duplicate extension collection "%s".', $descriptor->collection));
		}

		if (count($this->resources) >= self::MAX_RESOURCES) {
			throw new \LogicException('Maximum extension resource limit reached.');
		}

		$this->resources[$descriptor->collection] = $descriptor;
	}

	/**
	 * Look up an extension collection descriptor by collection slug.
	 *
	 * @param string $collection Collection identifier.
	 * @return ExtensionResourceDescriptor|null
	 */
	public function get(string $collection): ?ExtensionResourceDescriptor {
		return $this->resources[$collection] ?? null;
	}

	/**
	 * Return all registered extension descriptors sorted by collection name.
	 *
	 * @return list<ExtensionResourceDescriptor>
	 */
	public function all(): array {
		$copy = $this->resources;
		ksort($copy, SORT_STRING);

		return array_values($copy);
	}

	/**
	 * Return all isolated registration faults recorded during hook discovery.
	 *
	 * @return list<array{plugin: string, reason: string}>
	 */
	public function faults(): array {
		return $this->faults;
	}

	/**
	 * Dispatch registered api_register_resources hooks with scratch-clone fault isolation.
	 *
	 * Each sibling hook receives an empty array registrar and returns an array of
	 * plain PHP descriptor arrays. Throwing or returning invalid entries isolates
	 * only the faulty plugin without impacting core or other sibling resources.
	 *
	 * @param list<array{name: string, file: string, function: string}> $hooks        Active plugin_hooks rows.
	 * @param (callable(string, string): (string|false))|null           $fileResolver Optional plugin file path resolver.
	 * @return void
	 */
	public function dispatchHooks(array $hooks, ?callable $fileResolver = null): void {
		foreach ($hooks as $hook) {
			$slug     = $hook['name'];
			$file     = $hook['file'];
			$function = $hook['function'];

			if (preg_match(ExtensionResourceDescriptor::SLUG_PATTERN, $slug) !== 1) {
				$this->recordFault($slug, 'Invalid plugin slug.');

				continue;
			}

			if ($file === '' || str_contains($file, '..') || str_starts_with($file, '/') || str_contains($file, "\0")) {
				$this->recordFault($slug, 'Invalid hook file path.');

				continue;
			}

			$scratch = clone $this;

			try {
				if ($fileResolver !== null) {
					$resolved = $fileResolver($slug, $file);

					if (is_string($resolved) && is_file($resolved)) {
						include_once($resolved);
					}
				}

				if (!is_callable($function)) {
					$this->recordFault($slug, 'Hook callback is not callable.');

					continue;
				}

				$returned = $function([]);

				if (!is_array($returned)) {
					$this->recordFault($slug, 'Hook callback must return an array of descriptors.');

					continue;
				}

				foreach ($returned as $rawDescriptor) {
					if (!is_array($rawDescriptor)) {
						throw new \InvalidArgumentException('Each resource descriptor must be an array.');
					}

					$scratch->register(ExtensionResourceDescriptor::fromArray($rawDescriptor, $slug));
				}

				$this->resources = $scratch->resources;
			} catch (\Throwable $e) {
				$this->recordFault($slug, $e::class . ': ' . $e->getMessage());
			}
		}
	}

	/**
	 * Record an isolated plugin fault and log a warning without leaking sensitive data.
	 *
	 * @param string $plugin Plugin slug.
	 * @param string $reason Failure summary.
	 * @return void
	 */
	private function recordFault(string $plugin, string $reason): void {
		$safe           = preg_replace('/[^A-Za-z0-9_.-]/', '_', $plugin) ?? 'unknown';
		$this->faults[] = ['plugin' => $safe, 'reason' => $reason];
		Log::warning(sprintf('Plugin API extension "%s" fault: %s', $safe, $reason));
	}
}
