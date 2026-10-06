<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\WorkspaceIntegrations\Migration;
use Closure;
use OCP\DB\{ISchemaWrapper,Types};
use OCP\Migration\{IOutput,SimpleMigrationStep};
final class Version010100Date20261006000000 extends SimpleMigrationStep {
 public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
  $schema=$schemaClosure();
  if (!$schema->hasTable('wi_cards')) {
   $table=$schema->createTable('wi_cards');
   $table->addColumn('id',Types::STRING,['length'=>32,'notnull'=>true]);
   $table->addColumn('token',Types::STRING,['length'=>30,'notnull'=>true]);
   $table->addColumn('payload',Types::TEXT,['notnull'=>true]);
   $table->addColumn('created_at',Types::BIGINT,['notnull'=>true]);
   $table->setPrimaryKey(['id']);
  }
  return $schema;
 }
}
