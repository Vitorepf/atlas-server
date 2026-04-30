<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS atlas_task_events (
              id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
              task_id UUID NOT NULL REFERENCES atlas_tasks(id) ON DELETE CASCADE,
              event_type TEXT NOT NULL,
              source TEXT NOT NULL DEFAULT 'app',
              payload JSONB NOT NULL DEFAULT '{}'::jsonb,
              occurred_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
              created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
              updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            );

            CREATE INDEX IF NOT EXISTS idx_atlas_task_events_task
              ON atlas_task_events(task_id, occurred_at DESC);
            CREATE INDEX IF NOT EXISTS idx_atlas_task_events_type
              ON atlas_task_events(event_type, occurred_at DESC);

            DROP TRIGGER IF EXISTS trg_atlas_task_events_updated_at ON atlas_task_events;
            CREATE TRIGGER trg_atlas_task_events_updated_at
            BEFORE UPDATE ON atlas_task_events
            FOR EACH ROW
            EXECUTE FUNCTION set_updated_at();

            CREATE TABLE IF NOT EXISTS atlas_calendar_blocks (
              id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
              block_date DATE NOT NULL,
              timezone TEXT NOT NULL,
              title TEXT NOT NULL,
              starts_at TIMESTAMPTZ NOT NULL,
              ends_at TIMESTAMPTZ NOT NULL,
              source TEXT NOT NULL DEFAULT 'manual'
                CHECK (source IN ('manual', 'external_calendar', 'rize', 'system')),
              source_ref TEXT,
              task_id UUID REFERENCES atlas_tasks(id) ON DELETE SET NULL,
              metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
              created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
              updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
              deleted_at TIMESTAMPTZ,
              CONSTRAINT atlas_calendar_blocks_time_order
                CHECK (ends_at > starts_at)
            );

            CREATE INDEX IF NOT EXISTS idx_atlas_calendar_blocks_date
              ON atlas_calendar_blocks(block_date, starts_at)
              WHERE deleted_at IS NULL;
            CREATE INDEX IF NOT EXISTS idx_atlas_calendar_blocks_source
              ON atlas_calendar_blocks(source, source_ref)
              WHERE deleted_at IS NULL;
            CREATE INDEX IF NOT EXISTS idx_atlas_calendar_blocks_task
              ON atlas_calendar_blocks(task_id)
              WHERE deleted_at IS NULL;

            DROP TRIGGER IF EXISTS trg_atlas_calendar_blocks_updated_at ON atlas_calendar_blocks;
            CREATE TRIGGER trg_atlas_calendar_blocks_updated_at
            BEFORE UPDATE ON atlas_calendar_blocks
            FOR EACH ROW
            EXECUTE FUNCTION set_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_atlas_calendar_blocks_updated_at ON atlas_calendar_blocks;
            DROP TRIGGER IF EXISTS trg_atlas_task_events_updated_at ON atlas_task_events;
            DROP TABLE IF EXISTS atlas_calendar_blocks;
            DROP TABLE IF EXISTS atlas_task_events;
        SQL);
    }
};
