<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            UPDATE captures
            SET domain = 'outro'
            WHERE domain IS NULL OR BTRIM(domain) = '';

            ALTER TABLE inbox_health_snapshots
              DROP CONSTRAINT IF EXISTS inbox_health_snapshots_snapshot_date_domain_key;

            CREATE UNIQUE INDEX IF NOT EXISTS idx_inbox_health_unique_global_day
              ON inbox_health_snapshots(snapshot_date)
              WHERE domain IS NULL;

            CREATE UNIQUE INDEX IF NOT EXISTS idx_inbox_health_unique_domain_day
              ON inbox_health_snapshots(snapshot_date, domain)
              WHERE domain IS NOT NULL;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS idx_inbox_health_unique_domain_day;
            DROP INDEX IF EXISTS idx_inbox_health_unique_global_day;

            ALTER TABLE inbox_health_snapshots
              ADD CONSTRAINT inbox_health_snapshots_snapshot_date_domain_key
              UNIQUE(snapshot_date, domain);
        SQL);
    }
};
