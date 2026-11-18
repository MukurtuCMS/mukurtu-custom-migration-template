<?php

declare(strict_types=1);

namespace Drupal\custom_migrate\Hook;

use Drupal\Core\Hook\Attribute\Hook;

/**
 * Migration plugin definition alterations for custom_migrate.
 */
class MigrationPluginsAlterHooks {

  /**
   * Maps each custom Digital Heritage field to its term migration.
   *
   * Keys are the Mukurtu 4 field names, which are also the Mukurtu 3 field
   * names. Add one line for each custom taxonomy field you migrate.
   */
  public const FIELD_MIGRATIONS = [
    'field_example_topic' => 'mukurtu_cms_v3_terms_example_topic',
  ];

  /**
   * Implements hook_migration_plugins_alter().
   *
   * Adds the custom taxonomy fields to the core Digital Heritage migration,
   * and makes that migration depend on the custom term migrations so the
   * terms exist before Digital Heritage items look them up.
   */
  #[Hook('migration_plugins_alter')]
  public function migrationPluginsAlter(array &$definitions): void {
    if (!isset($definitions['mukurtu_cms_v3_digital_heritage'])) {
      return;
    }
    $migration = &$definitions['mukurtu_cms_v3_digital_heritage'];
    foreach (self::FIELD_MIGRATIONS as $field_name => $term_migration) {
      $migration['process'][$field_name] = [
        'plugin' => 'migration_lookup',
        'migration' => $term_migration,
        'source' => $field_name,
      ];
      $required = $migration['migration_dependencies']['required'] ?? [];
      if (!in_array($term_migration, $required, TRUE)) {
        $migration['migration_dependencies']['required'][] = $term_migration;
      }
    }
  }

}
