<?php

namespace TarunKorat\LaravelMigrationSquasher\Tests\Unit;

use TarunKorat\LaravelMigrationSquasher\Services\MigrationAnalyzer;
use TarunKorat\LaravelMigrationSquasher\Tests\TestCase;

class MigrationAnalyzerTest extends TestCase
{
    protected MigrationAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new MigrationAnalyzer($this->testMigrationPath);
    }

    /** @test */
    public function it_returns_empty_array_when_no_migrations_exist()
    {
        $migrations = $this->analyzer->getAllMigrations();

        $this->assertIsArray($migrations);
        $this->assertEmpty($migrations);
    }

    /** @test */
    public function it_gets_all_migration_files()
    {
        // Create 5 test migrations
        $this->createMultipleMigrations(5);

        $migrations = $this->analyzer->getAllMigrations();

        $this->assertCount(5, $migrations);
        $this->assertArrayHasKey('filename', $migrations[0]);
        $this->assertArrayHasKey('path', $migrations[0]);
        $this->assertArrayHasKey('timestamp', $migrations[0]);
        $this->assertArrayHasKey('name', $migrations[0]);
    }

    /** @test */
    public function it_excludes_schema_dump_files()
    {
        // Create regular migrations
        $this->createMultipleMigrations(3);

        // Create schema dump file
        $this->createMigration('0000_00_00_000000_schema_dump.php');

        $migrations = $this->analyzer->getAllMigrations();

        // Should only return 3, excluding schema dump
        $this->assertCount(3, $migrations);

        foreach ($migrations as $migration) {
            $this->assertStringNotContainsString('schema_dump', $migration['filename']);
        }
    }

    /** @test */
    public function it_ignores_non_migration_files()
    {
        // Create valid migration
        $this->createMigration('2024_01_01_000000_create_users_table.php');

        // Create invalid files
        file_put_contents($this->testMigrationPath . '/README.md', '# Test');
        file_put_contents($this->testMigrationPath . '/invalid_file.php', '<?php // Invalid');
        file_put_contents($this->testMigrationPath . '/.gitkeep', '');

        $migrations = $this->analyzer->getAllMigrations();

        // Should only return the valid migration
        $this->assertCount(1, $migrations);
    }

    /** @test */
    public function it_extracts_timestamp_correctly()
    {
        $this->createMigration('2023_06_15_143022_create_posts_table.php');

        $migrations = $this->analyzer->getAllMigrations();

        $this->assertCount(1, $migrations);

        $expectedTimestamp = strtotime('2023-06-15 14:30:22');
        $this->assertEquals($expectedTimestamp, $migrations[0]['timestamp']);
    }

    /** @test */
    public function it_extracts_migration_name_correctly()
    {
        $this->createMigration('2024_01_01_000000_create_users_table.php');

        $migrations = $this->analyzer->getAllMigrations();

        $this->assertEquals('create_users_table', $migrations[0]['name']);
    }

    /** @test */
    public function it_identifies_migrations_to_squash_by_date()
    {
        // Create old migrations (2023)
        $this->createMigration('2023_01_01_000000_old_migration_1.php');
        $this->createMigration('2023_06_01_000000_old_migration_2.php');

        // Create recent migrations (2024)
        $this->createMigration('2024_10_01_000000_recent_migration_1.php');
        $this->createMigration('2024_10_15_000000_recent_migration_2.php');

        $toSquash = $this->analyzer->getMigrationsToSquash('2024-01-01', 0);

        // Should only return 2 old migrations
        $this->assertCount(2, $toSquash);

        foreach ($toSquash as $migration) {
            $this->assertStringStartsWith('2023', $migration['filename']);
        }
    }

    /** @test */
    public function it_keeps_recent_migrations_when_squashing()
    {
        // Create 10 migrations
        $this->createMultipleMigrations(10, 100);

        // Squash, but keep 3 most recent
        $toSquash = $this->analyzer->getMigrationsToSquash('2024-10-01', 3);

        // Should squash 7, keep 3
        $this->assertCount(7, $toSquash);
    }

    /** @test */
    public function it_returns_empty_when_all_migrations_are_recent()
    {
        // Create very recent migrations (today)
        $this->createMultipleMigrations(5, 0);

        $toSquash = $this->analyzer->getMigrationsToSquash('2023-01-01', 5);

        $this->assertEmpty($toSquash);
    }

    /** @test */
    public function it_sorts_migrations_by_date()
    {
        // Create migrations in random order
        $this->createMigration('2023_12_01_000000_third.php');
        $this->createMigration('2023_01_01_000000_first.php');
        $this->createMigration('2023_06_01_000000_second.php');

        $migrations = $this->analyzer->getAllMigrations();
        $sorted = array_column($migrations, 'filename');

        // Verify they are sorted chronologically
        $this->assertEquals('2023_01_01_000000_first.php', $sorted[0]);
        $this->assertEquals('2023_06_01_000000_second.php', $sorted[1]);
        $this->assertEquals('2023_12_01_000000_third.php', $sorted[2]);
    }

    /** @test */
    public function it_provides_accurate_statistics()
    {
        // Create migrations spanning different dates
        $this->createMigration('2023_01_01_120000_oldest.php');
        $this->createMigration('2023_06_15_140000_middle.php');
        $this->createMigration('2024_10_29_160000_newest.php');

        $stats = $this->analyzer->getStatistics();

        $this->assertEquals(3, $stats['total']);
        $this->assertStringContainsString('oldest', $stats['oldest']);
        $this->assertStringContainsString('newest', $stats['newest']);
        $this->assertNotNull($stats['oldest_date']);
        $this->assertNotNull($stats['newest_date']);
    }

    /** @test */
    public function it_handles_empty_directory_statistics()
    {
        $stats = $this->analyzer->getStatistics();

        $this->assertEquals(0, $stats['total']);
        $this->assertNull($stats['oldest']);
        $this->assertNull($stats['newest']);
    }

    /** @test */
    public function it_handles_keep_recent_greater_than_total_migrations()
    {
        // Create 3 migrations
        $this->createMultipleMigrations(3, 100);

        // Try to keep 10 (more than exist)
        $toSquash = $this->analyzer->getMigrationsToSquash('2024-10-01', 10);

        // Should return empty as we're keeping more than we have
        $this->assertEmpty($toSquash);
    }

    /** @test */
    public function it_respects_both_date_and_keep_recent_criteria()
    {
        // Create migrations from 2022
        $this->createMigration('2022_01_01_000000_very_old_1.php');
        $this->createMigration('2022_06_01_000000_very_old_2.php');

        // Create migrations from 2023
        $this->createMigration('2023_01_01_000000_old_1.php');
        $this->createMigration('2023_06_01_000000_old_2.php');
        $this->createMigration('2023_12_01_000000_old_3.php');

        // Create migrations from 2024
        $this->createMigration('2024_01_01_000000_recent_1.php');
        $this->createMigration('2024_10_01_000000_recent_2.php');

        // Squash before 2024, but keep 2 most recent
        $toSquash = $this->analyzer->getMigrationsToSquash('2024-01-01', 2);

        // Should squash: 2 from 2022 + 3 from 2023 = 5 total
        $this->assertCount(5, $toSquash);

        // Verify all squashed migrations are before 2024
        foreach ($toSquash as $migration) {
            $this->assertStringStartsNotWith('2024', $migration['filename']);
        }
    }
}
