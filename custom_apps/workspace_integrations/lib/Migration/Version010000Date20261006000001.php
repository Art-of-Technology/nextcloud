<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\WorkspaceIntegrations\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version010000Date20261006000001 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$table = $schema->getTable('wi_integrations');
		if (!$table->hasColumn('admin_suspended')) {
			$table->addColumn('admin_suspended', Types::SMALLINT, ['notnull' => true,'default' => 0]);
		}
		return $schema;
	}
}
