<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Gate;

use App\Services\Ai\Programming\AtlasDev\Provider\DiffParseResult;
use App\Services\Ai\Programming\AtlasDev\Provider\ProviderCallResult;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\CostSummary;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\EscalationSummary;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\EvidenceRef;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\GateOutcome;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\RepairSummary;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\ProviderPromptProjection;
use App\Services\Ai\Programming\AtlasDev\Schemas\ScopeGuardReceipt;
use App\Services\Ai\Programming\AtlasDev\Schemas\VerificationReceipt;
use InvalidArgumentException;

/**
 * Composes the final VerificationReceipt from all upstream artifacts produced
 * by the one-call slice. Pure: no I/O, no clock. Provided file_hashes /
 * extra evidence_refs come from upstream persistence; we honour them as-is.
 */
final class ReceiptComposer
{
    /**
     * @param  array<string, string>  $fileHashes  optional precomputed file_hashes
     * @param  list<EvidenceRef>      $extraEvidenceRefs  extra refs (e.g. diff artifact)
     */
    public function compose(
        OperationEnvelope $envelope,
        LightTaskContract $taskContract,
        ProviderPromptProjection $promptProjection,
        ProviderCallResult $callResult,
        DiffParseResult $diffResult,
        ScopeGuardReceipt $scopeReceipt,
        VerificationGateResult $verificationResult,
        CompletionDecision $completion,
        string $contextPackHash,
        string $taskKind,
        string $riskLevel,
        array $fileHashes = [],
        array $extraEvidenceRefs = [],
        ?string $modelLabel = null,
    ): VerificationReceipt {
        if ($contextPackHash === '') {
            throw new InvalidArgumentException('ReceiptComposer.contextPackHash must not be empty.');
        }

        $promptHash = $promptProjection->promptProjectionHash !== ''
            ? $promptProjection->promptProjectionHash
            : $promptProjection->hash();

        $scopeReceiptHash = $scopeReceipt->receiptHash !== ''
            ? $scopeReceipt->receiptHash
            : $scopeReceipt->hash();

        $diffHash = $diffResult->diffHash();

        $changedFiles = $diffResult->hasPatch() ? $diffResult->changedFiles : [];

        $gates = $this->mergeGates($scopeReceipt, $verificationResult);

        $evidenceRefs = $this->mergeEvidence(
            verification: $verificationResult,
            diffResult: $diffResult,
            extras: $extraEvidenceRefs,
        );

        $cost = new CostSummary(
            providerCalls: 1,
            tokensIn: $callResult->tokensIn,
            tokensOut: $callResult->tokensOut,
            estimatedCostUsd: $callResult->costEstimateUsd,
            wallTimeMs: $callResult->durationMs,
        );

        $repair = new RepairSummary(
            attemptCount: 0,
            failureCapsuleRefs: [],
            convertedToGreen: false,
        );

        $escalation = new EscalationSummary(
            recommended: false,
            target: null,
            reasons: [],
            decisionRef: null,
        );

        return VerificationReceipt::issue(
            runId: $envelope->runId,
            taskContractHash: $taskContract->taskContractHash !== ''
                ? $taskContract->taskContractHash
                : $taskContract->hash(),
            workspaceHash: $envelope->workspaceHash,
            taskKind: $taskKind,
            riskLevel: $riskLevel,
            provider: $callResult->actualProvider,
            model: $modelLabel ?? $callResult->actualModelFamily,
            contextPackHash: $contextPackHash,
            promptProjectionHash: $promptHash,
            scopeGuardReceiptHash: $scopeReceiptHash,
            diffHash: $diffHash,
            changedFiles: $changedFiles,
            fileHashes: $fileHashes,
            evidenceRefs: $evidenceRefs,
            gates: $gates,
            tests: $verificationResult->tests,
            repair: $repair,
            cost: $cost,
            completion: $completion->toCompletionSummary(),
            escalation: $escalation,
        );
    }

    /**
     * @return list<GateOutcome>
     */
    private function mergeGates(ScopeGuardReceipt $scope, VerificationGateResult $verification): array
    {
        $gates = [];

        $gates[] = new GateOutcome(
            name: 'scope_guard_light',
            status: $this->scopeStatusAsGate($scope->status),
            required: true,
            evidenceRef: 'scope_guard_receipt:'.($scope->receiptHash !== '' ? $scope->receiptHash : $scope->hash()),
            fresh: true,
            waiverReason: null,
        );

        foreach ($verification->gates as $gate) {
            $gates[] = $gate;
        }

        return $gates;
    }

    private function scopeStatusAsGate(string $scopeStatus): string
    {
        return match ($scopeStatus) {
            ScopeGuardReceipt::STATUS_PASSED => GateOutcome::STATUS_PASSED,
            ScopeGuardReceipt::STATUS_FAILED => GateOutcome::STATUS_FAILED,
            ScopeGuardReceipt::STATUS_NEEDS_REVIEW => GateOutcome::STATUS_NEEDS_REVIEW,
            default => GateOutcome::STATUS_NEEDS_REVIEW,
        };
    }

    /**
     * @param  list<EvidenceRef>  $extras
     * @return list<EvidenceRef>
     */
    private function mergeEvidence(
        VerificationGateResult $verification,
        DiffParseResult $diffResult,
        array $extras,
    ): array {
        $refs = [];

        if ($diffResult->isNoPatchNeeded() && $diffResult->reason !== null) {
            $reason = (string) $diffResult->reason;
            $refs[] = new EvidenceRef(
                kind: 'no_patch_reason',
                path: 'inline://no_patch_reason',
                hash: hash('sha256', $reason),
            );
        }

        foreach ($verification->evidenceRefs as $ref) {
            $refs[] = $ref;
        }

        foreach ($extras as $ref) {
            if (! $ref instanceof EvidenceRef) {
                throw new InvalidArgumentException('ReceiptComposer.extraEvidenceRefs must contain EvidenceRef instances.');
            }
            $refs[] = $ref;
        }

        return $refs;
    }
}
