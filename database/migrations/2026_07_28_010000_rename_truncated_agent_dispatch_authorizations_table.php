<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * O Postgres corta identificador em 63 caracteres — em silêncio, sem aviso nem
 * erro. A migration original pedia
 * `atlas_self_construction_agent_dispatch_executor_release_authorizations`
 * (70 chars) e o disco ficou com `..._release_authori`. Todo o código — 16
 * arquivos e o model, que derivava o nome da classe — passou meses consultando
 * um nome que não existe: a tabela nunca recebeu nem devolveu uma linha, e
 * nada nunca reclamou.
 *
 * Renomeia para 53 chars, no mesmo padrão das irmãs
 * (`atlas_self_construction_agent_dispatch_receipts`).
 *
 * Defensiva nas duas pontas: só age se houver o que renomear e nada no destino.
 * A migration original também foi corrigida, então uma instalação nova já cria
 * o nome certo e esta aqui vira no-op em vez de erro.
 */
return new class extends Migration
{
    private const TRUNCATED = 'atlas_self_construction_agent_dispatch_executor_release_authori';

    private const CORRECT = 'atlas_self_construction_agent_dispatch_authorizations';

    public function up(): void
    {
        if (Schema::hasTable(self::CORRECT) || ! Schema::hasTable(self::TRUNCATED)) {
            return;
        }

        DB::statement(sprintf('ALTER TABLE %s RENAME TO %s', self::TRUNCATED, self::CORRECT));
    }

    public function down(): void
    {
        if (Schema::hasTable(self::TRUNCATED) || ! Schema::hasTable(self::CORRECT)) {
            return;
        }

        DB::statement(sprintf('ALTER TABLE %s RENAME TO %s', self::CORRECT, self::TRUNCATED));
    }
};
