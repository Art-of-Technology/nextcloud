<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\WorkspaceIntegrations\Db;

use OCP\IDBConnection;

/** Table names and column names are internal constants, never request input. */
class Repository {
	public function __construct(
		private IDBConnection $db,
	) {
	}
	public function ensureQuotaLock(): void {
		// Fresh Nextcloud installs may apply schema-only migrations and skip their
		// postSchemaChange callbacks. Seed idempotently before taking transaction locks.
		$this->db->insertIgnoreConflict('wi_locks', ['id' => 'ownership_quota']);
	}
	public function transaction(callable $work): mixed {
		$this->db->beginTransaction();
		try {
			$result = $work();
			$this->db->commit();
			return $result;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}
	public function rows(string $table, array $where = [], bool $lock = false): array {
		if ($lock) {
			// SQLite has no SELECT FOR UPDATE. An identity update takes a write lock
			// on both SQLite and PostgreSQL and keeps it until our transaction commits.
			if (!isset($where['id'])) {
				throw new \LogicException('A row identity is required for locking.');
			}
			$guard = $this->db->getQueryBuilder();
			$guard->update($table)->set('id', 'id')
				->where($guard->expr()->eq('id', $guard->createNamedParameter($where['id'])));
			$guard->executeStatement();
		}
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from($table);
		foreach ($where as $key => $value) {
			$q->andWhere($q->expr()->eq($key, $q->createNamedParameter($value)));
		}
		$result = $q->executeQuery();
		try {
			return $result->fetchAllAssociative();
		} finally {
			$result->closeCursor();
		}
	}
	public function one(string $table, string $id, bool $lock = false): ?array {
		return $this->rows($table, ['id' => $id], $lock)[0] ?? null;
	}
	public function insert(string $table, array $row): void {
		$q = $this->db->getQueryBuilder();
		$q->insert($table);
		foreach ($row as $key => $value) {
			$q->setValue($key, $q->createNamedParameter($value));
		}
		$q->executeStatement();
	}
	public function update(string $table, string $id, array $values): void {
		$q = $this->db->getQueryBuilder();
		$q->update($table)->where($q->expr()->eq('id', $q->createNamedParameter($id)));
		foreach ($values as $key => $value) {
			$q->set($key, $q->createNamedParameter($value));
		}
		$q->executeStatement();
	}
	public function recentDeliveries(string $connectionId, int $since): int {
		$q = $this->db->getQueryBuilder();
		$q->selectAlias($q->func()->count('*'), 'n')->from('wi_deliveries')
			->where($q->expr()->eq('connection_id', $q->createNamedParameter($connectionId)))
			->andWhere($q->expr()->gte('created_at', $q->createNamedParameter($since)));
		$r = $q->executeQuery();
		try {
			return (int)$r->fetchOne();
		} finally {
			$r->closeCursor();
		}
	}
}
