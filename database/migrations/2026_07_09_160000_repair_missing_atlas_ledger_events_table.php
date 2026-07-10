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
            Schema::create('atlas_ledger_events', function (Blueprint $table): void {
                $table->string('event_id', 32)->primary();
                $table->string('schema_version', 40)->default('atlas.ledger_event.v1');
                $table->string('tenant_id', 120)->index();
                $table->string('operator_id', 120)->index();
                $table->string('envelope_id', 80)->index();
                $table->string('receipt_id', 80)->nullable()->index();
                $table->string('trace_id', 80)->nullable()->index();
                $table->string('correlation_id', 120)->index();
                $table->string('causation_id', 80)->nullable()->index();
                $table->string('event_type', 80)->index();
                $table->string('emitter_stage', 120)->index();
                $table->string('emitter_version', 80);
                $table->json('payload');
                $table->string('payload_hash', 64)->index();
                $table->timestampTz('occurred_at')->index();
                $table->timestampsTz();
                $table->index(['tenant_id', 'occurred_at'], 'atlas_ledger_events_tenant_occurred_index');
                $table->index(['envelope_id', 'occurred_at'], 'atlas_ledger_events_envelope_occurred_index');
                $table->index(['event_type', 'occurred_at'], 'atlas_ledger_events_type_occurred_index');
            });
        }

        if (DB::getDriverName() === 'pgsql'
            && $this->setUpdatedAtFunctionExists()
            && ! $this->triggerExists()) {
            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_atlas_ledger_events_updated_at
                BEFORE UPDATE ON atlas_ledger_events
                FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            SQL);
        }
    }

    public function down(): void
    {
        // Evidence is append-only; a repair rollback never deletes the ledger.
    }

    private function setUpdatedAtFunctionExists(): bool
    {
        return (bool) (DB::selectOne(
            "SELECT EXISTS (SELECT 1 FROM pg_proc WHERE proname = 'set_updated_at') AS exists",
        )->exists ?? false);
    }

    private function triggerExists(): bool
    {
        return (bool) (DB::selectOne(<<<'SQL'
            SELECT EXISTS (
                SELECT 1 FROM pg_trigger
                WHERE tgname = 'trg_atlas_ledger_events_updated_at'
                  AND NOT tgisinternal
            ) AS exists
        SQL)->exists ?? false);
    }
};
