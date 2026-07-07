<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition;

use App\Services\Ai\AtlasOpenBrainWriteBackService;
use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
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
 *                              e testada + execução governada (assinatura S49 é a
 *                              ÚNICA parcela que depende do operador; sem ela o
 *                              teto honesto é 9.0, nunca fabricamos o 10).
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

    public function __construct(
        private readonly AtlasCognitionScoreCardService $scorecard = new AtlasCognitionScoreCardService,
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
                'autonomy_ceiling' => 'A parcela de assinatura do operador (S49) nunca é fabricada: sem assinatura, autonomia atinge no máximo 9.0.',
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
        $signals[] = [
            'signal' => 'feedback_loop_vivo',
            'points' => round(($feedback7d > 0 ? 1.25 : 0.0) + ($hintsOn ? 1.25 : 0.0), 2),
            'max' => 2.5,
            // WO-17-T0.1 — the recall candidate set is now query-aware (the
            // question enters selection, not just re-ranking). Evidence-only;
            // points/weights unchanged.
            'evidence' => sprintf('feedback_events_7d=%d global_hints=%s retrieval_mode=query_aware', max(0, $feedback7d), $hintsOn ? 'on' : 'off'),
        ];

        $held = $this->tableCount('ai_learning_candidates', fn ($q) => $q->where('decision', 'hold'));
        $promoted = $this->tableCount('ai_learning_candidates', fn ($q) => $q->where('decision', 'promote'));
        $signals[] = [
            'signal' => 'licoes_geridas',
            'points' => round(($held > 0 ? 1.25 : 0.0) + ($promoted > 0 ? 1.25 : 0.0), 2),
            'max' => 2.5,
            'evidence' => sprintf('quarantine_held=%d promoted=%d', max(0, $held), max(0, $promoted)),
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
        $signals[] = [
            'signal' => 'execucao_governada',
            'points' => round(($switchReadable ? 0.5 : 0.0) + ($chain['tier_exposed'] ? 1.0 : 0.0) + ($chain['operator_signed'] ? 1.0 : 0.0), 2),
            'max' => 2.5,
            'evidence' => sprintf(
                'master_switch=%s tier_exposed=%s operator_signature=%s',
                $switchReadable ? 'legível' : 'indisponível',
                $chain['tier_exposed'] ? 'yes' : 'no',
                $chain['operator_signed'] ? 'signed' : 'awaiting_operator_signature',
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
