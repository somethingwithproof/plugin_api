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

namespace Somethingwithproof\Api\Http;

use Somethingwithproof\Api\Aip\ApiException;
use Somethingwithproof\Api\Aip\ErrorStatus;
use Somethingwithproof\Api\Aip\FilterParser;
use Somethingwithproof\Api\Aip\IdempotencyStore;
use Somethingwithproof\Api\Aip\OperationManager;
use Somethingwithproof\Api\Aip\OrderByParser;
use Somethingwithproof\Api\Aip\ResourceName;
use Somethingwithproof\Api\Aip\SqlFilterCompiler;
use Somethingwithproof\Api\Auth\Principal;
use Somethingwithproof\Api\Auth\RateLimiter;
use Somethingwithproof\Api\Auth\TokenService;
use Somethingwithproof\Api\Extension\ExtensionResourceDescriptor;
use Somethingwithproof\Api\Extension\ResourceRegistry;
use Somethingwithproof\Api\Log;
use Somethingwithproof\Api\OpenApi\OpenApiGenerator;
use Somethingwithproof\Api\Pages;
use Somethingwithproof\Api\Repository\ApiRepositoryInterface;
use Somethingwithproof\Api\Resource\DataSourcesResource;
use Somethingwithproof\Api\Resource\DevicesResource;
use Somethingwithproof\Api\Resource\GraphsResource;
use Somethingwithproof\Api\Resource\OperationsResource;
use Somethingwithproof\Api\Resource\PluginsResource;
use Somethingwithproof\Api\Resource\PollersResource;
use Somethingwithproof\Api\Resource\ResourceContext;
use Somethingwithproof\Api\Resource\ResourceHandlerInterface;
use Somethingwithproof\Api\Resource\SitesResource;
use Somethingwithproof\Api\Resource\TokensResource;
use Somethingwithproof\Api\Resource\TreesResource;
use Somethingwithproof\Api\Settings;

/**
 * Front-controller HTTP router implementing Google AIP routing, authentication,
 * rate limiting, idempotency replay, CORS allowlisting, content negotiation,
 * and decoupled sibling plugin resource dispatch.
 */
final class Router {
	/**
	 * Core resource collection slugs mapped to lazily instantiated handler classes.
	 *
	 * @var array<string, class-string<ResourceHandlerInterface>>
	 */
	public const CORE_COLLECTIONS = [
		'sites'       => SitesResource::class,
		'pollers'     => PollersResource::class,
		'devices'     => DevicesResource::class,
		'graphs'      => GraphsResource::class,
		'dataSources' => DataSourcesResource::class,
		'trees'       => TreesResource::class,
		'plugins'     => PluginsResource::class,
		'operations'  => OperationsResource::class,
		'tokens'      => TokensResource::class,
	];

	/**
	 * Lazily instantiated core resource handlers keyed by collection slug.
	 *
	 * @var array<string, ResourceHandlerInterface>
	 */
	private array $handlers = [];

	/**
	 * Application Password authentication service.
	 *
	 * @var TokenService
	 */
	private readonly TokenService $tokenService;

	/**
	 * Fixed-window rate limiter.
	 *
	 * @var RateLimiter
	 */
	private readonly RateLimiter $rateLimiter;

	/**
	 * AIP-155 idempotency store.
	 *
	 * @var IdempotencyStore
	 */
	private readonly IdempotencyStore $idempotency;

	/**
	 * AIP-151 Long-Running Operations manager.
	 *
	 * @var OperationManager
	 */
	private readonly OperationManager $operations;

	/**
	 * Optional callable validating CSRF tokens for session-authenticated writes.
	 *
	 * @var (callable(string): bool)|null
	 */
	private $csrfValidator;

	/**
	 * Construct the API Router.
	 *
	 * @param ApiRepositoryInterface        $repository        Persistence adapter.
	 * @param Settings                      $settings          Plugin settings.
	 * @param ResourceRegistry              $extensionRegistry Discovered sibling plugin resources.
	 * @param string                        $pageTokenSecret   Server-side HMAC secret for AIP-158 cursors.
	 * @param string                        $apiVersion        Plugin version string.
	 * @param (callable(string): bool)|null $csrfValidator     Optional CSRF validator for session-authenticated writes.
	 */
	public function __construct(
		private readonly ApiRepositoryInterface $repository,
		private readonly Settings $settings,
		private readonly ResourceRegistry $extensionRegistry,
		private readonly string $pageTokenSecret,
		private readonly string $apiVersion = '0.1.0',
		?callable $csrfValidator = null,
	) {
		$this->tokenService  = new TokenService($repository);
		$this->rateLimiter   = new RateLimiter($repository);
		$this->idempotency   = new IdempotencyStore($repository);
		$this->operations    = new OperationManager($repository);
		$this->csrfValidator = $csrfValidator;
	}

	/**
	 * Return a lazily instantiated core resource handler for a known collection slug.
	 *
	 * @param string $collection Core collection identifier.
	 * @return ResourceHandlerInterface
	 */
	private function handlerFor(string $collection): ResourceHandlerInterface {
		if (!isset($this->handlers[$collection])) {
			$class                       = self::CORE_COLLECTIONS[$collection];
			$this->handlers[$collection] = new $class();
		}

		return $this->handlers[$collection];
	}

	/**
	 * Dispatch an incoming HTTP Request and return a canonical Response.
	 *
	 * @param Request  $request Incoming HTTP request.
	 * @param int|null $now     Optional Unix timestamp override for deterministic tests.
	 * @return Response
	 */
	public function handle(Request $request, ?int $now = null): Response {
		$timestamp = $now ?? time();
		$origin    = $request->header('Origin');

		try {
			if ($request->method === 'OPTIONS') {
				return Response::noContent()->withCors($origin, $this->settings);
			}

			if (!$this->settings->enabled) {
				throw ApiException::unavailable('The Cacti REST API is currently disabled by administrator settings.');
			}

			if ($this->settings->requireTls && !$request->isHttps) {
				throw ApiException::failedPrecondition('HTTPS is required for all API requests.');
			}

			$principal        = $this->authenticate($request, $timestamp);
			$rateLimitHeaders = $this->rateLimiter->consume(
				$principal->rateLimitSubject(),
				$this->settings->rateLimitPerMin,
				$timestamp
			);

			$response = $this->dispatchAuthenticated($request, $principal, $timestamp);
			$response = $response->withHeaders($rateLimitHeaders);

			if ($this->settings->enableYamlNegotiation && $request->prefersYaml()) {
				$response = $response->withFormat('yaml');
			}

			return $response->withCors($origin, $this->settings);
		} catch (ApiException $e) {
			$resp = Response::fromError($e->errorStatus, $e->headers);

			if ($this->settings->enableYamlNegotiation && $request->prefersYaml()) {
				$resp = $resp->withFormat('yaml');
			}

			return $resp->withCors($origin, $this->settings);
		} catch (\Throwable $e) {
			Log::error('Unhandled API exception: ' . $e::class . ': ' . $e->getMessage());
			$resp = Response::fromError(new ErrorStatus(ErrorStatus::INTERNAL, 'Internal server error.'));

			return $resp->withCors($origin, $this->settings);
		}
	}

	/**
	 * Authenticate the caller via Application Password Bearer token or Cacti session.
	 *
	 * Applies per-IP rate limiting to unauthenticated or failed token attempts.
	 *
	 * @param Request $request Incoming request.
	 * @param int     $now     Current Unix timestamp.
	 * @return Principal
	 * @throws ApiException On authentication failure or CSRF failure.
	 */
	private function authenticate(Request $request, int $now): Principal {
		$bearer = $request->bearerToken();

		if ($bearer !== null) {
			try {
				return $this->tokenService->authenticate($bearer, $request->clientIp, $now);
			} catch (ApiException $e) {
				$this->rateLimiter->consume('ip:' . $request->clientIp, $this->settings->rateLimitPerMin, $now);

				throw $e;
			}
		}

		if ($request->sessionUserId !== null && $request->sessionUserId > 0) {
			$user = $this->repository->getUserById($request->sessionUserId);

			if ($user === null || !$user['enabled'] || $user['locked']) {
				throw ApiException::unauthenticated('Session user account is disabled or locked.');
			}

			if (in_array($request->method, ['POST', 'PATCH', 'DELETE'], true)) {
				$csrf = $request->header('X-CSRF-Token') ?? '';

				if ($this->csrfValidator === null || !($this->csrfValidator)($csrf)) {
					throw ApiException::permissionDenied('Missing or invalid X-CSRF-Token for session-authenticated write request.');
				}
			}

			$realms  = $this->repository->getUserRealms($user['id']);
			$isAdmin = in_array(Pages::ADMIN, $realms, true);
			$scopes  = $isAdmin
				? [Principal::SCOPE_READ, Principal::SCOPE_WRITE, Principal::SCOPE_ADMIN]
				: [Principal::SCOPE_READ, Principal::SCOPE_WRITE];

			return new Principal(
				userId: $user['id'],
				username: $user['username'],
				authMethod: 'session',
				tokenId: null,
				scopes: $scopes,
				realmFiles: $realms,
				isAdmin: $isAdmin,
			);
		}

		$this->rateLimiter->consume('ip:' . $request->clientIp, $this->settings->rateLimitPerMin, $now);

		throw ApiException::unauthenticated('Missing Authorization Bearer token.');
	}

	/**
	 * Route an authenticated request including AIP-155 idempotency replay protection.
	 *
	 * @param Request   $request   Incoming request.
	 * @param Principal $principal Authenticated caller.
	 * @param int       $now       Current Unix timestamp.
	 * @return Response
	 */
	private function dispatchAuthenticated(Request $request, Principal $principal, int $now): Response {
		$path = '/' . ltrim($request->path, '/');

		if ($path === '/v1/openapi.json') {
			if ($request->method !== 'GET') {
				throw ApiException::unimplemented('Only GET is supported on /v1/openapi.json.');
			}

			if (!$principal->hasScope(Principal::SCOPE_READ)) {
				throw ApiException::permissionDenied('Token lacks read scope.');
			}

			return Response::ok(OpenApiGenerator::generate($this->extensionRegistry, $this->apiVersion));
		}

		$isMutation = in_array($request->method, ['POST', 'PATCH', 'DELETE'], true);
		$rawReqId   = $isMutation ? ($request->queryParam('request_id') ?? $request->header('Idempotency-Key')) : null;
		$reqId      = null;
		$reqHash    = null;

		if ($rawReqId !== null && trim($rawReqId) !== '') {
			$reqId   = IdempotencyStore::validateRequestId($rawReqId);
			$body    = $request->jsonBody($this->settings->maxBodyBytes);
			$reqHash = IdempotencyStore::fingerprint($principal->userId, $request->method, $path, $body);
			$cached  = $this->idempotency->lookup($reqId, $principal->userId, $request->method, $path, $reqHash, $now);

			if ($cached !== null) {
				return new Response(
					$cached['status_code'],
					$cached['body'],
					['X-Idempotent-Replay' => 'true']
				);
			}
		}

		$ctx      = new ResourceContext(
			$this->repository,
			$principal,
			$this->settings,
			$this->operations,
			$this->tokenService,
			$this->pageTokenSecret,
			$now
		);
		$response = $this->routePath($path, $request, $ctx);

		if ($reqId !== null && $reqHash !== null && $response->statusCode >= 200 && $response->statusCode < 300) {
			$this->idempotency->record(
				$reqId,
				$principal->userId,
				$request->method,
				$path,
				$reqHash,
				$response->statusCode,
				$response->body ?? [],
				$now
			);
		}

		return $response;
	}

	/**
	 * Match a /v1/... resource path against core and extension collections.
	 *
	 * @param string          $path    Normalized request path.
	 * @param Request         $request Incoming request.
	 * @param ResourceContext $ctx     Shared execution context.
	 * @return Response
	 */
	private function routePath(string $path, Request $request, ResourceContext $ctx): Response {
		if (!str_starts_with($path, '/v1/')) {
			throw ApiException::notFound(sprintf('API endpoint "%s" was not found.', $path));
		}

		$relative = trim(substr($path, 4), '/');

		if ($relative === '') {
			throw ApiException::notFound('API resource path is empty.');
		}

		$customMethod = null;
		$colonPos     = strrpos($relative, ':');

		if ($colonPos !== false) {
			$customMethod = substr($relative, $colonPos + 1);
			$relative     = substr($relative, 0, $colonPos);

			if (preg_match('/^[a-z][A-Za-z0-9]{1,31}$/', $customMethod) !== 1) {
				throw ApiException::invalidArgument(sprintf('Invalid custom method ":%s".', $customMethod));
			}
		}

		$segments = explode('/', $relative, 6);
		$count    = count($segments);

		// Nested device graphs: /v1/devices/{device}/graphs[/{graph}[:render]]
		if ($count >= 3 && $segments[0] === 'devices' && $segments[2] === 'graphs') {
			$deviceId = $segments[1];
			$handler  = $this->handlerFor('graphs');

			if ($count === 3) {
				if ($customMethod !== null || $request->method !== 'GET') {
					throw ApiException::unimplemented('Only GET is supported on /v1/devices/{device}/graphs.');
				}

				return $handler->list($request, $ctx, $deviceId);
			}

			if ($count === 4) {
				$graphId = $segments[3];

				if ($customMethod !== null) {
					if ($request->method !== 'POST') {
						throw ApiException::unimplemented('AIP-136 custom methods require HTTP POST.');
					}

					return $handler->customMethod($graphId, $customMethod, $request, $ctx, $deviceId);
				}

				if ($request->method !== 'GET') {
					throw ApiException::unimplemented('Only GET is supported on /v1/devices/{device}/graphs/{graph}.');
				}

				return $handler->get($graphId, $request, $ctx, $deviceId);
			}
		}

		$collection = $segments[0];

		if (isset(self::CORE_COLLECTIONS[$collection])) {
			return $this->dispatchHandler($this->handlerFor($collection), $segments, $customMethod, $request, $ctx);
		}

		$extension = $this->extensionRegistry->get($collection);

		if ($extension !== null) {
			return $this->dispatchExtension($extension, $segments, $customMethod, $request, $ctx);
		}

		throw ApiException::notFound(sprintf('Collection "%s" was not found.', $collection));
	}

	/**
	 * Dispatch a matched core ResourceHandlerInterface.
	 *
	 * @param ResourceHandlerInterface $handler      Core resource handler.
	 * @param list<string>             $segments     Path segments under /v1/.
	 * @param string|null              $customMethod Optional AIP-136 custom method name.
	 * @param Request                  $request      Incoming request.
	 * @param ResourceContext          $ctx          Execution context.
	 * @return Response
	 */
	private function dispatchHandler(
		ResourceHandlerInterface $handler,
		array $segments,
		?string $customMethod,
		Request $request,
		ResourceContext $ctx,
	): Response {
		$count = count($segments);

		if ($count === 1) {
			if ($customMethod !== null) {
				throw ApiException::unimplemented(sprintf('Collection-level custom method ":%s" is not supported.', $customMethod));
			}

			return match ($request->method) {
				'GET'   => $handler->list($request, $ctx),
				'POST'  => $handler->create($request, $ctx),
				default => throw ApiException::unimplemented(sprintf('Method %s is not allowed on /v1/%s.', $request->method, $segments[0])),
			};
		}

		if ($count === 2) {
			$id = $segments[1];

			if ($customMethod !== null) {
				if ($request->method !== 'POST') {
					throw ApiException::unimplemented('AIP-136 custom methods require HTTP POST.');
				}

				return $handler->customMethod($id, $customMethod, $request, $ctx);
			}

			return match ($request->method) {
				'GET'    => $handler->get($id, $request, $ctx),
				'PATCH'  => $handler->update($id, $request, $ctx),
				'DELETE' => $handler->delete($id, $request, $ctx),
				default  => throw ApiException::unimplemented(sprintf('Method %s is not allowed on /v1/%s/{id}.', $request->method, $segments[0])),
			};
		}

		throw ApiException::notFound('Resource path depth is not supported.');
	}

	/**
	 * Dispatch a dynamically registered sibling plugin extension collection.
	 *
	 * Enforces Cacti realm permissions, AIP-160 filtering, AIP-132 ordering,
	 * AIP-158 pagination, and AIP-161 update_mask around plain callable handlers.
	 *
	 * @param ExtensionResourceDescriptor $descriptor   Validated extension descriptor.
	 * @param list<string>                $segments     Path segments under /v1/.
	 * @param string|null                 $customMethod Optional custom method.
	 * @param Request                     $request      Incoming request.
	 * @param ResourceContext             $ctx          Execution context.
	 * @return Response
	 */
	private function dispatchExtension(
		ExtensionResourceDescriptor $descriptor,
		array $segments,
		?string $customMethod,
		Request $request,
		ResourceContext $ctx,
	): Response {
		if ($customMethod !== null) {
			throw ApiException::unimplemented(sprintf('Custom method ":%s" is not supported on extension collection "%s".', $customMethod, $descriptor->collection));
		}

		$count = count($segments);

		if ($count === 1 && $request->method === 'GET') {
			$ctx->enforce($ctx->principal->checkAccess(Principal::SCOPE_READ, $descriptor->realmRead));

			if (!isset($descriptor->handlers['list'])) {
				throw ApiException::unimplemented(sprintf('List is not implemented for "%s".', $descriptor->collection));
			}

			$rawItems = ($descriptor->handlers['list'])(['user_id' => $ctx->principal->userId, 'query' => $request->query]);

			if (!is_array($rawItems)) {
				throw ApiException::internal('Extension list handler returned invalid data.');
			}

			$items = [];

			foreach ($rawItems as $item) {
				if (is_array($item)) {
					$assoc = [];

					foreach ($item as $k => $v) {
						$assoc[(string) $k] = $v;
					}
					$items[] = $assoc;
				}
			}

			$filter = FilterParser::parse($request->queryParam('filter') ?? '');

			if ($filter !== null) {
				$items = SqlFilterCompiler::filterRecords($items, $filter, $descriptor->filterFields);
			}

			$orderMap = [];

			foreach ($descriptor->orderFields as $f) {
				$orderMap[$f] = $f;
			}

			$orderBy = OrderByParser::parse($request->queryParam('order_by') ?? '', $orderMap);
			$items   = OrderByParser::sortRecords($items, $orderBy, 'id');

			return Response::ok($ctx->paginate($descriptor->collection, $items, $request, array_values(array_unique(array_merge(['name', 'id'], $descriptor->filterFields, $descriptor->orderFields)))));
		}

		if ($count === 1 && $request->method === 'POST') {
			$ctx->enforce($ctx->principal->checkAccess(Principal::SCOPE_WRITE, $descriptor->realmWrite));

			if (!isset($descriptor->handlers['create'])) {
				throw ApiException::unimplemented(sprintf('Create is not implemented for "%s".', $descriptor->collection));
			}

			$body    = $request->jsonBody($ctx->settings->maxBodyBytes);
			$created = ($descriptor->handlers['create'])($body, ['user_id' => $ctx->principal->userId]);

			if (!is_array($created)) {
				throw ApiException::internal('Extension create handler returned invalid data.');
			}

			$out = [];

			foreach ($created as $k => $v) {
				$out[(string) $k] = $v;
			}

			return Response::created($out);
		}

		if ($count === 2) {
			$id = ResourceName::parse($descriptor->collection . '/' . $segments[1])->id;

			if ($request->method === 'GET') {
				$ctx->enforce($ctx->principal->checkAccess(Principal::SCOPE_READ, $descriptor->realmRead));

				if (!isset($descriptor->handlers['get'])) {
					throw ApiException::unimplemented(sprintf('Get is not implemented for "%s".', $descriptor->collection));
				}

				$item = ($descriptor->handlers['get'])($id, ['user_id' => $ctx->principal->userId]);

				if (!is_array($item)) {
					throw ApiException::notFound(sprintf('Resource "%s/%s" was not found.', $descriptor->collection, $id));
				}

				$out = [];

				foreach ($item as $k => $v) {
					$out[(string) $k] = $v;
				}

				return Response::ok($out);
			}

			if ($request->method === 'PATCH') {
				$ctx->enforce($ctx->principal->checkAccess(Principal::SCOPE_WRITE, $descriptor->realmWrite));

				if (!isset($descriptor->handlers['update'])) {
					throw ApiException::unimplemented(sprintf('Update is not implemented for "%s".', $descriptor->collection));
				}

				$body    = $request->jsonBody($ctx->settings->maxBodyBytes);
				$mask    = $ctx->resolveUpdateMask($request, $body, $descriptor->mutableFields, $descriptor->collection);
				$allowed = $mask->extractAllowedFields($body);
				$updated = ($descriptor->handlers['update'])($id, $allowed, ['user_id' => $ctx->principal->userId]);

				if (!is_array($updated)) {
					throw ApiException::notFound(sprintf('Resource "%s/%s" was not found.', $descriptor->collection, $id));
				}

				$out = [];

				foreach ($updated as $k => $v) {
					$out[(string) $k] = $v;
				}

				return Response::ok($out);
			}

			if ($request->method === 'DELETE') {
				$ctx->enforce($ctx->principal->checkAccess(Principal::SCOPE_WRITE, $descriptor->realmWrite));

				if (!isset($descriptor->handlers['delete'])) {
					throw ApiException::unimplemented(sprintf('Delete is not implemented for "%s".', $descriptor->collection));
				}

				$deleted = ($descriptor->handlers['delete'])($id, ['user_id' => $ctx->principal->userId]);

				if ($deleted === false) {
					throw ApiException::notFound(sprintf('Resource "%s/%s" was not found.', $descriptor->collection, $id));
				}

				return Response::noContent();
			}
		}

		throw ApiException::notFound('Extension resource path is not supported.');
	}
}
