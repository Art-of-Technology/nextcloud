<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\WorkspaceIntegrations\AppInfo;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\{IBootstrap,IBootContext,IRegistrationContext};
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCA\WorkspaceIntegrations\Listener\PageListener;
class Application extends App implements IBootstrap {
 public const APP_ID='workspace_integrations';
 public function __construct(array $urlParams=[]) { parent::__construct(self::APP_ID,$urlParams); }
 public function register(IRegistrationContext $context): void { $context->registerEventListener(BeforeTemplateRenderedEvent::class,PageListener::class); }
 public function boot(IBootContext $context): void {}
}
