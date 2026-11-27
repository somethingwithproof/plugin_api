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

namespace Somethingwithproof\Api\Resource;

use Somethingwithproof\Api\Aip\ApiException;
use Somethingwithproof\Api\Aip\Etag;
use Somethingwithproof\Api\Aip\FilterParser;
use Somethingwithproof\Api\Aip\OrderByParser;
use Somethingwithproof\Api\Aip\ResourceName;
use Somethingwithproof\Api\Auth\AccessResult;
use Somethingwithproof\Api\Auth\Principal;
use Somethingwithproof\Api\Http\Request;
use Somethingwithproof\Api\Http\Response;

/**
 * Google AIP resource controller for Cacti devices (devices/{device}) including
 * AIP-136 custom methods :enable, :disable, :reindex, and :poll.
 */
final class DevicesResource implements ResourceHandlerInterface {
	/**
	 * Allowlisted filter/sort column map.
	 *
	 * @var array<string, string>
	 */
	private const SORT_MAP = [
		'id'           => 'id',
		'description'  => 'description',
		'hostname'     => 'hostname',
		'site_id'      => 'site_id',
		'poller_id'    => 'poller_id',
		'status'       => 'status',
		'disabled'     => 'disabled',
		'location'     => 'location',
		'external_id'  => 'external_id',
		'snmp_version' => 'snmp_version',
	];

	/**
	 * Allowlisted mutable fields accepted by update_mask.
	 *
	 * @var list<string>
	 */
	private const MUTABLE_FIELDS = [
		'description',
		'hostname',
		'poller_id',
		'site_id',
		'host_template_id',
		'location',
		'notes',
		'external_id',
		'snmp_version',
		'snmp_port',
		'disabled',
	];

	/**
	 * Allowlisted readable fields accepted by read_mask.
	 *
	 * @var list<string>
	 */
	private const READABLE_FIELDS = [
		'name',
		'id',
		'description',
		'hostname',
		'poller_id',
		'site_id',
		'host_template_id',
		'location',
		'notes',
		'external_id',
		'snmp_version',
		'snmp_port',
		'disabled',
		'status',
		'availability',
		'etag',
	];

	/**
	 * {@inheritdoc}
	 */
	public function collection(): string {
		return 'devices';
	}

	/**
	 * {@inheritdoc}
	 */
	public function list(Request $request, ResourceContext $ctx, ?string $parentId = null): Response {
		$ctx->enforce($ctx->principal->checkAccess(Principal::SCOPE_READ, 'host.php'));

		$filter  = FilterParser::parse($request->queryParam('filter') ?? '');
		$orderBy = OrderByParser::parse($request->queryParam('order_by') ?? '', self::SORT_MAP);
		$rows    = $ctx->repository->listDevices($filter, $orderBy);

		$visible = [];

		foreach ($rows as $row) {
			$deviceId = is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;

			if ($ctx->principal->isAdmin || $ctx->repository->isDeviceAllowedForUser($ctx->principal->userId, $deviceId)) {
				$visible[] = self::serialize($row);
			}
		}

		return Response::ok($ctx->paginate('devices', $visible, $request, self::READABLE_FIELDS));
	}

	/**
	 * {@inheritdoc}
	 */
	public function get(string $id, Request $request, ResourceContext $ctx, ?string $parentId = null): Response {
		$intId = ResourceName::parse('devices/' . $id)->intId();
		$this->enforceDeviceAccess($ctx, Principal::SCOPE_READ, $intId);

		$row = $ctx->repository->getDevice($intId);

		if ($row === null) {
			throw ApiException::notFound(sprintf('Device "%d" was not found.', $intId), 'cacti.net/Device', 'devices/' . $intId);
		}

		$serialized = self::serialize($row);
		$etag       = is_string($serialized['etag']) ? $serialized['etag'] : '';

		return Response::ok(
			$ctx->projectSingle($serialized, $request, self::READABLE_FIELDS, 'devices'),
			['ETag' => $etag]
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function create(Request $request, ResourceContext $ctx, ?string $parentId = null): Response {
		$ctx->enforce($ctx->principal->checkAccess(Principal::SCOPE_WRITE, 'host.php'));

		$body        = $request->jsonBody($ctx->settings->maxBodyBytes);
		$description = is_string($body['description'] ?? null) ? trim($body['description']) : '';
		$hostname    = is_string($body['hostname'] ?? null) ? trim($body['hostname']) : '';

		$violations = [];

		if ($description === '' || strlen($description) > 150) {
			$violations[] = ['field' => 'description', 'description' => 'description must be 1 to 150 characters.'];
		}

		if ($hostname === '' || strlen($hostname) > 100) {
			$violations[] = ['field' => 'hostname', 'description' => 'hostname must be 1 to 100 characters.'];
		}

		if ($violations !== []) {
			throw ApiException::invalidArgument('Invalid device payload.', $violations);
		}

		$created    = $ctx->repository->createDevice([
			'description'      => $description,
			'hostname'         => $hostname,
			'poller_id'        => is_numeric($body['poller_id'] ?? null) ? max(1, (int) $body['poller_id']) : 1,
			'site_id'          => is_numeric($body['site_id'] ?? null) ? max(0, (int) $body['site_id']) : 0,
			'host_template_id' => is_numeric($body['host_template_id'] ?? null) ? max(0, (int) $body['host_template_id']) : 0,
			'location'         => is_scalar($body['location'] ?? null) ? (string) $body['location'] : '',
			'notes'            => is_scalar($body['notes'] ?? null) ? (string) $body['notes'] : '',
			'external_id'      => is_scalar($body['external_id'] ?? null) ? (string) $body['external_id'] : '',
			'snmp_version'     => is_numeric($body['snmp_version'] ?? null) ? (int) $body['snmp_version'] : 2,
			'snmp_port'        => is_numeric($body['snmp_port'] ?? null) ? (int) $body['snmp_port'] : 161,
			'disabled'         => ($body['disabled'] ?? false) === true,
		]);
		$serialized = self::serialize($created);
		$etag       = is_string($serialized['etag']) ? $serialized['etag'] : '';

		return Response::created($serialized, ['ETag' => $etag]);
	}

	/**
	 * {@inheritdoc}
	 */
	public function update(string $id, Request $request, ResourceContext $ctx, ?string $parentId = null): Response {
		$intId = ResourceName::parse('devices/' . $id)->intId();
		$this->enforceDeviceAccess($ctx, Principal::SCOPE_WRITE, $intId);

		$existing = $ctx->repository->getDevice($intId);

		if ($existing === null) {
			throw ApiException::notFound(sprintf('Device "%d" was not found.', $intId), 'cacti.net/Device', 'devices/' . $intId);
		}

		$body       = $request->jsonBody($ctx->settings->maxBodyBytes);
		$mask       = $ctx->resolveUpdateMask($request, $body, self::MUTABLE_FIELDS, 'devices');
		$serialized = self::serialize($existing);
		$currEtag   = is_string($serialized['etag']) ? $serialized['etag'] : '';
		$bodyEtag   = is_string($body['etag'] ?? null) ? $body['etag'] : null;

		Etag::assertMatch($currEtag, $request->header('If-Match'), $bodyEtag, 'devices/' . $intId);

		$patch = $mask->extractAllowedFields($body);

		if (array_key_exists('description', $patch) && (!is_string($patch['description']) || trim($patch['description']) === '')) {
			throw ApiException::invalidArgument(
				'description must not be empty.',
				[['field' => 'description', 'description' => 'Provide a non-empty description.']]
			);
		}

		if (array_key_exists('hostname', $patch) && (!is_string($patch['hostname']) || trim($patch['hostname']) === '')) {
			throw ApiException::invalidArgument(
				'hostname must not be empty.',
				[['field' => 'hostname', 'description' => 'Provide a non-empty hostname.']]
			);
		}

		$updated    = $ctx->repository->updateDevice($intId, $patch);
		$out        = self::serialize($updated ?? $existing);
		$updatedTag = is_string($out['etag']) ? $out['etag'] : '';

		return Response::ok($out, ['ETag' => $updatedTag]);
	}

	/**
	 * {@inheritdoc}
	 */
	public function delete(string $id, Request $request, ResourceContext $ctx, ?string $parentId = null): Response {
		$intId = ResourceName::parse('devices/' . $id)->intId();
		$this->enforceDeviceAccess($ctx, Principal::SCOPE_WRITE, $intId);

		$existing = $ctx->repository->getDevice($intId);

		if ($existing === null) {
			throw ApiException::notFound(sprintf('Device "%d" was not found.', $intId), 'cacti.net/Device', 'devices/' . $intId);
		}

		$serialized = self::serialize($existing);
		$currEtag   = is_string($serialized['etag']) ? $serialized['etag'] : '';
		Etag::assertMatch($currEtag, $request->header('If-Match'), null, 'devices/' . $intId);

		$ctx->repository->deleteDevice($intId);

		return Response::noContent();
	}

	/**
	 * {@inheritdoc}
	 */
	public function customMethod(string $id, string $method, Request $request, ResourceContext $ctx, ?string $parentId = null): Response {
		$intId = ResourceName::parse('devices/' . $id)->intId();
		$this->enforceDeviceAccess($ctx, Principal::SCOPE_WRITE, $intId);

		$existing = $ctx->repository->getDevice($intId);

		if ($existing === null) {
			throw ApiException::notFound(sprintf('Device "%d" was not found.', $intId), 'cacti.net/Device', 'devices/' . $intId);
		}

		if ($method === 'enable' || $method === 'disable') {
			$updated = $ctx->repository->setDeviceDisabled($intId, $method === 'disable');
			$out     = self::serialize($updated ?? $existing);
			$etag    = is_string($out['etag']) ? $out['etag'] : '';

			return Response::ok($out, ['ETag' => $etag]);
		}

		if ($method === 'reindex' || $method === 'poll') {
			$op = $ctx->operations->create(
				$ctx->principal->userId,
				'device.' . $method,
				ResourceName::build('devices', $intId),
				['deviceId' => $intId, 'action' => $method],
				$ctx->now
			);

			return Response::accepted($op);
		}

		throw ApiException::unimplemented(sprintf('Custom method ":%s" is not supported on devices.', $method));
	}

	/**
	 * Serialize a database host row into its canonical AIP device representation.
	 *
	 * Never exposes SNMP credentials (snmp_community, snmp_password, snmp_priv_passphrase).
	 *
	 * @param array<string, mixed> $row Raw repository host row.
	 * @return array<string, mixed>
	 */
	public static function serialize(array $row): array {
		$id       = is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
		$resource = [
			'name'             => ResourceName::build('devices', $id),
			'id'               => $id,
			'description'      => is_scalar($row['description'] ?? null) ? (string) $row['description'] : '',
			'hostname'         => is_scalar($row['hostname'] ?? null) ? (string) $row['hostname'] : '',
			'poller_id'        => is_numeric($row['poller_id'] ?? null) ? (int) $row['poller_id'] : 1,
			'site_id'          => is_numeric($row['site_id'] ?? null) ? (int) $row['site_id'] : 0,
			'host_template_id' => is_numeric($row['host_template_id'] ?? null) ? (int) $row['host_template_id'] : 0,
			'location'         => is_scalar($row['location'] ?? null) ? (string) $row['location'] : '',
			'notes'            => is_scalar($row['notes'] ?? null) ? (string) $row['notes'] : '',
			'external_id'      => is_scalar($row['external_id'] ?? null) ? (string) $row['external_id'] : '',
			'snmp_version'     => is_numeric($row['snmp_version'] ?? null) ? (int) $row['snmp_version'] : 2,
			'snmp_port'        => is_numeric($row['snmp_port'] ?? null) ? (int) $row['snmp_port'] : 161,
			'disabled'         => ($row['disabled'] ?? '') === 'on',
			'status'           => is_numeric($row['status'] ?? null) ? (int) $row['status'] : 0,
			'availability'     => is_numeric($row['availability'] ?? null) ? (float) $row['availability'] : 100.0,
		];
		$resource['etag'] = Etag::compute($resource);

		return $resource;
	}

	/**
	 * Evaluate combined token scope, host.php realm, and Cacti per-device object ACL.
	 *
	 * @param ResourceContext $ctx      Execution context.
	 * @param string          $scope    Required scope.
	 * @param int             $deviceId Target Cacti host ID.
	 * @return void
	 */
	private function enforceDeviceAccess(ResourceContext $ctx, string $scope, int $deviceId): void {
		$realmCheck = $ctx->principal->checkAccess($scope, 'host.php');
		$aclCheck   = $ctx->principal->isAdmin
			? AccessResult::allowed()
			: AccessResult::allowedIf(
				$ctx->repository->isDeviceAllowedForUser($ctx->principal->userId, $deviceId),
				sprintf('User does not have object access to device "%d".', $deviceId)
			);

		$ctx->enforce($realmCheck->andIf($aclCheck));
	}
}
