<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use App\Services\Ai\SelfConstruction\Support\RecursivelyKsortsArrays;

/**
 * Simulates claim/lease acquisition for a task packet without writing any
 * lock, reservation or runtime row. Detects multi-agent conflict by
 * overlapping allowed_files / write_set scope.
 *
 * Read-only: never starts processes, never calls Codex CLI/app, never
 * spawns subprocesses, never invokes adapters, never dispatches work,
 * never spends tokens, never advances the next required slice, never
 * enables self-programming, never writes the ledger.
 */
final class AgentControlPlaneClaimLeaseSimulator
{
    use RecursivelyKsortsArrays;
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_claim_lease_simulation.v1';

    public const MODE = 'read_only_agent_control_plane_claim_lease_simulation';

    /**
     * @param  array<string, mixed>  $taskPacket
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function simulate(array $taskPacket, array $options = []): array
    {
        $packetId = (string) ($taskPacket['task_packet_id'] ?? 'task-packet-unknown');
        $packetStatus = (string) ($taskPacket['status'] ?? 'unknown');
        $allowed = (array) data_get($taskPacket, 'normalized_scope.allowed_files', []);

        $existingLeases = (array) ($options['existing_leases'] ?? []);
        $owner = (string) ($options['lease_owner'] ?? data_get($taskPacket, 'operator_id', 'operator-unknown'));
        $ttl = (int) ($options['lease_ttl_seconds'] ?? data_get($taskPacket, 'lease_requirements.lease_ttl_seconds', 1800));
        $forceConflict = (bool) ($options['force_conflict'] ?? false);

        $conflicts = [];
        foreach ($existingLeases as $existing) {
            $existingAllowed = (array) ($existing['allowed_files'] ?? []);
            $overlap = WriteSetOverlap::collidingPaths($allowed, $existingAllowed); // A5/MF-12: prefix-aware dir-vs-file
            if ($overlap !== []) {
                $conflicts[] = [
                    'lease_id' => (string) ($existing['lease_id'] ?? 'unknown'),
                    'owner' => (string) ($existing['owner'] ?? 'unknown'),
                    'overlap_files' => $overlap,
                ];
            }
        }

        $leaseStatus = match (true) {
            $packetStatus !== 'planned' => 'simulated_blocked',
            $forceConflict, $conflicts !== [] => 'simulated_conflict',
            default => 'simulated_granted',
        };

        $blockingReasons = [];
        if ($packetStatus !== 'planned') {
            $blockingReasons[] = 'task_packet_not_planned';
        }
        if ($conflicts !== []) {
            $blockingReasons[] = 'conflict_overlap_detected';
        }
        if ($forceConflict) {
            $blockingReasons[] = 'forced_conflict_option';
        }

        $claimId = (string) ($options['claim_id'] ?? Str::uuid()->toString());
        $leaseId = (string) ($options['lease_id'] ?? Str::uuid()->toString());

        $renewalPlan = [
            'auto_renew' => true,
            'max_renewals' => (int) ($options['max_renewals'] ?? 3),
            'next_renewal_in_seconds' => (int) max(60, (int) round($ttl / 3)),
            'runtime_enabled' => false,
        ];

        $expirationPlan = [
            'expires_at_seconds_offset' => $ttl,
            'expiration_action' => 'release_simulated_lease',
            'runtime_enabled' => false,
        ];

        $releasePlan = [
            'release_strategy' => 'simulated_release_on_completion_or_cancel',
            'runtime_enabled' => false,
        ];

        $claimHash = $this->stableHash([
            'task_packet_id' => $packetId,
            'owner' => $owner,
            'allowed_files' => $allowed,
            'kind' => 'claim',
        ]);
        $leaseHash = $this->stableHash([
            'task_packet_id' => $packetId,
            'owner' => $owner,
            'allowed_files' => $allowed,
            'ttl_seconds' => $ttl,
            'kind' => 'lease',
        ]);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'claim_id' => $claimId,
            'lease_id' => $leaseId,
            'task_packet_id' => $packetId,
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'lease_status' => $leaseStatus,
            'lease_owner' => $owner,
            'lease_ttl_seconds' => $ttl,
            'conflict_set' => $conflicts,
            'conflict_count' => count($conflicts),
            'blocking_reasons' => $blockingReasons,
            'claim_hash' => $claimHash,
            'lease_hash' => $leaseHash,
            'renewal_plan' => $renewalPlan,
            'expiration_plan' => $expirationPlan,
            'release_plan' => $releasePlan,
            'read_only' => true,
            'runtime_disabled' => true,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
            'reservation_persistence_allowed' => false,
            'non_execution_guarantees' => [
                'claim_lease_simulator_does_not_start_codex',
                'claim_lease_simulator_does_not_call_codex_cli_or_app',
                'claim_lease_simulator_does_not_spawn_subprocess',
                'claim_lease_simulator_does_not_invoke_adapter',
                'claim_lease_simulator_does_not_call_provider',
                'claim_lease_simulator_does_not_dispatch_work',
                'claim_lease_simulator_does_not_spend_tokens',
                'claim_lease_simulator_does_not_enable_self_programming',
                'claim_lease_simulator_does_not_write_ledger',
                'claim_lease_simulator_does_not_persist_lease',
                'claim_lease_simulator_does_not_mutate_pointer',
            ],
            'human_summary' => sprintf(
                'Claim/lease simulation %s for packet %s (%d conflict%s).',
                $leaseStatus,
                $packetId,
                count($conflicts),
                count($conflicts) === 1 ? '' : 's',
            ),
        ];

        $payload['simulation_hash'] = $this->stableHash($this->normalizeForHash($payload));

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeForHash(array $payload): array
    {
        $clone = $payload;
        unset($clone['claim_id'], $clone['lease_id'], $clone['generated_at'], $clone['simulation_hash'], $clone['human_summary'], $clone['task_packet_id'], $clone['claim_hash'], $clone['lease_hash']);

        return $this->recursivelyKsort($clone);
    }


    /**
     * @param  array<mixed, mixed>  $payload
     */
    private function stableHash(array $payload): string
    {
        $payload = $this->recursivelyKsort($payload);

        return hash('sha256', (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
