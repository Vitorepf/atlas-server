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
            ],
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
     * @return array<string,mixed>
     */
    private function finding(string $id, string $severity, string $message): array
    {
        return [
            'finding_id' => $id,
            'severity' => $severity,
            'message' => $message,
        ];
    }
}
