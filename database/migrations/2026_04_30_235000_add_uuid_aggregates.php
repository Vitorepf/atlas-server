<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION uuid_max_state(uuid, uuid) RETURNS uuid AS $$
                SELECT GREATEST($1, $2);
            $$ LANGUAGE sql IMMUTABLE;

            CREATE OR REPLACE FUNCTION uuid_min_state(uuid, uuid) RETURNS uuid AS $$
                SELECT LEAST($1, $2);
            $$ LANGUAGE sql IMMUTABLE;

            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_aggregate a
                    JOIN pg_proc p ON a.aggfnoid = p.oid
                    JOIN pg_type t ON p.proargtypes[0] = t.oid
                    WHERE p.proname = 'max' AND t.typname = 'uuid'
                ) THEN
                    CREATE AGGREGATE max(uuid) (
                        SFUNC = uuid_max_state,
                        STYPE = uuid
                    );
                END IF;

                IF NOT EXISTS (
                    SELECT 1 FROM pg_aggregate a
                    JOIN pg_proc p ON a.aggfnoid = p.oid
                    JOIN pg_type t ON p.proargtypes[0] = t.oid
                    WHERE p.proname = 'min' AND t.typname = 'uuid'
                ) THEN
                    CREATE AGGREGATE min(uuid) (
                        SFUNC = uuid_min_state,
                        STYPE = uuid
                    );
                END IF;
            END$$;
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP AGGREGATE IF EXISTS max(uuid);
            DROP AGGREGATE IF EXISTS min(uuid);
            DROP FUNCTION IF EXISTS uuid_max_state(uuid, uuid);
            DROP FUNCTION IF EXISTS uuid_min_state(uuid, uuid);
        SQL);
    }
};
