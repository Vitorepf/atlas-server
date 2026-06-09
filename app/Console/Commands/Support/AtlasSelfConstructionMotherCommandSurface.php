<?php

declare(strict_types=1);

namespace App\Console\Commands\Support;

final class AtlasSelfConstructionMotherCommandSurface
{
    /**
     * @param  array<string, string>  $base
     * @return array<string, string>
     */
    public static function mergeFlagMethods(array $base): array
    {
        return self::insertAssociativeAfter($base, 'assignment-preview', self::flagMethods());
    }

    /**
     * @param  list<string>  $base
     * @return list<string>
     */
    public static function mergeValueOptions(array $base): array
    {
        return self::insertListAfter($base, 'evidence-hash', self::valueOptions());
    }

    /**
     * @param  list<string>  $base
     * @return list<string>
     */
    public static function mergeBooleanOptions(array $base): array
    {
        return array_values(array_unique(array_merge($base, self::booleanOptions())));
    }

    /**
     * @param  array<string, list<string>>  $base
     * @return array<string, list<string>>
     */
    public static function mergeHumanSignals(array $base): array
    {
        return array_merge($base, self::humanSignals());
    }

    /**
     * @return array<string, string>
     */
    public static function flagMethods(): array
    {
        return [
            'atlas-self-construction-final-operator-evidence-closure-corridor-status' => 'atlasSelfConstructionFinalOperatorEvidenceClosureCorridorStatus',
            'atlas-self-construction-operator-evidence-submission-readiness-contract' => 'atlasSelfConstructionOperatorEvidenceSubmissionReadinessContract',
            'atlas-self-construction-operator-evidence-submission-readiness-preflight' => 'atlasSelfConstructionOperatorEvidenceSubmissionReadinessPreflight',
            'atlas-self-construction-operator-evidence-submission-readiness-implementation-packet' => 'atlasSelfConstructionOperatorEvidenceSubmissionReadinessImplementationPacket',
            'atlas-self-construction-operator-evidence-submission-readiness-status' => 'atlasSelfConstructionOperatorEvidenceSubmissionReadinessStatus',
            'atlas-self-construction-runtime-gap-matrix' => 'atlasSelfConstructionRuntimeGapMatrix',
            'atlas-self-construction-os-completion-audit-contract' => 'atlasSelfConstructionOsCompletionAuditContract',
            'atlas-self-construction-os-completion-audit-preflight' => 'atlasSelfConstructionOsCompletionAuditPreflight',
            'atlas-self-construction-os-completion-audit-implementation-packet' => 'atlasSelfConstructionOsCompletionAuditImplementationPacket',
            'atlas-self-construction-os-completion-audit-status' => 'atlasSelfConstructionOsCompletionAuditStatus',
            'atlas-self-construction-os-completion-evidence-status' => 'atlasSelfConstructionOsCompletionEvidenceStatus',
            'atlas-self-construction-os-completion-operator-action-packet-contract' => 'atlasSelfConstructionOsCompletionOperatorActionPacketContract',
            'atlas-self-construction-os-completion-operator-action-packet-preflight' => 'atlasSelfConstructionOsCompletionOperatorActionPacketPreflight',
            'atlas-self-construction-os-completion-operator-action-packet-implementation-packet' => 'atlasSelfConstructionOsCompletionOperatorActionPacketImplementationPacket',
            'atlas-self-construction-os-completion-operator-action-packet-status' => 'atlasSelfConstructionOsCompletionOperatorActionPacketStatus',
            'atlas-self-construction-runtime-promotion-receipt-draft-status' => 'atlasSelfConstructionRuntimePromotionReceiptDraftStatus',
            'atlas-self-construction-runtime-promotion-draft-hash-finalizer-status' => 'atlasSelfConstructionRuntimePromotionDraftHashFinalizerStatus',
            'atlas-self-construction-operator-evidence-draft-hash-finalizer-status' => 'atlasSelfConstructionOperatorEvidenceDraftHashFinalizerStatus',
            'atlas-self-construction-operator-evidence-draft-workspace-publisher-status' => 'atlasSelfConstructionOperatorEvidenceDraftWorkspacePublisherStatus',
            'atlas-self-construction-human-completion-receipt-draft-status' => 'atlasSelfConstructionHumanCompletionReceiptDraftStatus',
            'atlas-self-construction-real-provider-smoke-draft-status' => 'atlasSelfConstructionRealProviderSmokeDraftStatus',
            'atlas-self-construction-operator-evidence-artifact-template-pack-status' => 'atlasSelfConstructionOperatorEvidenceArtifactTemplatePackStatus',
            'atlas-self-construction-human-completion-receipt-runbook-contract' => 'atlasSelfConstructionHumanCompletionReceiptRunbookContract',
            'atlas-self-construction-human-completion-receipt-runbook-preflight' => 'atlasSelfConstructionHumanCompletionReceiptRunbookPreflight',
            'atlas-self-construction-human-completion-receipt-runbook-implementation-packet' => 'atlasSelfConstructionHumanCompletionReceiptRunbookImplementationPacket',
            'atlas-self-construction-human-completion-receipt-runbook-status' => 'atlasSelfConstructionHumanCompletionReceiptRunbookStatus',
            'atlas-self-construction-human-completion-receipt-closure-execution-pack-status' => 'atlasSelfConstructionHumanCompletionReceiptClosureExecutionPackStatus',
            'atlas-self-construction-runtime-promotion-receipt-runbook-contract' => 'atlasSelfConstructionRuntimePromotionReceiptRunbookContract',
            'atlas-self-construction-runtime-promotion-receipt-runbook-preflight' => 'atlasSelfConstructionRuntimePromotionReceiptRunbookPreflight',
            'atlas-self-construction-runtime-promotion-receipt-runbook-implementation-packet' => 'atlasSelfConstructionRuntimePromotionReceiptRunbookImplementationPacket',
            'atlas-self-construction-runtime-promotion-receipt-runbook-status' => 'atlasSelfConstructionRuntimePromotionReceiptRunbookStatus',
            'atlas-self-construction-runtime-promotion-endgame-status' => 'atlasSelfConstructionRuntimePromotionEndgameStatus',
            'atlas-self-construction-real-provider-smoke-runbook-contract' => 'atlasSelfConstructionRealProviderSmokeRunbookContract',
            'atlas-self-construction-real-provider-smoke-runbook-preflight' => 'atlasSelfConstructionRealProviderSmokeRunbookPreflight',
            'atlas-self-construction-real-provider-smoke-runbook-implementation-packet' => 'atlasSelfConstructionRealProviderSmokeRunbookImplementationPacket',
            'atlas-self-construction-real-provider-smoke-runbook-status' => 'atlasSelfConstructionRealProviderSmokeRunbookStatus',
            'atlas-self-construction-real-provider-smoke-endgame-status' => 'atlasSelfConstructionRealProviderSmokeEndgameStatus',
            'atlas-self-construction-completion-finalization-gate-status' => 'atlasSelfConstructionCompletionFinalizationGateStatus',
            'atlas-self-construction-os-handoff-contract' => 'atlasSelfConstructionOsHandoffContract',
            'atlas-self-construction-os-handoff-preflight' => 'atlasSelfConstructionOsHandoffPreflight',
            'atlas-self-construction-os-handoff-implementation-packet' => 'atlasSelfConstructionOsHandoffImplementationPacket',
            'atlas-self-construction-os-handoff-status' => 'atlasSelfConstructionOsHandoffStatus',
            'agent-control-plane-task-auto-replenishment-contract' => 'agentControlPlaneTaskAutoReplenishmentContract',
            'agent-control-plane-task-auto-replenishment-preflight' => 'agentControlPlaneTaskAutoReplenishmentPreflight',
            'agent-control-plane-task-auto-replenishment-implementation-packet' => 'agentControlPlaneTaskAutoReplenishmentImplementationPacket',
            'agent-control-plane-task-auto-replenishment-status' => 'agentControlPlaneTaskAutoReplenishmentStatus',
            'agent-control-plane-worker-task-eligibility-certification-contract' => 'agentControlPlaneWorkerTaskEligibilityCertificationContract',
            'agent-control-plane-worker-task-eligibility-certification-preflight' => 'agentControlPlaneWorkerTaskEligibilityCertificationPreflight',
            'agent-control-plane-worker-task-eligibility-certification-implementation-packet' => 'agentControlPlaneWorkerTaskEligibilityCertificationImplementationPacket',
            'agent-control-plane-worker-task-eligibility-certification-status' => 'agentControlPlaneWorkerTaskEligibilityCertificationStatus',
            'agent-control-plane-task-queue-claim-next-status' => 'agentControlPlaneTaskQueueClaimNextStatus',
            'agent-control-plane-task-queue-complete-dry-run-status' => 'agentControlPlaneTaskQueueCompleteDryRunStatus',
            'agent-control-plane-one-shot-worker-packet-contract' => 'agentControlPlaneOneShotWorkerPacketContract',
            'agent-control-plane-one-shot-worker-packet-preflight' => 'agentControlPlaneOneShotWorkerPacketPreflight',
            'agent-control-plane-one-shot-worker-packet-implementation-packet' => 'agentControlPlaneOneShotWorkerPacketImplementationPacket',
            'agent-control-plane-one-shot-worker-packet-status' => 'agentControlPlaneOneShotWorkerPacketStatus',
            'agent-control-plane-terminal-worker-bootstrap-contract' => 'agentControlPlaneTerminalWorkerBootstrapContract',
            'agent-control-plane-terminal-worker-bootstrap-preflight' => 'agentControlPlaneTerminalWorkerBootstrapPreflight',
            'agent-control-plane-terminal-worker-bootstrap-implementation-packet' => 'agentControlPlaneTerminalWorkerBootstrapImplementationPacket',
            'agent-control-plane-terminal-worker-bootstrap-status' => 'agentControlPlaneTerminalWorkerBootstrapStatus',
            'agent-control-plane-terminal-loop-health-digest-contract' => 'agentControlPlaneTerminalLoopHealthDigestContract',
            'agent-control-plane-terminal-loop-health-digest-preflight' => 'agentControlPlaneTerminalLoopHealthDigestPreflight',
            'agent-control-plane-terminal-loop-health-digest-implementation-packet' => 'agentControlPlaneTerminalLoopHealthDigestImplementationPacket',
            'agent-control-plane-terminal-loop-health-digest-status' => 'agentControlPlaneTerminalLoopHealthDigestStatus',
            'agent-control-plane-task-lease-recovery-contract' => 'agentControlPlaneTaskLeaseRecoveryContract',
            'agent-control-plane-task-lease-recovery-preflight' => 'agentControlPlaneTaskLeaseRecoveryPreflight',
            'agent-control-plane-task-lease-recovery-implementation-packet' => 'agentControlPlaneTaskLeaseRecoveryImplementationPacket',
            'agent-control-plane-task-lease-recovery-status' => 'agentControlPlaneTaskLeaseRecoveryStatus',
            'agent-control-plane-multi-agent-loop-certification-contract' => 'agentControlPlaneMultiAgentLoopCertificationContract',
            'agent-control-plane-multi-agent-loop-certification-preflight' => 'agentControlPlaneMultiAgentLoopCertificationPreflight',
            'agent-control-plane-multi-agent-loop-certification-implementation-packet' => 'agentControlPlaneMultiAgentLoopCertificationImplementationPacket',
            'agent-control-plane-multi-agent-loop-certification-status' => 'agentControlPlaneMultiAgentLoopCertificationStatus',
            'agent-control-plane-terminal-loop-operational-proof-status' => 'agentControlPlaneTerminalLoopOperationalProofStatus',
        ];
    }

    /**
     * @return list<string>
     */
    public static function valueOptions(): array
    {
        return [
            'operator-draft-workspace-path',
            'runtime-promotion-receipt-json',
            'real-provider-smoke-json',
            'completion-receipt-json',
            'agent-control-plane-terminal-loop-operational-proof-json',
            'completion-evidence-json',
            'proof-id',
            'lease-id',
            'task-packet-id',
            'mode',
            'decision',
            'dispatch-envelope-hash',
            'adapter-contract-hash',
            'expires-at',
            'scenario-id',
            'before-snapshot-id',
            'after-snapshot-id',
            'iteration-count',
            'lease-minutes',
            'target-min-claimable-tasks',
            'max-new-tasks',
            'agent-count',
            'cycles',
        ];
    }

    /**
     * @return list<string>
     */
    public static function booleanOptions(): array
    {
        return [
            'persist-runtime-promotion-receipt',
            'persist-completion-evidence',
            'persist-operator-draft-workspace',
            'persist-export',
            'persist-terminal-loop-operational-proof-binding',
            'write-computed-runtime-promotion-receipt-hash',
            'write-computed-operator-draft-hashes',
            'publish-operator-draft-workspace',
            'terminal-worker-bootstrap-preview',
            'dry-run-only',
            'simulate-overlap',
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function humanSignals(): array
    {
        return [
            'atlasSelfConstructionFinalOperatorEvidenceClosureCorridorStatus' => ['--atlas-self-construction-runtime-promotion-receipt-draft-status', 'Atlas Self-Construction OS', 'Can run automatically', 'Exact command', 'Next action step', 'Next required submission', 'requires_operator_signature_and_runtime_promotion_judgment'],
            'atlasSelfConstructionOperatorEvidenceSubmissionReadinessStatus' => ['--atlas-self-construction-runtime-promotion-receipt-draft-status', 'Atlas Self-Construction OS', 'Can persist from readiness', 'Exact command', 'Next action artifact', 'Next action source', 'Next required', 'requires_operator_signature_and_runtime_promotion_judgment', 'Proof bundle', 'Missing operator proofs:', 'Closure runbook', 'Runbook current step', 'Command surface', 'Missing CLI options', 'Closure artifact sequence:', 'Terminal proof guardrails:', 'Prompt-to-artifact checklist:', 'Ready for final audit', 'Terminal proof ready'],
            'atlasSelfConstructionOsCompletionAuditStatus' => ['Failed criteria:', 'Human blockers:', 'Real provider blockers:', 'Technical blockers:', 'Current required artifact', 'Operator readiness command', '--atlas-self-construction-operator-evidence-submission-readiness-status', 'Closure corridor command', '--atlas-self-construction-final-operator-evidence-closure-corridor-status', 'Completion allowed'],
            'atlasSelfConstructionOsCompletionEvidenceStatus' => ['Failed checks', 'Human blockers', 'Real provider blockers', 'Technical blockers', 'Current required artifact'],
            'atlasSelfConstructionOsCompletionOperatorActionPacketStatus' => ['Current required artifact', 'Missing operator artifacts:', 'Human blockers', 'Real provider blockers', 'Technical blockers', 'Closure artifact sequence:', 'Prompt-to-artifact checklist:', 'Operator command plan:', 'Terminal proof required'],
            'atlasSelfConstructionOsHandoffStatus' => ['Canonical final blockers', 'Human blockers', 'Real provider blockers', 'Technical blockers', 'Release dossier snapshot', 'Snapshot refresh required', 'Release dossier green', 'Chain integrity status', 'Chain pointer aligned', 'Control plane next slice', 'Expected next slice', 'Runtime gap count', 'Batch command', 'Preflight command', 'Resume command count', 'Post-evidence guardrails', 'Operator resume sequence:', 'Post-evidence guardrail sequence:', '[required]', '[optional]', 'Current required artifact', 'Terminal loop proof passed', 'Self-programming allowed'],
        ];
    }

    /**
     * @param  array<string, string>  $base
     * @param  array<string, string>  $insert
     * @return array<string, string>
     */
    private static function insertAssociativeAfter(array $base, string $marker, array $insert): array
    {
        $merged = [];
        $inserted = false;

        foreach ($base as $key => $value) {
            $merged[$key] = $value;

            if ($key !== $marker) {
                continue;
            }

            $merged = array_merge($merged, $insert);
            $inserted = true;
        }

        if (! $inserted) {
            $merged = array_merge($merged, $insert);
        }

        return $merged;
    }

    /**
     * @param  list<string>  $base
     * @param  list<string>  $insert
     * @return list<string>
     */
    private static function insertListAfter(array $base, string $marker, array $insert): array
    {
        $merged = [];
        $inserted = false;

        foreach ($base as $value) {
            $merged[] = $value;

            if ($value !== $marker) {
                continue;
            }

            foreach ($insert as $insertedValue) {
                $merged[] = $insertedValue;
            }

            $inserted = true;
        }

        if (! $inserted) {
            foreach ($insert as $insertedValue) {
                $merged[] = $insertedValue;
            }
        }

        return array_values(array_unique($merged));
    }
}
