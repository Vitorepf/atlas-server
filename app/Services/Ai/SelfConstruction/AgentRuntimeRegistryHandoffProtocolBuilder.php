<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use App\Services\Ai\SelfConstruction\Support\HashesPayloadCanonically;

/**
 * Build a handoff protocol plan between two Agent Control Plane agents.
 *
 * Pure projection: the handoff is planned, never executed. No claim is
 * transferred, no provider is called, no token is spent, no process is
 * started, and no ledger entry is written. The plan is advisory and the
 * orchestrator/operator decides whether to act on it.
 */
final class AgentRuntimeRegistryHandoffProtocolBuilder
{
    use HashesPayloadCanonically;
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_runtime_registry_handoff_protocol.v1';

    public const MODE = 'read_only_agent_runtime_registry_handoff_protocol';

    public function __construct(
        private readonly AgentRuntimeRegistryCapabilityCatalog $catalog = new AgentRuntimeRegistryCapabilityCatalog,
    ) {}

    /**
     * @param  array<string, mixed>  $fromAgent
     * @param  array<string, mixed>  $toAgent
     * @param  array<string, mixed>  $taskPacket
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function build(array $fromAgent, array $toAgent, array $taskPacket, array $options = []): array
    {
        $now = CarbonImmutable::now()->toIso8601String();
        $protocolId = (string) ($options['handoff_protocol_id'] ?? Str::uuid());

        $fromId = (string) ($fromAgent['agent_id'] ?? '');
        $toId = (string) ($toAgent['agent_id'] ?? '');
        $taskPacketId = (string) ($taskPacket['task_packet_id'] ?? '');
        $taskPacketHash = (string) ($taskPacket['task_packet_hash'] ?? '');
        $risk = strtolower((string) ($taskPacket['risk_level'] ?? 'low'));

        $blockers = [];
        $warnings = [];

        if ($fromId === '') {
            $blockers[] = 'from_agent_id_missing';
        }
        if ($toId === '') {
            $blockers[] = 'to_agent_id_missing';
        }
        if ($taskPacketId === '') {
            $blockers[] = 'task_packet_id_missing';
        }
        if ($fromId !== '' && $fromId === $toId) {
            $warnings[] = 'handoff_to_same_agent';
        }

        $continuationSummary = (array) ($taskPacket['continuation_summary'] ?? []);
        $continuationHash = (string) ($options['continuation_summary_hash'] ?? ($continuationSummary['hash'] ?? ''));
        if ($continuationHash === '') {
            $blockers[] = 'missing_continuation_summary';
        }

        $requiredCapabilities = $this->catalog->normalizeCapabilities((array) ($taskPacket['required_capabilities'] ?? []));
        $toCapabilities = $this->catalog->normalizeCapabilities((array) ($toAgent['capabilities'] ?? []));
        $capabilityMatch = $this->catalog->match($requiredCapabilities, $toCapabilities);
        if ($requiredCapabilities !== [] && $capabilityMatch['match_status'] !== 'matched') {
            $blockers[] = 'to_agent_missing_capabilities';
        }

        $evidenceRefs = $this->normalizeStringList((array) ($options['evidence_refs'] ?? ($taskPacket['evidence_refs'] ?? [])));
        if ($evidenceRefs === [] && (bool) ($taskPacket['evidence_required'] ?? true)) {
            $blockers[] = 'missing_evidence_refs';
        }

        $leasePolicy = (string) ($options['lease_transfer_policy'] ?? 'plan_only_no_real_transfer');
        $workspacePolicy = (string) ($options['workspace_transfer_policy'] ?? 'plan_only_no_real_transfer');
        $requiresLeaseSupport = (bool) ($taskPacket['requires_lease'] ?? false);
        if ($requiresLeaseSupport && ! (bool) ($toAgent['lease_supported'] ?? false)) {
            $blockers[] = 'to_agent_lease_support_missing';
        }

        $workspaceRequired = (string) ($taskPacket['workspace_policy'] ?? 'none') !== 'none';
        if ($workspaceRequired && ! (bool) ($toAgent['workspace_isolation_supported'] ?? false)) {
            $blockers[] = 'to_agent_workspace_isolation_missing';
        }

        $operatorApprovalRequired = in_array($risk, ['high', 'critical'], true)
            || ! in_array('human_approval', $toCapabilities, true) && in_array($risk, ['high', 'critical'], true);
        if (in_array($risk, ['high', 'critical'], true)) {
            $operatorApprovalRequired = true;
        }

        if ((string) ($toAgent['status'] ?? '') === 'quarantined') {
            $blockers[] = 'to_agent_quarantined';
        }
        if ((string) ($fromAgent['status'] ?? '') === 'quarantined') {
            $warnings[] = 'from_agent_quarantined';
        }

        $status = $blockers === [] ? 'planned' : 'blocked';

        $hashPayload = [
            'protocol_id' => $protocolId,
            'from_agent_id' => $fromId,
            'to_agent_id' => $toId,
            'task_packet_id' => $taskPacketId,
            'task_packet_hash' => $taskPacketHash,
            'continuation_summary_hash' => $continuationHash,
            'evidence_refs' => $evidenceRefs,
            'lease_policy' => $leasePolicy,
            'workspace_policy' => $workspacePolicy,
            'risk' => $risk,
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'handoff_protocol_id' => $protocolId,
            'planned_at' => $now,
            'status' => $status,
            'from_agent_id' => $fromId,
            'to_agent_id' => $toId,
            'task_packet_id' => $taskPacketId,
            'task_packet_hash' => $taskPacketHash,
            'required_continuation_summary' => $continuationHash !== '',
            'continuation_summary_hash' => $continuationHash,
            'required_evidence_refs' => $evidenceRefs,
            'lease_transfer_policy' => $leasePolicy,
            'workspace_transfer_policy' => $workspacePolicy,
            'operator_approval_required' => $operatorApprovalRequired,
            'risk_level' => $risk,
            'capability_match' => $capabilityMatch,
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => array_values(array_unique($warnings)),
            'handoff_hash' => $this->stableHash($hashPayload),
            'handoff_execution_allowed' => false,
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
        ];
    }

    /**
     * @return array<string, bool>
     */
    public function runtimeFlags(): array
    {
        return [
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
            'handoff_execution_allowed' => false,
        ];
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private function normalizeStringList(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            $clean = trim((string) $value);
            if ($clean === '') {
                continue;
            }
            $normalized[$clean] = true;
        }
        $keys = array_keys($normalized);
        sort($keys);

        return array_values($keys);
    }

}
