<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;

/**
 * AP-786 real cycle certification + replay audit.
 *
 * Proves whether an AP-786 autonomous evolution session ran genuine, full
 * owner-flow cycles, or whether it only produced a legacy direct-provider-driver
 * (diagnostic) result that must never be claimed as full Atlas Forge / Atlas Dev.
 *
 * This is deliberately a JUDGE, not an executor. It is read-only and
 * deterministic: it never runs git, providers, merges, schedulers or owner
 * runtime. It reads an AP-786 session report (passed inline or replayed from the
 * append-only JSONL the session service wrote) and renders, per cycle, exactly
 * which required owner-flow stage is missing and whether the cycle is a real
 * full-Forge cycle or a fake/incomplete one.
 */
final class Ap786RealCycleCertificationService
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.ap786_real_cycle_certification.v1';

    public const STATUS_CERTIFIED = 'certified_real_cycle';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_BLOCKED = 'blocked_fake_or_incomplete';

    public const DEFAULT_AREA_ID = 'agentic_engineering_os';

    public const DEFAULT_MIN_REAL_CYCLES = 3;

    private const MAX_SESSION_JSONL_LINE_BYTES = 1048576;

    /**
     * The owner-flow chain every real cycle must prove before its work can be
     * claimed as full Atlas Forge / Atlas Dev execution.
     *
     * @var list<string>
     */
    private const REQUIRED_OWNER_FLOW_STAGES = ['AP-756', 'AP-747', 'AP-757', 'AP-749', 'AP-758', 'AP-759', 'AP-750'];

    /**
     * Stage statuses accepted as "ready/recorded" per the AP-786 contract.
     *
     * @var list<string>
     */
    private const SATISFIED_STAGE_STATUSES = ['ready', 'recorded', 'materialized', 'merged', 'passed', 'completed', 'done'];

    private ?string $sessionsDirOverride = null;

    public function setSessionsDirForTesting(?string $dir): void
    {
        $this->sessionsDirOverride = $dir !== null ? rtrim($dir, DIRECTORY_SEPARATOR) : null;
    }

    /**
     * Mirrors AutonomousEvolutionSessionService::storageDir() so replay reads the
     * exact JSONL the AP-786 session writes.
     */
    public function sessionsDir(): string
    {
        if ($this->sessionsDirOverride !== null) {
            return $this->sessionsDirOverride;
        }

        return function_exists('storage_path')
            ? storage_path('atlas/software_company_stewardship/autonomous_evolution_sessions')
            : sys_get_temp_dir().'/atlas/software_company_stewardship/autonomous_evolution_sessions';
    }

    public function sessionRecordPath(string $areaId): string
    {
        return $this->sessionsDir().DIRECTORY_SEPARATOR.AreaFocusSlugNormalizer::lowerFileToken($areaId, self::DEFAULT_AREA_ID).'.jsonl';
    }

    /**
     * Replay a recorded AP-786 session by id and certify it.
     *
     * @return array<string,mixed>
     */
    public function replay(string $sessionId, ?string $areaId = null): array
    {
        return $this->certify([
            'session_id' => $sessionId,
            'area_id' => $areaId ?? self::DEFAULT_AREA_ID,
        ]);
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function certify(array $input = []): array
    {
        $minReal = max(1, (int) ($input['min_real_cycles'] ?? self::DEFAULT_MIN_REAL_CYCLES));
        $sessionId = trim((string) ($input['session_id'] ?? ''));
        $areaHint = AreaFocusSlugNormalizer::lowerFileToken((string) ($input['area_id'] ?? self::DEFAULT_AREA_ID), self::DEFAULT_AREA_ID);

        $source = 'inline_session_report';
        $session = is_array($input['session_report'] ?? null) ? $input['session_report'] : null;
        $loadedFromJsonl = false;

        if ($session === null && $sessionId !== '') {
            $session = $this->loadSession($areaHint, $sessionId);
            $source = 'replayed_from_jsonl';
            $loadedFromJsonl = true;
            if ($session === null) {
                return $this->blockedTop('session_record_not_found', "No recorded AP-786 session '{$sessionId}' for area '{$areaHint}'.", [
                    'session_id' => $sessionId,
                    'area_id' => $areaHint,
                    'source' => $source,
                    'min_real_cycles_required' => $minReal,
                ]);
            }
        }

        if ($session === null) {
            return $this->blockedTop('session_report_required', 'Pass session_report (inline) or session_id (replay) to certify an AP-786 session.', [
                'source' => $source,
                'min_real_cycles_required' => $minReal,
            ]);
        }

        if ((string) ($session['ap_contract'] ?? '') !== 'AP-786'
            || ! in_array((string) ($session['schema_version'] ?? ''), [
                AutonomousEvolutionSessionService::REPORT_SCHEMA,
                AutonomousEvolutionSessionService::RECORD_SCHEMA,
            ], true)) {
            return $this->blockedTop('not_an_ap786_session', 'Record is not an AP-786 autonomous evolution session report.', [
                'source' => $source,
                'session_id' => (string) ($session['session_id'] ?? ''),
                'min_real_cycles_required' => $minReal,
            ]);
        }

        $areaId = AreaFocusSlugNormalizer::lowerFileToken((string) ($session['area_id'] ?? $areaHint), self::DEFAULT_AREA_ID);
        $sessionId = $sessionId !== '' ? $sessionId : (string) ($session['session_id'] ?? '');
        $sessionDirectAllowed = (bool) data_get($session, 'claim_policy.direct_provider_driver_allowed', false);
        $replayable = $loadedFromJsonl || $this->sessionReplayable($session, $sessionId, $areaId);

        $cyclesIn = AreaFocusLoopPayloadNormalizer::listOfArrays($session['cycles'] ?? []);
        $seenFindingKeys = [];
        $seenCommitTitles = [];
        $cycleReports = [];

        foreach ($cyclesIn as $offset => $cycle) {
            $report = $this->certifyCycle($cycle, $offset + 1, $sessionDirectAllowed, $replayable, $seenFindingKeys, $seenCommitTitles);

            // Register keys AFTER evaluating duplicates so the first occurrence is
            // allowed and only later repeats are flagged as wasted/duplicated.
            foreach ($report['_finding_keys'] as $key) {
                $seenFindingKeys[$key] = ($seenFindingKeys[$key] ?? 0) + 1;
            }
            foreach ($report['_commit_titles'] as $title) {
                $seenCommitTitles[$title] = ($seenCommitTitles[$title] ?? 0) + 1;
            }
            unset($report['_finding_keys'], $report['_commit_titles']);

            $cycleReports[] = $report;
        }

        $certifiedCount = $this->countByStatus($cycleReports, self::STATUS_CERTIFIED);
        $partialCount = $this->countByStatus($cycleReports, self::STATUS_PARTIAL);
        $blockedCount = $this->countByStatus($cycleReports, self::STATUS_BLOCKED);

        if ($sessionDirectAllowed) {
            $status = self::STATUS_BLOCKED;
        } elseif ($certifiedCount >= $minReal) {
            $status = self::STATUS_CERTIFIED;
        } elseif ($certifiedCount > 0) {
            $status = self::STATUS_PARTIAL;
        } else {
            $status = self::STATUS_BLOCKED;
        }

        $threeCycleAudit = [
            'min_real_cycles_required' => $minReal,
            'certified_real_cycles' => $certifiedCount,
            'partial_cycles' => $partialCount,
            'blocked_cycles' => $blockedCount,
            'attempted_cycles' => count($cycleReports),
            'satisfied' => $certifiedCount >= $minReal,
            'missing_real_cycles' => max(0, $minReal - $certifiedCount),
            'per_cycle' => array_map(static fn (array $c): array => [
                'cycle_index' => $c['cycle_index'],
                'cycle_id' => $c['cycle_id'],
                'status' => $c['status'],
                'missing_stages' => $c['missing_stages'],
                'fake_signals' => $c['fake_signals'],
            ], $cycleReports),
        ];

        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-786',
            'status' => $status,
            'session_id' => $sessionId,
            'area_id' => $areaId,
            'focus' => (string) ($session['focus'] ?? ''),
            'source' => $source,
            'session_replayable_from_jsonl' => $replayable,
            'session_direct_provider_driver_allowed' => $sessionDirectAllowed,
            'session_status' => (string) ($session['status'] ?? ''),
            'three_cycle_audit' => $threeCycleAudit,
            'cycles' => $cycleReports,
            'operator_truth' => $this->operatorTruth($status, $sessionDirectAllowed, $certifiedCount, $minReal, $replayable),
            'next_safe_command' => $this->nextSafeCommand($status, $sessionDirectAllowed, $areaId),
            'source_ap_contracts' => ['AP-747', 'AP-749', 'AP-750', 'AP-756', 'AP-757', 'AP-758', 'AP-759', 'AP-769', 'AP-774', 'AP-786'],
            'claim_policy' => [
                'read_only' => true,
                'mutates_refs' => false,
                'mutates_worktrees' => false,
                'invokes_provider' => false,
                'starts_scheduler' => false,
                'performs_merge' => false,
                'fabricates_receipts' => false,
                'direct_provider_driver_counts_as_real' => false,
                'deferred_counted_as_done' => false,
            ],
        ];

        return $this->finalize($payload);
    }

    /**
     * @param  array<string,mixed>  $cycle
     * @param  array<string,int>  $seenFindingKeys
     * @param  array<string,int>  $seenCommitTitles
     * @return array<string,mixed>
     */
    private function certifyCycle(array $cycle, int $index, bool $sessionDirectAllowed, bool $replayable, array $seenFindingKeys, array $seenCommitTitles): array
    {
        $cycleId = (string) ($cycle['cycle_id'] ?? '');
        $finalStatus = (string) ($cycle['final_status'] ?? '');
        $findingId = $this->findingId($cycle);
        $findingTitle = $this->findingTitle($cycle);
        $findingKeys = $this->findingKeys($cycle);
        $commitTitle = $this->commitTitle($cycle);

        $fakeSignals = [];
        $missing = [];
        $incomplete = [];

        // --- Fake / not-real signals (any one of these blocks the cycle) ---
        if ($sessionDirectAllowed) {
            $fakeSignals[] = 'session_direct_provider_driver_allowed';
        }
        if ((bool) data_get($cycle, 'flow_integrity_gate.direct_provider_driver_allowed', false) === true) {
            $fakeSignals[] = 'cycle_direct_provider_driver_allowed';
        }
        if ((bool) data_get($cycle, 'flow_integrity_gate.direct_provider_driver_path', false) === true) {
            $fakeSignals[] = 'provider_router_direct_path';
        }
        $gate = is_array($cycle['flow_integrity_gate'] ?? null) ? $cycle['flow_integrity_gate'] : null;
        if ($gate !== null && (bool) ($gate['uses_full_owner_runtime_chain'] ?? false) !== true) {
            $fakeSignals[] = 'not_full_owner_runtime_chain';
        }
        if (in_array($finalStatus, ['', 'dry_run_planned', 'blocked'], true)) {
            $fakeSignals[] = 'cycle_not_executed_to_completion';
        }
        foreach ($findingKeys as $key) {
            if (isset($seenFindingKeys[$key])) {
                $fakeSignals[] = 'duplicated_finding_from_previous_cycle';
                break;
            }
        }
        if ($commitTitle !== '' && isset($seenCommitTitles[$commitTitle])) {
            $fakeSignals[] = 'duplicated_commit_title_from_previous_cycle';
        }
        // Forge provider-proof (SEC-001): a forge cycle reporting changed files
        // with zero provider calls is unattributed — a false-merge signal, never
        // a real cycle. Conservative: only fires when both signals are present in
        // the record, so it never blocks a cycle for missing telemetry.
        $cycleOwner = strtolower((string) (data_get($cycle, 'owner')
            ?? data_get($cycle, 'target_owner')
            ?? data_get($cycle, 'owner_result.target_owner') ?? ''));
        $cycleChangedFiles = (array) (data_get($cycle, 'changed_files')
            ?? data_get($cycle, 'owner_result.changed_files') ?? []);
        $cycleProviderCalls = (int) (data_get($cycle, 'owner_result.runtime_invocation.command_result.owner_cli_provider_calls')
            ?? data_get($cycle, 'runtime_invocation.command_result.owner_cli_provider_calls') ?? 0);
        if ($cycleOwner === 'forge' && $cycleChangedFiles !== [] && $cycleProviderCalls <= 0) {
            $fakeSignals[] = 'forge_diff_without_provider_proof';
        }

        // --- Required identity ---
        if ($cycleId === '') {
            $missing[] = 'cycle_id';
        }
        if ($findingId === '' && $findingTitle === '') {
            $missing[] = 'selected_finding';
        }

        // --- Owner-flow chain (AP-756 -> AP-747 -> AP-757 -> AP-749 -> AP-758 -> AP-759 -> AP-750) ---
        $stages = [];
        foreach (self::REQUIRED_OWNER_FLOW_STAGES as $ap) {
            $stage = $this->ownerFlowStage($cycle, $ap);
            $stages[$ap] = $stage;
            if (! $stage['present']) {
                $missing[] = $ap.'_stage_missing';
            } elseif (! $stage['satisfied']) {
                $missing[] = $ap.'_stage_not_ready_or_recorded';
            }
        }

        // --- Evidence / Inbox emitted ---
        $evidence = $this->evidenceProof($cycle);
        if (! $evidence['present']) {
            $missing[] = 'evidence_or_inbox_not_emitted';
        }

        // --- Validation commands present and passed ---
        $validation = $this->validationProof($cycle);
        if (! $validation['commands_present']) {
            $missing[] = 'validation_commands_missing';
        } elseif ($validation['passed'] !== true) {
            $missing[] = 'validation_not_passed';
        }

        // --- Merge governance result present (AP-769/AP-774) ---
        $merge = $this->mergeGovernanceProof($cycle);
        if (! $merge['present']) {
            $missing[] = 'merge_governance_result_missing';
        } elseif (! $merge['merged']) {
            // A governed decision to hold for human review is honest, real work that
            // is simply not merged yet — that downgrades to partial, not fake.
            $incomplete[] = 'merge_governed_but_held_for_review';
        }

        // --- Branch / worktree isolation ---
        $isolation = $this->isolationProof($cycle);
        if (! $isolation['present']) {
            $missing[] = 'branch_worktree_isolation_missing';
        }

        // --- Replayable from JSONL ---
        if (! $replayable) {
            $missing[] = 'session_not_replayable_from_jsonl';
        }

        if ($fakeSignals !== [] || $missing !== []) {
            $status = self::STATUS_BLOCKED;
        } elseif ($incomplete !== []) {
            $status = self::STATUS_PARTIAL;
        } else {
            $status = self::STATUS_CERTIFIED;
        }

        return [
            'cycle_index' => $index,
            'cycle_id' => $cycleId,
            'status' => $status,
            'final_status' => $finalStatus,
            'selected_finding' => ['finding_id' => $findingId, 'title' => $findingTitle],
            'owner_flow_stages' => $stages,
            'evidence' => $evidence,
            'validation' => $validation,
            'merge_governance' => $merge,
            'isolation' => $isolation,
            'fake_signals' => AreaFocusStringListNormalizer::uniqueStringValues($fakeSignals),
            'missing_stages' => AreaFocusStringListNormalizer::uniqueStringValues($missing),
            'incomplete_stages' => AreaFocusStringListNormalizer::uniqueStringValues($incomplete),
            '_finding_keys' => $findingKeys,
            '_commit_titles' => $commitTitle !== '' ? [$commitTitle] : [],
        ];
    }

    /**
     * Resolve an owner-flow stage from a cycle.
     *
     * Canonical source is `cycle.owner_flow.<AP>` (a status string or
     * {status,id} map) that the owner-flow integrator emits. When that is
     * absent it falls back to the known cycle fields so a session recorded
     * before the integrator landed is judged on whatever evidence it carries.
     *
     * @param  array<string,mixed>  $cycle
     * @return array{present:bool,satisfied:bool,status:string,id:string,source:string}
     */
    private function ownerFlowStage(array $cycle, string $ap): array
    {
        $map = data_get($cycle, 'owner_flow');
        if (is_array($map) && array_key_exists($ap, $map)) {
            return $this->stageFromEntry($map[$ap], 'owner_flow');
        }

        return match ($ap) {
            'AP-756' => $this->stageFromSandbox($cycle),
            'AP-747' => $this->stageFromFields($cycle, ['release', 'ap747_release', 'release_record'], ['release_id', 'queue_record_id']),
            'AP-757' => $this->stageFromFields($cycle, ['sandbox_binding', 'ap757_binding', 'queue_sandbox_binding'], ['binding_id']),
            'AP-749' => $this->stageFromFields($cycle, ['consumption', 'ap749_consumption', 'queue_consumption'], ['consumption_id']),
            'AP-758' => $this->stageFromFields($cycle, ['execution', 'ap758_execution', 'owner_runtime_execution'], ['execution_id', 'owner_execution_id']),
            'AP-759' => $this->stageFromFields($cycle, ['owner_run', 'owner_sandbox_run', 'ap759_owner_run'], ['owner_run_id', 'run_id']),
            'AP-750' => $this->stageFromResultBridge($cycle),
            default => ['present' => false, 'satisfied' => false, 'status' => '', 'id' => '', 'source' => 'unknown'],
        };
    }

    /**
     * @return array{present:bool,satisfied:bool,status:string,id:string,source:string}
     */
    private function stageFromEntry(mixed $entry, string $source): array
    {
        if (is_string($entry)) {
            return [
                'present' => $entry !== '',
                'satisfied' => in_array($entry, self::SATISFIED_STAGE_STATUSES, true),
                'status' => $entry,
                'id' => '',
                'source' => $source,
            ];
        }
        if (is_array($entry)) {
            $statusValue = (string) ($entry['status'] ?? '');
            $id = (string) ($entry['id'] ?? $entry['record_id'] ?? $entry['receipt_id'] ?? '');

            return [
                'present' => $statusValue !== '' || $id !== '',
                'satisfied' => in_array($statusValue, self::SATISFIED_STAGE_STATUSES, true),
                'status' => $statusValue,
                'id' => $id,
                'source' => $source,
            ];
        }

        return ['present' => false, 'satisfied' => false, 'status' => '', 'id' => '', 'source' => $source];
    }

    /**
     * @param  array<string,mixed>  $cycle
     * @param  list<string>  $blockKeys
     * @param  list<string>  $idKeys
     * @return array{present:bool,satisfied:bool,status:string,id:string,source:string}
     */
    private function stageFromFields(array $cycle, array $blockKeys, array $idKeys): array
    {
        foreach ($blockKeys as $key) {
            if (is_array($cycle[$key] ?? null)) {
                $stage = $this->stageFromEntry($cycle[$key], 'cycle.'.$key);
                if ($stage['present']) {
                    return $stage;
                }
            }
        }
        foreach ($idKeys as $key) {
            $id = (string) ($cycle[$key] ?? '');
            if ($id !== '') {
                return ['present' => true, 'satisfied' => true, 'status' => 'recorded', 'id' => $id, 'source' => 'cycle.'.$key];
            }
        }

        return ['present' => false, 'satisfied' => false, 'status' => '', 'id' => '', 'source' => 'absent'];
    }

    /**
     * @param  array<string,mixed>  $cycle
     * @return array{present:bool,satisfied:bool,status:string,id:string,source:string}
     */
    private function stageFromSandbox(array $cycle): array
    {
        $sandboxStatus = (string) data_get($cycle, 'sandbox.status', '');
        $sandboxId = (string) ($cycle['sandbox_id'] ?? data_get($cycle, 'sandbox.sandbox_id', ''));
        $branchCreated = (bool) ($cycle['branch_created'] ?? data_get($cycle, 'sandbox.materialization.branch_created', false));
        $worktreeCreated = (bool) ($cycle['worktree_created'] ?? data_get($cycle, 'sandbox.materialization.worktree_created', false));

        $materialized = $sandboxStatus === AreaFocusBranchSandboxMaterializerService::STATUS_MATERIALIZED
            || ($sandboxId !== '' && $branchCreated && $worktreeCreated);

        return [
            'present' => $sandboxId !== '' || $sandboxStatus !== '',
            'satisfied' => $materialized,
            'status' => $materialized ? 'materialized' : ($sandboxStatus ?: 'unmaterialized'),
            'id' => $sandboxId,
            'source' => 'cycle.sandbox',
        ];
    }

    /**
     * @param  array<string,mixed>  $cycle
     * @return array{present:bool,satisfied:bool,status:string,id:string,source:string}
     */
    private function stageFromResultBridge(array $cycle): array
    {
        $id = (string) ($cycle['result_bridge_id'] ?? '');
        if ($id !== '') {
            return ['present' => true, 'satisfied' => true, 'status' => 'recorded', 'id' => $id, 'source' => 'cycle.result_bridge_id'];
        }

        return ['present' => false, 'satisfied' => false, 'status' => '', 'id' => '', 'source' => 'absent'];
    }

    /**
     * @param  array<string,mixed>  $cycle
     * @return array{present:bool,inbox_item_id:string,result_bridge_id:string}
     */
    private function evidenceProof(array $cycle): array
    {
        $inboxId = (string) ($cycle['inbox_item_id'] ?? data_get($cycle, 'owner_flow.AP-765.inbox_item_id', ''));
        $resultBridgeId = (string) ($cycle['result_bridge_id'] ?? '');

        return [
            'present' => $inboxId !== '' || $resultBridgeId !== '',
            'inbox_item_id' => $inboxId,
            'result_bridge_id' => $resultBridgeId,
        ];
    }

    /**
     * @param  array<string,mixed>  $cycle
     * @return array{commands_present:bool,passed:bool|null,command_count:int}
     */
    private function validationProof(array $cycle): array
    {
        $commands = (array) data_get($cycle, 'validation.commands', []);
        $results = (array) data_get($cycle, 'validation.results', []);
        $count = max(count($commands), count($results));
        $passedRaw = data_get($cycle, 'validation.passed');

        return [
            'commands_present' => $count > 0,
            'passed' => is_bool($passedRaw) ? $passedRaw : null,
            'command_count' => $count,
        ];
    }

    /**
     * @param  array<string,mixed>  $cycle
     * @return array{present:bool,status:string,merged:bool}
     */
    private function mergeGovernanceProof(array $cycle): array
    {
        $merge = is_array($cycle['merge_governance'] ?? null) ? $cycle['merge_governance'] : [];
        $status = (string) ($merge['status'] ?? '');

        return [
            'present' => $status !== '',
            'status' => $status,
            'merged' => $status === 'merged' || (bool) ($cycle['merge_performed'] ?? false) === true,
        ];
    }

    /**
     * @param  array<string,mixed>  $cycle
     * @return array{present:bool,branch_ref:string,worktree_path:string}
     */
    private function isolationProof(array $cycle): array
    {
        $branch = (string) ($cycle['branch_ref'] ?? data_get($cycle, 'owner_flow.AP-756.branch', data_get($cycle, 'sandbox.materialization.branch_name', '')));
        $worktree = (string) ($cycle['worktree_path'] ?? data_get($cycle, 'owner_flow.AP-756.worktree', data_get($cycle, 'sandbox.materialization.worktree_path', '')));

        return [
            'present' => $branch !== '' && $worktree !== '',
            'branch_ref' => $branch,
            'worktree_path' => $worktree,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadSession(string $areaId, string $sessionId): ?array
    {
        $path = $this->sessionRecordPath($areaId);
        if ($sessionId === '' || ! is_file($path)) {
            return null;
        }
        $match = null;
        foreach ($this->sessionRecordLines($path) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded) && (string) ($decoded['session_id'] ?? '') === $sessionId) {
                $match = $decoded; // last write wins
            }
        }

        return $match;
    }

    /**
     * @return \Generator<int,string>
     */
    private function sessionRecordLines(string $path): \Generator
    {
        $handle = fopen($path, 'rb');
        if (! is_resource($handle)) {
            return;
        }

        try {
            while (($line = fgets($handle, self::MAX_SESSION_JSONL_LINE_BYTES + 1)) !== false) {
                if ($line !== '' && ! str_ends_with($line, "\n") && ! feof($handle)) {
                    while (($chunk = fgets($handle, self::MAX_SESSION_JSONL_LINE_BYTES + 1)) !== false) {
                        if (str_ends_with($chunk, "\n") || feof($handle)) {
                            break;
                        }
                    }

                    continue;
                }

                $line = trim($line);
                if ($line !== '') {
                    yield $line;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array<string,mixed>  $session
     */
    private function sessionReplayable(array $session, string $sessionId, string $areaId): bool
    {
        if ((string) ($session['schema_version'] ?? '') === AutonomousEvolutionSessionService::RECORD_SCHEMA) {
            return true;
        }
        if (in_array((string) ($session['session_storage_status'] ?? ''), ['recorded', 'existing'], true)) {
            return true;
        }
        if ($sessionId !== '' && $this->loadSession($areaId, $sessionId) !== null) {
            return true;
        }

        return false;
    }

    private function operatorTruth(string $status, bool $directAllowed, int $certified, int $minReal, bool $replayable): string
    {
        if ($directAllowed) {
            return 'BLOCKED: this session ran with the legacy direct-provider driver allowed. It is diagnostic evidence only and must never be claimed as real Atlas Forge/Dev cycles. Wire the full owner-flow chain before certifying.';
        }
        if (! $replayable) {
            return 'BLOCKED: this session is not recorded to JSONL, so it cannot be replayed/audited later. Re-run with --record before trusting any cycle as real.';
        }
        if ($status === self::STATUS_CERTIFIED) {
            return "CERTIFIED: {$certified} real full-owner-flow cycle(s) proven (>= {$minReal} required). The first three real cycles are honest; the 24h autonomous run can be unlocked.";
        }
        if ($status === self::STATUS_PARTIAL) {
            return "PARTIAL: only {$certified}/{$minReal} real cycles are fully proven. Do not leave this running 24h until the remaining cycles prove the full owner-flow chain.";
        }

        return 'BLOCKED: no cycle proved a real full owner-flow chain. See per-cycle missing_stages and fake_signals for exactly what is missing.';
    }

    private function nextSafeCommand(string $status, bool $directAllowed, string $areaId): ?string
    {
        if ($directAllowed) {
            // The only safe next step is to fix the flow, not to run more automation.
            return null;
        }

        $base = 'php artisan atlas:software-company-stewardship:autonomous-evolution-session --area='.$areaId.' --focus=dev_forge';

        if ($status === self::STATUS_CERTIFIED) {
            return $base.' --cycles=3 --execute --record'
                .' --validation-command="git diff --check"'
                .' --validation-command="php artisan test tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionServiceTest.php"'
                .' --json';
        }

        // Not yet proven: only a dry-run (no --execute) is safe to suggest.
        return $base.' --cycles=3 --record --json';
    }

    /**
     * @param  list<array<string,mixed>>  $cycleReports
     */
    private function countByStatus(array $cycleReports, string $status): int
    {
        return count(array_filter($cycleReports, static fn (array $c): bool => ($c['status'] ?? '') === $status));
    }

    /**
     * @param  array<string,mixed>  $cycle
     */
    private function findingId(array $cycle): string
    {
        return (string) (data_get($cycle, 'selected_finding.finding_id', '') ?: data_get($cycle, 'selected_finding.id', ''));
    }

    /**
     * @param  array<string,mixed>  $cycle
     */
    private function findingTitle(array $cycle): string
    {
        return (string) (data_get($cycle, 'selected_finding.title', '') ?: data_get($cycle, 'selected_finding.summary', ''));
    }

    /**
     * @param  array<string,mixed>  $cycle
     * @return list<string>
     */
    private function findingKeys(array $cycle): array
    {
        $keys = [];
        $id = $this->findingId($cycle);
        if ($id !== '') {
            $keys[] = 'finding_id:'.$id;
        }
        $title = AreaFocusScalarNormalizer::collapsedLowerWhitespace($this->findingTitle($cycle));
        if ($title !== '') {
            $keys[] = 'finding_title:'.$title;
        }

        return $keys;
    }

    /**
     * @param  array<string,mixed>  $cycle
     */
    private function commitTitle(array $cycle): string
    {
        $message = (string) (data_get($cycle, 'commit.message', '') ?: data_get($cycle, 'commit.title', ''));
        $firstLine = trim((string) (explode("\n", $message)[0] ?? ''));

        return AreaFocusScalarNormalizer::collapsedLowerWhitespace($firstLine);
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function blockedTop(string $reason, string $detail, array $extra = []): array
    {
        return $this->finalize([
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-786',
            'status' => self::STATUS_BLOCKED,
            'reason' => $reason,
            'detail' => $detail,
            'three_cycle_audit' => [
                'min_real_cycles_required' => (int) ($extra['min_real_cycles_required'] ?? self::DEFAULT_MIN_REAL_CYCLES),
                'certified_real_cycles' => 0,
                'satisfied' => false,
            ],
            'cycles' => [],
            'operator_truth' => 'BLOCKED: '.$detail,
            'next_safe_command' => null,
            'source_ap_contracts' => ['AP-786'],
            'claim_policy' => [
                'read_only' => true,
                'mutates_refs' => false,
                'invokes_provider' => false,
                'fabricates_receipts' => false,
            ],
        ] + $extra);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function finalize(array $payload): array
    {
        $identity = $payload;
        unset($identity['certification_hash'], $identity['generated_at']);
        $payload['certification_hash'] = 'sha256:'.MissionCanonicalHash::sha256($identity);
        $payload['generated_at'] = AreaFocusUtcClock::atomNow();

        return $payload;
    }
}
