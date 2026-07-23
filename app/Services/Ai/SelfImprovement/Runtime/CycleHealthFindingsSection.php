<?php

namespace App\Services\Ai\SelfImprovement\Runtime;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Collection;

/**
 * Envelope/operation/gate/tool cycle-health findings family extracted VERBATIM from AtlasSelfImprovementRuntime
 * (GOD-DEBULK partial split). Scanner-pinned families remain on the facade;
 * the facade keeps same-signature delegators for every method here.
 */
class CycleHealthFindingsSection
{
    /**
     * @param  Collection<int,AtlasLedgerEvent>  $events
     * @return array<int,array<string,mixed>>
     */
    public function missingTerminalFindings(Collection $events, ?string $currentEnvelopeId = null): array
    {
        $terminal = [
            LedgerEventType::OperationCompleted->value,
            LedgerEventType::OperationFailed->value,
            LedgerEventType::OperationBlocked->value,
            LedgerEventType::OperationNeedsReview->value,
        ];

        return $events
            ->where('event_type', LedgerEventType::ExecutionStarted->value)
            ->groupBy('envelope_id')
            ->reject(fn (Collection $started, string $envelopeId): bool => $currentEnvelopeId !== null && $envelopeId === $currentEnvelopeId)
            ->filter(fn (Collection $started, string $envelopeId): bool => $events
                ->where('envelope_id', $envelopeId)
                ->whereIn('event_type', $terminal)
                ->isEmpty())
            ->map(fn (Collection $started, string $envelopeId): array => [
                'title' => 'Fechar envelopes sem evento terminal',
                'category' => 'self_improvement',
                'finding' => "Envelope {$envelopeId} iniciou execucao, mas nao registrou evento terminal no Evidence Ledger.",
                'problem' => 'Sem evento terminal, replay, metricas de sucesso/falha e aprendizado ficam incompletos.',
                'solution' => 'Instrumentar o emissor responsavel para publicar OPERATION_COMPLETED, OPERATION_FAILED, OPERATION_BLOCKED ou OPERATION_NEEDS_REVIEW.',
                'worth_it' => 'Vale porque completa a linha do tempo auditavel e evita que o Atlas aprenda com runs inacabados.',
                'best_solution_rationale' => 'Corrigir a instrumentacao do emissor preserva o contrato do kernel sem inventar regra especial por surface.',
                'alternatives' => ['Manter como warning ate confirmar se o processo ainda estava em andamento.', 'Adicionar TTL antes de considerar o envelope incompleto.'],
                'source_refs' => [['type' => 'ledger_envelope', 'id' => $envelopeId]],
                'confidence' => 0.86,
                'dedupe_key' => 'self-improvement:missing-terminal:'.sha1($envelopeId),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int,AtlasLedgerEvent>  $events
     * @return array<int,array<string,mixed>>
     */
    public function operationFailureFindings(Collection $events): array
    {
        return $events
            ->where('event_type', LedgerEventType::OperationFailed->value)
            ->groupBy('emitter_stage')
            ->filter(fn (Collection $group): bool => $group->count() >= 1)
            ->map(fn (Collection $group, string $stage): array => [
                'title' => "Reduzir falhas em {$stage}",
                'category' => 'self_improvement',
                'finding' => "{$group->count()} operacao(oes) falharam em {$stage} na janela analisada.",
                'problem' => 'Falhas repetidas por stage indicam lacuna de policy, provider, gate, repair ou contexto.',
                'solution' => 'Agrupar por envelope, comparar payload_hash e criar teste/regra de repair para a causa mais comum antes de alterar comportamento.',
                'worth_it' => 'Vale porque transforma falha operacional em backlog priorizado por evidencia real.',
                'best_solution_rationale' => 'Atacar a causa mais frequente reduz risco sem autoaplicar mudanca critica.',
                'alternatives' => ['Apenas observar por mais uma janela.', 'Abrir investigacao manual sem patch.'],
                'source_refs' => $group->take(5)->map(fn (AtlasLedgerEvent $event): array => [
                    'type' => 'ledger_event',
                    'id' => $event->event_id,
                    'envelope_id' => $event->envelope_id,
                ])->values()->all(),
                'confidence' => 0.82,
                'dedupe_key' => 'self-improvement:operation-failed:'.sha1($stage),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int,AtlasLedgerEvent>  $events
     * @return array<int,array<string,mixed>>
     */
    public function gateBlockedFindings(Collection $events): array
    {
        return $events
            ->where('event_type', LedgerEventType::GateBlocked->value)
            ->groupBy(fn (AtlasLedgerEvent $event): string => (string) data_get($event->payload, 'gate_type', $event->emitter_stage))
            ->map(fn (Collection $group, string $gate): array => [
                'title' => "Analisar gate bloqueando {$gate}",
                'category' => 'self_improvement',
                'finding' => "{$group->count()} bloqueio(s) de gate detectados para {$gate}.",
                'problem' => 'Gate bloqueando pode ser exatamente o comportamento correto, mas tambem pode indicar falta de evidencia, normalizer fraco ou threshold mal calibrado.',
                'solution' => 'Criar review de calibracao do gate com exemplos dos envelopes bloqueados, sem relaxar politica automaticamente.',
                'worth_it' => 'Vale porque melhora confianca sem reduzir rigor.',
                'best_solution_rationale' => 'Revisao por evidencias evita transformar bloqueio legitimo em bypass.',
                'alternatives' => ['Manter threshold atual.', 'Adicionar waiver especifico para finding conhecido.'],
                'source_refs' => $group->take(5)->map(fn (AtlasLedgerEvent $event): array => [
                    'type' => 'ledger_event',
                    'id' => $event->event_id,
                    'envelope_id' => $event->envelope_id,
                ])->values()->all(),
                'confidence' => 0.78,
                'dedupe_key' => 'self-improvement:gate-blocked:'.sha1($gate),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int,AtlasLedgerEvent>  $events
     * @return array<int,array<string,mixed>>
     */
    public function toolCoverageFindings(Collection $events): array
    {
        $harnessRuns = $events->filter(fn (AtlasLedgerEvent $event): bool => str_starts_with($event->envelope_id, 'engineering_run:'));
        if ($harnessRuns->isEmpty()) {
            return [];
        }

        $toolEvents = $harnessRuns->where('event_type', LedgerEventType::ToolEvidenceRecorded->value);
        if ($toolEvents->isNotEmpty()) {
            return [];
        }

        return [[
            'title' => 'Aumentar cobertura de tool evidence no Harness',
            'category' => 'self_improvement',
            'finding' => 'Runs do Engineering Harness foram observados sem TOOL_EVIDENCE_RECORDED na mesma janela.',
            'problem' => 'Sem tool evidence, gates e self-improvement dependem mais de scoring agregado do que de sensores normalizados.',
            'solution' => 'Garantir que quality scan, visual smoke ou tool gate relevante rode em pelo menos um perfil do Harness e publique evidencia no ledger.',
            'worth_it' => 'Vale porque fortalece o caminho Atlas Forge com provas verificaveis.',
            'best_solution_rationale' => 'Adicionar evidencia normalizada e melhor que aumentar confianca em resposta de provider.',
            'alternatives' => ['Manter tools apenas em perfis release.', 'Exigir tool evidence somente para tarefas critical.'],
            'source_refs' => $harnessRuns->take(5)->map(fn (AtlasLedgerEvent $event): array => [
                'type' => 'ledger_event',
                'id' => $event->event_id,
                'envelope_id' => $event->envelope_id,
            ])->values()->all(),
            'confidence' => 0.74,
            'dedupe_key' => 'self-improvement:harness-tool-coverage:v1',
        ]];
    }
}
