<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\WorkspaceIntegrations\Service;

use OCA\WorkspaceIntegrations\Db\Repository;
use OCP\IUserManager;

class CardService {
	public function __construct(
		private Repository $repository,
		private TalkGateway $talk,
		private IUserManager $users,
	) {
	}
	public function read(string $uid, string $id): array {
		if (!preg_match('/^[a-f0-9]{32}$/D', $id) || !AccountAccess::allowed($this->users->get($uid))) {
			throw new ServiceException('Card not found.', 404);
		}
		$card = $this->repository->one('wi_cards', $id);
		$delivery = $this->repository->one('wi_deliveries', $id);
		// Pending and uncertain reservations are deliberately not readable. No recovery resend.
		if (!$card || !$delivery || $delivery['status'] !== 'delivered' || $delivery['message_id'] === '') {
			throw new ServiceException('Card not found.', 404);
		}
		$this->talk->assertCardVisible($uid, $card['token'], $delivery['message_id']);
		return json_decode($card['payload'], true, 512, JSON_THROW_ON_ERROR);
	}
}
