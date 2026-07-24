<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\RuntimeDaemon;

use App\Services\Ai\SelfConstruction\NativeWorker\AtlasNativeWorkerProductionCallbacks;
use App\Services\Ai\SelfConstruction\NativeWorker\AtlasNativeWorkerProductionRuntime;
use App\Services\Ai\SelfConstruction\NativeWorker\AtlasNativeWorkerRecoverableProductionRuntime;
use Throwable;

/**
 * Bounded server-side tick for the final 24/7 Atlas Self-Construction daemon.
 *
 * Composes (always, no I/O):
 *   - {@see AtlasSelfConstructionRuntimeDaemonState}::reduce — projects the next daemon state.
 *   - Caller-supplied unattended supervisor verdict — controls "critical blocker" stop.
 *   - Caller-supplied native worker pool receipt — surfaces in the receipt-hash for downstream gates.
 *
 * Refuses any action whose `kind` is in {@see REFUSED_ACTION_KINDS}: operator, human, external provider,
 * Claude / Codex / Cursor, git, network, unrestricted shell. These NEVER fire, even with a callback.
 *
 * Apply mode runs only the typed Atlas-native executor and only for ready ticks. Callback options
 * are an explicitly test-environment-only compatibility harness and cannot be selected by production.
 */
final class AtlasSelfConstructionRuntimeDaemonCycle
{
    public const SCHEMA = 'atlas.self_construction.runtime_daemon_cycle.v1';

    public const REFUSED_ACTION_KINDS = [
        'operator_action',
        'human_action',
        'external_provider_call',
        'claude_code',
        'codex',
        'cursor',
        'git',
        'network',
        'unrestricted_shell',
    ];

    public function __construct(
        private readonly ?AtlasSelfConstructionRuntimeDaemonState $stateReducer = null,
        private readonly ?AtlasSelfConstructionNativeActionExecutor $actionExecutor = null,
        private readonly ?AtlasNativeWorkerProductionRuntime $nativeWorker = null,
    ) {}

    /**
     * Claim or recover one governed native lease without entering the productive action loop.
     *
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function claim(array $facts = [], bool $dryRun = false): array
    {
        $clientId = trim((string) ($facts['client_id'] ?? '')) ?: 'atlas-self-construction-runtime-daemon';

        // Claim is a native-authority operation. A diagnostic request must not
        // even probe resume/renew/claim, because those calls can acquire or
        // extend a lease.
        if ($dryRun) {
            return $this->claimReceipt($facts, $clientId, 'planned', 'dry_run_claim_withheld', dryRun: true);
        }

        try {
            $runtime = $this->nativeWorker;
            if (! $runtime instanceof AtlasNativeWorkerProductionRuntime) {
                if (! function_exists('app')) {
                    return $this->claimReceipt($facts, $clientId, 'native_claim_authority_unavailable');
                }
                $runtime = app(AtlasNativeWorkerProductionCallbacks::class);
            }

            if ($runtime instanceof AtlasNativeWorkerRecoverableProductionRuntime) {
                $resumed = $runtime->resume($clientId);
                if (is_array($resumed)) {
                    [$taskPacketId, $leaseId] = $this->claimIdentifiers($resumed);
                    if ($taskPacketId === '' || $leaseId === '') {
                        return $this->claimReceipt($facts, $clientId, 'invalid_native_claim_envelope');
                    }
                    if (($provenanceFailure = $this->nativeClaimProvenanceFailure($resumed)) !== null) {
                        return $this->claimReceipt(
                            $facts,
                            $clientId,
                            $provenanceFailure['status'],
                            $provenanceFailure['reason'],
                            envelope: $resumed,
                        );
                    }
                    if (! $runtime->renew($clientId, $taskPacketId, $leaseId)) {
                        return $this->claimReceipt($facts, $clientId, 'lease_recovery_failed');
                    }

                    return $this->claimReceipt(
                        $facts,
                        $clientId,
                        'claimed',
                        envelope: $resumed,
                        recovered: true,
                    );
                }
            }

            $envelope = $runtime instanceof AtlasNativeWorkerProductionCallbacks
                ? $runtime->claimEnvelope($clientId)
                : $runtime->claim($clientId);
            if ($envelope === null) {
                return $this->claimReceipt($facts, $clientId, 'no_claimable_task');
            }

            $status = trim((string) ($envelope['status'] ?? ''));
            if (! in_array($status, ['leased', 'served'], true)) {
                if ($status === '') {
                    return $this->claimReceipt($facts, $clientId, 'invalid_native_claim_envelope');
                }

                return $this->claimReceipt(
                    $facts,
                    $clientId,
                    $status,
                    trim((string) ($envelope['reason'] ?? '')) ?: $status,
                );
            }

            [$taskPacketId, $leaseId] = $this->claimIdentifiers($envelope);
            if ($taskPacketId === '' || $leaseId === '') {
                return $this->claimReceipt($facts, $clientId, 'invalid_native_claim_envelope');
            }

            if ($runtime instanceof AtlasNativeWorkerRecoverableProductionRuntime) {
                // A fresh claim's authority is not trusted until the owner
                // reads back the active lease it just issued. This prevents a
                // shape-valid serving envelope from minting claim truth.
                $authoritativeResume = $runtime->resume($clientId);
                if (! is_array($authoritativeResume)) {
                    return $this->claimReceipt(
                        $facts,
                        $clientId,
                        'native_claim_provenance_unavailable',
                        'fresh_claim_resume_unavailable',
                    );
                }
                [$resumedTaskPacketId, $resumedLeaseId] = $this->claimIdentifiers($authoritativeResume);
                if ($resumedTaskPacketId === '' || $resumedLeaseId === '') {
                    return $this->claimReceipt(
                        $facts,
                        $clientId,
                        'native_claim_provenance_mismatch',
                        'fresh_claim_resume_identifiers_missing',
                        envelope: $authoritativeResume,
                    );
                }
                if (! hash_equals($taskPacketId, $resumedTaskPacketId)
                    || ! hash_equals($leaseId, $resumedLeaseId)) {
                    return $this->claimReceipt(
                        $facts,
                        $clientId,
                        'native_claim_provenance_mismatch',
                        'fresh_claim_resume_mismatch',
                        envelope: $authoritativeResume,
                    );
                }
                if (($provenanceFailure = $this->nativeClaimProvenanceFailure($authoritativeResume)) !== null) {
                    return $this->claimReceipt(
                        $facts,
                        $clientId,
                        $provenanceFailure['status'],
                        $provenanceFailure['reason'],
                        envelope: $authoritativeResume,
                    );
                }

                // This is a field-for-field owner projection. No daemon-side
                // hash/ref may replace the authoritative readback.
                $envelope = array_replace($envelope, $authoritativeResume);
            } elseif (($provenanceFailure = $this->nativeClaimProvenanceFailure($envelope)) !== null) {
                return $this->claimReceipt(
                    $facts,
                    $clientId,
                    $provenanceFailure['status'],
                    $provenanceFailure['reason'],
                    envelope: $envelope,
                );
            }

            return $this->claimReceipt(
                $facts,
                $clientId,
                'claimed',
                envelope: $envelope,
            );
        } catch (Throwable) {
            return $this->claimReceipt($facts, $clientId, 'native_claim_authority_unavailable');
        }
    }

    /**
     * @param  array<string,mixed>  $facts  {daemon_state?, heartbeat_event, planned_actions?, unattended_verdict?, native_pool_receipt?}
     * @param  array<string,mixed>  $options  {apply?:bool, action_callbacks?:array<string,callable>}
     * @return array<string,mixed>
     */
    public function tick(array $facts, array $options = []): array
    {
        $apply = (bool) ($options['apply'] ?? false);
        $testCallbacks = app()->environment('testing') && ! $this->actionExecutor instanceof AtlasSelfConstructionNativeActionExecutor
            && is_array($options['action_callbacks'] ?? null)
            ? $options['action_callbacks']
            : [];

        $state = is_array($facts['daemon_state'] ?? null) ? $facts['daemon_state'] : [];
        $heartbeatEvent = is_array($facts['heartbeat_event'] ?? null) ? $facts['heartbeat_event'] : ['type' => 'heartbeat'];
        $plannedActions = array_values((array) ($facts['planned_actions'] ?? []));
        $unattended = is_array($facts['unattended_verdict'] ?? null) ? $facts['unattended_verdict'] : [];
        $poolReceipt = is_array($facts['native_pool_receipt'] ?? null) ? $facts['native_pool_receipt'] : null;

        $reducer = $this->stateReducer ?? new AtlasSelfConstructionRuntimeDaemonState;
        $nextState = $reducer->reduce($state, $heartbeatEvent);

        $unattendedCriticalBlocker = (bool) ($unattended['critical_blocker'] ?? false);
        $unattendedRecoveryNeeded = (bool) ($unattended['recovery_needed'] ?? false);

        // Recoverable brain stall → inject as atlas-native planned action (not critical; allowed kinds only)
        if ($unattendedRecoveryNeeded && ! $unattendedCriticalBlocker) {
            $plannedActions[] = [
                'kind' => 'atlas_native_brain_recovery',
                'verdict_classification' => (string) ($unattended['classification'] ?? ''),
                'verdict_severity' => (string) ($unattended['severity'] ?? ''),
                'verdict_reasons' => (array) ($unattended['reasons'] ?? []),
                'source' => 'unattended_verdict',
            ];
        }
        $nextTickAllowed = (bool) ($nextState['next_tick_allowed'] ?? false);
        $cycleBlockedReasons = [];
        if (! $nextTickAllowed) {
            $cycleBlockedReasons[] = 'daemon_state_blocks_tick:'.(string) ($nextState['status'] ?? 'unknown');
        }
        if ($unattendedCriticalBlocker) {
            $cycleBlockedReasons[] = 'unattended_supervisor_critical_blocker';
        }

        $appliedActions = [];
        $blockedActions = [];
        $withheldActions = [];
        $seenActionKeys = [];
        $replayedActionKeys = [];
        $duplicateActionKeys = [];
        $completedActionKeys = array_values(array_unique(array_filter(array_map(
            static fn (mixed $key): string => trim((string) $key),
            (array) ($facts['completed_action_keys'] ?? []),
        ))));

        foreach ($plannedActions as $action) {
            if (! is_array($action)) {
                continue;
            }
            $kind = (string) ($action['kind'] ?? '');
            $actionKey = trim((string) ($action['idempotency_key'] ?? ''));

            if ($actionKey !== '' && in_array($actionKey, $completedActionKeys, true)) {
                $replayedActionKeys[] = $actionKey;
                $withheldActions[] = [
                    'kind' => $kind,
                    'reason' => 'idempotency_replay',
                    'idempotency_key' => $actionKey,
                ];

                continue;
            }
            if ($actionKey !== '' && in_array($actionKey, $seenActionKeys, true)) {
                $duplicateActionKeys[] = $actionKey;
                $withheldActions[] = [
                    'kind' => $kind,
                    'reason' => 'duplicate_action',
                    'idempotency_key' => $actionKey,
                ];

                continue;
            }
            if ($actionKey !== '') {
                $seenActionKeys[] = $actionKey;
            }

            if (in_array($kind, self::REFUSED_ACTION_KINDS, true)) {
                $withheldActions[] = [
                    'kind' => $kind,
                    'reason' => 'refused_action_kind:'.$kind,
                ];

                continue;
            }
            $requiresProof = (bool) ($action['requires_proof'] ?? false);
            $proofRef = trim((string) ($action['proof_ref'] ?? ''));
            if ($requiresProof && $proofRef === '') {
                $withheldActions[] = [
                    'kind' => $kind,
                    'reason' => 'missing_proof',
                ];

                continue;
            }
            if (! $apply) {
                $withheldActions[] = [
                    'kind' => $kind,
                    'reason' => 'dry_run',
                ];

                continue;
            }
            if ($cycleBlockedReasons !== []) {
                $withheldActions[] = [
                    'kind' => $kind,
                    'reason' => 'cycle_blocked:'.$cycleBlockedReasons[0],
                ];

                continue;
            }
            $testCallback = $testCallbacks[$kind] ?? null;
            if (! $this->actionExecutor instanceof AtlasSelfConstructionNativeActionExecutor && ! is_callable($testCallback)) {
                $withheldActions[] = [
                    'kind' => $kind,
                    'reason' => 'no_callback_supplied',
                ];

                continue;
            }
            try {
                $result = $this->actionExecutor instanceof AtlasSelfConstructionNativeActionExecutor
                    ? $this->actionExecutor->execute($action, $nextState)
                    : $testCallback($action, $nextState);
                if (($result['status'] ?? null) === 'resolved') {
                    foreach (['independent_verification_receipt', 'release_receipt', 'canary_receipt'] as $requiredReceipt) {
                        if (trim((string) ($result[$requiredReceipt] ?? '')) === '') {
                            throw new \RuntimeException($requiredReceipt.'_missing');
                        }
                    }
                }
                $appliedActions[] = [
                    'kind' => $kind,
                    'result' => is_array($result) ? $result : ['ok' => true],
                ];
            } catch (Throwable $e) {
                $blockedActions[] = [
                    'kind' => $kind,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $idempotency = [
            'completed_action_keys' => $completedActionKeys,
            'seen_action_keys' => $seenActionKeys,
            'replayed_action_keys' => array_values(array_unique($replayedActionKeys)),
            'duplicate_action_keys' => array_values(array_unique($duplicateActionKeys)),
        ];
        $cycleReceiptHash = $this->cycleReceiptHash($nextState, $appliedActions, $blockedActions, $poolReceipt, $idempotency);
        $actionFeedback = $this->buildActionFeedback($appliedActions, $withheldActions, $blockedActions);

        // Single-action-per-tick summary (AC): the first planned action IS the action this tick
        // decided on — its proof requirement, retry policy, and any safety refusal it hit.
        $selectedAction = $plannedActions[0] ?? null;
        $selectedActionKind = is_array($selectedAction) ? (string) ($selectedAction['kind'] ?? '') : null;
        $selectedFeedback = null;
        if ($selectedActionKind !== null) {
            foreach ($actionFeedback as $entry) {
                if ($entry['kind'] === $selectedActionKind) {
                    $selectedFeedback = $entry;
                    break;
                }
            }
        }

        $safetyBlockers = $cycleBlockedReasons;
        if ($selectedActionKind !== null && in_array($selectedActionKind, self::REFUSED_ACTION_KINDS, true)) {
            $safetyBlockers[] = 'refused_action_kind:'.$selectedActionKind;
        }
        if (is_array($selectedAction) && (bool) ($selectedAction['requires_proof'] ?? false) && trim((string) ($selectedAction['proof_ref'] ?? '')) === '') {
            $safetyBlockers[] = 'missing_proof';
        }

        $payload = [
            'schema_version' => self::SCHEMA,
            'daemon_status' => (string) ($nextState['status'] ?? 'unknown'),
            'dry_run' => ! $apply,
            'planned_actions' => $plannedActions,
            'applied_actions' => $appliedActions,
            'withheld_actions' => $withheldActions,
            'blocked_actions' => $blockedActions,
            'action_feedback' => $actionFeedback,
            'heartbeat_event' => $heartbeatEvent,
            'cycle_receipt_hash' => $cycleReceiptHash,
            'cycle_blocked_reasons' => $cycleBlockedReasons,
            'next_state' => $nextState,
            'selected_action' => $selectedAction,
            'action_kind' => $selectedActionKind,
            'proof_required' => is_array($selectedAction) ? (bool) ($selectedAction['requires_proof'] ?? false) : false,
            'retry_policy' => [
                'retryable' => $selectedFeedback['retryable'] ?? false,
                'next_safe_action' => $selectedFeedback['next_safe_action'] ?? 'no_action_this_tick',
            ],
            'safety_blockers' => array_values(array_unique($safetyBlockers)),
            'idempotency' => $idempotency,
        ];
        $payload['daemon_cycle_hash'] = $this->hash($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $nextState
     * @param  list<array<string,mixed>>  $applied
     * @param  list<array<string,mixed>>  $blocked
     * @param  array<string,mixed>|null  $poolReceipt
     * @param  array<string,mixed>  $idempotency
     */
    private function cycleReceiptHash(array $nextState, array $applied, array $blocked, ?array $poolReceipt, array $idempotency): string
    {
        $payload = [
            'daemon_status' => (string) ($nextState['status'] ?? ''),
            'state_hash' => (string) ($nextState['state_hash'] ?? ''),
            'applied_kinds' => array_values(array_map(static fn (array $a): string => (string) ($a['kind'] ?? ''), $applied)),
            'blocked_kinds' => array_values(array_map(static fn (array $a): string => (string) ($a['kind'] ?? ''), $blocked)),
            'pool_supervisor_hash' => (string) ($poolReceipt['supervisor_hash'] ?? ''),
            'idempotency' => $idempotency,
        ];

        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hash(array $payload): string
    {
        $copy = $payload;
        unset($copy['daemon_cycle_hash']);
        $copy = $this->ksortDeep($copy);

        return hash('sha256', (string) json_encode($copy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string,mixed> $envelope @return array{0:string,1:string} */
    private function claimIdentifiers(array $envelope): array
    {
        $task = is_array($envelope['task'] ?? null) ? $envelope['task'] : $envelope;

        return [
            trim((string) ($task['task_packet_id'] ?? '')),
            trim((string) ($task['lease_id'] ?? '')),
        ];
    }

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    private function claimReceipt(
        array $facts,
        string $clientId,
        string $status,
        ?string $reason = null,
        ?array $envelope = null,
        bool $recovered = false,
        bool $dryRun = false,
    ): array {
        $native = $this->nativeClaimFields($envelope ?? []);
        $taskPacketId = $native['task_packet_id'];
        $leaseId = $native['lease_id'];

        $payload = [
            'schema_version' => self::SCHEMA,
            'status' => $status,
            'action' => 'claim',
            'dry_run' => $dryRun,
            'client_id' => $clientId,
            'intent' => trim((string) ($facts['intent'] ?? '')) ?: null,
            'workspace' => trim((string) ($facts['workspace'] ?? '')) ?: null,
            'reason' => $reason ?? ($status === 'claimed' ? null : $status),
            'recovered' => $recovered,
            // These are direct projections of the native envelope. Do not
            // synthesize a daemon hash or a prefixed reference in this path.
            'task_packet_id' => $taskPacketId,
            'lease_id' => $leaseId,
            'authority_nonce' => $native['authority_nonce'],
            'authority_hash' => $native['authority_hash'],
            'envelope_hash' => $native['envelope_hash'],
            'native_envelope_ref' => $native['envelope_hash'],
            'lease_expires_at' => $native['lease_expires_at'],
            'native_journey_ref' => $native['native_journey_ref'],
            'native_cycle_refs' => $native['native_cycle_refs'],
            'native_task_refs' => $taskPacketId === null ? [] : [$taskPacketId],
            'native_lease_refs' => $leaseId === null ? [] : [$leaseId],
            'task' => [
                'status' => $status === 'claimed' ? 'claimed' : 'not_claimed',
                'task_packet_id' => $taskPacketId,
                'lease_id' => $leaseId,
                'worker_executed' => false,
            ],
            'worker_executed' => false,
            'provider_calls' => 0,
            'mutation_performed' => false,
        ];

        return $payload;
    }

    /**
     * Extract native owner fields without normalizing, hashing or creating a
     * second reference namespace. The native worker owns their meaning.
     *
     * @param  array<string,mixed>  $envelope
     * @return array{task_packet_id:mixed,lease_id:mixed,authority_nonce:mixed,authority_hash:mixed,envelope_hash:mixed,authority_revoked:mixed,lease_expires_at:mixed,native_journey_ref:mixed,native_cycle_refs:array<mixed,mixed>}
     */
    private function nativeClaimFields(array $envelope): array
    {
        $task = is_array($envelope['task'] ?? null) ? $envelope['task'] : [];
        $field = static function (string $key) use ($envelope, $task): mixed {
            if (array_key_exists($key, $envelope)) {
                return $envelope[$key];
            }

            return $task[$key] ?? null;
        };
        $taskPacketId = $field('task_packet_id');
        $leaseId = $field('lease_id');

        return [
            'task_packet_id' => is_scalar($taskPacketId) && trim((string) $taskPacketId) !== '' ? $taskPacketId : null,
            'lease_id' => is_scalar($leaseId) && trim((string) $leaseId) !== '' ? $leaseId : null,
            'authority_nonce' => $field('authority_nonce'),
            'authority_hash' => $field('authority_hash'),
            'envelope_hash' => $field('envelope_hash'),
            'authority_revoked' => $field('authority_revoked'),
            'lease_expires_at' => $field('lease_expires_at'),
            'native_journey_ref' => $field('native_journey_ref'),
            'native_cycle_refs' => is_array($field('native_cycle_refs')) ? $field('native_cycle_refs') : [],
        ];
    }

    /**
     * The task-serving owner, rather than the daemon, issues these fields.
     * A claim without all of them is an observation, never claim authority.
     *
     * @param  array<string,mixed>  $envelope
     * @return array{status:string,reason:string}|null
     */
    private function nativeClaimProvenanceFailure(array $envelope): ?array
    {
        $native = $this->nativeClaimFields($envelope);
        if ((bool) $native['authority_revoked']) {
            return [
                'status' => 'native_claim_authority_revoked',
                'reason' => 'native_authority_revoked',
            ];
        }

        foreach (['authority_nonce', 'authority_hash', 'envelope_hash'] as $field) {
            $value = $native[$field];
            if (! is_scalar($value) || trim((string) $value) === '') {
                return [
                    'status' => 'native_claim_provenance_missing',
                    'reason' => 'missing_native_claim_'.$field,
                ];
            }
        }

        return null;
    }

    /**
     * @param  array<mixed,mixed>  $value
     * @return array<mixed,mixed>
     */
    private function ksortDeep(array $value): array
    {
        $isAssoc = $value !== [] && array_keys($value) !== range(0, count($value) - 1);
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $value[$k] = $this->ksortDeep($v);
            }
        }
        if ($isAssoc) {
            ksort($value);
        }

        return $value;
    }

    /**
     * Build deterministic action_feedback entries for every action in the tick.
     *
     * Each entry has:
     *   kind             string   — the action kind
     *   outcome_class    string   — 'applied' | 'withheld' | 'blocked'
     *   retryable        bool     — whether a retry is meaningful
     *   next_safe_action string   — deterministic next-step hint
     *   receipt_refs     string[] — any receipt references from the result
     *
     * @param  list<array<string,mixed>>  $appliedActions
     * @param  list<array<string,mixed>>  $withheldActions
     * @param  list<array<string,mixed>>  $blockedActions
     * @return list<array<string,mixed>>
     */
    private function buildActionFeedback(array $appliedActions, array $withheldActions, array $blockedActions): array
    {
        $feedback = [];

        foreach ($appliedActions as $a) {
            $kind = (string) ($a['kind'] ?? '');
            $resultStatus = (string) ($a['result']['status'] ?? 'applied');
            $refs = [];
            if (isset($a['result']['receipt'])) {
                $refs[] = (string) $a['result']['receipt'];
            }
            $feedback[] = [
                'kind' => $kind,
                'outcome_class' => $resultStatus === 'held' ? 'held' : 'applied',
                'retryable' => $resultStatus === 'held' || (bool) ($a['result']['retryable'] ?? false),
                'next_safe_action' => $resultStatus === 'held' ? 'retry_native_action:'.$kind : 'verify_applied_outcome:'.$kind,
                'receipt_refs' => $refs,
            ];
        }

        foreach ($withheldActions as $w) {
            $kind = (string) ($w['kind'] ?? '');
            $reason = (string) ($w['reason'] ?? '');
            [$retryable, $next] = $this->withheldRetryProfile($reason, $kind);
            $feedback[] = [
                'kind' => $kind,
                'outcome_class' => 'withheld',
                'retryable' => $retryable,
                'next_safe_action' => $next,
                'receipt_refs' => [],
            ];
        }

        foreach ($blockedActions as $b) {
            $kind = (string) ($b['kind'] ?? '');
            $feedback[] = [
                'kind' => $kind,
                'outcome_class' => 'blocked',
                'retryable' => true,
                'next_safe_action' => 'inspect_native_executor_error_and_retry:'.$kind,
                'receipt_refs' => [],
            ];
        }

        return $feedback;
    }

    /**
     * @return array{0:bool, 1:string}
     */
    private function withheldRetryProfile(string $reason, string $kind): array
    {
        if (str_starts_with($reason, 'refused_action_kind:')) {
            return [false, 'action_kind_permanently_refused:'.$kind];
        }
        if ($reason === 'dry_run') {
            return [true, 'retry_with_apply_true:'.$kind];
        }
        if (str_starts_with($reason, 'cycle_blocked:')) {
            return [true, 'retry_when_cycle_unblocked:'.$kind];
        }
        if ($reason === 'no_callback_supplied') {
            return [false, 'supply_callback_for:'.$kind];
        }
        if ($reason === 'missing_proof') {
            return [true, 'attach_proof_ref_then_retry:'.$kind];
        }

        return [true, 'retry_after_investigating_reason:'.$reason];
    }
}
