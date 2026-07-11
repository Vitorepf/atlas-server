<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition;

use App\Models\AiCompoundingMemory;
use App\Models\AiRagFeedbackEvent;
use App\Services\Ai\AtlasOpenBrainWriteBackService;
use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\Brain\AtlasEvolutionDiary;
use App\Services\Ai\Compounding\AtlasLearningRecallUseLiftService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Obra #14 — as 3 notas da evolução do ACOS pedidas pelo operador, RESOLVIDAS
 * de evidência em runtime (padrão anti-over-claim do scorecard v3: nenhum
 * literal auto-declarado; mover a evidência move a nota):
 *
 *   1. execucao_provada      — a dimensão pipeline do scorecard v3 (green-run
 *                              receipts reais, freshness-bound).
 *   2. inteligencia_entregue — cadência viva dos órgãos + pack sem lixo (echo
 *                              marcado por proveniência) + loop de feedback com
 *                              eventos reais + lições geridas (quarentena→promoção).
 *   3. autonomia             — motor launchd pulsando + gates de longo horizonte
 *                              auditados + cadeia de promoção de tier implementada
 *                              e testada + execução governada (Carta de Autonomia
 *                              Regra 4: a assinatura do operador foi REVOGADA; a
 *                              parcela que dela dependia agora mede o substituto
 *                              REAL — reversibilidade viva (git revert +
 *                              atlas:brain:replay) + Diário de Evolução íntegro,
 *                              "a licença que substitui a aprovação").
 *
 * DEGRADE-SAFE: toda probe que falha (tabela ausente, arquivo ausente, classe
 * inexistente) pontua 0 com evidência dizendo por quê — nunca um ready falso.
 */
class AtlasAcosEvolutionScoreService
{
    public const SCHEMA_VERSION = 'atlas.cognition.evolution_score.v1';

    /** Janela de frescor do heartbeat do scheduler (motor vivo). */
    private const HEARTBEAT_FRESH_SECONDS = 7200;

    /** Janela de frescor de receipts/série do gate de longo horizonte. */
    private const GATE_FRESH_SECONDS = 172800;

    /** Comandos-órgão cuja presença agendada é exigida pela cadência H2.1. */
    private const SCHEDULED_ORGANS = [
        'atlas:cognition:mint-pipeline-receipts',
        'atlas:fable:delta-series',
        'atlas:engineering:refactor-census',
        'acos-harvest-obra-lessons',
    ];

    /** FQN da cadeia de promoção de tier (H3.2); probe por class_exists. */
    private const TIER_CHAIN_CLASS = 'App\Services\Ai\AutonomousEvolution\AtlasLoopTierPromotionChainService';

    /** FQN do comando de reversão da memória (Carta Regra 4); probe por class_exists. */
    private const REVERSAL_COMMAND_CLASS = 'App\Console\Commands\AtlasBrainReplayCommand';

    /** Minimum live A/B cases per arm before feedback_loop_vivo can score fully. */
    private const LIFT_CASES_PER_ARM_REQUIRED = 10;

    public function __construct(
        private readonly AtlasCognitionScoreCardService $scorecard = new AtlasCognitionScoreCardService,
        private readonly AtlasLearningRecallUseLiftService $lift = new AtlasLearningRecallUseLiftService,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function build(): array
    {
        $card = $this->scorecard->build();

        $execucao = $this->execucaoProvada($card);
        $inteligencia = $this->inteligenciaEntregue();
        $autonomia = $this->autonomia();

        $overall = round(($execucao['score'] + $inteligencia['score'] + $autonomia['score']) / 3, 2);

        $envelope = [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => gmdate('c'),
            'overall_out_of_10' => $overall,
            'dimensions' => [
                'execucao_provada' => $execucao,
                'inteligencia_entregue' => $inteligencia,
                'autonomia' => $autonomia,
            ],
            'acos_scorecard_overall' => (float) data_get($card, 'score.overall_out_of_10', 0.0),
            'notes' => [
                'method' => 'Toda parcela é função de evidência resolvida em runtime (probe de DB/arquivo/agenda/classe); nenhum literal auto-declarado.',
                'autonomy_governance' => 'Carta de Autonomia (Regra 4): a assinatura do operador foi revogada; a parcela antes presa a ela agora mede o substituto REAL — reversibilidade viva (git revert + atlas:brain:replay) + Diário de Evolução íntegro. Degrade-safe: sem esse substrato, pontua 0, nunca fabricado.',
            ],
        ];
        $envelope['score_hash'] = 'sha256:'.hash('sha256', (string) json_encode([
            $overall, $execucao['score'], $inteligencia['score'], $autonomia['score'],
        ], JSON_THROW_ON_ERROR));

        return $envelope;
    }

    /**
     * @param  array<string,mixed>  $card
     * @return array<string,mixed>
     */
    private function execucaoProvada(array $card): array
    {
        $pipeline = (float) data_get($card, 'score.dimensions.pipeline.score_out_of_10', 0.0);

        return [
            'score' => round($pipeline, 2),
            'max' => 10.0,
            'signals' => [[
                'signal' => 'pipeline_green_run_receipts',
                'points' => round($pipeline, 2),
                'max' => 10.0,
                'evidence' => 'scorecard v3 dimensão pipeline (green-run receipts reais, freshness-bound)',
            ]],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function inteligenciaEntregue(): array
    {
        $signals = [];

        $heartbeatFresh = $this->heartbeatFresh();
        $organsScheduled = $this->scheduledOrganCount();
        $signals[] = [
            'signal' => 'cadencia_viva',
            'points' => round(($heartbeatFresh ? 1.25 : 0.0) + 1.25 * ($organsScheduled / count(self::SCHEDULED_ORGANS)), 2),
            'max' => 2.5,
            'evidence' => sprintf('heartbeat_fresh=%s organs_scheduled=%d/%d', $heartbeatFresh ? 'yes' : 'no', $organsScheduled, count(self::SCHEDULED_ORGANS)),
        ];

        $unmarked = $this->unmarkedSessionEchoCount();
        $signals[] = [
            'signal' => 'pack_anti_lixo',
            'points' => $unmarked === 0 ? 2.5 : 0.0,
            'max' => 2.5,
            'evidence' => $unmarked === -1 ? 'store ausente (0 honesto)' : sprintf('unmarked_session_echo_nodes=%d', $unmarked),
        ];

        $feedback7d = $this->tableCount('ai_rag_feedback_events', fn ($q) => $q->where('created_at', '>=', now()->subDays(7)));
        $hintsOn = (bool) config('atlas.ai.context_feedback.global_hints_enabled', true);
        $oldFeedbackPoints = round(($feedback7d > 0 ? 1.25 : 0.0) + ($hintsOn ? 1.25 : 0.0), 2);

        $lift = $this->lift->report(minCases: self::LIFT_CASES_PER_ARM_REQUIRED, minPassingUse: 1);
        $withCount = (int) data_get($lift, 'measurement.with_recalled_memory.case_count', 0);
        $withoutCount = (int) data_get($lift, 'measurement.without_recalled_memory.case_count', 0);
        $measurementReady = (bool) data_get($lift, 'measurement.measurement_ready', false);
        $armProgress = min($withCount, $withoutCount) / self::LIFT_CASES_PER_ARM_REQUIRED;
        $newFeedbackPoints = ($measurementReady && $withCount >= self::LIFT_CASES_PER_ARM_REQUIRED && $withoutCount >= self::LIFT_CASES_PER_ARM_REQUIRED)
            ? 2.5
            : round(min(2.5, max(0.0, $armProgress) * 2.5), 2);

        $signals[] = [
            'signal' => 'feedback_loop_vivo',
            'points' => $newFeedbackPoints,
            'max' => 2.5,
            'evidence' => sprintf(
                'dual_read old_feedback=%.2f new_feedback=%.2f lift_status=%s with_cases=%d without_cases=%d measurement_ready=%s',
                $oldFeedbackPoints,
                $newFeedbackPoints,
                (string) ($lift['status'] ?? 'unknown'),
                $withCount,
                $withoutCount,
                $measurementReady ? 'true' : 'false',
            ),
        ];

        $held = $this->tableCount('ai_learning_candidates', fn ($q) => $q->where('decision', 'hold'));
        $promoted = $this->tableCount('ai_learning_candidates', fn ($q) => $q->where('decision', 'promote'));
        $oldLicoesPoints = round(($held > 0 ? 1.25 : 0.0) + ($promoted > 0 ? 1.25 : 0.0), 2);
        $servedCompounding = $this->activeCompoundingMemoryServedByRecall();
        $newLicoesPoints = $servedCompounding ? 2.5 : 0.0;
        $signals[] = [
            'signal' => 'licoes_geridas',
            'points' => $newLicoesPoints,
            'max' => 2.5,
            'evidence' => sprintf(
                'dual_read old_licoes=%.2f new_licoes=%.2f quarantine_held=%d promoted=%d active_compounding_served=%s',
                $oldLicoesPoints,
                $newLicoesPoints,
                max(0, $held),
                max(0, $promoted),
                $servedCompounding ? 'yes' : 'no',
            ),
        ];

        return $this->rollUp($signals);
    }

    /**
     * @return array<string,mixed>
     */
    private function autonomia(): array
    {
        $signals = [];

        $heartbeatFresh = $this->heartbeatFresh();
        $signals[] = [
            'signal' => 'motor_vivo',
            'points' => $heartbeatFresh ? 2.5 : 0.0,
            'max' => 2.5,
            'evidence' => 'heartbeat do com.atlas.scheduler '.($heartbeatFresh ? 'fresco' : 'parado/ausente'),
        ];

        $gateFresh = $this->fileFresh(storage_path('app/atlas/evidence/acos-long-horizon-gate.json'), self::GATE_FRESH_SECONDS);
        $seriesFresh = $this->fileFresh(storage_path('app/atlas/evidence/fable-delta-series.jsonl'), self::GATE_FRESH_SECONDS);
        $signals[] = [
            'signal' => 'gates_auditados',
            'points' => round(($gateFresh ? 1.25 : 0.0) + ($seriesFresh ? 1.25 : 0.0), 2),
            'max' => 2.5,
            'evidence' => sprintf('long_horizon_receipt_fresh=%s delta_series_fresh=%s', $gateFresh ? 'yes' : 'no', $seriesFresh ? 'yes' : 'no'),
        ];

        $chain = $this->tierChainReadiness();
        $signals[] = [
            'signal' => 'cadeia_tier_implementada',
            'points' => round(($chain['implemented'] ? 1.25 : 0.0) + ($chain['audited'] ? 1.25 : 0.0), 2),
            'max' => 2.5,
            'evidence' => $chain['evidence'],
        ];

        $switchReadable = $this->loopMasterSwitchReadable();
        // Carta de Autonomia (Regra 4): a assinatura do operador foi REVOGADA. A
        // parcela que era `operator_signed` (1.0, presa em 0 para sempre → teto
        // 9.0) agora mede o substituto real que a Carta nomeia: reversibilidade
        // viva + Diário de Evolução íntegro. "Reversibilidade é a licença que
        // substitui a aprovação." Continua evidence-resolved e degrade-safe.
        $governance = $this->autonomousGovernance();
        $signals[] = [
            'signal' => 'execucao_governada',
            'points' => round(($switchReadable ? 0.5 : 0.0) + ($chain['tier_exposed'] ? 1.0 : 0.0) + $governance['points'], 2),
            'max' => 2.5,
            'evidence' => sprintf(
                'master_switch=%s tier_exposed=%s governanca_autonoma=%s',
                $switchReadable ? 'legível' : 'indisponível',
                $chain['tier_exposed'] ? 'yes' : 'no',
                $governance['evidence'],
            ),
        ];

        return $this->rollUp($signals);
    }

    // ------------------------------------------------------------------
    // Probes (todas degrade-safe)
    // ------------------------------------------------------------------

    private function heartbeatFresh(): bool
    {
        return $this->fileFresh(storage_path('atlas/scheduler/heartbeat.jsonl'), self::HEARTBEAT_FRESH_SECONDS);
    }

    private function fileFresh(string $path, int $maxAgeSeconds): bool
    {
        try {
            return is_file($path) && (time() - (int) filemtime($path)) <= $maxAgeSeconds;
        } catch (Throwable) {
            return false;
        }
    }

    private function scheduledOrganCount(): int
    {
        try {
            $haystack = '';
            foreach (app(Schedule::class)->events() as $event) {
                $haystack .= ' '.(string) ($event->command ?? '').' '.(string) ($event->description ?? '');
            }
            $found = 0;
            foreach (self::SCHEDULED_ORGANS as $organ) {
                if (str_contains($haystack, $organ)) {
                    $found++;
                }
            }

            return $found;
        } catch (Throwable) {
            return 0;
        }
    }

    /** -1 = store ausente (pontua 0, evidência explica). */
    private function unmarkedSessionEchoCount(): int
    {
        try {
            if (! Schema::hasTable('atlas_aurg_nodes')) {
                return -1;
            }
            $count = 0;
            DB::table('atlas_aurg_nodes')
                ->where('source_kind', 'mission')
                ->orderBy('id')
                ->chunkById(500, function ($nodes) use (&$count): void {
                    foreach ($nodes as $node) {
                        $meta = json_decode((string) ($node->meta ?? ''), true) ?: [];
                        if (($meta['origin'] ?? '') === AtlasOpenBrainWriteBackService::MISSION_ORIGIN_SESSION_CAPTURE) {
                            continue;
                        }
                        if (trim((string) ($meta['provider'] ?? '')) !== ''
                            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', (string) $node->source_id) === 1) {
                            $count++;
                        }
                    }
                });

            return $count;
        } catch (Throwable) {
            return -1;
        }
    }

    /** -1 = tabela ausente. */
    private function tableCount(string $table, callable $scope): int
    {
        try {
            if (! Schema::hasTable($table)) {
                return -1;
            }
            $query = DB::table($table);
            $scope($query);

            return (int) $query->count();
        } catch (Throwable) {
            return -1;
        }
    }

    private function activeCompoundingMemoryServedByRecall(): bool
    {
        try {
            if (! Schema::hasTable('ai_compounding_memories') || ! Schema::hasTable('ai_rag_feedback_events')) {
                return false;
            }

            $activeKeys = [];
            foreach (AiCompoundingMemory::query()->active()->get(['id', 'memory_hash']) as $memory) {
                foreach ([$memory->id, $memory->memory_hash] as $value) {
                    if (! is_string($value) || trim($value) === '') {
                        continue;
                    }
                    $value = trim($value);
                    $activeKeys['compounding_memory:'.$value] = true;
                    $activeKeys[$value] = true;
                }
            }

            if ($activeKeys === []) {
                return false;
            }

            return AiRagFeedbackEvent::query()
                ->where('created_at', '>=', now()->subDays(7))
                ->get(['source_utility'])
                ->contains(function (AiRagFeedbackEvent $event) use ($activeKeys): bool {
                    $utility = is_array($event->source_utility) ? $event->source_utility : [];
                    foreach ($utility as $key => $status) {
                        if (! isset($activeKeys[(string) $key])) {
                            continue;
                        }
                        $normalized = is_scalar($status) ? strtolower(trim((string) $status)) : '';
                        if (in_array($normalized, ['included', 'used', 'useful'], true)) {
                            return true;
                        }
                    }

                    return false;
                });
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array{implemented:bool, audited:bool, tier_exposed:bool, operator_signed:bool, evidence:string}
     */
    private function tierChainReadiness(): array
    {
        if (! class_exists(self::TIER_CHAIN_CLASS)) {
            return [
                'implemented' => false,
                'audited' => false,
                'tier_exposed' => false,
                'operator_signed' => false,
                'evidence' => 'cadeia S49→S55 não implementada (classe ausente)',
            ];
        }

        try {
            $readiness = (array) app(self::TIER_CHAIN_CLASS)->readiness();

            return [
                'implemented' => (bool) ($readiness['implemented'] ?? false),
                'audited' => (bool) ($readiness['audited'] ?? false),
                'tier_exposed' => array_key_exists('tier', $readiness),
                'operator_signed' => (bool) ($readiness['operator_signed'] ?? false),
                'evidence' => sprintf(
                    'chain implemented=%s audited=%s tier=%s signed=%s',
                    ($readiness['implemented'] ?? false) ? 'yes' : 'no',
                    ($readiness['audited'] ?? false) ? 'yes' : 'no',
                    (string) ($readiness['tier'] ?? '?'),
                    ($readiness['operator_signed'] ?? false) ? 'yes' : 'no',
                ),
            ];
        } catch (Throwable $e) {
            return [
                'implemented' => true,
                'audited' => false,
                'tier_exposed' => false,
                'operator_signed' => false,
                'evidence' => 'cadeia presente mas readiness() falhou: '.$e->getMessage(),
            ];
        }
    }

    private function loopMasterSwitchReadable(): bool
    {
        try {
            AtlasLoopMasterSwitch::enabled();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Carta de Autonomia (Regra 4) — o substituto autônomo da assinatura do
     * operador, que a Carta revogou. Vale 1.0 (o lugar exato do antigo
     * operator_signed) e é degrade-safe: sem trilho de reversão ou sem Diário
     * íntegro, pontua 0 com evidência explicando — nunca fabricado.
     *
     * @return array{points:float, evidence:string}
     */
    private function autonomousGovernance(): array
    {
        // Reversibilidade viva (0.5): os DOIS trilhos que a Carta nomeia existem —
        // git revert (repo presente) e atlas:brain:replay (comando registrado).
        $gitRepo = is_dir(base_path('.git'));
        $replayReady = class_exists(self::REVERSAL_COMMAND_CLASS);
        $reversible = $gitRepo && $replayReady;

        // Diário vivo + íntegro (0.5): há evoluções etiquetadas e a hash-chain
        // fecha ponta a ponta (navegável + à prova de adulteração).
        $diaryOk = false;
        $diaryCount = 0;
        try {
            $chain = (new AtlasEvolutionDiary)->verifyChain();
            $diaryCount = (int) ($chain['count'] ?? 0);
            $diaryOk = ($chain['ok'] ?? false) === true && $diaryCount > 0;
        } catch (Throwable) {
            $diaryOk = false;
        }

        return [
            'points' => round(($reversible ? 0.5 : 0.0) + ($diaryOk ? 0.5 : 0.0), 2),
            'evidence' => sprintf(
                'reversivel=%s(git=%s,replay=%s) diario_integro=%s(entradas=%d)',
                $reversible ? 'yes' : 'no',
                $gitRepo ? 'yes' : 'no',
                $replayReady ? 'yes' : 'no',
                $diaryOk ? 'yes' : 'no',
                $diaryCount,
            ),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $signals
     * @return array<string,mixed>
     */
    private function rollUp(array $signals): array
    {
        $score = 0.0;
        foreach ($signals as $signal) {
            $score += (float) $signal['points'];
        }

        return [
            'score' => round(min(10.0, $score), 2),
            'max' => 10.0,
            'signals' => $signals,
        ];
    }
}
