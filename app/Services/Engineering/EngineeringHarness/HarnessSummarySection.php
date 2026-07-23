<?php

namespace App\Services\Engineering\EngineeringHarness;

use App\Models\AiTrace;
use App\Models\AtlasEngineeringControlResult;
use App\Models\AtlasEngineeringPatchArtifact;
use App\Models\AtlasEngineeringRun;
use App\Models\AtlasEngineeringRunAttempt;
use App\Models\AtlasTask;
use App\Services\Ai\Memory\AtlasMemoryRegistryService;
use App\Services\Ai\FairClaudePolicy;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceExecutionGateService;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspacePathResolverService;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Tools\AtlasToolGateService;
use App\Support\AtlasPhpBinary;
use App\Support\AtlasSecurity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use App\Services\Engineering\EngineeringControlRegistryService;
use App\Services\Engineering\EngineeringProviderRuntimeService;
use App\Services\Engineering\EngineeringRunArtifactService;
use App\Services\Engineering\EngineeringWorkspaceService;
use App\Services\Engineering\EngineeringReviewFindingService;
use App\Services\Engineering\EngineeringPatchArtifactService;
use App\Services\Engineering\EngineeringTaskContractService;
use App\Services\Engineering\EngineeringBlueprintService;
use App\Services\Engineering\EngineeringBlueprintSnapshotService;
use App\Services\Engineering\EngineeringHarnessabilityService;
use App\Services\Engineering\EngineeringModelPolicyService;
use App\Services\Engineering\EngineeringContextPackService;
use App\Services\Engineering\EngineeringDockerHarnessService;
use App\Services\Engineering\EngineeringTestMatrixService;
use App\Services\Engineering\EngineeringRunScoringService;
use App\Services\Engineering\EngineeringHarnessRunnerInput;

class HarnessSummarySection
{
    /**
     * @param  array<string,mixed>  $options
     * @param  array<string,mixed>  $strategy
     * @return array{provider:?string,source:string,attempt_id:?string,attempt_number:?int,attempt_score:?int,model:?string}
     */
    public function replayProviderSelection(AtlasEngineeringRun $sourceRun, array $options, array $strategy): array
    {
        $modelOverride = is_string($options['model'] ?? null) && trim((string) $options['model']) !== ''
            ? trim((string) $options['model'])
            : null;
        $useModelPolicy = $this->replayUsesModelPolicy($options);

        if (is_string($options['provider'] ?? null) && trim((string) $options['provider']) !== '') {
            return [
                'provider' => trim((string) $options['provider']),
                'source' => 'operator_override',
                'attempt_id' => null,
                'attempt_number' => null,
                'attempt_score' => null,
                'model' => $modelOverride,
            ];
        }

        if (is_string($options['source_attempt_provider'] ?? null) && trim((string) $options['source_attempt_provider']) !== '') {
            return [
                'provider' => trim((string) $options['source_attempt_provider']),
                'source' => 'source_attempt',
                'attempt_id' => is_string($options['source_attempt_id'] ?? null) ? $options['source_attempt_id'] : null,
                'attempt_number' => is_numeric($options['source_attempt_number'] ?? null) ? (int) $options['source_attempt_number'] : null,
                'attempt_score' => null,
                'model' => $modelOverride ?: ($useModelPolicy ? null : (is_string($options['source_attempt_model'] ?? null) ? $options['source_attempt_model'] : null)),
            ];
        }

        $comparison = $this->attemptComparison($sourceRun);
        $bestAttemptId = is_string($comparison['best_attempt_id'] ?? null) ? $comparison['best_attempt_id'] : null;
        $bestAttempt = $bestAttemptId
            ? $sourceRun->attempts->first(fn (AtlasEngineeringRunAttempt $attempt): bool => $attempt->id === $bestAttemptId)
            : null;
        $bestProvider = $bestAttempt instanceof AtlasEngineeringRunAttempt && is_string($bestAttempt->provider) && trim($bestAttempt->provider) !== ''
            ? trim($bestAttempt->provider)
            : null;

        if ($bestProvider !== null) {
            return [
                'provider' => $bestProvider,
                'source' => 'attempt_comparison',
                'attempt_id' => $bestAttempt->id,
                'attempt_number' => $bestAttempt->attempt_number,
                'attempt_score' => is_numeric($comparison['best_score'] ?? null) ? (int) $comparison['best_score'] : null,
                'model' => $modelOverride ?: ($useModelPolicy ? null : (is_string($bestAttempt->model) ? $bestAttempt->model : null)),
            ];
        }

        $strategyProvider = data_get($strategy, 'provider');
        $strategyModel = data_get($strategy, 'model');

        return [
            'provider' => is_string($strategyProvider) && trim($strategyProvider) !== '' ? trim($strategyProvider) : null,
            'source' => 'source_strategy',
            'attempt_id' => null,
            'attempt_number' => null,
            'attempt_score' => null,
            'model' => $modelOverride ?: ($useModelPolicy ? null : (is_string($strategyModel) && trim($strategyModel) !== '' ? trim($strategyModel) : null)),
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     */
    public function replayUsesModelPolicy(array $options): bool
    {
        $policy = is_string($options['model_policy'] ?? null)
            ? strtolower(str_replace('-', '_', trim((string) $options['model_policy'])))
            : 'fixed';

        return ! in_array($policy, ['', 'fixed', 'off'], true);
    }

    /**
     * @return array<string,mixed>
     */
    public function patchArtifactIntegrity(AtlasEngineeringPatchArtifact $patch): array
    {
        $path = is_string($patch->diff_path) ? trim($patch->diff_path) : '';
        if ($path === '') {
            return [
                'checked' => true,
                'exists' => false,
                'hash_matches' => $patch->diff_hash === null,
                'reason' => $patch->diff_hash === null ? 'empty_diff' : 'diff_path_missing',
            ];
        }

        if (! File::exists($path)) {
            return [
                'checked' => true,
                'exists' => false,
                'hash_matches' => false,
                'reason' => 'diff_path_not_found',
            ];
        }

        $actualHash = hash('sha256', File::get($path));

        return [
            'checked' => true,
            'exists' => true,
            'hash_matches' => $patch->diff_hash === null || hash_equals((string) $patch->diff_hash, $actualHash),
            'sha256' => $actualHash,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function attemptComparison(AtlasEngineeringRun $run): array
    {
        $attemptCount = $run->attempts->count();
        if ($attemptCount === 0) {
            return [
                'status' => 'empty',
                'best_attempt_id' => null,
                'best_attempt_number' => null,
                'best_score' => null,
                'best_recommendation' => null,
                'attempts' => [],
            ];
        }

        $ranked = $run->attempts
            ->map(fn (AtlasEngineeringRunAttempt $attempt): array => $this->attemptComparisonRow($run, $attempt, $attemptCount))
            ->sort(fn (array $left, array $right): int => ($right['score'] <=> $left['score']) ?: ($left['attempt_number'] <=> $right['attempt_number']))
            ->values()
            ->map(fn (array $row, int $index): array => array_merge($row, ['rank' => $index + 1]))
            ->values();

        $best = $ranked->first();

        return [
            'status' => $attemptCount === 1 ? 'single_attempt' : 'ranked',
            'best_attempt_id' => $best['attempt_id'] ?? null,
            'best_attempt_number' => $best['attempt_number'] ?? null,
            'best_score' => $best['score'] ?? null,
            'best_recommendation' => $best['recommendation'] ?? null,
            'attempts' => $ranked->all(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function attemptComparisonRow(AtlasEngineeringRun $run, AtlasEngineeringRunAttempt $attempt, int $attemptCount): array
    {
        $patches = $this->recordsForAttempt($run->patchArtifacts, $attempt, $attemptCount);
        $tests = $this->recordsForAttempt($run->testRuns, $attempt, $attemptCount);
        $controls = $this->recordsForAttempt($run->controlResults, $attempt, $attemptCount);
        $findings = $this->recordsForAttempt($run->reviewFindings, $attempt, $attemptCount);

        $passedTests = $tests->where('status', 'passed')->count();
        $failedTests = $tests->filter(fn ($test): bool => in_array((string) $test->status, ['failed', 'blocked', 'timed_out'], true))->count();
        $failedControls = $controls->filter(fn ($control): bool => in_array((string) $control->status, ['failed', 'blocked'], true))->count();
        $openFindings = $findings->where('status', 'open');
        $blockingFindings = $openFindings->whereIn('severity', ['p0', 'p1'])->count();
        $riskFlags = $patches
            ->flatMap(fn ($patch): array => (array) ($patch->risk_flags_json ?? []))
            ->filter()
            ->unique()
            ->values();
        $changedFiles = collect((array) ($attempt->changed_files_json ?? []))
            ->merge($patches->flatMap(fn ($patch): array => (array) ($patch->changed_files_json ?? [])))
            ->filter()
            ->unique()
            ->values();

        $score = 50;
        $score += match ((string) $attempt->status) {
            'completed', 'passed', 'resolved' => 20,
            'failed' => -20,
            'timed_out' => -25,
            'cancelled' => -30,
            default => 0,
        };
        $score += min(20, $passedTests * 8);
        $score += $patches->isNotEmpty() ? 10 : 0;
        $score += $changedFiles->isNotEmpty() ? 5 : 0;
        $score -= min(30, $failedTests * 15);
        $score -= min(20, $failedControls * 10);
        $score -= min(30, $blockingFindings * 20);
        $score -= min(20, $riskFlags->count() * 5);
        $score -= is_string($attempt->failure_summary) && trim($attempt->failure_summary) !== '' ? 10 : 0;
        $score = max(0, min(100, $score));

        $recommendation = match (true) {
            $score >= 85 && $failedTests === 0 && $failedControls === 0 && $blockingFindings === 0 => 'best_repair_base',
            $score >= 70 => 'review_before_replay',
            default => 'avoid_replay_base',
        };

        return [
            'attempt_id' => $attempt->id,
            'attempt_number' => $attempt->attempt_number,
            'provider' => $attempt->provider,
            'model' => $attempt->model,
            'phase' => $attempt->phase,
            'status' => $attempt->status,
            'score' => $score,
            'recommendation' => $recommendation,
            'patch_hash' => $attempt->patch_hash,
            'changed_files_count' => $changedFiles->count(),
            'patch_count' => $patches->count(),
            'passed_tests' => $passedTests,
            'failed_tests' => $failedTests,
            'failed_controls' => $failedControls,
            'open_findings' => $openFindings->count(),
            'blocking_findings' => $blockingFindings,
            'risk_flags' => $riskFlags->all(),
            'signals' => $this->attemptComparisonSignals(
                $attempt,
                $patches->count(),
                $changedFiles->count(),
                $passedTests,
                $failedTests,
                $failedControls,
                $blockingFindings,
                $riskFlags->count(),
            ),
        ];
    }

    public function recordsForAttempt($records, AtlasEngineeringRunAttempt $attempt, int $attemptCount)
    {
        return $records->filter(function ($record) use ($attempt, $attemptCount): bool {
            $recordAttemptId = $record->attempt_id ?? null;

            return (string) $recordAttemptId === (string) $attempt->id
                || ($attemptCount === 1 && ($recordAttemptId === null || $recordAttemptId === ''));
        })->values();
    }

    /**
     * @return array<int,string>
     */
    public function attemptComparisonSignals(
        AtlasEngineeringRunAttempt $attempt,
        int $patchCount,
        int $changedFilesCount,
        int $passedTests,
        int $failedTests,
        int $failedControls,
        int $blockingFindings,
        int $riskFlagCount,
    ): array {
        return collect([
            in_array((string) $attempt->status, ['completed', 'passed', 'resolved'], true) ? 'attempt_completed' : 'attempt_not_completed',
            $patchCount > 0 ? 'patch_captured' : 'no_patch_artifact',
            $changedFilesCount > 0 ? 'changed_files_present' : 'no_changed_files',
            $passedTests > 0 ? 'tests_passed' : null,
            $failedTests > 0 ? 'tests_failed' : null,
            $failedControls > 0 ? 'controls_failed' : null,
            $blockingFindings > 0 ? 'blocking_findings_open' : null,
            $riskFlagCount > 0 ? 'risk_flags_present' : null,
            is_string($attempt->failure_summary) && trim($attempt->failure_summary) !== '' ? 'failure_summary_present' : null,
        ])->filter()->values()->all();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function timeline(AtlasEngineeringRun $run): array
    {
        $events = collect();

        foreach ($run->attempts as $attempt) {
            $events->push([
                'type' => 'attempt',
                'status' => $attempt->status,
                'label' => 'Attempt #'.$attempt->attempt_number.' '.$attempt->phase,
                'at' => $attempt->finished_at?->toJSON() ?: $attempt->created_at?->toJSON(),
                'ref_id' => $attempt->id,
            ]);
        }

        foreach ($run->testRuns as $testRun) {
            $events->push([
                'type' => 'test',
                'status' => $testRun->status,
                'label' => $testRun->command,
                'at' => $testRun->created_at?->toJSON(),
                'ref_id' => $testRun->id,
            ]);
        }

        foreach ($run->controlResults as $result) {
            $events->push([
                'type' => 'control',
                'status' => $result->status,
                'label' => $result->control_slug,
                'at' => $result->created_at?->toJSON(),
                'ref_id' => $result->id,
            ]);
        }

        foreach ($run->reviewFindings as $finding) {
            $events->push([
                'type' => 'review_finding',
                'status' => $finding->status,
                'label' => strtoupper((string) $finding->severity).': '.$finding->title,
                'at' => $finding->created_at?->toJSON(),
                'ref_id' => $finding->id,
            ]);
        }

        foreach ($run->operatorActions as $action) {
            $events->push([
                'type' => 'operator_action',
                'status' => $action->status_after,
                'label' => 'Operator '.$action->action,
                'at' => $action->acted_at?->toJSON() ?: $action->created_at?->toJSON(),
                'ref_id' => $action->id,
            ]);
        }

        return $events
            ->sortBy('at')
            ->values()
            ->all();
    }
}
