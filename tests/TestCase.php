<?php

namespace TarunKorat\LaravelMigrationSquasher\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Orchestra\Testbench\TestCase as Orchestra;
use TarunKorat\LaravelMigrationSquasher\MigrationSquasherServiceProvider;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected string $testMigrationPath;
    protected string $testBackupPath;

    protected function setUp(): void
    {
        parent::setUp();

        // Setup test migration paths
        $this->testMigrationPath = database_path('migrations_test');
        $this->testBackupPath = database_path('migrations_backup_test');

        // Create directories
        $this->createTestDirectories();

        // Configure package to use test paths
        config([
            'migration-squasher.migration_path' => 'migrations_test',
            'migration-squasher.backup_path' => 'migrations_backup_test',
            'migration-squasher.squash_before' => '2024-01-01',
            'migration-squasher.keep_recent' => 5,
        ]);
    }

    protected function tearDown(): void
    {
        // Clean up test directories
        $this->cleanTestDirectories();

        parent::tearDown();
    }

    /**
     * Get package providers.
     */
    protected function getPackageProviders($app): array
    {
        return [
            MigrationSquasherServiceProvider::class,
        ];
    }

    /**
     * Define environment setup.
     */
    protected function defineEnvironment($app): void
    {
        // Setup default database to use sqlite :memory:
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Setup package configuration
        $app['config']->set('migration-squasher.squash_before', '2024-01-01');
        $app['config']->set('migration-squasher.keep_recent', 5);
    }

    /**
     * Resolve application Console Kernel implementation (for Laravel 12+).
     */
    protected function resolveApplicationConsoleKernel($app): void
    {
        $app->singleton(
            \Illuminate\Contracts\Console\Kernel::class,
            \Orchestra\Testbench\Console\Kernel::class
        );
    }

    /**
     * Create test directories
     */
    protected function createTestDirectories(): void
    {
        if (!File::exists($this->testMigrationPath)) {
            File::makeDirectory($this->testMigrationPath, 0755, true);
        }

        if (!File::exists($this->testBackupPath)) {
            File::makeDirectory($this->testBackupPath, 0755, true);
        }
    }

    /**
     * Clean test directories
     */
    protected function cleanTestDirectories(): void
    {
        if (File::exists($this->testMigrationPath)) {
            File::deleteDirectory($this->testMigrationPath);
        }

        if (File::exists($this->testBackupPath)) {
            File::deleteDirectory($this->testBackupPath);
        }
    }

    /**
     * Create a test migration file
     */
    protected function createMigration(string $filename, ?string $content = null): string
    {
        $path = $this->testMigrationPath . '/' . $filename;

        $content = $content ?? $this->getDefaultMigrationContent($filename);

        File::put($path, $content);

        return $path;
    }

    /**
     * Create multiple test migrations
     */
    protected function createMultipleMigrations(int $count, int $daysAgo = 0): array
    {
        $migrations = [];

        for ($i = 1; $i <= $count; $i++) {
            $date = date('Y_m_d_His', strtotime("-" . ($daysAgo + $i) . " days"));
            $filename = "{$date}_test_migration_{$i}.php";
            $migrations[] = $this->createMigration($filename);
        }

        return $migrations;
    }

    /**
     * Get default migration content
     */
    protected function getDefaultMigrationContent(string $filename): string
    {
        $tableName = $this->extractTableName($filename);

        return <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('{$tableName}', function (Blueprint \$table) {
            \$table->id();
            \$table->string('name');
            \$table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('{$tableName}');
    }
};
PHP;
    }

    /**
     * Extract table name from migration filename
     */
    protected function extractTableName(string $filename): string
    {
        // Remove timestamp and .php extension
        $name = preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', $filename);
        $name = str_replace('.php', '', $name);

        // Extract table name (e.g., create_users_table -> users)
        if (preg_match('/create_(.+)_table/', $name, $matches)) {
            return $matches[1];
        }

        return 'test_' . str_replace('_', '', $name);
    }

    /**
     * Run migrations and populate database
     */
    protected function runTestMigrations(array $migrationFiles): void
    {
        foreach ($migrationFiles as $file) {
            $migration = include $file;
            $migration->up();
        }
    }

    /**
     * Assert migration file exists
     */
    protected function assertMigrationExists(string $filename): void
    {
        $this->assertFileExists(
            $this->testMigrationPath . '/' . $filename,
            "Migration file {$filename} does not exist"
        );
    }

    /**
     * Assert migration file does not exist
     */
    protected function assertMigrationNotExists(string $filename): void
    {
        $this->assertFileDoesNotExist(
            $this->testMigrationPath . '/' . $filename,
            "Migration file {$filename} still exists"
        );
    }

    /**
     * Count migration files
     */
    protected function countMigrationFiles(): int
    {
        if (!File::exists($this->testMigrationPath)) {
            return 0;
        }

        $files = File::files($this->testMigrationPath);
        return count(array_filter($files, fn($file) => $file->getExtension() === 'php'));
    }
}
