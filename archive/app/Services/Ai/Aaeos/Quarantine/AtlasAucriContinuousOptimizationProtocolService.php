<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

use App\Services\Ai\Mission\MissionCanonicalHash;

/**
 * Atlas AUCRI Continuous Optimization Protocol (ACOPRO) — runtime decider.
 *
 * Turns the protocol doc into PURE, deterministic decision logic. Existing
 * sibling services audit the doc/file ecosystem (AtlasAucriOptimizationAuditService)
 * and emit the static canary contract (AtlasAucriTokenQualityCanarySetService).
 * Neither evaluates an actual baseline-vs-variant optimization experiment. THIS
 * service is that missing piece: given a captured experiment it decides
 * promote / revert / reject, enforcing every documented quality gate so that a
 * token reduction is only accepted when quality, evidence and must-keep are
 * preserved and a rollback exists.
 *
 * Concrete contracts implemented (doc body):
 *
 *  - "Contratos" / "Campos minimos". The minimum experiment fields are
 *    target_block, baseline_hash, variant_hash, input_tokens_before/after,
 *    output_tokens_before/after, quality_score_before/after, must_keep_coverage,
 *    evidence_coverage, rollback_ref, receipt_hash. {@see requiredFields()} is
 *    that contract; {@see evaluateExperiment()} rejects an experiment that is
 *    missing any of them ("Nao promover variante sem baseline reproduzivel").
 *
 *  - "Fluxo" step 6: "Promover apenas se token cai sem queda de qualidade."
 *    {@see evaluateExperiment()} only returns decision=promote when total tokens
 *    strictly drop AND quality_score does not regress. A variant that does not
 *    reduce tokens is reject(no_token_saving); a variant that reduces tokens but
 *    drops quality is revert(quality_regression).
 *
 *  - "Evidencias" (minimum evidence): must_keep_coverage = 1.0,
 *    evidence_coverage without regression, quality_score equal-or-greater,
 *    rollback_ref present. Each is a hard gate. Breaking must_keep is the most
 *    severe: the doc's "Regras para IA" say "Nao aceitar compressao que remove
 *    decision, blocker, DoD, constraint ou risk", so must_keep_coverage < 1.0
 *    forces decision=revert.
 *
 *  - "Regras para IA":
 *      * "Nao contar token saving de resposta incompleta." A variant whose
 *        response_complete flag is false cannot count its saving → reject.
 *      * "Nao trocar provider por custo se o risk level exige modelo superior."
 *        For a high_risk experiment a provider downgrade (capability rank drops)
 *        forces decision=reject(provider_downgrade_blocked_high_risk).
 *      * "Nao otimizar por media se caso high-risk piorou." {@see decideBatch()}
 *        never promotes a batch when any high-risk case regressed, even if the
 *        average improved.
 *
 *  - "Riscos": rollback safety. A promote without a usable rollback_ref is
 *    impossible — absence is a critical violation, never a warning.
 *
 * All methods are pure (no DB, no clock, no IO) and return strict typed arrays.
 *
 * @see docs/engineering-knowledge-base/atlas-aucri-continuous-optimization-protocol.md
 */
final class AtlasAucriContinuousOptimizationProtocolService
{
    public const SCHEMA_VERSION = 'atlas.aucri.optimization_decision.v1';

    public const DECISION_PROMOTE = 'promote';

    public const DECISION_REVERT = 'revert';

    public const DECISION_REJECT = 'reject';

    /**
     * must_keep_coverage must equal exactly this. The doc's evidence section is
     * unambiguous: "must_keep_coverage = 1.0".
     */
    public const MUST_KEEP_REQUIRED = 1.0;

    /**
     * Kinds of context the doc forbids dropping during compression
     * ("decision, blocker, DoD, constraint ou risk"). Used by
     * {@see mustKeepKinds()} and the canary contract surface.
     *
     * @var array<int,string>
     */
    private const PROTECTED_KINDS = ['decision', 'blocker', 'dod', 'constraint', 'risk'];

    /**
     * The ten mandatory continuous-improvement techniques the doc lists under
     * "Escopo de Implementacao". Read model only.
     *
     * @var array<int,string>
     */
    private const TECHNIQUES = [
        'context_ablation_testing',
        'semantic_redundancy_removal',
        'prompt_distillation',
        'local_tool_substitution',
        'output_minimality_contract',
        'provider_aware_packing',
        'segment_roi_scoring',
        'failure_driven_retrieval_tuning',
        'stop_rule_for_retrieval',
        'regression_canary_set',
    ];

    /**
     * The minimum experiment fields from the doc's "Contratos" section. A field
     * present here but absent from an experiment makes the experiment
     * non-reproducible and therefore non-promotable.
     *
     * @return array<int,string>
     */
    public function requiredFields(): array
    {
        return [
            'target_block',
            'baseline_hash',
            'variant_hash',
            'input_tokens_before',
            'input_tokens_after',
            'output_tokens_before',
            'output_tokens_after',
            'quality_score_before',
            'quality_score_after',
            'must_keep_coverage',
            'evidence_coverage',
            'rollback_ref',
            'receipt_hash',
        ];
    }

    /**
     * @return array<int,string>
     */
    public function mustKeepKinds(): array
    {
        return self::PROTECTED_KINDS;
    }

    /**
     * @return array<int,string>
     */
    public function techniques(): array
    {
        return self::TECHNIQUES;
    }

    /**
     * Evaluate one optimization experiment and decide promote / revert / reject.
     *
     * Decision order (most severe wins, all violations still reported):
     *   1. Missing required field            → reject  (not reproducible)
     *   2. Incomplete response               → reject  (saving doesn't count)
     *   3. high_risk provider downgrade      → reject  (risk needs better model)
     *   4. must_keep_coverage < 1.0          → revert  (dropped protected context)
     *   5. quality_score regressed           → revert  (quality loss)
     *   6. evidence_coverage regressed       → revert  (lost evidence)
     *   7. missing rollback_ref              → revert  (no safe rollback)
     *   8. no token saving                   → reject  (nothing to promote)
     *   9. otherwise                         → promote
     *
     * @param  array<string,mixed>  $experiment
     * @return array<string,mixed>
     */
    public function evaluateExperiment(array $experiment): array
    {
        $violations = [];

        // Gate 1 — reproducibility. Every minimum field must be present.
        $missingFields = array_values(array_filter(
            $this->requiredFields(),
            static fn (string $field): bool => ! array_key_exists($field, $experiment),
        ));

        $inputBefore = $this->num($experiment['input_tokens_before'] ?? null);
        $inputAfter = $this->num($experiment['input_tokens_after'] ?? null);
        $outputBefore = $this->num($experiment['output_tokens_before'] ?? null);
        $outputAfter = $this->num($experiment['output_tokens_after'] ?? null);
        $qualityBefore = $this->num($experiment['quality_score_before'] ?? null);
        $qualityAfter = $this->num($experiment['quality_score_after'] ?? null);
        $mustKeep = $this->num($experiment['must_keep_coverage'] ?? null);
        $evidenceBefore = $this->num($experiment['evidence_coverage_before'] ?? $experiment['evidence_coverage'] ?? null);
        $evidenceAfter = $this->num($experiment['evidence_coverage'] ?? null);

        $rollbackRef = $experiment['rollback_ref'] ?? null;
        $hasRollback = is_string($rollbackRef) && trim($rollbackRef) !== '';

        $responseComplete = ($experiment['response_complete'] ?? true) !== false;
        $isHighRisk = ($experiment['risk_level'] ?? null) === 'high';

        $totalBefore = $inputBefore + $outputBefore;
        $totalAfter = $inputAfter + $outputAfter;
        $tokensSaved = $totalBefore - $totalAfter;
        $tokenSaving = $tokensSaved > 0;

        $qualityRegressed = $qualityAfter < $qualityBefore;
        $evidenceRegressed = $evidenceAfter < $evidenceBefore;
        $mustKeepOk = $this->approximatelyEqual($mustKeep, self::MUST_KEEP_REQUIRED);

        // Provider capability downgrade: only matters for high-risk experiments.
        $providerDowngrade = false;
        if (array_key_exists('provider_capability_rank_before', $experiment)
            || array_key_exists('provider_capability_rank_after', $experiment)) {
            $rankBefore = $this->num($experiment['provider_capability_rank_before'] ?? null);
            $rankAfter = $this->num($experiment['provider_capability_rank_after'] ?? null);
            $providerDowngrade = $rankAfter < $rankBefore;
        }

        // Collect every violation (so the receipt is auditable), then pick
        // the governing decision by severity.
        if ($missingFields !== []) {
            $violations[] = ['code' => 'missing_required_fields', 'severity' => 'critical', 'detail' => $missingFields];
        }
        if (! $responseComplete) {
            $violations[] = ['code' => 'incomplete_response_saving_not_counted', 'severity' => 'critical', 'detail' => 'response_complete=false'];
        }
        if ($isHighRisk && $providerDowngrade) {
            $violations[] = ['code' => 'provider_downgrade_blocked_high_risk', 'severity' => 'critical', 'detail' => 'risk_level=high requires equal-or-better model'];
        }
        if (! $mustKeepOk) {
            $violations[] = ['code' => 'must_keep_coverage_below_one', 'severity' => 'critical', 'detail' => $mustKeep];
        }
        if ($qualityRegressed) {
            $violations[] = ['code' => 'quality_regression', 'severity' => 'critical', 'detail' => ['before' => $qualityBefore, 'after' => $qualityAfter]];
        }
        if ($evidenceRegressed) {
            $violations[] = ['code' => 'evidence_coverage_regression', 'severity' => 'critical', 'detail' => ['before' => $evidenceBefore, 'after' => $evidenceAfter]];
        }
        if (! $hasRollback) {
            $violations[] = ['code' => 'missing_rollback_ref', 'severity' => 'critical', 'detail' => 'rollback_ref required for any promote'];
        }
        if (! $tokenSaving) {
            $violations[] = ['code' => 'no_token_saving', 'severity' => 'warn', 'detail' => ['tokens_saved' => $tokensSaved]];
        }

        // Decision selection. "reject" = experiment is invalid/inadmissible
        // (can't even be trusted); "revert" = admissible but failed a quality
        // gate (roll back to baseline); "promote" = passed everything.
        [$decision, $reason] = $this->decide(
            missingFields: $missingFields,
            responseComplete: $responseComplete,
            highRiskProviderDowngrade: $isHighRisk && $providerDowngrade,
            mustKeepOk: $mustKeepOk,
            qualityRegressed: $qualityRegressed,
            evidenceRegressed: $evidenceRegressed,
            hasRollback: $hasRollback,
            tokenSaving: $tokenSaving,
        );

        $savingRatio = $totalBefore > 0
            ? round(max(0.0, $tokensSaved) / $totalBefore, 6)
            : 0.0;

        $result = [
            'schema_version' => self::SCHEMA_VERSION,
            'target_block' => is_string($experiment['target_block'] ?? null) ? $experiment['target_block'] : null,
            'decision' => $decision,
            'reason' => $reason,
            'promotable' => $decision === self::DECISION_PROMOTE,
            'risk_level' => $isHighRisk ? 'high' : (is_string($experiment['risk_level'] ?? null) ? $experiment['risk_level'] : 'medium'),
            'token_saving' => [
                'total_before' => $totalBefore,
                'total_after' => $totalAfter,
                'tokens_saved' => $tokensSaved,
                'saving_ratio' => $savingRatio,
                'counts' => $tokenSaving && $responseComplete,
            ],
            'quality_gate' => [
                'must_keep_coverage' => $mustKeep,
                'must_keep_ok' => $mustKeepOk,
                'quality_score_before' => $qualityBefore,
                'quality_score_after' => $qualityAfter,
                'quality_preserved' => ! $qualityRegressed,
                'evidence_coverage_before' => $evidenceBefore,
                'evidence_coverage_after' => $evidenceAfter,
                'evidence_preserved' => ! $evidenceRegressed,
                'rollback_ref_present' => $hasRollback,
                'response_complete' => $responseComplete,
            ],
            'violations' => $violations,
            'gates_failed' => array_values(array_map(
                static fn (array $v): string => (string) $v['code'],
                $violations,
            )),
        ];

        $result['decision_hash'] = MissionCanonicalHash::sha256($result);

        return $result;
    }

    /**
     * Batch decision over many experiments (the doc's flow matrix: Dev, Forge,
     * Research, Finance, Strategy). Enforces "Nao otimizar por media se caso
     * high-risk piorou": the batch only promotes when EVERY high-risk case is
     * itself promotable, regardless of the average outcome.
     *
     * @param  array<int,array<string,mixed>>  $experiments
     * @return array<string,mixed>
     */
    public function decideBatch(array $experiments): array
    {
        $evaluations = array_map(
            fn (array $experiment): array => $this->evaluateExperiment($experiment),
            array_values($experiments),
        );

        $total = count($evaluations);
        $promotable = array_values(array_filter(
            $evaluations,
            static fn (array $e): bool => $e['decision'] === self::DECISION_PROMOTE,
        ));

        $highRiskRegressed = array_values(array_filter(
            $evaluations,
            static fn (array $e): bool => $e['risk_level'] === 'high'
                && $e['decision'] !== self::DECISION_PROMOTE,
        ));

        $batchPromotable = $total > 0
            && count($promotable) === $total
            && $highRiskRegressed === [];

        $blockReason = match (true) {
            $total === 0 => 'no_experiments',
            $highRiskRegressed !== [] => 'high_risk_case_regressed',
            count($promotable) !== $total => 'some_case_not_promotable',
            default => null,
        };

        $result = [
            'schema_version' => self::SCHEMA_VERSION,
            'batch_decision' => $batchPromotable ? self::DECISION_PROMOTE : self::DECISION_REVERT,
            'batch_promotable' => $batchPromotable,
            'block_reason' => $blockReason,
            'summary' => [
                'total' => $total,
                'promotable' => count($promotable),
                'not_promotable' => $total - count($promotable),
                'high_risk_regressed' => count($highRiskRegressed),
            ],
            'evaluations' => $evaluations,
        ];

        $result['batch_hash'] = MissionCanonicalHash::sha256($result);

        return $result;
    }

    /**
     * Convenience snapshot for the command's no-arg invocation: the contract
     * surface plus a worked promote-vs-revert example.
     *
     * @return array<string,mixed>
     */
    public function snapshot(): array
    {
        $clean = $this->evaluateExperiment([
            'target_block' => 'ATER',
            'baseline_hash' => 'base-aaaa',
            'variant_hash' => 'var-bbbb',
            'input_tokens_before' => 40000,
            'input_tokens_after' => 12000,
            'output_tokens_before' => 2000,
            'output_tokens_after' => 2000,
            'quality_score_before' => 0.91,
            'quality_score_after' => 0.93,
            'must_keep_coverage' => 1.0,
            'evidence_coverage' => 1.0,
            'rollback_ref' => 'rollback://ater/var-bbbb',
            'receipt_hash' => 'receipt-cccc',
            'response_complete' => true,
        ]);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'required_fields' => $this->requiredFields(),
            'must_keep_required' => self::MUST_KEEP_REQUIRED,
            'protected_must_keep_kinds' => $this->mustKeepKinds(),
            'techniques' => $this->techniques(),
            'example_clean_promote' => $clean,
        ];
    }

    /**
     * @param  array<int,string>  $missingFields
     * @return array{0:string,1:string}
     */
    private function decide(
        array $missingFields,
        bool $responseComplete,
        bool $highRiskProviderDowngrade,
        bool $mustKeepOk,
        bool $qualityRegressed,
        bool $evidenceRegressed,
        bool $hasRollback,
        bool $tokenSaving,
    ): array {
        // Inadmissible experiments → reject (cannot be trusted at all).
        if ($missingFields !== []) {
            return [self::DECISION_REJECT, 'missing_required_fields'];
        }
        if (! $responseComplete) {
            return [self::DECISION_REJECT, 'incomplete_response_saving_not_counted'];
        }
        if ($highRiskProviderDowngrade) {
            return [self::DECISION_REJECT, 'provider_downgrade_blocked_high_risk'];
        }

        // Admissible but failed a quality gate → revert to baseline.
        if (! $mustKeepOk) {
            return [self::DECISION_REVERT, 'must_keep_coverage_below_one'];
        }
        if ($qualityRegressed) {
            return [self::DECISION_REVERT, 'quality_regression'];
        }
        if ($evidenceRegressed) {
            return [self::DECISION_REVERT, 'evidence_coverage_regression'];
        }
        if (! $hasRollback) {
            return [self::DECISION_REVERT, 'missing_rollback_ref'];
        }

        // Passed every gate but produced no saving → nothing to promote.
        if (! $tokenSaving) {
            return [self::DECISION_REJECT, 'no_token_saving'];
        }

        return [self::DECISION_PROMOTE, 'token_reduced_quality_preserved'];
    }

    private function num(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function approximatelyEqual(float $a, float $b): bool
    {
        return abs($a - $b) < 0.0000001;
    }
}
