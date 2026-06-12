<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * L3-1 · Porta governada do merge-livre v2 (decisão do operador 12/06).
 *
 * O schema 2026_06_02_000200 cravou a invariante propose-only em DUAS camadas de DB:
 * um CHECK constraint absoluto (merged_to_main = false) e um trigger que sempre lança.
 * O CHECK é intransponível — nem uma transação governada o abre — então o
 * AtlasLoopAutoMergeService commitava em MAIN de verdade mas não conseguia REGISTRAR o
 * merge (a proposta voltava a ficar "drenável" para sempre).
 *
 * Esta migration evolui a invariante sem afrouxá-la por default:
 *   - REMOVE o CHECK absoluto (ele não distingue o merge governado do acidental);
 *   - SUBSTITUI a função do trigger por uma versão que só PERMITE merged_to_main=true
 *     quando a sessão declarou explicitamente `SET LOCAL atlas.governed_merge='on'`
 *     (o que apenas o auto-merger faz, dentro da sua transação).
 *
 * Resultado: never-merge continua sendo o estado default à prova de bug (qualquer
 * caminho sem o setting é bloqueado no banco); a única porta é o ato governado e
 * explícito do operador. Idempotente por construção (DROP IF EXISTS + CREATE OR REPLACE).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE atlas_loop_proposals DROP CONSTRAINT IF EXISTS chk_atlas_loop_proposals_never_merged');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION atlas_loop_block_merge() RETURNS trigger AS $$
            BEGIN
                IF NEW.merged_to_main IS TRUE
                   AND COALESCE(current_setting('atlas.governed_merge', true), 'off') <> 'on' THEN
                    RAISE EXCEPTION 'atlas_loop_proposals.merged_to_main must be false (propose-only invariant; governed merge-livre v2 requires SET LOCAL atlas.governed_merge=on)';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        // O trigger 2026_06_02_000200 já aponta para esta função; CREATE OR REPLACE basta.
        // Garante existência caso a ordem de migração difira no ambiente.
        DB::statement('DROP TRIGGER IF EXISTS atlas_loop_block_merge_trg ON atlas_loop_proposals');
        DB::statement('CREATE TRIGGER atlas_loop_block_merge_trg BEFORE INSERT OR UPDATE ON atlas_loop_proposals FOR EACH ROW EXECUTE FUNCTION atlas_loop_block_merge()');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Reverte para a invariante absoluta original (propose-only sem porta governada).
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION atlas_loop_block_merge() RETURNS trigger AS $$
            BEGIN
                IF NEW.merged_to_main IS TRUE THEN
                    RAISE EXCEPTION 'atlas_loop_proposals.merged_to_main must be false (propose-only invariant)';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement("ALTER TABLE atlas_loop_proposals ADD CONSTRAINT chk_atlas_loop_proposals_never_merged CHECK (merged_to_main = false) NOT VALID");
    }
};
