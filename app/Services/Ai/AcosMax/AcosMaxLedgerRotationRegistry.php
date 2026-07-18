<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\Support\AiValueNormalizer;

/**
 * ELEV-24 — Registro de política de rotação/retenção POR ledger.
 *
 * O plano criou dezenas de JSONLs (immune_verdict, pattern-ledger, shadows,
 * arena-runs, latency, séries v2) e só o latency ledger declarava rotação.
 * Este registro paga a dívida: cada série declarada em ELEV-20s ganha um
 * `rotation_policy = {max_size_mb, max_age_days, mode}`.
 *
 * `mode`:
 *   - `append_forever` — nunca rotaciona (append-only auditáveis, cadeia hash).
 *   - `rotate_size`   — quando o arquivo passa `max_size_mb`, rotaciona.
 *   - `rotate_age`    — arquivos com `> max_age_days` são rotacionados.
 *   - `rotate_hybrid` — o que estourar primeiro (tamanho OU idade).
 *
 * O guard arquitetural em `Elev24RotationRegistryTest` recusa qualquer série
 * de ELEV-20s sem política declarada aqui.
 */
final class AcosMaxLedgerRotationRegistry
{
    public const DEFAULT_MAX_SIZE_MB = 32;

    public const DEFAULT_MAX_AGE_DAYS = 30;

    public const MODE_APPEND_FOREVER = 'append_forever';

    public const MODE_ROTATE_HYBRID = 'rotate_hybrid';

    public const MODE_ROTATE_SIZE = 'rotate_size';

    public const MODE_ROTATE_AGE = 'rotate_age';

    public const FIELD_MAX_SIZE_MB = 'max_size_mb';

    public const FIELD_MAX_AGE_DAYS = 'max_age_days';

    public const FIELD_MODE = 'mode';

    public const FIELD_RATIONALE = 'rationale';
    public const FIELD_ACOS_ASI05_LEDGER_CLEANUP_V1 = 'acos.asi05.ledger_cleanup.v1';
    public const FIELD_ACOS_DEAD_SERIES_WATCHDOG_V1 = 'acos.dead_series_watchdog.v1';
    public const FIELD_ACOS_ESP00_GROUND_TRUTH_V1 = 'acos.esp00.ground_truth.v1';
    public const FIELD_ACOS_FLYWHEEL_LOOPS_V1 = 'acos.flywheel.loops.v1';
    public const FIELD_ACOS_LEARNING_LATENCY_V1 = 'acos.learning_latency.v1';
    public const FIELD_ACOS_OPERATOR_REVIEW_DEBT_V1 = 'acos.operator_review_debt.v1';
    public const FIELD_ACOS_VERIFIED_SHARE_V1 = 'acos.verified_share.v1';
    public const FIELD_ACOS_WINDOWS_ORCHESTRATOR_V1 = 'acos.windows_orchestrator.v1';
    public const FIELD_AOBG_LATENCY_LEDGER_V1 = 'aobg.latency_ledger.v1';
    public const FIELD_ASI_METRIC_M_V1 = 'asi.metric.m.v1';
    public const FIELD_ATLAS_ACOS_REC06_META_LOOP_BREAKERS_V1 = 'atlas.acos.rec06.meta_loop_breakers.v1';
    public const FIELD_ATLAS_AI_ABSTRACTION_LADDER_V1 = 'atlas.ai.abstraction_ladder.v1';
    public const FIELD_ATLAS_AI_COUNTERFACTUAL_LIFT_V2 = 'atlas.ai.counterfactual_lift.v2';
    public const FIELD_ATLAS_AI_LESSON_HALF_LIFE_V2 = 'atlas.ai.lesson_half_life.v2';
    public const FIELD_ATLAS_AI_LESSON_QUALITY_V2 = 'atlas.ai.lesson_quality.v2';
    public const FIELD_ATLAS_AI_LESSON_SEMANTIC_DEDUP_V1 = 'atlas.ai.lesson_semantic_dedup.v1';
    public const FIELD_ATLAS_AI_LESSON_TYPE_YIELD_V2 = 'atlas.ai.lesson_type_yield.v2';
    public const FIELD_ATLAS_AI_PROCEDURAL_SKILL_PROMOTER_V1 = 'atlas.ai.procedural_skill_promoter.v1';
    public const FIELD_ATLAS_AURG_PPR_SHADOW_DUAL_READ_V1 = 'atlas.aurg.ppr_shadow_dual_read.v1';
    public const FIELD_ATLAS_CAPTURE_COGNITIVE_IMMUNE_AUDIT_V2 = 'atlas.capture.cognitive_immune_audit.v2';
    public const FIELD_ATLAS_CODE_SYMBOL_EMBEDDING_COVERAGE_V1 = 'atlas.code_symbol_embedding_coverage.v1';
    public const FIELD_ATLAS_CONTEXT_EXECUTION_COOCCURRENCE_V1 = 'atlas.context.execution_cooccurrence.v1';
    public const FIELD_ATLAS_CONTEXT_GOLDEN_COUNTERFACTUAL_V1 = 'atlas.context.golden_counterfactual.v1';
    public const FIELD_ATLAS_DECIDE_CASCADE_COST_ROUTER_V1 = 'atlas.decide.cascade_cost_router.v1';
    public const FIELD_ATLAS_DECIDE_COST_OUTCOME_UNCERTAINTY_V1 = 'atlas.decide.cost_outcome_uncertainty.v1';
    public const FIELD_ATLAS_DECIDE_REPLAY_DIVERGENCE_V1 = 'atlas.decide.replay_divergence.v1';
    public const FIELD_ATLAS_DECIDE_ROUTE_REGRET_V2 = 'atlas.decide.route_regret.v2';
    public const FIELD_ATLAS_DECIDE_ZERO_WEIGHT_OUTCOMES_V1 = 'atlas.decide.zero_weight_outcomes.v1';
    public const FIELD_ATLAS_ESP_06_OUTCOME_ENVELOPE_V1 = 'atlas.esp_06.outcome_envelope.v1';
    public const FIELD_ATLAS_ESP_09_CHALLENGER_ADVISORY_V1 = 'atlas.esp_09.challenger_advisory.v1';
    public const FIELD_ATLAS_EVIDENCE_DELTA_ATTRIBUTION_V1 = 'atlas.evidence.delta_attribution.v1';
    public const FIELD_ATLAS_EVIDENCE_LEDGER_HASH_CHAIN_V1 = 'atlas.evidence_ledger.hash_chain.v1';
    public const FIELD_ATLAS_IMMUNE_CALIBRATION_V1 = 'atlas.immune.calibration.v1';
    public const FIELD_ATLAS_IMMUNE_CLASSIFIER_HYBRID_V1 = 'atlas.immune.classifier_hybrid.v1';
    public const FIELD_ATLAS_IMMUNE_SIGNATURE_STORE_V1 = 'atlas.immune.signature_store.v1';
    public const FIELD_ATLAS_KB_EMBEDDING_COVERAGE_V1 = 'atlas.kb_embedding_coverage.v1';
    public const INT_64 = 64;
    public const INT_45 = 45;
    public const INT_512 = 512;
    public const INT_128 = 128;
    public const INT_180 = 180;
    public const INT_16 = 16;
    public const INT_365 = 365;
    public const INT_60 = 60;
    public const INT_30 = 30;
    public const INT_32 = 32;
    public const INT_90 = 90;

    /** @var array<string,array{max_size_mb:int,max_age_days:int,mode:string,rationale:string}> */
    private array $policies;

    /**
     * @param  array<string,array{max_size_mb:int,max_age_days:int,mode:string,rationale?:string}>|null  $policies
     */
    public function __construct(?array $policies = null)
    {
        $this->policies = self::normalize($policies ?? self::defaultPolicies());
    }

    /**
     * @return array<string,array{max_size_mb:int,max_age_days:int,mode:string,rationale:string}>
     */
    public function all(): array
    {
        return $this->policies;
    }

    /**
     * @return array{max_size_mb:int,max_age_days:int,mode:string,rationale:string}|null
     */
    public function policyFor(string $series): ?array
    {
        $series = AiValueNormalizer::trimmedStringOrNull($series) ?? '';

        return $series === '' ? null : ($this->policies[$series] ?? null);
    }

    /**
     * @return array<string,array{max_size_mb:int,max_age_days:int,mode:string,rationale:string}>
     */
    public static function defaultPolicies(): array
    {
        return [
            self::FIELD_AOBG_LATENCY_LEDGER_V1 => [
                self::FIELD_MAX_SIZE_MB => self::INT_128,
                self::FIELD_MAX_AGE_DAYS => self::INT_45,
                self::FIELD_MODE => self::MODE_ROTATE_HYBRID,
                self::FIELD_RATIONALE => 'high-frequency append; MAXG-01 declares rotation contract',
            ],
            self::FIELD_ASI_METRIC_M_V1 => [
                self::FIELD_MAX_SIZE_MB => self::INT_32,
                self::FIELD_MAX_AGE_DAYS => self::INT_180,
                self::FIELD_MODE => self::MODE_ROTATE_SIZE,
                self::FIELD_RATIONALE => 'ELEV-02 metric M series; monthly append cadence',
            ],
            self::FIELD_ACOS_VERIFIED_SHARE_V1 => [
                self::FIELD_MAX_SIZE_MB => self::INT_32,
                self::FIELD_MAX_AGE_DAYS => self::INT_90,
                self::FIELD_MODE => self::MODE_ROTATE_HYBRID,
                self::FIELD_RATIONALE => 'ELEV-12 verified-share observed daily',
            ],
            self::FIELD_ACOS_ASI05_LEDGER_CLEANUP_V1 => [
                self::FIELD_MAX_SIZE_MB => 8,
                self::FIELD_MAX_AGE_DAYS => self::INT_365,
                self::FIELD_MODE => self::MODE_APPEND_FOREVER,
                self::FIELD_RATIONALE => 'one-time cleanup receipt; audit forever',
            ],
            self::FIELD_ACOS_ESP00_GROUND_TRUTH_V1 => [
                self::FIELD_MAX_SIZE_MB => 8,
                self::FIELD_MAX_AGE_DAYS => self::INT_365,
                self::FIELD_MODE => self::MODE_APPEND_FOREVER,
                self::FIELD_RATIONALE => 'ESP-00 ground-truth receipt; audit forever',
            ],
            self::FIELD_ATLAS_EVIDENCE_LEDGER_HASH_CHAIN_V1 => [
                self::FIELD_MAX_SIZE_MB => self::INT_512,
                self::FIELD_MAX_AGE_DAYS => self::INT_365,
                self::FIELD_MODE => self::MODE_APPEND_FOREVER,
                self::FIELD_RATIONALE => 'MAXL-02 hash chain: never rotate, integrity backbone',
            ],
            'atlas.memory.temporal_truth.v2' => [
                self::FIELD_MAX_SIZE_MB => self::INT_32,
                self::FIELD_MAX_AGE_DAYS => self::INT_45,
                self::FIELD_MODE => self::MODE_ROTATE_HYBRID,
                self::FIELD_RATIONALE => 'MAXH-01 computed reader',
            ],
            self::FIELD_ATLAS_CAPTURE_COGNITIVE_IMMUNE_AUDIT_V2 => [
                self::FIELD_MAX_SIZE_MB => self::INT_128,
                self::FIELD_MAX_AGE_DAYS => self::INT_30,
                self::FIELD_MODE => self::MODE_ROTATE_HYBRID,
                self::FIELD_RATIONALE => 'MAXI-02 capture audit; DB table pruning',
            ],
            self::FIELD_ATLAS_IMMUNE_CALIBRATION_V1 => [
                self::FIELD_MAX_SIZE_MB => self::INT_128,
                self::FIELD_MAX_AGE_DAYS => self::INT_30,
                self::FIELD_MODE => self::MODE_ROTATE_HYBRID,
                self::FIELD_RATIONALE => 'MAXI-03 verdict ledger table',
            ],
            self::FIELD_ACOS_DEAD_SERIES_WATCHDOG_V1 => [
                self::FIELD_MAX_SIZE_MB => self::INT_32,
                self::FIELD_MAX_AGE_DAYS => self::INT_30,
                self::FIELD_MODE => self::MODE_ROTATE_HYBRID,
                self::FIELD_RATIONALE => 'ELEV-20s DB-backed watchdog run trail',
            ],
            self::FIELD_ACOS_OPERATOR_REVIEW_DEBT_V1 => [
                self::FIELD_MAX_SIZE_MB => self::INT_32,
                self::FIELD_MAX_AGE_DAYS => self::INT_30,
                self::FIELD_MODE => self::MODE_ROTATE_HYBRID,
                self::FIELD_RATIONALE => 'ELEV-25 review-debt watchdog trail',
            ],
            self::FIELD_ATLAS_AI_LESSON_QUALITY_V2 => [
                self::FIELD_MAX_SIZE_MB => self::INT_32,
                self::FIELD_MAX_AGE_DAYS => self::INT_60,
                self::FIELD_MODE => self::MODE_ROTATE_HYBRID,
                self::FIELD_RATIONALE => 'MAXJ-01 lesson quality reader',
            ],
            self::FIELD_ATLAS_AI_LESSON_TYPE_YIELD_V2 => [
                self::FIELD_MAX_SIZE_MB => self::INT_32,
                self::FIELD_MAX_AGE_DAYS => self::INT_60,
                self::FIELD_MODE => self::MODE_ROTATE_HYBRID,
                self::FIELD_RATIONALE => 'MAXJ-05 lesson type yield',
            ],
            self::FIELD_ATLAS_DECIDE_ROUTE_REGRET_V2 => [
                self::FIELD_MAX_SIZE_MB => self::INT_32,
                self::FIELD_MAX_AGE_DAYS => self::INT_60,
                self::FIELD_MODE => self::MODE_ROTATE_HYBRID,
                self::FIELD_RATIONALE => 'MAXK-01 route regret',
            ],
            self::FIELD_ATLAS_DECIDE_COST_OUTCOME_UNCERTAINTY_V1 => [
                self::FIELD_MAX_SIZE_MB => self::INT_32,
                self::FIELD_MAX_AGE_DAYS => self::INT_60,
                self::FIELD_MODE => self::MODE_ROTATE_HYBRID,
                self::FIELD_RATIONALE => 'MULTK-01 cost-outcome',
            ],
            self::FIELD_ATLAS_DECIDE_REPLAY_DIVERGENCE_V1 => [
                self::FIELD_MAX_SIZE_MB => self::INT_32,
                self::FIELD_MAX_AGE_DAYS => self::INT_30,
                self::FIELD_MODE => self::MODE_ROTATE_HYBRID,
                self::FIELD_RATIONALE => 'MULTK-03 decision replay divergence',
            ],
            self::FIELD_ATLAS_DECIDE_ZERO_WEIGHT_OUTCOMES_V1 => [
                self::FIELD_MAX_SIZE_MB => self::INT_32,
                self::FIELD_MAX_AGE_DAYS => self::INT_60,
                self::FIELD_MODE => self::MODE_ROTATE_HYBRID,
                self::FIELD_RATIONALE => 'ESP-05 zero-weight outcomes',
            ],
            self::FIELD_ATLAS_ESP_06_OUTCOME_ENVELOPE_V1 => [
                self::FIELD_MAX_SIZE_MB => self::INT_32,
                self::FIELD_MAX_AGE_DAYS => self::INT_90,
                self::FIELD_MODE => self::MODE_ROTATE_HYBRID,
                self::FIELD_RATIONALE => 'ESP-06 outcome envelope adapters',
            ],
            self::FIELD_ATLAS_ESP_09_CHALLENGER_ADVISORY_V1 => [
                self::FIELD_MAX_SIZE_MB => self::INT_16,
                self::FIELD_MAX_AGE_DAYS => self::INT_90,
                self::FIELD_MODE => self::MODE_ROTATE_HYBRID,
                self::FIELD_RATIONALE => 'ESP-09 independent challenger advisory series',
            ],
            'operator.approval_history.v1' => [
                self::FIELD_MAX_SIZE_MB => self::INT_32,
                self::FIELD_MAX_AGE_DAYS => self::INT_180,
                self::FIELD_MODE => self::MODE_ROTATE_HYBRID,
                self::FIELD_RATIONALE => 'MULTN15-02 approval history',
            ],
            self::FIELD_ATLAS_EVIDENCE_DELTA_ATTRIBUTION_V1 => [
                self::FIELD_MAX_SIZE_MB => self::INT_32,
                self::FIELD_MAX_AGE_DAYS => self::INT_90,
                self::FIELD_MODE => self::MODE_ROTATE_HYBRID,
                self::FIELD_RATIONALE => 'MAXL-06 delta attribution reader',
            ],
            self::FIELD_ATLAS_CONTEXT_GOLDEN_COUNTERFACTUAL_V1 => [
                self::FIELD_MAX_SIZE_MB => self::INT_32,
                self::FIELD_MAX_AGE_DAYS => self::INT_90,
                self::FIELD_MODE => self::MODE_ROTATE_HYBRID,
                self::FIELD_RATIONALE => 'MAXL-07 paired golden counterfactual report',
            ],
            self::FIELD_ATLAS_CONTEXT_EXECUTION_COOCCURRENCE_V1 => [
                self::FIELD_MAX_SIZE_MB => self::INT_32,
                self::FIELD_MAX_AGE_DAYS => self::INT_90,
                self::FIELD_MODE => self::MODE_ROTATE_HYBRID,
                self::FIELD_RATIONALE => 'MAXL-08 report-only context execution co-occurrence',
            ],
            'atlas.originator.predicted_impact_calibration.v1' => [
                self::FIELD_MAX_SIZE_MB => self::INT_32,
                self::FIELD_MAX_AGE_DAYS => self::INT_90,
                self::FIELD_MODE => self::MODE_ROTATE_HYBRID,
                self::FIELD_RATIONALE => 'MULTN17-04 originator impact',
            ],
            self::FIELD_ACOS_FLYWHEEL_LOOPS_V1 => [
                self::FIELD_MAX_SIZE_MB => self::INT_32,
                self::FIELD_MAX_AGE_DAYS => self::INT_90,
                self::FIELD_MODE => self::MODE_ROTATE_HYBRID,
                self::FIELD_RATIONALE => 'MULTX-01 loops',
            ],
            'atlas.m.funnel.v1' => [
                self::FIELD_MAX_SIZE_MB => self::INT_32,
                self::FIELD_MAX_AGE_DAYS => self::INT_30,
                self::FIELD_MODE => self::MODE_ROTATE_HYBRID,
                self::FIELD_RATIONALE => 'MULTX-02 diagnostic funnel by executor',
            ],
            self::FIELD_ACOS_LEARNING_LATENCY_V1 => [
                self::FIELD_MAX_SIZE_MB => self::INT_32,
                self::FIELD_MAX_AGE_DAYS => self::INT_90,
                self::FIELD_MODE => self::MODE_ROTATE_HYBRID,
                self::FIELD_RATIONALE => 'MULTX-06 learning latency',
            ],
            self::FIELD_ACOS_WINDOWS_ORCHESTRATOR_V1 => [
                self::FIELD_MAX_SIZE_MB => self::INT_32,
                self::FIELD_MAX_AGE_DAYS => self::INT_30,
                self::FIELD_MODE => self::MODE_ROTATE_HYBRID,
                self::FIELD_RATIONALE => 'MULTX-09 windows DAG snapshot',
            ],
            self::FIELD_ATLAS_AI_LESSON_HALF_LIFE_V2 => [
                self::FIELD_MAX_SIZE_MB => self::INT_32,
                self::FIELD_MAX_AGE_DAYS => self::INT_90,
                self::FIELD_MODE => self::MODE_ROTATE_HYBRID,
                self::FIELD_RATIONALE => 'MULTJ-01 lesson half-life',
            ],
            self::FIELD_ATLAS_AI_LESSON_SEMANTIC_DEDUP_V1 => [
                self::FIELD_MAX_SIZE_MB => self::INT_32,
                self::FIELD_MAX_AGE_DAYS => self::INT_90,
                self::FIELD_MODE => self::MODE_ROTATE_HYBRID,
                self::FIELD_RATIONALE => 'MULTJ-02 dedup calibration',
            ],
            self::FIELD_ATLAS_AI_COUNTERFACTUAL_LIFT_V2 => [
                self::FIELD_MAX_SIZE_MB => self::INT_32,
                self::FIELD_MAX_AGE_DAYS => self::INT_90,
                self::FIELD_MODE => self::MODE_ROTATE_HYBRID,
                self::FIELD_RATIONALE => 'MULTJ-03 counterfactual lift',
            ],
            'mission_e2e.v1' => [
                self::FIELD_MAX_SIZE_MB => self::INT_32,
                self::FIELD_MAX_AGE_DAYS => self::INT_90,
                self::FIELD_MODE => self::MODE_ROTATE_HYBRID,
                self::FIELD_RATIONALE => 'TETO-02 mission e2e',
            ],
            'atlas.resource_budget.v1' => [
                self::FIELD_MAX_SIZE_MB => 8,
                self::FIELD_MAX_AGE_DAYS => self::INT_30,
                self::FIELD_MODE => self::MODE_ROTATE_HYBRID,
                self::FIELD_RATIONALE => 'ELEV-27 joint budget reader; watchdog snapshot cadence',
            ],
            'atlas.test_attestation.v1' => [
                self::FIELD_MAX_SIZE_MB => 8,
                self::FIELD_MAX_AGE_DAYS => self::INT_90,
                self::FIELD_MODE => self::MODE_ROTATE_HYBRID,
                self::FIELD_RATIONALE => 'ESP-03 per-landing attestation seal; per-commit cadence',
            ],
            'atlas.provider_leak_corpus.v1' => [
                self::FIELD_MAX_SIZE_MB => 8,
                self::FIELD_MAX_AGE_DAYS => self::INT_365,
                self::FIELD_MODE => self::MODE_APPEND_FOREVER,
                self::FIELD_RATIONALE => 'MAXM-01 frozen-corpus baseline receipt; audit-anchor, small append cadence',
            ],
            self::FIELD_ATLAS_IMMUNE_CLASSIFIER_HYBRID_V1 => [
                self::FIELD_MAX_SIZE_MB => self::INT_16,
                self::FIELD_MAX_AGE_DAYS => self::INT_60,
                self::FIELD_MODE => self::MODE_ROTATE_HYBRID,
                self::FIELD_RATIONALE => 'MAXI-04 hybrid-classifier switch receipt; measurement cadence tied to arm-ON runs',
            ],
            'atlas.n_capture_drill.v1' => [
                self::FIELD_MAX_SIZE_MB => 8,
                self::FIELD_MAX_AGE_DAYS => self::INT_365,
                self::FIELD_MODE => self::MODE_APPEND_FOREVER,
                self::FIELD_RATIONALE => 'TETO-01 N-Capture Drill receipt: quarterly-ish cadence, permanent audit anchor for N×M thesis proofs',
            ],
            self::FIELD_ATLAS_KB_EMBEDDING_COVERAGE_V1 => [
                self::FIELD_MAX_SIZE_MB => 8,
                self::FIELD_MAX_AGE_DAYS => self::INT_60,
                self::FIELD_MODE => self::MODE_ROTATE_HYBRID,
                self::FIELD_RATIONALE => 'MAXA-06 fase 1 KB coverage reader; watchdog snapshot cadence tied to knowledge sync runs',
            ],
            'atlas.semantic.jina_v3_dual_read.v1' => [
                self::FIELD_MAX_SIZE_MB => self::INT_32,
                self::FIELD_MAX_AGE_DAYS => self::INT_90,
                self::FIELD_MODE => self::MODE_ROTATE_HYBRID,
                self::FIELD_RATIONALE => 'MAXA-04 shadow dual-read receipts before any model promotion',
            ],
            RagxChainMechanismService::AB_SCHEMA => [
                self::FIELD_MAX_SIZE_MB => self::INT_16,
                self::FIELD_MAX_AGE_DAYS => self::INT_90,
                self::FIELD_MODE => self::MODE_ROTATE_HYBRID,
                self::FIELD_RATIONALE => 'RAGX-07 records A/B registrations only; results remain null until a real window runs',
            ],
            self::FIELD_ATLAS_DECIDE_CASCADE_COST_ROUTER_V1 => [
                self::FIELD_MAX_SIZE_MB => self::INT_32,
                self::FIELD_MAX_AGE_DAYS => self::INT_60,
                self::FIELD_MODE => self::MODE_ROTATE_HYBRID,
                self::FIELD_RATIONALE => 'MULTK-02 cascade cost router computed-reader snapshots',
            ],
            self::FIELD_ATLAS_AI_PROCEDURAL_SKILL_PROMOTER_V1 => [
                self::FIELD_MAX_SIZE_MB => self::INT_16,
                self::FIELD_MAX_AGE_DAYS => self::INT_90,
                self::FIELD_MODE => self::MODE_ROTATE_HYBRID,
                self::FIELD_RATIONALE => 'MULTJ-04 procedural skill promoter reports stay small until real case-count soak',
            ],
            self::FIELD_ATLAS_AI_ABSTRACTION_LADDER_V1 => [
                self::FIELD_MAX_SIZE_MB => self::INT_16,
                self::FIELD_MAX_AGE_DAYS => self::INT_90,
                self::FIELD_MODE => self::MODE_ROTATE_HYBRID,
                self::FIELD_RATIONALE => 'MULTJ-06 abstraction ladder computed-reader snapshots',
            ],
            self::FIELD_ATLAS_IMMUNE_SIGNATURE_STORE_V1 => [
                self::FIELD_MAX_SIZE_MB => self::INT_64,
                self::FIELD_MAX_AGE_DAYS => self::INT_60,
                self::FIELD_MODE => self::MODE_ROTATE_HYBRID,
                self::FIELD_RATIONALE => 'MAXI-05 immune signature DB-backed store; watchdog/table pruning cadence',
            ],
            self::FIELD_ATLAS_CODE_SYMBOL_EMBEDDING_COVERAGE_V1 => [
                self::FIELD_MAX_SIZE_MB => 8,
                self::FIELD_MAX_AGE_DAYS => self::INT_60,
                self::FIELD_MODE => self::MODE_ROTATE_HYBRID,
                self::FIELD_RATIONALE => 'MAXA-06 fase 2 code-symbol embedding coverage reader',
            ],
            self::FIELD_ATLAS_AURG_PPR_SHADOW_DUAL_READ_V1 => [
                self::FIELD_MAX_SIZE_MB => self::INT_32,
                self::FIELD_MAX_AGE_DAYS => self::INT_90,
                self::FIELD_MODE => self::MODE_ROTATE_HYBRID,
                self::FIELD_RATIONALE => 'MAXD-04 PPR shadow dual-read receipts before any query promotion',
            ],
            self::FIELD_ATLAS_ACOS_REC06_META_LOOP_BREAKERS_V1 => [
                self::FIELD_MAX_SIZE_MB => 8,
                self::FIELD_MAX_AGE_DAYS => self::INT_30,
                self::FIELD_MODE => self::MODE_ROTATE_HYBRID,
                self::FIELD_RATIONALE => 'REC-06 computed breaker report; stays small until real series arm it',
            ],
        ];
    }

    /**
     * @param  array<string,array<string,mixed>>  $policies
     * @return array<string,array{max_size_mb:int,max_age_days:int,mode:string,rationale:string}>
     */
    private static function normalize(array $policies): array
    {
        $normalized = [];
        foreach ($policies as $series => $policy) {
            $seriesKey = AiValueNormalizer::trimmedStringOrNull($series) ?? '';
            if ($seriesKey === '') {
                continue;
            }
            $policy = AiValueNormalizer::arrayOrEmpty($policy);
            $mode = AiValueNormalizer::trimmedStringOrNull($policy[self::FIELD_MODE] ?? null) ?? self::MODE_ROTATE_HYBRID;
            if (! in_array($mode, [self::MODE_APPEND_FOREVER, self::MODE_ROTATE_SIZE, self::MODE_ROTATE_AGE, self::MODE_ROTATE_HYBRID], true)) {
                $mode = self::MODE_ROTATE_HYBRID;
            }
            $normalized[$seriesKey] = [
            self::FIELD_MAX_SIZE_MB => max(1, (int) (AiValueNormalizer::finiteFloatOrNull($policy[self::FIELD_MAX_SIZE_MB] ?? null) ?? self::DEFAULT_MAX_SIZE_MB)),
            self::FIELD_MAX_AGE_DAYS => max(1, (int) (AiValueNormalizer::finiteFloatOrNull($policy[self::FIELD_MAX_AGE_DAYS] ?? null) ?? self::DEFAULT_MAX_AGE_DAYS)),
                self::FIELD_MODE => $mode,
                self::FIELD_RATIONALE => AiValueNormalizer::trimmedStringOrNull($policy[self::FIELD_RATIONALE] ?? null) ?? '',
            ];
        }

        return $normalized;
    }
}
