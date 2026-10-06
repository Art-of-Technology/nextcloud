<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\WorkspaceIntegrations\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version010000Date20261006000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$definitions = [
			'wi_integrations' => ['id' => 32, 'owner_uid' => 64, 'name' => 64, 'description' => 4000, 'bot_id' => 0, 'enabled' => 0, 'created_at' => 0],
			'wi_connections' => ['id' => 32, 'integration_id' => 32, 'token' => 30, 'credential_hash' => 64, 'enabled' => 0, 'last_status' => 24, 'last_at' => 0, 'created_at' => 0],
			'wi_deliveries' => ['id' => 32, 'connection_id' => 32, 'event_hash' => 64, 'payload_hash' => 64, 'status' => 24, 'message_id' => 64, 'created_at' => 0],
			'wi_audit' => ['id' => 32, 'actor_uid' => 64, 'integration_id' => 32, 'action' => 32, 'created_at' => 0],
		];
		foreach ($definitions as $name => $columns) {
			if ($schema->hasTable($name)) {
				continue;
			}
			$table = $schema->createTable($name);
			foreach ($columns as $column => $length) {
				$table->addColumn($column, $length ? Types::STRING : Types::BIGINT,
					$length ? ['notnull' => true, 'length' => $length, 'default' => ''] : ['notnull' => true, 'default' => 0]);
			}
			$table->setPrimaryKey(['id']);
			if ($name === 'wi_integrations') {
				$table->addIndex(['owner_uid'], 'wi_owner');
				$table->addUniqueIndex(['bot_id'], 'wi_bot');
			} elseif ($name === 'wi_connections') {
				$table->addUniqueIndex(['integration_id', 'token'], 'wi_channel');
				$table->addIndex(['token'], 'wi_token');
			} elseif ($name === 'wi_deliveries') {
				$table->addUniqueIndex(['connection_id', 'event_hash'], 'wi_event');
				$table->addIndex(['connection_id','created_at'], 'wi_rate');
			} else {
				$table->addIndex(['integration_id','created_at'], 'wi_audit_time');
			}
		}
		return $schema;
	}
}
