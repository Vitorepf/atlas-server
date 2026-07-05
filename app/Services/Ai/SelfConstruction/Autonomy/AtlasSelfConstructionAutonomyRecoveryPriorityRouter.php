<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Autonomy;

/**
 * Prioritizes recovery tasks when autonomy health regresses so the system
 * repairs sensing, queue, lease and proof loops before expanding scope.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasSelfConstructionAutonomyRecoveryPriorityRouter
{
    public const SCHEMA = 'atlas.self_construction.autonomy.recovery_priority_router.v1';

    public const RECOVERY_LEASE_LEAK = 'lease_leak_repair';
    public const RECOVERY_MALFORMED_BLOCKERS = 'malformed_blockers_repair';
    public const RECOVERY_STALE_PROOF = 'stale_proof_repair';
    public const RECOVERY_QUEUE_DRY = 'queue_dry_repair';
    public const RECOVERY_SENSING_DEGRADED = 'sensing_degraded_repair';
    public const RECOVERY_NONE = 'no_recovery_needed';

    private const PRIORITY_ORDER = [
        self::RECOVERY_LEASE_LEAK => 1,
        self::RECOVERY_MALFORMED_BLOCKERS => 2,
        self::RECOVERY_STALE_PROOF => 3,
        self::RECOVERY_QUEUE_DRY => 4,
        self::RECOVERY_SENSING_DEGRADED => 5,
        self::RECOVERY_NONE => 99,
    ];

    private const ALLOWED_FILES_BY_RECOVERY = [
        self::RECOVERY_LEASE_LEAK => [
            'app/Services/Ai/SelfConstruction/AgentControlPlaneClaimLeaseRepository.php',
            'tests/Feature/Ai/AtlasTaskCoordinationHealthTest.php',
        ],
        self::RECOVERY_MALFORMED_BLOCKERS => [
            'app/Services/Ai/SelfConstruction/AgentControlPlaneTaskQueueOrchestrator.php',
            'tests/Feature/Ai/AtlasTaskCoordinationHealthTest.php',
        ],
        self::RECOVERY_STALE_PROOF => [
            'app/Services/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainImplementationProofDemand.php',
            'tests/Unit/Services/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainImplementationProofDemandTest.php',
        ],
        self::RECOVERY_QUEUE_DRY => [
            'app/Services/Ai/SelfConstruction/Replenisher/AtlasSelfConstructionQueueTopUpPolicy.php',
            'tests/Unit/Services/Ai/SelfConstruction/Replenisher/AtlasSelfConstructionQueueTopUpPolicyTest.php',
        ],
        self::RECOVERY_SENSING_DEGRADED => [
            'app/Services/Ai/SelfConstruction/AutonomousRuntime/AtlasAutonomousRuntimeSafetyStopGate.php',
            'tests/Unit/Services/Ai/SelfConstruction/AutonomousRuntime/AtlasAutonomousRuntimeSafetyStopGateTest.php',
        ],
    ];

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function route(array $input): array
    {
        $health = is_array($input['autonomy_health'] ?? null) ? $input['autonomy_health'] : [];
        $originatorId = (string) ($input['originator_id'] ?? '');
        $roundId = (string) ($input['round_id'] ?? '');

        $leaseLeak = (bool) ($health['lease_leak_detected'] ?? false);
        $malformedCount = (int) ($health['malformed_count'] ?? 0);
        $staleProof = (bool) ($health['stale_proof_detected'] ?? false);
        $queueDry = (bool) ($health['queue_dry'] ?? false);
        $sensingDegraded = (bool) ($health['sensing_degraded'] ?? false);

        $recoveries = [];

        if ($leaseLeak) {
            $recoveries[] = $this->buildRecovery(self::RECOVERY_LEASE_LEAK, 'lease leak detected');
        }

        if ($malformedCount > 0) {
            $recoveries[] = $this->buildRecovery(self::RECOVERY_MALFORMED_BLOCKERS, "malformed_count={$malformedCount}");
        }

        if ($staleProof) {
            $recoveries[] = $this->buildRecovery(self::RECOVERY_STALE_PROOF, 'stale proof detected');
        }

        if ($queueDry) {
            $recoveries[] = $this->buildRecovery(self::RECOVERY_QUEUE_DRY, 'queue dry detected');
        }

        if ($sensingDegraded) {
            $recoveries[] = $this->buildRecovery(self::RECOVERY_SENSING_DEGRADED, 'sensing degraded detected');
        }

        if ($recoveries === []) {
            $recoveries[] = $this->buildRecovery(self::RECOVERY_NONE, 'no autonomy regression signals');
        }

        usort($recoveries, static function (array $a, array $b): int {
            return ($a['priority'] ?? 99) <=> ($b['priority'] ?? 99)
                ?: strcmp($a['recovery_kind'], $b['recovery_kind']);
        });

        $top = $recoveries[0];

        return [
            'schema_version' => self::SCHEMA,
            'originator_id' => $originatorId,
            'round_id' => $roundId,
            'recovery_needed' => $top['recovery_kind'] !== self::RECOVERY_NONE,
            'top_recovery' => $top,
            'recoveries' => $recoveries,
            'blocks_expansion' => $top['recovery_kind'] !== self::RECOVERY_NONE,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRecovery(string $kind, string $reason): array
    {
        return [
            'recovery_kind' => $kind,
            'priority' => self::PRIORITY_ORDER[$kind] ?? 99,
            'reason' => $reason,
            'allowed_files' => self::ALLOWED_FILES_BY_RECOVERY[$kind] ?? [],
        ];
    }
}
