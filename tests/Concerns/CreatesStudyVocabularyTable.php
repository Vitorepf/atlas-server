<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Espelha `2026_07_28_020000_create_atlas_study_vocabulary_table`.
 *
 * A suite nao roda o conjunto inteiro de migrations (custo por teste), entao a tabela e
 * criada a mao — mesmo padrao de `CreatesOperatorIntelligenceTables`. O par migration ↔
 * trait tem de andar junto; se divergirem, o teste passa verde sobre uma forma que a
 * producao nao tem.
 */
trait CreatesStudyVocabularyTable
{
    protected function createStudyVocabularyTable(): void
    {
        $this->dropStudyVocabularyTable();

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

    protected function dropStudyVocabularyTable(): void
    {
        Schema::dropIfExists('atlas_study_vocabulary');
    }
}
