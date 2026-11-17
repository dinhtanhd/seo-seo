<?php

namespace App\Domain\Support;

use Illuminate\Support\Facades\DB;

/** Memoize schema probes within a request or queue job. */
final class SchemaAvailability
{
    private array $tables = [];

    public function hasTable(string $table): bool
    {
        $connection = DB::connection();
        $key = $connection->getName().':'.$connection->getDatabaseName().':'.$table;

        return $this->tables[$key] ??= $connection->getSchemaBuilder()->hasTable($table);
    }
}
