<?php

namespace App\Console\Commands;

use App\Services\Ai\Memory\AtlasMemoryConflictResolutionService;

/**
 * Atlas Cognition Operating System — comando canonico de Absorcao 2 + 3 combinadas.
 *
 * `atlas:memory:judge` permite registrar verdict canonico entre duas memorias usando
 * os 6 verbos canon (Absorcao 2) e os 3 modos plan/dry-run/apply (Absorcao 3).
 *
 * Schemas combinados:
 *  - atlas.command.three_tier_envelope.v1 (Absorcao 3)
 *  - atlas.memory.relation_verdict.v1     (Absorcao 2)
 *
 * Modos:
 *  - plan      mostra o que faria, retorna verdict + escalation flag, sem persistir.
 *  - dry-run   abre transacao, persiste e rollback. Prova viabilidade.
 *  - apply     persiste de verdade. Exige `--check memory-judge-readiness` + `--confirm`.
 *
 * Check codes canonicos:
 *  - memory-judge-readiness (verifica memorias existem + verdict valido + escalation regra)
 *
 * Exemplo:
 *   php artisan atlas:memory:judge \
 *     --source=uuid-A --target=uuid-B \
 *     --verdict=supersedes \
 *     --actor=human \
 *     --confidence=0.92 \
 *     --reason="Postgres venceu" \
 *     --mode=apply --check=memory-judge-readiness --confirm \
 *     --json
 */
class AtlasMemoryJudgeCommand extends AtlasMutativeCommand
{
    protected $signature = 'atlas:memory:judge
        {--source= : Source memory entry UUID}
        {--target= : Target memory entry UUID}
        {--verdict= : One of: related|compatible|scoped|conflicts_with|supersedes|not_conflict}
        {--actor=unknown : agent|atlas|human|engram|unknown}
        {--model= : Optional model identifier (e.g. claude-sonnet-4-7)}
        {--confidence= : Float 0.0-1.0}
        {--reason= : Justification text (max 2000 chars)}
        {--evidence-refs=* : Evidence refs (repeatable)}
        {--allow-escalation-bypass : Allow agent/atlas to skip human escalation (audited)}
        {--force-not-conflict-persist : Force not_conflict from non-human actor to persist}
        {--mode=plan : plan|dry-run|apply}
        {--check= : Required check code for dry-run and apply}
        {--confirm : Required flag in apply mode}
        {--json : Emit JSON envelope}';

    protected $description = 'Atlas Cognition: register canonical conflict verdict between two memory entries (Absorcao 2 + 3).';

    public function handle(): int
    {
        return $this->handleMutative();
    }

    protected function mutativeName(): string
    {
        return 'atlas:memory:judge';
    }

    protected function availableCheckCodes(): array
    {
        return ['memory-judge-readiness'];
    }

    protected function buildContext(): array
    {
        return [
            'source' => (string) ($this->option('source') ?? ''),
            'target' => (string) ($this->option('target') ?? ''),
            'verdict' => (string) ($this->option('verdict') ?? ''),
            'actor' => (string) ($this->option('actor') ?? 'unknown'),
            'model' => $this->option('model'),
            'confidence' => $this->option('confidence') !== null ? (float) $this->option('confidence') : null,
            'reason' => $this->option('reason'),
            'evidence_refs' => array_values((array) $this->option('evidence-refs')),
            'allow_escalation_bypass' => (bool) $this->option('allow-escalation-bypass'),
            'force_not_conflict_persist' => (bool) $this->option('force-not-conflict-persist'),
        ];
    }

    protected function planActions(array $context): array
    {
        // Read-only: simula sem chamar service (que tocaria DB).
        $service = new AtlasMemoryConflictResolutionService;

        $verdict = $context['verdict'];
        $valid = AtlasMemoryConflictResolutionService::isValidVerdict($verdict);
        $visible = AtlasMemoryConflictResolutionService::isVisibleInSearch($verdict);

        // Para shouldEscalate ja precisamos saber memory_types — em plan mode
        // nao tocamos o DB, entao usamos heuristica conservadora.
        $escalation = $service->shouldEscalate(
            $verdict,
            $context['confidence'],
            // Conservador: assume high-risk para forcar visibilidade do gate.
            ['decision', 'architecture', 'policy']
        );

        return [
            'plan_summary' => "Persistir verdict '$verdict' entre source={$context['source']} e target={$context['target']}",
            'verdict' => $verdict,
            'verdict_valid' => $valid,
            'verdict_visible_in_search' => $visible,
            'actor' => $context['actor'],
            'confidence' => $context['confidence'],
            'predicted_escalation_required' => $escalation['required'],
            'predicted_escalation_reasons' => $escalation['reasons'],
            'note' => 'Plan mode nao toca DB; escalation usa heuristica conservadora assumindo memory_types high-risk.',
        ];
    }

    protected function dryRunActions(array $context): array
    {
        // Persist em transacao; base abstract garante rollback.
        $service = app(AtlasMemoryConflictResolutionService::class);
        $result = $service->judge(
            $context['source'],
            $context['target'],
            $context['verdict'],
            [
                'actor' => $context['actor'],
                'model' => $context['model'],
                'confidence' => $context['confidence'],
                'reason' => $context['reason'],
                'evidence_refs' => $context['evidence_refs'],
                'allow_escalation_bypass' => $context['allow_escalation_bypass'],
                'force_not_conflict_persist' => $context['force_not_conflict_persist'],
            ],
        );

        return [
            'service_result' => $result,
            'note' => 'Dry-run: transacao foi revertida; row nao persistiu.',
        ];
    }

    protected function applyActions(array $context): array
    {
        $service = app(AtlasMemoryConflictResolutionService::class);
        $result = $service->judge(
            $context['source'],
            $context['target'],
            $context['verdict'],
            [
                'actor' => $context['actor'],
                'model' => $context['model'],
                'confidence' => $context['confidence'],
                'reason' => $context['reason'],
                'evidence_refs' => $context['evidence_refs'],
                'allow_escalation_bypass' => $context['allow_escalation_bypass'],
                'force_not_conflict_persist' => $context['force_not_conflict_persist'],
            ],
        );

        return [
            'service_result' => $result,
        ];
    }

    protected function rollbackRef(array $context): ?string
    {
        // Toda decisão é reversível via comando inverso (humano edita ou apaga via outro AP).
        $source = $context['source'];
        $target = $context['target'];

        return "atlas:memory:judge --source=$target --target=$source --verdict=related --actor=human --mode=apply --check=memory-judge-readiness --confirm";
    }
}
