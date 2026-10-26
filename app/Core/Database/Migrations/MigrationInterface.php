<?php

declare(strict_types=1);

namespace CMS\Core\Database\Migrations;

use CMS\Core\Database\Connection;

/**
 * A single, ordered schema change.
 *
 * Migrations are plain objects that receive the Connection: they own raw DDL
 * and must not depend on repositories or application services.
 */
interface MigrationInterface
{
    public function up(Connection $connection): void;

    public function down(Connection $connection): void;
}
