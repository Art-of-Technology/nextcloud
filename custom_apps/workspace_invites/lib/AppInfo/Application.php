<?php

declare(strict_types=1);
namespace OCA\WorkspaceInvites\AppInfo;

use OCA\Settings\Events\BeforeTemplateRenderedEvent;
use OCA\WorkspaceInvites\Listener\UsersListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
	public const APP_ID = 'workspace_invites';
	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}
	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(BeforeTemplateRenderedEvent::class, UsersListener::class);
	}
	public function boot(IBootContext $context): void {
	}
}
