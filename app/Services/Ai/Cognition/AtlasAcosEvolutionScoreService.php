<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition;

use App\Models\AiCompoundingMemory;
use App\Models\AiRagFeedbackEvent;
use App\Services\Ai\AtlasOpenBrainWriteBackService;
use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\Brain\AtlasEvolutionDiary;
use App\Services\Ai\Compounding\AtlasLearningRecallUseLiftService;
use App\Services\Ai\Support\AiValueNormalizer;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;
use App\Support\YesNo;
use App\Support\UtcIsoTimestamp;

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
    public const FIELD_AUTONOMY_GOVERNANCE = 'autonomy_governance';
    public const FIELD_COUNT = 'count';
    public const STATUS_UNKNOWN = 'unknown';


    public const FIELD_EVIDENCE = 'evidence';

    public const FIELD_POINTS = 'points';

    public const FIELD_SIGNAL = 'signal';

    public const FIELD_SCORE = 'score';

    public const FIELD_IMPLEMENTED = 'implemented';

    public const FIELD_AUDITED = 'audited';

    public const FIELD_TIER_EXPOSED = 'tier_exposed';

    public const FIELD_OPERATOR_SIGNED = 'operator_signed';

    public const FIELD_SCHEMA_VERSION = 'schema_version';

    public const FIELD_STATUS = 'status';
    public const FIELD_MAX = 'max';
    public const FIELD_SIGNALS = 'signals';

    public const SCHEMA_VERSION = 'atlas.cognition.evolution_score.v1';

    /** Janela de frescor do heartbeat do scheduler (motor vivo). */
    public const HEARTBEAT_FRESH_SECONDS = 7200;

    /** Janela de frescor de receipts/série do gate de longo horizonte. */
    public const GATE_FRESH_SECONDS = 172800;

    /** Comandos-órgão cuja presença agendada é exigida pela cadência H2.1. */
    public const SCHEDULED_ORGANS = [
        self::FIELD_ATLAS_COGNITION_MINT_PIPELINE_RECEIPTS,
        self::FIELD_ATLAS_ACOS_DELTA_SERIES,
        self::FIELD_ATLAS_ENGINEERING_REFACTOR_CENSUS,
        self::FIELD_ACOS_HARVEST_OBRA_LESSONS,
    ];

    /** FQN da cadeia de promoção de tier (H3.2); probe por class_exists. */
    public const TIER_CHAIN_CLASS = 'App\Services\Ai\AutonomousEvolution\AtlasLoopTierPromotionChainService';

    /** FQN do comando de reversão da memória (Carta Regra 4); probe por class_exists. */
    public const REVERSAL_COMMAND_CLASS = 'App\Console\Commands\AtlasBrainReplayCommand';

    /** Minimum live A/B cases per arm before feedback_loop_vivo can score fully. */
    public const LIFT_CASES_PER_ARM_REQUIRED = 10;

    public const GLOBAL_HINTS_ENABLED_CONFIG_KEY = 'atlas.ai.context_feedback.global_hints_enabled';

    public const DEFAULT_GLOBAL_HINTS_ENABLED = true;

    public const LONG_HORIZON_GATE_EVIDENCE_RELATIVE = 'app/atlas/evidence/acos-long-horizon-gate.json';

    public const DELTA_SERIES_EVIDENCE_RELATIVE = 'app/atlas/evidence/acos-delta-series.jsonl';

    public const SCHEDULER_HEARTBEAT_RELATIVE = 'atlas/scheduler/heartbeat.jsonl';
    public const FIELD_ACOS_SCORECARD_OVERALL = 'acos_scorecard_overall';
    public const FIELD_AUTONOMIA = 'autonomia';
    public const FIELD_DIMENSIONS = 'dimensions';
    public const FIELD_EXECUCAO_PROVADA = 'execucao_provada';
    public const FIELD_GENERATED_AT = 'generated_at';
    public const FIELD_INTELIGENCIA_ENTREGUE = 'inteligencia_entregue';
    public const FIELD_METHOD = 'method';
    public const FIELD_NOTES = 'notes';
    public const FIELD_OK = 'ok';
    public const FIELD_ORIGIN = 'origin';
    public const FIELD_OVERALL_OUT_OF_10 = 'overall_out_of_10';
    public const FIELD_PROVIDER = 'provider';
    public const FIELD_SCORE_HASH = 'score_hash';
    public const FIELD_SOURCE_UTILITY = 'source_utility';
    public const FIELD_TIER = 'tier';
    public const FIELD_YES = 'yes';
    public const FIELD_CREATED_AT = 'created_at';
    public const FIELD_DECISION = 'decision';
    public const FIELD_CADEIA_TIER_IMPLEMENTADA = 'cadeia_tier_implementada';
    public const FIELD_CADENCIA_VIVA = 'cadencia_viva';
    public const FIELD_EXECUCAO_GOVERNADA = 'execucao_governada';
    public const FIELD_FEEDBACK_LOOP_VIVO = 'feedback_loop_vivo';
    public const FIELD_FRESCO = 'fresco';
    public const FIELD_GATES_AUDITADOS = 'gates_auditados';
    public const FIELD_LICOES_GERIDAS = 'licoes_geridas';
    public const FIELD_MOTOR_VIVO = 'motor_vivo';
    public const FIELD_PACK_ANTI_LIXO = 'pack_anti_lixo';
    public const FIELD_PIPELINE_GREEN_RUN_RECEIPTS = 'pipeline_green_run_receipts';
    public const FIELD_SOURCE_KIND = 'source_kind';
    public const FIELD_AI_LEARNING_CANDIDATES = 'ai_learning_candidates';
    public const FIELD_AI_RAG_FEEDBACK_EVENTS = 'ai_rag_feedback_events';
    public const FIELD_ATLAS_AURG_NODES = 'atlas_aurg_nodes';
    public const FIELD_AI_COMPOUNDING_MEMORIES = 'ai_compounding_memories';
    public const FIELD_HOLD = 'hold';
    public const FIELD_MISSION = 'mission';
    public const FIELD_PROMOTE = 'promote';
    public const FIELD_SHA256 = 'sha256';
    public const FIELD_USED = 'used';
    public const FIELD_NO = 'no';
    public const FIELD_ID = 'id';
    public const FIELD_INCLUDED = 'included';
    public const FIELD_MEMORY_HASH = 'memory_hash';
    public const FIELD_USEFUL = 'useful';
    public const FIELD_ACOS_HARVEST_OBRA_LESSONS = 'acos-harvest-obra-lessons';
    public const FIELD_MEASUREMENT_MEASUREMENT_READY = 'measurement.measurement_ready';
    public const FIELD_MEASUREMENT_WITH_RECALLED_MEMORY_CASE_COUNT = 'measurement.with_recalled_memory.case_count';
    public const FIELD_MEASUREMENT_WITHOUT_RECALLED_MEMORY_CASE_COUNT = 'measurement.without_recalled_memory.case_count';
    public const FIELD_SCORE_DIMENSIONS_PIPELINE_SCORE_OUT_OF_10 = 'score.dimensions.pipeline.score_out_of_10';
    public const FIELD_SCORE_OVERALL_OUT_OF_10 = 'score.overall_out_of_10';
    public const FIELD_ATLAS_ACOS_DELTA_SERIES = 'atlas:acos:delta-series';
    public const FIELD_ATLAS_COGNITION_MINT_PIPELINE_RECEIPTS = 'atlas:cognition:mint-pipeline-receipts';
    public const FIELD_ATLAS_ENGINEERING_REFACTOR_CENSUS = 'atlas:engineering:refactor-census';
    public const FIELD__GIT = '.git';
    public const FIELD_CADEIA_S49_S55_N_O_IMPLEMENTADA__CLASSE_AUSENTE_ = 'cadeia S49→S55 não implementada (classe ausente)';
    public const FIELD_CHAIN_IMPLEMENTED__S_AUDITED__S_TIER__S_SIGNED__S = 'chain implemented=%s audited=%s tier=%s signed=%s';
    public const FIELD_HEARTBEAT_DO_COM_ATLAS_SCHEDULER_ = 'heartbeat do com.atlas.scheduler ';
    public const FIELD_HEARTBEAT_FRESH__S_ORGANS_SCHEDULED__D__D = 'heartbeat_fresh=%s organs_scheduled=%d/%d';
    public const FIELD_INDISPON_VEL = 'indisponível';
    public const FIELD_LEG_VEL = 'legível';
    public const FIELD_LONG_HORIZON_RECEIPT_FRESH__S_DELTA_SERIES_FRESH__S = 'long_horizon_receipt_fresh=%s delta_series_fresh=%s';
    public const FIELD_MASTER_SWITCH__S_TIER_EXPOSED__S_GOVERNANCA_AUTONOMA__S = 'master_switch=%s tier_exposed=%s governanca_autonoma=%s';
    public const FIELD_REVERSIVEL__S_GIT__S_REPLAY__S__DIARIO_INTEGRO__S_ENTRADAS__D_ = 'reversivel=%s(git=%s,replay=%s) diario_integro=%s(entradas=%d)';
    public const FIELD_SCORECARD_V3_DIMENS_O_PIPELINE__GREEN_RUN_RECEIPTS_REAIS__FRESHNESS_BOUND_ = 'scorecard v3 dimensão pipeline (green-run receipts reais, freshness-bound)';
    public const FIELD_STORE_AUSENTE__0_HONESTO_ = 'store ausente (0 honesto)';
    public const FIELD_TODA_PARCELA___FUN__O_DE_EVID_NCIA_RESOLVIDA_EM_RUNTIME__PROBE_DE_DB_ARQUIVO_AGENDA_CLASSE___NENHUM_LITERAL_AUTO_DECLARADO_ = 'Toda parcela é função de evidência resolvida em runtime (probe de DB/arquivo/agenda/classe); nenhum literal auto-declarado.';
    public const FIELD_DUAL_READ_OLD_FEEDBACK___2F_NEW_FEEDBACK___2F_LIFT_STATUS__S_WITH_CASES__D_WITHOUT_CASES__D_MEASUREMENT_READY__S = 'dual_read old_feedback=%.2f new_feedback=%.2f lift_status=%s with_cases=%d without_cases=%d measurement_ready=%s';
    public const FIELD_DUAL_READ_OLD_LICOES___2F_NEW_LICOES___2F_QUARANTINE_HELD__D_PROMOTED__D_ACTIVE_COMPOUNDING_SERVED__S = 'dual_read old_licoes=%.2f new_licoes=%.2f quarantine_held=%d promoted=%d active_compounding_served=%s';
    public const FIELD_PARADO_AUSENTE = 'parado/ausente';
    public const FLOAT_2_5 = 2.5;
    public const FLOAT_10_0 = 10.0;

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

        $overall = round(($execucao[self::FIELD_SCORE] + $inteligencia[self::FIELD_SCORE] + $autonomia[self::FIELD_SCORE]) / 3, 2);

        $envelope = [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_GENERATED_AT => UtcIsoTimestamp::now(),
            self::FIELD_OVERALL_OUT_OF_10 => $overall,
            self::FIELD_DIMENSIONS => [
                self::FIELD_EXECUCAO_PROVADA => $execucao,
                self::FIELD_INTELIGENCIA_ENTREGUE => $inteligencia,
                self::FIELD_AUTONOMIA => $autonomia,
            ],
            self::FIELD_ACOS_SCORECARD_OVERALL => AiValueNormalizer::finiteFloatOrNull(data_get($card, self::FIELD_SCORE_OVERALL_OUT_OF_10, 0.0)) ?? 0.0,
            self::FIELD_NOTES => [
                self::FIELD_METHOD => self::FIELD_TODA_PARCELA___FUN__O_DE_EVID_NCIA_RESOLVIDA_EM_RUNTIME__PROBE_DE_DB_ARQUIVO_AGENDA_CLASSE___NENHUM_LITERAL_AUTO_DECLARADO_,
                self::FIELD_AUTONOMY_GOVERNANCE => 'Carta de Autonomia (Regra 4): a assinatura do operador foi revogada; a parcela antes presa a ela agora mede o substituto REAL — reversibilidade viva (git revert + atlas:brain:replay) + Diário de Evolução íntegro. Degrade-safe: sem esse substrato, pontua 0, nunca fabricado.',
            ],
        ];
        $envelope[self::FIELD_SCORE_HASH] = 'sha256:'.hash(self::FIELD_SHA256, (string) json_encode([
            $overall, $execucao[self::FIELD_SCORE], $inteligencia[self::FIELD_SCORE], $autonomia[self::FIELD_SCORE],
        ], JSON_THROW_ON_ERROR));

        return $envelope;
    }

    /**
     * @param  array<string,mixed>  $card
     * @return array<string,mixed>
     */
    private function execucaoProvada(array $card): array
    {
        $pipeline = AiValueNormalizer::finiteFloatOrNull(data_get($card, self::FIELD_SCORE_DIMENSIONS_PIPELINE_SCORE_OUT_OF_10, 0.0)) ?? 0.0;

        return [
            self::FIELD_SCORE => round($pipeline, 2),
            self::FIELD_MAX => self::FLOAT_10_0,
            self::FIELD_SIGNALS => [[
                self::FIELD_SIGNAL => self::FIELD_PIPELINE_GREEN_RUN_RECEIPTS,
                self::FIELD_POINTS => round($pipeline, 2),
                self::FIELD_MAX => self::FLOAT_10_0,
                self::FIELD_EVIDENCE => self::FIELD_SCORECARD_V3_DIMENS_O_PIPELINE__GREEN_RUN_RECEIPTS_REAIS__FRESHNESS_BOUND_,
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
            self::FIELD_SIGNAL => self::FIELD_CADENCIA_VIVA,
            self::FIELD_POINTS => round(($heartbeatFresh ? 1.25 : 0.0) + 1.25 * ($organsScheduled / count(self::SCHEDULED_ORGANS)), 2),
            self::FIELD_MAX => self::FLOAT_2_5,
            self::FIELD_EVIDENCE => sprintf(self::FIELD_HEARTBEAT_FRESH__S_ORGANS_SCHEDULED__D__D, $heartbeatFresh ? self::FIELD_YES : self::FIELD_NO, $organsScheduled, count(self::SCHEDULED_ORGANS)),
        ];

        $unmarked = $this->unmarkedSessionEchoCount();
        $signals[] = [
            self::FIELD_SIGNAL => self::FIELD_PACK_ANTI_LIXO,
            self::FIELD_POINTS => $unmarked === 0 ? 2.5 : 0.0,
            self::FIELD_MAX => self::FLOAT_2_5,
            self::FIELD_EVIDENCE => $unmarked === -1 ? self::FIELD_STORE_AUSENTE__0_HONESTO_ : sprintf('unmarked_session_echo_nodes=%d', $unmarked),
        ];

        $feedback7d = $this->tableCount(self::FIELD_AI_RAG_FEEDBACK_EVENTS, fn ($q) => $q->where(self::FIELD_CREATED_AT, '>=', now()->subDays(7)));
        $hintsOn = (AiValueNormalizer::boolOrNull(config(self::GLOBAL_HINTS_ENABLED_CONFIG_KEY, self::DEFAULT_GLOBAL_HINTS_ENABLED)) ?? self::DEFAULT_GLOBAL_HINTS_ENABLED);
        $oldFeedbackPoints = round(($feedback7d > 0 ? 1.25 : 0.0) + ($hintsOn ? 1.25 : 0.0), 2);

        $lift = $this->lift->report(minCases: self::LIFT_CASES_PER_ARM_REQUIRED, minPassingUse: 1);
        $withCount = (int) (AiValueNormalizer::finiteFloatOrNull(data_get($lift, self::FIELD_MEASUREMENT_WITH_RECALLED_MEMORY_CASE_COUNT, 0)) ?? 0);
        $withoutCount = (int) (AiValueNormalizer::finiteFloatOrNull(data_get($lift, self::FIELD_MEASUREMENT_WITHOUT_RECALLED_MEMORY_CASE_COUNT, 0)) ?? 0);
        $measurementReady = (AiValueNormalizer::boolOrNull(data_get($lift, self::FIELD_MEASUREMENT_MEASUREMENT_READY, false)) ?? false);
        $armProgress = min($withCount, $withoutCount) / self::LIFT_CASES_PER_ARM_REQUIRED;
        $newFeedbackPoints = ($measurementReady && $withCount >= self::LIFT_CASES_PER_ARM_REQUIRED && $withoutCount >= self::LIFT_CASES_PER_ARM_REQUIRED)
            ? 2.5
            : round(min(2.5, max(0.0, $armProgress) * 2.5), 2);

        $signals[] = [
            self::FIELD_SIGNAL => self::FIELD_FEEDBACK_LOOP_VIVO,
            self::FIELD_POINTS => $newFeedbackPoints,
            self::FIELD_MAX => self::FLOAT_2_5,
            self::FIELD_EVIDENCE => sprintf(
                self::FIELD_DUAL_READ_OLD_FEEDBACK___2F_NEW_FEEDBACK___2F_LIFT_STATUS__S_WITH_CASES__D_WITHOUT_CASES__D_MEASUREMENT_READY__S,
                $oldFeedbackPoints,
                $newFeedbackPoints,
                (AiValueNormalizer::trimmedStringOrNull($lift[self::FIELD_STATUS] ?? null) ?? self::STATUS_UNKNOWN),
                $withCount,
                $withoutCount,
                YesNo::trueFalse($measurementReady),
            ),
        ];

        $held = $this->tableCount(self::FIELD_AI_LEARNING_CANDIDATES, fn ($q) => $q->where(self::FIELD_DECISION, self::FIELD_HOLD));
        $promoted = $this->tableCount(self::FIELD_AI_LEARNING_CANDIDATES, fn ($q) => $q->where(self::FIELD_DECISION, self::FIELD_PROMOTE));
        $oldLicoesPoints = round(($held > 0 ? 1.25 : 0.0) + ($promoted > 0 ? 1.25 : 0.0), 2);
        $servedCompounding = $this->activeCompoundingMemoryServedByRecall();
        $newLicoesPoints = $servedCompounding ? 2.5 : 0.0;
        $signals[] = [
            self::FIELD_SIGNAL => self::FIELD_LICOES_GERIDAS,
            self::FIELD_POINTS => $newLicoesPoints,
            self::FIELD_MAX => self::FLOAT_2_5,
            self::FIELD_EVIDENCE => sprintf(
                self::FIELD_DUAL_READ_OLD_LICOES___2F_NEW_LICOES___2F_QUARANTINE_HELD__D_PROMOTED__D_ACTIVE_COMPOUNDING_SERVED__S,
                $oldLicoesPoints,
                $newLicoesPoints,
                max(0, $held),
                max(0, $promoted),
                $servedCompounding ? self::FIELD_YES : self::FIELD_NO,
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
            self::FIELD_SIGNAL => self::FIELD_MOTOR_VIVO,
            self::FIELD_POINTS => $heartbeatFresh ? 2.5 : 0.0,
            self::FIELD_MAX => self::FLOAT_2_5,
            self::FIELD_EVIDENCE => self::FIELD_HEARTBEAT_DO_COM_ATLAS_SCHEDULER_.($heartbeatFresh ? self::FIELD_FRESCO : self::FIELD_PARADO_AUSENTE),
        ];

        $gateFresh = $this->fileFresh(storage_path(self::LONG_HORIZON_GATE_EVIDENCE_RELATIVE), self::GATE_FRESH_SECONDS);
        $seriesFresh = $this->fileFresh(storage_path(self::DELTA_SERIES_EVIDENCE_RELATIVE), self::GATE_FRESH_SECONDS);
        $signals[] = [
            self::FIELD_SIGNAL => self::FIELD_GATES_AUDITADOS,
            self::FIELD_POINTS => round(($gateFresh ? 1.25 : 0.0) + ($seriesFresh ? 1.25 : 0.0), 2),
            self::FIELD_MAX => self::FLOAT_2_5,
            self::FIELD_EVIDENCE => sprintf(self::FIELD_LONG_HORIZON_RECEIPT_FRESH__S_DELTA_SERIES_FRESH__S, $gateFresh ? self::FIELD_YES : self::FIELD_NO, $seriesFresh ? self::FIELD_YES : self::FIELD_NO),
        ];

        $chain = $this->tierChainReadiness();
        $signals[] = [
            self::FIELD_SIGNAL => self::FIELD_CADEIA_TIER_IMPLEMENTADA,
            self::FIELD_POINTS => round(($chain[self::FIELD_IMPLEMENTED] ? 1.25 : 0.0) + ($chain[self::FIELD_AUDITED] ? 1.25 : 0.0), 2),
            self::FIELD_MAX => self::FLOAT_2_5,
            self::FIELD_EVIDENCE => $chain[self::FIELD_EVIDENCE],
        ];

        $switchReadable = $this->loopMasterSwitchReadable();
        // Carta de Autonomia (Regra 4): a assinatura do operador foi REVOGADA. A
        // parcela que era `operator_signed` (1.0, presa em 0 para sempre → teto
        // 9.0) agora mede o substituto real que a Carta nomeia: reversibilidade
        // viva + Diário de Evolução íntegro. "Reversibilidade é a licença que
        // substitui a aprovação." Continua evidence-resolved e degrade-safe.
        $governance = $this->autonomousGovernance();
        $signals[] = [
            self::FIELD_SIGNAL => self::FIELD_EXECUCAO_GOVERNADA,
            self::FIELD_POINTS => round(($switchReadable ? 0.5 : 0.0) + ($chain[self::FIELD_TIER_EXPOSED] ? 1.0 : 0.0) + $governance[self::FIELD_POINTS], 2),
            self::FIELD_MAX => self::FLOAT_2_5,
            self::FIELD_EVIDENCE => sprintf(
                self::FIELD_MASTER_SWITCH__S_TIER_EXPOSED__S_GOVERNANCA_AUTONOMA__S,
                $switchReadable ? self::FIELD_LEG_VEL : self::FIELD_INDISPON_VEL,
                $chain[self::FIELD_TIER_EXPOSED] ? self::FIELD_YES : self::FIELD_NO,
                $governance[self::FIELD_EVIDENCE],
            ),
        ];

        return $this->rollUp($signals);
    }

    // ------------------------------------------------------------------
    // Probes (todas degrade-safe)
    // ------------------------------------------------------------------

    private function heartbeatFresh(): bool
    {
        return $this->fileFresh(storage_path(self::SCHEDULER_HEARTBEAT_RELATIVE), self::HEARTBEAT_FRESH_SECONDS);
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
                $haystack .= ' '.AiValueNormalizer::trimmedString($event->command ?? '').' '.AiValueNormalizer::trimmedString($event->description ?? '');
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
            if (! Schema::hasTable(self::FIELD_ATLAS_AURG_NODES)) {
                return -1;
            }
            $count = 0;
            DB::table(self::FIELD_ATLAS_AURG_NODES)
                ->where(self::FIELD_SOURCE_KIND, self::FIELD_MISSION)
                ->orderBy(self::FIELD_ID)
                ->chunkById(500, function ($nodes) use (&$count): void {
                    foreach ($nodes as $node) {
                        $meta = AiValueNormalizer::arrayOrEmpty(json_decode((string) ($node->meta ?? ''), true));
                        if (($meta[self::FIELD_ORIGIN] ?? '') === AtlasOpenBrainWriteBackService::MISSION_ORIGIN_SESSION_CAPTURE) {
                            continue;
                        }
                        if (AiValueNormalizer::trimmedStringOrNull($meta[self::FIELD_PROVIDER] ?? null) !== null
                            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', AiValueNormalizer::trimmedScalarStringOrNull($node->source_id ?? null) ?? '') === 1) {
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
            if (! Schema::hasTable(self::FIELD_AI_COMPOUNDING_MEMORIES) || ! Schema::hasTable(self::FIELD_AI_RAG_FEEDBACK_EVENTS)) {
                return false;
            }

            $activeKeys = [];
            foreach (AiCompoundingMemory::query()->active()->get([self::FIELD_ID, self::FIELD_MEMORY_HASH]) as $memory) {
                foreach ([$memory->id, $memory->memory_hash] as $value) {
                    $value = AiValueNormalizer::trimmedStringOrNull($value);
                    if ($value === null) {
                        continue;
                    }
                    $activeKeys['compounding_memory:'.$value] = true;
                    $activeKeys[$value] = true;
                }
            }

            if ($activeKeys === []) {
                return false;
            }

            return AiRagFeedbackEvent::query()
                ->where(self::FIELD_CREATED_AT, '>=', now()->subDays(7))
                ->get([self::FIELD_SOURCE_UTILITY])
                ->contains(function (AiRagFeedbackEvent $event) use ($activeKeys): bool {
                    $utility = AiValueNormalizer::arrayOrEmpty($event->source_utility);
                    foreach ($utility as $key => $status) {
                        if (! isset($activeKeys[AiValueNormalizer::trimmedScalarStringOrNull($key) ?? ''])) {
                            continue;
                        }
                        $normalized = AiValueNormalizer::trimmedScalarStringOrNull($status) !== null
                            ? AiValueNormalizer::lowerTrimmedString($status)
                            : '';
                        if (in_array($normalized, [self::FIELD_INCLUDED, self::FIELD_USED, self::FIELD_USEFUL], true)) {
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
                self::FIELD_IMPLEMENTED => false,
                self::FIELD_AUDITED => false,
                self::FIELD_TIER_EXPOSED => false,
                self::FIELD_OPERATOR_SIGNED => false,
                self::FIELD_EVIDENCE => self::FIELD_CADEIA_S49_S55_N_O_IMPLEMENTADA__CLASSE_AUSENTE_,
            ];
        }

        try {
            $readiness = AiValueNormalizer::arrayOrEmpty(app(self::TIER_CHAIN_CLASS)->readiness());

            return [
                self::FIELD_IMPLEMENTED => (AiValueNormalizer::boolOrNull($readiness[self::FIELD_IMPLEMENTED] ?? null) ?? false),
                self::FIELD_AUDITED => (AiValueNormalizer::boolOrNull($readiness[self::FIELD_AUDITED] ?? null) ?? false),
                self::FIELD_TIER_EXPOSED => array_key_exists(self::FIELD_TIER, $readiness),
                self::FIELD_OPERATOR_SIGNED => (AiValueNormalizer::boolOrNull($readiness[self::FIELD_OPERATOR_SIGNED] ?? null) ?? false),
                self::FIELD_EVIDENCE => sprintf(
                    self::FIELD_CHAIN_IMPLEMENTED__S_AUDITED__S_TIER__S_SIGNED__S,
                    ($readiness[self::FIELD_IMPLEMENTED] ?? false) ? self::FIELD_YES : self::FIELD_NO,
                    ($readiness[self::FIELD_AUDITED] ?? false) ? self::FIELD_YES : self::FIELD_NO,
                    (AiValueNormalizer::trimmedStringOrNull($readiness[self::FIELD_TIER] ?? null) ?? '?'),
                    ($readiness[self::FIELD_OPERATOR_SIGNED] ?? false) ? self::FIELD_YES : self::FIELD_NO,
                ),
            ];
        } catch (Throwable $e) {
            return [
                self::FIELD_IMPLEMENTED => true,
                self::FIELD_AUDITED => false,
                self::FIELD_TIER_EXPOSED => false,
                self::FIELD_OPERATOR_SIGNED => false,
                self::FIELD_EVIDENCE => 'cadeia presente mas readiness() falhou: '.$e->getMessage(),
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
        $gitRepo = is_dir(base_path(self::FIELD__GIT));
        $replayReady = class_exists(self::REVERSAL_COMMAND_CLASS);
        $reversible = $gitRepo && $replayReady;

        // Diário vivo + íntegro (0.5): há evoluções etiquetadas e a hash-chain
        // fecha ponta a ponta (navegável + à prova de adulteração).
        $diaryOk = false;
        $diaryCount = 0;
        try {
            $chain = (new AtlasEvolutionDiary)->verifyChain();
            $diaryCount = (int) (AiValueNormalizer::finiteFloatOrNull($chain[self::FIELD_COUNT] ?? null) ?? 0);
            $diaryOk = ($chain[self::FIELD_OK] ?? false) === true && $diaryCount > 0;
        } catch (Throwable) {
            $diaryOk = false;
        }

        return [
            self::FIELD_POINTS => round(($reversible ? 0.5 : 0.0) + ($diaryOk ? 0.5 : 0.0), 2),
            self::FIELD_EVIDENCE => sprintf(
                self::FIELD_REVERSIVEL__S_GIT__S_REPLAY__S__DIARIO_INTEGRO__S_ENTRADAS__D_,
                $reversible ? self::FIELD_YES : self::FIELD_NO,
                $gitRepo ? self::FIELD_YES : self::FIELD_NO,
                $replayReady ? self::FIELD_YES : self::FIELD_NO,
                $diaryOk ? self::FIELD_YES : self::FIELD_NO,
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
            $score += AiValueNormalizer::finiteFloatOrNull($signal[self::FIELD_POINTS] ?? null) ?? 0.0;
        }

        return [
            self::FIELD_SCORE => round(min(10.0, $score), 2),
            self::FIELD_MAX => self::FLOAT_10_0,
            self::FIELD_SIGNALS => $signals,
        ];
    }
}
