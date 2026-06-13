<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * L5-3 — Auto-cura da suíte real.
 *
 * Snapshot append-only do número REAL de testes vermelhos por semana ISO, derivado
 * de um relatório de teste real (não de um número fornecido pelo chamador). É a fonte
 * de verdade do trend que destrava o claim L5-3 (gate exige ≥2 semanas REAIS caindo).
 *
 * Idempotente por (domain, iso_week): um segundo snapshot da MESMA semana atualiza o
 * último valor real medido — nunca cunha um ponto "decrescente" falso na mesma semana.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('atlas_suite_red_snapshots')) {
            Schema::create('atlas_suite_red_snapshots', function (Blueprint $table): void {
                $table->id();
                $table->string('domain', 64)->default('programming');
                // ISO-8601 week, ex: 2026-W24 — uma linha por (domain, semana).
                $table->string('iso_week', 12);
                // Contagem REAL de testes vermelhos derivada das linhas do relatório.
                $table->unsignedInteger('failed_count');
                $table->unsignedInteger('total_tests_seen')->default(0);
                $table->unsignedInteger('environmental_count')->default(0);
                $table->unsignedInteger('real_failure_count')->default(0);
                $table->unsignedInteger('unknown_count')->default(0);
                // Hash do relatório-fonte: prova proveniência real (anti-synthetic).
                $table->string('source_report_hash', 80)->nullable();
                $table->string('source_report_path', 512)->nullable();
                $table->timestamp('captured_at');
                $table->timestamps();
                $table->unique(['domain', 'iso_week']);
                $table->index(['domain', 'captured_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_suite_red_snapshots');
    }
};
