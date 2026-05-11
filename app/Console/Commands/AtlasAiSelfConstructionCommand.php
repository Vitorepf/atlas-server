<?php

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use Illuminate\Console\Command;

class AtlasAiSelfConstructionCommand extends Command
{
    protected $signature = 'atlas:ai:self-construction
        {--workspace= : Workspace root for reporting}
        {--meta-sdd : Generate a read-only Meta-SDD candidate packet}
        {--receipt-preview : Generate a read-only Decision Receipt preview}
        {--traceability : Audit Self-Construction documentation traceability}
        {--promotion-gate : Evaluate read-only phase promotion readiness}
        {--execution-candidate : Generate a read-only Phase 5 execution candidate}
        {--approval-packet : Generate a read-only human approval packet}
        {--receipt-draft : Generate a read-only signed receipt draft}
        {--execution-preflight : Evaluate read-only execution preflight}
        {--signature-request : Generate a read-only human signature request}
        {--execution-runbook : Generate a read-only post-signature execution runbook}
        {--evidence-packet : Generate a read-only post-execution evidence packet template}
        {--completion-readiness : Evaluate read-only completion readiness}
        {--residual-risk : Evaluate read-only residual risk before completion}
        {--handoff-packet : Generate a read-only handoff packet for the next operator}
        {--next-action : Select the next read-only safe action}
        {--surface-matrix : Generate a read-only command surface matrix}
        {--external-blockers : Report read-only blockers outside Self-Construction ownership}
        {--cold-lane-certification : Certify read-only Self-Construction cold lane status}
        {--operator-checklist : Generate a read-only operator checklist for the next safe review}
        {--promotion-blockers : Consolidate read-only blockers for promotion and completion}
        {--readiness-digest : Generate a compact read-only readiness digest}
        {--governance-scorecard : Generate a read-only governance scorecard}
        {--integrity-manifest : Generate a read-only integrity manifest of governed packets}
        {--continuation-token : Generate a compact read-only continuation token}
        {--ownership-boundary : Generate a read-only ownership boundary report}
        {--phase-ledger : Generate a read-only Self-Construction phase ledger}
        {--implementation-packet : Generate a read-only AI implementation packet}
        {--work-splitter : Generate read-only disjoint work packets for parallel AI sessions}
        {--scope-validator : Validate current diff against read-only packet scope}
        {--assignment-preview : Select one read-only packet for one AI session without persisting a claim}
        {--packet-runbook : Generate read-only packet consumption runbook for the selected assignment}
        {--packet-evidence-report : Review selected packet evidence without marking completion}
        {--packet-completion-gate : Evaluate packet completion readiness without persisting completion}
        {--reservation-ledger-preview : Preview future durable packet reservation without persisting a claim}
        {--durable-reservation-ledger-plan : Generate the read-only durable reservation ledger implementation plan}
        {--durable-reservation-ap-candidate : Generate the read-only AP candidate for durable packet reservations}
        {--durable-reservation-approval-request : Generate the read-only approval request for durable reservation implementation}
        {--durable-reservation-approval-decision : Generate the read-only approval decision template for durable reservation implementation}
        {--durable-reservation-post-approval-preflight : Generate the read-only post-approval preflight for durable reservation implementation}
        {--durable-reservation-implementation-packet : Generate the read-only implementation packet for durable reservation work}
        {--durable-reservation-storage-schema : Generate the read-only storage schema packet for durable reservation migrations}
        {--durable-reservation-repository-contract : Generate the read-only repository contract for durable reservation claims}
        {--durable-reservation-collision-guard : Generate the read-only collision guard contract for durable reservation claims}
        {--durable-reservation-lease-lifecycle : Generate the read-only lease lifecycle contract for durable reservation claims}
        {--durable-reservation-readiness-projection : Generate the read-only readiness projection contract for durable reservation state}
        {--durable-reservation-implementation-preflight : Generate the read-only final implementation preflight for durable reservation work}
        {--durable-reservation-migration-blueprint : Generate the read-only migration blueprint for durable reservation storage}
        {--durable-reservation-repository-blueprint : Generate the read-only repository implementation blueprint for durable reservation storage}
        {--durable-reservation-collision-guard-blueprint : Generate the read-only collision guard implementation blueprint for durable reservation claims}
        {--durable-reservation-lease-lifecycle-blueprint : Generate the read-only lease lifecycle implementation blueprint for durable reservation claims}
        {--durable-reservation-readiness-projection-blueprint : Generate the read-only readiness projection implementation blueprint for durable reservation state}
        {--durable-reservation-runtime-build-packet : Generate the read-only runtime build packet for durable reservation implementation}
        {--ai-session-bootstrap : Generate one read-only bootstrap packet for a new AI implementation session}
        {--packet-queue : Generate a read-only queue of available, blocked and withheld packets}
        {--parallel-session-plan : Plan up to five read-only AI session slots without claims or dispatch}
        {--collision-matrix : Generate a read-only packet collision matrix for parallel safety}
        {--dependency-unlock-plan : Preview which completed packets would unlock blocked work}
        {--multi-session-readiness-gate : Decide whether multiple AI sessions may safely proceed}
        {--single-session-instruction-packet : Generate the canonical read-only instruction packet for one AI session}
        {--codex-launch-plan : Generate a read-only launch plan with up to five Codex start commands}
        {--codex-execution-status : Generate a read-only execution status monitor for parallel Codex sessions}
        {--codex-integration-report : Generate a read-only integration report for completed Codex packets}
        {--codex-merge-readiness : Evaluate read-only merge review readiness for completed Codex packets}
        {--codex-final-review-packet : Generate a read-only final review packet for the principal integrator}
        {--codex-review-decision-template : Generate a read-only principal integrator decision template}
        {--codex-review-receipt-draft : Generate a read-only unsigned principal integrator review receipt draft}
        {--codex-review-signature-request : Generate a read-only signature request for the Codex review receipt draft}
        {--codex-review-post-signature-runbook : Generate a read-only post-signature runbook for the Codex review flow}
        {--codex-review-merge-action-template : Generate a read-only explicit merge action template for the Codex review flow}
        {--codex-review-merge-preflight : Evaluate read-only preflight for a future explicit Codex merge action}
        {--codex-start-packet : Durably claim the next packet and emit the canonical Codex session start contract}
        {--reservation-status : Read durable local packet reservation ledger status}
        {--claim-next-packet : Durably claim the next available Work Splitter packet and return scoped bootstrap instructions}
        {--claim-packet : Durably claim one Work Splitter packet in the local reservation ledger}
        {--release-packet : Release one active local packet reservation}
        {--complete-packet : Mark one active local packet reservation completed}
        {--target= : Target capability for Meta-SDD candidate generation}
        {--packet= : Packet id for packet-scoped bootstrap, runbook and validation}
        {--actor= : Reservation actor for packet claim/release}
        {--session= : Reservation session id for packet claim/release}
        {--lease-minutes=120 : Reservation lease duration in minutes}
        {--reason= : Release reason}
        {--evidence-hash= : Optional evidence hash for packet completion}
        {--json : Print machine-readable JSON}';

    protected $description = 'Show Atlas Self-Construction OS readiness and next safe governed construction blocks.';

    public function handle(AtlasSelfConstructionReadinessService $readiness): int
    {
        $options = [
            'workspace' => $this->option('workspace'),
            'target' => $this->option('target'),
            'packet' => $this->option('packet'),
            'actor' => $this->option('actor'),
            'session' => $this->option('session'),
            'lease_minutes' => $this->option('lease-minutes'),
            'reason' => $this->option('reason'),
            'evidence_hash' => $this->option('evidence-hash'),
        ];

        $payload = match (true) {
            (bool) $this->option('release-packet') => $readiness->releasePacket($options),
            (bool) $this->option('complete-packet') => $readiness->completePacket($options),
            (bool) $this->option('codex-start-packet') => $readiness->codexStartPacket($options),
            (bool) $this->option('claim-next-packet') => $readiness->claimNextPacket($options),
            (bool) $this->option('claim-packet') => $readiness->claimPacket($options),
            (bool) $this->option('reservation-status') => $readiness->reservationStatus($options),
            (bool) $this->option('scope-validator') => $readiness->scopeValidator($options),
            (bool) $this->option('single-session-instruction-packet') => $readiness->singleSessionInstructionPacket($options),
            (bool) $this->option('codex-review-merge-preflight') => $readiness->codexReviewMergePreflight($options),
            (bool) $this->option('codex-review-merge-action-template') => $readiness->codexReviewMergeActionTemplate($options),
            (bool) $this->option('codex-review-post-signature-runbook') => $readiness->codexReviewPostSignatureRunbook($options),
            (bool) $this->option('codex-review-signature-request') => $readiness->codexReviewSignatureRequest($options),
            (bool) $this->option('codex-review-receipt-draft') => $readiness->codexReviewReceiptDraft($options),
            (bool) $this->option('codex-review-decision-template') => $readiness->codexReviewDecisionTemplate($options),
            (bool) $this->option('codex-final-review-packet') => $readiness->codexFinalReviewPacket($options),
            (bool) $this->option('codex-merge-readiness') => $readiness->codexMergeReadiness($options),
            (bool) $this->option('codex-integration-report') => $readiness->codexIntegrationReport($options),
            (bool) $this->option('codex-execution-status') => $readiness->codexExecutionStatus($options),
            (bool) $this->option('codex-launch-plan') => $readiness->codexLaunchPlan($options),
            (bool) $this->option('multi-session-readiness-gate') => $readiness->multiSessionReadinessGate($options),
            (bool) $this->option('dependency-unlock-plan') => $readiness->dependencyUnlockPlan($options),
            (bool) $this->option('collision-matrix') => $readiness->collisionMatrix($options),
            (bool) $this->option('parallel-session-plan') => $readiness->parallelSessionPlan($options),
            (bool) $this->option('packet-queue') => $readiness->packetQueue($options),
            (bool) $this->option('ai-session-bootstrap') => $readiness->aiSessionBootstrap($options),
            (bool) $this->option('durable-reservation-runtime-build-packet') => $readiness->durableReservationRuntimeBuildPacket($options),
            (bool) $this->option('durable-reservation-readiness-projection-blueprint') => $readiness->durableReservationReadinessProjectionBlueprint($options),
            (bool) $this->option('durable-reservation-lease-lifecycle-blueprint') => $readiness->durableReservationLeaseLifecycleBlueprint($options),
            (bool) $this->option('durable-reservation-collision-guard-blueprint') => $readiness->durableReservationCollisionGuardBlueprint($options),
            (bool) $this->option('durable-reservation-repository-blueprint') => $readiness->durableReservationRepositoryBlueprint($options),
            (bool) $this->option('durable-reservation-migration-blueprint') => $readiness->durableReservationMigrationBlueprint($options),
            (bool) $this->option('durable-reservation-implementation-preflight') => $readiness->durableReservationImplementationPreflight($options),
            (bool) $this->option('durable-reservation-readiness-projection') => $readiness->durableReservationReadinessProjection($options),
            (bool) $this->option('durable-reservation-lease-lifecycle') => $readiness->durableReservationLeaseLifecycle($options),
            (bool) $this->option('durable-reservation-collision-guard') => $readiness->durableReservationCollisionGuard($options),
            (bool) $this->option('durable-reservation-repository-contract') => $readiness->durableReservationRepositoryContract($options),
            (bool) $this->option('durable-reservation-storage-schema') => $readiness->durableReservationStorageSchema($options),
            (bool) $this->option('durable-reservation-implementation-packet') => $readiness->durableReservationImplementationPacket($options),
            (bool) $this->option('durable-reservation-post-approval-preflight') => $readiness->durableReservationPostApprovalPreflight($options),
            (bool) $this->option('durable-reservation-approval-decision') => $readiness->durableReservationApprovalDecisionTemplate($options),
            (bool) $this->option('durable-reservation-approval-request') => $readiness->durableReservationApprovalRequest($options),
            (bool) $this->option('durable-reservation-ap-candidate') => $readiness->durableReservationApCandidate($options),
            (bool) $this->option('durable-reservation-ledger-plan') => $readiness->durableReservationLedgerImplementationPlan($options),
            (bool) $this->option('reservation-ledger-preview') => $readiness->reservationLedgerPreview($options),
            (bool) $this->option('packet-completion-gate') => $readiness->packetCompletionGate($options),
            (bool) $this->option('packet-evidence-report') => $readiness->packetEvidenceReport($options),
            (bool) $this->option('packet-runbook') => $readiness->packetRunbook($options),
            (bool) $this->option('assignment-preview') => $readiness->assignmentPreview($options),
            (bool) $this->option('work-splitter') => $readiness->workSplitter($options),
            (bool) $this->option('implementation-packet') => $readiness->implementationPacket($options),
            (bool) $this->option('ownership-boundary') => $readiness->ownershipBoundary($options),
            (bool) $this->option('continuation-token') => $readiness->continuationToken($options),
            (bool) $this->option('integrity-manifest') => $readiness->integrityManifest($options),
            (bool) $this->option('governance-scorecard') => $readiness->governanceScorecard($options),
            (bool) $this->option('readiness-digest') => $readiness->readinessDigest($options),
            (bool) $this->option('promotion-blockers') => $readiness->promotionBlockers($options),
            (bool) $this->option('operator-checklist') => $readiness->operatorChecklist($options),
            (bool) $this->option('cold-lane-certification') => $readiness->coldLaneCertification($options),
            (bool) $this->option('external-blockers') => $readiness->externalBlockers($options),
            (bool) $this->option('surface-matrix') => $readiness->surfaceMatrix($options),
            (bool) $this->option('phase-ledger') => $readiness->phaseLedger($options),
            (bool) $this->option('next-action') => $readiness->nextAction($options),
            (bool) $this->option('handoff-packet') => $readiness->handoffPacket($options),
            (bool) $this->option('residual-risk') => $readiness->residualRisk($options),
            (bool) $this->option('completion-readiness') => $readiness->completionReadiness($options),
            (bool) $this->option('evidence-packet') => $readiness->evidencePacket($options),
            (bool) $this->option('execution-runbook') => $readiness->executionRunbook($options),
            (bool) $this->option('signature-request') => $readiness->signatureRequest($options),
            (bool) $this->option('execution-preflight') => $readiness->executionPreflight($options),
            (bool) $this->option('receipt-draft') => $readiness->receiptDraft($options),
            (bool) $this->option('approval-packet') => $readiness->approvalPacket($options),
            (bool) $this->option('execution-candidate') => $readiness->executionCandidate($options),
            (bool) $this->option('promotion-gate') => $readiness->promotionGate($options),
            (bool) $this->option('traceability') => $readiness->traceabilityAudit($options),
            (bool) $this->option('receipt-preview') => $readiness->receiptPreview($options),
            (bool) $this->option('meta-sdd') => $readiness->metaSddPacket($options),
            default => $readiness->snapshot($options),
        };

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Self-Construction OS</>', (string) $payload['status']);

        if ((bool) $this->option('reservation-status')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Ledger write allowed', data_get($payload, 'ledger_write_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Active reservations', (string) data_get($payload, 'ledger.active_count'));
            $this->components->twoColumnDetail('Completed reservations', (string) data_get($payload, 'ledger.completed_count'));
            $this->components->twoColumnDetail('Ledger hash', (string) data_get($payload, 'ledger_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('claim-packet')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Claim persisted', data_get($payload, 'claim_persisted') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Packet', (string) data_get($payload, 'claim.packet_id'));
            $this->components->twoColumnDetail('Claim hash', (string) data_get($payload, 'claim_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('claim-next-packet')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Claim persisted', data_get($payload, 'claim_persisted') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Packet', (string) data_get($payload, 'packet_id'));
            $this->components->twoColumnDetail('Start hash', (string) data_get($payload, 'start_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-start-packet')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Claim persisted', data_get($payload, 'claim_persisted') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Packet', (string) data_get($payload, 'packet_id'));
            $this->components->twoColumnDetail('Contract hash', (string) data_get($payload, 'contract_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-launch-plan')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Launchable sessions', (string) data_get($payload, 'plan.launchable_count'));
            $this->components->twoColumnDetail('Plan hash', (string) data_get($payload, 'plan_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-execution-status')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Active', (string) data_get($payload, 'monitor.counts.claimed'));
            $this->components->twoColumnDetail('Completed', (string) data_get($payload, 'monitor.counts.completed'));
            $this->components->twoColumnDetail('Available', (string) data_get($payload, 'monitor.counts.available'));
            $this->components->twoColumnDetail('Monitor hash', (string) data_get($payload, 'monitor_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-integration-report')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Integration status', (string) data_get($payload, 'report.integration_status'));
            $this->components->twoColumnDetail('Ready to review', (string) data_get($payload, 'report.counts.ready_to_review'));
            $this->components->twoColumnDetail('Missing packets', (string) data_get($payload, 'report.counts.missing_packets'));
            $this->components->twoColumnDetail('Report hash', (string) data_get($payload, 'report_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-merge-readiness')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Merge review status', (string) data_get($payload, 'readiness.merge_review_status'));
            $this->components->twoColumnDetail('Blocking count', (string) data_get($payload, 'readiness.blocking_count'));
            $this->components->twoColumnDetail('Ready packets', (string) data_get($payload, 'readiness.ready_packet_count'));
            $this->components->twoColumnDetail('Readiness hash', (string) data_get($payload, 'readiness_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-final-review-packet')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Review status', (string) data_get($payload, 'packet.review_status'));
            $this->components->twoColumnDetail('Decision required', data_get($payload, 'packet.decision_required') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Ready packets', (string) data_get($payload, 'packet.ready_packet_count'));
            $this->components->twoColumnDetail('Packet hash', (string) data_get($payload, 'packet_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-decision-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Decision status', (string) data_get($payload, 'template.decision_status'));
            $this->components->twoColumnDetail('Recording allowed', data_get($payload, 'template.decision_recording_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Default decision', (string) data_get($payload, 'template.default_decision'));
            $this->components->twoColumnDetail('Template hash', (string) data_get($payload, 'template_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-receipt-draft')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Receipt status', (string) data_get($payload, 'receipt.status'));
            $this->components->twoColumnDetail('Signature required', data_get($payload, 'receipt.signature_required') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Default decision', (string) data_get($payload, 'receipt.default_decision'));
            $this->components->twoColumnDetail('Receipt hash', (string) data_get($payload, 'receipt_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-signature-request')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Signature status', (string) data_get($payload, 'signature_request.status'));
            $this->components->twoColumnDetail('Signature present', data_get($payload, 'signature_request.signature_present') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Signable hash', (string) data_get($payload, 'signable_payload_hash'));
            $this->components->twoColumnDetail('Request hash', (string) data_get($payload, 'request_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-post-signature-runbook')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Runbook status', (string) data_get($payload, 'runbook.status'));
            $this->components->twoColumnDetail('Signature required', data_get($payload, 'runbook.signature_required') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Step count', (string) data_get($payload, 'runbook.step_count'));
            $this->components->twoColumnDetail('Runbook hash', (string) data_get($payload, 'runbook_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-action-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Merge action status', (string) data_get($payload, 'template.status'));
            $this->components->twoColumnDetail('Explicit action required', data_get($payload, 'template.explicit_merge_action_required') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Merge allowed', data_get($payload, 'merge_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Template hash', (string) data_get($payload, 'template_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-preflight')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Preflight status', (string) data_get($payload, 'preflight.status'));
            $this->components->twoColumnDetail('Blocking count', (string) data_get($payload, 'preflight.blocking_count'));
            $this->components->twoColumnDetail('Merge allowed', data_get($payload, 'merge_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Preflight hash', (string) data_get($payload, 'preflight_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('release-packet')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Released', data_get($payload, 'release.released') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Packet', (string) data_get($payload, 'release.packet_id'));
            $this->components->twoColumnDetail('Release hash', (string) data_get($payload, 'release_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('complete-packet')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Completed', data_get($payload, 'completion.completed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Packet', (string) data_get($payload, 'completion.packet_id'));
            $this->components->twoColumnDetail('Completion hash', (string) data_get($payload, 'completion_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('ownership-boundary')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Allowed files', (string) data_get($payload, 'allowed_file_count'));
            $this->components->twoColumnDetail('Forbidden scopes', (string) data_get($payload, 'forbidden_scope_count'));
            $this->components->twoColumnDetail('Boundary hash', (string) data_get($payload, 'boundary_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('scope-validator')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Validator status', (string) $payload['status']);
            $this->components->twoColumnDetail('Blocking violations', (string) data_get($payload, 'summary.blocking_count'));
            $this->components->twoColumnDetail('Validator hash', (string) data_get($payload, 'validator_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('reservation-ledger-preview')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Ledger write allowed', data_get($payload, 'ledger_write_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Selected packet', (string) data_get($payload, 'reservation.selected_packet_id'));
            $this->components->twoColumnDetail('Reservation hash', (string) data_get($payload, 'reservation_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('durable-reservation-ledger-plan')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Ledger write allowed', data_get($payload, 'ledger_write_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Storage objects', (string) data_get($payload, 'plan.storage_object_count'));
            $this->components->twoColumnDetail('Plan hash', (string) data_get($payload, 'plan_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('durable-reservation-ap-candidate')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('AP id', (string) data_get($payload, 'candidate.ap_id'));
            $this->components->twoColumnDetail('Packet count', (string) data_get($payload, 'candidate.packet_count'));
            $this->components->twoColumnDetail('Candidate hash', (string) data_get($payload, 'candidate_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('durable-reservation-approval-request')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Approval status', (string) data_get($payload, 'request.approval_status'));
            $this->components->twoColumnDetail('Required signers', (string) data_get($payload, 'request.required_signer_count'));
            $this->components->twoColumnDetail('Request hash', (string) data_get($payload, 'request_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('durable-reservation-approval-decision')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Decision status', (string) data_get($payload, 'decision.decision_status'));
            $this->components->twoColumnDetail('Signer slots', (string) data_get($payload, 'decision.signer_slot_count'));
            $this->components->twoColumnDetail('Decision hash', (string) data_get($payload, 'decision_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('durable-reservation-post-approval-preflight')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Preflight decision', (string) data_get($payload, 'preflight.decision'));
            $this->components->twoColumnDetail('Blocking checks', (string) data_get($payload, 'preflight.blocking_check_count'));
            $this->components->twoColumnDetail('Preflight hash', (string) data_get($payload, 'preflight_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('durable-reservation-implementation-packet')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Packet status', (string) data_get($payload, 'packet.packet_status'));
            $this->components->twoColumnDetail('Work packets', (string) data_get($payload, 'packet.work_packet_count'));
            $this->components->twoColumnDetail('Packet hash', (string) data_get($payload, 'packet_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('durable-reservation-storage-schema')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Schema status', (string) data_get($payload, 'storage_schema.schema_status'));
            $this->components->twoColumnDetail('Tables', (string) data_get($payload, 'storage_schema.table_count'));
            $this->components->twoColumnDetail('Schema hash', (string) data_get($payload, 'schema_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('durable-reservation-repository-contract')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Contract status', (string) data_get($payload, 'repository_contract.contract_status'));
            $this->components->twoColumnDetail('Methods', (string) data_get($payload, 'repository_contract.method_count'));
            $this->components->twoColumnDetail('Contract hash', (string) data_get($payload, 'contract_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('durable-reservation-collision-guard')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Guard status', (string) data_get($payload, 'collision_guard.guard_status'));
            $this->components->twoColumnDetail('Blocking decisions', (string) data_get($payload, 'collision_guard.blocking_decision_count'));
            $this->components->twoColumnDetail('Guard hash', (string) data_get($payload, 'guard_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('durable-reservation-lease-lifecycle')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Lifecycle status', (string) data_get($payload, 'lease_lifecycle.lifecycle_status'));
            $this->components->twoColumnDetail('States', (string) data_get($payload, 'lease_lifecycle.state_count'));
            $this->components->twoColumnDetail('Lifecycle hash', (string) data_get($payload, 'lifecycle_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('durable-reservation-readiness-projection')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Projection status', (string) data_get($payload, 'readiness_projection.projection_status'));
            $this->components->twoColumnDetail('Queue states', (string) data_get($payload, 'readiness_projection.queue_state_count'));
            $this->components->twoColumnDetail('Projection hash', (string) data_get($payload, 'projection_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('durable-reservation-implementation-preflight')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Preflight status', (string) data_get($payload, 'implementation_preflight.preflight_status'));
            $this->components->twoColumnDetail('Contract hashes', (string) data_get($payload, 'implementation_preflight.contract_hash_count'));
            $this->components->twoColumnDetail('Preflight hash', (string) data_get($payload, 'preflight_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('durable-reservation-migration-blueprint')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Blueprint status', (string) data_get($payload, 'migration_blueprint.blueprint_status'));
            $this->components->twoColumnDetail('Tables', (string) data_get($payload, 'migration_blueprint.table_count'));
            $this->components->twoColumnDetail('Blueprint hash', (string) data_get($payload, 'blueprint_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('durable-reservation-repository-blueprint')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Blueprint status', (string) data_get($payload, 'repository_blueprint.blueprint_status'));
            $this->components->twoColumnDetail('Classes', (string) data_get($payload, 'repository_blueprint.class_count'));
            $this->components->twoColumnDetail('Blueprint hash', (string) data_get($payload, 'blueprint_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('durable-reservation-collision-guard-blueprint')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Blueprint status', (string) data_get($payload, 'collision_guard_blueprint.blueprint_status'));
            $this->components->twoColumnDetail('Blockers', (string) data_get($payload, 'collision_guard_blueprint.blocker_count'));
            $this->components->twoColumnDetail('Blueprint hash', (string) data_get($payload, 'blueprint_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('durable-reservation-lease-lifecycle-blueprint')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Blueprint status', (string) data_get($payload, 'lease_lifecycle_blueprint.blueprint_status'));
            $this->components->twoColumnDetail('States', (string) data_get($payload, 'lease_lifecycle_blueprint.state_count'));
            $this->components->twoColumnDetail('Blueprint hash', (string) data_get($payload, 'blueprint_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('durable-reservation-readiness-projection-blueprint')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Blueprint status', (string) data_get($payload, 'readiness_projection_blueprint.blueprint_status'));
            $this->components->twoColumnDetail('Queue states', (string) data_get($payload, 'readiness_projection_blueprint.queue_state_count'));
            $this->components->twoColumnDetail('Blueprint hash', (string) data_get($payload, 'blueprint_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('durable-reservation-runtime-build-packet')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Build status', (string) data_get($payload, 'runtime_build_packet.build_status'));
            $this->components->twoColumnDetail('Slices', (string) data_get($payload, 'runtime_build_packet.slice_count'));
            $this->components->twoColumnDetail('Build hash', (string) data_get($payload, 'build_packet_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('ai-session-bootstrap')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Claim persisted', data_get($payload, 'claim_persisted') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Selected packet', (string) data_get($payload, 'bootstrap.selected_packet_id'));
            $this->components->twoColumnDetail('Bootstrap hash', (string) data_get($payload, 'bootstrap_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('packet-queue')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Claim persisted', data_get($payload, 'claim_persisted') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Queue entries', (string) data_get($payload, 'queue.entry_count'));
            $this->components->twoColumnDetail('Queue hash', (string) data_get($payload, 'queue_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('parallel-session-plan')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Dispatch allowed', data_get($payload, 'dispatch_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Session slots', (string) data_get($payload, 'plan.slot_count'));
            $this->components->twoColumnDetail('Plan hash', (string) data_get($payload, 'plan_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('collision-matrix')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Pair count', (string) data_get($payload, 'matrix.pair_count'));
            $this->components->twoColumnDetail('Blocked pairs', (string) data_get($payload, 'matrix.blocked_pair_count'));
            $this->components->twoColumnDetail('Matrix hash', (string) data_get($payload, 'matrix_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('dependency-unlock-plan')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Blocked packets', (string) data_get($payload, 'plan.blocked_packet_count'));
            $this->components->twoColumnDetail('Unlock edges', (string) data_get($payload, 'plan.unlock_edge_count'));
            $this->components->twoColumnDetail('Plan hash', (string) data_get($payload, 'plan_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('multi-session-readiness-gate')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Decision', (string) data_get($payload, 'gate.decision'));
            $this->components->twoColumnDetail('Multi-session allowed', data_get($payload, 'multi_session_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Gate hash', (string) data_get($payload, 'gate_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('single-session-instruction-packet')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Selected packet', (string) data_get($payload, 'instruction.selected_packet_id'));
            $this->components->twoColumnDetail('Safe next', (string) data_get($payload, 'instruction.safe_next_instruction'));
            $this->components->twoColumnDetail('Instruction hash', (string) data_get($payload, 'instruction_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('packet-completion-gate')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Completion allowed', data_get($payload, 'completion_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Decision', (string) data_get($payload, 'gate.decision'));
            $this->components->twoColumnDetail('Gate hash', (string) data_get($payload, 'gate_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('packet-evidence-report')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Completion allowed', data_get($payload, 'completion_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Report status', (string) $payload['status']);
            $this->components->twoColumnDetail('Report hash', (string) data_get($payload, 'report_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('packet-runbook')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Runbook', (string) data_get($payload, 'runbook.runbook_id'));
            $this->components->twoColumnDetail('Selected packet', (string) data_get($payload, 'runbook.selected_packet_id'));
            $this->components->twoColumnDetail('Runbook hash', (string) data_get($payload, 'runbook_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('assignment-preview')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Assignment', (string) data_get($payload, 'assignment.assignment_id'));
            $this->components->twoColumnDetail('Selected packet', (string) data_get($payload, 'assignment.selected_packet_id'));
            $this->components->twoColumnDetail('Assignment hash', (string) data_get($payload, 'assignment_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('work-splitter')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Packets', (string) data_get($payload, 'packet_count'));
            $this->components->twoColumnDetail('Withheld', (string) data_get($payload, 'withheld_count'));
            $this->components->twoColumnDetail('Split hash', (string) data_get($payload, 'split_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('implementation-packet')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Packet', (string) data_get($payload, 'packet.packet_id'));
            $this->components->twoColumnDetail('Risk', (string) data_get($payload, 'packet.risk_level'));
            $this->components->twoColumnDetail('Packet hash', (string) data_get($payload, 'packet_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('continuation-token')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Token id', (string) data_get($payload, 'token.id'));
            $this->components->twoColumnDetail('Token hash', (string) data_get($payload, 'token_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('integrity-manifest')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Manifest entries', (string) data_get($payload, 'entry_count'));
            $this->components->twoColumnDetail('Manifest hash', (string) data_get($payload, 'manifest_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('governance-scorecard')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Score', (string) data_get($payload, 'scorecard.score'));
            $this->components->twoColumnDetail('Rating', (string) data_get($payload, 'scorecard.rating'));
            $this->components->twoColumnDetail('Scorecard hash', (string) data_get($payload, 'scorecard_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('readiness-digest')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Current phase', (string) data_get($payload, 'digest.current_phase'));
            $this->components->twoColumnDetail('Next action', (string) data_get($payload, 'digest.next_action_id'));
            $this->components->twoColumnDetail('Digest hash', (string) data_get($payload, 'digest_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('promotion-blockers')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Promotion allowed', data_get($payload, 'promotion_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Completion allowed', data_get($payload, 'completion_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Blockers', (string) data_get($payload, 'blocker_count'));
            $this->components->twoColumnDetail('Blocker hash', (string) data_get($payload, 'blocker_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('operator-checklist')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Completion allowed', data_get($payload, 'completion_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Checklist items', (string) data_get($payload, 'checklist_count'));
            $this->components->twoColumnDetail('Checklist hash', (string) data_get($payload, 'checklist_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('cold-lane-certification')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Completion allowed', data_get($payload, 'completion_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Next action', (string) data_get($payload, 'next_action_id'));
            $this->components->twoColumnDetail('External blockers', (string) data_get($payload, 'external_blocker_count'));
            $this->components->twoColumnDetail('Certification hash', (string) data_get($payload, 'certification_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('external-blockers')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Completion allowed', data_get($payload, 'completion_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Blockers', (string) data_get($payload, 'blocker_count'));
            $this->components->twoColumnDetail('Surface status', (string) data_get($payload, 'self_construction_surface_status'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('surface-matrix')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Completion allowed', data_get($payload, 'completion_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Surfaces', (string) data_get($payload, 'surface_count'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('phase-ledger')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Current phase', (string) data_get($payload, 'current_phase'));
            $this->components->twoColumnDetail('Next action', (string) data_get($payload, 'next_action_id'));
            $this->components->twoColumnDetail('Blocked phases', (string) data_get($payload, 'ledger_summary.blocked_count'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('next-action')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Completion allowed', data_get($payload, 'completion_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Selected action', (string) data_get($payload, 'selected_action.id'));
            $this->components->twoColumnDetail('Handoff hash', (string) data_get($payload, 'handoff_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('handoff-packet')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Completion allowed', data_get($payload, 'completion_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Handoff', (string) data_get($payload, 'handoff_packet.id'));
            $this->components->twoColumnDetail('Handoff hash', (string) data_get($payload, 'handoff_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('residual-risk')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Completion allowed', data_get($payload, 'completion_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Highest severity', (string) data_get($payload, 'risk_summary.highest_severity'));
            $this->components->twoColumnDetail('Blocking risks', (string) data_get($payload, 'risk_summary.blocking_count'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('completion-readiness')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Completion allowed', data_get($payload, 'completion_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Blocking failures', (string) count((array) data_get($payload, 'blocking_failures', [])));
            $this->components->twoColumnDetail('Evidence hash', (string) data_get($payload, 'evidence_packet_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('evidence-packet')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Evidence packet', (string) data_get($payload, 'evidence_packet.id'));
            $this->components->twoColumnDetail('Evidence hash', (string) data_get($payload, 'evidence_packet_hash'));
            $this->components->twoColumnDetail('Runbook status', (string) data_get($payload, 'runbook_status'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('execution-runbook')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Runbook', (string) data_get($payload, 'runbook.id'));
            $this->components->twoColumnDetail('Runbook hash', (string) data_get($payload, 'runbook_hash'));
            $this->components->twoColumnDetail('Signature status', (string) data_get($payload, 'signature_status'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('signature-request')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Signature request', (string) data_get($payload, 'signature_request.id'));
            $this->components->twoColumnDetail('Request hash', (string) data_get($payload, 'request_hash'));
            $this->components->twoColumnDetail('Preflight', (string) data_get($payload, 'preflight_status'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('execution-preflight')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Receipt', (string) data_get($payload, 'receipt_id'));
            $this->components->twoColumnDetail('Blocking failures', (string) count((array) data_get($payload, 'blocking_failures', [])));
            $this->components->twoColumnDetail('Next required action', (string) data_get($payload, 'next_required_action'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('receipt-draft')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Receipt draft', (string) data_get($payload, 'receipt_draft.id'));
            $this->components->twoColumnDetail('Signed', data_get($payload, 'receipt_draft.signed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Receipt hash', (string) data_get($payload, 'receipt_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('approval-packet')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Approval', (string) data_get($payload, 'approval.id'));
            $this->components->twoColumnDetail('Approved', data_get($payload, 'approval.approved') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Approval hash', (string) data_get($payload, 'approval_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('execution-candidate')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Candidate', (string) data_get($payload, 'candidate.id'));
            $this->components->twoColumnDetail('Autonomy', (string) data_get($payload, 'candidate.autonomy_level'));
            $this->components->twoColumnDetail('Candidate hash', (string) data_get($payload, 'candidate_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('promotion-gate')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Current phase', (string) data_get($payload, 'current_phase'));
            $this->components->twoColumnDetail('Recommended next phase', (string) data_get($payload, 'recommended_next_phase'));
            $this->components->twoColumnDetail('Blocking failures', (string) count((array) data_get($payload, 'blocking_failures', [])));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('traceability')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Required docs', (string) data_get($payload, 'summary.required_doc_count'));
            $this->components->twoColumnDetail('Violations', (string) data_get($payload, 'summary.violation_count'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('receipt-preview')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Receipt', (string) data_get($payload, 'receipt_preview.id'));
            $this->components->twoColumnDetail('Target capability', (string) data_get($payload, 'receipt_preview.target_capability'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('meta-sdd')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Target capability', (string) data_get($payload, 'meta_spec.target_capability'));
            $this->components->twoColumnDetail('Target maturity', (string) data_get($payload, 'meta_spec.target_maturity'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
        $this->components->twoColumnDetail('Runtime phase', (string) data_get($payload, 'summary.runtime_phase'));
        $this->components->twoColumnDetail('Docs missing', (string) data_get($payload, 'summary.missing_doc_count'));
        $this->components->twoColumnDetail('Self-programming allowed', data_get($payload, 'safety_contract.self_programming_allowed') ? 'yes' : 'no');
        $this->components->twoColumnDetail('Next target', (string) data_get($payload, 'maturity.next_target'));

        $blocks = (array) data_get($payload, 'next_safe_blocks', []);
        if ($blocks !== []) {
            $this->newLine();
            $this->line('Next safe blocks:');
            foreach ($blocks as $block) {
                $this->line('  - '.data_get($block, 'order').'. '.data_get($block, 'block').' ['.data_get($block, 'risk').']');
            }
        }

        return self::SUCCESS;
    }
}
