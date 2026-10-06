<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\WorkspaceIntegrations\Controller;

use OCA\WorkspaceIntegrations\Service\CardService;
use OCA\WorkspaceIntegrations\Service\ServiceException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IUserSession;

class CardController extends Controller {
	private const HEADERS = ['Cache-Control' => 'private, no-store','Referrer-Policy' => 'no-referrer','X-Content-Type-Options' => 'nosniff'];
	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $session,
		private CardService $cards,
	) {
		parent::__construct($appName, $request);
	}
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(string $cardId): JSONResponse {
		try {
			return new JSONResponse($this->cards->read($this->session->getUser()?->getUID() ?? '', $cardId), 200, self::HEADERS);
		} catch (ServiceException) {
			return new JSONResponse(['error' => 'Card not found.'], 404, self::HEADERS);
		} catch (\Throwable) {
			return new JSONResponse(['error' => 'Card temporarily unavailable.'], 503, self::HEADERS);
		}
	}
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function page(string $cardId): TemplateResponse|JSONResponse {
		try {
			$this->cards->read($this->session->getUser()?->getUID() ?? '', $cardId);
		} catch (ServiceException) {
			return new JSONResponse(['error' => 'Card not found.'], 404, self::HEADERS);
		} catch (\Throwable) {
			return new JSONResponse(['error' => 'Card temporarily unavailable.'], 503, self::HEADERS);
		}
		return new TemplateResponse('workspace_integrations', 'card', ['cardId' => $cardId], TemplateResponse::RENDER_AS_USER, 200, self::HEADERS);
	}
}
