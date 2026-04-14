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

        foreach ($queries as $query) {
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
