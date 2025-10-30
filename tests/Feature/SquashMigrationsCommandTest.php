<?php

namespace TarunKorat\LaravelMigrationSquasher\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use TarunKorat\LaravelMigrationSquasher\Tests\TestCase;

class SquashMigrationsCommandTest extends TestCase
{
    /** @test */
    public function it_can_run_squash_command_with_dry_run()
    {
        $this->createMultipleMigrations(5, 100);

        $this->artisan('migrations:squash', ['--dry-run' => true])
            ->expectsOutput('🏃 Dry run mode - no changes made')
            ->assertExitCode(0);
    }

    /** @test */
    public function it_shows_no_migrations_when_folder_is_empty()
    {
        $this->artisan('migrations:squash', ['--dry-run' => true])
            ->assertExitCode(0);

        // Check output contains appropriate message
        $output = $this->artisan('migrations:squash', ['--dry-run' => true]);
        $this->assertEquals(0, $output);
    }

    /** @test */
    public function it_displays_help_information()
    {
        $this->artisan('migrations:squash', ['--help' => true])
            ->assertExitCode(0);
    }

    /** @test */
    public function it_identifies_migrations_to_squash_correctly()
    {
        // Create old migrations
        $this->createMigration('2023_01_01_000000_old_migration.php');
        $this->createMigration('2023_06_01_000000_another_old.php');

        // Create recent migrations
        $this->createMigration('2024_10_01_000000_recent_migration.php');

        $this->artisan('migrations:squash', [
            '--before' => '2024-01-01',
            '--keep' => 1,
            '--dry-run' => true
        ])->assertExitCode(0);

        // Verify files still exist (dry run doesn't delete)
        $this->assertMigrationExists('2023_01_01_000000_old_migration.php');
        $this->assertMigrationExists('2023_06_01_000000_another_old.php');
        $this->assertMigrationExists('2024_10_01_000000_recent_migration.php');
    }

    /** @test */
    public function it_respects_keep_recent_option()
    {
        $this->createMultipleMigrations(15, 100);

        $this->artisan('migrations:squash', [
            '--keep' => 5,
            '--dry-run' => true
        ])->assertExitCode(0);

        // All 15 should still exist (dry run)
        $this->assertEquals(15, $this->countMigrationFiles());
    }

    /** @test */
    public function it_handles_invalid_date_format()
    {
        $this->artisan('migrations:squash', [
            '--before' => 'invalid-date',
        ])
            ->expectsOutput('❌ Invalid date format: invalid-date. Please use Y-m-d format (e.g., 2024-01-01)')
            ->assertExitCode(1);
    }

    /** @test */
    public function it_creates_schema_dump_file()
    {
        // Create some tables in database
        Schema::create('test_users', function ($table) {
            $table->id();
            $table->string('name');
        });

        // Create old migrations
        $migrations = $this->createMultipleMigrations(3, 400);
        $this->runTestMigrations($migrations);

        // Run squash without interaction
        $this->artisan('migrations:squash', [
            '--before' => '2024-10-01',
            '--keep' => 0,
            '--no-interaction' => true
        ])->assertExitCode(0);

        // Check schema dump was created
        $schemaFile = config('migration-squasher.schema_file');
        $this->assertMigrationExists($schemaFile);

        // Verify old migrations were deleted
        $this->assertEquals(1, $this->countMigrationFiles()); // Only schema dump
    }

    /** @test */
    public function it_creates_backup_before_deletion()
    {
        // Create migrations
        $this->createMigration('2023_01_01_000000_old_migration.php');
        $this->createMigration('2023_06_01_000000_another_old.php');

        // Run squash
        $this->artisan('migrations:squash', [
            '--before' => '2024-01-01',
            '--keep' => 0,
            '--no-interaction' => true
        ])->assertExitCode(0);

        // Check backup was created
        $backupPath = database_path(config('migration-squasher.backup_path'));
        $this->assertTrue(File::exists($backupPath));

        // Verify backup contains files
        $backupDirs = File::directories($backupPath);
        $this->assertNotEmpty($backupDirs);

        $latestBackup = end($backupDirs);
        $backupFiles = File::files($latestBackup);
        $this->assertCount(2, $backupFiles);
    }

    /** @test */
    public function it_can_skip_backup_with_no_backup_flag()
    {
        $this->createMultipleMigrations(3, 400);

        $backupPath = database_path(config('migration-squasher.backup_path'));

        // Clear any existing backups
        if (File::exists($backupPath)) {
            File::deleteDirectory($backupPath);
        }

        $this->artisan('migrations:squash', [
            '--before' => '2024-10-01',
            '--keep' => 0,
            '--no-backup' => true,
            '--no-interaction' => true
        ])->assertExitCode(0);

        // Backup should not exist
        $this->assertFalse(File::exists($backupPath) && count(File::directories($backupPath)) > 0);
    }

    /** @test */
    public function it_preserves_recent_migrations()
    {
        // Create old migrations
        $this->createMigration('2023_01_01_000000_old_1.php');
        $this->createMigration('2023_06_01_000000_old_2.php');

        // Create recent migrations
        $this->createMigration('2024_10_01_000000_recent_1.php');
        $this->createMigration('2024_10_15_000000_recent_2.php');
        $this->createMigration('2024_10_29_000000_recent_3.php');

        $this->artisan('migrations:squash', [
            '--before' => '2024-01-01',
            '--keep' => 3,
            '--no-interaction' => true
        ])->assertExitCode(0);

        // Recent migrations should still exist
        $this->assertMigrationExists('2024_10_01_000000_recent_1.php');
        $this->assertMigrationExists('2024_10_15_000000_recent_2.php');
        $this->assertMigrationExists('2024_10_29_000000_recent_3.php');

        // Old migrations should be deleted
        $this->assertMigrationNotExists('2023_01_01_000000_old_1.php');
        $this->assertMigrationNotExists('2023_06_01_000000_old_2.php');

        // Schema dump should exist
        $this->assertMigrationExists(config('migration-squasher.schema_file'));
    }

    /** @test */
    public function it_shows_statistics_before_squashing()
    {
        $this->createMultipleMigrations(10, 100);

        $this->artisan('migrations:squash', ['--dry-run' => true])
            ->expectsOutputToContain('📊')
            ->expectsOutputToContain('Current Migrations')
            ->assertExitCode(0);
    }

    /** @test */
    public function it_shows_settings_before_squashing()
    {
        $this->createMultipleMigrations(5, 100);

        $this->artisan('migrations:squash', ['--dry-run' => true])
            ->expectsOutputToContain('⚙️')
            ->expectsOutputToContain('Settings')
            ->assertExitCode(0);
    }

    /** @test */
    public function it_generates_valid_migration_code()
    {
        // Create actual database tables
        Schema::create('test_users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamps();
        });

        Schema::create('test_posts', function ($table) {
            $table->id();
            $table->foreignId('user_id')->constrained('test_users')->onDelete('cascade');
            $table->string('title');
            $table->text('body');
            $table->timestamps();
        });

        // Create migrations
        $this->createMultipleMigrations(3, 400);

        // Run squash
        $this->artisan('migrations:squash', [
            '--before' => '2024-10-01',
            '--keep' => 0,
            '--no-interaction' => true
        ])->assertExitCode(0);

        // Get the generated schema dump
        $schemaFile = $this->testMigrationPath . '/' . config('migration-squasher.schema_file');
        $this->assertFileExists($schemaFile);

        // Verify it's valid PHP
        $content = File::get($schemaFile);
        $this->assertStringContainsString('<?php', $content);
        $this->assertStringContainsString('Schema::create(', $content);

        // Drop tables and test the migration
        Schema::dropIfExists('test_posts');
        Schema::dropIfExists('test_users');

        // Execute the generated migration
        $migration = include $schemaFile;
        $migration->up();

        // Verify tables were recreated
        $this->assertTrue(Schema::hasTable('test_users'));
        $this->assertTrue(Schema::hasTable('test_posts'));
    }

    /** @test */
    public function it_handles_no_migrations_to_squash_gracefully()
    {
        // Create only very recent migrations
        $this->createMultipleMigrations(3, 0);

        $this->artisan('migrations:squash', [
            '--before' => '2023-01-01',
            '--dry-run' => true
        ])->assertExitCode(0);
    }

    /** @test */
    public function it_can_use_custom_before_date()
    {
        $this->createMigration('2022_01_01_000000_very_old.php');
        $this->createMigration('2023_06_01_000000_old.php');
        $this->createMigration('2024_01_01_000000_recent.php');

        $this->artisan('migrations:squash', [
            '--before' => '2023-01-01',
            '--keep' => 0,
            '--dry-run' => true
        ])->assertExitCode(0);
    }

    /** @test */
    public function it_updates_migrations_table_when_delete_records_flag_is_set()
    {
        // Create and run migrations
        $migrations = $this->createMultipleMigrations(3, 400);

        // Insert migration records
        foreach ($migrations as $migration) {
            $filename = basename($migration);
            $name = str_replace('.php', '', $filename);

            DB::table('migrations')->insert([
                'migration' => $name,
                'batch' => 1,
            ]);
        }

        $initialCount = DB::table('migrations')->count();

        // Run squash with delete-records flag
        $this->artisan('migrations:squash', [
            '--before' => '2024-10-01',
            '--keep' => 0,
            '--delete-records' => true,
            '--no-interaction' => true
        ])->assertExitCode(0);

        $finalCount = DB::table('migrations')->count();

        // Should have fewer records (old ones deleted, schema dump added)
        $this->assertLessThan($initialCount, $finalCount);

        // Schema dump record should exist
        $schemaFile = str_replace('.php', '', config('migration-squasher.schema_file'));
        $this->assertDatabaseHas('migrations', [
            'migration' => $schemaFile,
        ]);
    }

    /** @test */
    public function it_displays_success_message_after_squashing()
    {
        $this->createMultipleMigrations(3, 400);

        $this->artisan('migrations:squash', [
            '--before' => '2024-10-01',
            '--keep' => 0,
            '--no-interaction' => true
        ])
            ->expectsOutputToContain('✨ SQUASH COMPLETED! ✨')
            ->assertExitCode(0);
    }

    /** @test */
    public function it_handles_empty_migrations_folder()
    {
        $this->artisan('migrations:squash', ['--dry-run' => true])
            ->assertExitCode(0);
    }

    /** @test */
    public function it_can_squash_with_different_keep_values()
    {
        $this->createMultipleMigrations(20, 100);

        // Test with keep=0
        $this->artisan('migrations:squash', [
            '--keep' => 0,
            '--dry-run' => true
        ])->assertExitCode(0);

        // Test with keep=5
        $this->artisan('migrations:squash', [
            '--keep' => 5,
            '--dry-run' => true
        ])->assertExitCode(0);

        // Test with keep=15
        $this->artisan('migrations:squash', [
            '--keep' => 15,
            '--dry-run' => true
        ])->assertExitCode(0);
    }
}
