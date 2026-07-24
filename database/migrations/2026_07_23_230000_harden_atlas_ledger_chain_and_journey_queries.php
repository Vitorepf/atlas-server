<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('atlas_ledger_events')) {
            return;
        }

        Schema::table('atlas_ledger_events', function (Blueprint $table): void {
            if (! Schema::hasColumn('atlas_ledger_events', 'scope_type')) {
                $table->string('scope_type', 40)->nullable();
            }
            if (! Schema::hasColumn('atlas_ledger_events', 'scope_id')) {
                $table->string('scope_id', 80)->nullable();
            }
            if (! Schema::hasColumn('atlas_ledger_events', 'event_hash')) {
                $table->string('event_hash', 64)->nullable();
            }
            if (! Schema::hasColumn('atlas_ledger_events', 'prev_event_hash')) {
                $table->string('prev_event_hash', 64)->nullable();
            }
            if (! Schema::hasColumn('atlas_ledger_events', 'chain_basis')) {
                $table->string('chain_basis', 40)->nullable();
            }
            if (! Schema::hasColumn('atlas_ledger_events', 'chain_key_hash')) {
                $table->string('chain_key_hash', 64)->nullable();
            }
            if (! Schema::hasColumn('atlas_ledger_events', 'chain_position')) {
                $table->unsignedBigInteger('chain_position')->nullable();
            }
        });

        $this->ensureIndex(['event_hash'], 'atlas_ledger_events_event_hash_index');
        $this->ensureIndex(['prev_event_hash'], 'atlas_ledger_events_prev_event_hash_index');
        $this->ensureIndex(['chain_basis'], 'atlas_ledger_events_chain_basis_index');
        $this->ensureIndex(
            ['tenant_id', 'event_type', 'occurred_at', 'event_id'],
            'atlas_ledger_events_tenant_type_window_index',
        );
        $this->ensureIndex(
            ['tenant_id', 'envelope_id', 'chain_position'],
            'atlas_ledger_events_tenant_envelope_position_index',
        );
        $this->ensureIndex(
            ['tenant_id', 'correlation_id', 'chain_position'],
            'atlas_ledger_events_tenant_correlation_position_index',
        );
        $this->ensureIndex(
            ['tenant_id', 'scope_type', 'scope_id', 'chain_position'],
            'atlas_ledger_events_tenant_scope_position_index',
        );
        $this->ensureUniqueIndex(
            ['tenant_id', 'chain_key_hash', 'chain_position'],
            'atlas_ledger_events_tenant_chain_position_unique',
        );

        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->installPostgresAppendOnlyGuard();
        }
    }

    public function down(): void
    {
        // Expand-only authority migration: historical evidence and its guard are never removed.
    }

    /**
     * @param  array<int,string>  $columns
     */
    private function ensureIndex(array $columns, string $name): void
    {
        if (Schema::hasIndex('atlas_ledger_events', $name)) {
            return;
        }

        Schema::table('atlas_ledger_events', function (Blueprint $table) use ($columns, $name): void {
            $table->index($columns, $name);
        });
    }

    /**
     * @param  array<int,string>  $columns
     */
    private function ensureUniqueIndex(array $columns, string $name): void
    {
        if (Schema::hasIndex('atlas_ledger_events', $name)) {
            return;
        }

        Schema::table('atlas_ledger_events', function (Blueprint $table) use ($columns, $name): void {
            $table->unique($columns, $name);
        });
    }

    private function installPostgresAppendOnlyGuard(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_atlas_ledger_events_updated_at ON atlas_ledger_events;

            CREATE OR REPLACE FUNCTION atlas_reject_ledger_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $function$
            BEGIN
                RAISE EXCEPTION 'atlas_ledger_events is append-only: % is forbidden', TG_OP
                    USING ERRCODE = '55000';
            END;
            $function$;

            DROP TRIGGER IF EXISTS trg_atlas_ledger_events_append_only ON atlas_ledger_events;
            CREATE TRIGGER trg_atlas_ledger_events_append_only
            BEFORE UPDATE OR DELETE ON atlas_ledger_events
            FOR EACH ROW EXECUTE FUNCTION atlas_reject_ledger_mutation();

            REVOKE ALL ON TABLE atlas_ledger_events FROM PUBLIC;
        SQL);

        $this->configureRole((string) config('database.connections.atlas_p2_pg_runtime.username'), true);
        $this->configureRole((string) config('database.connections.atlas_p2_pg_verifier.username'), false);
    }

    private function configureRole(string $role, bool $mayInsert): void
    {
        $role = trim($role);
        if ($role === '' || preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $role) !== 1) {
            return;
        }

        $exists = DB::table('pg_roles')->where('rolname', $role)->exists();
        if (! $exists) {
            return;
        }

        $quoted = '"'.str_replace('"', '""', $role).'"';
        DB::statement("GRANT USAGE ON SCHEMA public TO {$quoted}");
        DB::statement("REVOKE ALL ON TABLE atlas_ledger_events FROM {$quoted}");
        DB::statement("GRANT SELECT ON TABLE atlas_ledger_events TO {$quoted}");
        if ($mayInsert) {
            DB::statement("GRANT INSERT ON TABLE atlas_ledger_events TO {$quoted}");
        }
    }
};
