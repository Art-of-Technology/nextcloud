<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\WorkspaceIntegrations\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IUserSession;

class PageController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $session
	) {
		parent::__construct($appName, $request);
	}
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse|JSONResponse {
		$user = $this->session->getUser();
		if (!\OCA\WorkspaceIntegrations\Service\AccountAccess::allowed($user)) {
			return new JSONResponse(['error' => 'Access denied.'], 403);
		}
		return new TemplateResponse('workspace_integrations', 'index', [], TemplateResponse::RENDER_AS_USER, 200, ['Cache-Control' => 'private, no-store']);
	}
}
