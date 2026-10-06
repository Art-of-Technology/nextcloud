<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\WorkspaceIntegrations\AppInfo;

use OCA\WorkspaceIntegrations\Listener\PageListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'workspace_integrations';
	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}
	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(BeforeTemplateRenderedEvent::class, PageListener::class);
		$context->registerReferenceProvider(\OCA\WorkspaceIntegrations\Reference\CardReferenceProvider::class);
		$context->registerEventListener(\OCP\Collaboration\Reference\RenderReferenceEvent::class, \OCA\WorkspaceIntegrations\Listener\RenderReferenceListener::class);
	}
	public function boot(IBootContext $context): void {
	}
}
