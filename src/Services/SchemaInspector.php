<?php

namespace TarunKorat\LaravelMigrationSquasher\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use TarunKorat\LaravelMigrationSquasher\Contracts\SchemaInspectorInterface;

class SchemaInspector implements SchemaInspectorInterface
{
    protected string $connection;
    protected bool $useDbal;

    public function __construct(?string $connection = null)
    {
        $this->connection = $connection ?? config('database.default');
        $this->useDbal = $this->isDbalAvailable();
    }

    /**
     * Check if doctrine/dbal is available.
     */
    public function isDbalAvailable(): bool
    {
        $connection = DB::connection($this->connection);
        return method_exists($connection, 'getDoctrineSchemaManager') &&
            class_exists(\Doctrine\DBAL\DriverManager::class);
    }

    /**
     * Get Doctrine Schema Manager (compatible with Laravel 8–12).
     */
    protected function getSchemaManager()
    {
        $connection = DB::connection($this->connection);

        if (method_exists($connection, 'getDoctrineSchemaManager')) {
            return $connection->getDoctrineSchemaManager();
        }

        throw new \RuntimeException('Unable to resolve Doctrine Schema Manager for this Laravel version.');
    }

    /**
     * Get all table names from database.
     */
    public function getTableNames(): array
    {
        if ($this->useDbal) {
            return $this->getTableNamesUsingDbal();
        }

        return $this->getTableNamesUsingNative();
    }

    /**
     * Get table names using Doctrine DBAL.
     */
    protected function getTableNamesUsingDbal(): array
    {
        try {
            $schemaManager = $this->getSchemaManager();
            return $schemaManager->listTableNames();
        } catch (\Throwable $e) {
            return $this->getTableNamesUsingNative();
        }
    }

    /**
     * Get table names using native Laravel.
     */
    protected function getTableNamesUsingNative(): array
    {
        $driver = DB::connection($this->connection)->getDriverName();

        switch ($driver) {
            case 'mysql':
                $database = DB::connection($this->connection)->getDatabaseName();
                $tables = DB::connection($this->connection)
                    ->select("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?", [$database]);
                return collect($tables)->pluck('TABLE_NAME')->toArray();

            case 'pgsql':
                $tables = DB::connection($this->connection)
                    ->select("SELECT tablename FROM pg_tables WHERE schemaname = 'public'");
                return collect($tables)->pluck('tablename')->toArray();

            case 'sqlite':
                $tables = DB::connection($this->connection)
                    ->select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
                return collect($tables)->pluck('name')->toArray();

            case 'sqlsrv':
                $tables = DB::connection($this->connection)
                    ->select("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE'");
                return collect($tables)->pluck('TABLE_NAME')->toArray();

            default:
                throw new \RuntimeException("Unsupported database driver: {$driver}");
        }
    }

    /**
     * Get columns for a specific table with enhanced metadata.
     */
    public function getTableColumns(string $table): array
    {
        if ($this->useDbal) {
            return $this->getTableColumnsUsingDbal($table);
        }

        return $this->getTableColumnsUsingNative($table);
    }

    /**
     * Get table columns using Doctrine DBAL with enhanced metadata.
     */
    protected function getTableColumnsUsingDbal(string $table): array
    {
        $schemaManager = $this->getSchemaManager();
        $columns = $schemaManager->listTableColumns($table);
        $indexes = $this->getTableIndexes($table);

        $definitions = [];
        foreach ($columns as $column) {
            $columnName = $column->getName();

            // Check if column has unique index
            $hasUniqueIndex = $this->columnHasUniqueIndex($columnName, $indexes);

            $columnDef = [
                'name' => $columnName,
                'type' => $column->getType()->getName(),
                'type_definition' => null,
                'length' => $column->getLength(),
                'precision' => $column->getPrecision(),
                'scale' => $column->getScale(),
                'nullable' => !$column->getNotnull(),
                'default' => $column->getDefault(),
                'unsigned' => method_exists($column, 'getUnsigned') ? $column->getUnsigned() : false,
                'autoincrement' => $column->getAutoincrement(),
                'comment' => $column->getComment(),
                'unique' => $hasUniqueIndex,
                'table_name' => $table,
            ];

            // Try to get enum values from database directly
            if (in_array($columnDef['type'], ['enum', 'set'])) {
                $columnDef['type_definition'] = $this->getColumnTypeDefinition($table, $columnName);
            }

            $definitions[] = $columnDef;
        }

        return $definitions;
    }

    /**
     * Get table columns using native Laravel Schema with enhanced metadata.
     */
    protected function getTableColumnsUsingNative(string $table): array
    {
        $columns = Schema::connection($this->connection)->getColumns($table);
        $indexes = $this->getTableIndexes($table);
        $definitions = [];

        foreach ($columns as $column) {
            $columnName = $column['name'];
            $hasUniqueIndex = $this->columnHasUniqueIndex($columnName, $indexes);

            $columnDef = [
                'name' => $columnName,
                'type' => $column['type_name'] ?? $column['type'],
                'length' => $column['length'] ?? null,
                'precision' => $column['precision'] ?? null,
                'scale' => $column['scale'] ?? null,
                'nullable' => $column['nullable'],
                'default' => $column['default'] ?? null,
                'unsigned' => $column['unsigned'] ?? false,
                'autoincrement' => $column['auto_increment'] ?? false,
                'comment' => $column['comment'] ?? null,
                'unique' => $hasUniqueIndex,
                'table_name' => $table,
            ];

            // Try to get enum values from database directly
            if (in_array($columnDef['type'], ['enum', 'set'])) {
                $columnDef['type_definition'] = $this->getColumnTypeDefinition($table, $columnName);
            }

            $definitions[] = $columnDef;
        }

        return $definitions;
    }

    /**
     * Check if a column has a unique index.
     */
    protected function columnHasUniqueIndex(string $columnName, array $indexes): bool
    {
        foreach ($indexes as $index) {
            if (($index['unique'] ?? false) &&
                count($index['columns']) === 1 &&
                $index['columns'][0] === $columnName) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get column type definition (for enum/set).
     */
    protected function getColumnTypeDefinition(string $table, string $column): ?string
    {
        $driver = DB::connection($this->connection)->getDriverName();

        try {
            switch ($driver) {
                case 'mysql':
                    $database = DB::connection($this->connection)->getDatabaseName();
                    $result = DB::connection($this->connection)
                        ->select(
                            "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
                             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?",
                            [$database, $table, $column]
                        );

                    return $result[0]->COLUMN_TYPE ?? null;

                case 'pgsql':
                    // PostgreSQL enum handling
                    $result = DB::connection($this->connection)
                        ->select(
                            "SELECT udt_name FROM information_schema.columns
                             WHERE table_name = ? AND column_name = ?",
                            [$table, $column]
                        );

                    return $result[0]->udt_name ?? null;

                default:
                    return null;
            }
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get indexes for a specific table.
     */
    public function getTableIndexes(string $table): array
    {
        if ($this->useDbal) {
            return $this->getTableIndexesUsingDbal($table);
        }

        return $this->getTableIndexesUsingNative($table);
    }

    /**
     * Get table indexes using Doctrine DBAL.
     */
    protected function getTableIndexesUsingDbal(string $table): array
    {
        $schemaManager = $this->getSchemaManager();
        $indexes = $schemaManager->listTableIndexes($table);

        $indexDefinitions = [];
        foreach ($indexes as $index) {
            $indexDefinitions[] = [
                'name' => $index->getName(),
                'columns' => $index->getColumns(),
                'unique' => $index->isUnique(),
                'primary' => $index->isPrimary(),
            ];
        }

        return $indexDefinitions;
    }

    /**
     * Get table indexes using native Laravel Schema.
     */
    protected function getTableIndexesUsingNative(string $table): array
    {
        $indexes = Schema::connection($this->connection)->getIndexes($table);
        $indexDefinitions = [];

        foreach ($indexes as $index) {
            $indexDefinitions[] = [
                'name' => $index['name'],
                'columns' => $index['columns'],
                'unique' => $index['unique'] ?? false,
                'primary' => $index['primary'] ?? false,
            ];
        }

        return $indexDefinitions;
    }

    /**
     * Get foreign keys for a specific table.
     */
    public function getTableForeignKeys(string $table): array
    {
        if ($this->useDbal) {
            return $this->getTableForeignKeysUsingDbal($table);
        }

        return $this->getTableForeignKeysUsingNative($table);
    }

    /**
     * Get table foreign keys using Doctrine DBAL.
     */
    protected function getTableForeignKeysUsingDbal(string $table): array
    {
        $schemaManager = $this->getSchemaManager();
        $foreignKeys = $schemaManager->listTableForeignKeys($table);

        $fkDefinitions = [];
        foreach ($foreignKeys as $fk) {
            $fkDefinitions[] = [
                'name' => $fk->getName(),
                'local_columns' => $fk->getLocalColumns(),
                'foreign_table' => $fk->getForeignTableName(),
                'foreign_columns' => $fk->getForeignColumns(),
                'on_update' => $fk->hasOption('onUpdate') ? $fk->getOption('onUpdate') : null,
                'on_delete' => $fk->hasOption('onDelete') ? $fk->getOption('onDelete') : null,
            ];
        }

        return $fkDefinitions;
    }

    /**
     * Get table foreign keys using native Laravel Schema.
     */
    protected function getTableForeignKeysUsingNative(string $table): array
    {
        $foreignKeys = Schema::connection($this->connection)->getForeignKeys($table);
        $fkDefinitions = [];

        foreach ($foreignKeys as $fk) {
            $fkDefinitions[] = [
                'name' => $fk['name'] ?? null,
                'local_columns' => $fk['columns'],
                'foreign_table' => $fk['foreign_table'],
                'foreign_columns' => $fk['foreign_columns'],
                'on_update' => $fk['on_update'] ?? null,
                'on_delete' => $fk['on_delete'] ?? null,
            ];
        }

        return $fkDefinitions;
    }
}
