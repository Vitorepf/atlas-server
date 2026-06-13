<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfImprovement;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Atlas Self-Improvement Regression Sentinel.
 *
 * Hunts for regressions that ordinary tests do not catch. Compares
 * before/after snapshots and the implementation diff descriptor to look for:
 *   - public API drift without doc;
 *   - fail-closed → fail-open;
 *   - new unapproved provider call;
 *   - completion claim promoted without review;
 *   - silent fallback;
 *   - external Rivals unblocked by synthetic score;
 *   - UI confusion (more steps without gain);
 *   - complexity grew without maturity delta;
 *   - docs/runtime divergence.
 *
 * Hard rules:
 *   - NEVER promotes;
 *   - NEVER calls provider;
 *   - ANY severe finding blocks promotion (caller must combine with Invariant
 *     Lock + Delta Scorecard before approving).
 *
 * Schema: atlas.self_improvement.regression_sentinel.v1
 */
class AtlasSelfImprovementRegressionSentinelService
{
    public const SCHEMA_VERSION = 'atlas.self_improvement.regression_sentinel.v1';

    public const STATUS_CLEAR = 'clear';

    public const STATUS_FINDINGS = 'findings';

    public const STATUS_BLOCKED = 'blocked';

    public const SEVERITY_INFO = 'info';

    public const SEVERITY_WARN = 'warn';

    public const SEVERITY_SEVERE = 'severe';

    /**
     * Scan the post-implementation snapshot for hidden regressions.
     *
     * @param  array<string,mixed>  $beforeSnapshot
     * @param  array<string,mixed>  $afterSnapshot
     * @param  array<string,mixed>  $implementationDiff
     * @return array<string,mixed>
     */
    public function scan(
        array $beforeSnapshot,
        array $afterSnapshot,
        array $implementationDiff = [],
    ): array {
        $findings = [];

        $findings = array_merge($findings, $this->scanApiDrift($beforeSnapshot, $afterSnapshot, $implementationDiff));
        $findings = array_merge($findings, $this->scanFailClosedToOpen($beforeSnapshot, $afterSnapshot));
        $findings = array_merge($findings, $this->scanProviderCall($afterSnapshot, $implementationDiff));
        $findings = array_merge($findings, $this->scanCompletionWithoutReview($afterSnapshot));
        $findings = array_merge($findings, $this->scanSilentFallback($afterSnapshot));
        $findings = array_merge($findings, $this->scanRivalsSynthetic($afterSnapshot));
        $findings = array_merge($findings, $this->scanUiConfusion($implementationDiff));
        $findings = array_merge($findings, $this->scanComplexityWithoutMaturity($implementationDiff));
        $findings = array_merge($findings, $this->scanDocsRuntimeDivergence($beforeSnapshot, $afterSnapshot));
        $observedBehaviorOracle = $this->scanObservedBehaviorDrift($beforeSnapshot, $afterSnapshot);
        $findings = array_merge($findings, $observedBehaviorOracle['findings']);

        $severeCount = 0;
        $warnCount = 0;
        $infoCount = 0;
        foreach ($findings as $finding) {
            match ($finding['severity']) {
                self::SEVERITY_SEVERE => $severeCount++,
                self::SEVERITY_WARN => $warnCount++,
                default => $infoCount++,
            };
        }

        $status = match (true) {
            $severeCount > 0 => self::STATUS_BLOCKED,
            $warnCount > 0 || $infoCount > 0 => self::STATUS_FINDINGS,
            default => self::STATUS_CLEAR,
        };

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'sentinel_id' => 'sent_'.(string) Str::ulid(),
            'evaluated_at' => Carbon::now()->toIso8601String(),
            'status' => $status,
            'findings' => array_values($findings),
            'finding_counts' => [
                'severe' => $severeCount,
                'warn' => $warnCount,
                'info' => $infoCount,
                'total' => count($findings),
            ],
            'next_action' => match ($status) {
                self::STATUS_CLEAR => 'sentinel_clear_run_delta_scorecard',
                self::STATUS_FINDINGS => 'review_findings_before_promotion',
                self::STATUS_BLOCKED => 'rollback_or_revise_until_severe_findings_clear',
                default => 'inspect_status',
            },
            'invariants' => [
                'severe_finding_blocks_promotion' => true,
                'never_calls_external_provider' => true,
                'never_unlocks_external_rivals_claim' => true,
                'uncovered_observed_behavior_drift_blocks_promotion' => true,
            ],
            'observed_behavior_oracle' => $observedBehaviorOracle,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'separated_from' => 'external_rivals_certification',
            'is_read_model' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $before
     * @param  array<string,mixed>  $after
     * @param  array<string,mixed>  $diff
     * @return list<array<string,mixed>>
     */
    private function scanApiDrift(array $before, array $after, array $diff): array
    {
        $findings = [];
        if ((bool) ($diff['changed_public_api'] ?? false)
            && ! (bool) ($diff['updated_canonical_docs'] ?? false)
        ) {
            $findings[] = $this->finding(
                'public_api_changed_without_doc_update',
                self::SEVERITY_SEVERE,
                'public API surface changed but no canonical doc was updated',
            );
        }

        return $findings;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function scanFailClosedToOpen(array $before, array $after): array
    {
        $findings = [];
        $beforeFlag = (bool) data_get($before, 'completion_audit.rules.synthetic_scores_allowed', false);
        $afterFlag = (bool) data_get($after, 'completion_audit.rules.synthetic_scores_allowed', false);
        if (! $beforeFlag && $afterFlag) {
            $findings[] = $this->finding(
                'synthetic_scores_were_disabled_now_enabled',
                self::SEVERITY_SEVERE,
                'fail-closed (synthetic_scores_allowed=false) became fail-open',
            );
        }

        return $findings;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function scanProviderCall(array $after, array $diff): array
    {
        $findings = [];
        if ((bool) data_get($after, 'completion_audit.atlas_forge_continuum_certification.external_provider_call', false)) {
            $findings[] = $this->finding(
                'continuum_audit_now_reports_external_provider_call',
                self::SEVERITY_SEVERE,
                'continuum certification flipped external_provider_call=true',
            );
        }
        if ((bool) ($diff['adds_unapproved_provider_call'] ?? false)) {
            $findings[] = $this->finding(
                'implementation_diff_introduces_unapproved_provider_call',
                self::SEVERITY_SEVERE,
                'implementation introduces a real provider call without approval gate',
            );
        }

        return $findings;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function scanCompletionWithoutReview(array $after): array
    {
        $findings = [];
        if (! (bool) data_get(
            $after,
            'completion_audit.atlas_code_forge_review_completion_certification.lifecycle_invariants.no_auto_completion_without_human_review',
            true,
        )) {
            $findings[] = $this->finding(
                'completion_gate_lost_no_auto_completion_invariant',
                self::SEVERITY_SEVERE,
                'review/completion gate no longer asserts no_auto_completion_without_human_review',
            );
        }

        return $findings;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function scanSilentFallback(array $after): array
    {
        $findings = [];
        if (! (bool) data_get($after, 'completion_audit.atlas_forge_continuum_certification.no_silent_fallback', true)) {
            $findings[] = $this->finding(
                'no_silent_fallback_invariant_lost',
                self::SEVERITY_SEVERE,
                'continuum audit no longer asserts no_silent_fallback',
            );
        }

        return $findings;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function scanRivalsSynthetic(array $after): array
    {
        $findings = [];
        if ((bool) data_get($after, 'completion_audit.rules.synthetic_scores_allowed', false)) {
            $findings[] = $this->finding(
                'synthetic_scores_allowed_in_rules',
                self::SEVERITY_SEVERE,
                'completion audit rules.synthetic_scores_allowed flipped to true',
            );
        }
        if (! (bool) data_get($after, 'completion_audit.atlas_forge_continuum_certification.separated_from_external_rivals', true)) {
            $findings[] = $this->finding(
                'continuum_audit_lost_separation_from_external_rivals',
                self::SEVERITY_SEVERE,
                'continuum audit lost separated_from_external_rivals',
            );
        }

        return $findings;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function scanUiConfusion(array $diff): array
    {
        $findings = [];
        if ((bool) ($diff['adds_extra_steps_without_gain'] ?? false)) {
            $findings[] = $this->finding(
                'ui_added_extra_steps_without_documented_gain',
                self::SEVERITY_WARN,
                'implementation adds extra steps without documented operator gain',
            );
        }

        return $findings;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function scanComplexityWithoutMaturity(array $diff): array
    {
        $findings = [];
        $linesAdded = (int) ($diff['lines_added'] ?? 0);
        $maturityDelta = (int) ($diff['maturity_delta'] ?? 0);
        if ($linesAdded > 500 && $maturityDelta <= 0) {
            $findings[] = $this->finding(
                'complexity_grew_without_maturity_delta',
                self::SEVERITY_WARN,
                'large diff (>500 lines) without measurable maturity delta',
            );
        }

        return $findings;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function scanDocsRuntimeDivergence(array $before, array $after): array
    {
        $findings = [];
        $beforeOversized = (int) data_get($before, 'docs_health.oversized_count', 0);
        $afterOversized = (int) data_get($after, 'docs_health.oversized_count', 0);
        if ($afterOversized > $beforeOversized) {
            $findings[] = $this->finding(
                'docs_health_oversized_count_increased',
                self::SEVERITY_WARN,
                'docs-health oversized_count increased after change',
            );
        }
        $beforeViolations = (int) data_get($before, 'docs_health.violations_count', 0);
        $afterViolations = (int) data_get($after, 'docs_health.violations_count', 0);
        if ($afterViolations > $beforeViolations) {
            $findings[] = $this->finding(
                'docs_health_violations_count_increased',
                self::SEVERITY_SEVERE,
                'docs-health violations increased — runtime/docs divergence',
            );
        }

        return $findings;
    }

    /**
     * L6-7: compare OBSERVED behavior contracts, not test assertions.
     *
     * Expected snapshot shape:
     *   observed_behavior.contracts[] = {
     *     id: string,
     *     observed_value: scalar|array,
     *     covered_by_test: bool,
     *     tolerance_abs?: float,
     *     tolerance_ratio?: float,
     *     source?: string
     *   }
     *
     * Missing observed behavior remains a no-op for backwards compatibility. A
     * changed contract that is not covered by a test becomes a severe finding: this
     * sentinel never mutates anything, but promotion callers must treat it as a block.
     *
     * @return array<string,mixed>
     */
    private function scanObservedBehaviorDrift(array $before, array $after): array
    {
        $beforeContracts = $this->observedBehaviorContracts($before);
        $afterContracts = $this->observedBehaviorContracts($after);
        $findings = [];
        $compared = 0;
        $uncoveredCompared = 0;
        $coveredSkipped = 0;
        $missingAfter = 0;

        foreach ($beforeContracts as $id => $beforeContract) {
            $afterContract = $afterContracts[$id] ?? null;
            $coveredByTest = $this->contractCoveredByTest($beforeContract)
                || ($afterContract !== null && $this->contractCoveredByTest($afterContract));

            if ($coveredByTest) {
                $coveredSkipped++;

                continue;
            }

            $uncoveredCompared++;
            $compared++;

            if ($afterContract === null) {
                $missingAfter++;
                $findings[] = $this->finding(
                    'observed_behavior_contract_missing_after',
                    self::SEVERITY_SEVERE,
                    'observed behavior contract disappeared without test coverage',
                    [
                        'oracle' => 'observed_behavior',
                        'behavior_id' => $id,
                        'covered_by_test' => false,
                    ],
                );

                continue;
            }

            $beforeValue = $this->observedBehaviorValue($beforeContract);
            $afterValue = $this->observedBehaviorValue($afterContract);
            if ($this->observedValuesEquivalent($beforeValue, $afterValue, $beforeContract, $afterContract)) {
                continue;
            }

            $findings[] = $this->finding(
                'observed_behavior_drift_uncovered_by_test',
                self::SEVERITY_SEVERE,
                'observed behavior changed but the contract is not covered by a test-spec',
                [
                    'oracle' => 'observed_behavior',
                    'behavior_id' => $id,
                    'covered_by_test' => false,
                    'source' => $this->stringValue($afterContract['source'] ?? $beforeContract['source'] ?? null),
                    'before_value_hash' => $this->valueHash($beforeValue),
                    'after_value_hash' => $this->valueHash($afterValue),
                    'before_value_preview' => $this->valuePreview($beforeValue),
                    'after_value_preview' => $this->valuePreview($afterValue),
                ],
            );
        }

        $status = match (true) {
            count($beforeContracts) === 0 => 'no_observed_behavior',
            $findings !== [] => 'drift_detected',
            default => 'clear',
        };

        return [
            'schema_version' => 'atlas.self_improvement.observed_behavior_oracle.v1',
            'status' => $status,
            'contract_count_before' => count($beforeContracts),
            'contract_count_after' => count($afterContracts),
            'compared_contracts' => $compared,
            'uncovered_compared_contracts' => $uncoveredCompared,
            'covered_contracts_skipped' => $coveredSkipped,
            'missing_after_count' => $missingAfter,
            'drift_count' => count($findings),
            'finding_ids' => array_values(array_map(
                static fn (array $finding): string => (string) ($finding['finding_id'] ?? ''),
                $findings,
            )),
            'findings' => $findings,
            'read_model_only' => true,
            'external_provider_call' => false,
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function observedBehaviorContracts(array $snapshot): array
    {
        $raw = data_get($snapshot, 'observed_behavior.contracts');
        if (! is_array($raw)) {
            $raw = data_get($snapshot, 'behavior_observations');
        }
        if (! is_array($raw)) {
            return [];
        }

        $contracts = [];
        foreach ($raw as $key => $contract) {
            if (! is_array($contract)) {
                continue;
            }
            $id = $this->stringValue($contract['id'] ?? $contract['behavior_id'] ?? $contract['name'] ?? null);
            if ($id === null && is_string($key) && trim($key) !== '') {
                $id = trim($key);
            }
            if ($id === null) {
                continue;
            }
            $contracts[$id] = $contract;
        }

        ksort($contracts);

        return $contracts;
    }

    /**
     * @param  array<string,mixed>  $contract
     */
    private function contractCoveredByTest(array $contract): bool
    {
        foreach (['covered_by_test', 'covered_by_tests', 'test_covered', 'test_spec_covered', 'covered_by_test_spec'] as $key) {
            if (array_key_exists($key, $contract)) {
                return filter_var($contract[$key], FILTER_VALIDATE_BOOL);
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $contract
     */
    private function observedBehaviorValue(array $contract): mixed
    {
        foreach (['observed_value', 'value', 'output', 'result', 'behavior', 'signature', 'hash'] as $key) {
            if (array_key_exists($key, $contract)) {
                return $contract[$key];
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $beforeContract
     * @param  array<string,mixed>  $afterContract
     */
    private function observedValuesEquivalent(
        mixed $before,
        mixed $after,
        array $beforeContract,
        array $afterContract,
    ): bool {
        if (is_numeric($before) && is_numeric($after)) {
            $beforeFloat = (float) $before;
            $afterFloat = (float) $after;
            $absoluteDelta = abs($afterFloat - $beforeFloat);
            $absoluteTolerance = max(
                0.0,
                (float) ($afterContract['tolerance_abs'] ?? $afterContract['tolerance'] ?? $beforeContract['tolerance_abs'] ?? $beforeContract['tolerance'] ?? 0),
            );
            if ($absoluteDelta <= $absoluteTolerance) {
                return true;
            }

            $ratioTolerance = max(
                0.0,
                (float) ($afterContract['tolerance_ratio'] ?? $beforeContract['tolerance_ratio'] ?? 0),
            );
            if ($ratioTolerance > 0) {
                $denominator = max(1.0, abs($beforeFloat));

                return ($absoluteDelta / $denominator) <= $ratioTolerance;
            }

            return false;
        }

        return hash_equals($this->valueHash($before), $this->valueHash($after));
    }

    private function valueHash(mixed $value): string
    {
        $normalized = $this->normalizeValue($value);
        $json = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return 'sha256:'.hash('sha256', $json === false ? '' : $json);
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $normalized = [];
        foreach ($value as $key => $child) {
            $normalized[$key] = $this->normalizeValue($child);
        }
        if (! array_is_list($normalized)) {
            ksort($normalized);
        }

        return $normalized;
    }

    private function valuePreview(mixed $value): string|int|float|bool|null
    {
        if (is_string($value)) {
            return mb_substr($value, 0, 160);
        }
        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }

        return $this->valueHash($value);
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    /**
     * @return array<string,mixed>
     */
    private function finding(string $id, string $severity, string $message, array $context = []): array
    {
        return array_merge([
            'finding_id' => $id,
            'severity' => $severity,
            'message' => $message,
        ], $context);
    }
}
