<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);

namespace Ibexa\Bundle\RepositoryInstaller\Installer;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Ibexa\Contracts\DoctrineSchema\Builder\SchemaBuilderInterface;
use Ibexa\DoctrineSchema\Database\DbPlatform\SqliteDbPlatform;
use Symfony\Component\Console\Helper\ProgressBar;

/**
 * Installer which uses SchemaBuilder.
 */
class CoreInstaller extends DbBasedInstaller implements Installer
{
    /** @var \Ibexa\Contracts\DoctrineSchema\Builder\SchemaBuilderInterface */
    protected $schemaBuilder;

    private string $projectDir;

    /**
     * @param \Doctrine\DBAL\Connection $db
     * @param \Ibexa\Contracts\DoctrineSchema\Builder\SchemaBuilderInterface $schemaBuilder
     * @param string $projectDir Kernel project directory (for locating ezpublish_legacy)
     */
    public function __construct(Connection $db, SchemaBuilderInterface $schemaBuilder, string $projectDir = '')
    {
        parent::__construct($db);

        $this->schemaBuilder = $schemaBuilder;
        $this->projectDir = $projectDir;
    }

    /**
     * Import Schema using event-driven Schema Builder API from Ibexa DoctrineSchema Bundle.
     *
     * If you wish to extend schema, implement your own EventSubscriber
     *
     * @see \Ibexa\Contracts\DoctrineSchema\Event\SchemaBuilderEvent
     * @see \Ibexa\Bundle\RepositoryInstaller\Event\Subscriber\BuildSchemaSubscriber
     *
     * @throws \Doctrine\DBAL\DBALException
     */
    public function importSchema()
    {
        // note: schema is built using Schema Builder event-driven API
        $schema = $this->schemaBuilder->buildSchema();
        $databasePlatform = $this->db->getDatabasePlatform();

        // SQLite: substitute SqliteDbPlatform so composite-PK tables don't get
        // AUTOINCREMENT on non-integer columns (SQLite doesn't support that).
        if ($databasePlatform instanceof SqlitePlatform && !($databasePlatform instanceof SqliteDbPlatform)) {
            $databasePlatform = new SqliteDbPlatform();
        }

        $queries = array_merge(
            $this->getDropSqlStatementsForExistingSchema($schema, $databasePlatform),
            // generate schema DDL queries
            $schema->toSql($databasePlatform)
        );

        $queriesCount = count($queries);
        $this->output->writeln(
            sprintf(
                '<info>Executing %d queries on database <comment>%s</comment> (<comment>%s</comment>)</info>',
                $queriesCount,
                $this->db->getDatabase(),
                $databasePlatform->getName()
            )
        );
        $progressBar = new ProgressBar($this->output);
        $progressBar->start($queriesCount);

        foreach ($queries as $query) {
            $this->db->exec($query);
            $progressBar->advance(1);
        }

        $progressBar->finish();
        // go to the next line after ProgressBar::finish and add one more extra blank line for readability
        $this->output->writeln(PHP_EOL);
        // clear any leftover progress bar parts in the output buffer
        $progressBar->clear();

        $this->importNetgenLayoutsSchema();
        $this->importLegacyKernelSchema();
    }

    /**
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Ibexa\Contracts\Core\Repository\Exceptions\InvalidArgumentException
     */
    public function importData()
    {
        $this->runQueriesFromFile($this->getKernelSQLFileForDBMS('cleandata.sql'));
        $this->importLegacyKernelData();

        // One published version per content item. The legacy kernel and the Platform use the same
        // version statuses (0 draft, 1 published, 2 pending, 3 archived, 5 internal draft): a
        // published version that is not the content item's current version (one the legacy seed
        // data brought in) is an archived one. The current version stays published.
        $this->db->exec(
            'UPDATE ezcontentobject_version SET status = 3'
            . ' WHERE status = 1'
            . ' AND version <> (SELECT current_version FROM ezcontentobject WHERE id = contentobject_id)'
        );

        // Fix ezxmltext data_text values: legacy SQL dumps store newlines as the two-character
        // sequence backslash + n (literal \n) rather than as the real newline character (0x0A).
        // PHP's DOMDocument::loadXML() rejects the literal \n between the XML declaration and
        // the root element with "Start tag expected, '<' not found", so we normalise all stored
        // ezxmltext data_text values to use real newlines after import.
        $this->db->exec(
            "UPDATE ezcontentobject_attribute"
            . " SET data_text = REPLACE(data_text, '\\n', CHAR(10))"
            . " WHERE data_type_string = 'ezxmltext'"
        );

        // Remove any SiteAccess limitations — they contain CRC32 hashes of siteaccess
        // names specific to the original eZ Publish demo install and would block
        // anonymous login on any other siteaccess name.
        $this->db->exec(
            'DELETE FROM ezpolicy_limitation_value WHERE limitation_id IN'
            . ' (SELECT id FROM ezpolicy_limitation WHERE identifier = \'SiteAccess\')'
        );
        $this->db->exec("DELETE FROM ezpolicy_limitation WHERE identifier = 'SiteAccess'");
    }

    /**
     * @param \Doctrine\DBAL\Schema\Schema $newSchema
     * @param \Doctrine\DBAL\Platforms\AbstractPlatform $databasePlatform
     *
     * @return string[]
     */
    protected function getDropSqlStatementsForExistingSchema(
        Schema $newSchema,
        AbstractPlatform $databasePlatform
    ): array {
        $existingSchema = $this->db->getSchemaManager()->createSchema();
        $statements = [];
        // reverse table order for clean-up (due to FKs)
        $tables = array_reverse($newSchema->getTables());
        // cleanup pre-existing database
        foreach ($tables as $table) {
            if ($existingSchema->hasTable($table->getName())) {
                $statements[] = $databasePlatform->getDropTableSQL($table);
            }
        }

        return $statements;
    }

    /**
     * Handle optional import of binary files to var folder.
     */
    public function importBinaries()
    {
    }

    /**
     * {@inheritdoc}
     */
    public function createConfiguration()
    {
    }

    /**
     * Import legacy eZ Publish kernel SQLite tables (CREATE TABLE IF NOT EXISTS).
     *
     * Only runs on SQLite. Uses IF NOT EXISTS to skip tables already created by
     * the Ibexa SchemaBuilder. Also imports ezflow schema when available.
     * Silently skipped when ezpublish_legacy is not present or on non-SQLite platforms.
     */
    private function importLegacyKernelSchema(): void
    {
        if (!$this->db->getDatabasePlatform() instanceof SqlitePlatform || empty($this->projectDir)) {
            return;
        }

        $legacyDir = $this->projectDir . '/ezpublish_legacy';

        $schemaFile = $legacyDir . '/kernel/sql/sqlite/schema.sql';
        if (\is_readable($schemaFile)) {
            $this->output->writeln('<info>Importing legacy kernel schema...</info>');
            $sql = \file_get_contents($schemaFile);
            // Avoid "table already exists" for tables created by Ibexa SchemaBuilder
            $sql = \preg_replace('/\bCREATE TABLE\b/', 'CREATE TABLE IF NOT EXISTS', $sql);
            $queries = \array_filter(\preg_split('(;\s*$)m', $sql));
            foreach ($queries as $query) {
                // sqlite_sequence is an internal SQLite table; skip any reference to it
                if (\stripos($query, 'sqlite_sequence') !== false) {
                    continue;
                }
                $this->addMissingLegacyColumns($query);
                $this->db->exec($query);
            }
        }

        // ezflow extension schema (if present)
        $ezflowSchema = $legacyDir . '/extension/ezflow/sql/sqlite/sqlite.sql';
        if (\is_readable($ezflowSchema)) {
            $this->output->writeln('<info>Importing ezflow schema...</info>');
            $sql = \file_get_contents($ezflowSchema);
            $sql = \preg_replace('/\bCREATE TABLE\b/', 'CREATE TABLE IF NOT EXISTS', $sql);
            $queries = \array_filter(\preg_split('(;\s*$)m', $sql));
            foreach ($queries as $query) {
                if (\stripos($query, 'sqlite_sequence') !== false) {
                    continue;
                }
                $this->db->exec($query);
            }
        }
    }

    /**
     * A legacy CREATE TABLE for a table the Ibexa SchemaBuilder already created is skipped by
     * IF NOT EXISTS, so columns only the legacy kernel has (ezcontentobject_trash.trashed_by and
     * trashed_via, for one) would be missing, and the legacy indexes on them fail. Add each such
     * column to the existing table first. SQLite's ADD COLUMN takes no PRIMARY KEY and needs a
     * default for NOT NULL, so those parts are left out where they cannot apply.
     */
    private function addMissingLegacyColumns(string $query): void
    {
        if (!\preg_match('/^\s*CREATE TABLE IF NOT EXISTS\s+[`"]?(\w+)[`"]?\s*\(/i', $query, $table)) {
            return;
        }
        $existing = \array_map(
            'strtolower',
            \array_column($this->db->fetchAllAssociative('PRAGMA table_info("' . $table[1] . '")'), 'name')
        );
        if ($existing === []) {
            return;
        }
        // one column per line: "  `name` type ..." or ",  `name` type ..."; constraints are not quoted
        \preg_match_all('/^\s*,?\s*`(\w+)`\s+(.+?)\s*$/m', $query, $columns, \PREG_SET_ORDER);
        foreach ($columns as [, $name, $definition]) {
            if (\in_array(\strtolower($name), $existing, true)) {
                continue;
            }
            $definition = \trim(\preg_replace('/\b(PRIMARY KEY|AUTOINCREMENT)\b/i', '', \rtrim($definition, ',')));
            if (\stripos($definition, 'NOT NULL') !== false && \stripos($definition, 'DEFAULT') === false) {
                $definition = \trim(\preg_replace('/\bNOT NULL\b/i', '', $definition));
            }
            $this->db->exec('ALTER TABLE "' . $table[1] . '" ADD COLUMN `' . $name . '` ' . $definition);
        }
    }

    /**
     * Import legacy eZ Publish kernel seed data.
     *
     * Runs AFTER our cleandata.sql so that our customised rows take precedence.
     * Uses INSERT OR IGNORE INTO so duplicate primary keys (shared tables already
     * seeded by our cleandata.sql) are silently skipped.
     * Silently skipped when ezpublish_legacy is not present or on non-SQLite platforms.
     */
    private function importLegacyKernelData(): void
    {
        if (!$this->db->getDatabasePlatform() instanceof SqlitePlatform || empty($this->projectDir)) {
            return;
        }

        $cleanDataFile = $this->projectDir . '/ezpublish_legacy/kernel/sql/sqlite/cleandata.sql';
        if (!\is_readable($cleanDataFile)) {
            return;
        }

        $this->output->writeln('<info>Importing legacy kernel seed data...</info>');
        $sql = \file_get_contents($cleanDataFile);
        // The legacy cleandata uses MySQL backslash-escape conventions inside single-quoted
        // string literals: \" means literal double-quote, \' means literal single-quote.
        // MySQL strips the backslash at import time; SQLite stores it verbatim, corrupting
        // PHP serialized values and splitting strings prematurely on \'.
        // Unescape both sequences to SQLite-compatible forms before executing.
        $sql = \str_replace('\\"', '"', $sql);    // \" → "
        $sql = \str_replace("\\'", "''", $sql);   // \' → '' (SQLite single-quote escape)
        // Skip rows that conflict with seed data already inserted by our cleandata.sql
        $sql = \preg_replace('/\bINSERT INTO\b/', 'INSERT OR IGNORE INTO', $sql);
        $queries = \array_filter(\preg_split('(;\s*$)m', $sql));

        // The legacy seed data has its own copies of the content items our cleandata.sql seeded
        // (content 1 version 6, Media's fields in eng-US, a language this installation does not
        // have). Rows of them that do not collide with a primary key are added by INSERT OR IGNORE
        // all the same, and the Platform admin cannot load them: its versions tab failed with
        // "Could not find 'Content' ... 'versionNo' => 6". Every row the legacy import adds to a
        // content item cleandata.sql seeded is removed again: versions and links that were not
        // seeded, and fields and names added after the seeded ones (rowid beyond the seeded rows).
        $this->db->exec('DROP TABLE IF EXISTS temp.exp_seeded_versions');
        $this->db->exec(
            'CREATE TEMP TABLE exp_seeded_versions AS'
            . ' SELECT contentobject_id, version FROM ezcontentobject_version'
        );
        $lastSeededRow = [];
        foreach (['ezcontentobject_attribute', 'ezcontentobject_name'] as $table) {
            $lastSeededRow[$table] = (int)$this->db->fetchOne('SELECT COALESCE(MAX(rowid), 0) FROM ' . $table);
        }

        foreach ($queries as $query) {
            $this->db->exec($query);
        }

        $seededContent = ' IN (SELECT DISTINCT contentobject_id FROM temp.exp_seeded_versions)';
        $notSeededVersion = ' AND NOT EXISTS (SELECT 1 FROM temp.exp_seeded_versions s'
            . ' WHERE s.contentobject_id = %1$s.%2$s AND s.version = %1$s.%3$s)';
        foreach ([
            ['ezcontentobject_link', 'from_contentobject_id', 'from_contentobject_version'],
            ['ezcontentobject_version', 'contentobject_id', 'version'],
        ] as [$table, $contentColumn, $versionColumn]) {
            $this->db->exec(
                'DELETE FROM ' . $table . ' WHERE ' . $contentColumn . $seededContent
                . \sprintf($notSeededVersion, $table, $contentColumn, $versionColumn)
            );
        }
        foreach ($lastSeededRow as $table => $lastRow) {
            $this->db->exec(
                'DELETE FROM ' . $table . ' WHERE contentobject_id' . $seededContent . ' AND rowid > ' . $lastRow
            );
        }
        $this->db->exec('DROP TABLE temp.exp_seeded_versions');

        $this->renameLegacySeedLanguage();
    }

    /**
     * The legacy seed data is written in eng-US, which it numbers language 2; cleandata.sql
     * seeds language 2 as eng-GB, and INSERT OR IGNORE keeps that row. The content items and
     * content types only the legacy seed has (Setup, Design, Common INI settings ...) then
     * carry language mask 2 but eng-US field, name and translation rows, and the Platform admin
     * failed on them with 'Undefined array key "eng-GB"'. Their eng-US rows are renamed to the
     * locale of language 2, the language their masks name.
     */
    private function renameLegacySeedLanguage(): void
    {
        $legacyLocale = 'eng-US';
        $locale = $this->db->fetchOne('SELECT locale FROM ezcontent_language WHERE id = 2');
        if (!\is_string($locale) || $locale === '' || $locale === $legacyLocale) {
            return;
        }
        if ($this->db->fetchOne('SELECT COUNT(*) FROM ezcontent_language WHERE locale = ?', [$legacyLocale]) > 0) {
            return;
        }

        foreach ([
            ['ezcontentobject_attribute', 'language_code'],
            ['ezcontentobject_name', 'content_translation'],
            ['ezcontentobject_name', 'real_translation'],
            ['ezcontentclass_name', 'language_locale'],
        ] as [$table, $column]) {
            $this->db->executeStatement(
                'UPDATE OR IGNORE ' . $table . ' SET ' . $column . ' = ? WHERE ' . $column . ' = ?',
                [$locale, $legacyLocale]
            );
        }

        // Serialized name and description lists are keyed by locale: s:6:"eng-US"
        $from = 's:' . \strlen($legacyLocale) . ':"' . $legacyLocale . '"';
        $to = 's:' . \strlen($locale) . ':"' . $locale . '"';
        foreach ([
            ['ezcontentclass', 'serialized_name_list'],
            ['ezcontentclass', 'serialized_description_list'],
            ['ezcontentclass_attribute', 'serialized_name_list'],
            ['ezcontentclass_attribute', 'serialized_description_list'],
        ] as [$table, $column]) {
            $this->db->executeStatement(
                'UPDATE ' . $table . ' SET ' . $column . ' = REPLACE(' . $column . ', ?, ?)'
                . ' WHERE ' . $column . ' LIKE ?',
                [$from, $to, '%' . $from . '%']
            );
        }
    }

    /**
     * Load the DBMS-specific Netgen Layouts DDL and create the nglayouts_* tables.
     *
     * Silently skipped if netgen/layouts-core is not installed.
     */
    private function importNetgenLayoutsSchema(): void
    {
        $platform = $this->db->getDatabasePlatform();
        $vendorDir = \dirname(__DIR__, 6);

        if ($platform instanceof SqlitePlatform) {
            $schemaFile = $vendorDir . '/netgen/layouts-core/tests/_fixtures/schema/schema.sqlite.sql';
        } elseif ($platform instanceof PostgreSQLPlatform) {
            $schemaFile = $vendorDir . '/netgen/layouts-core/resources/data/schema.pgsql.sql';
        } else {
            $schemaFile = $vendorDir . '/netgen/layouts-core/resources/data/schema.mysql.sql';
        }

        if (!\is_readable($schemaFile)) {
            return;
        }

        $this->runQueriesFromFile(\realpath($schemaFile));

        if ($platform instanceof SqlitePlatform) {
            $seedFile = \dirname(__DIR__, 4) . '/data/sqlite/nglayouts_cleandata.sql';
            if (\is_readable($seedFile)) {
                $this->runQueriesFromFile(\realpath($seedFile));
            }
        }
    }
}

class_alias(CoreInstaller::class, 'EzSystems\PlatformInstallerBundle\Installer\CoreInstaller');
