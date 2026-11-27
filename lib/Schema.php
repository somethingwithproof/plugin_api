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
 * Plugin schema migrations, hook table, and uninstall table retention control.
 */
final class Schema {
	/**
	 * Current database schema version for plugin_api.
	 */
	public const VERSION = 1;

	/**
	 * Cacti hooks registered by plugin_api.
	 */
	public const HOOKS = [
		'auth_alternate_realms'  => 'api_auth_alternate_realms',
		'config_arrays'          => 'api_config_arrays',
		'config_settings'        => 'api_config_settings',
		'draw_navigation_text'   => 'api_draw_navigation_text',
		'casc_register_providers' => 'api_casc_register_providers',
		'doctor_register_checks' => 'api_doctor_register_checks',
		'abilities_register'     => 'api_abilities_register',
	];

	/**
	 * Plugin slug used in Cacti tables.
	 */
	private const PLUGIN = 'api';

	/**
	 * Cacti settings key tracking installed schema version.
	 */
	private const SETTING = 'api_schema_version';

	/**
	 * Construct a schema manager bound to an optional PDO connection.
	 *
	 * @param \PDO|false $connection Cacti database connection handle, or false for default.
	 */
	public function __construct(private readonly \PDO|false $connection = false) {
	}

	/**
	 * Read the currently stored schema version from Cacti's settings table.
	 *
	 * @return int
	 */
	public function storedVersion(): int {
		$value = db_fetch_cell_prepared(
			'SELECT value FROM settings WHERE name = ?',
			[self::SETTING],
			'',
			true,
			$this->connection
		);

		return is_numeric($value) ? (int) $value : 0;
	}

	/**
	 * Return true when the stored schema version matches VERSION.
	 *
	 * @return bool
	 */
	public function isCurrent(): bool {
		return $this->storedVersion() === self::VERSION;
	}

	/**
	 * Create or migrate all plugin_api database tables idempotently.
	 *
	 * @return bool True on success, false if downgrade refused or table creation failed.
	 */
	public function migrate(): bool {
		$from = $this->storedVersion();

		if ($from > self::VERSION) {
			Log::error(sprintf('Schema version %d is newer than plugin code (%d); refusing downgrade', $from, self::VERSION));

			return false;
		}

		foreach (self::tables() as $table => $definition) {
			api_plugin_db_table_create(self::PLUGIN, $table, $definition);

			if (!$this->tableExists($table)) {
				Log::error(sprintf('Unable to create table %s; schema left at version %d', $table, $from));

				return false;
			}
		}

		db_execute_prepared(
			'REPLACE INTO settings (name, value) VALUES (?, ?)',
			[self::SETTING, (string) self::VERSION],
			true,
			$this->connection
		);

		if ($from !== self::VERSION) {
			Log::info(sprintf('Schema migrated from version %d to %d', $from, self::VERSION));
		}

		return true;
	}

	/**
	 * Return the plugin version recorded in Cacti's plugin_config table.
	 *
	 * @return string
	 */
	public function recordedPluginVersion(): string {
		$value = db_fetch_cell_prepared(
			'SELECT version FROM plugin_config WHERE directory = ?',
			[self::PLUGIN],
			'',
			true,
			$this->connection
		);

		return is_scalar($value) ? (string) $value : '';
	}

	/**
	 * Persist plugin metadata into Cacti's plugin_config table.
	 *
	 * @param string $version  Plugin version string from INFO.
	 * @param string $author   Plugin author string from INFO.
	 * @param string $homepage Plugin homepage URL from INFO.
	 * @return void
	 */
	public function recordPluginVersion(string $version, string $author, string $homepage): void {
		db_execute_prepared(
			'UPDATE plugin_config SET version = ?, author = ?, webpage = ? WHERE directory = ?',
			[$version, $author, $homepage, self::PLUGIN],
			true,
			$this->connection
		);
	}

	/**
	 * Return true when plugin_api is marked enabled in plugin_config.
	 *
	 * @return bool
	 */
	public function pluginEnabled(): bool {
		$status = db_fetch_cell_prepared(
			'SELECT status FROM plugin_config WHERE directory = ?',
			[self::PLUGIN],
			'',
			true,
			$this->connection
		);

		return is_numeric($status) && (int) $status === 1;
	}

	/**
	 * Verify that all declared hooks and realms are registered in Cacti's database.
	 *
	 * @return bool
	 */
	public function hooksRegistered(): bool {
		$placeholders = implode(', ', array_fill(0, count(self::HOOKS), '?'));
		$hooks        = db_fetch_cell_prepared(
			"SELECT COUNT(DISTINCT hook) FROM plugin_hooks WHERE name = ? AND hook IN ($placeholders)",
			array_merge([self::PLUGIN], array_keys(self::HOOKS)),
			'',
			true,
			$this->connection
		);
		$realms = db_fetch_cell_prepared(
			'SELECT COUNT(*) FROM plugin_realms WHERE plugin = ?',
			[self::PLUGIN],
			'',
			true,
			$this->connection
		);

		return (is_numeric($hooks) ? (int) $hooks : 0) === count(self::HOOKS)
			&& (is_numeric($realms) ? (int) $realms : 0) === count(Pages::realms());
	}

	/**
	 * Remove table creation records so Cacti leaves state tables intact during uninstall.
	 *
	 * @return void
	 */
	public function untrackTables(): void {
		db_execute_prepared(
			"DELETE FROM plugin_db_changes WHERE plugin = ? AND method = 'create'",
			[self::PLUGIN],
			true,
			$this->connection
		);
	}

	/**
	 * Remove the stored schema version setting during full uninstall.
	 *
	 * @return void
	 */
	public function forgetVersion(): void {
		db_execute_prepared(
			'DELETE FROM settings WHERE name = ?',
			[self::SETTING],
			true,
			$this->connection
		);
	}

	/**
	 * Check whether a table exists in the active database schema.
	 *
	 * @param string $table Table name to verify.
	 * @return bool
	 */
	private function tableExists(string $table): bool {
		/** @var mixed $count */
		$count = db_fetch_cell_prepared(
			'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
			[$table],
			'',
			true,
			$this->connection
		);

		return is_numeric($count) && (int) $count > 0;
	}

	/**
	 * Return Cacti api_plugin_db_table_create() definitions for all plugin state tables.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function tables(): array {
		return [
			'plugin_api_tokens' => [
				'columns' => [
					['name' => 'id', 'type' => 'varchar(36)', 'NULL' => false, 'default' => ''],
					['name' => 'user_id', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'name', 'type' => 'varchar(128)', 'NULL' => false, 'default' => ''],
					['name' => 'token_prefix', 'type' => 'varchar(16)', 'NULL' => false, 'default' => ''],
					['name' => 'token_hash', 'type' => 'char(64)', 'NULL' => false, 'default' => ''],
					['name' => 'scopes', 'type' => 'varchar(128)', 'NULL' => false, 'default' => 'read'],
					['name' => 'cidr_allowlist', 'type' => 'varchar(512)', 'NULL' => false, 'default' => ''],
					['name' => 'created_at', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'expires_at', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => true],
					['name' => 'last_used_at', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => true],
					['name' => 'revoked_at', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => true],
				],
				'primary' => 'id',
				'keys'    => [
					['name' => 'idx_token_prefix', 'columns' => 'token_prefix'],
					['name' => 'idx_user_id', 'columns' => 'user_id'],
					['name' => 'idx_revoked_expires', 'columns' => ['revoked_at', 'expires_at']],
				],
				'type'    => 'InnoDB',
				'comment' => 'Application Password tokens hashed with SHA-256 at rest',
			],
			'plugin_api_operations' => [
				'columns' => [
					['name' => 'id', 'type' => 'varchar(36)', 'NULL' => false, 'default' => ''],
					['name' => 'user_id', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'operation_type', 'type' => 'varchar(64)', 'NULL' => false, 'default' => ''],
					['name' => 'target_resource', 'type' => 'varchar(255)', 'NULL' => false, 'default' => ''],
					['name' => 'done', 'type' => 'tinyint(1)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'cancelled', 'type' => 'tinyint(1)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'metadata_json', 'type' => 'mediumtext', 'NULL' => false],
					['name' => 'response_json', 'type' => 'mediumtext', 'NULL' => true],
					['name' => 'error_json', 'type' => 'mediumtext', 'NULL' => true],
					['name' => 'created_at', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'updated_at', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
				],
				'primary' => 'id',
				'keys'    => [
					['name' => 'idx_done_created', 'columns' => ['done', 'created_at']],
					['name' => 'idx_user_id', 'columns' => 'user_id'],
				],
				'type'    => 'InnoDB',
				'comment' => 'Google AIP-151 Long-Running Operations state queue',
			],
			'plugin_api_idempotency' => [
				'columns' => [
					['name' => 'request_id', 'type' => 'varchar(64)', 'NULL' => false, 'default' => ''],
					['name' => 'user_id', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'method', 'type' => 'varchar(16)', 'NULL' => false, 'default' => ''],
					['name' => 'path', 'type' => 'varchar(255)', 'NULL' => false, 'default' => ''],
					['name' => 'request_hash', 'type' => 'char(64)', 'NULL' => false, 'default' => ''],
					['name' => 'status_code', 'type' => 'smallint(5)', 'unsigned' => true, 'NULL' => false, 'default' => '200'],
					['name' => 'response_json', 'type' => 'mediumtext', 'NULL' => false],
					['name' => 'created_at', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'expires_at', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
				],
				'primary' => 'request_id',
				'keys'    => [
					['name' => 'idx_expires_at', 'columns' => 'expires_at'],
				],
				'type'    => 'InnoDB',
				'comment' => 'Google AIP-155 idempotency key replay cache',
			],
			'plugin_api_rate_limits' => [
				'columns' => [
					['name' => 'bucket_key', 'type' => 'char(64)', 'NULL' => false, 'default' => ''],
					['name' => 'window_start', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'hit_count', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
				],
				'primary' => 'bucket_key',
				'keys'    => [
					['name' => 'idx_window_start', 'columns' => 'window_start'],
				],
				'type'    => 'InnoDB',
				'comment' => 'Per-token and per-IP fixed-window API rate limit counters',
			],
		];
	}
}
