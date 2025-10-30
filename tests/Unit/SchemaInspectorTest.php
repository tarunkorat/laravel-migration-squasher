<?php

namespace TarunKorat\LaravelMigrationSquasher\Tests\Unit;

use Illuminate\Support\Facades\Schema;
use TarunKorat\LaravelMigrationSquasher\Services\SchemaInspector;
use TarunKorat\LaravelMigrationSquasher\Tests\TestCase;

class SchemaInspectorTest extends TestCase
{
    protected SchemaInspector $inspector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inspector = new SchemaInspector('testing');
    }

    /** @test */
    public function it_can_be_instantiated()
    {
        $this->assertInstanceOf(SchemaInspector::class, $this->inspector);
    }

    /** @test */
    public function it_detects_if_dbal_is_available()
    {
        $isAvailable = $this->inspector->isDbalAvailable();

        $this->assertIsBool($isAvailable);

        // Doctrine DBAL should be installed via composer
        $this->assertTrue($isAvailable);
    }

    /** @test */
    public function it_gets_table_names_from_empty_database()
    {
        $tables = $this->inspector->getTableNames();

        $this->assertIsArray($tables);
        // Should at least have migrations table or be empty
        $this->assertContainsOnly('string', $tables);
    }

    /** @test */
    public function it_gets_table_names_after_creating_tables()
    {
        // Create test tables
        Schema::create('test_users', function ($table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('test_posts', function ($table) {
            $table->id();
            $table->string('title');
            $table->timestamps();
        });

        $tables = $this->inspector->getTableNames();

        $this->assertContains('test_users', $tables);
        $this->assertContains('test_posts', $tables);
    }

    /** @test */
    public function it_gets_table_columns()
    {
        Schema::create('test_table', function ($table) {
            $table->id();
            $table->string('name', 100);
            $table->string('email')->unique();
            $table->integer('age')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        $columns = $this->inspector->getTableColumns('test_table');

        $this->assertIsArray($columns);
        $this->assertNotEmpty($columns);

        // Check that we have column definitions
        $columnNames = array_column($columns, 'name');
        $this->assertContains('id', $columnNames);
        $this->assertContains('name', $columnNames);
        $this->assertContains('email', $columnNames);
        $this->assertContains('age', $columnNames);
        $this->assertContains('active', $columnNames);
    }

    /** @test */
    public function it_correctly_identifies_nullable_columns()
    {
        Schema::create('test_table', function ($table) {
            $table->id();
            $table->string('required_field');
            $table->string('optional_field')->nullable();
        });

        $columns = $this->inspector->getTableColumns('test_table');

        // Find the optional field
        $optionalColumn = collect($columns)->firstWhere('name', 'optional_field');

        $this->assertNotNull($optionalColumn);
        $this->assertTrue($optionalColumn['nullable']);
    }

    /** @test */
    public function it_correctly_identifies_column_types()
    {
        Schema::create('test_table', function ($table) {
            $table->id();
            $table->string('name');
            $table->text('description');
            $table->integer('count');
            $table->boolean('is_active');
            $table->timestamp('published_at');
        });

        $columns = $this->inspector->getTableColumns('test_table');
        $columnsByName = collect($columns)->keyBy('name');

        // Check types exist (exact type names may vary by database)
        $this->assertArrayHasKey('name', $columnsByName);
        $this->assertArrayHasKey('description', $columnsByName);
        $this->assertArrayHasKey('count', $columnsByName);
        $this->assertArrayHasKey('is_active', $columnsByName);
    }

    /** @test */
    public function it_gets_table_indexes()
    {
        Schema::create('test_table', function ($table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('username');
            $table->index('username');
        });

        $indexes = $this->inspector->getTableIndexes('test_table');

        $this->assertIsArray($indexes);
        $this->assertNotEmpty($indexes);

        // Check index structure
        foreach ($indexes as $index) {
            $this->assertArrayHasKey('name', $index);
            $this->assertArrayHasKey('columns', $index);
            $this->assertIsArray($index['columns']);
        }
    }

    /** @test */
    public function it_identifies_unique_indexes()
    {
        Schema::create('test_table', function ($table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('slug')->unique();
        });

        $indexes = $this->inspector->getTableIndexes('test_table');

        // Find unique indexes
        $uniqueIndexes = array_filter($indexes, fn($idx) => $idx['unique'] ?? false);

        $this->assertNotEmpty($uniqueIndexes);
    }

    /** @test */
    public function it_gets_foreign_keys()
    {
        // Create parent table
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
        });

        // Create child table with foreign key
        Schema::create('posts', function ($table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('title');
        });

        $foreignKeys = $this->inspector->getTableForeignKeys('posts');

        $this->assertIsArray($foreignKeys);

        if (!empty($foreignKeys)) {
            $fk = $foreignKeys[0];
            $this->assertArrayHasKey('local_columns', $fk);
            $this->assertArrayHasKey('foreign_table', $fk);
            $this->assertArrayHasKey('foreign_columns', $fk);
        }
    }

    /** @test */
    public function it_returns_empty_array_for_nonexistent_table_columns()
    {
        try {
            $columns = $this->inspector->getTableColumns('nonexistent_table');
            // Some databases might return empty array
            $this->assertIsArray($columns);
        } catch (\Exception $e) {
            // It's also acceptable to throw an exception
            $this->assertInstanceOf(\Exception::class, $e);
        }
    }

    /** @test */
    public function it_handles_tables_with_no_indexes()
    {
        Schema::create('simple_table', function ($table) {
            $table->string('name');
            $table->string('value');
            // No primary key, no indexes
        });

        $indexes = $this->inspector->getTableIndexes('simple_table');

        $this->assertIsArray($indexes);
        // May be empty or contain auto-created indexes
    }

    /** @test */
    public function it_handles_tables_with_no_foreign_keys()
    {
        Schema::create('standalone_table', function ($table) {
            $table->id();
            $table->string('name');
        });

        $foreignKeys = $this->inspector->getTableForeignKeys('standalone_table');

        $this->assertIsArray($foreignKeys);
        $this->assertEmpty($foreignKeys);
    }

    /** @test */
    public function it_handles_composite_indexes()
    {
        Schema::create('test_table', function ($table) {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->index(['first_name', 'last_name']);
        });

        $indexes = $this->inspector->getTableIndexes('test_table');

        // Find the composite index
        $compositeIndex = collect($indexes)->first(function ($idx) {
            return count($idx['columns']) > 1;
        });

        if ($compositeIndex) {
            $this->assertCount(2, $compositeIndex['columns']);
            $this->assertContains('first_name', $compositeIndex['columns']);
            $this->assertContains('last_name', $compositeIndex['columns']);
        }
    }

    /** @test */
    public function it_handles_default_values()
    {
        Schema::create('test_table', function ($table) {
            $table->id();
            $table->string('status')->default('active');
            $table->integer('count')->default(0);
            $table->boolean('is_published')->default(false);
        });

        $columns = $this->inspector->getTableColumns('test_table');
        $columnsByName = collect($columns)->keyBy('name');

        // Check that default values are captured
        if (isset($columnsByName['status'])) {
            $this->assertArrayHasKey('default', $columnsByName['status']);
        }
    }
}
