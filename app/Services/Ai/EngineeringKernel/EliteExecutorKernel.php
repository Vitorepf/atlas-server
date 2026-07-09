<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel;

use App\Services\Ai\EngineeringKernel\Adapters\AtlasAutonomosGateAdapter;
use App\Services\Ai\EngineeringKernel\Adapters\AtlasDevGateAdapter;
use App\Services\Ai\EngineeringKernel\Adapters\AtlasForgeGateAdapter;
use App\Services\Ai\EngineeringKernel\Repair\RepairDiagnosisStage;
use App\Services\Ai\EngineeringKernel\Spec\IntentEnvelope;

/**
 * Shared elite kernel surface for Dev · Forge · Autônomos.
 *
 * Same quality bar; difference is operator presence + scale/duration (adapters).
 */
final class EliteExecutorKernel
{
    public const SCHEMA_VERSION = 'atlas.elite_executor_kernel.v1';

    public function __construct(
        private readonly OutcomeProofGate $outcomeProof,
        private readonly FalseClaimInvariant $falseClaim,
        private readonly SovereignHonestyFloor $honestyFloor,
        private readonly RepairDiagnosisStage $repairDiagnosis,
        private readonly AtlasDevGateAdapter $devAdapter,
        private readonly AtlasForgeGateAdapter $forgeAdapter,
        private readonly AtlasAutonomosGateAdapter $autonomosAdapter,
    ) {}

    public function devGate(): AtlasDevGateAdapter
    {
        return $this->devAdapter;
    }

    public function forgeGate(): AtlasForgeGateAdapter
    {
        return $this->forgeAdapter;
    }

    public function autonomosGate(): AtlasAutonomosGateAdapter
    {
        return $this->autonomosAdapter;
    }

    public function outcomeProof(): OutcomeProofGate
    {
        return $this->outcomeProof;
    }

    public function honestyFloor(): SovereignHonestyFloor
    {
        return $this->honestyFloor;
    }

    /**
     * Obra 2 / DEV-02: single honesty contract — OutcomeProofGate and SovereignHonestyFloor
     * share FalseClaimInvariant so fake-green cannot drift between thin assert and full certify.
     *
     * @return array<string,mixed>
     */
    public function unifiedHonestyContract(): array
    {
        return [
            'schema_version' => 'atlas.elite_kernel.unified_honesty.v1',
            'outcome_proof' => OutcomeProofGate::class,
            'sovereign_floor' => SovereignHonestyFloor::class,
            'shared_invariant' => FalseClaimInvariant::class,
            'rule' => 'fake_green_blocked_identically',
            'assert_path' => 'assertHonestOutcome',
            'certify_path' => 'devGate|forgeGate|autonomosGate -> SovereignHonestyFloor::certify',
        ];
    }

    public function repairDiagnosis(): RepairDiagnosisStage
    {
        return $this->repairDiagnosis;
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    public function assertHonestOutcome(array $evidence, string $executor = 'dev'): void
    {
        $status = (string) ($evidence['status'] ?? 'failed');
        $proof = $this->outcomeProof->assess($status, (array) ($evidence['execution'] ?? []));
        if (($proof['fake_green'] ?? false) === true) {
            throw new \RuntimeException('elite_kernel_fake_green: '.(string) ($proof['reason'] ?? 'unknown').' executor='.$executor);
        }
    }

    public function intentEnvelope(string $objective, string $executor = 'dev'): IntentEnvelope
    {
        return new IntentEnvelope(rawGoal: $objective.' ['.$executor.']');
    }

    /**
     * @return array<string, mixed>
     */
    public function contract(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'executors' => ['dev', 'forge', 'autonomos'],
            'shared' => ['context_intake', 'evidence_grammar', 'repair', 'completion_governor', 'quality_bar'],
            'difference' => 'operator_presence_and_scale_only',
        ];
    }
}
