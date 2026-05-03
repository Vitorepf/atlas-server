<?php

namespace App\Services\Tools;

use App\Models\AtlasToolRun;
use Illuminate\Support\Collection;

class AtlasToolGateService
{
    public function __construct(
        private readonly AtlasToolEvidenceQueryService $evidence,
        private readonly AtlasToolFindingWaiverService $waivers,
    ) {}

    /**
     * @param  array<string,mixed>  $filters
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function evaluate(array $filters = [], array $options = []): array
    {
        $runs = $this->evidence->recent($filters);
        $failStatuses = $this->stringList($options['fail_statuses'] ?? []);
        if ($failStatuses === []) {
            $failStatuses = ['failed', 'timeout', 'requires_approval', 'denied'];
        }
        $requiredTools = $this->stringList($options['required_tools'] ?? []);
        $requireEvidence = (bool) ($options['require_evidence'] ?? false);
        $waiverAwareFailedRuns = (bool) ($options['waiver_aware_failed_runs'] ?? false);
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
            $blockingFailures = [
                ...$blockingFailures,
                ...$this->blockingFailuresForRun($run, $failStatuses, $waiverAwareFailedRuns),
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

        return [
            'status' => $status,
            'allowed' => $status !== 'blocked',
            'filters' => $this->publicFilters($filters),
            'required_tools' => $requiredTools,
            'fail_statuses' => $failStatuses,
            'summary' => [
                'run_count' => $runs->count(),
                'tool_count' => $runs->pluck('tool_slug')->unique()->count(),
                'failed_run_count' => $runs->whereIn('status', $failStatuses)->count(),
                'blocking_failure_count' => count($blockingFailures),
                'warning_count' => count($warnings),
            ],
            'blocking_failures' => $blockingFailures,
            'warnings' => $warnings,
            'runs' => $runs->map(fn (AtlasToolRun $run): array => $this->runSummary($run))->values()->all(),
        ];
    }

    /**
     * @param  array<int,string>  $failStatuses
     * @return array<int,array<string,mixed>>
     */
    private function blockingFailuresForRun(AtlasToolRun $run, array $failStatuses, bool $waiverAwareFailedRuns = false): array
    {
        $failures = [];
        $storedBlockingFindings = $run->findings->filter(fn ($finding): bool => (bool) $finding->blocks_resolved)->values();
        $blockingFindings = $storedBlockingFindings
            ->reject(fn ($finding): bool => $this->waivers->isWaived($finding))
            ->values();

        if (in_array($run->status, $failStatuses, true)) {
            if (! $this->failedStatusIsCoveredByWaivers($run, $storedBlockingFindings, $blockingFindings, $waiverAwareFailedRuns)) {
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

        foreach ($blockingFindings as $finding) {
            $failures[] = [
                'tool_run_id' => $run->id,
                'tool_slug' => $run->tool_slug,
                'finding_id' => $finding->id,
                'reason' => 'blocking_finding',
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
            'duration_ms' => $run->duration_ms,
            'finished_at' => $run->finished_at,
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

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    private function publicFilters(array $filters): array
    {
        return array_filter($filters, fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }
}
