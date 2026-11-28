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

use Somethingwithproof\Api\Repository\ApiRepositoryInterface;

/**
 * Google AIP-151 Long-Running Operations manager backed by plugin_api_operations.
 */
final class OperationManager {
	/**
	 * Construct an OperationManager bound to a repository.
	 *
	 * @param ApiRepositoryInterface $repository Persistence adapter.
	 */
	public function __construct(private readonly ApiRepositoryInterface $repository) {
	}

	/**
	 * Create a new pending Long-Running Operation and return its AIP-151 representation.
	 *
	 * @param int                  $userId         Owner user ID.
	 * @param string               $operationType  Operation type identifier (e.g. "device.poll", "device.reindex").
	 * @param string               $targetResource Canonical target resource name (e.g. "devices/42").
	 * @param array<string, mixed> $metadata       Additional operation metadata fields.
	 * @param int                  $now            Current Unix timestamp.
	 * @return array<string, mixed>
	 */
	public function create(
		int $userId,
		string $operationType,
		string $targetResource,
		array $metadata,
		int $now,
	): array {
		$id = self::generateUuidV4();

		$fullMetadata = array_merge([
			'@type'          => 'type.googleapis.com/cacti.api.v1.OperationMetadata',
			'operationType'  => $operationType,
			'targetResource' => $targetResource,
			'createTime'     => gmdate('Y-m-d\TH:i:s\Z', $now),
			'requestedCancellation' => false,
		], $metadata);

		$record = [
			'id'              => $id,
			'user_id'         => $userId,
			'operation_type'  => $operationType,
			'target_resource' => $targetResource,
			'done'            => false,
			'cancelled'       => false,
			'metadata'        => $fullMetadata,
			'response'        => null,
			'error'           => null,
			'created_at'      => $now,
			'updated_at'      => $now,
		];

		$this->repository->createOperation($record);

		return $this->toAipArray($record);
	}

	/**
	 * Fetch a single operation by ID, enforcing ownership unless caller is admin.
	 *
	 * @param string $id      Operation UUID.
	 * @param int    $userId  Requesting user ID.
	 * @param bool   $isAdmin Whether requesting user has API Admin privileges.
	 * @return array<string, mixed>
	 * @throws ApiException With 404 NOT_FOUND or 403 PERMISSION_DENIED.
	 */
	public function get(string $id, int $userId, bool $isAdmin): array {
		$record = $this->repository->getOperation($id);

		if ($record === null) {
			throw ApiException::notFound(sprintf('Operation "%s" was not found.', $id), 'cacti.net/Operation', 'operations/' . $id);
		}

		if (!$isAdmin && $record['user_id'] !== $userId) {
			throw ApiException::permissionDenied('You do not have permission to access this operation.');
		}

		return $this->toAipArray($record);
	}

	/**
	 * List operations visible to the requesting user.
	 *
	 * @param int  $userId  Requesting user ID.
	 * @param bool $isAdmin Whether caller can view all users' operations.
	 * @return list<array<string, mixed>>
	 */
	public function list(int $userId, bool $isAdmin): array {
		$rows = $this->repository->listOperations($isAdmin ? null : $userId);
		$out  = [];

		foreach ($rows as $row) {
			$out[] = $this->toAipArray($row);
		}

		return $out;
	}

	/**
	 * Mark an operation as successfully completed with a response payload.
	 *
	 * @param string               $id       Operation UUID.
	 * @param array<string, mixed> $response Result resource or summary payload.
	 * @param int                  $now      Current Unix timestamp.
	 * @return array<string, mixed>
	 * @throws ApiException If the operation does not exist.
	 */
	public function complete(string $id, array $response, int $now): array {
		$record = $this->repository->getOperation($id);

		if ($record === null) {
			throw ApiException::notFound(sprintf('Operation "%s" was not found.', $id), 'cacti.net/Operation', 'operations/' . $id);
		}

		if ($record['done']) {
			return $this->toAipArray($record);
		}

		$record['done']       = true;
		$record['response']   = $response;
		$record['error']      = null;
		$record['updated_at'] = $now;
		$record['metadata']['endTime'] = gmdate('Y-m-d\TH:i:s\Z', $now);

		$this->repository->updateOperation($record);

		return $this->toAipArray($record);
	}

	/**
	 * Mark an operation as failed with an AIP-193 ErrorStatus.
	 *
	 * @param string      $id     Operation UUID.
	 * @param ErrorStatus $status Canonical error status.
	 * @param int         $now    Current Unix timestamp.
	 * @return array<string, mixed>
	 * @throws ApiException If the operation does not exist.
	 */
	public function fail(string $id, ErrorStatus $status, int $now): array {
		$record = $this->repository->getOperation($id);

		if ($record === null) {
			throw ApiException::notFound(sprintf('Operation "%s" was not found.', $id), 'cacti.net/Operation', 'operations/' . $id);
		}

		if ($record['done']) {
			return $this->toAipArray($record);
		}

		$record['done']       = true;
		$record['response']   = null;
		$record['error']      = $status->toStatusObject();
		$record['updated_at'] = $now;
		$record['metadata']['endTime'] = gmdate('Y-m-d\TH:i:s\Z', $now);

		$this->repository->updateOperation($record);

		return $this->toAipArray($record);
	}

	/**
	 * Cancel an in-progress operation (AIP-151 :cancel custom method).
	 *
	 * Cancelling an already-cancelled operation is idempotent; cancelling a
	 * completed non-cancelled operation throws FAILED_PRECONDITION.
	 *
	 * @param string $id      Operation UUID.
	 * @param int    $userId  Requesting user ID.
	 * @param bool   $isAdmin Whether caller has API Admin privileges.
	 * @param int    $now     Current Unix timestamp.
	 * @return array<string, mixed>
	 * @throws ApiException On missing operation, permission denial, or already-completed state.
	 */
	public function cancel(string $id, int $userId, bool $isAdmin, int $now): array {
		$record = $this->repository->getOperation($id);

		if ($record === null) {
			throw ApiException::notFound(sprintf('Operation "%s" was not found.', $id), 'cacti.net/Operation', 'operations/' . $id);
		}

		if (!$isAdmin && $record['user_id'] !== $userId) {
			throw ApiException::permissionDenied('You do not have permission to cancel this operation.');
		}

		if ($record['cancelled']) {
			return $this->toAipArray($record);
		}

		if ($record['done']) {
			throw ApiException::failedPrecondition(sprintf('Operation "%s" has already completed and cannot be cancelled.', $id));
		}

		$record['done']       = true;
		$record['cancelled']  = true;
		$record['response']   = null;
		$record['error']      = (new ErrorStatus(ErrorStatus::CANCELLED, 'Operation was cancelled by client.'))->toStatusObject();
		$record['updated_at'] = $now;
		$record['metadata']['requestedCancellation'] = true;
		$record['metadata']['endTime']               = gmdate('Y-m-d\TH:i:s\Z', $now);

		$this->repository->updateOperation($record);

		return $this->toAipArray($record);
	}

	/**
	 * Delete an operation record from storage.
	 *
	 * @param string $id      Operation UUID.
	 * @param int    $userId  Requesting user ID.
	 * @param bool   $isAdmin Whether caller has API Admin privileges.
	 * @return void
	 * @throws ApiException If missing or unauthorized.
	 */
	public function delete(string $id, int $userId, bool $isAdmin): void {
		$record = $this->repository->getOperation($id);

		if ($record === null) {
			throw ApiException::notFound(sprintf('Operation "%s" was not found.', $id), 'cacti.net/Operation', 'operations/' . $id);
		}

		if (!$isAdmin && $record['user_id'] !== $userId) {
			throw ApiException::permissionDenied('You do not have permission to delete this operation.');
		}

		$this->repository->deleteOperation($id);
	}

	/**
	 * Convert a stored operation record into its canonical AIP-151 JSON representation.
	 *
	 * @param array{id: string, user_id: int, operation_type: string, target_resource: string, done: bool, cancelled: bool, metadata: array<string, mixed>, response: array<string, mixed>|null, error: array<string, mixed>|null, created_at: int, updated_at: int} $record Stored operation record.
	 * @return array<string, mixed>
	 */
	public function toAipArray(array $record): array {
		$out = [
			'name'       => ResourceName::build('operations', $record['id']),
			'id'         => $record['id'],
			'done'       => $record['done'],
			'metadata'   => $record['metadata'],
			'createTime' => gmdate('Y-m-d\TH:i:s\Z', $record['created_at']),
			'updateTime' => gmdate('Y-m-d\TH:i:s\Z', $record['updated_at']),
		];

		if ($record['error'] !== null) {
			$out['error'] = $record['error'];
		} elseif ($record['response'] !== null) {
			$out['response'] = $record['response'];
		}

		return $out;
	}

	/**
	 * Generate a cryptographically secure RFC 4122 UUIDv4 string.
	 *
	 * @return string
	 */
	public static function generateUuidV4(): string {
		$bytes    = random_bytes(16);
		$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
		$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
		$hex      = bin2hex($bytes);

		return sprintf(
			'%s-%s-%s-%s-%s',
			substr($hex, 0, 8),
			substr($hex, 8, 4),
			substr($hex, 12, 4),
			substr($hex, 16, 4),
			substr($hex, 20, 12)
		);
	}
}
