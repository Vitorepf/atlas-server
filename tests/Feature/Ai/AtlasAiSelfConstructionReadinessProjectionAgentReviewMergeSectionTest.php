<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\Readiness\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentReviewMergeSection;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Executes every public review/merge alias through both the extracted owner
 * and the legacy façade. The data provider is explicit so removed aliases
 * cannot disappear behind reflection-only coverage.
 */
final class AtlasAiSelfConstructionReadinessProjectionAgentReviewMergeSectionTest extends TestCase
{
    #[DataProvider('agentReviewMergeAliases')]
    public function test_every_review_merge_alias_executes_with_a_fail_closed_envelope(string $method): void
    {
        $service = app(AtlasSelfConstructionReadinessService::class);
        $section = new ReadinessProjectionAgentReviewMergeSection($service);
        $direct = $section->{$method}([]);
        $viaFacade = $service->{$method}([]);

        self::assertIsArray($direct);
        self::assertNotSame('', (string) data_get($direct, 'schema_version'));
        self::assertNotSame('', (string) data_get($direct, 'status'));
        self::assertFalse((bool) data_get($direct, 'execution_allowed'));
        self::assertFalse((bool) data_get($direct, 'dispatch_allowed'));
        self::assertFalse((bool) data_get($direct, 'ledger_write_allowed'));
        self::assertSame(data_get($direct, 'schema_version'), data_get($viaFacade, 'schema_version'));
        self::assertSame(data_get($direct, 'status'), data_get($viaFacade, 'status'));
        self::assertSame(data_get($direct, 'execution_allowed'), data_get($viaFacade, 'execution_allowed'));
    }

    /** @return array<string, array{0: string}> */
    public static function agentReviewMergeAliases(): array
    {
        return [
            'action-template' => ['agentReviewMergeActionTemplate'],
            'preflight' => ['agentReviewMergePreflight'],
            'action-draft' => ['agentReviewMergeActionDraft'],
            'receipt-draft' => ['agentReviewMergeReceiptDraft'],
            'signature-request' => ['agentReviewMergeSignatureRequest'],
            'post-signature-runbook' => ['agentReviewMergePostSignatureRunbook'],
            'execution-checklist' => ['agentReviewMergeExecutionChecklist'],
            'authorization-template' => ['agentReviewMergeAuthorizationTemplate'],
            'authorization-receipt-draft' => ['agentReviewMergeAuthorizationReceiptDraft'],
            'authorization-signature-request' => ['agentReviewMergeAuthorizationSignatureRequest'],
            'authorization-post-signature-runbook' => ['agentReviewMergeAuthorizationPostSignatureRunbook'],
            'final-authorization-preflight' => ['agentReviewMergeFinalAuthorizationPreflight'],
            'authorizing-action-template' => ['agentReviewMergeAuthorizingActionTemplate'],
            'final-receipt-draft' => ['agentReviewMergeFinalReceiptDraft'],
            'final-signature-request' => ['agentReviewMergeFinalSignatureRequest'],
            'final-post-signature-runbook' => ['agentReviewMergeFinalPostSignatureRunbook'],
            'signed-final-receipt-template' => ['agentReviewMergeSignedFinalReceiptTemplate'],
            'signed-final-receipt-preflight' => ['agentReviewMergeSignedFinalReceiptPreflight'],
            'signed-final-receipt-persistence-template' => ['agentReviewMergeSignedFinalReceiptPersistenceTemplate'],
            'executor-release-preflight' => ['agentReviewMergeExecutorReleasePreflight'],
            'executor-contract-template' => ['agentReviewMergeExecutorContractTemplate'],
            'execution-receipt-template' => ['agentReviewMergeExecutionReceiptTemplate'],
            'post-execution-preflight' => ['agentReviewMergePostExecutionPreflight'],
            'post-execution-action-template' => ['agentReviewMergePostExecutionActionTemplate'],
            'post-execution-action-receipt-draft' => ['agentReviewMergePostExecutionActionReceiptDraft'],
            'post-execution-action-signature-request' => ['agentReviewMergePostExecutionActionSignatureRequest'],
            'post-execution-action-post-signature-runbook' => ['agentReviewMergePostExecutionActionPostSignatureRunbook'],
            'post-execution-action-signed-receipt-template' => ['agentReviewMergePostExecutionActionSignedReceiptTemplate'],
            'post-execution-action-signed-receipt-preflight' => ['agentReviewMergePostExecutionActionSignedReceiptPreflight'],
            'post-execution-action-signed-receipt-persistence-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceTemplate'],
            'post-execution-action-signed-receipt-persistence-receipt-draft' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceReceiptDraft'],
            'post-execution-action-signed-receipt-persistence-preflight' => ['agentReviewMergePostExecutionActionSignedReceiptPersistencePreflight'],
            'post-execution-action-signed-receipt-persistence-post-preflight-runbook' => ['agentReviewMergePostExecutionActionSignedReceiptPersistencePostPreflightRunbook'],
            'post-execution-action-signed-receipt-persistence-append-only-event-payload-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceAppendOnlyEventPayloadTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-preflight' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterPreflight'],
            'post-execution-action-signed-receipt-persistence-writer-contract-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterContractTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-implementation-preflight' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterImplementationPreflight'],
            'post-execution-action-signed-receipt-persistence-writer-release-authorization-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-authorization-preflight' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationPreflight'],
            'post-execution-action-signed-receipt-persistence-writer-release-authorization-receipt-draft' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationReceiptDraft'],
            'post-execution-action-signed-receipt-persistence-writer-release-authorization-signature-request' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationSignatureRequest'],
            'post-execution-action-signed-receipt-persistence-writer-release-authorization-post-signature-runbook' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationPostSignatureRunbook'],
            'post-execution-action-signed-receipt-persistence-writer-release-authorization-signed-receipt-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationSignedReceiptTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-preflight' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleasePreflight'],
            'post-execution-action-signed-receipt-persistence-writer-release-receipt-draft' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseReceiptDraft'],
            'post-execution-action-signed-receipt-persistence-writer-release-signature-request' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseSignatureRequest'],
            'post-execution-action-signed-receipt-persistence-writer-release-post-signature-runbook' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleasePostSignatureRunbook'],
            'post-execution-action-signed-receipt-persistence-writer-release-signed-receipt-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseSignedReceiptTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-execution-contract-preflight' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseExecutionContractPreflight'],
            'post-execution-action-signed-receipt-persistence-writer-release-execution-contract-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseExecutionContractTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-disable-contract-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseDisableContractTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-observability-contract-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseObservabilityContractTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-post-monitoring-review-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleasePostMonitoringReviewTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-reenable-review-packet-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseReenableReviewPacketTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-request-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationRequestTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-receipt-draft-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationReceiptDraftTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-signature-request-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationSignatureRequestTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-post-signature-runbook-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationPostSignatureRunbookTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-signed-receipt-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationSignedReceiptTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-execution-contract-preflight-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationExecutionContractPreflightTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-execution-contract-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationExecutionContractTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-disable-contract-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationDisableContractTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-observability-contract-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationObservabilityContractTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-post-monitoring-review-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationPostMonitoringReviewTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-health-decision-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationHealthDecisionTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-disable-request-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationDisableRequestTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-request-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleRequestTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-authorization-request-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleAuthorizationRequestTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-receipt-draft-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleReceiptDraftTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-signature-request-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleSignatureRequestTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-post-signature-runbook-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCyclePostSignatureRunbookTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-signed-receipt-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleSignedReceiptTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-execution-contract-preflight-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleExecutionContractPreflightTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-execution-contract-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleExecutionContractTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-contract-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableContractTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-observability-contract-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleObservabilityContractTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-post-monitoring-review-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCyclePostMonitoringReviewTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-health-decision-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleHealthDecisionTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-request-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableRequestTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-preflight-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionPreflightTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-receipt-draft-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionReceiptDraftTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-signed-receipt-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionSignedReceiptTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-persistence-preflight-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionPersistencePreflightTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-persistence-receipt-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionPersistenceReceiptTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-post-persistence-review-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionPostPersistenceReviewTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-follow-up-observability-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionFollowUpObservabilityTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-evidence-repair-request-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionEvidenceRepairRequestTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-repaired-evidence-packet-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionRepairedEvidencePacketTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-repair-review-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionRepairReviewTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-repair-outcome-packet-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionRepairOutcomePacketTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-request-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleRequestTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-preflight-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCyclePreflightTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-request-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationRequestTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-receipt-draft-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationReceiptDraftTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-signature-request-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationSignatureRequestTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-post-signature-runbook-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationPostSignatureRunbookTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-signature-validation-report-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationSignatureValidationReportTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-signed-receipt-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationSignedReceiptTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-signed-receipt-preflight-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationSignedReceiptPreflightTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-persistence-preflight-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationPersistencePreflightTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-persistence-receipt-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationPersistenceReceiptTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-post-persistence-review-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationPostPersistenceReviewTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-follow-up-observability-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationFollowUpObservabilityTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-evidence-repair-request-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationEvidenceRepairRequestTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-repaired-evidence-packet-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationRepairedEvidencePacketTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-repair-review-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationRepairReviewTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-persistence-rejection-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationPersistenceRejectionTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-human-escalation-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationHumanEscalationTemplate'],
            'post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-manual-decision-request-template' => ['agentReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationManualDecisionRequestTemplate'],
        ];
    }
}
