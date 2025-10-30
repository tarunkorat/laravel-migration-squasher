<?php

namespace TarunKorat\LaravelMigrationSquasher\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;
use TarunKorat\LaravelMigrationSquasher\Contracts\SchemaInspectorInterface;

class SchemaDumper
{
    protected SchemaInspectorInterface $inspector;
    protected array $excludedTables;
    protected string $connection;

    public function __construct(SchemaInspectorInterface $inspector, ?string $connection = null)
    {
        $this->inspector = $inspector;
        $this->excludedTables = config('migration-squasher.excluded_tables', ['migrations']);
        $this->connection = $connection ?? config('database.default');
    }

    /**
     * Generate schema dump from current database.
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
     */
    protected function getAllTables(): array
    {
        $tables = [];
        $tableNames = $this->inspector->getTableNames();

        foreach ($tableNames as $tableName) {
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
     */
    protected function buildTableCreationCode(string $tableName, array $tableData): string
    {
        $code = "\n        Schema::create('{$tableName}', function (Blueprint \$table) {\n";

        $processedColumns = [];
        $columns = $tableData['columns'];

        for ($i = 0; $i < count($columns); $i++) {
            $column = $columns[$i];

            if (in_array($column['name'], $processedColumns)) {
                continue;
            }

            // Check for morphs pattern (column_type + column_id)
            if (str_ends_with($column['name'], '_type') &&
                isset($columns[$i + 1]) &&
                $columns[$i + 1]['name'] === str_replace('_type', '_id', $column['name'])) {

                $morphName = str_replace('_type', '', $column['name']);
                $nextColumn = $columns[$i + 1];

                // Check if it's uuidMorphs or regular morphs
                if ($this->isUuidColumn($nextColumn)) {
                    $code .= "            \$table->uuidMorphs('{$morphName}');\n";
                } else {
                    $code .= "            \$table->morphs('{$morphName}');\n";
                }

                $processedColumns[] = $column['name'];
                $processedColumns[] = $columns[$i + 1]['name'];
                $i++;
                continue;
            }

            // Check for timestamps pattern
            if ($column['name'] === 'created_at' &&
                isset($columns[$i + 1]) &&
                $columns[$i + 1]['name'] === 'updated_at') {

                if ($this->isTimestampTz($column)) {
                    $code .= "            \$table->timestampsTz();\n";
                } else {
                    $code .= "            \$table->timestamps();\n";
                }

                $processedColumns[] = 'created_at';
                $processedColumns[] = 'updated_at';
                $i++;
                continue;
            }

            // Check for softDeletes pattern
            if ($column['name'] === 'deleted_at' && $column['nullable']) {
                if ($this->isTimestampTz($column)) {
                    $code .= "            \$table->softDeletesTz();\n";
                } else {
                    $code .= "            \$table->softDeletes();\n";
                }
                $processedColumns[] = 'deleted_at';
                continue;
            }

            // Check for rememberToken pattern
            if ($column['name'] === 'remember_token' &&
                $column['nullable'] &&
                ($column['length'] ?? 0) == 100) {

                $code .= "            \$table->rememberToken();\n";
                $processedColumns[] = 'remember_token';
                continue;
            }

            // Regular column
            $code .= $this->buildColumnCode($column);
            $processedColumns[] = $column['name'];
        }

        // Add indexes (excluding primary keys and auto-created unique indexes)
        foreach ($tableData['indexes'] as $index) {
            if ($index['primary']) {
                continue;
            }

            // Skip indexes that are automatically created by unique() on columns
            if ($this->isAutoGeneratedUniqueIndex($index, $columns)) {
                continue;
            }

            $code .= $this->buildIndexCode($index);
        }

        $code .= "        });\n";

        return $code;
    }

    /**
     * Check if index is auto-generated from a unique column constraint.
     */
    protected function isAutoGeneratedUniqueIndex(array $index, array $columns): bool
    {
        // If it's a unique index on a single column
        if (($index['unique'] ?? false) && count($index['columns']) === 1) {
            $columnName = $index['columns'][0];

            // Check if any column with this name was defined with unique()
            foreach ($columns as $column) {
                if ($column['name'] === $columnName) {
                    // This will be handled by the column's ->unique() modifier
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if column is UUID type.
     */
    protected function isUuidColumn(array $column): bool
    {
        $type = strtolower($column['type'] ?? '');
        return in_array($type, ['uuid', 'char']) && ($column['length'] ?? 0) === 36;
    }

    /**
     * Check if timestamp column has timezone.
     */
    protected function isTimestampTz(array $column): bool
    {
        $type = strtolower($column['type'] ?? '');
        return in_array($type, ['timestamptz', 'timestamp with time zone']);
    }

    /**
     * Build code for foreign keys (must be added after all tables are created).
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
     * Build code for a single column with EXACT specifications.
     */
    protected function buildColumnCode(array $column): string
    {
        $type = $this->mapTypeToLaravel($column['type']);
        $name = $column['name'];

        // Handle special ID columns
        if ($column['autoincrement'] && in_array($name, ['id'])) {
            return "            \$table->id();\n";
        }

        // Start building the column definition
        $code = "            \$table->{$type}('{$name}'";

        // Add parameters based on column type
        $params = $this->getColumnTypeParameters($type, $column);
        if ($params) {
            $code .= $params;
        }

        $code .= ")";

        // Add modifiers in correct order
        $code .= $this->getColumnModifiers($column, $type);

        $code .= ";\n";

        return $code;
    }

    /**
     * Get column type parameters (length, precision, scale, enum values, etc.)
     */
    protected function getColumnTypeParameters(string $type, array $column): string
    {
        $params = '';

        switch ($type) {
            case 'string':
                // ALWAYS include length for string columns (default 255)
                $length = $column['length'] ?? 255;
                if ($length != 255) {
                    $params = ", {$length}";
                }
                break;

            case 'char':
                // ALWAYS include length for char columns
                $length = $column['length'] ?? 255;
                $params = ", {$length}";
                break;

            case 'decimal':
            case 'double':
            case 'float':
                // ALWAYS include precision and scale for decimal/float types
                $precision = $column['precision'] ?? 8;
                $scale = $column['scale'] ?? 2;
                $params = ", {$precision}, {$scale}";
                break;

            case 'enum':
            case 'set':
                // Get enum/set values from database
                $values = $this->getEnumValues($column);
                if (!empty($values)) {
                    $formatted = array_map(fn($v) => "'{$v}'", $values);
                    $params = ", [" . implode(', ', $formatted) . "]";
                }
                break;
        }

        return $params;
    }

    /**
     * Get column modifiers (unsigned, nullable, default, unique, etc.)
     */
    protected function getColumnModifiers(array $column, string $type): string
    {
        $modifiers = '';

        // Unsigned (must come before other modifiers)
        if (!empty($column['unsigned'])) {
            $modifiers .= "->unsigned()";
        }

        // Nullable
        if ($column['nullable']) {
            $modifiers .= "->nullable()";
        }

        // Unique - check if this column has a unique index
        if ($this->hasUniqueIndex($column)) {
            $modifiers .= "->unique()";
        }

        // Default value
        if (array_key_exists('default', $column) && $column['default'] !== null && $column['default'] !== '') {
            $default = $this->formatDefaultValue($column['default'], $type);
            $modifiers .= "->default({$default})";
        }

        // Auto increment (for non-id columns)
        if ($column['autoincrement'] && !in_array($column['name'], ['id'])) {
            $modifiers .= "->autoIncrement()";
        }

        // Comment
        if (!empty($column['comment'])) {
            $comment = addslashes($column['comment']);
            $modifiers .= "->comment('{$comment}')";
        }

        return $modifiers;
    }

    /**
     * Check if column has a unique index.
     */
    protected function hasUniqueIndex(array $column): bool
    {
        // This will be set by the inspector if the column has unique constraint
        return !empty($column['unique']) ||
               (isset($column['indexes']) &&
                in_array('unique', array_column($column['indexes'], 'type')));
    }

    /**
     * Get enum/set values from database column definition.
     */
    protected function getEnumValues(array $column): array
    {
        $driver = DB::connection($this->connection)->getDriverName();

        try {
            switch ($driver) {
                case 'mysql':
                    return $this->getMySQLEnumValues($column);

                case 'pgsql':
                    return $this->getPostgreSQLEnumValues($column);

                default:
                    return [];
            }
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get enum values from MySQL.
     */
    protected function getMySQLEnumValues(array $column): array
    {
        // First check if type_definition is available
        if (!empty($column['type_definition'])) {
            if (preg_match("/^enum\('(.*)'\)$/i", $column['type_definition'], $matches)) {
                return explode("','", $matches[1]);
            }
            if (preg_match("/^set\('(.*)'\)$/i", $column['type_definition'], $matches)) {
                return explode("','", $matches[1]);
            }
        }

        // Fallback: query INFORMATION_SCHEMA
        try {
            $tableName = $column['table_name'] ?? null;
            $columnName = $column['name'];

            if ($tableName) {
                $database = DB::connection($this->connection)->getDatabaseName();
                $result = DB::connection($this->connection)
                    ->select(
                        "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
                         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?",
                        [$database, $tableName, $columnName]
                    );

                if (!empty($result)) {
                    $columnType = $result[0]->COLUMN_TYPE;
                    if (preg_match("/^enum\('(.*)'\)$/i", $columnType, $matches)) {
                        return explode("','", $matches[1]);
                    }
                    if (preg_match("/^set\('(.*)'\)$/i", $columnType, $matches)) {
                        return explode("','", $matches[1]);
                    }
                }
            }
        } catch (\Exception $e) {
            // Ignore errors
        }

        return [];
    }

    /**
     * Get enum values from PostgreSQL.
     */
    protected function getPostgreSQLEnumValues(array $column): array
    {
        // PostgreSQL uses custom types for enums
        try {
            $typeName = $column['type_name'] ?? null;
            if ($typeName) {
                $result = DB::connection($this->connection)
                    ->select(
                        "SELECT e.enumlabel
                         FROM pg_type t
                         JOIN pg_enum e ON t.oid = e.enumtypid
                         WHERE t.typname = ?
                         ORDER BY e.enumsortorder",
                        [$typeName]
                    );

                return array_column($result, 'enumlabel');
            }
        } catch (\Exception $e) {
            // Ignore errors
        }

        return [];
    }

    /**
     * Build code for an index.
     */
    protected function buildIndexCode(array $index): string
    {
        $columns = $this->formatColumns($index['columns']);
        $indexName = !empty($index['name']) ? ", '{$index['name']}'" : '';

        if ($index['unique']) {
            return "            \$table->unique({$columns}{$indexName});\n";
        }

        return "            \$table->index({$columns}{$indexName});\n";
    }

    /**
     * Build code for a foreign key.
     */
    protected function buildForeignKeyCode(array $fk): string
    {
        $localColumns = $this->formatColumns($fk['local_columns']);
        $foreignTable = $fk['foreign_table'];
        $foreignColumns = $this->formatColumns($fk['foreign_columns']);

        $code = "            \$table->foreign({$localColumns}";

        if (!empty($fk['name'])) {
            $code .= ", '{$fk['name']}'";
        }

        $code .= ")\n";
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
            'tinyint(1)' => 'boolean',
            'decimal' => 'decimal',
            'numeric' => 'decimal',
            'float' => 'float',
            'double' => 'double',
            'real' => 'double',
            'json' => 'json',
            'jsonb' => 'jsonb',
            'binary' => 'binary',
            'varbinary' => 'binary',
            'blob' => 'binary',
            'longblob' => 'longBinary',
            'mediumblob' => 'mediumBinary',
            'uuid' => 'uuid',
            'enum' => 'enum',
            'set' => 'set',
            'geometry' => 'geometry',
            'point' => 'point',
            'linestring' => 'lineString',
            'polygon' => 'polygon',
            'geometrycollection' => 'geometryCollection',
            'multipoint' => 'multiPoint',
            'multilinestring' => 'multiLineString',
            'multipolygon' => 'multiPolygon',
        ];

        return $map[$type] ?? 'string';
    }

    /**
     * Format default value for code generation.
     */
    protected function formatDefaultValue($value, string $type): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_string($value) && stripos($value, 'CURRENT_TIMESTAMP') !== false) {
            return "DB::raw('CURRENT_TIMESTAMP')";
        }

        if (is_string($value) && stripos($value, 'NOW()') !== false) {
            return "DB::raw('NOW()')";
        }

        if (is_bool($value) || in_array($type, ['boolean', 'bool'])) {
            return $value ? 'true' : 'false';
        }

        if (is_numeric($value) && !in_array($type, ['string', 'char'])) {
            return (string) $value;
        }

        $escaped = addslashes((string) $value);
        return "'{$escaped}'";
    }

    /**
     * Format columns array for code generation.
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
     */
    public function saveSchemaDump(string $content, string $filename): void
    {
        $path = database_path(config('migration-squasher.migration_path', 'migrations') . '/' . $filename);
        File::put($path, $content);
    }
}
