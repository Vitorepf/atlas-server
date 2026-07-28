<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness\DispatchProvider;

use App\Models\AtlasSelfConstructionAgentDispatchExecutorReleaseAuthorization;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentDispatchExecutorReleaseAuthorizationPersistenceWriter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Closure;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentDispatchProviderSection;

/**
 * GOD-DEBULK sub-split part 04 of {@see ReadinessProjectionAgentDispatchProviderSection}.
 *
 * Bodies moved VERBATIM from the facade (A1-SC-0056 dispatch-receipt guard
 * lives in part 01, byte-identical). The injected $stableHash closure keeps
 * every ($this->stableHash)(...) call unchanged, and __call routes every
 * sibling/mother back-call through the facade exactly as before.
 */
final class DispatchProviderPart04SubSection
{
    public function __construct(
        private readonly ReadinessProjectionAgentDispatchProviderSection $section,
        private readonly \Closure $stableHash,
    ) {}

    public function __call(string $name, array $arguments): mixed
    {
        return $this->section->$name(...$arguments);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentDispatchExecutorReleaseAuthorizationPersistenceTemplate(array $options = []): array
    {
        $preflightPayload = $this->agentDispatchExecutorReleaseAuthorizationSignedReceiptPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'dispatch_executor_release_authorization_signed_receipt_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'dispatch_executor_release_authorization_signed_receipt_preflight_hash');

        $template = [
            'status' => 'blocked_before_authorization_persistence_writer',
            'source_signed_receipt_preflight_hash' => $preflightHash,
            'receipt_key' => data_get($preflight, 'receipt_key'),
            'authorization_id' => data_get($preflight, 'authorization_id'),
            'provider' => data_get($preflight, 'provider'),
            'provider_role' => data_get($preflight, 'provider_role'),
            'packet_id' => data_get($preflight, 'packet_id'),
            'persistence_target' => [
                'table' => 'atlas_self_construction_agent_dispatch_authorizations',
                'record_key_column' => 'authorization_key',
                'idempotency_column' => 'signed_receipt_hash',
                'status_column' => 'status',
                'append_only_ledger_event_type' => 'self_construction.agent_dispatch_executor_release_authorization.persisted',
            ],
            'required_columns' => [
                'authorization_key',
                'receipt_key',
                'authorization_id',
                'packet_id',
                'provider',
                'provider_role',
                'decision',
                'status',
                'signed_by',
                'signed_at',
                'expires_at',
                'signed_receipt_template_hash',
                'signed_receipt_preflight_hash',
                'signed_receipt_hash',
                'payload',
                'persisted_at',
            ],
            'required_atomic_guards' => [
                'unique_authorization_key',
                'unique_signed_receipt_hash',
                'reject_expired_signed_receipt',
                'reject_reused_signed_receipt',
                'reject_missing_external_signature_validation_report',
                'reject_hard_denial_condition',
                'write_authorization_and_ledger_event_in_same_transaction',
            ],
            'required_before_persistence_writer' => [
                'authorization_persistence_migration_exists' => false,
                'authorization_model_exists' => false,
                'authorization_repository_exists' => false,
                'external_signature_validation_report_exists' => false,
                'signed_payload_values_exist' => false,
                'append_only_event_writer_exists' => false,
                'idempotency_guard_exists' => false,
            ],
            'persistence_policy' => [
                'template_is_read_only' => true,
                'authorization_persistence_allowed_here' => false,
                'ledger_write_allowed_here' => false,
                'receipt_use_mark_allowed_here' => false,
                'release_allowed_by_template' => false,
                'provider_start_allowed_by_template' => false,
                'requires_separate_persistence_preflight' => true,
                'requires_separate_atomic_writer' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-dispatch-executor-release-authorization-persistence-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_dispatch_executor_release_authorization_persistence_template.v1',
            'status' => 'blocked',
            'mode' => 'read_only_agent_dispatch_executor_release_authorization_persistence_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'dispatch_executor_release_authorization_persistence_template' => $template,
            'dispatch_executor_release_authorization_persistence_template_hash' => ($this->stableHash)($template),
            'non_execution_guarantees' => [
                'agent_dispatch_executor_release_authorization_persistence_template_does_not_start_providers',
                'agent_dispatch_executor_release_authorization_persistence_template_does_not_mark_receipt_used',
                'agent_dispatch_executor_release_authorization_persistence_template_does_not_dispatch_work',
                'agent_dispatch_executor_release_authorization_persistence_template_does_not_accept_signatures',
                'agent_dispatch_executor_release_authorization_persistence_template_does_not_validate_signatures',
                'agent_dispatch_executor_release_authorization_persistence_template_does_not_persist_authorization',
                'agent_dispatch_executor_release_authorization_persistence_template_does_not_write_ledger',
            ],
            'human_summary' => 'Agent dispatch executor release authorization persistence template is blocked/read-only; it defines the future storage, idempotency and ledger contract without persisting authorization or releasing providers.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentDispatchExecutorReleaseAuthorizationPersistencePreflight(array $options = []): array
    {
        $templatePayload = $this->agentDispatchExecutorReleaseAuthorizationPersistenceTemplate($options);
        $template = (array) data_get($templatePayload, 'dispatch_executor_release_authorization_persistence_template', []);
        $templateHash = (string) data_get($templatePayload, 'dispatch_executor_release_authorization_persistence_template_hash');
        $targetTable = (string) data_get($template, 'persistence_target.table');

        $migrationExists = file_exists(database_path('migrations/2026_05_12_020000_create_atlas_self_construction_agent_dispatch_executor_release_authorizations_table.php'));
        $modelExists = class_exists(AtlasSelfConstructionAgentDispatchExecutorReleaseAuthorization::class);
        $schemaExists = $targetTable !== '' && Schema::hasTable($targetTable);

        $blockingReasons = array_values(array_filter([
            $migrationExists ? null : 'authorization_persistence_migration_missing',
            $modelExists ? null : 'authorization_model_missing',
            $schemaExists ? null : 'authorization_persistence_table_missing',
            'authorization_repository_missing',
            'external_signature_validation_report_missing',
            'signed_payload_values_missing',
            'append_only_event_writer_missing',
            'idempotency_guard_missing',
            'atomic_transaction_boundary_missing',
            'receipt_use_writer_missing',
            'provider_release_still_disabled',
        ]));

        $preflight = [
            'status' => 'blocked',
            'blocking_reasons' => $blockingReasons,
            'blocking_count' => count($blockingReasons),
            'persistence_template_hash' => $templateHash,
            'source_signed_receipt_preflight_hash' => data_get($template, 'source_signed_receipt_preflight_hash'),
            'receipt_key' => data_get($template, 'receipt_key'),
            'authorization_id' => data_get($template, 'authorization_id'),
            'provider' => data_get($template, 'provider'),
            'provider_role' => data_get($template, 'provider_role'),
            'packet_id' => data_get($template, 'packet_id'),
            'storage_checks' => [
                'migration_exists' => $migrationExists,
                'model_exists' => $modelExists,
                'table_exists' => $schemaExists,
                'target_table' => $targetTable,
            ],
            'writer_checks' => [
                'authorization_repository_exists' => false,
                'external_signature_validation_report_exists' => false,
                'signed_payload_values_exist' => false,
                'append_only_event_writer_exists' => false,
                'idempotency_guard_exists' => false,
                'atomic_transaction_boundary_exists' => false,
                'receipt_use_writer_exists' => false,
            ],
            'required_atomic_guards' => data_get($template, 'required_atomic_guards', []),
            'preflight_policy' => [
                'preflight_is_read_only' => true,
                'authorization_persistence_allowed_here' => false,
                'ledger_write_allowed_here' => false,
                'receipt_use_mark_allowed_here' => false,
                'release_allowed_by_preflight' => false,
                'provider_start_allowed_by_preflight' => false,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-dispatch-executor-release-authorization-persistence-writer-contract-template --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_dispatch_executor_release_authorization_persistence_preflight.v1',
            'status' => 'blocked',
            'mode' => 'read_only_agent_dispatch_executor_release_authorization_persistence_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'dispatch_executor_release_authorization_persistence_preflight' => $preflight,
            'dispatch_executor_release_authorization_persistence_preflight_hash' => ($this->stableHash)($preflight),
            'non_execution_guarantees' => [
                'agent_dispatch_executor_release_authorization_persistence_preflight_does_not_start_providers',
                'agent_dispatch_executor_release_authorization_persistence_preflight_does_not_mark_receipt_used',
                'agent_dispatch_executor_release_authorization_persistence_preflight_does_not_dispatch_work',
                'agent_dispatch_executor_release_authorization_persistence_preflight_does_not_accept_signatures',
                'agent_dispatch_executor_release_authorization_persistence_preflight_does_not_validate_signatures',
                'agent_dispatch_executor_release_authorization_persistence_preflight_does_not_persist_authorization',
                'agent_dispatch_executor_release_authorization_persistence_preflight_does_not_write_ledger',
            ],
            'human_summary' => 'Agent dispatch executor release authorization persistence preflight remains blocked until storage, repository, external signature validation, idempotency, append-only event writer and atomic receipt-use writer exist.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentDispatchExecutorReleaseAuthorizationPersistenceWriterContractTemplate(array $options = []): array
    {
        $preflightPayload = $this->agentDispatchExecutorReleaseAuthorizationPersistencePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'dispatch_executor_release_authorization_persistence_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'dispatch_executor_release_authorization_persistence_preflight_hash');

        $contract = [
            'status' => 'writer_contract_template_ready_but_not_implemented',
            'persistence_preflight_hash' => $preflightHash,
            'receipt_key' => data_get($preflight, 'receipt_key'),
            'authorization_id' => data_get($preflight, 'authorization_id'),
            'provider' => data_get($preflight, 'provider'),
            'provider_role' => data_get($preflight, 'provider_role'),
            'packet_id' => data_get($preflight, 'packet_id'),
            'contract' => [
                'service' => 'AgentDispatchExecutorReleaseAuthorizationPersistenceWriter',
                'method' => 'persistSignedReleaseAuthorization',
                'input_dto' => 'SignedExecutorReleaseAuthorizationPersistenceInput',
                'output_dto' => 'SignedExecutorReleaseAuthorizationPersistenceResult',
                'transaction_boundary' => 'single_database_transaction',
                'idempotency_key' => 'signed_receipt_hash',
            ],
            'required_input_fields' => [
                'authorization_key',
                'receipt_key',
                'authorization_id',
                'decision',
                'signed_by',
                'signed_at',
                'expires_at',
                'signed_receipt_template_hash',
                'signed_receipt_preflight_hash',
                'persistence_template_hash',
                'persistence_preflight_hash',
                'external_signature_validation_report_hash',
                'signed_receipt_hash',
                'payload',
            ],
            'required_validation_steps' => [
                'verify_persistence_preflight_hash_matches_current_contract',
                'verify_signed_receipt_hash_is_unique',
                'verify_authorization_key_is_unique',
                'verify_decision_is_approve_release_once_or_reject_release_or_request_more_evidence',
                'reject_expired_signed_receipt',
                'reject_missing_external_signature_validation_report_hash',
                'reject_hard_denial_conditions',
                'write_authorization_record',
                'write_append_only_ledger_event',
                'return_persistence_receipt_hash',
            ],
            'forbidden_writer_behaviors' => [
                'starting_provider',
                'dispatching_work',
                'marking_dispatch_receipt_used',
                'validating_raw_signatures_without_external_report',
                'mutating_packet_state',
                'mutating_policy',
                'bypassing_idempotency',
                'writing_authorization_without_ledger_event',
            ],
            'required_tests' => [
                'persists_authorization_once_with_validated_signature_report',
                'is_idempotent_for_same_signed_receipt_hash',
                'rejects_duplicate_authorization_key',
                'rejects_expired_signed_receipt',
                'rejects_missing_external_signature_validation_report',
                'does_not_start_provider_or_dispatch_work',
                'writes_ledger_event_in_same_transaction',
            ],
            'implementation_files_allowed_future' => [
                'app/Services/Ai/SelfConstruction/AgentDispatchExecutorReleaseAuthorizationPersistenceWriter.php',
                'app/Models/AtlasSelfConstructionAgentDispatchExecutorReleaseAuthorization.php',
                'database/migrations/*_create_atlas_self_construction_agent_dispatch_authorizations_table.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentDispatchExecutorReleaseAuthorizationPersistenceWriterTest.php',
            ],
            'contract_policy' => [
                'template_is_read_only' => true,
                'writer_implementation_allowed_here' => false,
                'authorization_persistence_allowed_here' => false,
                'ledger_write_allowed_here' => false,
                'provider_start_allowed_here' => false,
                'requires_separate_implementation_preflight' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-dispatch-executor-release-authorization-persistence-writer-implementation-preflight --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_dispatch_executor_release_authorization_persistence_writer_contract_template.v1',
            'status' => 'agent_dispatch_executor_release_authorization_persistence_writer_contract_template_ready',
            'mode' => 'read_only_agent_dispatch_executor_release_authorization_persistence_writer_contract_template',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'dispatch_executor_release_authorization_persistence_writer_contract_template' => $contract,
            'dispatch_executor_release_authorization_persistence_writer_contract_template_hash' => ($this->stableHash)($contract),
            'non_execution_guarantees' => [
                'agent_dispatch_executor_release_authorization_persistence_writer_contract_template_does_not_start_providers',
                'agent_dispatch_executor_release_authorization_persistence_writer_contract_template_does_not_mark_receipt_used',
                'agent_dispatch_executor_release_authorization_persistence_writer_contract_template_does_not_dispatch_work',
                'agent_dispatch_executor_release_authorization_persistence_writer_contract_template_does_not_accept_signatures',
                'agent_dispatch_executor_release_authorization_persistence_writer_contract_template_does_not_validate_signatures',
                'agent_dispatch_executor_release_authorization_persistence_writer_contract_template_does_not_persist_authorization',
                'agent_dispatch_executor_release_authorization_persistence_writer_contract_template_does_not_write_ledger',
                'agent_dispatch_executor_release_authorization_persistence_writer_contract_template_does_not_create_writer_files',
            ],
            'human_summary' => 'Agent dispatch executor release authorization persistence writer contract template is ready, but it only defines the future writer interface, validations, forbidden behaviors and tests.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentDispatchExecutorReleaseAuthorizationPersistenceWriterImplementationPreflight(array $options = []): array
    {
        $contractPayload = $this->agentDispatchExecutorReleaseAuthorizationPersistenceWriterContractTemplate($options);
        $contract = (array) data_get($contractPayload, 'dispatch_executor_release_authorization_persistence_writer_contract_template', []);
        $contractHash = (string) data_get($contractPayload, 'dispatch_executor_release_authorization_persistence_writer_contract_template_hash');
        $allowedFiles = (array) data_get($contract, 'implementation_files_allowed_future', []);

        $preflight = [
            'status' => 'ready_for_scoped_writer_implementation_packet',
            'writer_contract_template_hash' => $contractHash,
            'receipt_key' => data_get($contract, 'receipt_key'),
            'authorization_id' => data_get($contract, 'authorization_id'),
            'provider' => data_get($contract, 'provider'),
            'provider_role' => data_get($contract, 'provider_role'),
            'packet_id' => data_get($contract, 'packet_id'),
            'allowed_files' => $allowedFiles,
            'allowed_file_count' => count($allowedFiles),
            'required_first_changes' => [
                'create_authorization_persistence_migration',
                'create_authorization_model',
                'create_persistence_writer_service',
                'create_writer_feature_test',
                'wire_no_provider_start_or_dispatch_side_effects',
            ],
            'implementation_constraints' => [
                'do_not_start_providers',
                'do_not_dispatch_work',
                'do_not_mark_dispatch_receipts_used',
                'do_not_accept_raw_signatures',
                'do_not_validate_raw_signatures_without_external_report',
                'do_not_mutate_packet_state',
                'do_not_mutate_policy',
                'do_not_touch_voice_runtime',
                'do_not_touch_hot_kernel_runtime',
            ],
            'required_gates' => [
                'php -l app/Services/Ai/SelfConstruction/AgentDispatchExecutorReleaseAuthorizationPersistenceWriter.php',
                'php -l app/Models/AtlasSelfConstructionAgentDispatchExecutorReleaseAuthorization.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentDispatchExecutorReleaseAuthorizationPersistenceWriterTest.php',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter=agent_dispatch_executor_release_authorization',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'preflight_policy' => [
                'preflight_is_read_only' => true,
                'writer_file_creation_allowed_by_preflight' => false,
                'authorization_persistence_allowed_here' => false,
                'ledger_write_allowed_here' => false,
                'provider_start_allowed_here' => false,
                'requires_explicit_implementation_packet' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-dispatch-executor-release-authorization-persistence-writer-implementation-packet --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_dispatch_executor_release_authorization_persistence_writer_implementation_preflight.v1',
            'status' => 'ready_for_scoped_writer_implementation_packet',
            'mode' => 'read_only_agent_dispatch_executor_release_authorization_persistence_writer_implementation_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'dispatch_executor_release_authorization_persistence_writer_implementation_preflight' => $preflight,
            'dispatch_executor_release_authorization_persistence_writer_implementation_preflight_hash' => ($this->stableHash)($preflight),
            'non_execution_guarantees' => [
                'agent_dispatch_executor_release_authorization_persistence_writer_implementation_preflight_does_not_start_providers',
                'agent_dispatch_executor_release_authorization_persistence_writer_implementation_preflight_does_not_mark_receipt_used',
                'agent_dispatch_executor_release_authorization_persistence_writer_implementation_preflight_does_not_dispatch_work',
                'agent_dispatch_executor_release_authorization_persistence_writer_implementation_preflight_does_not_accept_signatures',
                'agent_dispatch_executor_release_authorization_persistence_writer_implementation_preflight_does_not_validate_signatures',
                'agent_dispatch_executor_release_authorization_persistence_writer_implementation_preflight_does_not_persist_authorization',
                'agent_dispatch_executor_release_authorization_persistence_writer_implementation_preflight_does_not_write_ledger',
                'agent_dispatch_executor_release_authorization_persistence_writer_implementation_preflight_does_not_create_writer_files',
            ],
            'human_summary' => 'Agent dispatch executor release authorization persistence writer implementation preflight is ready to produce a scoped implementation packet, but it does not create writer files or persist authorization.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentDispatchExecutorReleaseAuthorizationPersistenceWriterImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->agentDispatchExecutorReleaseAuthorizationPersistenceWriterImplementationPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'dispatch_executor_release_authorization_persistence_writer_implementation_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'dispatch_executor_release_authorization_persistence_writer_implementation_preflight_hash');
        $allowedFiles = (array) data_get($preflight, 'allowed_files', []);

        $tasks = [
            [
                'id' => 'T1',
                'title' => 'Create executor release authorization persistence migration',
                'type' => 'migration',
                'allowed_files' => ['database/migrations/*_create_atlas_self_construction_agent_dispatch_authorizations_table.php'],
                'acceptance' => 'Table contains authorization key, receipt links, signer metadata, hashes, payload, status, timestamps and unique idempotency indexes.',
            ],
            [
                'id' => 'T2',
                'title' => 'Create executor release authorization model',
                'type' => 'model',
                'allowed_files' => ['app/Models/AtlasSelfConstructionAgentDispatchExecutorReleaseAuthorization.php'],
                'acceptance' => 'Model exposes guarded fillable/casts for payload and datetime fields without side effects.',
            ],
            [
                'id' => 'T3',
                'title' => 'Create persistence writer service',
                'type' => 'service',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentDispatchExecutorReleaseAuthorizationPersistenceWriter.php'],
                'acceptance' => 'Writer validates required fields, idempotency and external signature validation hash, writes authorization and append-only event in one transaction, and never starts providers.',
            ],
            [
                'id' => 'T4',
                'title' => 'Create writer feature test',
                'type' => 'test',
                'allowed_files' => ['tests/Feature/Ai/AtlasAiSelfConstructionAgentDispatchExecutorReleaseAuthorizationPersistenceWriterTest.php'],
                'acceptance' => 'Tests cover successful persistence, idempotency, duplicate rejection, expiry rejection, missing signature report rejection and no dispatch/provider side effects.',
            ],
        ];

        $packet = [
            'status' => 'ready_for_scoped_writer_implementation',
            'implementation_packet_id' => 'AGENT-DISPATCH-EXECUTOR-RELEASE-AUTHORIZATION-PERSISTENCE-WRITER-IMPLEMENTATION-SELF-CONSTRUCTION-0001',
            'source_preflight_hash' => $preflightHash,
            'objective' => 'Implement the guarded persistence writer for signed executor release authorizations without enabling provider execution.',
            'non_goals' => [
                'do_not_start_providers',
                'do_not_dispatch_work',
                'do_not_mark_dispatch_receipts_used',
                'do_not_build_signature_authority',
                'do_not_mutate_packet_state',
                'do_not_release_executor',
            ],
            'allowed_files' => $allowedFiles,
            'forbidden_scopes' => [
                'voice_runtime',
                'hot_kernel_runtime',
                'provider_start_drivers',
                'policy_mutation',
                'packet_claim_or_completion_state',
            ],
            'tasks' => $tasks,
            'task_count' => count($tasks),
            'acceptance_criteria' => [
                'signed_authorization_can_be_persisted_once_with_external_signature_validation_report_hash',
                'same_signed_receipt_hash_is_idempotent',
                'duplicate_authorization_key_is_rejected',
                'expired_signed_receipt_is_rejected',
                'missing_external_signature_validation_report_hash_is_rejected',
                'authorization_and_ledger_event_share_one_transaction',
                'writer_does_not_start_provider_dispatch_work_or_mark_receipt_used',
            ],
            'required_gates' => data_get($preflight, 'required_gates', []),
            'stop_conditions' => [
                'need_to_modify_file_outside_allowed_files',
                'need_to_start_provider_or_dispatch_work',
                'need_to_validate_raw_signature_inside_writer',
                'missing_append_only_event_api_contract',
                'test_requires_hot_runtime_or_voice_scope_change',
            ],
            'implementation_policy' => [
                'packet_is_read_only' => true,
                'implementation_allowed_by_packet' => true,
                'provider_start_allowed_by_packet' => false,
                'dispatch_allowed_by_packet' => false,
                'signature_validation_authority_allowed_by_packet' => false,
                'executor_release_allowed_by_packet' => false,
            ],
            'next_required_action' => 'Implement only the allowed files, then run the required gates and report evidence.',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_dispatch_executor_release_authorization_persistence_writer_implementation_packet.v1',
            'status' => 'ready_for_scoped_writer_implementation',
            'mode' => 'read_only_agent_dispatch_executor_release_authorization_persistence_writer_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'dispatch_executor_release_authorization_persistence_writer_implementation_packet' => $packet,
            'dispatch_executor_release_authorization_persistence_writer_implementation_packet_hash' => ($this->stableHash)($packet),
            'non_execution_guarantees' => [
                'agent_dispatch_executor_release_authorization_persistence_writer_implementation_packet_does_not_start_providers',
                'agent_dispatch_executor_release_authorization_persistence_writer_implementation_packet_does_not_mark_receipt_used',
                'agent_dispatch_executor_release_authorization_persistence_writer_implementation_packet_does_not_dispatch_work',
                'agent_dispatch_executor_release_authorization_persistence_writer_implementation_packet_does_not_accept_signatures',
                'agent_dispatch_executor_release_authorization_persistence_writer_implementation_packet_does_not_validate_signatures',
                'agent_dispatch_executor_release_authorization_persistence_writer_implementation_packet_does_not_persist_authorization',
                'agent_dispatch_executor_release_authorization_persistence_writer_implementation_packet_does_not_write_ledger',
                'agent_dispatch_executor_release_authorization_persistence_writer_implementation_packet_does_not_create_writer_files',
            ],
            'human_summary' => 'Agent dispatch executor release authorization persistence writer implementation packet is ready; it defines the scoped implementation work but does not create files or persist authorization.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentDispatchExecutorReleaseAuthorizationPersistenceStatus(array $options = []): array
    {
        $authorizationModel = AtlasSelfConstructionAgentDispatchExecutorReleaseAuthorization::class;
        $writerService = AgentDispatchExecutorReleaseAuthorizationPersistenceWriter::class;
        $authorizationTable = 'atlas_self_construction_agent_dispatch_authorizations';
        $receiptHash = strtolower(trim((string) ($options['receipt_hash'] ?? '')));
        $receiptHashIsValid = preg_match('/^[a-f0-9]{64}$/', $receiptHash) === 1;

        $storage = [
            'authorization_table' => $authorizationTable,
            'authorization_table_ready' => Schema::hasTable($authorizationTable),
            'authorization_model_ready' => class_exists($authorizationModel),
            'persistence_writer_ready' => class_exists($writerService),
            'ledger_table_ready' => Schema::hasTable('atlas_ledger_events'),
            'receipt_hash_filter' => $receiptHashIsValid ? $receiptHash : null,
            'receipt_hash_filter_valid' => $receiptHash === '' || $receiptHashIsValid,
        ];

        $selectedAuthorization = null;
        $persistedAuthorizationCount = 0;

        if ($storage['authorization_table_ready'] && $storage['authorization_model_ready']) {
            /** @var class-string<Model> $authorizationModel */
            $persistedAuthorizationCount = $authorizationModel::query()->count();
            $query = $authorizationModel::query()->latest('created_at');

            if ($receiptHashIsValid) {
                $query->where('signed_receipt_hash', $receiptHash);
            }

            $authorization = $query->first();

            if ($authorization !== null) {
                $expiresAt = $authorization->getAttribute('expires_at');
                $selectedAuthorization = [
                    'id' => (string) $authorization->getAttribute('id'),
                    'authorization_key' => (string) $authorization->getAttribute('authorization_key'),
                    'receipt_key' => (string) $authorization->getAttribute('receipt_key'),
                    'authorization_id' => (string) $authorization->getAttribute('authorization_id'),
                    'packet_id' => $authorization->getAttribute('packet_id'),
                    'provider' => $authorization->getAttribute('provider'),
                    'provider_role' => $authorization->getAttribute('provider_role'),
                    'decision' => (string) $authorization->getAttribute('decision'),
                    'status' => (string) $authorization->getAttribute('status'),
                    'signed_by' => (string) $authorization->getAttribute('signed_by'),
                    'signed_receipt_hash' => (string) $authorization->getAttribute('signed_receipt_hash'),
                    'expires_at' => method_exists($expiresAt, 'toIso8601String') ? $expiresAt->toIso8601String() : (string) $expiresAt,
                ];
            }
        }

        $selectedStatus = (string) data_get($selectedAuthorization, 'status', '');
        $selectedDecision = (string) data_get($selectedAuthorization, 'decision', '');
        $selectedExpiresAt = (string) data_get($selectedAuthorization, 'expires_at', '');
        $selectedNotExpired = $selectedExpiresAt !== '' && now()->lessThan(CarbonImmutable::parse($selectedExpiresAt));
        $selectedUsableForFutureReleasePreflight = $selectedAuthorization !== null
            && $selectedStatus === 'persisted_pending_executor_release'
            && $selectedDecision === 'approve_release_once'
            && $selectedNotExpired;

        $blockingReasons = array_values(array_filter([
            $storage['authorization_table_ready'] ? null : 'authorization_table_missing',
            $storage['authorization_model_ready'] ? null : 'authorization_model_missing',
            $storage['persistence_writer_ready'] ? null : 'persistence_writer_missing',
            $storage['ledger_table_ready'] ? null : 'ledger_table_missing',
            $storage['receipt_hash_filter_valid'] ? null : 'receipt_hash_filter_invalid',
        ]));

        $status = [
            'status' => $blockingReasons === [] ? 'agent_dispatch_executor_release_authorization_persistence_status_ready' : 'blocked',
            'storage' => $storage,
            'persisted_authorization_count' => $persistedAuthorizationCount,
            'selected_authorization' => $selectedAuthorization,
            'selected_authorization_present' => $selectedAuthorization !== null,
            'selected_authorization_not_expired' => $selectedNotExpired,
            'selected_usable_for_future_release_preflight' => $selectedUsableForFutureReleasePreflight,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'release_preconditions' => [
                'persisted_authorization_present' => $selectedAuthorization !== null,
                'decision_approves_one_release' => $selectedDecision === 'approve_release_once',
                'status_pending_executor_release' => $selectedStatus === 'persisted_pending_executor_release',
                'authorization_not_expired' => $selectedNotExpired,
                'ledger_table_ready' => (bool) $storage['ledger_table_ready'],
                'future_receipt_use_mark_writer_required' => true,
                'provider_sandbox_binding_required' => true,
                'manual_release_authorization_required' => true,
            ],
            'next_required_command' => 'php artisan atlas:ai:self-construction --agent-dispatch-executor-release-preflight --json',
            'status_policy' => [
                'read_only' => true,
                'authorization_persistence_allowed_here' => false,
                'receipt_use_mark_allowed_here' => false,
                'provider_start_allowed_here' => false,
                'dispatch_allowed_here' => false,
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_dispatch_executor_release_authorization_persistence_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_dispatch_executor_release_authorization_persistence_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'dispatch_executor_release_authorization_persistence_status' => $status,
            'dispatch_executor_release_authorization_persistence_status_hash' => ($this->stableHash)($status),
            'non_execution_guarantees' => [
                'agent_dispatch_executor_release_authorization_persistence_status_does_not_start_providers',
                'agent_dispatch_executor_release_authorization_persistence_status_does_not_mark_receipt_used',
                'agent_dispatch_executor_release_authorization_persistence_status_does_not_dispatch_work',
                'agent_dispatch_executor_release_authorization_persistence_status_does_not_accept_signatures',
                'agent_dispatch_executor_release_authorization_persistence_status_does_not_validate_signatures',
                'agent_dispatch_executor_release_authorization_persistence_status_does_not_persist_authorization',
                'agent_dispatch_executor_release_authorization_persistence_status_does_not_write_ledger',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Agent dispatch executor release authorization persistence status is ready; persisted authorization state can be inspected, but providers remain unreleased.'
                : 'Agent dispatch executor release authorization persistence status is blocked by missing storage or invalid filters; no provider release is allowed.',
        ];
    }
}
