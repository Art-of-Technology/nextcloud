<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\WorkspaceBotMentions\Service;

use OCA\Talk\Manager;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Model\Bot;
use OCA\Talk\Service\BotService;
use OCA\Talk\Service\ParticipantService;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;

class BotSuggestions {
	public function __construct(
		private IUserSession $users,
		private IUserManager $userManager,
	) {
	}

	/** Only called after Talk has successfully authorized the original mention request. */
	public function append(array $original, string $token, string $search, int $limit): array {
		$limit = min(100, $limit);
		$user = $this->users->getUser();
		if ($user === null || !preg_match('/^[a-z0-9]{4,30}$/D', $token) || $limit < 1 || count($original) >= $limit) {
			return $original;
		}
		// Resolve Talk dependencies lazily: global middleware is constructed during bootstrap.
		$room = Server::get(Manager::class)->getRoomForUserByToken($token, $user->getUID());
		if ($room->isFederatedConversation()) {
			return $original;
		}
		$participant = Server::get(ParticipantService::class)->getParticipantByActor($room, Attendee::ACTOR_USERS, $user->getUID());
		if ($participant->getAttendee()->getActorType() !== Attendee::ACTOR_USERS || $participant->getAttendee()->getActorId() !== $user->getUID()) {
			return $original;
		}
		$service = Server::get(BotService::class);
		$candidates = [];
		$counts = [];
		foreach ($service->getBotsForToken($token, Bot::FEATURE_WEBHOOK | Bot::FEATURE_EVENT) as $bot) {
			$server = $bot->getBotServer();
			if (!$bot->isEnabled() || !$service->isAppForBotEnabled($server)) {
				continue;
			}
			$name = $server->getName();
			// Only plain aliases: excludes quotes, @, slash, markup, controls and reserved mentions.
			if (!preg_match('/^[\p{L}\p{N}][\p{L}\p{N} _.-]{0,63}$/uD', $name) || trim($name) !== $name || mb_strtolower($name) === 'all') {
				continue;
			}
			$key = mb_strtolower($name);
			$counts[$key] = ($counts[$key] ?? 0) + 1;
			$candidates[] = [$server, $name, $key];
		}
		$added = [];
		foreach ($candidates as [$server, $name, $key]) {
			if ($counts[$key] !== 1 || ($search !== '' && mb_stripos($name, $search) === false) || $this->userManager->get($name) !== null) {
				continue;
			}
			$collision = false;
			foreach ($original as $item) {
				if (mb_strtolower((string)($item['mentionId'] ?? $item['id'] ?? '')) === $key) {
					$collision = true;
					break;
				}
			}
			if ($collision) {
				continue;
			}
			$hash = $server->getUrlHash();
			if (!preg_match('/^[a-f0-9]{32,128}$/D', $hash)) {
				continue;
			}
			$added[] = ['id' => 'bot-' . $hash, 'label' => $name . ' (bot)', 'source' => 'bots', 'mentionId' => $name];
		}
		usort($added, static fn (array $a, array $b): int => strcasecmp($a['mentionId'], $b['mentionId']));
		// Preserve all human suggestions and their ordering; append within the requested limit.
		return array_merge($original, array_slice($added, 0, min(100, $limit) - count($original)));
	}
}
