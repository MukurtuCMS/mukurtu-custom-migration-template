<?php

declare(strict_types=1);

namespace Drupal\Tests\custom_migrate\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\custom_migrate\Hook\MigrationPluginsAlterHooks;
use Drupal\field\Entity\FieldConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ContentLanguageSettings;
use Drupal\migrate\MigrateExecutable;
use Drupal\migrate\Row;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the custom taxonomy fields and their Mukurtu 3 migrations.
 *
 * Every test covers each field in
 * MigrationPluginsAlterHooks::FIELD_MIGRATIONS, so adding a field to that
 * map adds it to these tests.
 *
 * @see \Drupal\custom_migrate\Hook\MigrationPluginsAlterHooks
 */
#[Group('custom_migrate')]
class CustomMigrateTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'taxonomy',
    'language',
    'content_translation',
    'migrate',
    'migrate_drupal',
    'migrate_plus',
    'search_api',
    'mukurtu_migrate',
    'custom_migrate',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['filter', 'node']);

    // mukurtu_digital_heritage is too heavy to install here, so provide the
    // bundle the custom fields attach to.
    NodeType::create(['type' => 'digital_heritage', 'name' => 'Digital Heritage'])->save();
    $this->installConfig(['custom_migrate']);

    // The core Digital Heritage migration this module alters.
    $module_path = \Drupal::service('extension.list.module')->getPath('mukurtu_migrate');
    $data = Yaml::decode(file_get_contents($module_path . '/config/install/migrate_plus.migration.mukurtu_cms_v3_digital_heritage.yml'));
    \Drupal::entityTypeManager()->getStorage('migration')->create($data)->save();
  }

  /**
   * Returns the custom fields, keyed by field name.
   *
   * @return array<string, array{vid: string, migration: string, cardinality: int}>
   *   The vocabulary each field references, its term migration, and its
   *   cardinality.
   */
  protected function customFields(): array {
    $fields = [];
    foreach (MigrationPluginsAlterHooks::FIELD_MIGRATIONS as $field_name => $migration) {
      $field = FieldConfig::loadByName('node', 'digital_heritage', $field_name);
      $this->assertNotNull($field, "$field_name is installed on Digital Heritage.");
      $target_bundles = $field->getSetting('handler_settings')['target_bundles'];
      $this->assertCount(1, $target_bundles, "$field_name references one vocabulary.");
      $fields[$field_name] = [
        'vid' => reset($target_bundles),
        'migration' => $migration,
        'cardinality' => $field->getFieldStorageDefinition()->getCardinality(),
      ];
    }
    $this->assertNotEmpty($fields);
    return $fields;
  }

  /**
   * Every Digital Heritage field the module ships is migrated.
   */
  public function testEveryShippedFieldIsMigrated(): void {
    $module_path = \Drupal::service('extension.list.module')->getPath('custom_migrate');
    $files = glob($module_path . '/config/install/field.field.node.digital_heritage.*.yml');
    $this->assertNotEmpty($files);
    foreach ($files as $file) {
      $field_name = Yaml::decode(file_get_contents($file))['field_name'];
      $this->assertArrayHasKey($field_name, MigrationPluginsAlterHooks::FIELD_MIGRATIONS, "$field_name is in MigrationPluginsAlterHooks::FIELD_MIGRATIONS.");
    }
  }

  /**
   * Each field references an installed vocabulary and shares its terms.
   */
  public function testFieldsInstalled(): void {
    foreach ($this->customFields() as $field_name => $expected) {
      $this->assertNotNull(Vocabulary::load($expected['vid']), "{$expected['vid']} is installed.");
      $field = FieldConfig::loadByName('node', 'digital_heritage', $field_name);
      $this->assertSame('taxonomy_term', $field->getFieldStorageDefinition()->getSetting('target_type'));
      // As with Mukurtu's own Digital Heritage term fields, translations of
      // an item share its terms. The terms themselves are translated.
      $this->assertFalse($field->isTranslatable(), "$field_name is not translatable.");
    }
  }

  /**
   * Terms in each vocabulary are translatable, as in Mukurtu's vocabularies.
   */
  public function testTermsTranslatable(): void {
    $this->installConfig(['language']);
    \Drupal::service('config.installer')->installOptionalConfig();

    foreach ($this->customFields() as $expected) {
      $settings = ContentLanguageSettings::loadByEntityTypeBundle('taxonomy_term', $expected['vid']);
      $this->assertFalse($settings->isNew(), $expected['vid'] . ' has language settings.');
      $this->assertTrue($settings->getThirdPartySetting('content_translation', 'enabled'));
    }
  }

  /**
   * Mukurtu Managers can manage terms in each vocabulary.
   */
  public function testManagerPermissions(): void {
    Role::create(['id' => 'mukurtu_manager', 'label' => 'Mukurtu Manager'])->save();
    $module_path = \Drupal::service('extension.list.module')->getPath('custom_migrate');
    require_once \Drupal::root() . '/' . $module_path . '/custom_migrate.install';

    // A config import brings the role's permissions with it.
    custom_migrate_install(TRUE);
    $this->assertSame([], Role::load('mukurtu_manager')->getPermissions());

    custom_migrate_install(FALSE);
    $manager = Role::load('mukurtu_manager');
    foreach ($this->customFields() as $expected) {
      foreach (['create', 'delete', 'edit'] as $operation) {
        $this->assertTrue($manager->hasPermission("$operation terms in {$expected['vid']}"));
      }
    }
  }

  /**
   * The term migrations are tagged Mukurtu 3 and run before Digital Heritage.
   */
  public function testTermMigrationsRunBeforeDigitalHeritage(): void {
    $manager = \Drupal::service('plugin.manager.migration');
    $manager->clearCachedDefinitions();
    $ids = array_keys($manager->createInstancesByTag('Mukurtu 3'));
    $dh_position = array_search('mukurtu_cms_v3_digital_heritage', $ids, TRUE);
    $this->assertNotFalse($dh_position);
    $dependencies = $manager->createInstance('mukurtu_cms_v3_digital_heritage')->getMigrationDependencies();

    foreach ($this->customFields() as $expected) {
      $migration = $manager->createInstance($expected['migration']);
      $this->assertNotEmpty($migration->getSourceConfiguration()['bundle'], $expected['migration'] . ' names a Mukurtu 3 vocabulary.');
      $this->assertSame($expected['vid'], $migration->getProcess()['vid'][0]['default_value'], $expected['migration'] . ' creates terms in the vocabulary its field references.');

      $position = array_search($expected['migration'], $ids, TRUE);
      $this->assertNotFalse($position, $expected['migration'] . ' is tagged Mukurtu 3.');
      $this->assertLessThan($dh_position, $position, $expected['migration'] . ' runs before Digital Heritage.');
      $this->assertContains($expected['migration'], $dependencies['required']);
    }
  }

  /**
   * Digital Heritage items get the migrated terms for each field.
   */
  public function testDigitalHeritageFieldValues(): void {
    $manager = \Drupal::service('plugin.manager.migration');
    $manager->clearCachedDefinitions();
    $process = $manager->getDefinition('mukurtu_cms_v3_digital_heritage')['process'];

    $with_terms = ['nid' => 1, 'title' => 'With terms'];
    $without_terms = ['nid' => 2, 'title' => 'Without terms'];
    $pipeline = ['title' => 'title'];
    $expected_ids = [];
    $source_tid = 1000;
    foreach ($this->customFields() as $field_name => $field) {
      $count = $field['cardinality'] === 1 ? 1 : 2;
      $with_terms[$field_name] = [];
      $expected_ids[$field_name] = [];
      for ($i = 0; $i < $count; $i++) {
        $source_tid++;
        $term = Term::create(['vid' => $field['vid'], 'name' => "Term $source_tid"]);
        $term->save();
        $manager->createInstance($field['migration'])->getIdMap()
          ->saveIdMapping(new Row(['tid' => $source_tid], ['tid' => ['type' => 'integer']]), [$term->id()]);
        $with_terms[$field_name][] = ['tid' => $source_tid];
        $expected_ids[$field_name][] = $term->id();
      }
      $without_terms[$field_name] = [];
      $pipeline[$field_name] = $process[$field_name];
    }

    // The altered Digital Heritage process lines, fed from embedded rows
    // instead of a Mukurtu 3 database.
    $definition = [
      'id' => 'custom_dh_pipeline',
      'source' => [
        'plugin' => 'embedded_data',
        'data_rows' => [$with_terms, $without_terms],
        'ids' => ['nid' => ['type' => 'integer']],
      ],
      'process' => $pipeline,
      'destination' => [
        'plugin' => 'entity:node',
        'default_bundle' => 'digital_heritage',
      ],
    ];
    (new MigrateExecutable($manager->createStubMigration($definition)))->import();

    $nodes = [];
    foreach (Node::loadMultiple() as $node) {
      $nodes[$node->label()] = $node;
    }
    $this->assertCount(2, $nodes);
    foreach (array_keys($expected_ids) as $field_name) {
      $this->assertEquals($expected_ids[$field_name], array_column($nodes['With terms']->get($field_name)->getValue(), 'target_id'), "$field_name gets its migrated terms.");
      $this->assertTrue($nodes['Without terms']->get($field_name)->isEmpty(), "$field_name stays empty without terms.");
    }
  }

}
