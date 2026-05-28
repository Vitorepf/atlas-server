<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Atlas Rivals Run Orchestrator v1.
 *
 * Thin orchestration layer that exercises a Rivals Forge run while emitting
 * structured JSONL events the operator can tail in real time. Three modes:
 *
 *   - `dry_run`         : runs preflight + dry-run service ONLY; never spawns provider.
 *   - `fake_provider`   : spawns a fake subprocess given by `fake_provider_command`
 *                         and applies the full lifecycle (heartbeat, after-clean-check,
 *                         evidence pack, final report) WITHOUT calling Claude/Codex.
 *                         Used by tests and by operators rehearsing the pipeline.
 *   - `real_provider`   : would dispatch the actual atlas:engineering:benchmark:claude-fair
 *                         provider battery. NEVER invoked from tests. Requires explicit
 *                         operator confirmation flags before being constructed.
 *
 * Schema: atlas.programming.rivals_forge_run_orchestrator.v1
 */
class AtlasRivalsRunOrchestrator
{
    public const SCHEMA_VERSION = 'atlas.programming.rivals_forge_run_orchestrator.v1';

    public const MODE_DRY_RUN = 'dry_run';

    public const MODE_FAKE_PROVIDER = 'fake_provider';

    public const MODE_REAL_PROVIDER = 'real_provider';

    /** @var list<string> */
    public const SUPPORTED_MODES = [
        self::MODE_DRY_RUN,
        self::MODE_FAKE_PROVIDER,
        self::MODE_REAL_PROVIDER,
    ];

    public const VERDICT_PASSED = 'passed';

    public const VERDICT_BLOCKED_PREFLIGHT = 'blocked_preflight';

    public const VERDICT_BLOCKED_FINGERPRINT_MISMATCH = 'blocked_fingerprint_mismatch';

    public const VERDICT_INVALID_DIRTY_AFTER_RUN = 'invalid_dirty_after_run';

    public const VERDICT_INVALID_MISSING_EVIDENCE = 'invalid_missing_evidence';

    public const VERDICT_STALLED_RUNNER = 'stalled_runner_no_heartbeat';

    public const VERDICT_REAL_PROVIDER_REQUIRES_OPERATOR = 'real_provider_requires_operator_runbook';

    /** Max wall-clock seconds for the fake provider subprocess. */
    public const DEFAULT_FAKE_PROVIDER_TIMEOUT_SECONDS = 30;

    /** Heartbeat interval polling loop. */
    public const HEARTBEAT_TICK_MILLISECONDS = 200;

    /** Stall budget (seconds of zero output) before aborting. */
    public const DEFAULT_STALL_BUDGET_SECONDS = 5;

    public function __construct(
        private readonly AtlasForgeNativeRivalsPreflightService $preflight,
        private readonly AtlasForgeNativeRivalsDryRunService $dryRun,
        private readonly AtlasRivalsEvidencePackService $evidencePack,
        private readonly AtlasRivalsEvidencePackVerifierService $evidenceVerifier,
        private readonly RivalsForgeReadinessFingerprintService $fingerprint,
        private readonly RivalsForgeRunLogStreamService $stream,
        private readonly WorkspaceHygieneService $workspaceHygiene,
    ) {}

    /**
     * @param  array<string,mixed>  $intent
     * @return array<string,mixed>
     */
    public function run(array $intent): array
    {
        $mode = (string) ($intent['mode'] ?? self::MODE_DRY_RUN);
        if (! in_array($mode, self::SUPPORTED_MODES, true)) {
            return $this->blockedResponse('unsupported_mode', ['mode' => $mode]);
        }
        $runId = (string) ($intent['run_id'] ?? ('rivals-forge-'.(string) Str::ulid()));
        $atlasWorkspace = is_string($intent['atlas_workspace'] ?? null) ? (string) $intent['atlas_workspace'] : base_path();
        $baselineWorkspace = is_string($intent['baseline_workspace'] ?? null) ? (string) $intent['baseline_workspace'] : null;
        $atlasModel = (string) ($intent['atlas_model'] ?? 'opus');
        $baselineModel = (string) ($intent['baseline_model'] ?? $atlasModel);
        $preset = (string) ($intent['preset'] ?? 'quick');
        $caseId = is_string($intent['case_id'] ?? null) ? (string) $intent['case_id'] : null;
        $fakeProviderCommand = is_string($intent['fake_provider_command'] ?? null)
            ? (string) $intent['fake_provider_command']
            : null;
        $timeoutSeconds = (int) ($intent['fake_provider_timeout_seconds'] ?? self::DEFAULT_FAKE_PROVIDER_TIMEOUT_SECONDS);
        $stallBudgetSeconds = (int) ($intent['stall_budget_seconds'] ?? self::DEFAULT_STALL_BUDGET_SECONDS);
        $runTests = (bool) ($intent['run_tests'] ?? false);
        $testCommand = is_string($intent['test_command'] ?? null) ? (string) $intent['test_command'] : null;

        $intentForFingerprint = [
            'suite_id' => $intent['suite_id'] ?? null,
            'preset' => $preset,
            'atlas_model' => $atlasModel,
            'baseline_model' => $baselineModel,
            'atlas_workspace' => $atlasWorkspace,
            'baseline_workspace' => $baselineWorkspace,
            'case_ids' => $caseId === null ? [] : [$caseId],
            'gate_profile' => $intent['gate_profile'] ?? null,
            'test_command' => $testCommand,
        ];
        $readinessFingerprint = $this->fingerprint->compute($intentForFingerprint);
        $expectedFingerprint = is_array($intent['expected_fingerprint'] ?? null) ? $intent['expected_fingerprint'] : null;
        $fingerprintDiagnosis = $expectedFingerprint === null
            ? ['matches' => true, 'reason' => 'no_expected_fingerprint_supplied', 'diff_fields' => []]
            : $this->fingerprint->diagnose($expectedFingerprint, $readinessFingerprint);

        $intentForStream = array_merge($intentForFingerprint, [
            'mode' => $mode,
            'run_tests' => $runTests,
            'fingerprint_value' => $readinessFingerprint['value'],
        ]);
        $this->stream->start($runId, $intentForStream);

        if (! $fingerprintDiagnosis['matches']) {
            return $this->finalize($runId, $readinessFingerprint, self::VERDICT_BLOCKED_FINGERPRINT_MISMATCH, [
                'fingerprint_diagnosis' => $fingerprintDiagnosis,
            ]);
        }

        $preflightPacket = $this->preflight->preflight([
            'workspace' => $atlasWorkspace,
            'baseline_workspace' => $baselineWorkspace,
            'case_id' => $caseId,
            'suite_id' => $intent['suite_id'] ?? null,
            'preset' => $preset,
            'atlas_model' => $atlasModel,
            'baseline_model' => $baselineModel,
            'gate_profile' => $intent['gate_profile'] ?? null,
            'test_command' => $testCommand,
            'intends_provider_battery' => $mode === self::MODE_REAL_PROVIDER,
            'provider_cost_approved' => (bool) ($intent['provider_cost_approved'] ?? false),
            'runbook_reviewed' => (bool) ($intent['runbook_reviewed'] ?? false),
        ]);
        $this->stream->event($runId, 'preflight', [
            'status' => $preflightPacket['status'] ?? null,
            'blocking_reasons' => $preflightPacket['blocking_reasons'] ?? [],
        ]);

        if (! in_array($preflightPacket['status'] ?? '', ['ready_for_dry_run', 'ready_for_provider_battery'], true)) {
            return $this->finalize($runId, $readinessFingerprint, self::VERDICT_BLOCKED_PREFLIGHT, [
                'preflight_status' => $preflightPacket['status'] ?? null,
                'blocking_reasons' => $preflightPacket['blocking_reasons'] ?? [],
            ]);
        }

        $dryRunPacket = $this->dryRun->dryRun([
            'workspace' => $atlasWorkspace,
            'baseline_workspace' => $baselineWorkspace,
            'case_id' => $caseId,
            'preset' => $preset,
            'atlas_model' => $atlasModel,
            'baseline_model' => $baselineModel,
            'test_command' => $testCommand,
        ]);
        $this->stream->event($runId, 'dry_run', [
            'status' => $dryRunPacket['status'] ?? null,
            'replay_manifest_valid' => (bool) data_get($dryRunPacket, 'replay_manifest.valid', false),
        ]);

        if ($mode === self::MODE_DRY_RUN) {
            return $this->finalize($runId, $readinessFingerprint, self::VERDICT_PASSED, [
                'mode' => $mode,
                'dry_run_status' => $dryRunPacket['status'] ?? null,
            ]);
        }

        if ($mode === self::MODE_REAL_PROVIDER) {
            // Real provider dispatch is governed by AtlasEngineeringBenchmarkFairCommand,
            // not by this orchestrator. We refuse here so a stray caller cannot bypass
            // operator approval.
            return $this->finalize($runId, $readinessFingerprint, self::VERDICT_REAL_PROVIDER_REQUIRES_OPERATOR, [
                'next_action' => 'Run real Rivals battery only through `atlas:engineering:benchmark:rivals run` with explicit --confirm-runbook-reviewed --confirm-provider-cost flags.',
            ]);
        }

        // MODE_FAKE_PROVIDER from here on.
        $workspaceBefore = $this->workspaceHygiene->snapshot($atlasWorkspace);
        $this->stream->event($runId, 'provider_start', [
            'model' => $atlasModel,
            'baseline_model' => $baselineModel,
            'mode' => $mode,
            'fake' => true,
            'fake_provider_command' => $fakeProviderCommand,
        ]);

        $providerResult = $this->runFakeProvider($runId, $atlasWorkspace, $fakeProviderCommand, $timeoutSeconds, $stallBudgetSeconds);
        $this->stream->event($runId, 'provider_done', [
            'exit_code' => $providerResult['exit_code'],
            'stalled' => $providerResult['stalled'],
            'duration_ms' => $providerResult['duration_ms'],
        ]);

        if ($providerResult['stalled']) {
            $this->stream->event($runId, 'stalled', [
                'reason' => 'no_heartbeat_within_budget',
                'budget_seconds' => $stallBudgetSeconds,
            ]);
            return $this->finalize($runId, $readinessFingerprint, self::VERDICT_STALLED_RUNNER, [
                'provider_result' => $providerResult,
            ]);
        }

        $afterCleanCheck = $this->workspaceHygiene->dirtyAfterRun($workspaceBefore, $atlasWorkspace);
        $this->stream->event($runId, 'after_clean_check', $afterCleanCheck);

        $packOptions = [
            'workspace' => $atlasWorkspace,
            'case_id' => $caseId,
            'preset' => $preset,
            'run_tests' => $runTests,
            'test_command' => $testCommand,
        ];
        $pack = $this->evidencePack->generate($packOptions);

        $packForVerifier = $pack;
        $packForVerifier['workspace']['after_clean_check'] = $afterCleanCheck;
        if ($workspaceBefore['is_git'] ?? false) {
            $packForVerifier['workspace']['before_status_hash'] = $workspaceBefore['status_hash'] ?? null;
            $packForVerifier['workspace']['before_head_sha'] = $workspaceBefore['head_sha'] ?? null;
        }

        $verification = $this->evidenceVerifier->verify($packForVerifier);
        $this->stream->event($runId, 'evidence_pack', [
            'verification_status' => $verification['status'] ?? null,
            'evidence_pack_id' => $pack['evidence_pack_id'] ?? null,
            'workspace_clean_after_run' => (bool) ($afterCleanCheck['clean'] ?? false),
        ]);

        if (! ($afterCleanCheck['clean'] ?? false)) {
            return $this->finalize($runId, $readinessFingerprint, self::VERDICT_INVALID_DIRTY_AFTER_RUN, [
                'after_clean_check' => $afterCleanCheck,
                'evidence_pack_id' => $pack['evidence_pack_id'] ?? null,
            ]);
        }

        if (($verification['status'] ?? null) !== 'passed') {
            return $this->finalize($runId, $readinessFingerprint, self::VERDICT_INVALID_MISSING_EVIDENCE, [
                'verification' => $verification,
                'evidence_pack_id' => $pack['evidence_pack_id'] ?? null,
            ]);
        }

        return $this->finalize($runId, $readinessFingerprint, self::VERDICT_PASSED, [
            'evidence_pack_id' => $pack['evidence_pack_id'] ?? null,
            'after_clean_check' => $afterCleanCheck,
        ]);
    }

    /**
     * Run a fake provider subprocess with heartbeats and a no-output stall budget.
     *
     * @return array{exit_code:int,stdout:string,stderr:string,duration_ms:int,stalled:bool}
     */
    private function runFakeProvider(string $runId, string $cwd, ?string $command, int $timeoutSeconds, int $stallBudgetSeconds): array
    {
        $cmd = $command ?? "php -r 'fwrite(STDOUT, \"fake-provider-ok\");'";
        $process = Process::fromShellCommandline($cmd, $cwd, $this->workspaceHygiene->forceBytecodeDisabledEnv());
        $process->setTimeout(max(1, $timeoutSeconds));
        $startedAt = microtime(true);
        $process->start();
        $lastOutputAt = microtime(true);
        $stalled = false;
        $stdoutBuffer = '';
        $stderrBuffer = '';
        $lastHeartbeatAt = microtime(true);

        while ($process->isRunning()) {
            $stdoutChunk = $process->getIncrementalOutput();
            $stderrChunk = $process->getIncrementalErrorOutput();
            if ($stdoutChunk !== '' || $stderrChunk !== '') {
                $stdoutBuffer .= $stdoutChunk;
                $stderrBuffer .= $stderrChunk;
                $lastOutputAt = microtime(true);
                if ($stdoutChunk !== '') {
                    $this->stream->event($runId, 'provider_progress', [
                        'chunk_hash' => hash('sha256', $stdoutChunk),
                        'bytes' => strlen($stdoutChunk),
                    ]);
                }
            }
            if ((microtime(true) - $lastHeartbeatAt) >= 1.0) {
                $this->stream->heartbeat($runId);
                $lastHeartbeatAt = microtime(true);
            }
            if ((microtime(true) - $lastOutputAt) >= $stallBudgetSeconds) {
                $process->stop(0);
                $stalled = true;
                break;
            }
            usleep(self::HEARTBEAT_TICK_MILLISECONDS * 1000);
        }

        if (! $stalled) {
            $stdoutBuffer .= $process->getIncrementalOutput();
            $stderrBuffer .= $process->getIncrementalErrorOutput();
        }

        $endedAt = microtime(true);

        return [
            'exit_code' => $process->getExitCode() ?? -1,
            'stdout' => $stdoutBuffer,
            'stderr' => $stderrBuffer,
            'duration_ms' => (int) round(($endedAt - $startedAt) * 1000),
            'stalled' => $stalled,
        ];
    }

    /**
     * @param  array<string,mixed>  $details
     * @return array<string,mixed>
     */
    private function finalize(string $runId, array $readinessFingerprint, string $verdict, array $details = []): array
    {
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'run_id' => $runId,
            'verdict' => $verdict,
            'score' => null,
            'claim_ready' => false,
            'readiness_fingerprint' => $readinessFingerprint,
            'details' => $details,
            'finalized_at' => now()->toJSON(),
            'external_provider_call' => false,
            'separated_from_external_rivals_certification' => true,
        ];
        $this->stream->event($runId, 'final_report', $payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $details
     * @return array<string,mixed>
     */
    private function blockedResponse(string $reason, array $details = []): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'run_id' => null,
            'verdict' => 'blocked',
            'score' => null,
            'claim_ready' => false,
            'details' => array_merge(['reason' => $reason], $details),
            'finalized_at' => now()->toJSON(),
            'external_provider_call' => false,
            'separated_from_external_rivals_certification' => true,
        ];
    }
}
