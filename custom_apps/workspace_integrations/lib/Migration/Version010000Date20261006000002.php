<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\WorkspaceIntegrations\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version010000Date20261006000002 extends SimpleMigrationStep {
	public function __construct(
		private IDBConnection $db
	) {
	}
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('wi_locks')) {
			$table = $schema->createTable('wi_locks');
			$table->addColumn('id', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->setPrimaryKey(['id']);
		}
		return $schema;
	}
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$this->db->insertIgnoreConflict('wi_locks', ['id' => 'ownership_quota']);
	}
}
