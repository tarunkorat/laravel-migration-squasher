<?php

namespace TarunKorat\LaravelMigrationSquasher\Contracts;

interface SchemaInspectorInterface
{
    /**
     * Get all table names from database.
     *
     * @return array<string>
     */
    public function getTableNames(): array;

    /**
     * Get columns for a specific table.
     *
     * @param string $table
     * @return array
     */
    public function getTableColumns(string $table): array;

    /**
     * Get indexes for a specific table.
     *
     * @param string $table
     * @return array
     */
    public function getTableIndexes(string $table): array;

    /**
     * Get foreign keys for a specific table.
     *
     * @param string $table
     * @return array
     */
    public function getTableForeignKeys(string $table): array;

    /**
     * Check if doctrine/dbal is available.
     *
     * @return bool
     */
    public function isDbalAvailable(): bool;
}
