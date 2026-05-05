<?php

namespace App\Services\Tools;

use App\Models\AtlasToolRun;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Collection;

class AtlasToolGateService
{
    public function __construct(
        private readonly AtlasToolEvidenceQueryService $evidence,
        private readonly AtlasToolFindingWaiverService $waivers,
        private readonly AtlasToolFindingCorrelationService $correlations,
        private readonly AtlasToolAuthorityPolicyService $authorityPolicies,
        private readonly AtlasEvidenceLedger $ledger,
    ) {}

    /**
     * @param  array<string,mixed>  $filters
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function evaluate(array $filters = [], array $options = []): array
    {
        $allRuns = $this->evidence->recent($filters);
        $latestPerTool = (bool) ($options['latest_per_tool'] ?? false);
        $runs = $latestPerTool ? $this->latestRunsPerTool($allRuns) : $allRuns;
        $failStatuses = $this->stringList($options['fail_statuses'] ?? []);
        if ($failStatuses === []) {
            $failStatuses = ['failed', 'timeout', 'requires_approval', 'denied'];
        }
        $requiredTools = $this->stringList($options['required_tools'] ?? []);
        $requireEvidence = (bool) ($options['require_evidence'] ?? false);
        $waiverAwareFailedRuns = (bool) ($options['waiver_aware_failed_runs'] ?? false);
        $maxAgeMinutes = $this->positiveInteger($options['max_age_minutes'] ?? null);
        $staleBlocks = (bool) ($options['stale_blocks'] ?? false);
        $correlationResult = $this->correlations->correlate($runs);
        $suppressedFindingIds = (array) ($correlationResult['suppressed_finding_ids'] ?? []);
        $blockingFailures = [];
        $warnings = [];

        if ($runs->isEmpty()) {
            $message = 'No tool evidence matched the gate filters.';
            if ($requireEvidence || $requiredTools !== []) {
                $blockingFailures[] = ['reason' => 'missing_evidence', 'message' => $message];
            } else {
                $warnings[] = ['reason' => 'missing_evidence', 'message' => $message];
            }
        }

        foreach (array_diff($requiredTools, $runs->pluck('tool_slug')->unique()->values()->all()) as $toolSlug) {
            $blockingFailures[] = [
                'tool_slug' => $toolSlug,
                'reason' => 'required_tool_missing',
                'message' => "Required tool [{$toolSlug}] has no matching evidence.",
            ];
        }

        foreach ($runs as $run) {
            $staleIssue = $this->staleEvidenceIssue($run, $maxAgeMinutes, $staleBlocks);
            if ($staleIssue !== null) {
                if ($staleBlocks) {
                    $blockingFailures[] = $staleIssue;
                } else {
                    $warnings[] = $staleIssue;
                }
            }

            if ($this->hasNonBlockingRecipeFailure($run, $failStatuses)) {
                $warnings[] = [
                    'tool_run_id' => $run->id,
                    'tool_slug' => $run->tool_slug,
                    'reason' => 'non_blocking_recipe_failed',
                    'message' => "Tool [{$run->tool_slug}] recipe [".data_get($run->metadata_json, 'recipe').'] ended with status ['.$run->status.'] but is not blocking-capable.',
                ];
            }

            $blockingFailures = [
                ...$blockingFailures,
                ...$this->blockingFailuresForRun($run, $failStatuses, $waiverAwareFailedRuns, $suppressedFindingIds),
            ];
            $warnings = [
                ...$warnings,
                ...$this->authorityWarningsForRun($run, $suppressedFindingIds),
            ];

            if ($run->status === 'skipped') {
                $warnings[] = [
                    'tool_run_id' => $run->id,
                    'tool_slug' => $run->tool_slug,
                    'reason' => 'tool_skipped',
                    'message' => "Tool [{$run->tool_slug}] was skipped.",
                ];
            }
        }

        $status = $blockingFailures !== [] ? 'blocked' : ($warnings !== [] ? 'warning' : 'passed');

        $result = [
            'status' => $status,
            'allowed' => $status !== 'blocked',
            'filters' => $this->publicFilters($filters),
            'required_tools' => $requiredTools,
            'fail_statuses' => $failStatuses,
            'summary' => [
                'input_run_count' => $allRuns->count(),
                'run_count' => $runs->count(),
                'tool_count' => $runs->pluck('tool_slug')->unique()->count(),
                'failed_run_count' => $runs->whereIn('status', $failStatuses)->count(),
                'blocking_failure_count' => count($blockingFailures),
                'warning_count' => count($warnings),
                'stale_evidence_count' => $maxAgeMinutes ? $runs->filter(fn (AtlasToolRun $run): bool => $this->evidenceAgeMinutes($run) > $maxAgeMinutes)->count() : 0,
                'correlated_finding_group_count' => count((array) ($correlationResult['correlations'] ?? [])),
                'suppressed_duplicate_finding_count' => count($suppressedFindingIds),
            ],
            'freshness' => [
                'max_age_minutes' => $maxAgeMinutes,
                'stale_blocks' => $staleBlocks,
            ],
            'selection' => [
                'latest_per_tool' => $latestPerTool,
            ],
            'blocking_failures' => $blockingFailures,
            'warnings' => $warnings,
            'finding_correlations' => $correlationResult['correlations'] ?? [],
            'runs' => $runs->map(fn (AtlasToolRun $run): array => $this->runSummary($run))->values()->all(),
        ];

        $this->recordLedgerGate($result, $filters, $options);

        return $result;
    }

    /**
     * @param  array<string,mixed>  $result
     * @param  array<string,mixed>  $filters
     * @param  array<string,mixed>  $options
     */
    private function recordLedgerGate(array $result, array $filters, array $options): void
    {
        $envelopeId = (string) (
            $options['envelope_id']
            ?? $filters['envelope_id']
            ?? (isset($filters['run_context_type'], $filters['run_context_id']) ? "{$filters['run_context_type']}:{$filters['run_context_id']}" : 'tool_gate:'.hash('sha256', json_encode([$filters, $options], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}'))
        );
        $eventType = ($result['status'] ?? null) === 'passed'
            ? LedgerEventType::GatePassed
            : (($result['status'] ?? null) === 'blocked' ? LedgerEventType::GateBlocked : LedgerEventType::GateEvaluated);

        try {
            $this->ledger->record($eventType, [
                'envelope_id' => $envelopeId,
                'gate_type' => 'tool_runtime',
                'status' => $result['status'] ?? null,
                'allowed' => $result['allowed'] ?? null,
                'summary' => $result['summary'] ?? [],
                'required_tools' => $result['required_tools'] ?? [],
                'fail_statuses' => $result['fail_statuses'] ?? [],
                'freshness' => $result['freshness'] ?? [],
                'selection' => $result['selection'] ?? [],
                'blocking_failure_count' => count((array) ($result['blocking_failures'] ?? [])),
                'warning_count' => count((array) ($result['warnings'] ?? [])),
                'run_ids' => collect((array) ($result['runs'] ?? []))->pluck('id')->filter()->values()->all(),
            ], [
                'tenant_id' => (string) ($options['tenant_id'] ?? 'default'),
                'operator_id' => (string) ($options['operator_id'] ?? 'system'),
                'envelope_id' => $envelopeId,
                'receipt_id' => is_string($options['receipt_id'] ?? null) ? $options['receipt_id'] : null,
                'trace_id' => is_string($options['trace_id'] ?? null) ? $options['trace_id'] : null,
                'correlation_id' => (string) ($options['correlation_id'] ?? $envelopeId),
                'emitter_stage' => 'atlas.tools.gate',
                'emitter_version' => 'tool-gate-v1',
            ]);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @param  array<int,string>  $failStatuses
     * @return array<int,array<string,mixed>>
     */
    private function blockingFailuresForRun(AtlasToolRun $run, array $failStatuses, bool $waiverAwareFailedRuns = false, array $suppressedFindingIds = []): array
    {
        $failures = [];
        $storedBlockingFindings = $run->findings->filter(fn ($finding): bool => (bool) $finding->blocks_resolved)->values();
        $openFindings = $run->findings
            ->reject(fn ($finding): bool => $this->waivers->isWaived($finding))
            ->values();
        $blockingFindings = $storedBlockingFindings
            ->reject(fn ($finding): bool => $this->waivers->isWaived($finding))
            ->values();

        if (in_array($run->status, $failStatuses, true)) {
            if (
                ! $this->hasNonBlockingRecipeFailure($run, $failStatuses)
                && ! $this->failedStatusIsCoveredByWaivers($run, $storedBlockingFindings, $blockingFindings, $waiverAwareFailedRuns)
            ) {
                $failures[] = [
                    'tool_run_id' => $run->id,
                    'tool_slug' => $run->tool_slug,
                    'reason' => 'tool_status_failed',
                    'message' => "Tool [{$run->tool_slug}] ended with status [{$run->status}].",
                ];
            }
        }

        if (in_array($run->policy_decision, ['denied', 'requires_approval'], true)) {
            $failures[] = [
                'tool_run_id' => $run->id,
                'tool_slug' => $run->tool_slug,
                'reason' => 'policy_not_allowed',
                'message' => "Tool [{$run->tool_slug}] policy decision was [{$run->policy_decision}].",
            ];
        }

        foreach ($openFindings as $finding) {
            if (in_array((string) $finding->id, $suppressedFindingIds, true)) {
                continue;
            }

            $policy = $this->authorityPolicies->evaluateFinding($run, $finding);
            if (! (bool) $finding->blocks_resolved && $policy['decision'] !== 'block') {
                continue;
            }

            $failures[] = [
                'tool_run_id' => $run->id,
                'tool_slug' => $run->tool_slug,
                'finding_id' => $finding->id,
                'authority_group' => $policy['authority_group'],
                'authority_policy' => $policy['policy'],
                'severity' => $policy['severity'],
                'reason' => $policy['reason'] ?? 'blocking_finding',
                'message' => $finding->title,
            ];
        }

        foreach ((array) data_get($run->normalized_result_json, 'blocking_failures', []) as $failure) {
            if ($this->duplicatesStoredFinding($failure, $storedBlockingFindings)) {
                continue;
            }

            $failures[] = [
                'tool_run_id' => $run->id,
                'tool_slug' => $run->tool_slug,
                'reason' => 'normalized_blocking_failure',
                'message' => is_scalar($failure) ? (string) $failure : json_encode($failure, JSON_UNESCAPED_SLASHES),
            ];
        }

        return $failures;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function authorityWarningsForRun(AtlasToolRun $run, array $suppressedFindingIds = []): array
    {
        return $run->findings
            ->reject(fn ($finding): bool => $this->waivers->isWaived($finding))
            ->reject(fn ($finding): bool => in_array((string) $finding->id, $suppressedFindingIds, true))
            ->map(function ($finding) use ($run): ?array {
                $policy = $this->authorityPolicies->evaluateFinding($run, $finding);
                if ($policy['decision'] !== 'warn') {
                    return null;
                }

                return [
                    'tool_run_id' => $run->id,
                    'tool_slug' => $run->tool_slug,
                    'finding_id' => $finding->id,
                    'authority_group' => $policy['authority_group'],
                    'authority_policy' => $policy['policy'],
                    'severity' => $policy['severity'],
                    'reason' => $policy['reason'],
                    'message' => $finding->title,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Diagnostic recipes and other explicitly non-blocking recipes should not
     * fail gates just because the command failed. Policy denials and blocking
     * findings are handled separately and still block.
     *
     * @param  array<int,string>  $failStatuses
     */
    private function hasNonBlockingRecipeFailure(AtlasToolRun $run, array $failStatuses): bool
    {
        if (! in_array($run->status, $failStatuses, true)) {
            return false;
        }

        if (! is_string(data_get($run->metadata_json, 'recipe')) || data_get($run->metadata_json, 'recipe') === '') {
            return false;
        }

        return data_get($run->metadata_json, 'recipe_blocking_capable') === false;
    }

    private function failedStatusIsCoveredByWaivers(AtlasToolRun $run, Collection $storedBlockingFindings, Collection $blockingFindings, bool $enabled): bool
    {
        if (! $enabled || $run->status !== 'failed' || $storedBlockingFindings->isEmpty() || $blockingFindings->isNotEmpty()) {
            return false;
        }

        foreach ((array) data_get($run->normalized_result_json, 'blocking_failures', []) as $failure) {
            if (! $this->duplicatesStoredFinding($failure, $storedBlockingFindings)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Normalized blocking failures are often the same items persisted as findings.
     * Keep explicit normalized failures, but avoid reporting the same finding twice.
     */
    private function duplicatesStoredFinding(mixed $failure, Collection $blockingFindings): bool
    {
        if (! is_array($failure)) {
            return false;
        }

        $ruleId = data_get($failure, 'rule_id');
        $title = data_get($failure, 'title');
        $message = data_get($failure, 'message');

        return $blockingFindings->contains(function ($finding) use ($ruleId, $title, $message): bool {
            if (is_string($ruleId) && $ruleId !== '' && $finding->rule_id === $ruleId) {
                return true;
            }

            if (is_string($title) && $title !== '' && $finding->title === $title) {
                return true;
            }

            return is_string($message) && $message !== '' && $finding->message === $message;
        });
    }

    /**
     * @return array<string,mixed>
     */
    private function runSummary(AtlasToolRun $run): array
    {
        return [
            'id' => $run->id,
            'tool_slug' => $run->tool_slug,
            'surface' => $run->surface,
            'status' => $run->status,
            'required' => $run->required,
            'failure_policy' => $run->failure_policy,
            'policy_decision' => $run->policy_decision,
            'run_context_type' => $run->run_context_type,
            'run_context_id' => $run->run_context_id,
            'recipe' => data_get($run->metadata_json, 'recipe'),
            'recipe_category' => data_get($run->metadata_json, 'recipe_category'),
            'recipe_recommended_surface' => data_get($run->metadata_json, 'recipe_recommended_surface'),
            'recipe_creates_evidence' => data_get($run->metadata_json, 'recipe_creates_evidence'),
            'recipe_blocking_capable' => data_get($run->metadata_json, 'recipe_blocking_capable'),
            'execution_origin' => data_get($run->metadata_json, 'execution_origin'),
            'duration_ms' => $run->duration_ms,
            'finished_at' => $run->finished_at,
            'evidence_age_minutes' => $this->evidenceAgeMinutes($run),
            'finding_count' => $run->findings->count(),
            'blocking_finding_count' => $run->findings
                ->where('blocks_resolved', true)
                ->reject(fn ($finding): bool => $this->waivers->isWaived($finding))
                ->count(),
            'waived_finding_count' => $run->findings
                ->filter(fn ($finding): bool => $this->waivers->isWaived($finding))
                ->count(),
            'artifact_count' => $run->artifacts->count(),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function stringList(mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];

        return collect($values)
            ->flatMap(fn (mixed $item): array => is_string($item) ? explode(',', $item) : [])
            ->map(fn (string $item): string => trim($item))
            ->filter(fn (string $item): bool => $item !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function staleEvidenceIssue(AtlasToolRun $run, ?int $maxAgeMinutes, bool $blocks): ?array
    {
        if (! $maxAgeMinutes) {
            return null;
        }

        $ageMinutes = $this->evidenceAgeMinutes($run);
        if ($ageMinutes <= $maxAgeMinutes) {
            return null;
        }

        return [
            'tool_run_id' => $run->id,
            'tool_slug' => $run->tool_slug,
            'reason' => $blocks ? 'stale_evidence_blocks' : 'stale_evidence',
            'message' => "Tool [{$run->tool_slug}] evidence is stale: {$ageMinutes} minutes old, max {$maxAgeMinutes}.",
            'evidence_age_minutes' => $ageMinutes,
            'max_age_minutes' => $maxAgeMinutes,
        ];
    }

    private function evidenceAgeMinutes(AtlasToolRun $run): int
    {
        $timestamp = $run->finished_at ?? $run->created_at;
        if (! $timestamp) {
            return 0;
        }

        return max(0, (int) floor($timestamp->diffInMinutes(now())));
    }

    /**
     * @param  Collection<int,AtlasToolRun>  $runs
     * @return Collection<int,AtlasToolRun>
     */
    private function latestRunsPerTool(Collection $runs): Collection
    {
        return $runs
            ->sortByDesc(fn (AtlasToolRun $run): string => ($run->finished_at ?? $run->created_at)?->toJSON() ?? '')
            ->unique('tool_slug')
            ->values();
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    private function publicFilters(array $filters): array
    {
        return array_filter($filters, fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }
}
