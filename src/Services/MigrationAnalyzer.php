<?php

namespace TarunKorat\LaravelMigrationSquasher\Services;

use Illuminate\Support\Facades\File;
use Carbon\Carbon;

class MigrationAnalyzer
{
    protected string $migrationPath;

    public function __construct(?string $migrationPath = null)
    {
        $this->migrationPath = $migrationPath ?? database_path(config('migration-squasher.migration_path', 'migrations'));
    }

    /**
     * Get all migrations that should be squashed.
     *
     * @param string $beforeDate Date in Y-m-d format
     * @param int $keepRecent Number of recent migrations to keep
     * @return array
     */
    public function getMigrationsToSquash(string $beforeDate, int $keepRecent): array
    {
        $allMigrations = $this->getAllMigrations();

        if (empty($allMigrations)) {
            return [];
        }

        $sortedMigrations = $this->sortMigrationsByDate($allMigrations);

        // Keep recent migrations
        $migrationsToKeep = array_slice($sortedMigrations, -$keepRecent);
        $keepFileNames = array_column($migrationsToKeep, 'filename');

        // Filter by date and exclude recent ones
        $beforeTimestamp = Carbon::parse($beforeDate)->timestamp;

        return array_filter($sortedMigrations, function ($migration) use ($beforeTimestamp, $keepFileNames) {
            return $migration['timestamp'] < $beforeTimestamp
                && !in_array($migration['filename'], $keepFileNames);
        });
    }

    /**
     * Get all migration files.
     *
     * @return array
     */
    public function getAllMigrations(): array
    {
        if (!File::exists($this->migrationPath)) {
            return [];
        }

        $files = File::files($this->migrationPath);
        $migrations = [];

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $filename = $file->getFilename();

            // Skip if already a schema dump
            if (str_contains($filename, 'schema_dump')) {
                continue;
            }

            // Skip if it doesn't match migration naming pattern
            if (!preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}_.+\.php$/', $filename)) {
                continue;
            }

            $migrations[] = [
                'filename' => $filename,
                'path' => $file->getPathname(),
                'timestamp' => $this->extractTimestamp($filename),
                'name' => $this->extractMigrationName($filename),
            ];
        }

        return $migrations;
    }

    /**
     * Extract timestamp from migration filename.
     *
     * @param string $filename
     * @return int
     */
    protected function extractTimestamp(string $filename): int
    {
        // Extract date from filename: 2024_01_15_120000_create_users_table.php
        preg_match('/^(\d{4})_(\d{2})_(\d{2})_(\d{6})/', $filename, $matches);

        if (count($matches) !== 5) {
            return 0;
        }

        $date = "{$matches[1]}-{$matches[2]}-{$matches[3]} " .
            substr($matches[4], 0, 2) . ":" .
            substr($matches[4], 2, 2) . ":" .
            substr($matches[4], 4, 2);

        try {
            return Carbon::parse($date)->timestamp;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Extract migration name from filename.
     *
     * @param string $filename
     * @return string
     */
    protected function extractMigrationName(string $filename): string
    {
        // Remove .php extension
        $name = str_replace('.php', '', $filename);

        // Remove timestamp prefix
        return preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', $name);
    }

    /**
     * Sort migrations by timestamp.
     *
     * @param array $migrations
     * @return array
     */
    protected function sortMigrationsByDate(array $migrations): array
    {
        usort($migrations, fn ($a, $b) => $a['timestamp'] <=> $b['timestamp']);
        return $migrations;
    }

    /**
     * Get migration statistics.
     *
     * @return array
     */
    public function getStatistics(): array
    {
        $allMigrations = $this->getAllMigrations();

        if (empty($allMigrations)) {
            return [
                'total' => 0,
                'oldest' => null,
                'newest' => null,
            ];
        }

        $sorted = $this->sortMigrationsByDate($allMigrations);

        return [
            'total' => count($sorted),
            'oldest' => $sorted[0]['filename'] ?? null,
            'newest' => $sorted[count($sorted) - 1]['filename'] ?? null,
            'oldest_date' => isset($sorted[0]) ? date('Y-m-d H:i:s', $sorted[0]['timestamp']) : null,
            'newest_date' => isset($sorted[count($sorted) - 1]) ? date('Y-m-d H:i:s', $sorted[count($sorted) - 1]['timestamp']) : null,
        ];
    }
}
