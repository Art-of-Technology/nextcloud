<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\WorkspaceIntegrations\Service;

use OCA\Talk\Chat\ChatManager;
use OCA\Talk\Manager;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Model\Bot;
use OCA\Talk\Model\BotConversation;
use OCA\Talk\Model\BotConversationMapper;
use OCA\Talk\Model\BotServer;
use OCA\Talk\Model\BotServerMapper;
use OCA\Talk\Room;
use OCA\Talk\Service\ParticipantService;
use OCP\Server;

/** Talk services are resolved lazily, after app boot. No HTTP callbacks are registered. */
class TalkGateway {
	public function moderatedRoom(string $uid, string $token): Room {
		try {
			$room = Server::get(Manager::class)->getRoomByToken($token);
			$participant = Server::get(ParticipantService::class)->getParticipant($room, $uid);
			if (!$participant->hasModeratorPermissions(false) || $participant->getAttendee()->getActorType() !== Attendee::ACTOR_USERS
				|| $room->isFederatedConversation() || !in_array($room->getType(), [Room::TYPE_GROUP, Room::TYPE_PUBLIC], true)) {
				throw new \RuntimeException();
			}
			return $room;
		} catch (\Throwable) {
			throw new ServiceException('Conversation unavailable or moderator permission required.', 403);
		}
	}
	public function channels(string $uid): array {
		$out = [];
		foreach (Server::get(Manager::class)->getRoomsForUser($uid) as $room) {
			try {
				$this->moderatedRoom($uid, $room->getToken());
			} catch (ServiceException) {
				continue;
			}
			$out[] = ['token' => $room->getToken(), 'name' => $room->getName()];
		}
		return $out;
	}
	public function create(string $id, string $name, string $description): int {
		$bot = new BotServer();
		$url = Bot::URL_RESPONSE_ONLY_PREFIX . 'workspace_integrations/' . $id;
		$bot->setUrl($url);
		$bot->setUrlHash(sha1($url));
		$bot->setName($name);
		$bot->setDescription($description);
		$bot->setSecret(bin2hex(random_bytes(32)));
		$bot->setState(Bot::STATE_NO_SETUP);
		$bot->setFeatures(Bot::FEATURE_RESPONSE);
		return (int)Server::get(BotServerMapper::class)->insert($bot)->getId();
	}
	private function managed(array $integration): BotServer {
		try {
			$bot = Server::get(BotServerMapper::class)->findById((int)$integration['bot_id']);
		} catch (\Throwable) {
			throw new ServiceException('Native bot is unavailable.', 409);
		}
		if ($bot->getUrl() !== Bot::URL_RESPONSE_ONLY_PREFIX . 'workspace_integrations/' . $integration['id']) {
			throw new ServiceException('Native bot is unavailable.', 409);
		}
		return $bot;
	}
	public function update(array $integration, bool $explicitEnable = false): void {
		$bot = $this->managed($integration);
		$bot->setName($integration['name']);
		$bot->setDescription($integration['description']);
		// Renaming must never undo an administrator's native bot disable.
		if ((bool)($integration['admin_suspended'] ?? 0)) {
			$bot->setState(Bot::STATE_DISABLED);
		} elseif ($explicitEnable) {
			$bot->setState(Bot::STATE_NO_SETUP);
		}
		Server::get(BotServerMapper::class)->update($bot);
	}
	public function assertCanEnable(array $integration, bool $isAdmin): void {
		if (!$isAdmin && $this->managed($integration)->getState() === Bot::STATE_DISABLED) {
			throw new ServiceException('An administrator must restore this disabled native bot.', 403);
		}
	}
	public function connect(array $integration, string $token): void {
		$this->managed($integration);
		$mapper = Server::get(BotConversationMapper::class);
		foreach ($mapper->findForToken($token) as $entry) {
			if ($entry->getBotId() === (int)$integration['bot_id']) {
				$entry->setState(Bot::STATE_ENABLED);
				$mapper->update($entry);
				return;
			}
		}
		$entry = new BotConversation();
		$entry->setBotId((int)$integration['bot_id']);
		$entry->setToken($token);
		$entry->setState(Bot::STATE_ENABLED);
		$mapper->insert($entry);
	}
	public function disconnect(array $integration, string $token): void {
		Server::get(BotConversationMapper::class)->deleteByBotIdAndTokens((int)$integration['bot_id'], [$token]);
	}
	public function available(array $integration, string $token): bool {
		try {
			$bot = $this->managed($integration);
			if ($bot->getState() !== Bot::STATE_NO_SETUP || $bot->getFeatures() !== Bot::FEATURE_RESPONSE) {
				return false;
			}
			$room = Server::get(Manager::class)->getRoomByToken($token);
			if ($room->getReadOnly() !== Room::READ_WRITE || $room->isFederatedConversation()
				|| !in_array($room->getType(), [Room::TYPE_GROUP, Room::TYPE_PUBLIC], true)) {
				return false;
			}
			foreach (Server::get(BotConversationMapper::class)->findForToken($token) as $entry) {
				if ($entry->getBotId() === (int)$integration['bot_id'] && $entry->getState() === Bot::STATE_ENABLED) {
					return true;
				}
			}
		} catch (\Throwable) { /* Removed room/bot fails closed. */
		}
		return false;
	}
	public function roomName(string $token, string $uid): string {
		try {
			$room = Server::get(Manager::class)->getRoomByToken($token);
			Server::get(ParticipantService::class)->getParticipant($room, $uid);
			return $room->getName();
		} catch (\Throwable) {
			return '(unavailable)';
		}
	}
	public function send(array $integration, string $token, string $text, string $reference): string {
		if (!$this->available($integration, $token)) {
			throw new ServiceException('Delivery is disabled.', 409);
		}
		$bot = $this->managed($integration);
		$room = Server::get(Manager::class)->getRoomByToken($token);
		$comment = Server::get(ChatManager::class)->sendMessage($room, null, Attendee::ACTOR_BOTS,
			Attendee::ACTOR_BOT_PREFIX . $bot->getUrlHash(), $text, new \DateTime('now', new \DateTimeZone('UTC')),
			null, $reference, false, rateLimitGuestMentions: false);
		return (string)$comment->getId();
	}
}
