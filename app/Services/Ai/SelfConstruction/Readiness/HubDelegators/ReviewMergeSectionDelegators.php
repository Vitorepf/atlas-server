<?php

namespace App\Services\Ai\SelfConstruction\Readiness\HubDelegators;

use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionRuntimeGapMatrixService;
use App\Services\Ai\SelfConstruction\Readiness\CertificationWorkbenchEvaluator;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessCertificationChainQuartetProjector;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessPacketProjection;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessStatusProjection;

trait ReviewMergeSectionDelegators
{
    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergeActionTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergeActionTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePreflight(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePreflight($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergeActionDraft(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergeActionDraft($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergeReceiptDraft(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergeReceiptDraft($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergeSignatureRequest(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergeSignatureRequest($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostSignatureRunbook(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostSignatureRunbook($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergeExecutionChecklist(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergeExecutionChecklist($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergeAuthorizationTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergeAuthorizationTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergeAuthorizationReceiptDraft(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergeAuthorizationReceiptDraft($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergeAuthorizationSignatureRequest(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergeAuthorizationSignatureRequest($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergeAuthorizationPostSignatureRunbook(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergeAuthorizationPostSignatureRunbook($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergeFinalAuthorizationPreflight(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergeFinalAuthorizationPreflight($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergeAuthorizingActionTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergeAuthorizingActionTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergeFinalReceiptDraft(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergeFinalReceiptDraft($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergeFinalSignatureRequest(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergeFinalSignatureRequest($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergeFinalPostSignatureRunbook(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergeFinalPostSignatureRunbook($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergeSignedFinalReceiptTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergeSignedFinalReceiptTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergeSignedFinalReceiptPreflight(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergeSignedFinalReceiptPreflight($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergeSignedFinalReceiptPersistenceTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergeSignedFinalReceiptPersistenceTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergeExecutorReleasePreflight(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergeExecutorReleasePreflight($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergeExecutorContractTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergeExecutorContractTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergeExecutionReceiptTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergeExecutionReceiptTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionPreflight(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionPreflight($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionReceiptDraft(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionReceiptDraft($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignatureRequest(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignatureRequest($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionPostSignatureRunbook(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionPostSignatureRunbook($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPreflight(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPreflight($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceReceiptDraft(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceReceiptDraft($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistencePreflight(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistencePreflight($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistencePostPreflightRunbook(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistencePostPreflightRunbook($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceAppendOnlyEventPayloadTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceAppendOnlyEventPayloadTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterPreflight(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterPreflight($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterContractTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterContractTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterImplementationPreflight(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterImplementationPreflight($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationPreflight(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationPreflight($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationReceiptDraft(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationReceiptDraft($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationSignatureRequest(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationSignatureRequest($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationPostSignatureRunbook(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationPostSignatureRunbook($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationSignedReceiptTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationSignedReceiptTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleasePreflight(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleasePreflight($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseReceiptDraft(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseReceiptDraft($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseSignatureRequest(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseSignatureRequest($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleasePostSignatureRunbook(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleasePostSignatureRunbook($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseSignedReceiptTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseSignedReceiptTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseExecutionContractPreflight(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseExecutionContractPreflight($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseExecutionContractTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseExecutionContractTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseDisableContractTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseDisableContractTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseObservabilityContractTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseObservabilityContractTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleasePostMonitoringReviewTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleasePostMonitoringReviewTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseReenableReviewPacketTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseReenableReviewPacketTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationRequestTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationRequestTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationReceiptDraftTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationReceiptDraftTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationSignatureRequestTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationSignatureRequestTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationPostSignatureRunbookTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationPostSignatureRunbookTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationSignedReceiptTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationSignedReceiptTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationExecutionContractPreflightTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationExecutionContractPreflightTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationExecutionContractTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationExecutionContractTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationDisableContractTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationDisableContractTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationObservabilityContractTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationObservabilityContractTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationPostMonitoringReviewTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationPostMonitoringReviewTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationHealthDecisionTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationHealthDecisionTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationDisableRequestTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationDisableRequestTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleRequestTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleRequestTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleAuthorizationRequestTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleAuthorizationRequestTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleReceiptDraftTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleReceiptDraftTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleSignatureRequestTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleSignatureRequestTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCyclePostSignatureRunbookTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCyclePostSignatureRunbookTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleSignedReceiptTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleSignedReceiptTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleExecutionContractPreflightTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleExecutionContractPreflightTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleExecutionContractTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleExecutionContractTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableContractTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableContractTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleObservabilityContractTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleObservabilityContractTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCyclePostMonitoringReviewTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCyclePostMonitoringReviewTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleHealthDecisionTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleHealthDecisionTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableRequestTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableRequestTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionPreflightTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionPreflightTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionReceiptDraftTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionReceiptDraftTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionSignedReceiptTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionSignedReceiptTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionPersistencePreflightTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionPersistencePreflightTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionPersistenceReceiptTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionPersistenceReceiptTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionPostPersistenceReviewTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionPostPersistenceReviewTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionFollowUpObservabilityTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionFollowUpObservabilityTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionEvidenceRepairRequestTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionEvidenceRepairRequestTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionRepairedEvidencePacketTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionRepairedEvidencePacketTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionRepairReviewTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionRepairReviewTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionRepairOutcomePacketTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionRepairOutcomePacketTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleRequestTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleRequestTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCyclePreflightTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCyclePreflightTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationRequestTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationRequestTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationReceiptDraftTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationReceiptDraftTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationSignatureRequestTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationSignatureRequestTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationPostSignatureRunbookTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationPostSignatureRunbookTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationSignatureValidationReportTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationSignatureValidationReportTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationSignedReceiptTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationSignedReceiptTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationSignedReceiptPreflightTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationSignedReceiptPreflightTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationPersistencePreflightTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationPersistencePreflightTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationPersistenceReceiptTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationPersistenceReceiptTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationPostPersistenceReviewTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationPostPersistenceReviewTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationFollowUpObservabilityTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationFollowUpObservabilityTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationEvidenceRepairRequestTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationEvidenceRepairRequestTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationRepairedEvidencePacketTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationRepairedEvidencePacketTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationRepairReviewTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationRepairReviewTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationPersistenceRejectionTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationPersistenceRejectionTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationHumanEscalationTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationHumanEscalationTemplate($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationManualDecisionRequestTemplate(array $options = []): array
    {
        return $this->reviewMergeSection()->agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationManualDecisionRequestTemplate($options);
    }
}
