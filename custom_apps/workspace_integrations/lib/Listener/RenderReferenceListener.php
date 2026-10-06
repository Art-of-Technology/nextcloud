<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\WorkspaceIntegrations\Listener;

use OCP\Collaboration\Reference\RenderReferenceEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/** @template-implements IEventListener<RenderReferenceEvent> */
class RenderReferenceListener implements IEventListener {
	public function handle(Event $event): void {
		if (!$event instanceof RenderReferenceEvent) {
			return;
		}
		Util::addScript('workspace_integrations', 'notification-card', 'spreed');
		Util::addScript('workspace_integrations', 'card-widget', 'spreed');
		Util::addStyle('workspace_integrations', 'notification-card');
	}
}
