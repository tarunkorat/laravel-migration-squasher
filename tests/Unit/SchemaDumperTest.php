<?php

namespace TarunKorat\LaravelMigrationSquasher\Tests\Unit;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use TarunKorat\LaravelMigrationSquasher\Services\SchemaDumper;
use TarunKorat\LaravelMigrationSquasher\Services\SchemaInspector;
use TarunKorat\LaravelMigrationSquasher\Tests\TestCase;

class SchemaDumperTest extends TestCase
{
    protected SchemaDumper $dumper;
    protected SchemaInspector $inspector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inspector = new SchemaInspector('testing');
        $this->dumper = new SchemaDumper($this->inspector);
    }

    /** @test */
    public function it_can_be_instantiated()
    {
        $this->assertInstanceOf(SchemaDumper::class, $this->dumper);
    }

    /** @test */
    public function it_generates_schema_dump_for_empty_database()
    {
        $schemaDump = $this->dumper->generateSchemaDump();

        $this->assertIsString($schemaDump);
        $this->assertStringContainsString('<?php', $schemaDump);
        $this->assertStringContainsString('use Illuminate\Database\Migrations\Migration', $schemaDump);
        $this->assertStringContainsString('return new class extends Migration', $schemaDump);
        $this->assertStringContainsString('public function up()', $schemaDump);
        $this->assertStringContainsString('public function down()', $schemaDump);
    }

    /** @test */
    public function it_generates_schema_dump_with_single_table()
    {
        // Create a simple table
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamps();
        });

        $schemaDump = $this->dumper->generateSchemaDump();

        $this->assertStringContainsString("Schema::create('users'", $schemaDump);
        $this->assertStringContainsString('$table->id()', $schemaDump);
        $this->assertStringContainsString("'name'", $schemaDump);
        $this->assertStringContainsString("'email'", $schemaDump);
    }

    /** @test */
    public function it_generates_schema_dump_with_multiple_tables()
    {
        // Create multiple tables
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
        });

        Schema::create('posts', function ($table) {
            $table->id();
            $table->string('title');
        });

        Schema::create('comments', function ($table) {
            $table->id();
            $table->text('body');
        });

        $schemaDump = $this->dumper->generateSchemaDump();

        $this->assertStringContainsString("Schema::create('users'", $schemaDump);
        $this->assertStringContainsString("Schema::create('posts'", $schemaDump);
        $this->assertStringContainsString("Schema::create('comments'", $schemaDump);
    }

    /** @test */
    public function it_excludes_migrations_table()
    {
        // Migrations table usually exists
        $schemaDump = $this->dumper->generateSchemaDump();

        $this->assertStringNotContainsString("Schema::create('migrations'", $schemaDump);
    }

    /** @test */
    public function it_includes_nullable_columns()
    {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('middle_name')->nullable();
        });

        $schemaDump = $this->dumper->generateSchemaDump();

        $this->assertStringContainsString('nullable()', $schemaDump);
    }

    /** @test */
    public function it_includes_default_values()
    {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('status')->default('active');
            $table->integer('count')->default(0);
        });

        $schemaDump = $this->dumper->generateSchemaDump();

        $this->assertStringContainsString('default(', $schemaDump);
    }

    /** @test */
    public function it_includes_unique_indexes()
    {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('username')->unique();
        });

        $schemaDump = $this->dumper->generateSchemaDump();

        $this->assertStringContainsString('unique()', $schemaDump);
    }

    /** @test */
    public function it_includes_regular_indexes()
    {
        Schema::create('posts', function ($table) {
            $table->id();
            $table->string('slug');
            $table->index('slug');
        });

        $schemaDump = $this->dumper->generateSchemaDump();

        $this->assertStringContainsString('index(', $schemaDump);
    }

    /** @test */
    public function it_includes_foreign_keys()
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
        $this->assertStringContainsString('->on(', $schemaDump);
    }

    /** @test */
    public function it_generates_down_method_with_drop_statements()
    {
        Schema::create('users', function ($table) {
            $table->id();
        });

        Schema::create('posts', function ($table) {
            $table->id();
        });

        $schemaDump = $this->dumper->generateSchemaDump();

        $this->assertStringContainsString('public function down()', $schemaDump);
        $this->assertStringContainsString("Schema::dropIfExists('users')", $schemaDump);
        $this->assertStringContainsString("Schema::dropIfExists('posts')", $schemaDump);
    }

    /** @test */
    public function it_saves_schema_dump_to_file()
    {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
        });

        $schemaDump = $this->dumper->generateSchemaDump();
        $filename = '0000_00_00_000000_test_schema_dump.php';

        $this->dumper->saveSchemaDump($schemaDump, $filename);

        $expectedPath = database_path('migrations_test/' . $filename);
        $this->assertFileExists($expectedPath);

        $content = File::get($expectedPath);
        $this->assertStringContainsString('<?php', $content);
        $this->assertStringContainsString("Schema::create('users'", $content);

        // Cleanup
        File::delete($expectedPath);
    }

    /** @test */
    public function it_generates_valid_php_syntax()
    {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamps();
        });

        $schemaDump = $this->dumper->generateSchemaDump();

        // Try to evaluate the PHP code (syntax check)
        $tempFile = tempnam(sys_get_temp_dir(), 'schema_test_');
        file_put_contents($tempFile, $schemaDump);

        // Check for syntax errors
        $output = shell_exec("php -l {$tempFile} 2>&1");
        $this->assertStringContainsString('No syntax errors', $output);

        unlink($tempFile);
    }

    /** @test */
    public function it_handles_tables_with_many_column_types()
    {
        Schema::create('complex_table', function ($table) {
            $table->id();
            $table->string('name');
            $table->text('description');
            $table->integer('count');
            $table->bigInteger('big_count');
            $table->boolean('is_active');
            $table->date('birth_date');
            $table->dateTime('published_at');
            $table->timestamp('created_at');
            $table->decimal('price', 10, 2);
            $table->float('rating');
            $table->json('metadata');
        });

        $schemaDump = $this->dumper->generateSchemaDump();

        // Check that various column types are represented
        $this->assertStringContainsString("Schema::create('complex_table'", $schemaDump);
        $this->assertIsString($schemaDump);
        $this->assertGreaterThan(0, strlen($schemaDump));
    }

    /** @test */
    public function it_handles_unsigned_columns()
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
    public function generated_migration_is_executable()
    {
        // Create a table
        Schema::create('test_users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
        });

        // Generate schema dump
        $schemaDump = $this->dumper->generateSchemaDump();

        // Drop the original table
        Schema::dropIfExists('test_users');

        // Save and execute the generated migration
        $filename = '0000_00_00_000000_test_executable.php';
        $this->dumper->saveSchemaDump($schemaDump, $filename);

        $migrationPath = database_path('migrations_test/' . $filename);
        $this->assertFileExists($migrationPath);

        // Include and execute the migration
        $migration = include $migrationPath;
        $migration->up();

        // Verify table was recreated
        $this->assertTrue(Schema::hasTable('test_users'));

        // Test down method
        $migration->down();
        $this->assertFalse(Schema::hasTable('test_users'));

        // Cleanup
        File::delete($migrationPath);
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

        $schemaDump = $this->dumper->generateSchemaDump();

        // Should contain index with multiple columns
        $this->assertStringContainsString('index(', $schemaDump);
    }
}
