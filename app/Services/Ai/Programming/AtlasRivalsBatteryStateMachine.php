<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use Throwable;

/**
 * Atlas Rivals Battery State Machine v1.
 *
 * Read-model that consolidates the health of a Forge-Native Rivals battery
 * into a single canonical state. Composes preflight, dry-run, workspace
 * hygiene, triage registry, evidence-pack verifier and run-log stream
 * services into one snapshot. NEVER dispatches a provider, NEVER mutates
 * git state, NEVER alters disk other than what its dependencies already do
 * as read operations. Every dependency call is wrapped in try/catch so a
 * single broken service does not collapse the snapshot — it just appears as
 * `<service>_unavailable` in `blocking_reasons`.
 *
 * Schema: atlas.rivals.battery_state.v1
 */
class AtlasRivalsBatteryStateMachine
{
    public const STATE_NO_WORKSPACE = 'no_workspace';

    public const STATE_WORKTREES_READY = 'worktrees_ready';

    public const STATE_PREFLIGHT_READY = 'preflight_ready';

    public const STATE_DRY_RUN_PASSED = 'dry_run_passed';

    public const STATE_READY_FOR_REAL_PROVIDER = 'ready_for_real_provider';

    public const STATE_RUNNING = 'running';

    public const STATE_COMPLETED_VALID = 'completed_valid';

    public const STATE_COMPLETED_INVALID = 'completed_invalid';

    public const STATE_BLOCKED_DIRTY_WORKSPACE = 'blocked_dirty_workspace';

    public const STATE_BLOCKED_TRACKED_BYTECODE = 'blocked_tracked_bytecode';

    public const STATE_BLOCKED_TRIAGE_REQUIRED = 'blocked_triage_required';

    public const STATE_BLOCKED_OPERATOR_CONFIRMATION_REQUIRED = 'blocked_operator_confirmation_required';

    public const STATE_BLOCKED_PROVIDER_UNAVAILABLE = 'blocked_provider_unavailable';

    public const STATE_REPLAY_READY = 'replay_ready';

    public const STATE_REPLAY_FAILED = 'replay_failed';

    public const SCHEMA = 'atlas.rivals.battery_state.v1';

    /** Heartbeat freshness (seconds) below which a run is considered alive. */
    private const HEARTBEAT_LIVENESS_WINDOW_SECONDS = 120;

    public function __construct(
        private readonly AtlasForgeNativeRivalsPreflightService $preflight,
        private readonly AtlasForgeNativeRivalsDryRunService $dryRunService,
        private readonly RivalsForgeReadinessFingerprintService $fingerprintService,
        private readonly WorkspaceHygieneService $workspaceHygiene,
        private readonly AtlasRivalsInvalidBatteryTriageRegistry $triageRegistry,
        private readonly AtlasRivalsEvidencePackVerifierService $evidenceVerifier,
        private readonly RivalsForgeRunLogStreamService $runLogStream,
    ) {}

    /**
     * Pure read-model classifier: take a flat input hash describing the
     * harness operator's intent + checks and return the canonical state +
     * blocking reasons. No I/O, no service calls — every fact is supplied
     * explicitly by the caller. Used by tests and by the command's
     * quick-real plan when a synthetic snapshot is needed without re-running
     * preflight / dry-run.
     *
     * Recognized keys (all optional, defaults to false):
     *   - worktrees_present: bool
     *   - dirty / atlas_worktree_clean / workspaces_clean: bool
     *   - tracked_pyc: bool
     *   - triaged: bool (fingerprint triage required)
     *   - preflight_passed: bool
     *   - dry_run_passed: bool
     *   - runbook_reviewed / provider_cost_approved / real_provider_call_confirmed: bool
     *   - confirmations: {real_provider_call:bool, cost_approved:bool, runbook_reviewed:bool}
     *
     * @param  array<string,mixed>  $input
     * @return array{state:string, blocking_reasons:list<string>}
     */
    public function classify(array $input): array
    {
        // Confirmations can come in flat or grouped form.
        $confirmations = is_array($input['confirmations'] ?? null) ? $input['confirmations'] : [];
        $confirmRealProviderCall = (bool) ($input['real_provider_call_confirmed']
            ?? $input['confirm_real_provider_call']
            ?? $confirmations['real_provider_call']
            ?? false);
        $confirmCost = (bool) ($input['provider_cost_approved']
            ?? $input['confirm_provider_cost']
            ?? $confirmations['cost_approved']
            ?? $confirmations['provider_cost']
            ?? false);
        $confirmRunbook = (bool) ($input['runbook_reviewed']
            ?? $input['confirm_runbook_reviewed']
            ?? $confirmations['runbook_reviewed']
            ?? false);

        $worktreesPresent = (bool) ($input['worktrees_present']
            ?? $input['atlas_worktree_exists']
            ?? true);
        $dirty = (bool) ($input['dirty']
            ?? ! (bool) ($input['atlas_worktree_clean']
                ?? $input['workspaces_clean']
                ?? true));
        $trackedPyc = (bool) ($input['tracked_pyc']
            ?? $input['tracked_python_bytecode']
            ?? false);
        $triaged = (bool) ($input['triaged']
            ?? $input['triage_required']
            ?? $input['fingerprint_triage_pending']
            ?? false);
        $preflightPassed = (bool) ($input['preflight_passed'] ?? false);
        $dryRunPassed = (bool) ($input['dry_run_passed'] ?? false);

        $blockingReasons = [];

        if (! $worktreesPresent) {
            return [
                'state' => self::STATE_NO_WORKSPACE,
                'blocking_reasons' => ['atlas_worktree_missing'],
            ];
        }
        if ($trackedPyc) {
            return [
                'state' => self::STATE_BLOCKED_TRACKED_BYTECODE,
                'blocking_reasons' => ['tracked_python_bytecode_in_workspace'],
            ];
        }
        if ($dirty) {
            return [
                'state' => self::STATE_BLOCKED_DIRTY_WORKSPACE,
                'blocking_reasons' => ['dirty_workspace'],
            ];
        }
        if ($triaged) {
            return [
                'state' => self::STATE_BLOCKED_TRIAGE_REQUIRED,
                'blocking_reasons' => ['fingerprint_pending_triage'],
            ];
        }
        if (! $preflightPassed && array_key_exists('preflight_passed', $input)) {
            return [
                'state' => self::STATE_WORKTREES_READY,
                'blocking_reasons' => ['preflight_not_passed'],
            ];
        }
        if (! $dryRunPassed && array_key_exists('dry_run_passed', $input)) {
            return [
                'state' => self::STATE_PREFLIGHT_READY,
                'blocking_reasons' => ['dry_run_not_passed'],
            ];
        }

        $allConfirm = $confirmRealProviderCall && $confirmCost && $confirmRunbook;
        if (! $allConfirm) {
            $missing = [];
            if (! $confirmRunbook) {
                $missing[] = 'confirm_runbook_reviewed';
            }
            if (! $confirmCost) {
                $missing[] = 'confirm_provider_cost';
            }
            if (! $confirmRealProviderCall) {
                $missing[] = 'confirm_real_provider_call';
            }

            return [
                'state' => self::STATE_BLOCKED_OPERATOR_CONFIRMATION_REQUIRED,
                'blocking_reasons' => $missing,
            ];
        }

        return [
            'state' => self::STATE_READY_FOR_REAL_PROVIDER,
            'blocking_reasons' => $blockingReasons,
        ];
    }

    /**
     * Build the full battery snapshot.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function snapshot(array $input): array
    {
        $generatedAt = gmdate('Y-m-d\TH:i:s\Z');

        $atlasWorktree = $this->normalizeString($input['atlas_worktree'] ?? null);
        $baselineWorktree = $this->normalizeString($input['baseline_worktree'] ?? null);
        $model = $this->normalizeString($input['model'] ?? null) ?? '';
        $baselineModel = $this->normalizeString($input['baseline_model'] ?? null) ?? '';
        $suiteId = $this->normalizeString($input['suite_id'] ?? null) ?? '';
        $preset = $this->normalizeString($input['preset'] ?? null) ?? '';
        $gateProfile = $this->normalizeString($input['gate_profile'] ?? null);
        $testCommand = $this->normalizeString($input['test_command'] ?? null);
        $confirmRunbook = (bool) ($input['confirm_runbook_reviewed'] ?? false);
        $confirmProviderCost = (bool) ($input['confirm_provider_cost'] ?? false);
        $confirmRealProviderCall = (bool) ($input['confirm_real_provider_call'] ?? false);
        $runId = $this->normalizeString($input['run_id'] ?? null);

        $blockingReasons = [];

        // ---- Worktrees existence ------------------------------------------------
        $atlasExists = $atlasWorktree !== null && is_dir($atlasWorktree);
        $baselineExists = $baselineWorktree !== null && is_dir($baselineWorktree);

        // ---- Fingerprint --------------------------------------------------------
        $fingerprintFull = null;
        $fingerprintPrefix = null;
        try {
            $fp = $this->fingerprintService->compute([
                'suite_id' => $suiteId,
                'preset' => $preset,
                'atlas_model' => $model,
                'baseline_model' => $baselineModel,
                'atlas_workspace' => $atlasWorktree,
                'baseline_workspace' => $baselineWorktree,
                'case_ids' => [],
                'gate_profile' => $gateProfile,
                'test_command' => $testCommand,
            ]);
            $fingerprintFull = is_string($fp['value'] ?? null) ? $fp['value'] : null;
        } catch (Throwable $e) {
            $blockingReasons[] = 'fingerprint_service_unavailable';
        }

        if (! is_string($fingerprintFull)) {
            // Fallback deterministic fingerprint so the snapshot stays usable.
            $fingerprintFull = hash('sha256', json_encode([
                'suite_id' => $suiteId,
                'preset' => $preset,
                'model' => $model,
                'baseline_model' => $baselineModel,
                'atlas_worktree' => $atlasWorktree,
                'baseline_worktree' => $baselineWorktree,
                'gate_profile' => $gateProfile,
                'test_command' => $testCommand,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
        }
        $fingerprintPrefix = substr($fingerprintFull, 0, 16);

        // ---- Workspace hygiene --------------------------------------------------
        $workspacesClean = false;
        $trackedBytecodeBlocked = false;
        $afterCleanCheckOk = false;
        $atlasBytecodeCount = 0;
        $baselineBytecodeCount = 0;
        $atlasSnapshot = null;
        $baselineSnapshot = null;

        if ($atlasExists) {
            try {
                $atlasSnapshot = $this->workspaceHygiene->snapshot($atlasWorktree);
            } catch (Throwable $e) {
                $blockingReasons[] = 'workspace_hygiene_unavailable';
            }
            try {
                $atlasBytecode = $this->workspaceHygiene->trackedPythonBytecode($atlasWorktree);
                $atlasBytecodeCount = (int) ($atlasBytecode['tracked_count'] ?? 0);
            } catch (Throwable $e) {
                $blockingReasons[] = 'workspace_hygiene_unavailable';
            }
        }

        if ($baselineExists) {
            try {
                $baselineSnapshot = $this->workspaceHygiene->snapshot($baselineWorktree);
            } catch (Throwable $e) {
                $blockingReasons[] = 'workspace_hygiene_unavailable';
            }
            try {
                $baselineBytecode = $this->workspaceHygiene->trackedPythonBytecode($baselineWorktree);
                $baselineBytecodeCount = (int) ($baselineBytecode['tracked_count'] ?? 0);
            } catch (Throwable $e) {
                $blockingReasons[] = 'workspace_hygiene_unavailable';
            }
        }

        $atlasClean = is_array($atlasSnapshot) ? (bool) ($atlasSnapshot['clean'] ?? false) : false;
        $baselineClean = $baselineExists
            ? (is_array($baselineSnapshot) ? (bool) ($baselineSnapshot['clean'] ?? false) : false)
            : true; // baseline is optional for some flows; treated as clean when absent.
        $workspacesClean = $atlasClean && $baselineClean && $atlasExists;
        $trackedBytecodeBlocked = ($atlasBytecodeCount + $baselineBytecodeCount) > 0;

        // ---- Triage registry ----------------------------------------------------
        $fingerprintTriaged = false;
        $triagePending = false;
        try {
            $triagePending = $this->triageRegistry->requiresTriage($fingerprintFull);
            // `requiresTriage` returns TRUE iff there's a known invalid battery
            // that has NOT been triaged. We expose `fingerprint_triaged` as
            // "fingerprint has been triaged_quarantined" — derived from the
            // registry entry status.
            $entry = $this->triageRegistry->loadEntry($fingerprintFull);
            $fingerprintTriaged = is_array($entry)
                && ($entry['status'] ?? null) === AtlasRivalsInvalidBatteryTriageRegistry::STATUS_TRIAGED_QUARANTINED;
        } catch (Throwable $e) {
            $blockingReasons[] = 'triage_registry_unavailable';
        }

        // ---- Preflight ----------------------------------------------------------
        $preflightStatus = 'skipped';
        $preflightPacket = null;
        if ($atlasExists) {
            try {
                $preflightPacket = $this->preflight->preflight([
                    'workspace' => $atlasWorktree,
                    'baseline_workspace' => $baselineWorktree,
                    'suite_id' => $suiteId !== '' ? $suiteId : null,
                    'preset' => $preset !== '' ? $preset : null,
                    'atlas_model' => $model !== '' ? $model : null,
                    'baseline_model' => $baselineModel !== '' ? $baselineModel : null,
                    'gate_profile' => $gateProfile,
                    'test_command' => $testCommand,
                    'provider_cost_approved' => $confirmProviderCost,
                    'runbook_reviewed' => $confirmRunbook,
                    'intends_provider_battery' => $confirmRealProviderCall,
                ]);
                $preflightStatus = is_string($preflightPacket['status'] ?? null)
                    ? $preflightPacket['status']
                    : 'unknown';
            } catch (Throwable $e) {
                $blockingReasons[] = 'preflight_service_unavailable';
                $preflightStatus = 'service_unavailable';
            }
        }

        // ---- Dry-run ------------------------------------------------------------
        $dryRunStatus = 'skipped';
        $dryRunPacket = null;
        $dryRunEligible = $atlasExists
            && $workspacesClean
            && ! $trackedBytecodeBlocked
            && ! $triagePending
            && in_array($preflightStatus, ['ready_for_dry_run', 'ready_for_provider_battery'], true);

        if ($dryRunEligible) {
            try {
                $dryRunPacket = $this->dryRunService->dryRun([
                    'workspace' => $atlasWorktree,
                    'baseline_workspace' => $baselineWorktree,
                    'suite_id' => $suiteId !== '' ? $suiteId : null,
                    'preset' => $preset !== '' ? $preset : null,
                    'atlas_model' => $model !== '' ? $model : null,
                    'baseline_model' => $baselineModel !== '' ? $baselineModel : null,
                    'gate_profile' => $gateProfile,
                    'test_command' => $testCommand,
                ]);
                $dryRunStatus = is_string($dryRunPacket['status'] ?? null)
                    ? $dryRunPacket['status']
                    : 'unknown';
            } catch (Throwable $e) {
                $blockingReasons[] = 'dry_run_service_unavailable';
                $dryRunStatus = 'service_unavailable';
            }
        }

        // ---- Run liveness / evidence / replay ----------------------------------
        $resolvedRunId = $runId;
        if ($resolvedRunId === null) {
            try {
                $resolvedRunId = $this->runLogStream->latestRunId();
            } catch (Throwable $e) {
                $blockingReasons[] = 'run_log_stream_unavailable';
            }
        }

        $heartbeatFreshSeconds = null;
        $runHasRecentHeartbeat = false;
        $runHasFinalReport = false;
        $replayEventsPresent = false;
        if (is_string($resolvedRunId) && $resolvedRunId !== '') {
            try {
                $lastEventAt = $this->runLogStream->lastEventAt($resolvedRunId);
                if ($lastEventAt !== null) {
                    $heartbeatFreshSeconds = max(0, time() - $lastEventAt->getTimestamp());
                    $runHasRecentHeartbeat = $heartbeatFreshSeconds <= self::HEARTBEAT_LIVENESS_WINDOW_SECONDS;
                }
                $events = $this->runLogStream->tail($resolvedRunId, 0);
                foreach ($events as $event) {
                    $kind = is_string($event['kind'] ?? null) ? $event['kind'] : null;
                    if ($kind === 'final_report') {
                        $runHasFinalReport = true;
                    }
                    if ($kind === 'provider_done' || $kind === 'evidence_pack' || $kind === 'final_report') {
                        $replayEventsPresent = true;
                    }
                }
            } catch (Throwable $e) {
                $blockingReasons[] = 'run_log_stream_unavailable';
            }
        }

        // ---- Evidence pack & after-clean (read-only inspection) ----------------
        $evidencePackPresent = false;
        $evidencePackComplete = false;
        $replayPackPresent = false;
        $evidencePackPath = null;
        $evidencePack = $this->loadLatestEvidencePack($resolvedRunId, $evidencePackPath);
        if (is_array($evidencePack)) {
            $evidencePackPresent = true;
            try {
                $verification = $this->evidenceVerifier->verify(
                    $evidencePack,
                    AtlasRivalsEvidencePackVerifierService::MODE_REAL_RUN,
                );
                $evidencePackComplete = ($verification['status'] ?? null) === 'passed';
                $afterCleanCheckOk = (bool) data_get($evidencePack, 'workspace.after_clean_check.clean', false);
                $replayPackPresent = (bool) data_get($evidencePack, 'replay_manifest.present', false);
            } catch (Throwable $e) {
                $blockingReasons[] = 'evidence_pack_verifier_unavailable';
            }
        }

        // ---- Operator confirmations -------------------------------------------
        $operatorConfirmations = [
            'runbook_reviewed' => $confirmRunbook,
            'provider_cost' => $confirmProviderCost,
            'real_provider_call' => $confirmRealProviderCall,
        ];
        $allConfirmationsTrue = $confirmRunbook && $confirmProviderCost && $confirmRealProviderCall;

        // ---- State machine ------------------------------------------------------
        $state = $this->decideState(
            atlasExists: $atlasExists,
            baselineExists: $baselineExists,
            workspacesClean: $workspacesClean,
            trackedBytecodeBlocked: $trackedBytecodeBlocked,
            triagePending: $triagePending,
            preflightStatus: $preflightStatus,
            dryRunStatus: $dryRunStatus,
            allConfirmationsTrue: $allConfirmationsTrue,
            runHasRecentHeartbeat: $runHasRecentHeartbeat,
            runHasFinalReport: $runHasFinalReport,
            evidencePackPresent: $evidencePackPresent,
            evidencePackComplete: $evidencePackComplete,
            afterCleanCheckOk: $afterCleanCheckOk,
            replayEventsPresent: $replayEventsPresent,
            replayPackPresent: $replayPackPresent,
            resolvedRunId: $resolvedRunId,
            blockingReasons: $blockingReasons,
        );

        $blockingReasons = array_values(array_unique($blockingReasons));

        return [
            'schema' => self::SCHEMA,
            'generated_at' => $generatedAt,
            'state' => $state,
            'fingerprint' => $fingerprintPrefix,
            'fingerprint_full' => $fingerprintFull,
            'fingerprint_triaged' => $fingerprintTriaged,
            'checks' => [
                'atlas_worktree_exists' => $atlasExists,
                'baseline_worktree_exists' => $baselineExists,
                'workspaces_clean' => $workspacesClean,
                'tracked_python_bytecode_blocked' => $trackedBytecodeBlocked,
                'preflight_status' => $preflightStatus,
                'dry_run_status' => $dryRunStatus,
                'operator_confirmations' => $operatorConfirmations,
                'evidence_pack_present' => $evidencePackPresent,
                'evidence_pack_complete' => $evidencePackComplete,
                'replay_pack_present' => $replayPackPresent,
                'after_clean_check_ok' => $afterCleanCheckOk,
                'atlas_tracked_bytecode_count' => $atlasBytecodeCount,
                'baseline_tracked_bytecode_count' => $baselineBytecodeCount,
                'heartbeat_age_seconds' => $heartbeatFreshSeconds,
                'run_id' => $resolvedRunId,
                'evidence_pack_path' => $evidencePackPath,
                'triage_pending' => $triagePending,
            ],
            'blocking_reasons' => $blockingReasons,
            'next_actions' => $this->nextActions($state, [
                'atlas_worktree' => $atlasWorktree,
                'baseline_worktree' => $baselineWorktree,
                'suite_id' => $suiteId,
                'preset' => $preset,
                'model' => $model,
                'baseline_model' => $baselineModel,
                'fingerprint' => $fingerprintFull,
            ]),
            'inputs_echo' => [
                'atlas_worktree' => $atlasWorktree,
                'baseline_worktree' => $baselineWorktree,
                'model' => $model,
                'baseline_model' => $baselineModel,
                'suite_id' => $suiteId,
                'preset' => $preset,
                'gate_profile' => $gateProfile,
                'test_command' => $testCommand,
                'confirm_runbook_reviewed' => $confirmRunbook,
                'confirm_provider_cost' => $confirmProviderCost,
                'confirm_real_provider_call' => $confirmRealProviderCall,
                'run_id' => $runId,
            ],
        ];
    }

    /**
     * Diagnostic-only snapshot: surfaces operational invariants (cwd, php
     * version, git clean, tracked .pyc, service responsiveness, worktree
     * presence) WITHOUT proposing provider next-actions.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function doctor(array $input): array
    {
        $generatedAt = gmdate('Y-m-d\TH:i:s\Z');

        $atlasWorktree = $this->normalizeString($input['atlas_worktree'] ?? null);
        $baselineWorktree = $this->normalizeString($input['baseline_worktree'] ?? null);

        $cwd = getcwd() ?: null;
        $phpVersion = PHP_VERSION;
        $phpBinary = PHP_BINARY;

        $expectedRepoMarker = function_exists('base_path') ? @base_path() : null;
        $cwdMatchesRepo = is_string($expectedRepoMarker)
            && is_string($cwd)
            && rtrim((string) $cwd, DIRECTORY_SEPARATOR) === rtrim((string) $expectedRepoMarker, DIRECTORY_SEPARATOR);

        $atlasExists = $atlasWorktree !== null && is_dir($atlasWorktree);
        $baselineExists = $baselineWorktree !== null && is_dir($baselineWorktree);

        $blockingReasons = [];

        // Service responsiveness probes ------------------------------------------
        $servicesProbed = [
            'preflight' => $this->probeService(fn () => $this->preflight::SCHEMA_VERSION),
            'dry_run' => $this->probeService(fn () => $this->dryRunService::SCHEMA_VERSION),
            'fingerprint' => $this->probeService(fn () => $this->fingerprintService::SCHEMA_VERSION),
            'workspace_hygiene' => $this->probeService(fn () => $this->workspaceHygiene::SCHEMA_VERSION),
            'triage_registry' => $this->probeService(fn () => $this->triageRegistry::SCHEMA_VERSION),
            'evidence_verifier' => $this->probeService(fn () => $this->evidenceVerifier::SCHEMA_VERSION),
            'run_log_stream' => $this->probeService(fn () => $this->runLogStream::SCHEMA_VERSION),
        ];
        foreach ($servicesProbed as $name => $probe) {
            if (($probe['status'] ?? null) !== 'ok') {
                $blockingReasons[] = $name.'_unavailable';
            }
        }

        // Git / hygiene probes ---------------------------------------------------
        $atlasClean = null;
        $baselineClean = null;
        $atlasTrackedBytecode = 0;
        $baselineTrackedBytecode = 0;
        if ($atlasExists) {
            try {
                $atlasSnapshot = $this->workspaceHygiene->snapshot($atlasWorktree);
                $atlasClean = (bool) ($atlasSnapshot['clean'] ?? false);
            } catch (Throwable $e) {
                $blockingReasons[] = 'workspace_hygiene_unavailable';
            }
            try {
                $atlasBytecode = $this->workspaceHygiene->trackedPythonBytecode($atlasWorktree);
                $atlasTrackedBytecode = (int) ($atlasBytecode['tracked_count'] ?? 0);
            } catch (Throwable $e) {
                $blockingReasons[] = 'workspace_hygiene_unavailable';
            }
        } else {
            $blockingReasons[] = 'atlas_worktree_missing';
        }

        if ($baselineExists) {
            try {
                $baselineSnapshot = $this->workspaceHygiene->snapshot($baselineWorktree);
                $baselineClean = (bool) ($baselineSnapshot['clean'] ?? false);
            } catch (Throwable $e) {
                $blockingReasons[] = 'workspace_hygiene_unavailable';
            }
            try {
                $baselineBytecode = $this->workspaceHygiene->trackedPythonBytecode($baselineWorktree);
                $baselineTrackedBytecode = (int) ($baselineBytecode['tracked_count'] ?? 0);
            } catch (Throwable $e) {
                $blockingReasons[] = 'workspace_hygiene_unavailable';
            }
        }

        if ($atlasClean === false || $baselineClean === false) {
            $blockingReasons[] = 'dirty_workspace';
        }
        if (($atlasTrackedBytecode + $baselineTrackedBytecode) > 0) {
            $blockingReasons[] = 'tracked_python_bytecode';
        }

        $phpVersionOk = version_compare($phpVersion, '8.4.0', '>=');
        if (! $phpVersionOk) {
            $blockingReasons[] = 'php_version_below_8_4';
        }
        if (! $cwdMatchesRepo) {
            $blockingReasons[] = 'cwd_not_in_repo_root';
        }

        $blockingReasons = array_values(array_unique($blockingReasons));
        $status = $blockingReasons === [] ? 'ok' : 'blocked';

        // Flat `checks` map (Boolean per gate) so consumers can answer questions
        // like "is the battery green?" without navigating nested objects. The
        // nested `environment/worktrees/services` blocks remain canonical.
        $checks = [
            'cwd_in_repo_root' => $cwdMatchesRepo,
            'php_version_ok' => $phpVersionOk,
            'atlas_worktree_exists' => $atlasExists,
            'baseline_worktree_exists' => $baselineExists,
            'atlas_worktree_clean' => $atlasClean === true,
            'baseline_worktree_clean' => $baselineClean === true,
            'no_tracked_python_bytecode' => ($atlasTrackedBytecode + $baselineTrackedBytecode) === 0,
            'preflight_service_ok' => ($servicesProbed['preflight']['status'] ?? null) === 'ok',
            'dry_run_service_ok' => ($servicesProbed['dry_run']['status'] ?? null) === 'ok',
            'fingerprint_service_ok' => ($servicesProbed['fingerprint']['status'] ?? null) === 'ok',
            'workspace_hygiene_ok' => ($servicesProbed['workspace_hygiene']['status'] ?? null) === 'ok',
        ];

        return [
            'schema' => self::SCHEMA,
            'mode' => 'doctor',
            'generated_at' => $generatedAt,
            'status' => $status,
            'checks' => $checks,
            'environment' => [
                'cwd' => $cwd,
                'expected_repo_root' => $expectedRepoMarker,
                'cwd_matches_repo' => $cwdMatchesRepo,
                'php_version' => $phpVersion,
                'php_version_ok' => $phpVersionOk,
                'php_binary' => $phpBinary,
            ],
            'worktrees' => [
                'atlas' => [
                    'path' => $atlasWorktree,
                    'exists' => $atlasExists,
                    'clean' => $atlasClean,
                    'tracked_python_bytecode_count' => $atlasTrackedBytecode,
                ],
                'baseline' => [
                    'path' => $baselineWorktree,
                    'exists' => $baselineExists,
                    'clean' => $baselineClean,
                    'tracked_python_bytecode_count' => $baselineTrackedBytecode,
                ],
            ],
            'services' => $servicesProbed,
            'blocking_reasons' => $blockingReasons,
        ];
    }

    /**
     * Map a state constant to a list of human-readable reasons for being in it.
     *
     * @return list<string>
     */
    public function reasonsForState(string $state): array
    {
        return match ($state) {
            self::STATE_NO_WORKSPACE => [
                'O Atlas worktree (e/ou baseline worktree) ainda não foi criado ou o caminho aponta para um diretório inexistente. Crie os worktrees com `git worktree add <path> <ref>` antes de prosseguir.',
            ],
            self::STATE_WORKTREES_READY => [
                'Worktrees existem e estão limpos. Preflight ainda não foi rodado — execute `php artisan atlas:programming:rivals-forge-preflight --json --strict`.',
            ],
            self::STATE_PREFLIGHT_READY => [
                'Preflight passou. Próximo passo é o dry-run para validar protocolo, manifest e replay-manifest sem gastar tokens.',
            ],
            self::STATE_DRY_RUN_PASSED => [
                'Dry-run validou protocolo, manifest e replay-manifest. Faltam confirmações do operador antes de despachar provider real.',
            ],
            self::STATE_READY_FOR_REAL_PROVIDER => [
                'Tudo pronto: workspace limpo, preflight passou, dry-run passou, fingerprint não está pendente de triage, operador confirmou runbook + custo + chamada real. Pode despachar o runner real.',
            ],
            self::STATE_RUNNING => [
                'Há um run com heartbeat recente. Tail o stream para acompanhar; não despache outro run sobre o mesmo workspace.',
            ],
            self::STATE_COMPLETED_VALID => [
                'Run encerrou com evidence pack completo, after_clean_check OK e replay manifest presente. Bateria pode ser arquivada como evidence ledger.',
            ],
            self::STATE_COMPLETED_INVALID => [
                'Run encerrou sem evidence pack completo OU after_clean_check falhou. Trate como `historical_invalid_battery` e abra triage com `php artisan atlas:engineering:benchmark:rivals triage-invalid-battery`.',
            ],
            self::STATE_BLOCKED_DIRTY_WORKSPACE => [
                'O Atlas worktree (ou o baseline worktree) tem mudanças não commitadas. Commit ou stash antes de retomar, ou use um worktree separado e limpo.',
            ],
            self::STATE_BLOCKED_TRACKED_BYTECODE => [
                "Há arquivos .pyc / __pycache__ rastreados pelo git em um dos worktrees. Toda execução Python regenera esses bytes e marca o worktree dirty. Remova com `git rm --cached -r '*.pyc' '*.pyo' '*__pycache__*'` e commit a limpeza antes de retomar.",
            ],
            self::STATE_BLOCKED_TRIAGE_REQUIRED => [
                'Este fingerprint já registrou uma bateria invalid no passado e ainda não foi triado. Rode `php artisan atlas:engineering:benchmark:rivals triage-invalid-battery --fingerprint=<fp> --confirm-invalid-battery-quarantine --reason="..."` antes de tentar de novo.',
            ],
            self::STATE_BLOCKED_OPERATOR_CONFIRMATION_REQUIRED => [
                'Workspace + preflight + dry-run estão OK, mas faltam confirmações explícitas do operador (--confirm-runbook-reviewed, --confirm-provider-cost, --confirm-real-provider-call). Sem as três o runner não chama provider real.',
            ],
            self::STATE_BLOCKED_PROVIDER_UNAVAILABLE => [
                'Um ou mais services necessários (preflight, dry-run, evidence verifier, run log stream, hygiene) falharam ao responder. Veja `blocking_reasons` no snapshot para identificar quem caiu.',
            ],
            self::STATE_REPLAY_READY => [
                'Há eventos de replay (provider_done / evidence_pack / final_report) no stream e o evidence pack carrega `replay_manifest.present=true`. Pode replicar a execução com o replay manifest.',
            ],
            self::STATE_REPLAY_FAILED => [
                'O stream contém eventos de provider/encerramento, mas o evidence pack não traz replay manifest válido — replay não é reprodutível. Trate como invalid battery e abra triage.',
            ],
            default => ['Estado desconhecido: '.$state],
        };
    }

    /**
     * @param  list<string>  $blockingReasons
     */
    private function decideState(
        bool $atlasExists,
        bool $baselineExists,
        bool $workspacesClean,
        bool $trackedBytecodeBlocked,
        bool $triagePending,
        string $preflightStatus,
        string $dryRunStatus,
        bool $allConfirmationsTrue,
        bool $runHasRecentHeartbeat,
        bool $runHasFinalReport,
        bool $evidencePackPresent,
        bool $evidencePackComplete,
        bool $afterCleanCheckOk,
        bool $replayEventsPresent,
        bool $replayPackPresent,
        ?string $resolvedRunId,
        array &$blockingReasons,
    ): string {
        // Provider/service collapse beats every other state — surface it first
        // so the operator does not chase phantom blockers.
        $serviceUnavailable = false;
        foreach ($blockingReasons as $reason) {
            if (str_ends_with($reason, '_unavailable')) {
                $serviceUnavailable = true;
                break;
            }
        }
        if ($serviceUnavailable) {
            return self::STATE_BLOCKED_PROVIDER_UNAVAILABLE;
        }

        if (! $atlasExists) {
            $blockingReasons[] = 'atlas_worktree_missing';

            return self::STATE_NO_WORKSPACE;
        }

        if ($trackedBytecodeBlocked) {
            $blockingReasons[] = 'tracked_python_bytecode_in_workspace';

            return self::STATE_BLOCKED_TRACKED_BYTECODE;
        }

        if (! $workspacesClean) {
            $blockingReasons[] = 'dirty_workspace';

            return self::STATE_BLOCKED_DIRTY_WORKSPACE;
        }

        if ($triagePending) {
            $blockingReasons[] = 'fingerprint_pending_triage';

            return self::STATE_BLOCKED_TRIAGE_REQUIRED;
        }

        // Run-level signals: if a run finished (final_report or evidence pack
        // produced) we resolve completion BEFORE evaluating dispatch readiness.
        if ($resolvedRunId !== null && $runHasFinalReport) {
            if ($evidencePackPresent && $evidencePackComplete && $afterCleanCheckOk) {
                return self::STATE_COMPLETED_VALID;
            }

            // Final report fired but evidence pack missing/incomplete OR
            // workspace dirty after run -> invalid battery.
            $blockingReasons[] = 'evidence_pack_incomplete_or_missing';

            // If we have replay events without a valid replay pack, the more
            // specific REPLAY_FAILED state is more actionable than the generic
            // COMPLETED_INVALID.
            if ($replayEventsPresent && ! $replayPackPresent) {
                return self::STATE_REPLAY_FAILED;
            }

            return self::STATE_COMPLETED_INVALID;
        }

        // Run alive (heartbeat fresh) overrides preflight/dry-run gating.
        if ($resolvedRunId !== null && $runHasRecentHeartbeat) {
            return self::STATE_RUNNING;
        }

        // Replay surface: events exist, pack exists, no fresh run -> replay
        // ready / failed.
        if ($replayEventsPresent && $replayPackPresent && $evidencePackPresent) {
            return self::STATE_REPLAY_READY;
        }
        if ($replayEventsPresent && ! $replayPackPresent) {
            $blockingReasons[] = 'replay_events_without_replay_pack';

            return self::STATE_REPLAY_FAILED;
        }

        // Preflight gating ---------------------------------------------------
        if ($preflightStatus === 'skipped' || $preflightStatus === 'unknown' || $preflightStatus === '') {
            return self::STATE_WORKTREES_READY;
        }
        if (! in_array($preflightStatus, ['ready_for_dry_run', 'ready_for_provider_battery'], true)) {
            $blockingReasons[] = 'preflight_'.$preflightStatus;

            return self::STATE_WORKTREES_READY;
        }

        // Dry-run gating -----------------------------------------------------
        if ($dryRunStatus === 'skipped' || $dryRunStatus === '') {
            return self::STATE_PREFLIGHT_READY;
        }
        if ($dryRunStatus !== 'dry_run_passed') {
            $blockingReasons[] = 'dry_run_'.$dryRunStatus;

            return self::STATE_PREFLIGHT_READY;
        }

        // Dry-run passed, evaluate operator confirmations. Honest framing:
        // missing any of the three confirmations blocks dispatch. The state
        // surfaces that explicitly so the operator never sees a misleading
        // "ready" signal while a switch is still off.
        if (! $allConfirmationsTrue) {
            $blockingReasons[] = 'operator_confirmations_missing';

            return self::STATE_BLOCKED_OPERATOR_CONFIRMATION_REQUIRED;
        }

        return self::STATE_READY_FOR_REAL_PROVIDER;
    }

    /**
     * @param  array<string,mixed>  $context
     * @return list<array<string,string>>
     */
    private function nextActions(string $state, array $context): array
    {
        $atlas = (string) ($context['atlas_worktree'] ?? '');
        $baseline = (string) ($context['baseline_worktree'] ?? '');
        $fingerprint = (string) ($context['fingerprint'] ?? '');

        return match ($state) {
            self::STATE_NO_WORKSPACE => [[
                'kind' => 'command',
                'label' => 'Crie os worktrees Atlas e baseline.',
                'command' => 'git worktree add <atlas_worktree_path> <ref> && git worktree add <baseline_worktree_path> <ref>',
            ]],
            self::STATE_BLOCKED_DIRTY_WORKSPACE => [[
                'kind' => 'command',
                'label' => 'Comite ou stash mudanças no worktree antes de continuar.',
                'command' => sprintf('git -C %s status && git -C %s stash --include-untracked', escapeshellarg($atlas), escapeshellarg($atlas)),
            ]],
            self::STATE_BLOCKED_TRACKED_BYTECODE => [[
                'kind' => 'command',
                'label' => 'Remova bytecode Python rastreado e commit a limpeza.',
                'command' => sprintf("git -C %s rm --cached -r 'runtimes/python/**/__pycache__' '*.pyc' '*.pyo' && git -C %s commit -m 'chore: untrack python bytecode'", escapeshellarg($atlas), escapeshellarg($atlas)),
            ]],
            self::STATE_BLOCKED_TRIAGE_REQUIRED => [[
                'kind' => 'command',
                'label' => 'Triage o fingerprint historicamente inválido antes de retomar.',
                'command' => sprintf('php artisan atlas:engineering:benchmark:rivals triage-invalid-battery --fingerprint=%s --confirm-invalid-battery-quarantine --reason="<por que está quarentenado>"', $fingerprint),
            ]],
            self::STATE_WORKTREES_READY => [[
                'kind' => 'command',
                'label' => 'Rode o preflight diagnostic-only.',
                'command' => 'php artisan atlas:programming:rivals-forge-preflight --json --strict',
            ]],
            self::STATE_PREFLIGHT_READY => [[
                'kind' => 'command',
                'label' => 'Rode o dry-run para planejar replay manifest sem gastar provider.',
                'command' => 'php artisan atlas:programming:rivals-forge-dry-run --json --strict',
            ]],
            self::STATE_DRY_RUN_PASSED => [[
                'kind' => 'command',
                'label' => 'Revise o runbook e dê as três confirmações explícitas.',
                'command' => 'php artisan atlas:engineering:benchmark:rivals runbook --confirm-runbook-reviewed --confirm-provider-cost --confirm-real-provider-call',
            ]],
            self::STATE_BLOCKED_OPERATOR_CONFIRMATION_REQUIRED => [[
                'kind' => 'command',
                'label' => 'Forneça as três confirmações de operador antes de despachar provider real.',
                'command' => 'php artisan atlas:engineering:benchmark:rivals run --confirm-runbook-reviewed --confirm-provider-cost --confirm-real-provider-call',
            ]],
            self::STATE_READY_FOR_REAL_PROVIDER => [[
                'kind' => 'command',
                'label' => 'Despache o runner real (provider call será cobrado).',
                'command' => 'php artisan atlas:engineering:benchmark:rivals run --execute --confirm-runbook-reviewed --confirm-provider-cost --confirm-real-provider-call',
            ]],
            self::STATE_RUNNING => [[
                'kind' => 'command',
                'label' => 'Tail o stream de eventos do run em andamento.',
                'command' => 'php artisan atlas:engineering:benchmark:rivals tail --follow',
            ]],
            self::STATE_COMPLETED_VALID => [[
                'kind' => 'command',
                'label' => 'Arquive evidence pack no ledger.',
                'command' => 'php artisan atlas:engineering:benchmark:rivals archive --latest',
            ]],
            self::STATE_COMPLETED_INVALID => [[
                'kind' => 'command',
                'label' => 'Abra triage para o fingerprint antes de retomar.',
                'command' => sprintf('php artisan atlas:engineering:benchmark:rivals triage-invalid-battery --fingerprint=%s --confirm-invalid-battery-quarantine --reason="<motivo>"', $fingerprint),
            ]],
            self::STATE_REPLAY_READY => [[
                'kind' => 'command',
                'label' => 'Replique o run a partir do replay manifest.',
                'command' => 'php artisan atlas:engineering:benchmark:rivals replay --latest',
            ]],
            self::STATE_REPLAY_FAILED => [[
                'kind' => 'command',
                'label' => 'Replay não é reprodutível — triage como invalid battery.',
                'command' => sprintf('php artisan atlas:engineering:benchmark:rivals triage-invalid-battery --fingerprint=%s --confirm-invalid-battery-quarantine --reason="replay_failed"', $fingerprint),
            ]],
            self::STATE_BLOCKED_PROVIDER_UNAVAILABLE => [[
                'kind' => 'command',
                'label' => 'Rode o doctor para identificar qual service caiu.',
                'command' => 'php artisan atlas:engineering:benchmark:rivals doctor',
            ]],
            default => [],
        };
    }

    /**
     * Read latest evidence pack JSON associated with $runId, if discoverable.
     * Tries `storage/app/rivals-forge-runs/<runId>/evidence_pack.json` and
     * `evidence_pack/pack.json` shapes; returns null on any failure.
     *
     * @return array<string,mixed>|null
     */
    private function loadLatestEvidencePack(?string $runId, ?string &$pathOut): ?array
    {
        $pathOut = null;
        if (! is_string($runId) || $runId === '') {
            return null;
        }
        try {
            $dir = $this->runLogStream->runDirectory($runId);
        } catch (Throwable) {
            return null;
        }
        $candidates = [
            $dir.DIRECTORY_SEPARATOR.'evidence_pack.json',
            $dir.DIRECTORY_SEPARATOR.'evidence_pack'.DIRECTORY_SEPARATOR.'pack.json',
        ];
        foreach ($candidates as $candidate) {
            if (! is_file($candidate)) {
                continue;
            }
            $blob = @file_get_contents($candidate);
            if (! is_string($blob) || trim($blob) === '') {
                continue;
            }
            $decoded = json_decode($blob, true);
            if (is_array($decoded)) {
                $pathOut = $candidate;

                return $decoded;
            }
        }

        return null;
    }

    private function normalizeString(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }
        $trimmed = trim($raw);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return array{status:string, error?:string}
     */
    private function probeService(callable $probe): array
    {
        try {
            $value = $probe();
            if (is_string($value) && $value !== '') {
                return ['status' => 'ok'];
            }

            return ['status' => 'degraded'];
        } catch (Throwable $e) {
            return ['status' => 'unavailable', 'error' => $e->getMessage()];
        }
    }
}
