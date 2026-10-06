<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

use OCA\WorkspaceIntegrations\Db\Repository;
use OCA\WorkspaceIntegrations\Service\IntegrationService;
use OCA\WorkspaceIntegrations\Service\ServiceException;
use OCA\WorkspaceIntegrations\Service\TalkGateway;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

final class MemoryRepository extends Repository {
	public array $tables = [];
	public function __construct() {
	}
	public function ensureQuotaLock(): void {
		$this->tables['wi_locks']['ownership_quota'] ??= ['id' => 'ownership_quota'];
	}
	public function transaction(callable $work): mixed {
		$old = $this->tables;
		try {
			return $work();
		} catch (Throwable $e) {
			$this->tables = $old;
			throw $e;
		}
	}
	public function rows(string $table, array $where = [], bool $lock = false): array {
		return array_values(array_filter($this->tables[$table] ?? [], static fn ($row) => count(array_filter($where, static fn ($value, $key) => ($row[$key] ?? null) != $value, ARRAY_FILTER_USE_BOTH)) === 0));
	}
	public function one(string $table, string $id, bool $lock = false): ?array {
		return $this->tables[$table][$id] ?? null;
	}
	public function insert(string $table, array $row): void {
		$this->tables[$table][$row['id']] = $row;
	}
	public function update(string $table, string $id, array $values): void {
		$this->tables[$table][$id] = array_replace($this->tables[$table][$id], $values);
	}
	public function recentDeliveries(string $connectionId, int $since): int {
		return count(array_filter($this->rows('wi_deliveries', ['connection_id' => $connectionId]), static fn ($row) => $row['created_at'] >= $since));
	}
}

final class backendTest extends TestCase {
	private MemoryRepository $repo;
	private TalkGateway $talk;
	private IntegrationService $service;
	private string $credential;
	private IUserManager $users;
	protected function setUp(): void {
		$this->repo = new MemoryRepository();
		$this->repo->insert('wi_locks', ['id' => 'ownership_quota']);
		$this->talk = $this->createMock(TalkGateway::class);
		$this->talk->expects(self::any())->method('available')->willReturn(true);
		$this->talk->method('roomName')->willReturn('Audit');
		$users = $this->createStub(IUserManager::class);
		$users->method('get')->willReturnCallback(function ($uid) {
			if ($uid === 'missing') {
				return null;
			}
			$user = $this->createStub(IUser::class);
			$user->method('isEnabled')->willReturn($uid !== 'disabled');
			$user->method('getUID')->willReturn(strtolower($uid));
			$user->method('getBackendClassName')->willReturn($uid === 'guest' ? 'Guests' : 'Database');
			return $user;
		});
		$this->users = $users;
		$groups = $this->createStub(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn ($uid) => $uid === 'admin');
		$urls = $this->createStub(\OCP\IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(static fn ($route, $params) => 'https://chat.example/apps/workspace_integrations/cards/' . $params['cardId']);
		$this->service = new IntegrationService($this->repo, $this->talk, $users, $groups, $urls);
		$this->credential = str_repeat('a', 64);
		$this->repo->insert('wi_integrations', ['id' => 'integration', 'owner_uid' => 'owner', 'name' => 'Audit', 'description' => '', 'bot_id' => 1, 'enabled' => 1, 'created_at' => 1]);
		$this->repo->insert('wi_connections', ['id' => 'connection', 'integration_id' => 'integration', 'token' => 'roomtoken', 'credential_hash' => hash('sha256', $this->credential), 'enabled' => 1, 'last_status' => '', 'last_at' => 0, 'created_at' => 1]);
	}
	public function testOwnerListDoesNotExposeCredentialOrNativeBot(): void {
		$this->talk->expects(self::never())->method('send');
		$result = $this->service->list('owner');
		$text = json_encode($result);
		self::assertCount(1, $result['integrations']);
		self::assertStringNotContainsString('credential', $text);
		self::assertStringNotContainsString('bot_id', $text);
		self::assertStringNotContainsString(hash('sha256', $this->credential), $text);
		self::assertCount(0, $this->service->list('other')['integrations']);
	}
	public function testNonOwnerCannotUpdate(): void {
		$this->talk->expects(self::never())->method('send');
		$this->expectException(ServiceException::class);
		$this->expectExceptionMessage('not found');
		$this->service->update('other', 'integration', ['name' => 'Stolen']);
	}
	public function testCanonicalSameOwnerDoesNotRevokeCredentials(): void {
		$this->talk->expects(self::never())->method('disconnect');
		$this->talk->expects(self::never())->method('send');
		$this->service->update('admin', 'integration', ['ownerUid' => 'OWNER']);
		self::assertSame('owner', $this->repo->tables['wi_integrations']['integration']['owner_uid']);
		self::assertSame(1, $this->repo->tables['wi_connections']['connection']['enabled']);
		self::assertSame(hash('sha256', $this->credential), $this->repo->tables['wi_connections']['connection']['credential_hash']);
	}
	public function testNonAdminCannotListAll(): void {
		$this->talk->expects(self::never())->method('send');
		$this->expectException(ServiceException::class);
		$this->service->list('owner', 'all');
	}
	public function testDisabledUserCannotList(): void {
		$this->talk->expects(self::never())->method('send');
		$this->expectException(ServiceException::class);
		$this->service->list('disabled');
	}
	public function testGuestCannotCreate(): void {
		$this->talk->expects(self::never())->method('send');
		$this->expectException(ServiceException::class);
		$this->service->create('guest', 'Audit');
	}
	public function testGuestBackendMatchingIsExactAcrossEntryPoints(): void {
		$this->talk->expects(self::never())->method('send');
		foreach (['Guests' => false, 'OCA\\Guests\\UserBackend' => false, 'GuestResearchDirectory' => true, 'Database' => true] as $backend => $expected) {
			$user = $this->createStub(IUser::class);
			$user->method('isEnabled')->willReturn(true);
			$user->method('getBackendClassName')->willReturn($backend);
			self::assertSame($expected, \OCA\WorkspaceIntegrations\Service\AccountAccess::allowed($user), $backend);
		}
		self::assertFalse(\OCA\WorkspaceIntegrations\Service\AccountAccess::allowed(null));
	}
	public function testSendUsesPinnedChannelAndDeduplicatesSameContent(): void {
		$this->talk->expects(self::once())->method('send')->with(self::anything(), 'roomtoken', 'Notice', self::stringStartsWith('wi-'))->willReturn('321');
		self::assertSame(['status' => 'delivered','messageId' => '321'], $this->service->deliver('connection', $this->credential, ['text' => 'Notice','eventId' => 'source-1']));
		self::assertSame(['status' => 'duplicate','messageId' => '321'], $this->service->deliver('connection', $this->credential, ['text' => 'Notice','eventId' => 'source-1']));
	}
	public function testEventIdCannotBeReusedWithDifferentText(): void {
		$this->talk->expects(self::once())->method('send')->willReturn('321');
		$this->service->deliver('connection', $this->credential, ['text' => 'Notice','eventId' => 'source-1']);
		$this->expectException(ServiceException::class);
		$this->expectExceptionMessage('different content');
		$this->service->deliver('connection', $this->credential, ['text' => 'Changed','eventId' => 'source-1']);
	}
	public function testUncertainOutcomeNeverCallsSendTwice(): void {
		$this->talk->expects(self::once())->method('send')->willThrowException(new RuntimeException('Simulate failure after storage'));
		foreach ([1,2] as $attempt) {
			try {
				$this->service->deliver('connection', $this->credential, ['text' => 'Notice','eventId' => 'source-1']);
				self::fail('Expected uncertain outcome');
			} catch (ServiceException $e) {
				self::assertSame(409, $e->status);
			}
		}
		self::assertSame('uncertain', array_values($this->repo->tables['wi_deliveries'])[0]['status']);
	}
	public function testWebhookCannotOverrideChannel(): void {
		$this->talk->expects(self::never())->method('send');
		$this->expectException(ServiceException::class);
		$this->service->deliver('connection', $this->credential, ['text' => 'Notice','token' => 'another']);
	}
	public function testBadCredentialCannotDeliver(): void {
		$this->talk->expects(self::never())->method('send');
		$this->expectException(ServiceException::class);
		$this->service->deliver('connection', str_repeat('b', 64), ['text' => 'Notice']);
	}
	public function testDisabledOwnerBlocksKnownCredential(): void {
		$this->repo->update('wi_integrations', 'integration', ['owner_uid' => 'disabled']);
		$this->talk->expects(self::never())->method('send');
		$this->expectException(ServiceException::class);
		$this->service->deliver('connection', $this->credential, ['text' => 'Notice']);
	}
	public function testTransferRevokesOldCredentialsAndChannelAccess(): void {
		$this->talk->expects(self::once())->method('disconnect')->with(self::anything(), 'roomtoken');
		$this->service->update('admin', 'integration', ['ownerUid' => 'newowner']);
		self::assertSame('newowner', $this->repo->one('wi_integrations', 'integration')['owner_uid']);
		self::assertSame(0, $this->repo->one('wi_connections', 'connection')['enabled']);
		$this->expectException(ServiceException::class);
		$this->service->deliver('connection', $this->credential, ['text' => 'Notice']);
	}
	public function testRateLimitPreventsSixtyFirstReservation(): void {
		for ($i = 0;$i < 60;$i++) {
			$this->repo->insert('wi_deliveries', ['id' => (string)$i,'connection_id' => 'connection','created_at' => time(),'event_hash' => 'old' . $i]);
		}
		$this->talk->expects(self::never())->method('send');
		$this->expectException(ServiceException::class);
		$this->expectExceptionMessage('Rate limit');
		$this->service->deliver('connection', $this->credential, ['text' => 'Notice']);
	}
	public function testOwnerCannotUndoAdministrativeSuspension(): void {
		$this->talk->expects(self::exactly(2))->method('update');
		$this->service->update('admin', 'integration', ['enabled' => false]);
		self::assertTrue($this->service->list('owner')['integrations'][0]['adminDisabled']);
		$this->service->update('owner', 'integration', ['name' => 'Renamed']);
		$this->expectException(ServiceException::class);
		$this->expectExceptionMessage('administrator');
		$this->service->update('owner', 'integration', ['enabled' => true]);
	}
	public function testAdministratorCanRestoreSuspension(): void {
		$this->talk->expects(self::exactly(2))->method('update');
		$this->service->update('admin', 'integration', ['enabled' => false]);
		$result = $this->service->update('admin', 'integration', ['enabled' => true]);
		self::assertTrue($result['enabled']);
		self::assertFalse($result['adminDisabled']);
	}
	public function testFreshSchemaOnlyInstallInitializesQuotaLock(): void {
		unset($this->repo->tables['wi_locks']);
		$this->talk->expects(self::once())->method('create')->willReturn(2);
		$created = $this->service->create('owner', 'Fresh worker');
		self::assertSame('Fresh worker', $created['name']);
		self::assertNotNull($this->repo->one('wi_locks', 'ownership_quota'));
	}

	public function testRichReservationIsImmutableAndDeduplicatesFullPayload(): void {
		$this->talk->expects(self::once())->method('send')->willReturnCallback(function ($integration, $token, $text, $reference) {
			$id = substr($reference, 3);
			self::assertSame('pending', $this->repo->tables['wi_deliveries'][$id]['status']);
			self::assertArrayHasKey($id, $this->repo->tables['wi_cards']);
			self::assertStringContainsString('/cards/' . $id, $text);
			return '901';
		});
		$payload = ['text' => 'Summary','blocks' => [['type' => 'section','text' => ['type' => 'mrkdwn','text' => '*Details*']]],'eventId' => 'rich1'];
		self::assertSame('delivered', $this->service->deliver('connection', $this->credential, $payload)['status']);
		self::assertSame('duplicate', $this->service->deliver('connection', $this->credential, $payload)['status']);
		self::assertCount(1, $this->repo->tables['wi_cards']);
		$payload['blocks'][0]['text']['text'] = 'Changed';
		$this->expectException(ServiceException::class);
		$this->expectExceptionMessage('different content');
		$this->service->deliver('connection', $this->credential, $payload);
	}
	public function testCardReadRechecksMembershipEveryTime(): void {
		$id = str_repeat('a', 32);
		$this->repo->insert('wi_cards', ['id' => $id,'token' => 'roomtoken','payload' => '{"schemaVersion":1,"blocks":[],"text":"Notice"}']);
		$this->repo->insert('wi_deliveries', ['id' => $id,'status' => 'delivered','message_id' => '901']);
		$service = new \OCA\WorkspaceIntegrations\Service\CardService($this->repo, $this->talk, $this->users);
		$calls = 0;
		$this->talk->expects(self::exactly(2))->method('assertCardVisible')->with('owner', 'roomtoken', '901')->willReturnCallback(function () use (&$calls) {
			if (++$calls === 2) {
				throw new ServiceException('Card not found.', 404);
			}
		});
		self::assertSame('Notice', $service->read('owner', $id)['text']);
		$this->expectException(ServiceException::class);
		$service->read('owner', $id);
	}
	public function testPendingAndUncertainCardsAreUnreadable(): void {
		$id = str_repeat('b', 32);
		$this->repo->insert('wi_cards', ['id' => $id,'token' => 'roomtoken','payload' => '{}']);
		$this->talk->expects(self::never())->method('assertCardVisible');
		$service = new \OCA\WorkspaceIntegrations\Service\CardService($this->repo, $this->talk, $this->users);
		foreach (['pending','uncertain'] as $state) {
			$this->repo->insert('wi_deliveries', ['id' => $id,'status' => $state,'message_id' => '']);
			try {
				$service->read('owner', $id);
				self::fail('Must deny incomplete delivery');
			} catch (ServiceException $e) {
				self::assertSame(404, $e->status);
			}
		}
	}
	public function testCardsDenyDisabledAndGuestAccounts(): void {
		$this->talk->expects(self::never())->method('assertCardVisible');
		$service = new \OCA\WorkspaceIntegrations\Service\CardService($this->repo, $this->talk, $this->users);
		foreach (['missing','disabled','guest'] as $uid) {
			try {
				$service->read($uid, str_repeat('c', 32));
				self::fail('Must deny account');
			} catch (ServiceException $e) {
				self::assertSame(404, $e->status);
			}
		}
	}
}
