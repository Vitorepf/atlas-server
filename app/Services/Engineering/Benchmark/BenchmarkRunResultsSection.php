<?php

namespace App\Services\Engineering\Benchmark;

use App\Models\AiTraceMetricSummary;
use App\Models\AtlasEngineeringBenchmarkCase;
use App\Models\AtlasEngineeringBenchmarkResult;
use App\Models\AtlasEngineeringBenchmarkRun;
use App\Models\AtlasEngineeringBenchmarkSuite;
use App\Models\AtlasEngineeringRun;
use App\Models\AtlasTask;
use App\Services\Ai\FairClaudePolicy;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;
use App\Services\Engineering\EngineeringHarnessRunnerService;
use App\Services\Engineering\EngineeringClaudeCodeBaselineRunnerService;
use App\Services\Engineering\EngineeringWorkspaceService;
use App\Services\Engineering\EngineeringReleaseGateAlertService;
use App\Services\Engineering\EngineeringBenchmarkInput;
use App\Services\Engineering\EngineeringStringListNormalizer;

class BenchmarkRunResultsSection
{
    public function __construct(
        private readonly BenchmarkPrimitives $primitives,
        private readonly EngineeringHarnessRunnerService $runner,
    ) {}

    public function evaluate(AtlasEngineeringBenchmarkCase $case, ?string $decision, ?int $score, ?array $fairScorecard = null): array
    {
        $expectedDecision = $case->expected_decision ?: 'resolved';
        $minScore = (int) ($case->min_score ?? 85);
        $failures = [];

        if ($decision !== $expectedDecision) {
            $failures[] = "decision esperado={$expectedDecision}, observado=".($decision ?: 'null');
        }

        if ($score === null || $score < $minScore) {
            $failures[] = "score minimo={$minScore}, observado=".($score === null ? 'null' : (string) $score);
        }

        if (is_array($fairScorecard) && (bool) ($fairScorecard['required'] ?? false) && ! (bool) ($fairScorecard['passed'] ?? false)) {
            $reasons = implode(', ', array_map('strval', (array) ($fairScorecard['blocking_reasons'] ?? [])));
            $failures[] = 'fair_scorecard_failed'.($reasons !== '' ? ': '.$reasons : '');
        }

        return [
            'passed' => $failures === [],
            'failure_summary' => $failures === [] ? null : implode('; ', $failures),
        ];
    }

    public function fairScorecard(array $payload, array $runnerOptions): ?array
    {
        $fairMode = (bool) ($runnerOptions['fair_mode'] ?? false)
            || (bool) ($runnerOptions['claude_only'] ?? false)
            || (bool) data_get($payload, 'run.fair_mode.fair_mode')
            || (bool) data_get($payload, 'run.fair_mode_result');

        if (! $fairMode) {
            return null;
        }

        $fairResult = is_array(data_get($payload, 'run.fair_mode_result'))
            ? data_get($payload, 'run.fair_mode_result')
            : [];
        $provider = data_get($payload, 'run.model_selection.selected_provider')
            ?: data_get($payload, 'run.attempts.0.provider');
        $model = data_get($payload, 'run.model_selection.selected_model')
            ?: data_get($payload, 'run.attempts.0.model');
        $required = (bool) ($runnerOptions['require_pass_without_human'] ?? true);
        $providerLocked = $provider === 'claude_cli';
        $modelLocked = $this->fairClaudeModelLocked($model);
        $reportedPassWithoutHuman = (bool) ($fairResult['pass_without_human'] ?? false);
        $humanInterventionCount = max(0, (int) ($fairResult['human_intervention_count'] ?? 0));
        $deterministicGatesPassed = (bool) ($fairResult['deterministic_gates_passed'] ?? false);
        $protocolValid = (string) ($fairResult['status'] ?? 'unverified') === 'valid';
        $harnessStatus = (string) data_get($payload, 'run.status', 'unknown');
        $harnessDecision = (string) data_get($payload, 'run.decision', 'unknown');
        $harnessGatesPassed = $harnessStatus === 'passed' && $harnessDecision === 'resolved';
        $attemptCount = (int) (data_get($payload, 'run.attempt_count') ?: count((array) data_get($payload, 'run.attempts', [])));
        $attemptCount = max(0, $attemptCount);
        $repairAttemptCount = max(0, $attemptCount - 1);
        $scopeSafety = $this->fairScopeSafety($payload);
        $passWithoutHuman = $reportedPassWithoutHuman && $deterministicGatesPassed && $harnessGatesPassed && $scopeSafety['verified'];
        $blockingReasons = [];

        if (! $providerLocked) {
            $blockingReasons[] = 'provider_not_locked_to_claude_cli';
        }
        if (! $modelLocked) {
            $blockingReasons[] = 'model_not_locked_to_opus';
        }
        if (! $protocolValid) {
            $blockingReasons[] = 'fair_protocol_not_valid';
        }
        if (! $deterministicGatesPassed) {
            $blockingReasons[] = 'deterministic_gates_not_passed';
        }
        if (! $harnessGatesPassed) {
            $blockingReasons[] = 'harness_gates_not_passed';
        }
        if (! $passWithoutHuman) {
            $blockingReasons[] = 'pass_without_human_false';
        }
        foreach ($scopeSafety['blocking_reasons'] as $reason) {
            $blockingReasons[] = $reason;
        }

        return [
            'required' => $required,
            'passed' => $providerLocked && $modelLocked && $protocolValid && $deterministicGatesPassed && $harnessGatesPassed && $passWithoutHuman,
            'fair_mode' => true,
            'provider_lock' => $provider,
            'model_lock' => $model,
            'protocol_status' => $fairResult['status'] ?? 'unverified',
            'protocol_valid' => $protocolValid,
            'deterministic_gates_passed' => $deterministicGatesPassed,
            'harness_gates_passed' => $harnessGatesPassed,
            'harness_status' => $harnessStatus,
            'harness_decision' => $harnessDecision,
            'final_gate_passed' => $deterministicGatesPassed && $harnessGatesPassed,
            'human_intervention_count' => $humanInterventionCount,
            'pass_without_human' => $passWithoutHuman,
            'pass_without_human_reported' => $reportedPassWithoutHuman,
            'provider_violation_count' => $providerLocked ? 0 : 1,
            'fallback_violation_count' => 0,
            'attempt_count' => $attemptCount,
            'repair_attempt_count' => $repairAttemptCount,
            'repair_used' => $repairAttemptCount > 0,
            'converted_to_green' => $repairAttemptCount > 0 && $protocolValid && $deterministicGatesPassed && $passWithoutHuman,
            'scope_safety' => $scopeSafety['summary'],
            'blocking_reasons' => $this->primitives->uniqueReasonStrings(
                $blockingReasons,
                (array) ($fairResult['blocking_reasons'] ?? []),
            ),
        ];
    }

    public function fairScopeSafety(array $payload): array
    {
        $isolatedPatchApply = data_get($payload, 'run.scope_safety');
        if (! is_array($isolatedPatchApply) || $isolatedPatchApply === []) {
            return [
                'verified' => false,
                'summary' => [
                    'status' => 'missing',
                    'reason' => 'isolated_patch_apply_missing',
                    'dirty_overlap' => [],
                    'untracked_overlap' => [],
                    'modified_overlap' => [],
                    'untracked_files' => [],
                    'profile_status' => null,
                ],
                'blocking_reasons' => ['scope_safety_unverified'],
            ];
        }

        $status = (string) ($isolatedPatchApply['status'] ?? '');
        $reason = $this->primitives->nonEmptyString($isolatedPatchApply['reason'] ?? null);
        $profile = is_array($isolatedPatchApply['scope_safety'] ?? null) ? $isolatedPatchApply['scope_safety'] : [];
        $profileSafe = (bool) ($profile['safe'] ?? true);
        $dirtyOverlap = array_values((array) ($isolatedPatchApply['dirty_overlap'] ?? $profile['dirty_overlap'] ?? []));
        $untrackedOverlap = array_values((array) ($isolatedPatchApply['untracked_overlap'] ?? $profile['untracked_overlap'] ?? []));
        $modifiedOverlap = array_values((array) ($profile['modified_overlap'] ?? array_values(array_diff($dirtyOverlap, $untrackedOverlap))));
        $untrackedFiles = array_values((array) ($profile['untracked_files'] ?? []));

        $summary = [
            'status' => $status,
            'reason' => $reason,
            'dirty_overlap' => $dirtyOverlap,
            'untracked_overlap' => $untrackedOverlap,
            'modified_overlap' => $modifiedOverlap,
            'untracked_files' => $untrackedFiles,
            'profile_status' => $profile['status'] ?? null,
        ];

        $verified = match ($status) {
            'applied' => true,
            'no_patch' => true,
            'not_applicable' => $profileSafe,
            default => false,
        };

        $blocking = [];
        if ($dirtyOverlap !== []) {
            $blocking[] = 'dirty_state_overlap';
        }
        if ($status === 'blocked' && $reason === 'possible_secret_in_diff') {
            $blocking[] = 'possible_secret_in_diff';
        }
        if ($status === 'failed') {
            $blocking[] = 'isolated_patch_apply_failed';
        }
        if (! $verified) {
            $blocking[] = 'scope_safety_unverified';
        }

        return [
            'verified' => $verified,
            'summary' => $summary,
            'blocking_reasons' => $this->primitives->uniqueReasonStrings($blocking),
        ];
    }

    public function fairClaudeModelLocked(mixed $model): bool
    {
        if (! is_string($model) || trim($model) === '') {
            return false;
        }

        $model = strtolower(trim($model));
        $configured = strtolower(trim((string) config('atlas.ai.providers.claude_cli.premium_model', '')));

        return $model === FairClaudePolicy::MODEL_LOCK
            || ($configured !== '' && $model === $configured);
    }

    public function pairedScorecard(
        AtlasEngineeringBenchmarkCase $case,
        array $atlasEvaluation,
        ?array $fairScorecard,
        ?array $claudeCodeBaseline,
        ?string $atlasDecision,
        ?int $atlasScore,
    ): ?array {
        if (! is_array($claudeCodeBaseline) || ! (bool) ($claudeCodeBaseline['enabled'] ?? false)) {
            return null;
        }

        $expectedDecision = $case->expected_decision ?: 'resolved';
        $minScore = (int) ($case->min_score ?? 85);
        $baselineStatus = (string) ($claudeCodeBaseline['status'] ?? 'unknown');
        $baselineExecuted = (bool) ($claudeCodeBaseline['executed'] ?? false);
        $baselineDecision = $this->primitives->nonEmptyString($claudeCodeBaseline['decision'] ?? null);
        $baselineScore = is_numeric($claudeCodeBaseline['score'] ?? null) ? (int) $claudeCodeBaseline['score'] : null;
        $baselineDeterministic = (bool) ($claudeCodeBaseline['deterministic_gates_passed'] ?? false);
        $baselinePassWithoutHuman = (bool) ($claudeCodeBaseline['pass_without_human'] ?? false);
        $baselineVerified = $baselineExecuted
            && $baselineStatus === 'completed'
            && $baselineDeterministic
            && $baselinePassWithoutHuman;
        $atlasProtocolInvalidReasons = $this->atlasFairProtocolInvalidReasons($fairScorecard);
        $atlasProtocolValid = $atlasProtocolInvalidReasons === [];

        $atlasPassed = (bool) ($atlasEvaluation['passed'] ?? false);
        $atlasFairPassed = $fairScorecard === null || ! (bool) ($fairScorecard['required'] ?? false) || (bool) ($fairScorecard['passed'] ?? false);
        $atlasVerified = $atlasProtocolValid && $atlasPassed && $atlasFairPassed;
        $baselinePassed = $baselineVerified
            && $baselineDecision === $expectedDecision
            && $baselineScore !== null
            && $baselineScore >= $minScore;

        $comparisonStatus = match (true) {
            ! $atlasProtocolValid => 'atlas_protocol_invalid',
            $baselineStatus === 'planned' => 'baseline_planned',
            ! $baselineExecuted => 'baseline_not_executed',
            $baselineStatus !== 'completed' => 'baseline_failed',
            ! $baselineDeterministic => 'baseline_unverified',
            ! $baselinePassWithoutHuman => 'baseline_human_intervention',
            $baselineDecision !== $expectedDecision => 'comparable',
            $baselineScore === null || $baselineScore < $minScore => 'comparable',
            default => 'comparable',
        };
        $comparable = $comparisonStatus === 'comparable';
        $winner = null;

        if ($comparable) {
            $winner = match (true) {
                $atlasVerified && ! $baselinePassed => 'atlas',
                ! $atlasVerified && $baselinePassed => 'claude_code_baseline',
                $atlasVerified && $baselinePassed && $atlasScore !== null && $baselineScore !== null && $atlasScore > $baselineScore => 'atlas',
                $atlasVerified && $baselinePassed && $atlasScore !== null && $baselineScore !== null && $atlasScore < $baselineScore => 'claude_code_baseline',
                $atlasVerified === $baselinePassed => 'tie',
                default => null,
            };
        }

        return [
            'schema_version' => 1,
            'fair_mode' => $fairScorecard !== null,
            'case' => [
                'id' => $case->id,
                'case_code' => $case->case_code,
                'corpus_tier' => $case->corpus_tier,
                'domain_slug' => $case->domain_slug,
                'risk_profile' => $case->risk_profile,
            ],
            'comparison_status' => $comparisonStatus,
            'comparable' => $comparable,
            'winner' => $winner,
            'atlas' => [
                'provider' => 'atlas',
                'decision' => $atlasDecision,
                'score' => $atlasScore,
                'passed' => $atlasPassed,
                'verified' => $atlasVerified,
                'fair_scorecard_passed' => $fairScorecard === null ? null : (bool) ($fairScorecard['passed'] ?? false),
                'protocol_valid' => $fairScorecard === null ? null : (bool) ($fairScorecard['protocol_valid'] ?? false),
                'final_gate_passed' => $fairScorecard === null ? null : (bool) ($fairScorecard['final_gate_passed'] ?? false),
                'pass_without_human' => $fairScorecard === null ? null : (bool) ($fairScorecard['pass_without_human'] ?? $fairScorecard['passed'] ?? false),
                'human_intervention_count' => $fairScorecard === null ? null : (int) ($fairScorecard['human_intervention_count'] ?? 0),
                'provider_violation_count' => $fairScorecard === null ? 0 : (int) ($fairScorecard['provider_violation_count'] ?? 0),
                'fallback_violation_count' => $fairScorecard === null ? 0 : (int) ($fairScorecard['fallback_violation_count'] ?? 0),
                'attempt_count' => $fairScorecard === null ? null : (int) ($fairScorecard['attempt_count'] ?? 0),
                'repair_attempt_count' => $fairScorecard === null ? null : (int) ($fairScorecard['repair_attempt_count'] ?? 0),
                'repair_used' => $fairScorecard === null ? null : (bool) ($fairScorecard['repair_used'] ?? false),
                'converted_to_green' => $fairScorecard === null ? null : (bool) ($fairScorecard['converted_to_green'] ?? false),
            ],
            'claude_code_baseline' => [
                'provider' => $claudeCodeBaseline['provider'] ?? null,
                'model' => $claudeCodeBaseline['model'] ?? null,
                'status' => $baselineStatus,
                'executed' => $baselineExecuted,
                'decision' => $baselineDecision,
                'score' => $baselineScore,
                'deterministic_gates_passed' => $baselineDeterministic,
                'pass_without_human' => $baselinePassWithoutHuman,
                'verified' => $baselineVerified,
                'passed' => $baselinePassed,
                'duration_ms' => is_numeric($claudeCodeBaseline['duration_ms'] ?? null) ? (int) $claudeCodeBaseline['duration_ms'] : null,
            ],
            'deltas' => [
                'score' => $atlasScore !== null && $baselineScore !== null ? $atlasScore - $baselineScore : null,
            ],
            'blocking_reasons' => $this->pairedScorecardBlockingReasons(
                $comparisonStatus,
                $atlasVerified,
                $baselineVerified,
                $claudeCodeBaseline,
                $atlasProtocolInvalidReasons,
            ),
        ];
    }

    public function pairedScorecardBlockingReasons(
        string $comparisonStatus,
        bool $atlasVerified,
        bool $baselineVerified,
        array $claudeCodeBaseline,
        array $atlasProtocolInvalidReasons = [],
    ): array {
        $reasons = [];
        if ($comparisonStatus !== 'comparable') {
            $reasons[] = $comparisonStatus;
        }
        if (! $atlasVerified) {
            $reasons[] = 'atlas_not_verified_pass';
        }
        if (! $baselineVerified) {
            $reasons[] = 'baseline_not_verified_pass';
        }

        return $this->primitives->uniqueReasonStrings(
            $reasons,
            $atlasProtocolInvalidReasons,
            (array) ($claudeCodeBaseline['blocking_reasons'] ?? []),
        );
    }

    public function atlasFairProtocolInvalidReasons(?array $fairScorecard): array
    {
        if ($fairScorecard === null || ! (bool) ($fairScorecard['required'] ?? false)) {
            return [];
        }

        $reasons = [];
        $protocolValid = (bool) data_get($fairScorecard, 'protocol_valid', data_get($fairScorecard, 'passed', false));
        if (! $protocolValid) {
            $reasons[] = 'fair_protocol_not_valid';
        }
        if ((int) data_get($fairScorecard, 'provider_violation_count', 0) > 0) {
            $reasons[] = 'provider_lock_violation';
        }
        if ((int) data_get($fairScorecard, 'fallback_violation_count', 0) > 0) {
            $reasons[] = 'fallback_violation';
        }

        $contaminationReasons = [
            'dirty_state_overlap',
            'scope_safety_unverified',
            'possible_secret_in_diff',
            'isolated_patch_apply_failed',
        ];
        foreach ((array) data_get($fairScorecard, 'blocking_reasons', []) as $reason) {
            if (is_string($reason) && in_array($reason, $contaminationReasons, true)) {
                $reasons[] = $reason;
            }
        }

        return $this->primitives->uniqueReasonStrings($reasons);
    }

    public function replayManifestPayload(AtlasEngineeringBenchmarkRun $run): array
    {
        $summary = $this->withReplayManifestArtifactVerification($this->primitives->arrayValue($run->summary_json ?? []));
        $manifest = $this->primitives->arrayValue(data_get($summary, 'replay_manifest', []));
        $artifact = $this->primitives->arrayValue(data_get($manifest, 'artifact', []));
        $safeArtifact = $this->safeReplayManifestArtifact($artifact);

        if (! (bool) ($manifest['enabled'] ?? false)) {
            return [
                'status' => 'unavailable',
                'reason' => 'replay_manifest_disabled',
                'artifact' => $safeArtifact,
                'summary' => Arr::except($manifest, ['artifact', 'packets']),
                'final_packet' => data_get($manifest, 'final_packet'),
                'replay_manifest' => null,
            ];
        }

        $integrity = $this->primitives->arrayValue(data_get($artifact, 'integrity', []));
        if (($artifact['status'] ?? null) !== 'persisted' || ! (bool) ($integrity['hash_matches'] ?? false)) {
            return [
                'status' => 'unavailable',
                'reason' => (string) ($integrity['reason'] ?? 'artifact_integrity_failed'),
                'artifact' => $safeArtifact,
                'summary' => Arr::except($manifest, ['artifact', 'packets']),
                'final_packet' => data_get($manifest, 'final_packet'),
                'replay_manifest' => null,
            ];
        }

        $path = $this->resolveReplayManifestArtifactPath($this->primitives->nonEmptyString($artifact['path'] ?? null));
        if ($path === null || ! File::isFile($path)) {
            return [
                'status' => 'unavailable',
                'reason' => 'artifact_not_readable',
                'artifact' => $safeArtifact,
                'summary' => Arr::except($manifest, ['artifact', 'packets']),
                'final_packet' => data_get($manifest, 'final_packet'),
                'replay_manifest' => null,
            ];
        }

        $decoded = json_decode(File::get($path), true);
        if (! is_array($decoded)) {
            return [
                'status' => 'unavailable',
                'reason' => 'artifact_json_decode_failed',
                'artifact' => $safeArtifact,
                'summary' => Arr::except($manifest, ['artifact', 'packets']),
                'final_packet' => data_get($manifest, 'final_packet'),
                'replay_manifest' => null,
            ];
        }

        return [
            'status' => 'available',
            'artifact' => $safeArtifact,
            'summary' => Arr::except($manifest, ['artifact', 'packets']),
            'final_packet' => data_get($decoded, 'final_packet', data_get($manifest, 'final_packet')),
            'replay_manifest' => $decoded,
        ];
    }

    public function resultPayload(AtlasEngineeringBenchmarkResult $result): array
    {
        return [
            'id' => $result->id,
            'benchmark_run_id' => $result->benchmark_run_id,
            'case_id' => $result->case_id,
            'case_code' => $result->benchmarkCase?->case_code,
            'engineering_run_id' => $result->engineering_run_id,
            'task_id' => $result->task_id,
            'status' => $result->status,
            'passed' => $result->passed,
            'decision' => $result->decision,
            'score' => $result->score,
            'duration_ms' => $result->duration_ms,
            'expectation' => $result->expectation_json,
            'observed' => $result->observed_json,
            'failure_summary' => $result->failure_summary,
            'engineering_run' => $result->engineeringRun
                ? $this->runner->runSummary($result->engineeringRun)
                : null,
            'created_at' => $result->created_at?->toJSON(),
        ];
    }

    public function claudeCodeBaselineSummary(Collection $results): array
    {
        $baselines = $results
            ->map(fn (AtlasEngineeringBenchmarkResult $result): mixed => data_get($result->observed_json ?? [], 'claude_code_baseline'))
            ->filter(fn (mixed $baseline): bool => is_array($baseline) && (bool) ($baseline['enabled'] ?? false))
            ->values();

        if ($baselines->isEmpty()) {
            return [
                'enabled' => false,
                'case_count' => 0,
            ];
        }

        return [
            'enabled' => true,
            'case_count' => $baselines->count(),
            'planned_count' => $baselines->where('status', 'planned')->count(),
            'executed_count' => $baselines->where('executed', true)->count(),
            'completed_count' => $baselines->where('status', 'completed')->count(),
            'failed_count' => $baselines->where('status', 'failed')->count(),
            'providers' => $baselines->pluck('provider')->filter()->unique()->values()->all(),
            'models' => $baselines->pluck('model')->filter()->unique()->values()->all(),
            'prompt_hashes' => $baselines->pluck('prompt_hash')->filter()->unique()->values()->all(),
        ];
    }

    public function pairedScorecardSummary(Collection $results, ?int $costMicrousd = null): array
    {
        $scorecards = $results
            ->map(function (AtlasEngineeringBenchmarkResult $result): mixed {
                $scorecard = data_get($result->observed_json ?? [], 'paired_scorecard');
                if (! is_array($scorecard)) {
                    return $scorecard;
                }
                if (! is_numeric(data_get($scorecard, 'atlas.duration_ms')) && is_numeric($result->duration_ms)) {
                    data_set($scorecard, 'atlas.duration_ms', (int) $result->duration_ms);
                }

                return $this->normalizePairedScorecardForReport($scorecard);
            })
            ->filter(fn (mixed $scorecard): bool => is_array($scorecard))
            ->values();

        if ($scorecards->isEmpty()) {
            return [
                'enabled' => false,
                'case_count' => 0,
                'protocol_validity_rate' => null,
                'pass_without_human_rate' => null,
                'pass_without_human_rate_medium_hard' => null,
                'repair_conversion_rate' => null,
                'final_gate_pass_rate' => null,
                'intervention_reduction' => null,
                'autonomous_success_lift' => null,
                'time_to_green' => null,
                'cost_per_green_case' => null,
                'invalid_case_count' => 0,
                'provider_violation_count' => 0,
                'fallback_violation_count' => 0,
            ];
        }

        $comparable = $scorecards->where('comparable', true);
        $caseCount = $scorecards->count();
        $atlasPassWithoutHumanCount = $scorecards
            ->filter(fn (array $scorecard): bool => (bool) data_get($scorecard, 'atlas.verified')
                && (bool) data_get($scorecard, 'atlas.pass_without_human'))
            ->count();
        $baselinePassWithoutHumanCount = $scorecards
            ->filter(fn (array $scorecard): bool => (bool) data_get($scorecard, 'claude_code_baseline.verified')
                && (bool) data_get($scorecard, 'claude_code_baseline.pass_without_human'))
            ->count();
        $atlasHumanInterventions = $scorecards
            ->sum(fn (array $scorecard): int => max(0, (int) data_get($scorecard, 'atlas.human_intervention_count', 0)));
        $baselineHumanInterventions = $scorecards
            ->sum(fn (array $scorecard): int => max(0, (int) data_get($scorecard, 'claude_code_baseline.human_intervention_count', 0)));
        $repairUsed = $scorecards
            ->filter(fn (array $scorecard): bool => (bool) data_get($scorecard, 'atlas.repair_used', ((int) data_get($scorecard, 'atlas.repair_attempt_count', 0)) > 0))
            ->values();
        $repairConvertedCount = $repairUsed
            ->filter(fn (array $scorecard): bool => (bool) data_get($scorecard, 'atlas.converted_to_green'))
            ->count();
        $atlasGreenDurations = $scorecards
            ->filter(fn (array $scorecard): bool => (bool) data_get($scorecard, 'atlas.verified')
                && (bool) data_get($scorecard, 'atlas.pass_without_human')
                && is_numeric(data_get($scorecard, 'atlas.duration_ms')))
            ->map(fn (array $scorecard): int => (int) data_get($scorecard, 'atlas.duration_ms'))
            ->values();
        $baselineGreenDurations = $scorecards
            ->filter(fn (array $scorecard): bool => (bool) data_get($scorecard, 'claude_code_baseline.verified')
                && (bool) data_get($scorecard, 'claude_code_baseline.pass_without_human')
                && is_numeric(data_get($scorecard, 'claude_code_baseline.duration_ms')))
            ->map(fn (array $scorecard): int => (int) data_get($scorecard, 'claude_code_baseline.duration_ms'))
            ->values();
        $winners = $scorecards
            ->pluck('winner')
            ->filter(fn (mixed $winner): bool => is_string($winner) && $winner !== '')
            ->countBy()
            ->all();

        return [
            'enabled' => true,
            'case_count' => $scorecards->count(),
            'fair_mode_count' => $scorecards
                ->filter(fn (array $scorecard): bool => (bool) ($scorecard['fair_mode'] ?? false))
                ->count(),
            'comparable_count' => $comparable->count(),
            'inconclusive_count' => max(0, $scorecards->count() - $comparable->count()),
            'comparison_statuses' => $scorecards
                ->pluck('comparison_status')
                ->filter()
                ->countBy()
                ->all(),
            'winners' => $winners,
            'atlas_win_count' => (int) ($winners['atlas'] ?? 0),
            'claude_code_baseline_win_count' => (int) ($winners['claude_code_baseline'] ?? 0),
            'tie_count' => (int) ($winners['tie'] ?? 0),
            'protocol_validity_rate' => $this->primitives->rate($scorecards
                ->filter(fn (array $scorecard): bool => (bool) data_get($scorecard, 'atlas.protocol_valid', data_get($scorecard, 'atlas.verified')))
                ->count(), $caseCount),
            'pass_without_human_rate' => $this->primitives->rate($atlasPassWithoutHumanCount, $caseCount),
            'pass_without_human_rate_medium_hard' => $this->primitives->rate($scorecards
                ->filter(fn (array $scorecard): bool => in_array(data_get($scorecard, 'case.risk_profile'), ['medium', 'high', 'critical'], true)
                    && (bool) data_get($scorecard, 'atlas.verified')
                    && (bool) data_get($scorecard, 'atlas.pass_without_human'))
                ->count(), max(0, $scorecards
                ->filter(fn (array $scorecard): bool => in_array(data_get($scorecard, 'case.risk_profile'), ['medium', 'high', 'critical'], true))
                ->count())),
            'repair_conversion_rate' => $this->primitives->rate($repairConvertedCount, $repairUsed->count()),
            'final_gate_pass_rate' => $this->primitives->rate($scorecards
                ->filter(fn (array $scorecard): bool => (bool) data_get($scorecard, 'atlas.verified')
                    && (bool) data_get($scorecard, 'atlas.final_gate_passed'))
                ->count(), $caseCount),
            'intervention_reduction' => $atlasHumanInterventions > 0
                ? round($baselineHumanInterventions / $atlasHumanInterventions, 2)
                : ($baselineHumanInterventions > 0 ? null : 1.0),
            'autonomous_success_lift' => round(
                ($this->primitives->rate($atlasPassWithoutHumanCount, $caseCount) ?? 0.0)
                - ($this->primitives->rate($baselinePassWithoutHumanCount, $caseCount) ?? 0.0),
                2,
            ),
            'time_to_green' => [
                'atlas_avg_ms' => $atlasGreenDurations->isNotEmpty() ? (int) round($atlasGreenDurations->avg()) : null,
                'claude_code_baseline_avg_ms' => $baselineGreenDurations->isNotEmpty() ? (int) round($baselineGreenDurations->avg()) : null,
                'atlas_green_case_count' => $atlasGreenDurations->count(),
                'claude_code_baseline_green_case_count' => $baselineGreenDurations->count(),
            ],
            'cost_per_green_case' => $costMicrousd !== null && $costMicrousd > 0 && $atlasPassWithoutHumanCount > 0
                ? [
                    'microusd' => (int) round($costMicrousd / $atlasPassWithoutHumanCount),
                    'usd' => round(($costMicrousd / $atlasPassWithoutHumanCount) / 1_000_000, 6),
                    'green_case_count' => $atlasPassWithoutHumanCount,
                    'source_cost_microusd' => $costMicrousd,
                ]
                : null,
            'invalid_case_count' => $scorecards
                ->filter(fn (array $scorecard): bool => (bool) data_get($scorecard, 'fair_mode')
                    && ! (bool) data_get($scorecard, 'atlas.protocol_valid', data_get($scorecard, 'atlas.verified')))
                ->count(),
            'provider_violation_count' => $scorecards
                ->sum(fn (array $scorecard): int => max(0, (int) data_get($scorecard, 'atlas.provider_violation_count', 0))),
            'fallback_violation_count' => $scorecards
                ->sum(fn (array $scorecard): int => max(0, (int) data_get($scorecard, 'atlas.fallback_violation_count', 0))),
            'atlas_pass_without_human_rate' => $this->primitives->rate($atlasPassWithoutHumanCount, $caseCount),
            'baseline_pass_without_human_rate' => $this->primitives->rate($baselinePassWithoutHumanCount, $caseCount),
        ];
    }

    public function normalizePairedScorecardForReport(array $scorecard): array
    {
        $invalidReasons = $this->pairedScorecardProtocolInvalidReasons($scorecard);
        if ($invalidReasons === []) {
            return $scorecard;
        }

        $scorecard['comparison_status'] = 'atlas_protocol_invalid';
        $scorecard['comparable'] = false;
        $scorecard['winner'] = null;
        data_set($scorecard, 'atlas.verified', false);
        $scorecard['blocking_reasons'] = $this->primitives->uniqueReasonStrings(
            ['atlas_protocol_invalid'],
            $invalidReasons,
            (array) ($scorecard['blocking_reasons'] ?? []),
        );

        return $scorecard;
    }

    public function pairedScorecardProtocolInvalidReasons(array $scorecard): array
    {
        if (! (bool) ($scorecard['fair_mode'] ?? false)) {
            return [];
        }

        $reasons = [];
        if (! (bool) data_get($scorecard, 'atlas.protocol_valid', data_get($scorecard, 'atlas.verified', false))) {
            $reasons[] = 'fair_protocol_not_valid';
        }
        if ((int) data_get($scorecard, 'atlas.provider_violation_count', 0) > 0) {
            $reasons[] = 'provider_lock_violation';
        }
        if ((int) data_get($scorecard, 'atlas.fallback_violation_count', 0) > 0) {
            $reasons[] = 'fallback_violation';
        }

        $contaminationReasons = [
            'dirty_state_overlap',
            'scope_safety_unverified',
            'possible_secret_in_diff',
            'isolated_patch_apply_failed',
        ];
        foreach ((array) ($scorecard['blocking_reasons'] ?? []) as $reason) {
            if (is_string($reason) && in_array($reason, $contaminationReasons, true)) {
                $reasons[] = $reason;
            }
        }

        return $this->primitives->uniqueReasonStrings($reasons);
    }

    public function safeReplayManifestSummaryForReport(array $manifest): array
    {
        $artifact = $this->primitives->arrayValue($manifest['artifact'] ?? []);
        $safe = Arr::except($manifest, ['packets', 'artifact']);
        if ($artifact !== []) {
            $safe['artifact'] = $this->safeReplayManifestArtifact($artifact);
        }

        return $safe;
    }

    public function replayManifestSummary(
        AtlasEngineeringBenchmarkRun $run,
        Collection $results,
        string $status,
        array $quality,
        array $releaseGate,
    ): array {
        $packets = $results
            ->map(function (AtlasEngineeringBenchmarkResult $result): ?array {
                $packet = data_get($result->observed_json ?? [], 'claude_code_baseline.replay_packet');
                if (! is_array($packet)) {
                    return null;
                }

                return [
                    'case_id' => $result->case_id,
                    'case_code' => data_get($packet, 'case_code'),
                    'provider_lock' => data_get($packet, 'provider_lock'),
                    'model_lock' => data_get($packet, 'model_lock'),
                    'mode' => data_get($packet, 'mode'),
                    'workspace_hash' => data_get($packet, 'workspace_hash'),
                    'prompt_hash' => data_get($packet, 'input.prompt_hash'),
                    'task_contract_hash' => data_get($packet, 'input.task_contract_hash'),
                    'command_hash' => data_get($packet, 'invocation.command_hash'),
                    'deterministic_gate_command_hash' => data_get($packet, 'deterministic_gate.command_hash'),
                    'deterministic_gate_command_present' => (bool) data_get($packet, 'deterministic_gate.command_present'),
                    'packet_hash' => hash('sha256', $this->primitives->canonicalJsonForHash($this->primitives->hashableReplayPacket($packet))),
                ];
            })
            ->filter()
            ->values();

        if ($packets->isEmpty()) {
            $manifest = [
                'enabled' => false,
                'packet_count' => 0,
            ];

            return array_merge($manifest, [
                'final_packet' => $this->benchmarkFinalPacket($run, $results, $status, $quality, $releaseGate, $manifest),
            ]);
        }

        $packetHashes = $packets
            ->pluck('packet_hash')
            ->filter()
            ->values()
            ->all();
        $sortedPacketHashes = $packetHashes;
        sort($sortedPacketHashes);

        $manifest = [
            'enabled' => true,
            'schema_version' => 1,
            'kind' => 'engineering_benchmark_replay_manifest',
            'packet_count' => $packets->count(),
            'providers' => $packets->pluck('provider_lock')->filter()->unique()->values()->all(),
            'model_locks' => $packets->pluck('model_lock')->filter()->unique()->values()->all(),
            'modes' => $packets->pluck('mode')->filter()->countBy()->all(),
            'deterministic_gate_packet_count' => $packets
                ->filter(fn (array $packet): bool => (bool) ($packet['deterministic_gate_command_present'] ?? false))
                ->count(),
            'packet_hashes' => $packetHashes,
            'manifest_hash' => hash('sha256', $this->primitives->canonicalJsonForHash($sortedPacketHashes)),
            'packets' => $packets->all(),
        ];

        $finalPacket = $this->benchmarkFinalPacket($run, $results, $status, $quality, $releaseGate, $manifest);

        return array_merge($manifest, [
            'final_packet' => array_merge($finalPacket, [
                'final_packet_hash' => hash('sha256', $this->primitives->canonicalJsonForHash(
                    Arr::except($finalPacket, ['generated_at']),
                )),
            ]),
        ]);
    }

    public function benchmarkFinalPacket(
        AtlasEngineeringBenchmarkRun $run,
        Collection $results,
        string $status,
        array $quality,
        array $releaseGate,
        array $manifest,
    ): array {
        $scorecards = $results
            ->map(fn (AtlasEngineeringBenchmarkResult $result): mixed => data_get($result->observed_json ?? [], 'paired_scorecard'))
            ->filter(fn (mixed $scorecard): bool => is_array($scorecard))
            ->values();
        $fairScorecards = $scorecards
            ->filter(fn (array $scorecard): bool => (bool) ($scorecard['fair_mode'] ?? false))
            ->values();
        $fairMode = $fairScorecards->isNotEmpty();
        $protocolValid = $fairMode
            ? $fairScorecards->every(fn (array $scorecard): bool => (bool) data_get($scorecard, 'atlas.protocol_valid', data_get($scorecard, 'atlas.verified')))
            : null;
        $humanInterventionCount = $scorecards
            ->sum(fn (array $scorecard): int => max(0, (int) data_get($scorecard, 'atlas.human_intervention_count', 0))
                + max(0, (int) data_get($scorecard, 'claude_code_baseline.human_intervention_count', 0)));
        $pairedSummary = $this->pairedScorecardSummary($results, (int) ($quality['cost_microusd'] ?? 0));
        $packetCount = (int) ($manifest['packet_count'] ?? 0);
        $deterministicGatePacketCount = (int) ($manifest['deterministic_gate_packet_count'] ?? 0);
        $providerViolationCount = (int) ($pairedSummary['provider_violation_count'] ?? 0);
        $fallbackViolationCount = (int) ($pairedSummary['fallback_violation_count'] ?? 0);
        $invalidCaseCount = (int) ($pairedSummary['invalid_case_count'] ?? 0);
        $atlasVerifiedCount = $fairScorecards
            ->filter(fn (array $scorecard): bool => (bool) data_get($scorecard, 'atlas.verified')
                && (bool) data_get($scorecard, 'atlas.pass_without_human'))
            ->count();
        $failedTestCount = (int) ($quality['failed_test_count'] ?? 0);
        $failedControlCount = (int) ($quality['failed_control_count'] ?? 0);
        $releaseGateStatus = $this->primitives->nonEmptyString($releaseGate['status'] ?? null);

        $invalidReasons = [];
        if ($protocolValid === false) {
            $invalidReasons[] = 'fair_protocol_invalid';
        }
        if ($providerViolationCount > 0) {
            $invalidReasons[] = 'provider_lock_violation';
        }
        if ($fallbackViolationCount > 0) {
            $invalidReasons[] = 'fallback_violation';
        }

        $unverifiedReasons = [];
        if ($packetCount > 0 && $deterministicGatePacketCount < $packetCount) {
            $unverifiedReasons[] = 'deterministic_gate_packet_missing';
        }
        if ($fairMode && $atlasVerifiedCount < $fairScorecards->count()) {
            $unverifiedReasons[] = 'pass_without_human_unverified';
        }

        $failedReasons = [];
        if ($failedTestCount > 0) {
            $failedReasons[] = 'failed_tests_present';
        }
        if ($failedControlCount > 0) {
            $failedReasons[] = 'failed_controls_present';
        }
        if ($releaseGateStatus === 'failed') {
            $failedReasons[] = 'release_gate_failed';
        }

        $finalStatus = match (true) {
            $invalidReasons !== [] => 'invalid',
            $status === 'failed' || $failedReasons !== [] => 'failed',
            $unverifiedReasons !== [] => 'unverified',
            $status === 'passed' => 'passed',
            default => $status,
        };
        $evaluatorVerified = $finalStatus === 'passed';
        $decisionReasons = $this->primitives->uniqueReasonStrings(
            $invalidReasons,
            $failedReasons,
            $unverifiedReasons,
        );

        $skips = [];
        if ($packetCount > 0 && $deterministicGatePacketCount < $packetCount) {
            $skips[] = [
                'reason' => 'deterministic_gate_packet_missing',
                'packet_count' => $packetCount,
                'deterministic_gate_packet_count' => $deterministicGatePacketCount,
            ];
        }

        $selfAssessment = [
            'authoritative' => false,
            'note' => 'Self-reported flags from provider stdout never promote status to passed; Atlas-deterministic gates are authoritative.',
            'protocol_valid' => $protocolValid,
            'fair_scorecard_count' => $fairScorecards->count(),
            'invalid_case_count' => $invalidCaseCount,
            'pass_without_human_reported_count' => $fairScorecards
                ->filter(fn (array $scorecard): bool => (bool) data_get($scorecard, 'atlas.pass_without_human_reported', data_get($scorecard, 'atlas.pass_without_human')))
                ->count(),
        ];

        return [
            'schema_version' => 1,
            'kind' => 'engineering_benchmark_final_packet',
            'evaluator' => 'atlas_deterministic',
            'evaluator_verified' => $evaluatorVerified,
            'evaluation_inputs' => [
                'paired_scorecard',
                'fair_scorecard',
                'release_gate',
                'quality_metrics',
            ],
            'status' => $finalStatus,
            'benchmark_status' => $status,
            'protocol_valid' => $protocolValid,
            'fair_mode' => $fairMode,
            'provider_lock' => $fairMode ? FairClaudePolicy::PROVIDER_LOCK : null,
            'model_lock' => $fairMode ? FairClaudePolicy::MODEL_LOCK : null,
            'attempts' => (int) ($quality['total_attempts'] ?? 0),
            'repair_conversion_rate' => $pairedSummary['repair_conversion_rate'] ?? null,
            'time_to_green' => $pairedSummary['time_to_green'] ?? null,
            'human_intervention_count' => $humanInterventionCount,
            'files_changed_count' => (int) ($quality['changed_files_count'] ?? 0),
            'diff_hash' => null,
            'replay_manifest_hash' => $manifest['manifest_hash'] ?? null,
            'tests' => [
                'failed_test_count' => $failedTestCount,
                'failed_tests' => $quality['failed_tests'] ?? [],
            ],
            'gates' => [
                'release_gate_status' => $releaseGate['status'] ?? null,
                'release_gate_profile' => $releaseGate['profile'] ?? null,
                'deterministic_gate_packet_count' => $deterministicGatePacketCount,
                'deterministic_gate_packet_total' => $packetCount,
                'failed_control_count' => $failedControlCount,
                'blocked_control_count' => (int) ($quality['blocked_control_count'] ?? 0),
                'release_gate_failures' => $releaseGate['failures'] ?? [],
                'fair_scorecard_count' => $fairScorecards->count(),
                'fair_scorecard_atlas_verified_count' => $atlasVerifiedCount,
                'provider_violation_count' => $providerViolationCount,
                'fallback_violation_count' => $fallbackViolationCount,
            ],
            'skips' => $skips,
            'risks' => [
                'risk_flag_count' => (int) ($quality['risk_flag_count'] ?? 0),
                'risk_flags' => $quality['risk_flags'] ?? [],
                'release_gate_warnings' => $releaseGate['warnings'] ?? [],
            ],
            'decision_reasons' => $decisionReasons,
            'self_assessment' => $selfAssessment,
            'replay_command' => 'atlas benchmark claude-fair replay '.$run->id.' --json',
            'rollback_command' => null,
            'trace_id' => data_get($quality, 'telemetry_trace_ids.0'),
            'trace_ids' => $quality['telemetry_trace_ids'] ?? [],
            'generated_at' => now()->toJSON(),
        ];
    }

    public function persistReplayManifestArtifact(AtlasEngineeringBenchmarkRun $run, array $manifest): array
    {
        if (! (bool) ($manifest['enabled'] ?? false)) {
            return $manifest;
        }

        $directory = storage_path('app/engineering-benchmark-runs/'.$run->id);
        $path = $directory.'/replay-manifest.json';
        $payload = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (! is_string($payload) || $payload === '') {
            return array_merge($manifest, [
                'artifact' => [
                    'status' => 'failed',
                    'reason' => 'manifest_json_encode_failed',
                ],
            ]);
        }

        File::ensureDirectoryExists($directory);
        File::put($path, $payload."\n");

        return array_merge($manifest, [
            'artifact' => [
                'status' => File::isFile($path) ? 'persisted' : 'failed',
                'path' => $path,
                'path_hash' => hash('sha256', $path),
                'bytes' => File::isFile($path) ? File::size($path) : null,
                'sha256' => File::isFile($path) ? hash('sha256', File::get($path)) : null,
                'written_at' => now()->toJSON(),
            ],
        ]);
    }

    public function withReplayManifestArtifactVerification(array $summary): array
    {
        $artifact = data_get($summary, 'replay_manifest.artifact');
        if (! is_array($artifact)) {
            return $summary;
        }

        $path = $this->primitives->nonEmptyString($artifact['path'] ?? null);
        $expectedSha = $this->primitives->nonEmptyString($artifact['sha256'] ?? null);
        if ($path === null) {
            data_set($summary, 'replay_manifest.artifact.integrity', [
                'checked' => true,
                'exists' => false,
                'hash_matches' => false,
                'reason' => 'artifact_path_missing',
                'checked_at' => now()->toJSON(),
            ]);

            return $summary;
        }

        $resolvedPath = $this->resolveReplayManifestArtifactPath($path);

        if ($resolvedPath === null) {
            data_set($summary, 'replay_manifest.artifact.integrity', [
                'checked' => true,
                'exists' => realpath($path) !== false,
                'hash_matches' => false,
                'reason' => $this->replayManifestArtifactPathFailureReason($path),
                'checked_at' => now()->toJSON(),
            ]);

            return $summary;
        }

        $actualSha = File::isFile($resolvedPath) ? hash('sha256', File::get($resolvedPath)) : null;
        data_set($summary, 'replay_manifest.artifact.integrity', [
            'checked' => true,
            'exists' => $actualSha !== null,
            'hash_matches' => $expectedSha !== null && hash_equals($expectedSha, (string) $actualSha),
            'expected_sha256' => $expectedSha,
            'actual_sha256' => $actualSha,
            'checked_at' => now()->toJSON(),
        ]);

        return $summary;
    }

    public function resolveReplayManifestArtifactPath(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        $allowedRoot = realpath(storage_path('app/engineering-benchmark-runs'));
        $resolvedPath = realpath($path);
        if ($allowedRoot === false || $resolvedPath === false) {
            return null;
        }

        $allowedPrefix = rtrim($allowedRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($resolvedPath, $allowedPrefix) ? $resolvedPath : null;
    }

    public function replayManifestArtifactPathFailureReason(string $path): string
    {
        $allowedRoot = realpath(storage_path('app/engineering-benchmark-runs'));
        if ($allowedRoot === false) {
            return 'artifact_allowed_root_missing';
        }

        $resolvedPath = realpath($path);
        if ($resolvedPath === false) {
            return 'artifact_missing';
        }

        return 'artifact_path_outside_allowed_root';
    }

    public function safeReplayManifestArtifact(array $artifact): array
    {
        return Arr::except($artifact, ['path']);
    }

    public function safeReplayManifestSummaryForApi(array $summary): array
    {
        $manifest = $this->primitives->arrayValue(data_get($summary, 'replay_manifest'));
        if ($manifest === []) {
            return $summary;
        }

        $artifact = $this->primitives->arrayValue($manifest['artifact'] ?? []);
        if ($artifact !== []) {
            $manifest['artifact'] = $this->safeReplayManifestArtifact($artifact);
        }

        $summary['replay_manifest'] = $manifest;

        return $summary;
    }
}
