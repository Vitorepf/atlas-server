<?php

namespace App\Services\Ai\SelfConstruction\Readiness\HubDelegators;

trait CodexProjectionSectionDelegators
{
    public function codexLaunchPlan(array $options = []): array
    {
        return $this->codexProjectionSection()->codexLaunchPlan($options);
    }

    public function codexExecutionStatus(array $options = []): array
    {
        return $this->codexProjectionSection()->codexExecutionStatus($options);
    }

    public function codexMergeReadiness(array $options = []): array
    {
        return $this->codexProjectionSection()->codexMergeReadiness($options);
    }

    public function codexFinalReviewPacket(array $options = []): array
    {
        return $this->codexProjectionSection()->codexFinalReviewPacket($options);
    }

    public function codexReviewDecisionTemplate(array $options = []): array
    {
        return $this->codexProjectionSection()->codexReviewDecisionTemplate($options);
    }

    public function codexReviewReceiptDraft(array $options = []): array
    {
        return $this->codexProjectionSection()->codexReviewReceiptDraft($options);
    }

    public function codexReviewSignatureRequest(array $options = []): array
    {
        return $this->codexProjectionSection()->codexReviewSignatureRequest($options);
    }

    public function codexReviewPostSignatureRunbook(array $options = []): array
    {
        return $this->codexProjectionSection()->codexReviewPostSignatureRunbook($options);
    }
}
