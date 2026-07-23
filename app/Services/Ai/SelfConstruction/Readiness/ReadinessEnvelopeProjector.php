<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness;

use App\Services\Ai\SelfConstruction\Support\ReadinessHash;

/**
 * The single constructor of Readiness status envelopes.
 *
 * ARCH BLUEPRINT SelfConstructionReadiness §2.1 (resolves A1-SC-0003/0013):
 * - projectCertificationWorkbenchStatus is the byte-compatible engine behind the
 *   legacy wrapCertificationWorkbenchStatus family, plus the truth switch: a route
 *   that actually wrote durable state MUST say so (`runtime_write_performed`,
 *   `runtime_write_allowed`, mutating mode string) instead of hard-coding
 *   `read_only_*`.
 * - projectEnvelope is the blueprint-native envelope for new owners: outer status
 *   is DERIVED via ReadinessFailClosedPolicy (never a literal), guarantees are
 *   derived once from the policy, hashes go through ReadinessHash.
 */
final class ReadinessEnvelopeProjector
{
    public const ENVELOPE_SCHEMA_VERSION = 'atlas.self_construction_readiness_envelope.v1';

    public function __construct(
        private readonly ReadinessFailClosedPolicy $policy = new ReadinessFailClosedPolicy,
    ) {}

    /**
     * Byte-compatible with the legacy wrap when $runtimeWritePerformed is false.
     * When true, the envelope stops lying: mode drops the `read_only_` claim,
     * `runtime_write_allowed` reports true and `runtime_write_performed` appears.
     * The non-execution guarantees remain (a queue/lease write still never starts
     * codex, dispatches work, executes an adapter or enables self-programming).
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $extraStatusFields
     * @return array<string, mixed>
     */
    public function projectCertificationWorkbenchStatus(
        string $keyPrefix,
        string $label,
        array $payload,
        string $statusKey,
        array $extraStatusFields,
        bool $runtimeWritePerformed = false,
    ): array {
        $statusValue = (string) data_get($payload, $statusKey, 'unknown');
        $status = array_merge([
            'status' => $statusValue,
        ], $extraStatusFields);

        $envelope = [
            'schema_version' => "atlas.self_construction_agent_control_plane_{$keyPrefix}_status.v1",
            'status' => $statusValue,
            'mode' => $runtimeWritePerformed
                ? "mutating_agent_control_plane_{$keyPrefix}_status"
                : "read_only_agent_control_plane_{$keyPrefix}_status",
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => $runtimeWritePerformed,
        ];

        if ($runtimeWritePerformed) {
            $envelope['runtime_write_performed'] = true;
        }

        return $envelope + [
            "agent_control_plane_{$keyPrefix}_status" => $status,
            "agent_control_plane_{$keyPrefix}" => $payload,
            "agent_control_plane_{$keyPrefix}_status_hash" => ReadinessHash::stable($status),
            'non_execution_guarantees' => $this->policy->nonExecutionGuarantees("agent_control_plane_{$keyPrefix}_status"),
            'human_summary' => "Agent Control Plane {$label} status is {$statusValue}.",
        ];
    }

    /**
     * Blueprint-native envelope: outer status derived fail-closed from the payload.
     *
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $requiredFields
     * @param  list<string>  $requiredAuthorities
     * @param  list<string>  $requiredEvidenceKeys
     * @return array<string, mixed>
     */
    public function projectEnvelope(
        string $keyPrefix,
        string $label,
        array $payload,
        array $requiredFields = [],
        array $requiredAuthorities = [],
        array $requiredEvidenceKeys = [],
        bool $runtimeWritePerformed = false,
        string $statusKey = 'status',
    ): array {
        $decision = $this->policy->decideOuterStatus(
            $payload,
            $requiredFields,
            $requiredAuthorities,
            $requiredEvidenceKeys,
            $statusKey,
        );

        return [
            'schema_version' => self::ENVELOPE_SCHEMA_VERSION,
            'status' => $decision->status,
            'violations' => $decision->violations,
            'runtime_write_performed' => $runtimeWritePerformed,
            $keyPrefix => $payload,
            "{$keyPrefix}_hash" => ReadinessHash::stable($payload),
            'non_execution_guarantees' => $this->policy->nonExecutionGuarantees($keyPrefix),
            'human_summary' => "{$label} status is {$decision->status}.",
        ];
    }
}
