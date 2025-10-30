<?php

namespace TarunKorat\LaravelMigrationSquasher\Services;

use Illuminate\Support\Facades\File;
use TarunKorat\LaravelMigrationSquasher\Contracts\SchemaInspectorInterface;

class SchemaDumper
{
    protected SchemaInspectorInterface $inspector;
    protected array $excludedTables;

    public function __construct(SchemaInspectorInterface $inspector)
    {
        $this->inspector = $inspector;
        $this->excludedTables = config('migration-squasher.excluded_tables', ['migrations']);
    }

    /**
     * Generate schema dump from current database.
     *
     * @return string
     */
    public function generateSchemaDump(): string
    {
        $tables = $this->getAllTables();
        $createSchema = $this->buildCreateTablesCode($tables);
        $foreignKeys = $this->buildForeignKeysCode($tables);

        return $this->wrapInMigrationClass($createSchema, $foreignKeys, $tables);
    }

    /**
     * Get all tables from database with their structure.
     *
     * @return array
     */
    protected function getAllTables(): array
    {
        $tables = [];
        $tableNames = $this->inspector->getTableNames();

        foreach ($tableNames as $tableName) {
            // Skip excluded tables
            if (in_array($tableName, $this->excludedTables)) {
                continue;
            }

            $tables[$tableName] = [
                'columns' => $this->inspector->getTableColumns($tableName),
                'indexes' => $this->inspector->getTableIndexes($tableName),
                'foreign_keys' => $this->inspector->getTableForeignKeys($tableName),
            ];
        }

        return $tables;
    }

    /**
     * Build PHP code for creating tables.
     *
     * @param array $tables
     * @return string
     */
    protected function buildCreateTablesCode(array $tables): string
    {
        $code = '';

        foreach ($tables as $tableName => $tableData) {
            $code .= $this->buildTableCreationCode($tableName, $tableData);
        }

        return $code;
    }

    /**
     * Build code for creating a single table.
     *
     * @param string $tableName
     * @param array $tableData
     * @return string
     */
    protected function buildTableCreationCode(string $tableName, array $tableData): string
    {
        $code = "\n        Schema::create('{$tableName}', function (Blueprint \$table) {\n";

        // Add columns
        foreach ($tableData['columns'] as $column) {
            $code .= $this->buildColumnCode($column);
        }

        // Add indexes (excluding primary key as it's handled by column definition)
        foreach ($tableData['indexes'] as $index) {
            if ($index['primary']) {
                continue; // Primary key is handled in column definition
            }

            $code .= $this->buildIndexCode($index);
        }

        $code .= "        });\n";

        return $code;
    }

    /**
     * Build code for foreign keys (must be added after all tables are created).
     *
     * @param array $tables
     * @return string
     */
    protected function buildForeignKeysCode(array $tables): string
    {
        $code = '';

        foreach ($tables as $tableName => $tableData) {
            if (empty($tableData['foreign_keys'])) {
                continue;
            }

            $code .= "\n        Schema::table('{$tableName}', function (Blueprint \$table) {\n";

            foreach ($tableData['foreign_keys'] as $fk) {
                $code .= $this->buildForeignKeyCode($fk);
            }

            $code .= "        });\n";
        }

        return $code;
    }

    /**
     * Build code for a single column.
     *
     * @param array $column
     * @return string
     */
    protected function buildColumnCode(array $column): string
    {
        $type = $this->mapTypeToLaravel($column['type']);
        $name = $column['name'];

        // Start building the column definition
        $code = "            \$table->{$type}('{$name}'";

        // Add length for string/char types
        if (in_array($type, ['string', 'char']) && $column['length']) {
            $code .= ", {$column['length']}";
        }

        // Add precision and scale for decimal types
        if ($type === 'decimal' && $column['precision'] && $column['scale']) {
            $code .= ", {$column['precision']}, {$column['scale']}";
        }

        $code .= ")";

        // Add modifiers
        if ($column['unsigned']) {
            $code .= "->unsigned()";
        }

        if ($column['nullable']) {
            $code .= "->nullable()";
        }

        if ($column['default'] !== null) {
            $default = $this->formatDefaultValue($column['default'], $type);
            $code .= "->default({$default})";
        }

        if ($column['autoincrement']) {
            // For auto-increment, we typically use id() or bigIncrements()
            // But if it's already defined, we can use autoIncrement()
            if (!in_array($type, ['id', 'bigIncrements', 'increments'])) {
                $code .= "->autoIncrement()";
            }
        }

        if (!empty($column['comment'])) {
            $comment = addslashes($column['comment']);
            $code .= "->comment('{$comment}')";
        }

        $code .= ";\n";

        return $code;
    }

    /**
     * Build code for an index.
     *
     * @param array $index
     * @return string
     */
    protected function buildIndexCode(array $index): string
    {
        $columns = $this->formatColumns($index['columns']);

        if ($index['unique']) {
            return "            \$table->unique({$columns});\n";
        }

        return "            \$table->index({$columns});\n";
    }

    /**
     * Build code for a foreign key.
     *
     * @param array $fk
     * @return string
     */
    protected function buildForeignKeyCode(array $fk): string
    {
        $localColumns = $this->formatColumns($fk['local_columns']);
        $foreignTable = $fk['foreign_table'];
        $foreignColumns = $this->formatColumns($fk['foreign_columns']);

        $code = "            \$table->foreign({$localColumns})\n";
        $code .= "                ->references({$foreignColumns})\n";
        $code .= "                ->on('{$foreignTable}')";

        if (!empty($fk['on_update'])) {
            $onUpdate = strtolower($fk['on_update']);
            $code .= "\n                ->onUpdate('{$onUpdate}')";
        }

        if (!empty($fk['on_delete'])) {
            $onDelete = strtolower($fk['on_delete']);
            $code .= "\n                ->onDelete('{$onDelete}')";
        }

        $code .= ";\n";

        return $code;
    }

    /**
     * Map database types to Laravel migration types.
     *
     * @param string $type
     * @return string
     */
    protected function mapTypeToLaravel(string $type): string
    {
        $type = strtolower($type);

        $map = [
            'int' => 'integer',
            'integer' => 'integer',
            'tinyint' => 'tinyInteger',
            'smallint' => 'smallInteger',
            'mediumint' => 'mediumInteger',
            'bigint' => 'bigInteger',
            'varchar' => 'string',
            'string' => 'string',
            'char' => 'char',
            'text' => 'text',
            'mediumtext' => 'mediumText',
            'longtext' => 'longText',
            'tinytext' => 'text',
            'datetime' => 'dateTime',
            'timestamp' => 'timestamp',
            'date' => 'date',
            'time' => 'time',
            'year' => 'year',
            'boolean' => 'boolean',
            'bool' => 'boolean',
            'decimal' => 'decimal',
            'numeric' => 'decimal',
            'float' => 'float',
            'double' => 'double',
            'json' => 'json',
            'jsonb' => 'jsonb',
            'binary' => 'binary',
            'blob' => 'binary',
            'uuid' => 'uuid',
            'enum' => 'enum',
            'set' => 'set',
        ];

        return $map[$type] ?? 'string';
    }

    /**
     * Format default value for code generation.
     *
     * @param mixed $value
     * @param string $type
     * @return string
     */
    protected function formatDefaultValue($value, string $type): string
    {
        if ($value === null) {
            return 'null';
        }

        // Handle boolean values
        if (is_bool($value) || in_array($type, ['boolean', 'bool'])) {
            return $value ? 'true' : 'false';
        }

        // Handle numeric values
        if (is_numeric($value) && !in_array($type, ['string', 'char'])) {
            return (string) $value;
        }

        // Handle string values
        $escaped = addslashes((string) $value);
        return "'{$escaped}'";
    }

    /**
     * Format columns array for code generation.
     *
     * @param array $columns
     * @return string
     */
    protected function formatColumns(array $columns): string
    {
        if (count($columns) === 1) {
            return "'{$columns[0]}'";
        }

        $formatted = array_map(fn ($col) => "'{$col}'", $columns);
        return '[' . implode(', ', $formatted) . ']';
    }

    /**
     * Wrap schema in migration class.
     *
     * @param string $createSchema
     * @param string $foreignKeys
     * @param array $tables
     * @return string
     */
    protected function wrapInMigrationClass(string $createSchema, string $foreignKeys, array $tables): string
    {
        $downCode = $this->buildDownMethod($tables);

        return <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {{$createSchema}{$foreignKeys}
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {{$downCode}
    }
};
PHP;
    }

    /**
     * Build down method for migration.
     *
     * @param array $tables
     * @return string
     */
    protected function buildDownMethod(array $tables): string
    {
        if (empty($tables)) {
            return "\n        // No tables to drop\n    ";
        }

        $code = '';
        $tableNames = array_reverse(array_keys($tables));

        foreach ($tableNames as $tableName) {
            $code .= "\n        Schema::dropIfExists('{$tableName}');";
        }

        return $code . "\n    ";
    }

    /**
     * Save schema dump to file.
     *
     * @param string $content
     * @param string $filename
     * @return void
     */
    public function saveSchemaDump(string $content, string $filename): void
    {
        $path = database_path(config('migration-squasher.migration_path', 'migrations') . '/' . $filename);
        File::put($path, $content);
    }
}
