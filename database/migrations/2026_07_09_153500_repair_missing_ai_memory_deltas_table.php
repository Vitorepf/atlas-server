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
        if (! Schema::hasTable('ai_memory_deltas')) {
            Schema::create('ai_memory_deltas', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('source_trace_id')->nullable()->index();
                $table->uuid('source_session_id')->nullable()->index();
                $table->string('source_workspace')->nullable();
                $table->string('type', 32)->default('process');
                $table->text('claim');
                $table->json('evidence');
                $table->string('scope', 255)->default('global');
                $table->float('confidence')->default(0.5);
                $table->timestamp('valid_from')->nullable();
                $table->timestamp('valid_until')->nullable();
                $table->json('use_when')->nullable();
                $table->json('do_not_use_when')->nullable();
                $table->boolean('requires_confirmation')->default(true);
                $table->string('status', 16)->default('pending');
                $table->uuid('superseded_by')->nullable();
                $table->uuid('promoted_memory_entry_id')->nullable()->index();
                $table->timestamp('promoted_at')->nullable()->index();
                $table->timestamps();

                $table->index(['status', 'type']);
                $table->index(['scope', 'status']);
            });
        } else {
            Schema::table('ai_memory_deltas', function (Blueprint $table): void {
                if (! Schema::hasColumn('ai_memory_deltas', 'promoted_memory_entry_id')) {
                    $table->uuid('promoted_memory_entry_id')->nullable()->index();
                }
                if (! Schema::hasColumn('ai_memory_deltas', 'promoted_at')) {
                    $table->timestamp('promoted_at')->nullable()->index();
                }
            });
        }

        if (DB::connection()->getDriverName() === 'pgsql'
            && $this->setUpdatedAtFunctionExists()
            && ! $this->triggerExists()) {
            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_ai_memory_deltas_updated_at
                BEFORE UPDATE ON ai_memory_deltas
                FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            SQL);
        }
    }

    /**
     * Repair migrations must never remove a canonical table on rollback.
     */
    public function down(): void
    {
        // Intentionally irreversible.
    }

    private function setUpdatedAtFunctionExists(): bool
    {
        $result = DB::selectOne(
            "SELECT EXISTS (SELECT 1 FROM pg_proc WHERE proname = 'set_updated_at') AS exists",
        );

        return (bool) ($result->exists ?? false);
    }

    private function triggerExists(): bool
    {
        $result = DB::selectOne(<<<'SQL'
            SELECT EXISTS (
                SELECT 1
                FROM pg_trigger
                WHERE tgname = 'trg_ai_memory_deltas_updated_at'
                  AND NOT tgisinternal
            ) AS exists
        SQL);

        return (bool) ($result->exists ?? false);
    }
};
