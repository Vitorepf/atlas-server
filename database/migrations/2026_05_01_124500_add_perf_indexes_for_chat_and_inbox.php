<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Índices de performance idempotentes:
 *
 * - ai_traces(thread_id, created_at DESC): chat carrega traces de uma
 *   thread ordenados por created_at; antes caía no idx de status, fazendo
 *   scan filtrado.
 * - ai_inbox_items(user_id, status, expires_at) parcial: a query do
 *   AtlasInboxService filtra status active + expires_at > now(); o índice
 *   composto existente cobre user_id+status+created_at mas não expires_at,
 *   forçando filter pós-scan.
 *
 * Conforme CLAUDE.md: tudo idempotente (IF NOT EXISTS), sem editar
 * tabela `migrations`, sem carimbo manual.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_ai_traces_thread_created
            ON ai_traces (thread_id, created_at DESC)
            WHERE thread_id IS NOT NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_ai_inbox_items_user_active_expiry
            ON ai_inbox_items (user_id, status, expires_at)
            WHERE status NOT IN ('resolved', 'dismissed', 'expired')
        SQL);
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS idx_ai_traces_thread_created');
        DB::statement('DROP INDEX IF EXISTS idx_ai_inbox_items_user_active_expiry');
    }
};
