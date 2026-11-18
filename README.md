# Mukurtu custom migration template

A starting point for migrating custom taxonomy fields on Digital Heritage items from Mukurtu CMS 3 to Mukurtu CMS 4.

The standard Mukurtu 3 to 4 migration (`mukurtu_migrate`) only knows about the fields Mukurtu ships. If your Mukurtu 3 site added its own taxonomy fields to Digital Heritage, this template gives you a small module that migrates them too. For each custom field, the module does the following:

- Creates the vocabulary and the Digital Heritage field in Mukurtu 4.
- Migrates the vocabulary's terms from Mukurtu 3.
- Adds the field to the Digital Heritage migration, and makes that migration wait until the terms are migrated.
- Makes the vocabulary's terms translatable, like Mukurtu's own vocabularies.
- Lets Mukurtu Managers create, edit, and delete the vocabulary's terms.

The template ships with one example field, Example Topic (`field_example_topic`). Replace it with your own fields.

## Before you begin

You need the following:

- A Mukurtu CMS 4 site that you're migrating your Mukurtu 3 site into.
- Administrator access to the Mukurtu 3 site.

For each custom taxonomy field, collect these details from the Mukurtu 3 site:

| Detail | Where to find it in Mukurtu 3 |
|-|-|
| Field machine name, for example `field_dialect` | **Structure** > **Content types** > **Digital Heritage** > **Manage fields**, in the **Name** column. |
| Field label | Same page, in the **Label** column. |
| Number of values | The field's edit page, under **Number of values**. |
| Vocabulary machine name, for example `dialects` | **Structure** > **Taxonomy** > the vocabulary > **Edit**, next to **Machine name**. |
| Vocabulary name and description | Same page. |

The field machine name and the vocabulary machine name are often different. For example, a field named `field_dialect` might use a vocabulary named `dialects`.

## Create your module

1. Select **Use this template** on GitHub to create your own repository, or download this repository.
2. Optional: rename the module. Replace `custom_migrate` with your own name, such as `mysite_migrate`, in file names, directory names, and file contents. On macOS or Linux, from the repository root:

    ```bash
    NEW=mysite_migrate
    grep -rl custom_migrate . --exclude-dir=.git | xargs perl -pi -e "s/custom_migrate/$NEW/g"
    mv custom_migrate/custom_migrate.info.yml custom_migrate/$NEW.info.yml
    mv custom_migrate/custom_migrate.install custom_migrate/$NEW.install
    mv custom_migrate $NEW
    ```

    Then edit `name`, `description`, and `package` in the `.info.yml` file.

3. Add each custom field, as described in the next section.
4. Remove the example field, unless you renamed its files for your own field. Delete these files, and its line in `FIELD_MIGRATIONS`:

    - `config/install/taxonomy.vocabulary.example_topic.yml`
    - `config/install/field.storage.node.field_example_topic.yml`
    - `config/install/field.field.node.digital_heritage.field_example_topic.yml`
    - `config/install/migrate_plus.migration.mukurtu_cms_v3_terms_example_topic.yml`
    - `config/optional/language.content_settings.taxonomy_term.example_topic.yml`

### Add a field

Each custom field needs five configuration files and one line of code. Copy the example field's files, rename them, and change the values listed in the following table.

In the table, `FIELD` is the field machine name and `VOCAB` is the vocabulary machine name. Keep the Mukurtu 3 machine names in Mukurtu 4 unless you have a reason to change them.

| File | Values to change |
|-|-|
| `config/install/taxonomy.vocabulary.VOCAB.yml` | `vid`, `name`, `description` |
| `config/install/field.storage.node.FIELD.yml` | `id`, `field_name`, `cardinality` |
| `config/install/field.field.node.digital_heritage.FIELD.yml` | Both entries under `dependencies.config` that name the field and vocabulary, plus `id`, `field_name`, `label`, `description`, and `target_bundles` |
| `config/install/migrate_plus.migration.mukurtu_cms_v3_terms_VOCAB.yml` | `id`, `label`, `source.bundle` (the Mukurtu 3 vocabulary), and the `vid` default value (the Mukurtu 4 vocabulary) |
| `config/optional/language.content_settings.taxonomy_term.VOCAB.yml` | The vocabulary under `dependencies.config`, plus `id` and `target_bundle` |

Set `cardinality` to the field's number of values: `1` for a single value, or `-1` for unlimited.

Then add the field to `FIELD_MIGRATIONS` in `src/Hook/MigrationPluginsAlterHooks.php`. The key is the field machine name and the value is the term migration's `id`:

```php
public const FIELD_MIGRATIONS = [
  'field_dialect' => 'mukurtu_cms_v3_terms_dialects',
];
```

## Install the module and migrate

Install the module before you run the migration.

1. Copy your module directory to `web/modules/custom/` on the Mukurtu 4 site.
2. Go to **Extend**, and install the module.
3. Run the Mukurtu 3 to 4 migration as usual. Your term migrations appear in the list with the standard ones, and they run before Digital Heritage.
4. Add your fields to the Digital Heritage form and display. Go to **Structure** > **Content types** > **Digital Heritage**, then **Manage form display** and **Manage display**.
5. Optional: add facets for your fields.

## Run the tests

The kernel tests cover every field in `FIELD_MIGRATIONS`, so they also test the fields you add. Run them from a Mukurtu 4 site with your module in `web/modules/custom/`:

```bash
./vendor/bin/phpunit --no-coverage web/modules/custom/custom_migrate/tests
```

If you renamed the module, use the new directory name in this command.

## How it behaves

- **Term IDs:** Terms keep their Mukurtu 3 term IDs, as in the standard Mukurtu term migrations.
- **Missing terms:** If a Mukurtu 3 item references a term that no longer exists, the migration creates an empty placeholder term in that vocabulary, as the standard Digital Heritage fields do.
- **Translations:** Translations of an item share its terms, and the terms themselves are translated. This matches Mukurtu's own Digital Heritage taxonomy fields.
- **New terms:** People can't create new terms from the Digital Heritage form. To allow it, set `auto_create` to `true` in the field's `field.field` file before installing, or change the field's settings later.
- **Uninstalling:** Uninstalling the module keeps the vocabularies, fields, and migrated content. To install the module again on the same site, delete that configuration first.
- **Scope:** The template only handles taxonomy fields on Digital Heritage. Other field types and content types need their own process plugins and migration changes.

## License

GPL-3.0-or-later. See [LICENSE.txt](LICENSE.txt).
