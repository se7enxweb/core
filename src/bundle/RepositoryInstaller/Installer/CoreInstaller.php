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
    /**
     * Legacy tables the legacy kernel reaches through a Platform table, because the legacy
     * database handler rewrites the table name at query time, and whose legacy columns keep
     * their names there. Only the legacy columns the Platform table lacks are added to it.
     */
    private const array LEGACY_TABLES_ON_PLATFORM_TABLES = [
        'ezcontentbrowsebookmark' => 'ibexa_content_bookmark',
        'ezcontentobject_trash' => 'ibexa_content_trash',
    ];

    private const string LEGACY_COLUMN_PROBE_TABLE = 'exp_legacy_column_probe';

    /** @var \Ibexa\Contracts\DoctrineSchema\Builder\SchemaBuilderInterface */
    protected $schemaBuilder;

    /**
     * @param \Doctrine\DBAL\Connection $db
     * @param \Ibexa\Contracts\DoctrineSchema\Builder\SchemaBuilderInterface $schemaBuilder
     */
    public function __construct(Connection $db, SchemaBuilderInterface $schemaBuilder)
    {
        parent::__construct($db);

        $this->schemaBuilder = $schemaBuilder;
    }

    /**
     * Import Schema using event-driven Schema Builder API from Ibexa DoctrineSchema Bundle.
     *
     * If you wish to extend schema, implement your own EventSubscriber
     *
     * @see \Ibexa\Contracts\DoctrineSchema\Event\SchemaBuilderEvent
     * @see \Ibexa\Bundle\RepositoryInstaller\Event\Subscriber\BuildSchemaSubscriber
     *
     * @throws \Doctrine\DBAL\Exception
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
            $this->db->executeStatement($query);
            $progressBar->advance(1);
        }

        $progressBar->finish();
        // go to the next line after ProgressBar::finish and add one more extra blank line for readability
        $this->output->writeln(PHP_EOL);
        // clear any leftover progress bar parts in the output buffer
        $progressBar->clear();

        $this->importNetgenLayoutsSchema();
        $this->importLegacySchema();
    }

    /**
     * Load the DBMS-specific Netgen Layouts DDL and create the nglayouts_* tables.
     *
     * The package is looked up under both names it can be installed as:
     * se7enxweb/layouts-core (the fork, which replaces netgen/layouts-core)
     * and netgen/layouts-core (upstream). The first one that carries the
     * schema file for the current database platform is used. When neither
     * is installed the step is skipped, and the installer says so.
     */
    private function importNetgenLayoutsSchema(): void
    {
        $platform = $this->db->getDatabasePlatform();
        $vendorDir = \dirname(__DIR__, 6);

        if ($platform instanceof SqlitePlatform) {
            $relativeSchemaFile = 'tests/_fixtures/schema/schema.sqlite.sql';
        } elseif ($platform instanceof PostgreSQLPlatform) {
            $relativeSchemaFile = 'resources/data/schema.pgsql.sql';
        } else {
            $relativeSchemaFile = 'resources/data/schema.mysql.sql';
        }

        $candidateFiles = [
            $vendorDir . '/se7enxweb/layouts-core/' . $relativeSchemaFile,
            $vendorDir . '/netgen/layouts-core/' . $relativeSchemaFile,
        ];

        $schemaFile = null;
        foreach ($candidateFiles as $candidateFile) {
            if (\is_readable($candidateFile)) {
                $schemaFile = \realpath($candidateFile);
                break;
            }
        }

        if ($schemaFile === null) {
            $this->output->writeln(
                \sprintf(
                    '<comment>Netgen Layouts schema not imported: no layouts-core package found. Looked for %s</comment>',
                    \implode(' and ', $candidateFiles)
                )
            );

            return;
        }

        $this->output->writeln(
            \sprintf(
                '<info>Importing Netgen Layouts schema from <comment>%s</comment></info>',
                $schemaFile
            )
        );

        $this->runQueriesFromFile($schemaFile);

        if ($platform instanceof SqlitePlatform) {
            $seedFile = \dirname(__DIR__, 4) . '/data/sqlite/nglayouts_cleandata.sql';
            if (\is_readable($seedFile)) {
                $this->runQueriesFromFile(\realpath($seedFile));
            }
        }
    }

    /**
     * Load the DBMS-specific eZ Publish legacy kernel SQL schema.
     *
     * Creates legacy ez_* tables that exist in ezpublish_legacy/kernel/sql but
     * have no direct Ibexa DXP 5.x equivalent (e.g. ezbasket, ezdiscountrule).
     * Tables that map 1:1 to ibexa_* counterparts are created as lightweight stubs;
     * all legacy SQL queries against those are transparently redirected to their
     * ibexa_* equivalents by the sevenx_exponential_platform_v5_database_connections
     * extension at query time, so stub rows are never written to directly.
     *
     * Every CREATE TABLE statement is rewritten as CREATE TABLE IF NOT EXISTS so
     * that the installer is idempotent and sqlite_sequence (auto-created by SQLite
     * for AUTOINCREMENT tables) does not cause a duplicate-table error.
     *
     * Silently skipped if ezpublish_legacy/kernel/sql is not present.
     */
    private function importLegacySchema(): void
    {
        $platform = $this->db->getDatabasePlatform();
        // Project root is seven directory levels above __DIR__ when this package
        // is installed via Composer (vendor/se7enxweb/exponential-platform-dxp-core/
        // src/bundle/RepositoryInstaller/Installer).
        $projectDir = \dirname(__DIR__, 7);

        if ($platform instanceof SqlitePlatform) {
            $schemaFile = $projectDir . '/ezpublish_legacy/kernel/sql/sqlite/schema.sql';
        } elseif ($platform instanceof PostgreSQLPlatform) {
            $schemaFile = $projectDir . '/ezpublish_legacy/kernel/sql/postgresql/kernel_schema.sql';
        } else {
            $schemaFile = $projectDir . '/ezpublish_legacy/kernel/sql/mysql/kernel_schema.sql';
        }

        if (!\is_readable($schemaFile)) {
            return;
        }

        $schemaFile = \realpath($schemaFile);

        // Rewrite every CREATE TABLE → CREATE TABLE IF NOT EXISTS so that
        // re-running the installer does not fail on existing tables.
        $sql = \str_ireplace(
            'CREATE TABLE ',
            'CREATE TABLE IF NOT EXISTS ',
            \file_get_contents($schemaFile)
        );

        $queries = \array_filter(\preg_split('(;\s*$)m', $sql));

        // SQLite reserves the sqlite_sequence table for its own AUTOINCREMENT
        // bookkeeping.  The legacy sqlite/schema.sql lists it explicitly as a
        // plain CREATE TABLE statement, which errors even with IF NOT EXISTS
        // ("object name reserved for internal use").  Strip it out.
        $queries = \array_filter($queries, static function (string $q): bool {
            return \stripos(\trim($q), 'create table if not exists sqlite_sequence') === false;
        });

        if (!$this->output->isQuiet()) {
            $this->output->writeln(
                \sprintf(
                    '<info>Executing %d legacy schema queries from <comment>%s</comment> on database <comment>%s</comment></info>',
                    \count($queries),
                    $schemaFile,
                    $this->db->getDatabase()
                )
            );
        }

        $tablesBeforeImport = \array_map('strtolower', $this->db->createSchemaManager()->listTableNames());

        foreach ($queries as $query) {
            $this->db->executeStatement($query);
            $this->addMissingLegacyColumns($query, $tablesBeforeImport);
        }

        $this->importLegacyExtensionSchema();
    }

    /**
     * Give tables that already existed before the legacy schema import the columns the
     * legacy kernel's own CREATE TABLE has and they lack.
     *
     * Two cases are covered, on SQLite, PostgreSQL and MySQL alike:
     *
     * - The legacy table itself already existed. Its CREATE TABLE IF NOT EXISTS is then a
     *   no-op, and the legacy kernel's queries (and the legacy indexes that follow) would
     *   reference columns the table never got.
     * - The legacy kernel reaches the table through a Platform table at query time (the legacy
     *   database handler rewrites the table name, see LEGACY_TABLES_ON_PLATFORM_TABLES). The
     *   legacy table is only a stub then, and the Platform table has to carry the legacy
     *   columns, e.g. ezcontentbrowsebookmark.folder_id and priority on ibexa_content_bookmark.
     *
     * The legacy definition is read by creating it once under a probe name and reading the
     * columns back through the schema manager, so no dialect-specific DDL has to be parsed.
     * A column that is NOT NULL without a default is added as nullable, and AUTOINCREMENT is
     * never added to an existing table.
     *
     * @param string[] $tablesBeforeImport lower-case names of the tables that existed before the import
     *
     * @throws \Doctrine\DBAL\Exception
     */
    private function addMissingLegacyColumns(string $query, array $tablesBeforeImport): void
    {
        if (!\preg_match('/^(\s*CREATE TABLE IF NOT EXISTS\s+)([`"]?)(\w+)\2(?=\s*\()/i', $query, $match)) {
            return;
        }

        $legacyTable = \strtolower($match[3]);
        $targets = [];
        if (\in_array($legacyTable, $tablesBeforeImport, true)) {
            $targets[] = $legacyTable;
        }
        $platformTable = self::LEGACY_TABLES_ON_PLATFORM_TABLES[$legacyTable] ?? null;
        if ($platformTable !== null && \in_array($platformTable, $tablesBeforeImport, true)) {
            $targets[] = $platformTable;
        }
        if ($targets === []) {
            return;
        }

        $platform = $this->db->getDatabasePlatform();
        $schemaManager = $this->db->createSchemaManager();
        $probe = self::LEGACY_COLUMN_PROBE_TABLE;
        if ($schemaManager->tablesExist([$probe])) {
            $schemaManager->dropTable($probe);
        }

        $this->db->executeStatement(
            \substr_replace($query, $match[1] . $match[2] . $probe . $match[2], 0, \strlen($match[0]))
        );
        try {
            $legacyColumns = $schemaManager->listTableColumns($probe);
        } finally {
            $schemaManager->dropTable($probe);
        }

        foreach ($targets as $table) {
            $existing = [];
            foreach ($schemaManager->listTableColumns($table) as $column) {
                $existing[\strtolower($column->getName())] = true;
            }

            foreach ($legacyColumns as $column) {
                if (isset($existing[\strtolower($column->getName())])) {
                    continue;
                }

                $definition = $column->toArray();
                $definition['autoincrement'] = false;
                if ($column->getNotnull() && $column->getDefault() === null) {
                    $definition['notnull'] = false;
                }

                $this->db->executeStatement(\sprintf(
                    'ALTER TABLE %s ADD COLUMN %s',
                    $platform->quoteIdentifier($table),
                    $platform->getColumnDeclarationSQL($platform->quoteIdentifier($column->getName()), $definition)
                ));

                if (!$this->output->isQuiet()) {
                    $this->output->writeln(\sprintf(
                        '<info>Added legacy column <comment>%s</comment> to <comment>%s</comment></info>',
                        $column->getName(),
                        $table
                    ));
                }
            }
        }
    }

    /**
     * Load the full eZ Publish legacy extension SQL schema.
     *
     * Creates legacy ez_* tables used by extensions and admin modules (e.g.
     * ezinformationcollection_attribute, ezenumvalue) that are not in the
     * core kernel schema. The dump lives in ezpublish_legacy/share/ and is
     * a mysqldump of the full legacy table set.
     *
     * Silently skipped if ezpublish_legacy/share/legacy_schema.sql is not present.
     *
     * The dump is MySQL syntax (AUTO_INCREMENT, ENGINE=, /*!...*\/ blocks), so it only runs on
     * MySQL and MariaDB; SQLite and PostgreSQL get the legacy tables from the kernel schema of
     * their own platform (importLegacyKernelSchema above).
     */
    private function importLegacyExtensionSchema(): void
    {
        $platform = $this->db->getDatabasePlatform();
        if ($platform instanceof SqlitePlatform || $platform instanceof PostgreSQLPlatform) {
            return;
        }

        $projectDir = \dirname(__DIR__, 7);
        $schemaFile = $projectDir . '/ezpublish_legacy/share/legacy_schema.sql';

        if (!\is_readable($schemaFile)) {
            return;
        }

        $schemaFile = \realpath($schemaFile);

        $sql = \file_get_contents($schemaFile);

        // The dump contains DROP TABLE IF EXISTS statements; they must not run
        // because the database already contains data. Strip them and use IF NOT EXISTS.
        $sql = \preg_replace('/^DROP TABLE IF EXISTS `[^`]+`;\s*$/m', '', $sql);
        $sql = \str_ireplace('CREATE TABLE `', 'CREATE TABLE IF NOT EXISTS `', $sql);

        $queries = \array_filter(\preg_split('/;\s*$/m', $sql));

        if (!$this->output->isQuiet()) {
            $this->output->writeln(
                \sprintf(
                    '<info>Executing %d legacy extension schema queries from <comment>%s</comment> on database <comment>%s</comment></info>',
                    \count($queries),
                    $schemaFile,
                    $this->db->getDatabase()
                )
            );
        }

        foreach ($queries as $query) {
            $query = \trim($query);
            if ($query === '') {
                continue;
            }
            $this->db->executeStatement($query);
        }
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     * @throws \Ibexa\Contracts\Core\Repository\Exceptions\InvalidArgumentException
     */
    public function importData()
    {
        $this->runQueriesFromFile($this->getKernelSQLFileForDBMS('cleandata.sql'));
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
}
