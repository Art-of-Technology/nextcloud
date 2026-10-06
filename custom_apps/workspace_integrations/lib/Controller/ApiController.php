<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\WorkspaceIntegrations\Controller;

use OCA\WorkspaceIntegrations\Service\IntegrationService;
use OCA\WorkspaceIntegrations\Service\ServiceException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

class ApiController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $session,
		private IURLGenerator $urls,
		private IntegrationService $service,
	) {
		parent::__construct($appName, $request);
	}
	private function actor(): string {
		return $this->session->getUser()?->getUID() ?? '';
	}
	private function reply(callable $action): JSONResponse {
		try {
			return new JSONResponse($action(), 200, ['Cache-Control' => 'private, no-store','Referrer-Policy' => 'no-referrer']);
		} catch (ServiceException $e) {
			return new JSONResponse(['error' => $e->getMessage()], $e->status, ['Cache-Control' => 'private, no-store']);
		} catch (\Throwable $e) {
			return new JSONResponse(['error' => 'The integration request could not be completed.'], 500, ['Cache-Control' => 'private, no-store']);
		}
	}
	private function hookUrl(string $connectionId): string {
		return $this->urls->linkToRouteAbsolute('workspace_integrations.api.hook', ['connectionId' => $connectionId]);
	}
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function capabilities(): JSONResponse {
		$u = $this->session->getUser();
		$ok = \OCA\WorkspaceIntegrations\Service\AccountAccess::allowed($u);
		return new JSONResponse($ok ? ['enabled' => true] : ['error' => 'Access denied.'], $ok ? 200 : 403, ['Cache-Control' => 'private, no-store']);
	}
	#[NoAdminRequired]
	public function channelConnections(string $token): JSONResponse {
		return $this->reply(fn () => $this->service->channelConnections($this->actor(), $token));
	}
	#[NoAdminRequired]
	public function disconnectChannel(string $token, string $connectionId): JSONResponse {
		return $this->reply(function () use ($token, $connectionId) {
			$this->service->disconnectChannel($this->actor(), $token, $connectionId);
			return ['removed' => true];
		});
	}
	#[NoAdminRequired]
	public function index(string $scope = 'mine'): JSONResponse {
		return $this->reply(fn () => $this->service->list($this->actor(), $scope));
	}
	#[NoAdminRequired]
	#[UserRateLimit(limit:30, period:60)]
	public function create(string $name = '', string $description = ''): JSONResponse {
		return $this->reply(fn () => $this->service->create($this->actor(), $name, $description));
	}
	#[NoAdminRequired]
	public function update(string $id): JSONResponse {
		return $this->reply(fn () => $this->service->update($this->actor(), $id, array_intersect_key($this->request->getParams(), array_flip(['name','description','enabled','ownerUid']))));
	}
	#[NoAdminRequired]
	public function channels(): JSONResponse {
		return $this->reply(fn () => $this->service->channels($this->actor()));
	}
	#[NoAdminRequired]
	public function connect(string $id, string $token = ''): JSONResponse {
		return $this->reply(function () use ($id, $token) {
			$r = $this->service->connect($this->actor(), $id, $token);
			$r['webhookUrl'] = $this->hookUrl($r['connection']['id']);
			return $r;
		});
	}
	#[NoAdminRequired]
	public function disconnect(string $id, string $connectionId): JSONResponse {
		return $this->reply(function () use ($id, $connectionId) {
			$this->service->disconnect($this->actor(), $id, $connectionId);
			return ['removed' => true];
		});
	}
	#[NoAdminRequired]
	public function rotate(string $id, string $connectionId): JSONResponse {
		return $this->reply(function () use ($id, $connectionId) {
			$r = $this->service->rotate($this->actor(), $id, $connectionId);
			$r['webhookUrl'] = $this->hookUrl($connectionId);
			return $r;
		});
	}
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit:120, period:60)]
	#[UserRateLimit(limit:120, period:60)]
	public function hook(string $connectionId): JSONResponse {
		return $this->reply(function () use ($connectionId) {
			$authorization = $this->request->getHeader('Authorization');
			if (!preg_match('/^Bearer ([A-Za-z0-9_-]{32,256})$/D', $authorization, $m)) {
				return $this->service->deliver($connectionId, '', []);
			}
			if (!str_starts_with(strtolower($this->request->getHeader('Content-Type')), 'application/json')) {
				throw new ServiceException('JSON content type required.', 415);
			}
			$payload = $this->request->getParams();
			unset($payload['connectionId'], $payload['_route']);
			return $this->service->deliver($connectionId, $m[1], $payload);
		});
	}
}
