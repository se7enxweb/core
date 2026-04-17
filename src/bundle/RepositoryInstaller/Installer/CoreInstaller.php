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
        foreach ($queries as $query) {
            $this->db->exec($query);
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
