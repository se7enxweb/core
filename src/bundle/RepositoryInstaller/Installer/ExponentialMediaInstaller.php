<?php

/**
 * @copyright Copyright (C) Exponential Platform Contributors. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);

namespace Ibexa\Bundle\RepositoryInstaller\Installer;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

/**
 * Installer for the Exponential Platform "exponential-media" installation type.
 *
 * Provides a cross-DBMS (MySQL/MariaDB, PostgreSQL, SQLite) alternative to the
 * MySQL-only "netgen-media" installer. The full schema is built via Doctrine's
 * SchemaBuilder (inherited from CoreInstaller), and platform-specific seed data
 * is loaded from data/{mysql,sqlite,postgresql}/media_data.sql.
 *
 * Usage:
 *   bin/console exponential:install exponential-media
 */
class ExponentialMediaInstaller extends CoreInstaller
{
    private string $projectDir;

    private string $storagePath;

    public function setProjectDir(string $projectDir): void
    {
        $this->projectDir = $projectDir;
    }

    public function setStoragePath(string $storagePath): void
    {
        $this->storagePath = $storagePath;
    }

    /**
     * Import the media-site demo data using the DBMS-appropriate SQL file.
     *
     * Replaces the base CoreInstaller::importData() (which loads cleandata.sql)
     * with the richer media_data.sql that includes layouts, content, tags and
     * nglayouts configuration for the Netgen Media site demo.
     *
     * @throws \Doctrine\DBAL\Exception
     * @throws \Ibexa\Contracts\Core\Repository\Exceptions\InvalidArgumentException
     */
    public function importSchema(): void
    {
        parent::importSchema();
        $this->runQueriesFromFile($this->getKernelSQLFileForDBMS('media_schema.sql'));
    }

    public function importData(): void
    {
        $this->runQueriesFromFile($this->getKernelSQLFileForDBMS('media_data.sql'));
    }

    /**
     * Copy the demo storage directory (images, files, …) from the vendor package
     * to the project's public storage path.
     *
     * Silently skipped when the source directory does not exist or when the target
     * already contains files (idempotent re-install safety).
     */
    public function importBinaries(): void
    {
        $source = $this->projectDir . '/vendor/netgen/media-site-data/netgen-media/storage';

        if (!\is_dir($source)) {
            return;
        }

        $target = $this->projectDir . '/' . \ltrim($this->storagePath, '/');
        $fs = new Filesystem();

        if ($fs->exists($target)) {
            $finder = (new Finder())
                ->ignoreDotFiles(false)
                ->ignoreVCS(false)
                ->ignoreUnreadableDirs(false)
                ->in($target);

            if ($finder->count() > 0) {
                $this->output->writeln(
                    \sprintf(
                        '<comment>Storage directory <info>%s</info> already exists and is not empty, skipping creation...</comment>',
                        $target,
                    ),
                );

                return;
            }

            $fs->remove($target);
        }

        $this->output->writeln(
            \sprintf('Copying storage directory to <info>%s</info>', $target),
        );

        $fs->mirror($source, $target);
    }
}
