<?php

namespace App\Services\Ai\SelfConstruction;

use App\Models\AtlasSelfConstructionAgentDispatchExecutorReleaseAuthorization;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class AgentDispatchExecutorReleaseAuthorizationPersistenceWriter
{
    private const AUTHORIZATIONS_TABLE = 'atlas_self_construction_agent_dispatch_executor_release_authorizations';

    private const LEDGER_TABLE = 'atlas_ledger_events';

    public function __construct(
        private readonly AtlasEvidenceLedger $ledger,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function persistSignedReleaseAuthorization(array $input): array
    {
        $normalized = $this->normalize($input);

        if (! Schema::hasTable(self::AUTHORIZATIONS_TABLE)) {
            throw new InvalidArgumentException('authorization_persistence_table_missing');
        }

        if (! Schema::hasTable(self::LEDGER_TABLE)) {
            throw new InvalidArgumentException('append_only_ledger_table_missing');
        }

        return DB::transaction(function () use ($normalized): array {
            $existingByHash = AtlasSelfConstructionAgentDispatchExecutorReleaseAuthorization::query()
                ->where('signed_receipt_hash', $normalized['signed_receipt_hash'])
                ->first();

            if ($existingByHash instanceof AtlasSelfConstructionAgentDispatchExecutorReleaseAuthorization) {
                return $this->result($existingByHash, created: false, ledgerEventId: null);
            }

            $duplicateKey = AtlasSelfConstructionAgentDispatchExecutorReleaseAuthorization::query()
                ->where('authorization_key', $normalized['authorization_key'])
                ->exists();

            if ($duplicateKey) {
                throw new InvalidArgumentException('duplicate_authorization_key');
            }

            $authorization = AtlasSelfConstructionAgentDispatchExecutorReleaseAuthorization::query()->create($normalized);

            $ledgerEvent = $this->ledger->record(LedgerEventType::OperationCompleted, [
                'domain_event_type' => 'self_construction.agent_dispatch_executor_release_authorization.persisted',
                'authorization_key' => $authorization->authorization_key,
                'authorization_id' => $authorization->authorization_id,
                'receipt_key' => $authorization->receipt_key,
                'decision' => $authorization->decision,
                'provider' => $authorization->provider,
                'provider_role' => $authorization->provider_role,
                'packet_id' => $authorization->packet_id,
                'signed_receipt_hash' => $authorization->signed_receipt_hash,
                'persistence_preflight_hash' => $authorization->persistence_preflight_hash,
                'provider_start_allowed' => false,
                'dispatch_allowed' => false,
                'receipt_use_mark_allowed' => false,
            ], [
                'envelope_id' => 'self_construction:agent_dispatch_executor_release_authorization',
                'receipt_id' => $authorization->authorization_id,
                'correlation_id' => $authorization->signed_receipt_hash,
                'emitter_stage' => 'atlas.self_construction.agent_control_plane',
                'emitter_version' => 'agent-dispatch-executor-release-authorization-persistence-writer.v1',
            ]);

            if ($ledgerEvent === null) {
                throw new InvalidArgumentException('append_only_ledger_event_write_failed');
            }

            return $this->result($authorization, created: true, ledgerEventId: (string) $ledgerEvent->event_id);
        });
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function normalize(array $input): array
    {
        $required = [
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
        ];

        foreach ($required as $field) {
            if (! Arr::has($input, $field) || $input[$field] === null || $input[$field] === '') {
                throw new InvalidArgumentException('missing_'.$field);
            }
        }

        $decision = (string) $input['decision'];
        $allowedDecisions = ['approve_release_once', 'reject_release', 'request_more_evidence'];

        if (! in_array($decision, $allowedDecisions, true)) {
            throw new InvalidArgumentException('invalid_decision');
        }

        foreach ([
            'signed_receipt_template_hash',
            'signed_receipt_preflight_hash',
            'persistence_template_hash',
            'persistence_preflight_hash',
            'external_signature_validation_report_hash',
            'signed_receipt_hash',
        ] as $hashField) {
            $hash = strtolower((string) $input[$hashField]);

            if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
                throw new InvalidArgumentException('invalid_'.$hashField);
            }

            $input[$hashField] = $hash;
        }

        $signedAt = CarbonImmutable::parse((string) $input['signed_at']);
        $expiresAt = CarbonImmutable::parse((string) $input['expires_at']);

        if ($expiresAt->lessThanOrEqualTo(CarbonImmutable::now())) {
            throw new InvalidArgumentException('expired_signed_receipt');
        }

        return [
            'authorization_key' => (string) $input['authorization_key'],
            'receipt_key' => (string) $input['receipt_key'],
            'authorization_id' => (string) $input['authorization_id'],
            'packet_id' => $this->nullableString($input['packet_id'] ?? null),
            'provider' => $this->nullableString($input['provider'] ?? null),
            'provider_role' => $this->nullableString($input['provider_role'] ?? null),
            'decision' => $decision,
            'status' => $decision === 'approve_release_once' ? 'persisted_pending_executor_release' : 'persisted_no_release',
            'signed_by' => (string) $input['signed_by'],
            'signed_at' => $signedAt,
            'expires_at' => $expiresAt,
            'signed_receipt_template_hash' => $input['signed_receipt_template_hash'],
            'signed_receipt_preflight_hash' => $input['signed_receipt_preflight_hash'],
            'persistence_template_hash' => $input['persistence_template_hash'],
            'persistence_preflight_hash' => $input['persistence_preflight_hash'],
            'external_signature_validation_report_hash' => $input['external_signature_validation_report_hash'],
            'signed_receipt_hash' => $input['signed_receipt_hash'],
            'payload' => (array) $input['payload'],
            'persisted_at' => CarbonImmutable::now(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function result(
        AtlasSelfConstructionAgentDispatchExecutorReleaseAuthorization $authorization,
        bool $created,
        ?string $ledgerEventId,
    ): array {
        return [
            'status' => 'persisted',
            'created' => $created,
            'authorization_id' => $authorization->id,
            'authorization_key' => $authorization->authorization_key,
            'receipt_key' => $authorization->receipt_key,
            'decision' => $authorization->decision,
            'authorization_status' => $authorization->status,
            'signed_receipt_hash' => $authorization->signed_receipt_hash,
            'ledger_event_id' => $ledgerEventId,
            'provider_start_allowed' => false,
            'dispatch_allowed' => false,
            'receipt_use_mark_allowed' => false,
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
