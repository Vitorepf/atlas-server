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
        $series = AiValueNormalizer::trimmedString($series);

        return $series === '' ? null : ($this->policies[$series] ?? null);
    }

    /**
     * @return array<string,array{max_size_mb:int,max_age_days:int,mode:string,rationale:string}>
     */
    public static function defaultPolicies(): array
    {
        return [
            'aobg.latency_ledger.v1' => [
                'max_size_mb' => 128,
                'max_age_days' => 45,
                'mode' => 'rotate_hybrid',
                'rationale' => 'high-frequency append; MAXG-01 declares rotation contract',
            ],
            'asi.metric.m.v1' => [
                'max_size_mb' => 32,
                'max_age_days' => 180,
                'mode' => 'rotate_size',
                'rationale' => 'ELEV-02 metric M series; monthly append cadence',
            ],
            'acos.verified_share.v1' => [
                'max_size_mb' => 32,
                'max_age_days' => 90,
                'mode' => 'rotate_hybrid',
                'rationale' => 'ELEV-12 verified-share observed daily',
            ],
            'acos.asi05.ledger_cleanup.v1' => [
                'max_size_mb' => 8,
                'max_age_days' => 365,
                'mode' => 'append_forever',
                'rationale' => 'one-time cleanup receipt; audit forever',
            ],
            'acos.esp00.ground_truth.v1' => [
                'max_size_mb' => 8,
                'max_age_days' => 365,
                'mode' => 'append_forever',
                'rationale' => 'ESP-00 ground-truth receipt; audit forever',
            ],
            'atlas.evidence_ledger.hash_chain.v1' => [
                'max_size_mb' => 512,
                'max_age_days' => 365,
                'mode' => 'append_forever',
                'rationale' => 'MAXL-02 hash chain: never rotate, integrity backbone',
            ],
            'atlas.memory.temporal_truth.v2' => [
                'max_size_mb' => 32,
                'max_age_days' => 45,
                'mode' => 'rotate_hybrid',
                'rationale' => 'MAXH-01 computed reader',
            ],
            'atlas.capture.cognitive_immune_audit.v2' => [
                'max_size_mb' => 128,
                'max_age_days' => 30,
                'mode' => 'rotate_hybrid',
                'rationale' => 'MAXI-02 capture audit; DB table pruning',
            ],
            'atlas.immune.calibration.v1' => [
                'max_size_mb' => 128,
                'max_age_days' => 30,
                'mode' => 'rotate_hybrid',
                'rationale' => 'MAXI-03 verdict ledger table',
            ],
            'acos.dead_series_watchdog.v1' => [
                'max_size_mb' => 32,
                'max_age_days' => 30,
                'mode' => 'rotate_hybrid',
                'rationale' => 'ELEV-20s DB-backed watchdog run trail',
            ],
            'acos.operator_review_debt.v1' => [
                'max_size_mb' => 32,
                'max_age_days' => 30,
                'mode' => 'rotate_hybrid',
                'rationale' => 'ELEV-25 review-debt watchdog trail',
            ],
            'atlas.ai.lesson_quality.v2' => [
                'max_size_mb' => 32,
                'max_age_days' => 60,
                'mode' => 'rotate_hybrid',
                'rationale' => 'MAXJ-01 lesson quality reader',
            ],
            'atlas.ai.lesson_type_yield.v2' => [
                'max_size_mb' => 32,
                'max_age_days' => 60,
                'mode' => 'rotate_hybrid',
                'rationale' => 'MAXJ-05 lesson type yield',
            ],
            'atlas.decide.route_regret.v2' => [
                'max_size_mb' => 32,
                'max_age_days' => 60,
                'mode' => 'rotate_hybrid',
                'rationale' => 'MAXK-01 route regret',
            ],
            'atlas.decide.cost_outcome_uncertainty.v1' => [
                'max_size_mb' => 32,
                'max_age_days' => 60,
                'mode' => 'rotate_hybrid',
                'rationale' => 'MULTK-01 cost-outcome',
            ],
            'atlas.decide.replay_divergence.v1' => [
                'max_size_mb' => 32,
                'max_age_days' => 30,
                'mode' => 'rotate_hybrid',
                'rationale' => 'MULTK-03 decision replay divergence',
            ],
            'atlas.decide.zero_weight_outcomes.v1' => [
                'max_size_mb' => 32,
                'max_age_days' => 60,
                'mode' => 'rotate_hybrid',
                'rationale' => 'ESP-05 zero-weight outcomes',
            ],
            'atlas.esp_06.outcome_envelope.v1' => [
                'max_size_mb' => 32,
                'max_age_days' => 90,
                'mode' => 'rotate_hybrid',
                'rationale' => 'ESP-06 outcome envelope adapters',
            ],
            'atlas.esp_09.challenger_advisory.v1' => [
                'max_size_mb' => 16,
                'max_age_days' => 90,
                'mode' => 'rotate_hybrid',
                'rationale' => 'ESP-09 independent challenger advisory series',
            ],
            'operator.approval_history.v1' => [
                'max_size_mb' => 32,
                'max_age_days' => 180,
                'mode' => 'rotate_hybrid',
                'rationale' => 'MULTN15-02 approval history',
            ],
            'atlas.evidence.delta_attribution.v1' => [
                'max_size_mb' => 32,
                'max_age_days' => 90,
                'mode' => 'rotate_hybrid',
                'rationale' => 'MAXL-06 delta attribution reader',
            ],
            'atlas.context.golden_counterfactual.v1' => [
                'max_size_mb' => 32,
                'max_age_days' => 90,
                'mode' => 'rotate_hybrid',
                'rationale' => 'MAXL-07 paired golden counterfactual report',
            ],
            'atlas.context.execution_cooccurrence.v1' => [
                'max_size_mb' => 32,
                'max_age_days' => 90,
                'mode' => 'rotate_hybrid',
                'rationale' => 'MAXL-08 report-only context execution co-occurrence',
            ],
            'atlas.originator.predicted_impact_calibration.v1' => [
                'max_size_mb' => 32,
                'max_age_days' => 90,
                'mode' => 'rotate_hybrid',
                'rationale' => 'MULTN17-04 originator impact',
            ],
            'acos.flywheel.loops.v1' => [
                'max_size_mb' => 32,
                'max_age_days' => 90,
                'mode' => 'rotate_hybrid',
                'rationale' => 'MULTX-01 loops',
            ],
            'atlas.m.funnel.v1' => [
                'max_size_mb' => 32,
                'max_age_days' => 30,
                'mode' => 'rotate_hybrid',
                'rationale' => 'MULTX-02 diagnostic funnel by executor',
            ],
            'acos.learning_latency.v1' => [
                'max_size_mb' => 32,
                'max_age_days' => 90,
                'mode' => 'rotate_hybrid',
                'rationale' => 'MULTX-06 learning latency',
            ],
            'acos.windows_orchestrator.v1' => [
                'max_size_mb' => 32,
                'max_age_days' => 30,
                'mode' => 'rotate_hybrid',
                'rationale' => 'MULTX-09 windows DAG snapshot',
            ],
            'atlas.ai.lesson_half_life.v2' => [
                'max_size_mb' => 32,
                'max_age_days' => 90,
                'mode' => 'rotate_hybrid',
                'rationale' => 'MULTJ-01 lesson half-life',
            ],
            'atlas.ai.lesson_semantic_dedup.v1' => [
                'max_size_mb' => 32,
                'max_age_days' => 90,
                'mode' => 'rotate_hybrid',
                'rationale' => 'MULTJ-02 dedup calibration',
            ],
            'atlas.ai.counterfactual_lift.v2' => [
                'max_size_mb' => 32,
                'max_age_days' => 90,
                'mode' => 'rotate_hybrid',
                'rationale' => 'MULTJ-03 counterfactual lift',
            ],
            'mission_e2e.v1' => [
                'max_size_mb' => 32,
                'max_age_days' => 90,
                'mode' => 'rotate_hybrid',
                'rationale' => 'TETO-02 mission e2e',
            ],
            'atlas.resource_budget.v1' => [
                'max_size_mb' => 8,
                'max_age_days' => 30,
                'mode' => 'rotate_hybrid',
                'rationale' => 'ELEV-27 joint budget reader; watchdog snapshot cadence',
            ],
            'atlas.test_attestation.v1' => [
                'max_size_mb' => 8,
                'max_age_days' => 90,
                'mode' => 'rotate_hybrid',
                'rationale' => 'ESP-03 per-landing attestation seal; per-commit cadence',
            ],
            'atlas.provider_leak_corpus.v1' => [
                'max_size_mb' => 8,
                'max_age_days' => 365,
                'mode' => 'append_forever',
                'rationale' => 'MAXM-01 frozen-corpus baseline receipt; audit-anchor, small append cadence',
            ],
            'atlas.immune.classifier_hybrid.v1' => [
                'max_size_mb' => 16,
                'max_age_days' => 60,
                'mode' => 'rotate_hybrid',
                'rationale' => 'MAXI-04 hybrid-classifier switch receipt; measurement cadence tied to arm-ON runs',
            ],
            'atlas.n_capture_drill.v1' => [
                'max_size_mb' => 8,
                'max_age_days' => 365,
                'mode' => 'append_forever',
                'rationale' => 'TETO-01 N-Capture Drill receipt: quarterly-ish cadence, permanent audit anchor for N×M thesis proofs',
            ],
            'atlas.kb_embedding_coverage.v1' => [
                'max_size_mb' => 8,
                'max_age_days' => 60,
                'mode' => 'rotate_hybrid',
                'rationale' => 'MAXA-06 fase 1 KB coverage reader; watchdog snapshot cadence tied to knowledge sync runs',
            ],
            'atlas.semantic.jina_v3_dual_read.v1' => [
                'max_size_mb' => 32,
                'max_age_days' => 90,
                'mode' => 'rotate_hybrid',
                'rationale' => 'MAXA-04 shadow dual-read receipts before any model promotion',
            ],
            RagxChainMechanismService::AB_SCHEMA => [
                'max_size_mb' => 16,
                'max_age_days' => 90,
                'mode' => 'rotate_hybrid',
                'rationale' => 'RAGX-07 records A/B registrations only; results remain null until a real window runs',
            ],
            'atlas.decide.cascade_cost_router.v1' => [
                'max_size_mb' => 32,
                'max_age_days' => 60,
                'mode' => 'rotate_hybrid',
                'rationale' => 'MULTK-02 cascade cost router computed-reader snapshots',
            ],
            'atlas.ai.procedural_skill_promoter.v1' => [
                'max_size_mb' => 16,
                'max_age_days' => 90,
                'mode' => 'rotate_hybrid',
                'rationale' => 'MULTJ-04 procedural skill promoter reports stay small until real case-count soak',
            ],
            'atlas.ai.abstraction_ladder.v1' => [
                'max_size_mb' => 16,
                'max_age_days' => 90,
                'mode' => 'rotate_hybrid',
                'rationale' => 'MULTJ-06 abstraction ladder computed-reader snapshots',
            ],
            'atlas.immune.signature_store.v1' => [
                'max_size_mb' => 64,
                'max_age_days' => 60,
                'mode' => 'rotate_hybrid',
                'rationale' => 'MAXI-05 immune signature DB-backed store; watchdog/table pruning cadence',
            ],
            'atlas.code_symbol_embedding_coverage.v1' => [
                'max_size_mb' => 8,
                'max_age_days' => 60,
                'mode' => 'rotate_hybrid',
                'rationale' => 'MAXA-06 fase 2 code-symbol embedding coverage reader',
            ],
            'atlas.aurg.ppr_shadow_dual_read.v1' => [
                'max_size_mb' => 32,
                'max_age_days' => 90,
                'mode' => 'rotate_hybrid',
                'rationale' => 'MAXD-04 PPR shadow dual-read receipts before any query promotion',
            ],
            'atlas.acos.rec06.meta_loop_breakers.v1' => [
                'max_size_mb' => 8,
                'max_age_days' => 30,
                'mode' => 'rotate_hybrid',
                'rationale' => 'REC-06 computed breaker report; stays small until real series arm it',
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
            $seriesKey = AiValueNormalizer::trimmedString($series);
            if ($seriesKey === '') {
                continue;
            }
            $policy = AiValueNormalizer::arrayOrEmpty($policy);
            $mode = AiValueNormalizer::trimmedString($policy['mode'] ?? 'rotate_hybrid');
            if (! in_array($mode, ['append_forever', 'rotate_size', 'rotate_age', 'rotate_hybrid'], true)) {
                $mode = 'rotate_hybrid';
            }
            $normalized[$seriesKey] = [
                'max_size_mb' => max(1, (int) ($policy['max_size_mb'] ?? 32)),
                'max_age_days' => max(1, (int) ($policy['max_age_days'] ?? 30)),
                'mode' => $mode,
                'rationale' => AiValueNormalizer::trimmedString($policy['rationale'] ?? ''),
            ];
        }

        return $normalized;
    }
}
