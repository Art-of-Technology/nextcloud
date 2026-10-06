<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\WorkspaceIntegrations\Service;

use OCA\WorkspaceIntegrations\Db\Repository;
use OCP\IGroupManager;
use OCP\IUserManager;

/** Owns authorization: controller-supplied identifiers never grant authority. */
class IntegrationService {
	public function __construct(
		private Repository $repository,
		private TalkGateway $talk,
		private IUserManager $users,
		private IGroupManager $groups,
	) {
	}
	private function user(string $uid): void {
		$user = $this->users->get($uid);
		if (!AccountAccess::allowed($user)) {
			throw new ServiceException('An enabled registered account is required.', 403);
		}
	}
	private function owned(string $uid, string $id, bool $lock = false): array {
		$this->user($uid);
		$row = $this->repository->one('wi_integrations', $id, $lock);
		if (!$row || ($row['owner_uid'] !== $uid && !$this->groups->isAdmin($uid))) {
			throw new ServiceException('Integration not found.', 404);
		}
		return $row;
	}
	private function audit(string $uid, string $id, string $action): void {
		$this->repository->insert('wi_audit', ['id' => bin2hex(random_bytes(16)), 'actor_uid' => $uid,
			'integration_id' => $id, 'action' => $action, 'created_at' => time()]);
	}
	private function ownershipQuota(string $uid): void {
		// One tiny mutex row serializes create/transfer quota checks across workers.
		if (!$this->repository->one('wi_locks', 'ownership_quota', true)) {
			throw new ServiceException('Integration storage is not initialized.', 503);
		}
		if (count($this->repository->rows('wi_integrations', ['owner_uid' => $uid])) >= 50) {
			throw new ServiceException('Integration limit reached.', 429);
		}
	}
	private function details(string $name, string $description): array {
		$name = trim($name);
		if ($name === '' || strlen($name) > 64 || preg_match('/[\x00-\x1f\x7f"@]/u', $name) || !mb_check_encoding($name, 'UTF-8')) {
			throw new ServiceException('Name must be 1–64 bytes without control characters, quotes or @.');
		}
		if (strlen($description) > 4000 || !mb_check_encoding($description, 'UTF-8')) {
			throw new ServiceException('Description is too long or invalid.');
		}
		return [$name, $description];
	}
	private function viewConnection(array $row, array $integration, string $uid): array {
		return ['id' => $row['id'], 'token' => $row['token'], 'name' => $this->talk->roomName($row['token'], $uid),
			'enabled' => (bool)$row['enabled'] && (bool)$integration['enabled'] && $this->talk->available($integration, $row['token']),
			'lastStatus' => $row['last_status'], 'lastAt' => (int)$row['last_at']];
	}
	private function view(array $row, string $uid): array {
		$connections = array_map(fn ($connection) => $this->viewConnection($connection, $row, $uid), $this->repository->rows('wi_connections', ['integration_id' => $row['id']]));
		return ['id' => $row['id'], 'name' => $row['name'], 'description' => $row['description'], 'ownerUid' => $row['owner_uid'],
			'enabled' => (bool)$row['enabled'], 'adminDisabled' => (bool)($row['admin_suspended'] ?? 0), 'createdAt' => (int)$row['created_at'], 'connections' => $connections];
	}
	public function list(string $uid, string $scope = 'mine'): array {
		$this->user($uid);
		$admin = $this->groups->isAdmin($uid);
		if (!in_array($scope, ['mine','all'], true)) {
			throw new ServiceException('Invalid scope.');
		}
		if ($scope === 'all' && !$admin) {
			throw new ServiceException('Administrator permission required.', 403);
		}
		return ['isAdmin' => $admin, 'integrations' => array_map(fn ($row) => $this->view($row, $uid),
			$this->repository->rows('wi_integrations', $scope === 'all' ? [] : ['owner_uid' => $uid]))];
	}
	public function create(string $uid, string $name, string $description = ''): array {
		$this->user($uid);
		[$name, $description] = $this->details($name, $description);
		$this->repository->ensureQuotaLock();
		return $this->repository->transaction(function () use ($uid, $name, $description) {
			$this->ownershipQuota($uid);
			$id = bin2hex(random_bytes(16));
			$row = ['id' => $id, 'owner_uid' => $uid, 'name' => $name, 'description' => $description,
				'bot_id' => $this->talk->create($id, $name, $description), 'enabled' => 1, 'admin_suspended' => 0, 'created_at' => time()];
			$this->repository->insert('wi_integrations', $row);
			$this->audit($uid, $id, 'create');
			return $this->view($row, $uid);
		});
	}
	public function update(string $uid, string $id, array $changes): array {
		if (array_diff(array_keys($changes), ['name','description','enabled','ownerUid'])) {
			throw new ServiceException('Unsupported field.');
		}
		$this->owned($uid, $id);
		$this->repository->ensureQuotaLock();
		return $this->repository->transaction(function () use ($uid, $id, $changes) {
			$row = $this->owned($uid, $id, true);
			foreach (['name','description','ownerUid'] as $key) {
				if (array_key_exists($key, $changes) && !is_string($changes[$key])) {
					throw new ServiceException('Invalid field type.');
				}
			}
			[$row['name'], $row['description']] = $this->details($changes['name'] ?? $row['name'], $changes['description'] ?? $row['description']);
			if (array_key_exists('enabled', $changes)) {
				if (!is_bool($changes['enabled'])) {
					throw new ServiceException('Enabled must be boolean.');
				}
				$isAdmin = $this->groups->isAdmin($uid);
				if ($changes['enabled']) {
					if (!$isAdmin && (bool)($row['admin_suspended'] ?? 0)) {
						throw new ServiceException('Only an administrator can restore this integration.', 403);
					}
					$this->talk->assertCanEnable($row, $isAdmin);
					if ($isAdmin) {
						$row['admin_suspended'] = 0;
					}
				} elseif ($isAdmin && $row['owner_uid'] !== $uid) {
					$row['admin_suspended'] = 1;
				}
				$row['enabled'] = (int)$changes['enabled'];
			}
			if (isset($changes['ownerUid']) && $this->groups->isAdmin($uid)) {
				$this->user($changes['ownerUid']);
				$changes['ownerUid'] = $this->users->get($changes['ownerUid'])->getUID();
			}
			if (isset($changes['ownerUid']) && $changes['ownerUid'] !== $row['owner_uid']) {
				if (!$this->groups->isAdmin($uid)) {
					throw new ServiceException('Administrator permission required.', 403);
				}
				$this->user($changes['ownerUid']);
				$this->ownershipQuota($changes['ownerUid']);
				// Previous owner knows every existing key. Transfer revokes every connection.
				foreach ($this->repository->rows('wi_connections', ['integration_id' => $id]) as $connection) {
					$this->revoke($row, $connection);
				}
				$row['owner_uid'] = $changes['ownerUid'];
				$this->audit($uid, $id, 'transfer');
			}
			$this->repository->update('wi_integrations', $id, $row);
			$this->talk->update($row, ($changes['enabled'] ?? false) === true);
			$this->audit($uid, $id, 'update');
			return $this->view($row, $uid);
		});
	}
	public function channels(string $uid): array {
		$this->user($uid);
		return ['channels' => $this->talk->channels($uid)];
	}
	public function connect(string $uid, string $id, string $token): array {
		if (!preg_match('/^[a-z0-9]{4,30}$/D', $token)) {
			throw new ServiceException('Invalid conversation.');
		}
		return $this->repository->transaction(function () use ($uid, $id, $token) {
			$row = $this->owned($uid, $id, true);
			$this->talk->moderatedRoom($uid, $token);
			$this->user($row['owner_uid']);
			if (!(bool)$row['enabled']) {
				throw new ServiceException('Integration is disabled.', 409);
			}
			$connections = $this->repository->rows('wi_connections', ['integration_id' => $id]);
			$existing = null;
			foreach ($connections as $connection) {
				if ($connection['token'] === $token) {
					$existing = $connection;
				}
			}
			if ($existing && (bool)$existing['enabled']) {
				throw new ServiceException('Connection exists. Rotate its credential instead.', 409);
			}
			if (!$existing && count($connections) >= 100) {
				throw new ServiceException('Connection limit reached.', 429);
			}
			$credential = bin2hex(random_bytes(32));
			$connection = ['id' => $existing['id'] ?? bin2hex(random_bytes(16)), 'integration_id' => $id, 'token' => $token,
				'credential_hash' => hash('sha256', $credential), 'enabled' => 1, 'last_status' => '', 'last_at' => 0, 'created_at' => time()];
			$this->talk->connect($row, $token);
			if ($existing) {
				$this->repository->update('wi_connections', $connection['id'], $connection);
			} else {
				$this->repository->insert('wi_connections', $connection);
			}
			$this->audit($uid, $id, 'connect');
			return ['connection' => $this->viewConnection($connection, $row, $uid), 'credential' => $credential];
		});
	}
	private function connection(string $id, string $connectionId, bool $lock = false): array {
		$connection = $this->repository->one('wi_connections', $connectionId, $lock);
		if (!$connection || $connection['integration_id'] !== $id) {
			throw new ServiceException('Connection not found.', 404);
		}
		return $connection;
	}
	private function revoke(array $integration, array $connection): void {
		$this->repository->update('wi_connections', $connection['id'], ['enabled' => 0, 'credential_hash' => hash('sha256', random_bytes(32))]);
		$this->talk->disconnect($integration, $connection['token']);
	}
	public function disconnect(string $uid, string $id, string $connectionId): void {
		$this->repository->transaction(function () use ($uid, $id, $connectionId) {
			$row = $this->owned($uid, $id, true);
			$connection = $this->connection($id, $connectionId, true);
			$this->revoke($row, $connection);
			$this->audit($uid, $id, 'disconnect');
		});
	}
	public function rotate(string $uid, string $id, string $connectionId): array {
		return $this->repository->transaction(function () use ($uid, $id, $connectionId) {
			$row = $this->owned($uid, $id, true);
			$connection = $this->connection($id, $connectionId, true);
			$this->talk->moderatedRoom($uid, $connection['token']);
			if (!(bool)$connection['enabled']) {
				throw new ServiceException('Connection is disabled.', 409);
			}
			$credential = bin2hex(random_bytes(32));
			$this->repository->update('wi_connections', $connectionId, ['credential_hash' => hash('sha256', $credential)]);
			$this->audit($uid, $id, 'rotate');
			return ['credential' => $credential];
		});
	}
	public function channelConnections(string $uid, string $token): array {
		$this->user($uid);
		$this->talk->moderatedRoom($uid, $token);
		$out = [];
		foreach ($this->repository->rows('wi_connections', ['token' => $token]) as $connection) {
			$row = $this->repository->one('wi_integrations', $connection['integration_id']);
			if ($row && (bool)$connection['enabled']) {
				$out[] = ['id' => $connection['id'], 'name' => $row['name'], 'enabled' => (bool)$row['enabled'] && $this->talk->available($row, $token)];
			}
		}
		return ['connections' => $out];
	}
	public function disconnectChannel(string $uid, string $token, string $connectionId): void {
		$this->user($uid);
		$this->talk->moderatedRoom($uid, $token);
		$connection = $this->repository->one('wi_connections', $connectionId);
		if (!$connection || $connection['token'] !== $token) {
			throw new ServiceException('Connection not found.', 404);
		}
		$this->repository->transaction(function () use ($uid, $token, $connection) {
			$this->user($uid);
			$this->talk->moderatedRoom($uid, $token);
			$row = $this->repository->one('wi_integrations', $connection['integration_id'], true);
			if (!$row) {
				throw new ServiceException('Connection not found.', 404);
			}
			$this->revoke($row, $connection);
			$this->audit($uid, $row['id'], 'channel_disconnect');
		});
	}
	private function authenticConnection(string $id, string $credential, bool $lock = false): array {
		$connection = $this->repository->one('wi_connections', $id, $lock);
		if (!$connection || !preg_match('/^[a-f0-9]{64}$/D', $credential)
			|| !hash_equals($connection['credential_hash'], hash('sha256', $credential)) || !(bool)$connection['enabled']) {
			throw new ServiceException('Invalid webhook credential.', 401);
		}
		return $connection;
	}
	public function deliver(string $connectionId, string $credential, array $payload): array {
		$this->authenticConnection($connectionId, $credential);
		if (array_diff(array_keys($payload), ['text','message','eventId']) || (isset($payload['text']) && isset($payload['message']))) {
			throw new ServiceException('Unsupported payload.');
		}
		$text = $payload['text'] ?? $payload['message'] ?? null;
		if (!is_string($text) || trim($text) === '' || strlen($text) > 16000 || !mb_check_encoding($text, 'UTF-8')) {
			throw new ServiceException('Message must be nonempty UTF-8, at most 16000 bytes.');
		}
		$eventId = $payload['eventId'] ?? bin2hex(random_bytes(16));
		if (!is_string($eventId) || $eventId === '' || strlen($eventId) > 200) {
			throw new ServiceException('Invalid eventId.');
		}
		$delivery = $this->repository->transaction(function () use ($connectionId, $credential, $eventId, $text) {
			// Serializes quota + reservation for this connection; unique index is a second guard.
			$connection = $this->authenticConnection($connectionId, $credential, true);
			$integration = $this->repository->one('wi_integrations', $connection['integration_id']);
			if (!$integration) {
				throw new ServiceException('Delivery is disabled.', 409);
			}
			$this->user($integration['owner_uid']);
			if (!(bool)$integration['enabled'] || !$this->talk->available($integration, $connection['token'])) {
				throw new ServiceException('Delivery is disabled.', 409);
			}
			$eventHash = hash('sha256', $eventId);
			$payloadHash = hash('sha256', $text);
			$existing = $this->repository->rows('wi_deliveries', ['connection_id' => $connectionId, 'event_hash' => $eventHash])[0] ?? null;
			if ($existing) {
				if (!hash_equals($existing['payload_hash'], $payloadHash)) {
					throw new ServiceException('eventId was already used for different content.', 409);
				}
				if ($existing['status'] !== 'delivered') {
					throw new ServiceException('Delivery outcome is pending or uncertain. Do not retry with a new eventId.', 409);
				}
				return ['duplicate' => true, 'messageId' => $existing['message_id']];
			}
			if ($this->repository->recentDeliveries($connectionId, time() - 60) >= 60) {
				throw new ServiceException('Rate limit exceeded.', 429);
			}
			$id = bin2hex(random_bytes(16));
			$this->repository->insert('wi_deliveries', ['id' => $id, 'connection_id' => $connectionId, 'event_hash' => $eventHash,
				'payload_hash' => $payloadHash, 'status' => 'pending', 'message_id' => '', 'created_at' => time()]);
			$this->repository->update('wi_connections', $connectionId, ['last_status' => 'pending','last_at' => time()]);
			return ['id' => $id, 'integration' => $integration, 'connection' => $connection];
		});
		if (isset($delivery['duplicate'])) {
			return ['status' => 'duplicate','messageId' => $delivery['messageId']];
		}
		// Deliberately outside the reservation transaction: a crash can never undo the intent.
		try {
			$this->authenticConnection($connectionId, $credential);
			$integration = $this->repository->one('wi_integrations', $delivery['integration']['id']);
			if (!$integration || !(bool)$integration['enabled']) {
				throw new ServiceException('Delivery is disabled.', 409);
			}
			$this->user($integration['owner_uid']);
			$messageId = $this->talk->send($integration, $delivery['connection']['token'], $text, 'wi-' . $delivery['id']);
			$this->repository->transaction(function () use ($delivery, $connectionId, $messageId) {
				$this->repository->update('wi_deliveries', $delivery['id'], ['status' => 'delivered','message_id' => $messageId]);
				$this->repository->update('wi_connections', $connectionId, ['last_status' => 'delivered','last_at' => time()]);
			});
			return ['status' => 'delivered','messageId' => $messageId];
		} catch (\Throwable) {
			// Even a post-send persistence failure stays non-retriable. No payload/secrets are logged.
			try {
				$this->repository->update('wi_deliveries', $delivery['id'], ['status' => 'uncertain']);
				$this->repository->update('wi_connections', $connectionId, ['last_status' => 'uncertain','last_at' => time()]);
			} catch (\Throwable) { /* Durable pending intent still prevents a repeat send. */
			}
			throw new ServiceException('Delivery outcome is uncertain. Inspect the channel; do not retry with a new eventId.', 409);
		}
	}
}
