<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\WorkspaceOnboarding\AppInfo;

use OCA\WorkspaceOnboarding\Listener\PageListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;

class Application extends App implements IBootstrap {
	public function __construct(array $urlParams = []) {
		parent::__construct('workspace_onboarding', $urlParams);
	}
	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(BeforeTemplateRenderedEvent::class, PageListener::class);
	}
	public function boot(IBootContext $context): void {
	}
}
