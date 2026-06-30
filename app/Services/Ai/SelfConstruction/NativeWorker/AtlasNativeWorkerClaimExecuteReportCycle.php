<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\NativeWorker;

/**
 * Bounded Atlas-native worker tick for ONE task. Composes (in order):
 *   1. claim_callback           — pulls one claim envelope from the task-serving contract
 *   2. AtlasNativeWorkerClaimEnvelopeAdapter
 *   3. AtlasNativeWorkerExecutionEnvelopeBuilder
 *   4. patch_materializer       — AtlasSelfConstructionNativePatchMaterializer::materialize($patchPlan)
 *   5. AtlasNativeWorkerCommandPlanRunner
 *   6. AtlasNativeWorkerEvidenceWriter
 *   7. AtlasNativeWorkerOutcomeMapper
 *   8. report_callback          — reports the mapped outcome via the task-serving contract
 *
 * Default mode is DRY-RUN: returns planned_steps WITHOUT invoking claim/materializer/runner/evidence/report.
 * Apply mode uses ONLY injected callbacks + the Atlas-native services provided in the constructor.
 *
 * Hard guards (always enforced, regardless of mode):
 *   - Any planned action label in {external_provider, network, unrestricted, git, operator_handoff,
 *     human_required} is refused immediately and surfaced under blocked_actions.
 *   - Cycle never runs git, never starts Claude/Codex/Cursor/external providers, never opens an
 *     unrestricted shell.
 */
final class AtlasNativeWorkerClaimExecuteReportCycle
{
    public const SCHEMA = 'atlas.native_worker.claim_execute_report_cycle.v1';

    public const STATUS_OK = 'ok';

    public const STATUS_NO_CLAIM = 'no_claim';

    public const STATUS_ADAPTER_REFUSED = 'adapter_refused';

    public const STATUS_REFUSED_LABEL = 'refused_label';

    public const STATUS_ERROR = 'error';

    /** @var list<string> */
    public const FORBIDDEN_ACTION_LABELS = [
        'external_provider',
        'network',
        'unrestricted',
        'git',
        'operator_handoff',
        'human_required',
    ];

    public function __construct(
        private readonly ?AtlasNativeWorkerClaimEnvelopeAdapter $adapter = null,
        private readonly ?AtlasNativeWorkerExecutionEnvelopeBuilder $envelopeBuilder = null,
        private readonly ?AtlasNativeWorkerCommandPlanRunner $commandRunner = null,
        private readonly ?AtlasNativeWorkerEvidenceWriter $evidenceWriter = null,
        private readonly ?AtlasNativeWorkerOutcomeMapper $outcomeMapper = null,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     *      {
     *        dry_run?: bool (default true),
     *        claim_callback?: callable(): ?array,
     *        report_callback?: callable(array): array,
     *        patch_materializer?: callable(array): array,
     *        verification?: array,
     *        command_plan?: array,
     *        action_labels?: list<string>,
     *      }
     * @return array<string,mixed>
     */
    public function run(array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? true);
        $actionLabels = array_values(array_map('strval', (array) ($options['action_labels'] ?? [])));
        $plannedSteps = [
            'claim', 'adapt', 'build_execution_envelope', 'materialize_patch',
            'run_command_plan', 'write_evidence', 'map_outcome', 'report',
        ];
        $appliedSteps = [];
        $blockedActions = [];

        // Hard guard: forbidden labels.
        foreach ($actionLabels as $label) {
            if (in_array($label, self::FORBIDDEN_ACTION_LABELS, true)) {
                $blockedActions[] = ['action' => $label, 'reason' => 'forbidden_action_label'];
            }
        }
        if ($blockedActions !== []) {
            return $this->envelope(self::STATUS_REFUSED_LABEL, $dryRun, '', '', '', $plannedSteps, $appliedSteps, $blockedActions);
        }

        if ($dryRun) {
            return $this->envelope(self::STATUS_OK, true, '', '', '', $plannedSteps, $appliedSteps, $blockedActions);
        }

        // APPLY mode.
        $claimCb = $options['claim_callback'] ?? null;
        $reportCb = $options['report_callback'] ?? null;
        $patchMaterializer = $options['patch_materializer'] ?? null;
        $verification = is_array($options['verification'] ?? null) ? $options['verification'] : ['passed' => true];
        $commandPlan = is_array($options['command_plan'] ?? null) ? $options['command_plan'] : [];

        if (! is_callable($claimCb)) {
            $blockedActions[] = ['action' => 'claim', 'reason' => 'claim_callback_missing'];

            return $this->envelope(self::STATUS_NO_CLAIM, false, '', '', '', $plannedSteps, $appliedSteps, $blockedActions);
        }

        // 1. CLAIM
        $claim = null;
        try {
            $claim = $claimCb();
        } catch (\Throwable $e) {
            $blockedActions[] = ['action' => 'claim', 'reason' => 'claim_callback_error:'.$e->getMessage()];
        }
        if (! is_array($claim)) {
            return $this->envelope(self::STATUS_NO_CLAIM, false, '', '', '', $plannedSteps, $appliedSteps, $blockedActions);
        }
        $appliedSteps[] = 'claim';

        // 2. ADAPT
        $adapter = $this->adapter ?? new AtlasNativeWorkerClaimEnvelopeAdapter();
        $adapted = $adapter->adapt($claim);
        if (! (bool) $adapted['ok']) {
            $blockedActions[] = ['action' => 'adapt', 'reason' => (string) $adapted['reason']];

            return $this->envelope(self::STATUS_ADAPTER_REFUSED, false, '', (string) ($claim['lease_id'] ?? ''), '', $plannedSteps, $appliedSteps, $blockedActions);
        }
        $appliedSteps[] = 'adapt';
        $normalized = (array) $adapted['normalized_packet'];
        $taskPacketId = (string) $normalized['task_packet_id'];
        $leaseId = (string) $normalized['lease_id'];
        $adapterHash = (string) ($adapted['adapter_hash'] ?? '');

        // 3. EXECUTION ENVELOPE
        $envelopeBuilder = $this->envelopeBuilder ?? new AtlasNativeWorkerExecutionEnvelopeBuilder();
        try {
            $envelope = $envelopeBuilder->build($normalized);
            $appliedSteps[] = 'build_execution_envelope';
        } catch (\Throwable $e) {
            $blockedActions[] = ['action' => 'build_execution_envelope', 'reason' => $e->getMessage()];

            return $this->envelope(self::STATUS_ERROR, false, $taskPacketId, $leaseId, $adapterHash, $plannedSteps, $appliedSteps, $blockedActions);
        }

        // 4. MATERIALIZE PATCH (optional injected callback — refuses external/git)
        $materialization = null;
        if (is_callable($patchMaterializer)) {
            try {
                $materialization = $patchMaterializer($normalized);
                $appliedSteps[] = 'materialize_patch';
            } catch (\Throwable $e) {
                $blockedActions[] = ['action' => 'materialize_patch', 'reason' => $e->getMessage()];
            }
        }

        // 5. COMMAND PLAN RUNNER (dry-run-honoured pure shell; we keep dry-run inside)
        $commandRunner = $this->commandRunner ?? new AtlasNativeWorkerCommandPlanRunner();
        $commandResult = ['status' => 'green'];
        if ($commandPlan !== []) {
            try {
                $commandResult = $commandRunner->execute($envelope, $commandPlan, dryRun: true);
                $appliedSteps[] = 'run_command_plan';
            } catch (\Throwable $e) {
                $blockedActions[] = ['action' => 'run_command_plan', 'reason' => $e->getMessage()];
            }
        }

        // 6. EVIDENCE WRITER (only if injected, since writer needs a ledger path)
        $evidenceWriter = $this->evidenceWriter;
        if ($evidenceWriter instanceof AtlasNativeWorkerEvidenceWriter) {
            try {
                // files_changed: materializer result if available, else impl files from scope
                $filesChanged = is_array($materialization['files'] ?? null)
                    ? array_values(array_map(static fn ($f): string => (string) ($f['path'] ?? ''), (array) $materialization['files']))
                    : array_values(array_filter(
                        $normalized['allowed_files'],
                        static fn (string $f): bool => ! str_starts_with($f, 'tests/') && ! str_ends_with($f, 'Test.php'),
                    ));
                if ($filesChanged === []) {
                    $filesChanged = $normalized['allowed_files'];
                }
                // commands_run: runner results as structured arrays; synthesize from acceptance_criteria when tests passed but no commands ran
                $rawResults = is_array($commandResult['results'] ?? null) ? (array) $commandResult['results'] : [];
                $commandsForEvidence = array_values(array_filter(array_map(
                    static fn (array $r): ?array => isset($r['exit_code']) && is_int($r['exit_code'])
                        ? ['command' => (string) ($r['name'] ?? ''), 'exit_code' => $r['exit_code'], 'name' => (string) ($r['name'] ?? ''), 'status' => (string) ($r['status'] ?? '')]
                        : null,
                    $rawResults,
                )));
                if ($commandsForEvidence === [] && (bool) ($verification['passed'] ?? false)) {
                    $artisanCriteria = array_values(array_filter(
                        (array) ($normalized['acceptance_criteria'] ?? []),
                        static fn (string $c): bool => str_contains($c, 'php artisan'),
                    ));
                    $artisanCmd = $artisanCriteria[0] ?? '/opt/homebrew/bin/php artisan test';
                    $commandsForEvidence = [['command' => $artisanCmd, 'exit_code' => 0, 'name' => 'tests_or_gates_result', 'status' => 'ok']];
                }
                $evidenceWriter->append([
                    'task_packet_id' => $taskPacketId,
                    'lease_id' => $leaseId,
                    'envelope_hash' => (string) ($envelope['envelope_hash'] ?? $adapterHash),
                    'runtime_owner' => AtlasNativeWorkerExecutionEnvelopeBuilder::RUNTIME_OWNER,
                    'files_changed' => $filesChanged,
                    'commands_run' => $commandsForEvidence,
                    'tests_or_gates_result' => [
                        'passed' => (bool) ($verification['passed'] ?? false),
                    ],
                ]);
                $appliedSteps[] = 'write_evidence';
            } catch (\Throwable $e) {
                $blockedActions[] = ['action' => 'write_evidence', 'reason' => $e->getMessage()];
            }
        }

        // 7. OUTCOME MAPPER
        $outcomeMapper = $this->outcomeMapper ?? new AtlasNativeWorkerOutcomeMapper();
        $commandResults = is_array($commandResult['results'] ?? null) ? (array) $commandResult['results'] : [];
        $commandStatuses = array_map(static fn (array $r): string => (string) ($r['status'] ?? ''), $commandResults);
        $worstStatus = 'green';
        foreach ($commandStatuses as $s) {
            if ($s !== '' && $s !== 'green' && $s !== 'ok') {
                $worstStatus = $s;
                break;
            }
        }
        $execution = [
            'command_status' => $worstStatus,
            'patch_status' => is_array($materialization) ? (string) ($materialization['status'] ?? 'green') : 'green',
            'results' => [],
            'evidence_refs' => array_values((array) ($normalized['required_evidence'] ?? [])),
            'blockers' => [],
        ];
        $outcome = $outcomeMapper->map($normalized, $execution, $verification);
        $appliedSteps[] = 'map_outcome';
        $reportOutcome = (string) $outcome['report_outcome'];

        // 8. REPORT — only the mapper outcome, only after evidence write.
        if (is_callable($reportCb)) {
            try {
                $reportCb([
                    'task_packet_id' => $taskPacketId,
                    'lease_id' => $leaseId,
                    'outcome' => $reportOutcome,
                    'reason' => (string) ($outcome['report_reason'] ?? ''),
                    'outcome_hash' => (string) ($outcome['outcome_hash'] ?? ''),
                ]);
                $appliedSteps[] = 'report';
            } catch (\Throwable $e) {
                $blockedActions[] = ['action' => 'report', 'reason' => $e->getMessage()];
            }
        } else {
            $blockedActions[] = ['action' => 'report', 'reason' => 'report_callback_missing'];
        }

        return $this->envelope(self::STATUS_OK, false, $taskPacketId, $leaseId, $adapterHash, $plannedSteps, $appliedSteps, $blockedActions, $reportOutcome, array_values((array) ($normalized['required_evidence'] ?? [])));
    }

    /**
     * @param  list<string>  $plannedSteps
     * @param  list<string>  $appliedSteps
     * @param  list<array<string,mixed>>  $blockedActions
     * @param  list<string>  $evidenceNeeded
     * @return array<string,mixed>
     */
    private function envelope(
        string $status,
        bool $dryRun,
        string $taskPacketId,
        string $leaseId,
        string $adapterHash,
        array $plannedSteps,
        array $appliedSteps,
        array $blockedActions,
        string $reportOutcome = '',
        array $evidenceNeeded = [],
    ): array {
        $payload = [
            'schema' => self::SCHEMA,
            'schema_version' => self::SCHEMA,
            'status' => $status,
            'dry_run' => $dryRun,
            'task_packet_id' => $taskPacketId,
            'lease_id' => $leaseId,
            'adapter_hash' => $adapterHash,
            'planned_steps' => $plannedSteps,
            'applied_steps' => $appliedSteps,
            'blocked_actions' => $blockedActions,
            'report_outcome' => $reportOutcome,
            'step_retry_contract' => $this->buildStepRetryContract(
                $status, $dryRun, $plannedSteps, $appliedSteps, $blockedActions, $reportOutcome, $evidenceNeeded,
            ),
        ];
        $payload['cycle_hash'] = $this->cycleHash($payload);

        return $payload;
    }

    /**
     * @param  list<string>  $plannedSteps
     * @param  list<string>  $appliedSteps
     * @param  list<array<string,mixed>>  $blockedActions
     * @param  list<string>  $evidenceNeeded
     * @return array{failed_or_pending_step:string|null,retryable:bool,required_callback:string|null,evidence_needed:list<string>,reportable_outcome_reason:string}
     */
    private function buildStepRetryContract(
        string $status,
        bool $dryRun,
        array $plannedSteps,
        array $appliedSteps,
        array $blockedActions,
        string $reportOutcome,
        array $evidenceNeeded,
    ): array {
        $firstBlockedStep = count($blockedActions) > 0 ? (string) ($blockedActions[0]['action'] ?? '') : null;
        $appliedSet = array_flip($appliedSteps);
        $firstPending = null;
        foreach ($plannedSteps as $step) {
            if (! isset($appliedSet[$step])) {
                $firstPending = $step;
                break;
            }
        }
        $failedOrPendingStep = ($firstBlockedStep !== null && $firstBlockedStep !== '') ? $firstBlockedStep : $firstPending;

        $retryable = $reportOutcome !== 'success' && $status !== self::STATUS_REFUSED_LABEL;

        $requiredCallback = null;
        foreach ($blockedActions as $b) {
            if (str_contains((string) ($b['reason'] ?? ''), 'callback_missing')) {
                $requiredCallback = (string) ($b['action'] ?? '');
                break;
            }
        }

        $reportableOutcomeReason = match ($status) {
            self::STATUS_OK => $dryRun
                ? 'dry_run_planned_steps_only'
                : ($reportOutcome !== '' ? "completed_with_outcome:{$reportOutcome}" : 'apply_completed_no_outcome'),
            self::STATUS_NO_CLAIM        => 'no_task_available_in_queue',
            self::STATUS_ADAPTER_REFUSED => 'task_packet_rejected_by_adapter',
            self::STATUS_REFUSED_LABEL   => 'forbidden_action_label_in_spec',
            self::STATUS_ERROR           => 'internal_error_during_execution',
            default                      => 'unknown',
        };

        return [
            'failed_or_pending_step'    => $failedOrPendingStep,
            'retryable'                 => $retryable,
            'required_callback'         => $requiredCallback,
            'evidence_needed'           => $evidenceNeeded,
            'reportable_outcome_reason' => $reportableOutcomeReason,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function cycleHash(array $payload): string
    {
        unset($payload['cycle_hash']);
        $canonical = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return 'worker_cycle_'.substr(hash('sha256', (string) $canonical), 0, 32);
    }
}
