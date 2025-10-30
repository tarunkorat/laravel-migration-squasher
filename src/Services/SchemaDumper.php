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

        // Group columns to detect Laravel helpers (morphs, timestamps, etc.)
        $processedColumns = [];
        $columns = $tableData['columns'];

        for ($i = 0; $i < count($columns); $i++) {
            $column = $columns[$i];

            // Skip if already processed
            if (in_array($column['name'], $processedColumns)) {
                continue;
            }

            // Check for morphs pattern (column_type + column_id)
            if (str_ends_with($column['name'], '_type') &&
                isset($columns[$i + 1]) &&
                $columns[$i + 1]['name'] === str_replace('_type', '_id', $column['name'])) {

                $morphName = str_replace('_type', '', $column['name']);
                $code .= "            \$table->morphs('{$morphName}');\n";
                $processedColumns[] = $column['name'];
                $processedColumns[] = $columns[$i + 1]['name'];
                $i++; // Skip next column
                continue;
            }

            // Check for uuidMorphs pattern
            if (str_ends_with($column['name'], '_type') &&
                isset($columns[$i + 1]) &&
                $columns[$i + 1]['name'] === str_replace('_type', '_id', $column['name']) &&
                $columns[$i + 1]['type'] === 'uuid') {

                $morphName = str_replace('_type', '', $column['name']);
                $code .= "            \$table->uuidMorphs('{$morphName}');\n";
                $processedColumns[] = $column['name'];
                $processedColumns[] = $columns[$i + 1]['name'];
                $i++; // Skip next column
                continue;
            }

            // Check for timestamps pattern (created_at + updated_at)
            if ($column['name'] === 'created_at' &&
                isset($columns[$i + 1]) &&
                $columns[$i + 1]['name'] === 'updated_at') {

                $code .= "            \$table->timestamps();\n";
                $processedColumns[] = 'created_at';
                $processedColumns[] = 'updated_at';
                $i++; // Skip next column
                continue;
            }

            // Check for timestampsTz pattern
            if ($column['name'] === 'created_at' &&
                $column['type'] === 'timestamptz' &&
                isset($columns[$i + 1]) &&
                $columns[$i + 1]['name'] === 'updated_at') {

                $code .= "            \$table->timestampsTz();\n";
                $processedColumns[] = 'created_at';
                $processedColumns[] = 'updated_at';
                $i++; // Skip next column
                continue;
            }

            // Check for softDeletes pattern
            if ($column['name'] === 'deleted_at' && $column['nullable']) {
                $code .= "            \$table->softDeletes();\n";
                $processedColumns[] = 'deleted_at';
                continue;
            }

            // Check for softDeletesTz pattern
            if ($column['name'] === 'deleted_at' &&
                $column['type'] === 'timestamptz' &&
                $column['nullable']) {

                $code .= "            \$table->softDeletesTz();\n";
                $processedColumns[] = 'deleted_at';
                continue;
            }

            // Check for rememberToken pattern
            if ($column['name'] === 'remember_token' &&
                $column['nullable'] &&
                $column['length'] == 100) {

                $code .= "            \$table->rememberToken();\n";
                $processedColumns[] = 'remember_token';
                continue;
            }

            // Regular column
            $code .= $this->buildColumnCode($column);
            $processedColumns[] = $column['name'];
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
     * Build code for a single column with EXACT specifications.
     *
     * @param array $column
     * @return string
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
        $code .= $this->getColumnTypeParameters($type, $column);

        $code .= ")";

        // Add modifiers in correct order
        $code .= $this->getColumnModifiers($column, $type);

        $code .= ";\n";

        return $code;
    }

    /**
     * Get column type parameters (length, precision, scale, enum values, etc.)
     *
     * @param string $type
     * @param array $column
     * @return string
     */
    protected function getColumnTypeParameters(string $type, array $column): string
    {
        $params = '';

        switch ($type) {
            case 'string':
            case 'char':
                // Include length if specified and not default (255 for string)
                if (!empty($column['length']) && $column['length'] != 255) {
                    $params .= ", {$column['length']}";
                }
                break;

            case 'decimal':
            case 'double':
            case 'float':
                // Include precision and scale
                if (!empty($column['precision'])) {
                    $params .= ", {$column['precision']}";
                    if (!empty($column['scale'])) {
                        $params .= ", {$column['scale']}";
                    }
                }
                break;

            case 'enum':
            case 'set':
                // Get enum/set values from database
                $values = $this->getEnumValues($column);
                if (!empty($values)) {
                    $formatted = array_map(fn($v) => "'{$v}'", $values);
                    $params .= ", [" . implode(', ', $formatted) . "]";
                }
                break;
        }

        return $params;
    }

    /**
     * Get column modifiers (unsigned, nullable, default, etc.)
     *
     * @param array $column
     * @param string $type
     * @return string
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

        // Default value
        if ($column['default'] !== null && $column['default'] !== '') {
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

        // After (if column has after constraint)
        if (!empty($column['after'])) {
            $modifiers .= "->after('{$column['after']}')";
        }

        return $modifiers;
    }

    /**
     * Get enum/set values from database column definition.
     *
     * @param array $column
     * @return array
     */
    protected function getEnumValues(array $column): array
    {
        // Try to get enum values from database
        // This is database-specific
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
     *
     * @param array $column
     * @return array
     */
    protected function getMySQLEnumValues(array $column): array
    {
        if (empty($column['type_definition'])) {
            return [];
        }

        // Parse enum('value1','value2') format
        if (preg_match("/^enum\('(.*)'\)$/i", $column['type_definition'], $matches)) {
            return explode("','", $matches[1]);
        }

        if (preg_match("/^set\('(.*)'\)$/i", $column['type_definition'], $matches)) {
            return explode("','", $matches[1]);
        }

        return [];
    }

    /**
     * Get enum values from PostgreSQL.
     *
     * @param array $column
     * @return array
     */
    protected function getPostgreSQLEnumValues(array $column): array
    {
        // PostgreSQL uses custom types for enums
        // Would need to query pg_enum table
        return [];
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
        $indexName = !empty($index['name']) ? ", '{$index['name']}'" : '';

        if ($index['unique']) {
            return "            \$table->unique({$columns}{$indexName});\n";
        }

        return "            \$table->index({$columns}{$indexName});\n";
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

        $code = "            \$table->foreign({$localColumns}";

        // Add constraint name if exists
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
     *
     * @param string $type
     * @return string
     */
    protected function mapTypeToLaravel(string $type): string
    {
        $type = strtolower($type);

        $map = [
            // Integer types
            'int' => 'integer',
            'integer' => 'integer',
            'tinyint' => 'tinyInteger',
            'smallint' => 'smallInteger',
            'mediumint' => 'mediumInteger',
            'bigint' => 'bigInteger',

            // String types
            'varchar' => 'string',
            'string' => 'string',
            'char' => 'char',

            // Text types
            'text' => 'text',
            'mediumtext' => 'mediumText',
            'longtext' => 'longText',
            'tinytext' => 'text',

            // Date/Time types
            'datetime' => 'dateTime',
            'timestamp' => 'timestamp',
            'date' => 'date',
            'time' => 'time',
            'year' => 'year',

            // Boolean
            'boolean' => 'boolean',
            'bool' => 'boolean',
            'tinyint(1)' => 'boolean',

            // Numeric types
            'decimal' => 'decimal',
            'numeric' => 'decimal',
            'float' => 'float',
            'double' => 'double',
            'real' => 'double',

            // JSON
            'json' => 'json',
            'jsonb' => 'jsonb',

            // Binary
            'binary' => 'binary',
            'varbinary' => 'binary',
            'blob' => 'binary',
            'longblob' => 'longBinary',
            'mediumblob' => 'mediumBinary',

            // Other
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

        // Handle CURRENT_TIMESTAMP
        if (is_string($value) && stripos($value, 'CURRENT_TIMESTAMP') !== false) {
            return "DB::raw('CURRENT_TIMESTAMP')";
        }

        // Handle NOW()
        if (is_string($value) && stripos($value, 'NOW()') !== false) {
            return "DB::raw('NOW()')";
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
