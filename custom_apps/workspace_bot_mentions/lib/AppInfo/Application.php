<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\WorkspaceBotMentions\AppInfo;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\{IBootstrap, IBootContext, IRegistrationContext};
use OCA\WorkspaceBotMentions\Middleware\MentionMiddleware;
class Application extends App implements IBootstrap {
    public function __construct(array $urlParams = []) { parent::__construct('workspace_bot_mentions', $urlParams); }
    public function register(IRegistrationContext $context): void { $context->registerMiddleware(MentionMiddleware::class, true); }
    public function boot(IBootContext $context): void {}
}
