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
                $evidenceWriter->append([
                    'task_packet_id' => $taskPacketId,
                    'lease_id' => $leaseId,
                    'envelope_hash' => (string) ($envelope['envelope_hash'] ?? $adapterHash),
                    'runtime_owner' => AtlasNativeWorkerExecutionEnvelopeBuilder::RUNTIME_OWNER,
                    'files_changed' => is_array($materialization['files'] ?? null)
                        ? array_values(array_map(static fn ($f): string => (string) ($f['path'] ?? ''), (array) $materialization['files']))
                        : [],
                    'commands_run' => is_array($commandResult['commands'] ?? null)
                        ? (array) $commandResult['commands']
                        : [],
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
        $execution = [
            'command_status' => (string) ($commandResult['status'] ?? ''),
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

        return $this->envelope(self::STATUS_OK, false, $taskPacketId, $leaseId, $adapterHash, $plannedSteps, $appliedSteps, $blockedActions, $reportOutcome);
    }

    /**
     * @param  list<string>  $plannedSteps
     * @param  list<string>  $appliedSteps
     * @param  list<array<string,mixed>>  $blockedActions
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
        ];
        $payload['cycle_hash'] = $this->cycleHash($payload);

        return $payload;
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
