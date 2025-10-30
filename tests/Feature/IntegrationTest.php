<?php

namespace TarunKorat\LaravelMigrationSquasher\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use TarunKorat\LaravelMigrationSquasher\Tests\TestCase;

class IntegrationTest extends TestCase
{
    /** @test */
    public function it_performs_complete_squash_workflow()
    {
        // Step 1: Create database schema
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('posts', function ($table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('title');
            $table->text('body');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('comments', function ($table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained();
            $table->text('content');
            $table->timestamps();
        });

        // Step 2: Create corresponding migration files
        $this->createMigration('2023_01_01_000000_create_users_table.php');
        $this->createMigration('2023_02_01_000000_create_posts_table.php');
        $this->createMigration('2023_03_01_000000_create_comments_table.php');
        $this->createMigration('2024_10_01_000000_add_slug_to_posts.php');
        $this->createMigration('2024_10_15_000000_add_verified_at_to_users.php');

        $initialCount = $this->countMigrationFiles();
        $this->assertEquals(5, $initialCount);

        // Step 3: Run squash
        $this->artisan('migrations:squash', [
            '--before' => '2024-01-01',
            '--keep' => 2,
            '--no-interaction' => true
        ])->assertExitCode(0);

        // Step 4: Verify results
        // Should have: 1 schema dump + 2 recent migrations = 3 files
        $finalCount = $this->countMigrationFiles();
        $this->assertEquals(3, $finalCount);

        // Verify schema dump exists
        $this->assertMigrationExists(config('migration-squasher.schema_file'));

        // Verify recent migrations still exist
        $this->assertMigrationExists('2024_10_01_000000_add_slug_to_posts.php');
        $this->assertMigrationExists('2024_10_15_000000_add_verified_at_to_users.php');

        // Verify old migrations were deleted
        $this->assertMigrationNotExists('2023_01_01_000000_create_users_table.php');
        $this->assertMigrationNotExists('2023_02_01_000000_create_posts_table.php');
        $this->assertMigrationNotExists('2023_03_01_000000_create_comments_table.php');

        // Step 5: Verify backup was created
        $backupPath = database_path(config('migration-squasher.backup_path'));
        $this->assertTrue(File::exists($backupPath));

        // Step 6: Test that schema dump is functional
        // Drop all tables
        Schema::dropIfExists('comments');
        Schema::dropIfExists('posts');
        Schema::dropIfExists('users');

        // Run the schema dump migration
        $schemaFile = $this->testMigrationPath . '/' . config('migration-squasher.schema_file');
        $migration = include $schemaFile;
        $migration->up();

        // Verify all tables were recreated
        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(Schema::hasTable('posts'));
        $this->assertTrue(Schema::hasTable('comments'));

        // Verify columns exist
        $this->assertTrue(Schema::hasColumn('users', 'email'));
        $this->assertTrue(Schema::hasColumn('posts', 'title'));
        $this->assertTrue(Schema::hasColumn('comments', 'content'));
    }

    /** @test */
    public function it_handles_multiple_squash_operations()
    {
        // First squash
        $this->createMultipleMigrations(10, 500);

        $this->artisan('migrations:squash', [
            '--before' => '2023-01-01',
            '--keep' => 3,
            '--no-interaction' => true
        ])->assertExitCode(0);

        $firstSquashCount = $this->countMigrationFiles();

        // Create more migrations
        $this->createMultipleMigrations(5, 200);

        // Second squash
        $this->artisan('migrations:squash', [
            '--before' => '2024-01-01',
            '--keep' => 2,
            '--no-interaction' => true
        ])->assertExitCode(0);

        $secondSquashCount = $this->countMigrationFiles();

        // Should have fewer files after second squash
        $this->assertLessThanOrEqual($firstSquashCount, $secondSquashCount);
    }

    /** @test */
    public function it_maintains_database_integrity_after_squash()
    {
        // Create complex schema
        Schema::create('categories', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
        });

        Schema::create('products', function ($table) {
            $table->id();
            $table->foreignId('category_id')->constrained();
            $table->string('name');
            $table->decimal('price', 10, 2);
            $table->integer('stock')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('product_images', function ($table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->string('url');
            $table->integer('order')->default(0);
        });

        // Create migrations
        $this->createMultipleMigrations(5, 300);

        // Squash
        $this->artisan('migrations:squash', [
            '--before' => '2024-10-01',
            '--keep' => 0,
            '--no-interaction' => true
        ])->assertExitCode(0);

        // Verify schema structure is intact
        $this->assertTrue(Schema::hasTable('categories'));
        $this->assertTrue(Schema::hasTable('products'));
        $this->assertTrue(Schema::hasTable('product_images'));

        // Verify columns
        $this->assertTrue(Schema::hasColumn('products', 'price'));
        $this->assertTrue(Schema::hasColumn('products', 'is_active'));
        $this->assertTrue(Schema::hasColumn('product_images', 'order'));
    }

    /** @test */
    public function it_can_recover_from_backup()
    {
        // Create migrations
        $this->createMigration('2023_01_01_000000_important_migration.php');
        $this->createMigration('2023_06_01_000000_another_migration.php');

        // Squash with backup
        $this->artisan('migrations:squash', [
            '--before' => '2024-01-01',
            '--keep' => 0,
            '--no-interaction' => true
        ])->assertExitCode(0);

        // Verify migrations were deleted
        $this->assertMigrationNotExists('2023_01_01_000000_important_migration.php');
        $this->assertMigrationNotExists('2023_06_01_000000_another_migration.php');

        // Find backup
        $backupPath = database_path(config('migration-squasher.backup_path'));
        $backupDirs = File::directories($backupPath);
        $latestBackup = end($backupDirs);

        // Verify backup contains files
        $backupFiles = File::files($latestBackup);
        $this->assertCount(2, $backupFiles);

        // Restore from backup
        foreach ($backupFiles as $file) {
            File::copy(
                $file->getPathname(),
                $this->testMigrationPath . '/' . $file->getFilename()
            );
        }

        // Verify restored
        $this->assertMigrationExists('2023_01_01_000000_important_migration.php');
        $this->assertMigrationExists('2023_06_01_000000_another_migration.php');
    }

    /** @test */
    public function it_handles_edge_case_of_exactly_keep_recent_migrations()
    {
        // Create exactly 5 migrations
        $this->createMultipleMigrations(5, 100);

        // Try to keep 5 (all of them)
        $this->artisan('migrations:squash', [
            '--before' => '2024-10-01',
            '--keep' => 5,
            '--dry-run' => true
        ])->assertExitCode(0);

        // All migrations should remain
        $this->assertEquals(5, $this->countMigrationFiles());
    }

    /** @test */
    public function it_works_with_fresh_migrate_after_squashing()
    {
        // Create schema
        Schema::create('test_table', function ($table) {
            $table->id();
            $table->string('name');
        });

        // Create migrations
        $this->createMultipleMigrations(5, 400);

        // Squash
        $this->artisan('migrations:squash', [
            '--before' => '2024-10-01',
            '--keep' => 1,
            '--no-interaction' => true
        ])->assertExitCode(0);

        // Drop all tables
        Schema::dropIfExists('test_table');

        // Run migrate:fresh simulation
        $schemaFile = $this->testMigrationPath . '/' . config('migration-squasher.schema_file');
        $migration = include $schemaFile;
        $migration->up();

        // Verify table was recreated
        $this->assertTrue(Schema::hasTable('test_table'));
    }

    /** @test */
    public function it_preserves_migration_order_in_schema_dump()
    {
        // Create tables with dependencies
        Schema::create('countries', function ($table) {
            $table->id();
            $table->string('name');
        });

        Schema::create('cities', function ($table) {
            $table->id();
            $table->foreignId('country_id')->constrained();
            $table->string('name');
        });

        Schema::create('addresses', function ($table) {
            $table->id();
            $table->foreignId('city_id')->constrained();
            $table->string('street');
        });

        // Create migrations
        $this->createMultipleMigrations(3, 400);

        // Squash
        $this->artisan('migrations:squash', [
            '--before' => '2024-10-01',
            '--keep' => 0,
            '--no-interaction' => true
        ])->assertExitCode(0);

        // Drop tables in reverse order
        Schema::dropIfExists('addresses');
        Schema::dropIfExists('cities');
        Schema::dropIfExists('countries');

        // Run schema dump
        $schemaFile = $this->testMigrationPath . '/' . config('migration-squasher.schema_file');
        $migration = include $schemaFile;

        // Should not throw foreign key errors
        try {
            $migration->up();
            $success = true;
        } catch (\Exception $e) {
            $success = false;
        }

        $this->assertTrue($success, 'Schema dump should handle dependencies correctly');
    }

    /** @test */
    public function it_generates_proper_rollback_in_down_method()
    {
        Schema::create('test_users', function ($table) {
            $table->id();
            $table->string('name');
        });

        Schema::create('test_posts', function ($table) {
            $table->id();
            $table->string('title');
        });

        $this->createMultipleMigrations(2, 400);

        $this->artisan('migrations:squash', [
            '--before' => '2024-10-01',
            '--keep' => 0,
            '--no-interaction' => true
        ])->assertExitCode(0);

        // Test down method
        $schemaFile = $this->testMigrationPath . '/' . config('migration-squasher.schema_file');
        $migration = include $schemaFile;

        $this->assertTrue(Schema::hasTable('test_users'));
        $this->assertTrue(Schema::hasTable('test_posts'));

        $migration->down();

        $this->assertFalse(Schema::hasTable('test_users'));
        $this->assertFalse(Schema::hasTable('test_posts'));
    }
}
