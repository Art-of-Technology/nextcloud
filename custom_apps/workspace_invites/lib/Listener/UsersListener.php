<?php
declare(strict_types=1);
namespace OCA\WorkspaceInvites\Listener;

use OCA\Settings\Events\BeforeTemplateRenderedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IGroupManager;
use OCP\IUserSession;
use OCP\Util;

class UsersListener implements IEventListener {
    public function __construct(private IUserSession $session, private IGroupManager $groups) {}
    public function handle(Event $event): void {
        $actor = $this->session->getUser();
        if (!$event instanceof BeforeTemplateRenderedEvent || $actor === null || !$this->groups->isAdmin($actor->getUID())) {
            return;
        }
        Util::addScript('workspace_invites', 'users');
        Util::addStyle('workspace_invites', 'users');
    }
}
