<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('process_patterns')) {
            Schema::create('process_patterns', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 80)->unique();
                $table->string('category', 32);
                $table->string('intent', 200);
                $table->text('problem_context');
                $table->json('forces');
                $table->json('solution');
                $table->json('consequences');
                $table->json('anti_patterns')->nullable();
                $table->json('related_patterns')->nullable();
                $table->json('personal_evidence_refs')->nullable();
                $table->unsignedInteger('applied_count')->default(0);
                $table->unsignedInteger('success_count')->default(0);
                $table->unsignedInteger('failure_count')->default(0);
                $table->timestamp('last_applied_at')->nullable();
                $table->string('created_via', 32);
                $table->string('status', 32)->default('draft');
                $table->uuid('knowledge_node_id')->nullable();
                $table->timestamps();
                $table->index('category');
                $table->index('status');
                $table->index('last_applied_at');
            });
        }

        if (! Schema::hasTable('process_pattern_applications')) {
            Schema::create('process_pattern_applications', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('process_pattern_id')->constrained('process_patterns')->cascadeOnDelete();
                $table->uuid('envelope_id');
                $table->string('domain', 64);
                $table->string('outcome', 32);
                $table->json('outcome_evidence')->nullable();
                $table->text('reflection')->nullable();
                $table->timestamp('applied_at');
                $table->timestamps();
                $table->index(['process_pattern_id', 'applied_at']);
            });
        }

        $this->seedCanonicalPatterns();
    }

    public function down(): void
    {
        Schema::dropIfExists('process_pattern_applications');
        Schema::dropIfExists('process_patterns');
    }

    private function seedCanonicalPatterns(): void
    {
        if (! Schema::hasTable('process_patterns')) {
            return;
        }

        $now = now();
        foreach ($this->canonicalPatterns() as $pattern) {
            DB::table('process_patterns')->updateOrInsert(
                ['name' => $pattern['name']],
                [
                    ...$pattern,
                    'forces' => json_encode($pattern['forces'], JSON_THROW_ON_ERROR),
                    'solution' => json_encode($pattern['solution'], JSON_THROW_ON_ERROR),
                    'consequences' => json_encode($pattern['consequences'], JSON_THROW_ON_ERROR),
                    'anti_patterns' => json_encode($pattern['anti_patterns'], JSON_THROW_ON_ERROR),
                    'related_patterns' => json_encode($pattern['related_patterns'], JSON_THROW_ON_ERROR),
                    'personal_evidence_refs' => json_encode([], JSON_THROW_ON_ERROR),
                    'created_via' => 'canonical_seed',
                    'status' => 'active',
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function canonicalPatterns(): array
    {
        return [
            $this->pattern('cut-then-rebuild', 'decision', 'Abandonar tentativa saturada e reconstruir com escopo menor.', 'Quando a complexidade acumulada tornou a solucao atual mais cara que um rebuild controlado.'),
            $this->pattern('validate-then-scale', 'process', 'Validar em pequena escala antes de ampliar investimento.', 'Quando a decisao e reversivel, mas escala cedo demais aumenta custo de erro.'),
            $this->pattern('unblock-then-validate', 'process', 'Remover bloqueio com solucao temporaria e validar antes de oficializar.', 'Quando o custo de inacao supera o risco de uma solucao provisoria auditavel.'),
            $this->pattern('reverse-the-burden', 'decision', 'Mover o onus da decisao para a tese que esta bloqueando progresso.', 'Quando uma discussao circular permanece sem evidencia nova nem owner claro.'),
            $this->pattern('fail-fast-cheap', 'optimization', 'Projetar a menor falha barata capaz de invalidar a tese.', 'Quando aprender cedo vale mais que preservar uma hipotese bonita.'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function pattern(string $name, string $category, string $intent, string $context): array
    {
        return [
            'name' => $name,
            'category' => $category,
            'intent' => $intent,
            'problem_context' => $context,
            'forces' => [
                ['name' => 'speed', 'description' => 'Precisa gerar progresso sem perder governanca.'],
                ['name' => 'evidence', 'description' => 'Precisa trocar opiniao por prova observavel.'],
            ],
            'solution' => [
                'abstract' => $intent,
                'steps' => ['nomear contexto', 'escolher menor acao reversivel', 'medir resultado', 'registrar evidencia'],
                'applicability' => ['decision', 'process', 'programming', 'operations'],
            ],
            'consequences' => [
                'pros' => ['reduz bloqueio', 'gera aprendizado rastreavel'],
                'cons' => ['pode parecer menos elegante no curto prazo'],
                'trade_offs' => ['otimiza aprendizado antes de otimizacao final'],
            ],
            'anti_patterns' => [],
            'related_patterns' => [],
        ];
    }
};
