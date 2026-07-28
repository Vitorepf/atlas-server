<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness;

use App\Models\AtlasSelfConstructionAgentDispatchReceipt;
use App\Models\AtlasSelfConstructionAgentWakeupItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

/**
 * SC-01 fatia ReadinessProjectionMutatingWriterSection (Obra 4 Residual Elite).
 */
final class ReadinessProjectionMutatingWriterSection
{
    private ?AtlasSelfConstructionReadinessService $mother = null;

    public function setMother(AtlasSelfConstructionReadinessService $mother): self
    {
        $this->mother = $mother;

        return $this;
    }

    public function __call(string $name, array $arguments): mixed
    {
        if ($this->mother === null) {
            throw new \RuntimeException('ReadinessProjectionMutatingWriterSection mother not bound for '.$name);
        }
        $method = new \ReflectionMethod($this->mother, $name);

        return $method->invokeArgs($this->mother, $arguments);
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickMutatingWriterReleasePreflight(array $options = []): array
    {
        $persistenceStatusPayload = $this->agentAutomaticDispatchSchedulerOneShotTickReleaseReceiptPersistenceStatus($options);
        $persistenceStatus = (array) data_get($persistenceStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_persistence_status', []);
        $dryRunTickPayload = $this->agentAutomaticDispatchSchedulerDryRunTick($options);
        $dryRunTick = (array) data_get($dryRunTickPayload, 'agent_automatic_dispatch_scheduler_dry_run_tick', []);
        $selectedWakeupItem = data_get($dryRunTick, 'queue_projection.selected_wakeup_item');
        $dispatchEnvelopePreview = data_get($dryRunTick, 'dispatch_envelope_preview');
        $receiptHash = strtolower(trim((string) ($options['receipt_hash'] ?? '')));
        $receiptHashValid = preg_match('/^[a-f0-9]{64}$/', $receiptHash) === 1;
        $releaseReceipt = null;

        if (Schema::hasTable('atlas_self_construction_agent_dispatch_receipts') && $receiptHashValid) {
            $query = AtlasSelfConstructionAgentDispatchReceipt::query()
                ->where('decision', 'approve_scheduler_claim_and_receipt_once')
                ->where('status', 'release_authorized_pending_one_shot_tick');

            if ($receiptHash !== '') {
                $query->where('receipt_hash', $receiptHash);
            }

            $releaseReceipt = $query->latest('created_at')->first();
        }

        $releasePayload = $releaseReceipt instanceof AtlasSelfConstructionAgentDispatchReceipt
            ? (array) $releaseReceipt->payload
            : [];
        $expectedDispatchEnvelopeHash = is_array($dispatchEnvelopePreview)
            ? $this->stableHash($dispatchEnvelopePreview)
            : null;
        $selectedWakeupKey = is_array($selectedWakeupItem)
            ? (string) data_get($selectedWakeupItem, 'wakeup_key')
            : null;
        $releaseWakeupKey = (string) data_get($releasePayload, 'selected_wakeup_key', '');
        $now = CarbonImmutable::now();

        $componentReadiness = [
            'persistence_status_ready' => data_get($persistenceStatusPayload, 'status') === 'release_receipt_persistence_writer_service_ready',
            'dry_run_tick_ready' => in_array(data_get($dryRunTickPayload, 'status'), [
                'agent_automatic_dispatch_scheduler_dry_run_tick_ready',
                'blocked',
            ], true) && is_array($selectedWakeupItem) && is_array($dispatchEnvelopePreview),
            'receipt_hash_filter_valid' => $receiptHashValid,
            'release_receipt_exists' => $releaseReceipt instanceof AtlasSelfConstructionAgentDispatchReceipt,
            'selected_wakeup_available' => is_array($selectedWakeupItem),
            'dispatch_envelope_preview_available' => is_array($dispatchEnvelopePreview),
        ];
        $releaseChecks = [
            'release_receipt_status_is_pending_one_shot_tick' => $releaseReceipt instanceof AtlasSelfConstructionAgentDispatchReceipt
                && $releaseReceipt->status === 'release_authorized_pending_one_shot_tick',
            'release_receipt_decision_is_approve_once' => $releaseReceipt instanceof AtlasSelfConstructionAgentDispatchReceipt
                && $releaseReceipt->decision === 'approve_scheduler_claim_and_receipt_once',
            'release_receipt_is_unexpired' => $releaseReceipt instanceof AtlasSelfConstructionAgentDispatchReceipt
                && $releaseReceipt->expires_at !== null
                && $releaseReceipt->expires_at->greaterThan($now),
            'release_receipt_wakeup_matches_selected_candidate' => $selectedWakeupKey !== null
                && $releaseWakeupKey !== ''
                && $releaseWakeupKey === $selectedWakeupKey,
            'release_receipt_dispatch_envelope_matches_current_dry_run' => $releaseReceipt instanceof AtlasSelfConstructionAgentDispatchReceipt
                && $expectedDispatchEnvelopeHash !== null
                && $releaseReceipt->dispatch_envelope_hash === $expectedDispatchEnvelopeHash,
            'release_receipt_payload_forbids_provider_start' => data_get($releasePayload, 'provider_start_allowed_by_writer') === false,
            'release_receipt_payload_forbids_dispatch_receipt_write' => data_get($releasePayload, 'dispatch_receipt_write_allowed_by_writer') === false,
            'release_receipt_payload_forbids_self_programming' => data_get($releasePayload, 'self_programming_allowed_by_writer') === false,
            'selected_wakeup_still_queued' => data_get($selectedWakeupItem, 'status') === 'queued',
            'selected_wakeup_not_claimed' => is_array($selectedWakeupItem)
                && data_get($selectedWakeupItem, 'claimed_at') === null,
        ];
        $failedComponentChecks = array_values(array_keys(array_filter(
            $componentReadiness,
            static fn (bool $passed): bool => ! $passed,
        )));
        $failedReleaseChecks = array_values(array_keys(array_filter(
            $releaseChecks,
            static fn (bool $passed): bool => ! $passed,
        )));
        $blockingReasons = array_values(array_unique(array_merge($failedComponentChecks, $failedReleaseChecks)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_mutating_writer_release_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-MUTATING-WRITER-RELEASE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'source_persistence_status_hash' => data_get($persistenceStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_persistence_status_hash'),
            'source_dry_run_tick_hash' => data_get($dryRunTickPayload, 'agent_automatic_dispatch_scheduler_dry_run_tick_hash'),
            'component_readiness' => $componentReadiness,
            'release_checks' => $releaseChecks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'failed_component_checks' => $failedComponentChecks,
            'failed_release_checks' => $failedReleaseChecks,
            'selected_candidate' => [
                'selected_wakeup_key' => $selectedWakeupKey,
                'packet_id' => data_get($selectedWakeupItem, 'packet_id'),
                'provider' => data_get($selectedWakeupItem, 'provider'),
                'actor' => data_get($selectedWakeupItem, 'actor'),
            ],
            'release_receipt' => $releaseReceipt instanceof AtlasSelfConstructionAgentDispatchReceipt
                ? [
                    'receipt_id' => $releaseReceipt->id,
                    'receipt_key' => $releaseReceipt->receipt_key,
                    'receipt_hash' => $releaseReceipt->receipt_hash,
                    'decision' => $releaseReceipt->decision,
                    'status' => $releaseReceipt->status,
                    'signed_by' => $releaseReceipt->signed_by,
                    'signed_at' => $releaseReceipt->signed_at?->toIso8601String(),
                    'expires_at' => $releaseReceipt->expires_at?->toIso8601String(),
                    'dispatch_envelope_hash' => $releaseReceipt->dispatch_envelope_hash,
                    'payload_selected_wakeup_key' => $releaseWakeupKey === '' ? null : $releaseWakeupKey,
                ]
                : null,
            'dispatch_envelope' => [
                'expected_dispatch_envelope_hash' => $expectedDispatchEnvelopeHash,
                'release_receipt_dispatch_envelope_hash' => $releaseReceipt?->dispatch_envelope_hash,
            ],
            'mutation_policy' => [
                'preflight_is_read_only' => true,
                'mutation_allowed_here' => false,
                'future_one_shot_tick_mutation_requires_this_preflight_ready' => true,
                'future_tick_may_claim_one_wakeup_after_separate_contract' => true,
                'future_tick_may_write_one_dispatch_receipt_after_separate_contract' => true,
                'future_tick_must_stop_before_receipt_use' => true,
                'future_tick_must_stop_before_provider_start' => true,
                'provider_start_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'forbidden_now' => [
                'claim_wakeup_item',
                'write_dispatch_receipt',
                'mark_dispatch_receipt_used',
                'start_provider_process',
                'invoke_provider_adapter',
                'spend_provider_tokens',
                'enable_self_programming',
            ],
            'next_required_slice' => $blockingReasons === []
                ? 'activate_signed_one_shot_scheduler_tick_mutating_writer_contract'
                : 'repair_signed_one_shot_scheduler_tick_mutating_writer_release_preflight_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_release_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_release_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'signature_acceptance_allowed' => false,
            'release_receipt_persistence_allowed' => false,
            'claim_allowed' => false,
            'dispatch_receipt_write_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_release_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_release_preflight_hash' => $this->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_release_preflight_does_not_accept_signatures',
                'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_release_preflight_does_not_persist_release_receipts',
                'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_release_preflight_does_not_claim_wakeup_items',
                'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_release_preflight_does_not_write_dispatch_receipts',
                'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_release_preflight_does_not_start_providers',
                'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_release_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick mutating writer release preflight is ready; a future contract may permit one wakeup claim and one dispatch receipt write, still stopping before provider start.'
                : 'Automatic dispatch scheduler one-shot tick mutating writer release preflight is blocked until a fresh persisted release receipt matches the current scheduler candidate and dispatch envelope.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickMutatingWriterPreflight(array $options = []): array
    {
        $contractPayload = $this->agentAutomaticDispatchSchedulerOneShotTickMutatingWriterContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_contract_hash');
        $wakeupTable = 'atlas_self_construction_agent_wakeup_items';
        $dispatchReceiptTable = 'atlas_self_construction_agent_dispatch_receipts';
        $ledgerTable = 'atlas_ledger_events';
        $wakeupTableReady = Schema::hasTable($wakeupTable);
        $dispatchReceiptTableReady = Schema::hasTable($dispatchReceiptTable);
        $ledgerTableReady = Schema::hasTable($ledgerTable);
        $requiredWakeupColumns = [
            'id',
            'wakeup_key',
            'packet_id',
            'actor',
            'provider',
            'status',
            'claimed_at',
        ];
        $requiredDispatchReceiptColumns = [
            'id',
            'wakeup_item_id',
            'receipt_key',
            'packet_id',
            'provider',
            'decision',
            'status',
            'signed_by',
            'signed_at',
            'expires_at',
            'dispatch_envelope_hash',
            'receipt_hash',
            'payload',
        ];
        $wakeupColumns = array_fill_keys($requiredWakeupColumns, false);
        $dispatchReceiptColumns = array_fill_keys($requiredDispatchReceiptColumns, false);

        if ($wakeupTableReady) {
            foreach ($requiredWakeupColumns as $column) {
                $wakeupColumns[$column] = Schema::hasColumn($wakeupTable, $column);
            }
        }

        if ($dispatchReceiptTableReady) {
            foreach ($requiredDispatchReceiptColumns as $column) {
                $dispatchReceiptColumns[$column] = Schema::hasColumn($dispatchReceiptTable, $column);
            }
        }

        $preflightChecks = [
            'mutating_writer_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_mutating_writer_contract_ready',
            'mutating_writer_contract_hash_present' => $contractHash !== '',
            'contract_method_defined' => data_get($contract, 'contract_method') === 'executeOneShotSchedulerTickAfterReleasePreflight',
            'max_one_wakeup_claim' => data_get($contract, 'writer_scope.max_wakeup_claims_per_invocation') === 1,
            'max_one_dispatch_receipt' => data_get($contract, 'writer_scope.max_dispatch_receipts_per_invocation') === 1,
            'wakeup_table_ready' => $wakeupTableReady,
            'dispatch_receipts_table_ready' => $dispatchReceiptTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'wakeup_model_exists' => class_exists(AtlasSelfConstructionAgentWakeupItem::class),
            'dispatch_receipt_model_exists' => class_exists(AtlasSelfConstructionAgentDispatchReceipt::class),
            'wakeup_required_columns_ready' => $wakeupColumns !== [] && ! in_array(false, $wakeupColumns, true),
            'dispatch_receipt_required_columns_ready' => $dispatchReceiptColumns !== [] && ! in_array(false, $dispatchReceiptColumns, true),
            'atomic_sequence_requires_transaction' => in_array('open_database_transaction', (array) data_get($contract, 'atomic_mutation_sequence', []), true),
            'atomic_sequence_requires_release_preflight_recompute' => in_array('recompute_release_preflight_inside_transaction', (array) data_get($contract, 'atomic_mutation_sequence', []), true),
            'atomic_sequence_requires_wakeup_lock' => in_array('lock_selected_wakeup_row_for_update', (array) data_get($contract, 'atomic_mutation_sequence', []), true),
            'atomic_sequence_stops_before_receipt_use' => in_array('commit_transaction_and_stop_before_receipt_use', (array) data_get($contract, 'atomic_mutation_sequence', []), true),
            'provider_start_forbidden' => data_get($contract, 'writer_scope.provider_start_allowed') === false,
            'receipt_use_forbidden' => data_get($contract, 'writer_scope.receipt_use_allowed') === false,
            'adapter_invocation_forbidden' => data_get($contract, 'writer_scope.adapter_invocation_allowed') === false,
            'token_spend_forbidden' => data_get($contract, 'writer_scope.token_spend_allowed') === false,
            'self_programming_forbidden' => data_get($contract, 'writer_scope.self_programming_allowed') === false,
        ];
        $failedChecks = array_values(array_keys(array_filter(
            $preflightChecks,
            static fn (bool $passed): bool => ! $passed,
        )));
        $blockingReasons = array_values(array_unique($failedChecks));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_mutating_writer_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-MUTATING-WRITER-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'workspace_id' => 'FORGE-WORKSPACE-ATLAS-SELF-CONSTRUCTION-0001',
            'source_mutating_writer_contract_hash' => $contractHash,
            'source_release_preflight_status' => data_get($contract, 'source_release_preflight_status'),
            'preflight_checks' => $preflightChecks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'failed_preflight_checks' => $failedChecks,
            'storage_readiness' => [
                'wakeup_table' => $wakeupTable,
                'wakeup_table_ready' => $wakeupTableReady,
                'wakeup_model' => AtlasSelfConstructionAgentWakeupItem::class,
                'wakeup_model_exists' => class_exists(AtlasSelfConstructionAgentWakeupItem::class),
                'wakeup_required_columns' => $wakeupColumns,
                'dispatch_receipts_table' => $dispatchReceiptTable,
                'dispatch_receipts_table_ready' => $dispatchReceiptTableReady,
                'dispatch_receipt_model' => AtlasSelfConstructionAgentDispatchReceipt::class,
                'dispatch_receipt_model_exists' => class_exists(AtlasSelfConstructionAgentDispatchReceipt::class),
                'dispatch_receipt_required_columns' => $dispatchReceiptColumns,
                'ledger_table' => $ledgerTable,
                'ledger_table_ready' => $ledgerTableReady,
            ],
            'implementation_requirements' => [
                'recompute_release_preflight_inside_transaction',
                'lock_selected_wakeup_row_for_update',
                'verify_release_receipt_still_unexpired',
                'verify_selected_wakeup_is_still_queued_and_unclaimed',
                'claim_exactly_one_wakeup_item',
                'write_exactly_one_signed_pending_dispatch_receipt',
                'append_scheduler_tick_evidence_event',
                'rollback_claim_and_receipt_if_evidence_write_fails',
                'stop_before_dispatch_receipt_use',
                'never_start_provider_in_mutating_writer',
                'never_invoke_provider_adapter_in_mutating_writer',
            ],
            'writer_policy' => [
                'preflight_is_read_only' => true,
                'implementation_allowed_after_packet' => true,
                'runtime_mutation_allowed_here' => false,
                'claim_allowed_here' => false,
                'dispatch_receipt_write_allowed_here' => false,
                'provider_start_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'forbidden_now' => [
                'claim_wakeup_item',
                'write_dispatch_receipt',
                'mark_dispatch_receipt_used',
                'start_provider_process',
                'invoke_provider_adapter',
                'spend_provider_tokens',
                'enable_self_programming',
            ],
            'next_required_slice' => $blockingReasons === []
                ? 'activate_signed_one_shot_scheduler_tick_mutating_writer_implementation_packet'
                : 'repair_signed_one_shot_scheduler_tick_mutating_writer_preflight_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'signature_acceptance_allowed' => false,
            'release_receipt_persistence_allowed' => false,
            'claim_allowed' => false,
            'dispatch_receipt_write_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_preflight_hash' => $this->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_preflight_does_not_claim_wakeup_items',
                'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_preflight_does_not_write_dispatch_receipts',
                'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_preflight_does_not_use_dispatch_receipts',
                'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_preflight_does_not_start_providers',
                'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick mutating writer preflight is ready; the next slice can generate the scoped implementation packet without mutating runtime.'
                : 'Automatic dispatch scheduler one-shot tick mutating writer preflight is blocked until contract, storage and atomic guard requirements are ready; no runtime mutation was performed.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickReleaseReceiptPersistenceWriterImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->agentAutomaticDispatchSchedulerOneShotTickReleaseReceiptPersistenceWriterPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_persistence_writer_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_persistence_writer_preflight_hash');

        $allowedFiles = [
            'app/Services/Ai/SelfConstruction/ControlPlane/AgentAutomaticDispatchSchedulerOneShotTickReleaseReceiptPersistenceWriter.php',
            'tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickReleaseReceiptPersistenceWriterTest.php',
            'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
            'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
            'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
            'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
        ];

        $tasks = [
            [
                'id' => 'T1',
                'title' => 'Create guarded release receipt persistence writer service',
                'type' => 'service',
                'allowed_files' => [
                    'app/Services/Ai/SelfConstruction/ControlPlane/AgentAutomaticDispatchSchedulerOneShotTickReleaseReceiptPersistenceWriter.php',
                ],
                'acceptance' => 'Writer validates signed receipt payload, current preflight hashes, selected wakeup identity, dispatch envelope hash, scope hash and expiry inside a transaction before persisting exactly one release receipt.',
            ],
            [
                'id' => 'T2',
                'title' => 'Persist release receipt idempotently without claiming or dispatching',
                'type' => 'runtime_guard',
                'allowed_files' => [
                    'app/Services/Ai/SelfConstruction/ControlPlane/AgentAutomaticDispatchSchedulerOneShotTickReleaseReceiptPersistenceWriter.php',
                ],
                'acceptance' => 'Same idempotency key returns the existing release receipt; conflicts are rejected; writer never claims wakeups, never writes dispatch receipts and never starts providers.',
            ],
            [
                'id' => 'T3',
                'title' => 'Add focused persistence writer feature tests',
                'type' => 'test',
                'allowed_files' => [
                    'tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickReleaseReceiptPersistenceWriterTest.php',
                ],
                'acceptance' => 'Tests cover successful write, idempotent replay, missing signature rejection, expired signature rejection, stale hash rejection, duplicate conflict rejection and no provider/dispatch side effects.',
            ],
            [
                'id' => 'T4',
                'title' => 'Expose writer readiness/status through read-only Self-Construction projection',
                'type' => 'projection',
                'allowed_files' => [
                    'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                    'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                    'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                ],
                'acceptance' => 'Command can inspect writer readiness and persisted release receipts without accepting signatures, claiming wakeups, writing dispatch receipts or starting providers.',
            ],
            [
                'id' => 'T5',
                'title' => 'Update Agent Control Plane contract documentation',
                'type' => 'documentation',
                'allowed_files' => [
                    'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
                ],
                'acceptance' => 'Documentation records the writer boundary, allowed mutations, forbidden side effects, required gates and next slice after persistence writer activation.',
            ],
        ];

        $packet = [
            'status' => 'ready_for_scoped_release_receipt_persistence_writer_implementation',
            'implementation_packet_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-RELEASE-RECEIPT-PERSISTENCE-WRITER-IMPLEMENTATION-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'workspace_id' => 'FORGE-WORKSPACE-ATLAS-SELF-CONSTRUCTION-0001',
            'source_preflight_status' => data_get($preflightPayload, 'status'),
            'source_preflight_hash' => $preflightHash,
            'objective' => 'Implement the guarded one-shot scheduler tick release receipt persistence writer so Atlas can persist an explicit signed release receipt before a future mutating one-shot scheduler tick, while still forbidding wakeup claim, dispatch receipt write, provider start and self-programming.',
            'non_goals' => [
                'do_not_accept_signatures_in_readiness_projection',
                'do_not_claim_wakeup_items',
                'do_not_write_dispatch_receipts',
                'do_not_mark_dispatch_receipts_used',
                'do_not_start_provider_processes',
                'do_not_invoke_provider_adapters',
                'do_not_spend_provider_tokens',
                'do_not_enable_self_programming',
            ],
            'allowed_files' => $allowedFiles,
            'forbidden_scopes' => [
                'provider_process_start',
                'adapter_invocation_runtime',
                'dispatch_executor_runtime',
                'dispatch_receipt_use_writer',
                'packet_claim_or_completion_state',
                'hot_kernel_runtime',
                'voice_runtime',
                'policy_mutation',
                'self_programming_runtime',
            ],
            'tasks' => $tasks,
            'task_count' => count($tasks),
            'acceptance_criteria' => [
                'signed_scheduler_tick_release_receipt_can_be_persisted_once_after_current_validation_preflight_is_ready',
                'same_signed_receipt_replay_returns_existing_release_receipt',
                'stale_source_hashes_are_rejected',
                'expired_or_missing_signature_metadata_is_rejected',
                'selected_wakeup_identity_must_still_match_pending_scheduler_candidate',
                'release_receipt_payload_is_auditable_and_hash_addressed',
                'writer_does_not_claim_wakeup_items_write_dispatch_receipts_start_providers_or_spend_tokens',
            ],
            'required_gates' => [
                'php -l app/Services/Ai/SelfConstruction/ControlPlane/AgentAutomaticDispatchSchedulerOneShotTickReleaseReceiptPersistenceWriter.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickReleaseReceiptPersistenceWriterTest.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'source_preflight_requirements' => data_get($preflight, 'implementation_requirements', []),
            'stop_conditions' => [
                'need_to_start_provider_or_call_adapter',
                'need_to_claim_wakeup_inside_release_receipt_writer',
                'need_to_write_or_use_dispatch_receipt_inside_release_receipt_writer',
                'need_to_mutate_packet_claim_or_completion_state',
                'need_to_modify_file_outside_allowed_files',
                'signature_authority_contract_is_missing_or_ambiguous',
                'append_only_evidence_event_contract_is_missing_or_ambiguous',
            ],
            'implementation_policy' => [
                'packet_is_read_only' => true,
                'implementation_allowed_by_packet' => true,
                'release_receipt_persistence_allowed_by_future_writer' => true,
                'signature_acceptance_allowed_by_packet' => false,
                'claim_allowed_by_packet' => false,
                'dispatch_receipt_write_allowed_by_packet' => false,
                'provider_start_allowed_by_packet' => false,
                'adapter_invocation_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'self_programming_allowed_by_packet' => false,
                'requires_separate_mutating_scheduler_tick_release' => true,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_release_receipt_persistence_writer_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_persistence_writer_implementation_packet.v1',
            'status' => 'ready_for_scoped_release_receipt_persistence_writer_implementation',
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_persistence_writer_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'signature_acceptance_allowed' => false,
            'release_receipt_persistence_allowed' => false,
            'claim_allowed' => false,
            'dispatch_receipt_write_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_persistence_writer_implementation_packet' => $packet,
            'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_persistence_writer_implementation_packet_hash' => $this->stableHash($packet),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_persistence_writer_implementation_packet_does_not_accept_signatures',
                'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_persistence_writer_implementation_packet_does_not_persist_release_receipts',
                'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_persistence_writer_implementation_packet_does_not_claim_wakeup_items',
                'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_persistence_writer_implementation_packet_does_not_write_dispatch_receipts',
                'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_persistence_writer_implementation_packet_does_not_start_providers',
                'agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_persistence_writer_implementation_packet_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick release receipt persistence writer implementation packet is ready; it defines scoped implementation work but does not create writer files, persist receipts, claim wakeups or start providers.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickMutatingWriterImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->agentAutomaticDispatchSchedulerOneShotTickMutatingWriterPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_preflight_hash');

        $allowedFiles = [
            'app/Services/Ai/SelfConstruction/ControlPlane/AgentAutomaticDispatchSchedulerOneShotTickMutatingWriter.php',
            'tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickMutatingWriterTest.php',
            'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
            'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
            'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
            'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
        ];

        $tasks = [
            [
                'id' => 'T1',
                'title' => 'Create guarded one-shot scheduler tick mutating writer service',
                'type' => 'service',
                'allowed_files' => [
                    'app/Services/Ai/SelfConstruction/ControlPlane/AgentAutomaticDispatchSchedulerOneShotTickMutatingWriter.php',
                ],
                'acceptance' => 'Writer recomputes release preflight inside a transaction, locks the selected queued wakeup row and refuses to proceed unless the persisted release receipt still matches the current dry-run candidate.',
            ],
            [
                'id' => 'T2',
                'title' => 'Claim exactly one wakeup and write exactly one signed pending dispatch receipt',
                'type' => 'runtime_guard',
                'allowed_files' => [
                    'app/Services/Ai/SelfConstruction/ControlPlane/AgentAutomaticDispatchSchedulerOneShotTickMutatingWriter.php',
                ],
                'acceptance' => 'Writer atomically marks one wakeup as claimed, writes one signed_pending_dispatch receipt, appends evidence and rolls back the full transaction on any claim, receipt or evidence failure.',
            ],
            [
                'id' => 'T3',
                'title' => 'Guarantee the writer stops before receipt use and provider start',
                'type' => 'safety_boundary',
                'allowed_files' => [
                    'app/Services/Ai/SelfConstruction/ControlPlane/AgentAutomaticDispatchSchedulerOneShotTickMutatingWriter.php',
                ],
                'acceptance' => 'Writer never marks dispatch receipts used, never invokes provider adapters, never starts providers, never spends provider tokens and never enables Self-Programming OS behavior.',
            ],
            [
                'id' => 'T4',
                'title' => 'Add dedicated mutating writer feature tests',
                'type' => 'test',
                'allowed_files' => [
                    'tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickMutatingWriterTest.php',
                ],
                'acceptance' => 'Tests cover successful one-shot claim/write/evidence, idempotent replay, stale release receipt rejection, expired release rejection, already-claimed wakeup rejection, dispatch hash mismatch rejection, evidence rollback and no provider side effects.',
            ],
            [
                'id' => 'T5',
                'title' => 'Expose mutating writer service status through read-only Self-Construction projection',
                'type' => 'projection',
                'allowed_files' => [
                    'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                    'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                    'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                ],
                'acceptance' => 'Command can report writer service readiness, claim/write counts and next runtime slice without running a scheduler tick or starting providers.',
            ],
            [
                'id' => 'T6',
                'title' => 'Update Agent Control Plane documentation',
                'type' => 'documentation',
                'allowed_files' => [
                    'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
                ],
                'acceptance' => 'Documentation names the one-shot mutating writer service boundary, atomic mutation limits, rollback policy and provider/self-programming prohibitions.',
            ],
        ];

        $packet = [
            'status' => 'ready_for_scoped_one_shot_tick_mutating_writer_implementation',
            'packet_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-MUTATING-WRITER-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'workspace_id' => 'FORGE-WORKSPACE-ATLAS-SELF-CONSTRUCTION-0001',
            'source_mutating_writer_preflight_status' => data_get($preflight, 'status'),
            'source_mutating_writer_preflight_hash' => $preflightHash,
            'task_count' => count($tasks),
            'tasks' => $tasks,
            'allowed_files' => $allowedFiles,
            'non_goals' => [
                'do_not_use_dispatch_receipt',
                'do_not_start_provider_process',
                'do_not_invoke_provider_adapter',
                'do_not_spend_provider_tokens',
                'do_not_merge_work_products',
                'do_not_enable_self_programming',
            ],
            'acceptance_criteria' => [
                'writer_recomputes_release_preflight_inside_transaction',
                'writer_claims_exactly_one_wakeup_item',
                'writer_writes_exactly_one_signed_pending_dispatch_receipt',
                'writer_appends_evidence_event_before_commit',
                'writer_rolls_back_claim_and_receipt_if_evidence_write_fails',
                'writer_is_idempotent_by_tick_key',
                'writer_rejects_stale_or_expired_release_receipts',
                'writer_stops_before_dispatch_receipt_use_and_provider_start',
            ],
            'required_gates' => [
                'php_lint_new_writer',
                'php_lint_readiness_service',
                'php_lint_command',
                'pint_touched_files',
                'dedicated_mutating_writer_feature_tests',
                'focused_self_construction_command_tests',
                'architecture_validate',
                'docs_health',
                'git_diff_check',
            ],
            'implementation_policy' => [
                'implementation_allowed_by_packet' => true,
                'runtime_mutation_allowed_by_packet' => true,
                'claim_allowed_by_packet_after_release_preflight_ready' => true,
                'dispatch_receipt_write_allowed_by_packet_after_release_preflight_ready' => true,
                'dispatch_receipt_use_allowed_by_packet' => false,
                'provider_start_allowed_by_packet' => false,
                'adapter_invocation_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'self_programming_allowed_by_packet' => false,
            ],
            'stop_conditions' => [
                'release_preflight_not_ready_at_runtime',
                'selected_wakeup_missing_or_already_claimed',
                'release_receipt_expired_or_mismatched',
                'dispatch_envelope_hash_mismatch',
                'evidence_write_failed',
                'need_to_use_dispatch_receipt',
                'need_to_start_provider_or_call_adapter',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_mutating_writer_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'signature_acceptance_allowed' => false,
            'release_receipt_persistence_allowed' => false,
            'claim_allowed' => false,
            'dispatch_receipt_write_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_implementation_packet' => $packet,
            'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_implementation_packet_hash' => $this->stableHash($packet),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_implementation_packet_does_not_claim_wakeup_items',
                'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_implementation_packet_does_not_write_dispatch_receipts',
                'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_implementation_packet_does_not_use_dispatch_receipts',
                'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_implementation_packet_does_not_start_providers',
                'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_implementation_packet_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick mutating writer implementation packet is ready; it scopes the future service that may claim one wakeup and write one dispatch receipt after release preflight, while still forbidding provider start.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerDryRunTick(array $options = []): array
    {
        $runtimeExecutionGatePayload = $this->agentAutomaticDispatchSchedulerRuntimeExecutionGate($options);
        $dispatchPreflightPayload = $this->agentDispatchPreflight($options);
        $executorPreflightPayload = $this->agentDispatchExecutorPreflight($options);
        $runtimeTables = $this->agentControlPlaneRuntimeTables();
        $now = CarbonImmutable::now();

        $readyWakeupItems = [];
        $selectedWakeupItem = null;
        $queuedReadyWakeupCount = null;

        if ($runtimeTables['atlas_self_construction_agent_wakeup_items']) {
            $readyWakeupItems = AtlasSelfConstructionAgentWakeupItem::query()
                ->where('status', 'queued')
                ->where(function ($query) use ($now): void {
                    $query
                        ->whereNull('scheduled_for')
                        ->orWhere('scheduled_for', '<=', $now);
                })
                ->orderByRaw("case priority when 'critical' then 0 when 'high' then 1 when 'normal' then 2 when 'low' then 3 else 4 end")
                ->orderByRaw('scheduled_for is null')
                ->orderBy('scheduled_for')
                ->orderBy('created_at')
                ->limit(10)
                ->get()
                ->map(fn (AtlasSelfConstructionAgentWakeupItem $item): array => $this->agentWakeupItemPreview($item))
                ->all();

            $queuedReadyWakeupCount = AtlasSelfConstructionAgentWakeupItem::query()
                ->where('status', 'queued')
                ->where(function ($query) use ($now): void {
                    $query
                        ->whereNull('scheduled_for')
                        ->orWhere('scheduled_for', '<=', $now);
                })
                ->count();
            $selectedWakeupItem = $readyWakeupItems[0] ?? null;
        }

        $componentReadiness = [
            'runtime_execution_gate' => data_get($runtimeExecutionGatePayload, 'status') === 'agent_automatic_dispatch_scheduler_runtime_execution_gate_ready',
            'wakeup_items_table' => $runtimeTables['atlas_self_construction_agent_wakeup_items'],
            'dispatch_receipts_table' => $runtimeTables['atlas_self_construction_agent_dispatch_receipts'],
            'agent_runs_table' => $runtimeTables['atlas_self_construction_agent_runs'],
            'dispatch_preflight_contract_available' => in_array(data_get($dispatchPreflightPayload, 'status'), ['agent_dispatch_preflight_ready', 'blocked'], true),
            'dispatch_executor_preflight_contract_available' => in_array(data_get($executorPreflightPayload, 'status'), ['agent_dispatch_executor_preflight_ready', 'blocked'], true),
        ];
        $blockingReasons = array_values(array_map(
            static fn (string $component): string => $component.'_not_ready',
            array_keys(array_filter($componentReadiness, static fn (bool $ready): bool => ! $ready))
        ));

        $dispatchEnvelopePreview = $selectedWakeupItem === null
            ? null
            : [
                'tick_mode' => 'read_only_dry_run_simulation',
                'wakeup_key' => data_get($selectedWakeupItem, 'wakeup_key'),
                'packet_id' => data_get($selectedWakeupItem, 'packet_id'),
                'provider' => data_get($selectedWakeupItem, 'provider'),
                'actor' => data_get($selectedWakeupItem, 'actor'),
                'reason' => data_get($selectedWakeupItem, 'reason'),
                'idempotency_key' => hash('sha256', implode('|', [
                    (string) data_get($selectedWakeupItem, 'wakeup_key'),
                    (string) data_get($selectedWakeupItem, 'packet_id'),
                    (string) data_get($selectedWakeupItem, 'provider'),
                    'dry_run_preview',
                ])),
                'would_prepare_dispatch_preflight' => true,
                'would_request_signed_dispatch_receipt' => true,
                'would_stop_before_receipt_use' => true,
                'would_stop_before_provider_start' => true,
            ];

        $dryRunTick = [
            'status' => $blockingReasons === [] ? 'agent_automatic_dispatch_scheduler_dry_run_tick_ready' : 'blocked',
            'tick_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-DRY-RUN-TICK-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'source_runtime_execution_gate_hash' => data_get($runtimeExecutionGatePayload, 'agent_automatic_dispatch_scheduler_runtime_execution_gate_hash'),
            'component_readiness' => $componentReadiness,
            'component_preflight_hashes' => [
                'runtime_execution_gate' => data_get($runtimeExecutionGatePayload, 'agent_automatic_dispatch_scheduler_runtime_execution_gate_hash'),
                'dispatch_preflight' => data_get($dispatchPreflightPayload, 'dispatch_preflight_hash'),
                'dispatch_executor_preflight' => data_get($executorPreflightPayload, 'dispatch_executor_preflight_hash'),
            ],
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'tick_policy' => [
                'tick_is_read_only_simulation' => true,
                'max_candidate_items' => 1,
                'runtime_write_allowed_here' => false,
                'claim_allowed_here' => false,
                'dispatch_receipt_write_allowed_here' => false,
                'provider_start_allowed_here' => false,
                'token_spend_allowed_here' => false,
            ],
            'queue_projection' => [
                'queued_ready_count' => $queuedReadyWakeupCount,
                'preview_count' => count($readyWakeupItems),
                'selection_order' => ['priority', 'scheduled_for', 'created_at'],
                'selected_wakeup_item' => $selectedWakeupItem,
                'candidate_wakeup_items' => $readyWakeupItems,
            ],
            'dispatch_envelope_preview' => $dispatchEnvelopePreview,
            'would_do_after_future_signed_mutating_release' => [
                'claim_selected_wakeup_item_atomically',
                'materialize_dispatch_decision_evidence',
                'write_or_request_signed_dispatch_receipt',
                'stop_before_receipt_use_and_provider_start',
            ],
            'forbidden_now' => [
                'claim_wakeup_item',
                'write_dispatch_receipt',
                'mark_dispatch_receipt_used',
                'start_provider_process',
                'invoke_provider_adapter',
                'spend_provider_tokens',
                'self_program_or_self_merge',
            ],
            'next_required_slice' => $blockingReasons === []
                ? 'activate_signed_one_shot_scheduler_tick_writer_contract'
                : 'repair_automatic_dispatch_scheduler_dry_run_tick_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_dry_run_tick.v1',
            'status' => (string) $dryRunTick['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_dry_run_tick',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'read_only_tick_simulation_allowed' => true,
            'scheduler_runtime_execution_allowed' => false,
            'claim_allowed' => false,
            'dispatch_receipt_write_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_dry_run_tick' => $dryRunTick,
            'agent_automatic_dispatch_scheduler_dry_run_tick_hash' => $this->stableHash($dryRunTick),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_dry_run_tick_does_not_claim_wakeup_items',
                'agent_automatic_dispatch_scheduler_dry_run_tick_does_not_write_dispatch_receipts',
                'agent_automatic_dispatch_scheduler_dry_run_tick_does_not_mark_receipts_used',
                'agent_automatic_dispatch_scheduler_dry_run_tick_does_not_start_providers',
                'agent_automatic_dispatch_scheduler_dry_run_tick_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler dry-run tick is ready: Atlas can simulate the next scheduler candidate and dispatch envelope without claiming, writing receipts, starting providers or spending tokens.'
                : 'Automatic dispatch scheduler dry-run tick is blocked until every runtime prerequisite is ready.',
        ];
    }

}
