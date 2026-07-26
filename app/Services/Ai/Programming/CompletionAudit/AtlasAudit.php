<?php

namespace App\Services\Ai\Programming\CompletionAudit;

use App\Services\Ai\Programming\CompletionAudit\AtlasAudit\ForgeSection;
use App\Services\Ai\Programming\CompletionAudit\AtlasAudit\SelfImprovementSection;
use App\Services\Ai\Programming\CompletionAudit\AtlasAudit\UxSection;

/**
 * Façade over the Atlas certification audit blocks, sub-split by method
 * family (Forge / UX / Self-Improvement) into AtlasAudit/*Section classes.
 * Public surface is identical to the pre-split monolith.
 */
class AtlasAudit
{
    public function __construct(
        private readonly ForgeSection $forge,
        private readonly UxSection $ux,
        private readonly SelfImprovementSection $selfImprovement,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function atlasCodeEnterpriseCertification(): array
    {
        return $this->forge->atlasCodeEnterpriseCertification();
    }

    /**
     * @return array<string,mixed>
     */
    public function atlasForgeContinuumCertification(string $workspace): array
    {
        return $this->forge->atlasForgeContinuumCertification($workspace);
    }

    /**
     * @return array<string,mixed>
     */
    public function atlasForgeProviderCapacityCertification(string $workspace): array
    {
        return $this->forge->atlasForgeProviderCapacityCertification($workspace);
    }

    /**
     * @return array<string,mixed>
     */
    public function atlasForgeProviderInvocationCertification(string $workspace): array
    {
        return $this->forge->atlasForgeProviderInvocationCertification($workspace);
    }

    /**
     * @return array<string,mixed>
     */
    public function atlasForgeRealProviderDriversCertification(string $workspace): array
    {
        return $this->forge->atlasForgeRealProviderDriversCertification($workspace);
    }

    /**
     * @return array<string,mixed>
     */
    public function atlasCodeForgeHumanFirstUxCertification(string $workspace): array
    {
        return $this->ux->atlasCodeForgeHumanFirstUxCertification($workspace);
    }

    /**
     * @return array<string,mixed>
     */
    public function atlasCodeObraCommandCenterCertification(string $workspace): array
    {
        return $this->ux->atlasCodeObraCommandCenterCertification($workspace);
    }

    /**
     * @return array<string,mixed>
     */
    public function atlasCodeVisualErgonomicsCertification(string $workspace): array
    {
        return $this->ux->atlasCodeVisualErgonomicsCertification($workspace);
    }

    /**
     * @return array<string,mixed>
     */
    public function atlasCodePremiumWorkbenchVisualComfortCertification(string $workspace): array
    {
        return $this->ux->atlasCodePremiumWorkbenchVisualComfortCertification($workspace);
    }

    /**
     * @return array<string,mixed>
     */
    public function atlasSelfImprovementGovernanceCertification(string $workspace): array
    {
        return $this->selfImprovement->atlasSelfImprovementGovernanceCertification($workspace);
    }

    /**
     * @return array<string,mixed>
     */
    public function atlasSelfImprovementForgeActivationCertification(string $workspace): array
    {
        return $this->selfImprovement->atlasSelfImprovementForgeActivationCertification($workspace);
    }

    /**
     * @return array<string,mixed>
     */
    public function atlasSelfImprovementActivationCockpitCertification(string $workspace): array
    {
        return $this->selfImprovement->atlasSelfImprovementActivationCockpitCertification($workspace);
    }

    /**
     * @return array<string,mixed>
     */
    public function atlasSelfImprovementClosedLoopLevel7Certification(string $workspace): array
    {
        return $this->selfImprovement->atlasSelfImprovementClosedLoopLevel7Certification($workspace);
    }
}
