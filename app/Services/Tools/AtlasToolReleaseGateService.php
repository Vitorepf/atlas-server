<?php

namespace App\Services\Tools;

use App\Models\AtlasToolRun;
use Illuminate\Support\Collection;

class AtlasToolReleaseGateService
{
    public function __construct(
        private readonly AtlasToolEvidenceQueryService $evidence,
        private readonly AtlasToolGateService $gate,
    ) {}

    /**
     * @param  array<string,mixed>  $filters
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function evaluate(array $filters = [], array $options = []): array
    {
        $filters = [
            ...$filters,
            'limit' => max(1, min(200, is_numeric($filters['limit'] ?? null) ? (int) $filters['limit'] : 100)),
        ];
        $gatePayload = $this->gate->evaluate($filters, [
            'require_evidence' => true,
            'fail_statuses' => $options['fail_statuses'] ?? ['failed', 'timeout', 'requires_approval', 'denied'],
            'waiver_aware_failed_runs' => true,
            'max_age_minutes' => $options['max_age_minutes'] ?? 1440,
            'stale_blocks' => array_key_exists('stale_blocks', $options) ? (bool) $options['stale_blocks'] : true,
            'latest_per_tool' => true,
        ]);
        $runs = $this->latestRunsPerTool($this->evidence->recent($filters));
        $releaseFailures = $this->releaseRequirementFailures($runs);
        $blockingFailures = [
            ...((array) ($gatePayload['blocking_failures'] ?? [])),
            ...$releaseFailures,
        ];
        $warnings = (array) ($gatePayload['warnings'] ?? []);
        $status = $blockingFailures !== [] ? 'blocked' : ($warnings !== [] ? 'warning' : 'passed');

        return [
            ...$gatePayload,
            'status' => $status,
            'allowed' => $status !== 'blocked',
            'release_profile' => (string) ($options['release_profile'] ?? 'security_sbom_release'),
            'summary' => [
                ...((array) ($gatePayload['summary'] ?? [])),
                'release_requirement_count' => 4,
                'release_requirement_failure_count' => count($releaseFailures),
                'blocking_failure_count' => count($blockingFailures),
                'warning_count' => count($warnings),
            ],
            'blocking_failures' => $blockingFailures,
            'warnings' => $warnings,
            'release_requirements' => $this->releaseRequirements($runs),
        ];
    }

    /**
     * @param  iterable<int,AtlasToolRun>  $runs
     * @return array<int,array<string,mixed>>
     */
    private function releaseRequirementFailures(iterable $runs): array
    {
        return collect($this->releaseRequirements($runs))
            ->filter(fn (array $requirement): bool => ! (bool) ($requirement['satisfied'] ?? false))
            ->map(fn (array $requirement): array => [
                'reason' => 'release_requirement_missing',
                'requirement' => $requirement['id'],
                'message' => $requirement['message'],
                'tool_slugs' => $requirement['tool_slugs'],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  iterable<int,AtlasToolRun>  $runs
     * @return array<int,array<string,mixed>>
     */
    private function releaseRequirements(iterable $runs): array
    {
        $runs = collect($runs);

        return [
            $this->toolGroupRequirement(
                'secret_scan',
                'Secret scan evidence is required for release gates.',
                ['gitleaks'],
                $runs,
            ),
            $this->toolGroupRequirement(
                'static_security_scan',
                'Static security scan evidence is required for release gates.',
                ['semgrep'],
                $runs,
            ),
            $this->toolGroupRequirement(
                'dependency_vulnerability_scan',
                'Dependency vulnerability scan evidence is required for release gates.',
                ['osv_scanner', 'trivy', 'grype'],
                $runs,
            ),
            [
                ...$this->toolGroupRequirement(
                    'sbom_attached',
                    'SBOM evidence with a normalized artifact summary is required for release gates.',
                    ['syft'],
                    $runs,
                ),
                'satisfied' => $this->hasSbomEvidence($runs),
            ],
        ];
    }

    /**
     * @param  array<int,string>  $toolSlugs
     */
    private function toolGroupRequirement(string $id, string $message, array $toolSlugs, Collection $runs): array
    {
        $matchingRuns = $runs
            ->filter(fn (AtlasToolRun $run): bool => in_array($run->tool_slug, $toolSlugs, true))
            ->values();

        return [
            'id' => $id,
            'message' => $message,
            'tool_slugs' => $toolSlugs,
            'satisfied' => $matchingRuns->contains(fn (AtlasToolRun $run): bool => $run->status !== 'skipped'),
            'run_ids' => $matchingRuns->pluck('id')->values()->all(),
        ];
    }

    private function hasSbomEvidence(Collection $runs): bool
    {
        return $runs
            ->filter(fn (AtlasToolRun $run): bool => $run->tool_slug === 'syft' && $run->status !== 'skipped')
            ->contains(function (AtlasToolRun $run): bool {
                $artifactTypes = collect((array) data_get($run->normalized_result_json, 'artifacts', []))
                    ->pluck('type')
                    ->filter()
                    ->values();

                return $artifactTypes->contains('sbom_summary')
                    || is_numeric(data_get($run->normalized_result_json, 'metrics.package_count'));
            });
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
}
