<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\WorkspaceOnboarding\Listener;

use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use OCP\Util;

class PageListener implements IEventListener {
	public function __construct(
		private IUserSession $session
	) {
	}
	public function handle(Event $event): void {
		if (!$event instanceof BeforeTemplateRenderedEvent || $this->session->getUser() === null
			|| $event->getResponse()->getRenderAs() !== TemplateResponse::RENDER_AS_USER) {
			return;
		}
		Util::addScript('workspace_onboarding', 'notifications');
		Util::addStyle('workspace_onboarding', 'notifications');
	}
}
