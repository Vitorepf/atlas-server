<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O vocabulario controlado de um dominio de estudo — a casa que faltava.
 *
 * Por que uma tabela nova em vez de reusar o que existe, medido antes de escrever:
 *
 *  - `OperatorTaxonomyRegistry` sao 170 itens sobre QUEM O OPERADOR E (SYS/OP/COL), com
 *    teste de contrato travando a contagem em 170. Termo de dominio nao cabe la, e o
 *    efeito de forcar era visivel: toda decisao de poquer entrava como `OP-071` — "Seu
 *    jeito preferido de receber resposta". Uma decisao de mao arquivada como preferencia
 *    de formatacao de texto.
 *  - `atlas_engineering_knowledge_items` e read model de doc do repo (`canonical_path`,
 *    `source_hash`), reconstruido por sync com `--prune`. Termo sem arquivo canonico
 *    seria podado no proximo ciclo.
 *  - `worked_examples` guarda EXEMPLO, nao TERMO, e seu `knowledge_node_id` e uuid NOT
 *    NULL apontando para tabela que nao existe neste banco.
 *
 * `domain` e o eixo que generaliza: o mesmo ciclo serve poquer, engenharia ou qualquer
 * area — e casa com `scope_id` do sinal, que ja carrega o dominio.
 *
 * A unicidade e por (domain, term_normalized), nao por (domain, term): sem dobrar acento
 * e caixa, "Mão" e "mao" entram como dois verbetes e a ancora do principio passa a
 * depender de como o operador digitou.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('atlas_study_vocabulary')) {
            return;
        }

        Schema::create('atlas_study_vocabulary', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('domain');
            $table->string('term');
            $table->string('term_normalized');
            $table->text('definition');
            $table->string('source_ref')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['domain', 'term_normalized']);
            $table->index(['domain', 'term']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_study_vocabulary');
    }
};
