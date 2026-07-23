<?php

namespace App\Services\Ai\SelfConstruction\Readiness\HubDelegators;

use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionRuntimeGapMatrixService;
use App\Services\Ai\SelfConstruction\Readiness\CertificationWorkbenchEvaluator;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessCertificationChainQuartetProjector;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessPacketProjection;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessStatusProjection;

trait OsEvidenceSectionDelegators
{
    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionOsHandoffStatus(array $options = []) : array
    {
        return $this->osEvidenceSection()->atlasSelfConstructionOsHandoffStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionFinalOperatorEvidenceClosureCorridorStatus(array $options = []) : array
    {
        return $this->osEvidenceSection()->atlasSelfConstructionFinalOperatorEvidenceClosureCorridorStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionOperatorEvidenceSubmissionReadinessStatus(array $options = []) : array
    {
        return $this->osEvidenceSection()->atlasSelfConstructionOperatorEvidenceSubmissionReadinessStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfProgrammingOsTransitionReadinessStatus(array $options = []) : array
    {
        return $this->osEvidenceSection()->atlasSelfProgrammingOsTransitionReadinessStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function atlasSelfConstructionOsCompletionEvidenceStatus(array $options = []) : array
    {
        return $this->osEvidenceSection()->atlasSelfConstructionOsCompletionEvidenceStatus($options);
    }
}
