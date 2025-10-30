<?php

namespace TarunKorat\LaravelMigrationSquasher\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use TarunKorat\LaravelMigrationSquasher\Services\MigrationAnalyzer;
use TarunKorat\LaravelMigrationSquasher\Services\SchemaDumper;

class SquashMigrationsCommand extends Command
{
    protected $signature = 'migrations:squash
                            {--before= : Squash migrations before this date (Y-m-d)}
                            {--keep= : Number of recent migrations to keep}
                            {--path= : Custom migration path}
                            {--dry-run : Show what would be squashed without making changes}
                            {--no-backup : Do not create backup of squashed migrations}
                            {--delete-records : Delete squashed migration records from database}';

    protected $description = 'Squash old migrations into a single schema file (Laravel 8-12 compatible)';

    protected MigrationAnalyzer $analyzer;
    protected SchemaDumper $dumper;

    public function __construct(MigrationAnalyzer $analyzer, SchemaDumper $dumper)
    {
        parent::__construct();
        $this->analyzer = $analyzer;
        $this->dumper = $dumper;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->displayHeader();

        // Get options
        $beforeDate = $this->option('before') ?? config('migration-squasher.squash_before');
        $keepRecent = (int) ($this->option('keep') ?? config('migration-squasher.keep_recent'));
        $isDryRun = $this->option('dry-run');
        $createBackup = !$this->option('no-backup') && config('migration-squasher.backup_migrations');
        $deleteRecords = $this->option('delete-records') || config('migration-squasher.delete_batch_records');

        // Validate date
        if (!$this->isValidDate($beforeDate)) {
            $this->error("❌ Invalid date format: {$beforeDate}. Please use Y-m-d format (e.g., 2024-01-01)");
            return 1;
        }

        // Display current settings
        $this->displaySettings($beforeDate, $keepRecent, $isDryRun, $createBackup);

        // Get statistics
        $stats = $this->analyzer->getStatistics();
        $this->displayStatistics($stats);

        if ($stats['total'] === 0) {
            $this->info("\n✅ No migrations found to squash!");
            return 0;
        }

        // Analyze migrations
        $this->info("\n🔍 Analyzing migrations...");
        $migrationsToSquash = $this->analyzer->getMigrationsToSquash($beforeDate, $keepRecent);

        if (empty($migrationsToSquash)) {
            $this->info("\n✅ No migrations found to squash based on your criteria!");
            $this->line("   Try adjusting --before date or --keep count.");
            return 0;
        }

        // Display migrations to squash
        $this->displayMigrationsToSquash($migrationsToSquash);

        if ($isDryRun) {
            $this->warn("\n🏃 Dry run mode - no changes made");
            $this->line("   Remove --dry-run flag to perform actual squash");
            return 0;
        }

        // Confirm action
        if (!$this->confirm("\n❓ Do you want to proceed with squashing?", false)) {
            $this->info("Operation cancelled.");
            return 0;
        }

        // Perform squash
        try {
            $this->performSquash($migrationsToSquash, $createBackup, $deleteRecords);
        } catch (\Exception $e) {
            $this->error("\n❌ Error during squash: " . $e->getMessage());
            $this->line("   Stack trace: " . $e->getTraceAsString());
            return 1;
        }

        return 0;
    }

    /**
     * Perform the actual squashing operation.
     */
    protected function performSquash(array $migrationsToSquash, bool $createBackup, bool $deleteRecords): void
    {
        // Step 1: Backup migrations
        if ($createBackup) {
            $this->info("\n📦 Creating backup...");
            $backupPath = $this->backupMigrations($migrationsToSquash);
            $this->info("   ✓ Backup created: {$backupPath}");
        }

        // Step 2: Generate schema dump
        $this->info("\n🏗️  Generating schema dump from database...");
        $this->line("   This may take a moment for large databases...");

        $schemaDump = $this->dumper->generateSchemaDump();

        // Step 3: Save schema file
        $schemaFilename = config('migration-squasher.schema_file');
        $this->dumper->saveSchemaDump($schemaDump, $schemaFilename);
        $this->info("   ✓ Schema dump created: database/migrations/{$schemaFilename}");

        // Step 4: Delete migration records from database
        if ($deleteRecords) {
            $this->info("\n🗄️  Updating migrations table...");
            $this->deleteMigrationRecords($migrationsToSquash);
            $this->insertSchemaDumpRecord($schemaFilename);
            $this->info("   ✓ Migration records updated");
        }

        // Step 5: Delete old migration files
        $this->info("\n🗑️  Removing squashed migration files...");
        foreach ($migrationsToSquash as $migration) {
            File::delete($migration['path']);
        }
        $this->info("   ✓ Removed " . count($migrationsToSquash) . " migration files");

        // Success summary
        $this->displaySuccess(count($migrationsToSquash), $schemaFilename);
    }

    /**
     * Backup migrations before deletion.
     */
    protected function backupMigrations(array $migrations): string
    {
        $backupBasePath = database_path(config('migration-squasher.backup_path'));

        if (!File::exists($backupBasePath)) {
            File::makeDirectory($backupBasePath, 0755, true);
        }

        $timestamp = date('Y-m-d_His');
        $backupDir = $backupBasePath . '/' . $timestamp;
        File::makeDirectory($backupDir, 0755, true);

        foreach ($migrations as $migration) {
            File::copy(
                $migration['path'],
                $backupDir . '/' . $migration['filename']
            );
        }

        return $backupDir;
    }

    /**
     * Delete migration records from database.
     */
    protected function deleteMigrationRecords(array $migrations): void
    {
        $migrationNames = array_map(function ($migration) {
            return str_replace('.php', '', $migration['filename']);
        }, $migrations);

        DB::table('migrations')
            ->whereIn('migration', $migrationNames)
            ->delete();
    }

    /**
     * Insert schema dump record into migrations table.
     */
    protected function insertSchemaDumpRecord(string $filename): void
    {
        $migrationName = str_replace('.php', '', $filename);

        DB::table('migrations')->insert([
            'migration' => $migrationName,
            'batch' => 1,
        ]);
    }

    /**
     * Display command header.
     */
    protected function displayHeader(): void
    {
        $this->newLine();
        $this->line('╔════════════════════════════════════════════════════════════════╗');
        $this->line('║           Laravel Migration Squasher (v1.0.0)                 ║');
        $this->line('║                  Laravel 8-12 Compatible                       ║');
        $this->line('╚════════════════════════════════════════════════════════════════╝');
        $this->newLine();
    }

    /**
     * Display current settings.
     */
    protected function displaySettings(string $beforeDate, int $keepRecent, bool $isDryRun, bool $createBackup): void
    {
        $this->line("⚙️  <fg=yellow>Settings:</>");
        $this->line("   Squash Before: <fg=cyan>{$beforeDate}</>");
        $this->line("   Keep Recent:   <fg=cyan>{$keepRecent} migrations</>");
        $this->line("   Dry Run:       <fg=cyan>" . ($isDryRun ? 'Yes' : 'No') . "</>");
        $this->line("   Backup:        <fg=cyan>" . ($createBackup ? 'Yes' : 'No') . "</>");
    }

    /**
     * Display migration statistics.
     */
    protected function displayStatistics(array $stats): void
    {
        if ($stats['total'] === 0) {
            return;
        }

        $this->newLine();
        $this->line("📊 <fg=yellow>Current Migrations:</>");
        $this->line("   Total:      <fg=cyan>{$stats['total']} migrations</>");
        $this->line("   Oldest:     <fg=cyan>{$stats['oldest_date']}</> ({$stats['oldest']})");
        $this->line("   Newest:     <fg=cyan>{$stats['newest_date']}</> ({$stats['newest']})");
    }

    /**
     * Display migrations that will be squashed.
     */
    protected function displayMigrationsToSquash(array $migrations): void
    {
        $this->newLine();
        $this->line("📝 <fg=yellow>Found " . count($migrations) . " migrations to squash:</>");

        $count = count($migrations);
        $displayLimit = 10;

        foreach (array_slice($migrations, 0, $displayLimit) as $migration) {
            $this->line("   <fg=gray>→</> {$migration['filename']}");
        }

        if ($count > $displayLimit) {
            $remaining = $count - $displayLimit;
            $this->line("   <fg=gray>... and {$remaining} more</>");
        }
    }

    /**
     * Display success message.
     */
    protected function displaySuccess(int $count, string $schemaFilename): void
    {
        $this->newLine();
        $this->line('╔════════════════════════════════════════════════════════════════╗');
        $this->line('║                  ✨ SQUASH COMPLETED! ✨                       ║');
        $this->line('╚════════════════════════════════════════════════════════════════╝');
        $this->newLine();
        $this->line("   <fg=green>✓</> Squashed:     <fg=cyan>{$count} migrations</>");
        $this->line("   <fg=green>✓</> Schema File:  <fg=cyan>database/migrations/{$schemaFilename}</>");
        $this->newLine();
        $this->info("💡 Next steps:");
        $this->line("   1. Test with: php artisan migrate:fresh");
        $this->line("   2. Commit changes to version control");
        $this->line("   3. Share with your team!");
        $this->newLine();
    }

    /**
     * Check if date is valid.
     */
    protected function isValidDate(string $date): bool
    {
        try {
            $d = \DateTime::createFromFormat('Y-m-d', $date);
            return $d && $d->format('Y-m-d') === $date;
        } catch (\Exception $e) {
            return false;
        }
    }
}
