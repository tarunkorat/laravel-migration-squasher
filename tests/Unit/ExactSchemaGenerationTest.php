<?php

namespace TarunKorat\LaravelMigrationSquasher\Tests\Unit;

use Illuminate\Support\Facades\Schema;
use TarunKorat\LaravelMigrationSquasher\Services\SchemaDumper;
use TarunKorat\LaravelMigrationSquasher\Services\SchemaInspector;
use TarunKorat\LaravelMigrationSquasher\Tests\TestCase;

class ExactSchemaGenerationTest extends TestCase
{
    protected SchemaDumper $dumper;
    protected SchemaInspector $inspector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inspector = new SchemaInspector('testing');
        $this->dumper = new SchemaDumper($this->inspector, 'testing');
    }

    /** @test */
    public function it_preserves_string_length()
    {
        Schema::create('test_table', function ($table) {
            $table->id();
            $table->string('name', 250)->nullable();
        });

        $schemaDump = $this->dumper->generateSchemaDump();

        $this->assertStringContainsString("string('name', 250)", $schemaDump);
        $this->assertStringContainsString('nullable()', $schemaDump);
    }

    /** @test */
    public function it_preserves_decimal_precision_and_scale()
    {
        Schema::create('test_table', function ($table) {
            $table->id();
            $table->decimal('amount', 8, 2);
        });

        $schemaDump = $this->dumper->generateSchemaDump();

        $this->assertStringContainsString('decimal(\'amount\', 8, 2)', $schemaDump);
    }

    /** @test */
    public function it_handles_morphs_columns_as_single_call()
    {
        Schema::create('test_table', function ($table) {
            $table->id();
            $table->morphs('loggable');
        });

        $schemaDump = $this->dumper->generateSchemaDump();

        $this->assertStringContainsString("morphs('loggable')", $schemaDump);
        // Should NOT contain individual column definitions
        $this->assertStringNotContainsString("string('loggable_type')", $schemaDump);
        $this->assertStringNotContainsString("bigInteger('loggable_id')", $schemaDump);
    }

    /** @test */
    public function it_handles_timestamps_as_single_call()
    {
        Schema::create('test_table', function ($table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        $schemaDump = $this->dumper->generateSchemaDump();

        $this->assertStringContainsString('timestamps()', $schemaDump);
        // Should NOT contain individual timestamp columns
        $this->assertStringNotContainsString("timestamp('created_at')", $schemaDump);
        $this->assertStringNotContainsString("timestamp('updated_at')", $schemaDump);
    }

    /** @test */
    public function it_handles_soft_deletes()
    {
        Schema::create('test_table', function ($table) {
            $table->id();
            $table->softDeletes();
        });

        $schemaDump = $this->dumper->generateSchemaDump();

        $this->assertStringContainsString('softDeletes()', $schemaDump);
        $this->assertStringNotContainsString("timestamp('deleted_at')", $schemaDump);
    }

    /** @test */
    public function it_handles_remember_token()
    {
        Schema::create('test_table', function ($table) {
            $table->id();
            $table->rememberToken();
        });

        $schemaDump = $this->dumper->generateSchemaDump();

        $this->assertStringContainsString('rememberToken()', $schemaDump);
    }

    /** @test */
    public function it_preserves_default_values_correctly()
    {
        Schema::create('test_table', function ($table) {
            $table->id();
            $table->string('status')->default('active');
            $table->integer('count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_deleted')->default(false);
        });

        $schemaDump = $this->dumper->generateSchemaDump();

        $this->assertStringContainsString("default('active')", $schemaDump);
        $this->assertStringContainsString('default(0)', $schemaDump);
        $this->assertStringContainsString('default(true)', $schemaDump);
        $this->assertStringContainsString('default(false)', $schemaDump);
    }

    /** @test */
    public function it_preserves_unsigned_modifier()
    {
        Schema::create('test_table', function ($table) {
            $table->id();
            $table->unsignedInteger('count');
            $table->unsignedBigInteger('big_count');
        });

        $schemaDump = $this->dumper->generateSchemaDump();

        $this->assertStringContainsString('unsigned()', $schemaDump);
    }

    /** @test */
    public function it_preserves_nullable_columns()
    {
        Schema::create('test_table', function ($table) {
            $table->id();
            $table->string('required_field');
            $table->string('optional_field')->nullable();
        });

        $schemaDump = $this->dumper->generateSchemaDump();

        // Optional field should have nullable
        $this->assertStringContainsString("string('optional_field')", $schemaDump);

        // Count how many times nullable appears
        $nullableCount = substr_count($schemaDump, '->nullable()');
        $this->assertGreaterThanOrEqual(1, $nullableCount);
    }

    /** @test */
    public function it_preserves_column_comments()
    {
        Schema::create('test_table', function ($table) {
            $table->id();
            $table->string('name')->comment('User full name');
        });

        $schemaDump = $this->dumper->generateSchemaDump();

        $this->assertStringContainsString("comment('User full name')", $schemaDump);
    }

    /** @test */
    public function it_preserves_unique_indexes()
    {
        Schema::create('test_table', function ($table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('username')->unique();
        });

        $schemaDump = $this->dumper->generateSchemaDump();

        $uniqueCount = substr_count($schemaDump, '->unique(');
        $this->assertGreaterThanOrEqual(2, $uniqueCount);
    }

    /** @test */
    public function it_handles_complex_table_with_all_features()
    {
        Schema::create('complex_table', function ($table) {
            $table->id();
            $table->string('name', 250)->nullable();
            $table->string('email', 100)->unique();
            $table->decimal('price', 10, 2)->default(0.00);
            $table->unsignedInteger('quantity')->default(1);
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->morphs('commentable');
            $table->timestamps();
            $table->softDeletes();
        });

        $schemaDump = $this->dumper->generateSchemaDump();

        // Verify all features are preserved
        $this->assertStringContainsString("string('name', 250)", $schemaDump);
        $this->assertStringContainsString("string('email', 100)", $schemaDump);
        $this->assertStringContainsString('decimal(\'price\', 10, 2)', $schemaDump);
        $this->assertStringContainsString('unsignedInteger(\'quantity\')', $schemaDump);
        $this->assertStringContainsString('boolean(\'is_active\')', $schemaDump);
        $this->assertStringContainsString("morphs('commentable')", $schemaDump);
        $this->assertStringContainsString('timestamps()', $schemaDump);
        $this->assertStringContainsString('softDeletes()', $schemaDump);
        $this->assertStringContainsString('unique()', $schemaDump);
    }

    /** @test */
    public function it_does_not_add_automatic_indexes()
    {
        Schema::create('test_table', function ($table) {
            $table->id();
            $table->string('name'); // No index
            $table->string('email'); // No index
        });

        $schemaDump = $this->dumper->generateSchemaDump();

        // Should only have primary key index, not automatic indexes on name/email
        $indexCount = substr_count($schemaDump, '->index(');
        $uniqueCount = substr_count($schemaDump, '->unique(');

        // If there were no explicit indexes, these should be 0
        $this->assertEquals(0, $indexCount + $uniqueCount);
    }

    /** @test */
    public function it_preserves_foreign_key_constraints()
    {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
        });

        Schema::create('posts', function ($table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('title');
        });

        $schemaDump = $this->dumper->generateSchemaDump();

        $this->assertStringContainsString('foreign(', $schemaDump);
        $this->assertStringContainsString('references(', $schemaDump);
        $this->assertStringContainsString("->on('users')", $schemaDump);
        $this->assertStringContainsString("->onDelete('cascade')", $schemaDump);
    }

    /** @test */
    public function generated_schema_produces_identical_database_structure()
    {
        // Create original table
        Schema::create('original_table', function ($table) {
            $table->id();
            $table->string('name', 250)->nullable();
            $table->decimal('amount', 8, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Generate schema dump
        $schemaDump = $this->dumper->generateSchemaDump();

        // Get original columns
        $originalColumns = $this->inspector->getTableColumns('original_table');

        // Drop and recreate from schema dump
        Schema::dropIfExists('original_table');

        // Execute generated migration
        $tempFile = tempnam(sys_get_temp_dir(), 'migration_');
        file_put_contents($tempFile, $schemaDump);
        $migration = include $tempFile;
        $migration->up();
        unlink($tempFile);

        // Get new columns
        $newColumns = $this->inspector->getTableColumns('original_table');

        // Compare (excluding auto-generated values)
        $this->assertEquals(
            count($originalColumns),
            count($newColumns),
            'Column count should match'
        );

        foreach ($originalColumns as $index => $originalColumn) {
            $newColumn = $newColumns[$index];

            $this->assertEquals(
                $originalColumn['name'],
                $newColumn['name'],
                "Column name should match at index {$index}"
            );

            $this->assertEquals(
                $originalColumn['nullable'],
                $newColumn['nullable'],
                "Nullable should match for column {$originalColumn['name']}"
            );
        }
    }
}
