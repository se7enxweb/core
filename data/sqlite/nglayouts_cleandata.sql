-- Netgen Layouts seed data for SQLite.
-- The SQLite schema fixture (tests/_fixtures/schema/schema.sqlite.sql) ships DDL only.
-- This file provides the required seed rows and the nglayouts_migration_versions table
-- that are present in the MySQL/PostgreSQL schema files but absent from the SQLite fixture.

CREATE TABLE IF NOT EXISTS `nglayouts_migration_versions` (
  `version` varchar(191) NOT NULL,
  `executed_at` datetime DEFAULT NULL,
  `execution_time` integer DEFAULT NULL,
  PRIMARY KEY (`version`)
);

INSERT OR IGNORE INTO `nglayouts_migration_versions` (`version`, `executed_at`, `execution_time`) VALUES ('Netgen\Layouts\Migrations\Doctrine\Version000700', datetime('now'), NULL);
INSERT OR IGNORE INTO `nglayouts_migration_versions` (`version`, `executed_at`, `execution_time`) VALUES ('Netgen\Layouts\Migrations\Doctrine\Version000800', datetime('now'), NULL);
INSERT OR IGNORE INTO `nglayouts_migration_versions` (`version`, `executed_at`, `execution_time`) VALUES ('Netgen\Layouts\Migrations\Doctrine\Version000900', datetime('now'), NULL);
INSERT OR IGNORE INTO `nglayouts_migration_versions` (`version`, `executed_at`, `execution_time`) VALUES ('Netgen\Layouts\Migrations\Doctrine\Version001000', datetime('now'), NULL);
INSERT OR IGNORE INTO `nglayouts_migration_versions` (`version`, `executed_at`, `execution_time`) VALUES ('Netgen\Layouts\Migrations\Doctrine\Version001100', datetime('now'), NULL);
INSERT OR IGNORE INTO `nglayouts_migration_versions` (`version`, `executed_at`, `execution_time`) VALUES ('Netgen\Layouts\Migrations\Doctrine\Version001200', datetime('now'), NULL);
INSERT OR IGNORE INTO `nglayouts_migration_versions` (`version`, `executed_at`, `execution_time`) VALUES ('Netgen\Layouts\Migrations\Doctrine\Version001300', datetime('now'), NULL);
INSERT OR IGNORE INTO `nglayouts_migration_versions` (`version`, `executed_at`, `execution_time`) VALUES ('Netgen\Layouts\Migrations\Doctrine\Version010000', datetime('now'), NULL);
INSERT OR IGNORE INTO `nglayouts_migration_versions` (`version`, `executed_at`, `execution_time`) VALUES ('Netgen\Layouts\Migrations\Doctrine\Version010200', datetime('now'), NULL);
INSERT OR IGNORE INTO `nglayouts_migration_versions` (`version`, `executed_at`, `execution_time`) VALUES ('Netgen\Layouts\Migrations\Doctrine\Version010300', datetime('now'), NULL);

INSERT OR IGNORE INTO `nglayouts_rule_group` (`id`, `status`, `uuid`, `depth`, `path`, `parent_id`, `name`, `description`) VALUES (1, 1, '00000000-0000-0000-0000-000000000000', 0, '/1/', NULL, 'Root', '');

INSERT OR IGNORE INTO `nglayouts_rule_group_data` (`rule_group_id`, `enabled`, `priority`) VALUES (1, 1, 0);
