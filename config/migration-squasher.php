<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Squash Before Date
    |--------------------------------------------------------------------------
    | Migrations older than this date will be squashed into schema file.
    | Format: Y-m-d (e.g., 2024-01-01)
    | You can override this with --before option in command
    */
    'squash_before' => env('MIGRATION_SQUASH_BEFORE', '2024-01-01'),

    /*
    |--------------------------------------------------------------------------
    | Keep Recent Migrations Count
    |--------------------------------------------------------------------------
    | Number of recent migrations to keep separate (not squash).
    | Useful to keep track of latest database changes.
    */
    'keep_recent' => (int) env('MIGRATION_SQUASH_KEEP_RECENT', 10),

    /*
    |--------------------------------------------------------------------------
    | Schema File Name
    |--------------------------------------------------------------------------
    | Name of the generated schema dump file.
    | Using 0000_00_00 prefix ensures it runs first.
    */
    'schema_file' => '0000_00_00_000000_schema_dump.php',

    /*
    |--------------------------------------------------------------------------
    | Backup Migrations
    |--------------------------------------------------------------------------
    | Create backup of squashed migrations before deletion.
    | Highly recommended to keep this enabled.
    */
    'backup_migrations' => env('MIGRATION_SQUASH_BACKUP', true),

    /*
    |--------------------------------------------------------------------------
    | Backup Path
    |--------------------------------------------------------------------------
    | Path where backup migrations will be stored.
    | Relative to database path.
    */
    'backup_path' => env('MIGRATION_SQUASH_BACKUP_PATH', 'migrations/backup'),

    /*
    |--------------------------------------------------------------------------
    | Excluded Tables
    |--------------------------------------------------------------------------
    | Tables that should not be included in schema dump.
    | 'migrations' table is always excluded.
    */
    'excluded_tables' => [
        'migrations',
        // Add other tables to exclude if needed
    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Path
    |--------------------------------------------------------------------------
    | Path to migrations folder relative to database path.
    | Usually you don't need to change this.
    */
    'migration_path' => 'migrations',

    /*
    |--------------------------------------------------------------------------
    | Delete Batch Records
    |--------------------------------------------------------------------------
    | Whether to delete squashed migration records from migrations table.
    | If true, only schema_dump record will remain for squashed migrations.
    */
    'delete_batch_records' => false,
];
