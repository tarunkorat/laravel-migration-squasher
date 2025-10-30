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
            // Fallback to native if Doctrine is not supported
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
     * Get columns for a specific table.
     */
    public function getTableColumns(string $table): array
    {
        if ($this->useDbal) {
            return $this->getTableColumnsUsingDbal($table);
        }

        return $this->getTableColumnsUsingNative($table);
    }

    /**
     * Get table columns using Doctrine DBAL.
     */
    protected function getTableColumnsUsingDbal(string $table): array
    {
        $schemaManager = $this->getSchemaManager();
        $columns = $schemaManager->listTableColumns($table);

        $definitions = [];
        foreach ($columns as $column) {
            $definitions[] = [
                'name' => $column->getName(),
                'type' => $column->getType()->getName(),
                'type_definition' => $columnArray['columnDefinition'] ?? null,
                'length' => $column->getLength(),
                'precision' => $column->getPrecision(),
                'scale' => $column->getScale(),
                'nullable' => !$column->getNotnull(),
                'default' => $column->getDefault(),
                'unsigned' => $column->getUnsigned(),
                'autoincrement' => $column->getAutoincrement(),
                'comment' => $column->getComment(),
                'collation' => $columnArray['collation'] ?? null,
                'charset' => $columnArray['charset'] ?? null,
            ];
        }

        return $definitions;
    }

    /**
     * Get table columns using native Laravel Schema.
     */
    protected function getTableColumnsUsingNative(string $table): array
    {
        $columns = Schema::connection($this->connection)->getColumns($table);
        $definitions = [];

        foreach ($columns as $column) {
            $definitions[] = [
                'name' => $column['name'],
                'type' => $column['type_name'] ?? $column['type'],
                'length' => $column['length'] ?? null,
                'precision' => $column['precision'] ?? null,
                'scale' => $column['scale'] ?? null,
                'nullable' => $column['nullable'],
                'default' => $column['default'] ?? null,
                'unsigned' => $column['unsigned'] ?? false,
                'autoincrement' => $column['auto_increment'] ?? false,
                'comment' => $column['comment'] ?? null,
            ];
        }

        return $definitions;
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
