<?php

namespace App\Services\Ai\Kernel\Repair;

use App\Services\Ai\Kernel\Failure\FailureDomain;

final class AtlasRepairOrchestrator
{
    public const CONTRACT_VERSION = 1;

    public function __construct(
        private readonly ?RepairStrategyResolver $strategyResolver = null,
        private readonly ?RepairEvidencePayloadFormatter $evidencePayloadFormatter = null,
    ) {}

    public function plan(RepairRequest $request): RepairDecision
    {
        $contractErrors = $this->contractErrors($request);

        if ($contractErrors !== []) {
            return $this->decision(
                request: $request,
                status: RepairDecisionStatus::RepairBlocked,
                strategy: null,
                reasons: $contractErrors,
            );
        }

        if (! $request->policy->enabled) {
            return $this->decision(
                request: $request,
                status: RepairDecisionStatus::RepairBlocked,
                strategy: null,
                reasons: [RepairReason::RepairPolicyDisabled->value],
            );
        }

        if ($request->currentAttempt >= $request->policy->maxAttempts) {
            return $this->decision(
                request: $request,
                status: RepairDecisionStatus::RepairExhausted,
                strategy: null,
                reasons: [RepairReason::MaxAttemptsReached->value],
            );
        }

        if ($this->resolver()->requiresHumanReview($request->failure->domain)) {
            return $this->decision(
                request: $request,
                status: RepairDecisionStatus::NeedsHumanReview,
                strategy: RepairStrategy::HumanReview->value,
                reasons: [RepairReason::FailureDomainRequiresHumanReview->value],
            );
        }

        $strategy = $this->strategyFor($request->failure->domain);

        if ($strategy === RepairStrategy::HumanReview->value) {
            return $this->decision(
                request: $request,
                status: RepairDecisionStatus::NeedsHumanReview,
                strategy: $strategy,
                reasons: [RepairReason::NoAutomaticStrategyForFailureDomain->value],
            );
        }

        if (! $request->policy->allows($strategy)) {
            return $this->decision(
                request: $request,
                status: RepairDecisionStatus::RepairBlocked,
                strategy: $strategy,
                reasons: [RepairReason::StrategyNotAllowed->value],
            );
        }

        if (
            $request->policy->requiresEvidenceForHeavyRepair
            && $request->policy->isHeavy($strategy)
            && $request->evidenceRefs === []
        ) {
            return $this->decision(
                request: $request,
                status: RepairDecisionStatus::RepairBlocked,
                strategy: $strategy,
                reasons: [RepairReason::HeavyRepairRequiresEvidenceRefs->value],
            );
        }

        return $this->decision(
            request: $request,
            status: RepairDecisionStatus::RepairAllowed,
            strategy: $strategy,
            reasons: [RepairReason::RepairPlanned->value],
        );
    }

    public function attempt(RepairRequest $request): RepairResult
    {
        $decision = $this->plan($request);

        if (! $decision->allowsRepair()) {
            return new RepairResult(
                request: $request,
                decision: $decision,
                attempt: null,
                executed: false,
                evidencePayload: $this->resultEvidencePayload($request, $decision, null, [RepairReason::RepairNotAllowed->value]),
            );
        }

        $reasons = $request->dryRun
            ? [RepairReason::ExecutionBlockedByDryRun->value]
            : [RepairReason::ExecutionNotImplementedContractFoundationOnly->value];

        $attempt = new RepairAttempt(
            attemptNumber: $decision->nextAttempt,
            strategy: $decision->strategy,
            executed: false,
            dryRun: $request->dryRun,
            evidenceRefs: $request->evidenceRefs,
            reasons: $reasons,
            metadata: [
                'contract_foundation_only' => true,
                'delegates_to_future_repair_executor' => true,
            ],
        );

        return new RepairResult(
            request: $request,
            decision: $decision,
            attempt: $attempt,
            executed: false,
            evidencePayload: $this->resultEvidencePayload($request, $decision, $attempt, $reasons),
        );
    }

    public function strategyFor(FailureDomain $domain): string
    {
        return $this->resolver()->strategyFor($domain);
    }

    /**
     * @return array{ok:bool,contract_version:int,decisions:array<int,string>,strategies:array<int,string>,reasons:array<int,string>,execution_enabled:bool,errors:array<int,string>}
     */
    public function complianceReport(): array
    {
        $errors = [];
        $decisions = array_map(
            fn (RepairDecisionStatus $status): string => $status->value,
            RepairDecisionStatus::cases(),
        );

        foreach (['repair_allowed', 'repair_blocked', 'repair_exhausted', 'needs_human_review'] as $requiredDecision) {
            if (! in_array($requiredDecision, $decisions, true)) {
                $errors[] = "missing decision status [{$requiredDecision}]";
            }
        }

        foreach (FailureDomain::cases() as $domain) {
            if (! in_array($this->strategyFor($domain), RepairStrategy::values(), true)) {
                $errors[] = "unknown repair strategy for failure domain [{$domain->value}]";
            }
        }

        $resolverReport = $this->resolver()->complianceReport();
        foreach ($resolverReport['errors'] as $error) {
            $errors[] = $error;
        }

        return [
            'ok' => $errors === [],
            'contract_version' => self::CONTRACT_VERSION,
            'decisions' => $decisions,
            'strategies' => RepairStrategy::values(),
            'reasons' => RepairReason::values(),
            'human_review_domains' => $resolverReport['human_review_domains'],
            'ledger_event_contract' => [
                'LedgerEventType::RepairInitiated',
                'LedgerEventType::RepairCompleted',
            ],
            'execution_enabled' => false,
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<int,string>  $reasons
     */
    private function decision(RepairRequest $request, RepairDecisionStatus $status, ?string $strategy, array $reasons): RepairDecision
    {
        return new RepairDecision(
            status: $status,
            strategy: $strategy,
            nextAttempt: $request->currentAttempt + 1,
            reasons: $reasons,
            evidencePayload: $this->evidenceFormatter()->decisionPayload($request, $status, $strategy, $reasons),
            metadata: [
                'contract_foundation_only' => true,
                'executes_repair' => false,
            ],
        );
    }

    /**
     * @param  array<int,string>  $reasons
     * @return array<string,mixed>
     */
    private function resultEvidencePayload(RepairRequest $request, RepairDecision $decision, ?RepairAttempt $attempt, array $reasons): array
    {
        return $this->evidenceFormatter()->resultPayload($request, $decision, $attempt, $reasons);
    }

    private function resolver(): RepairStrategyResolver
    {
        return $this->strategyResolver ?? new RepairStrategyResolver;
    }

    private function evidenceFormatter(): RepairEvidencePayloadFormatter
    {
        return $this->evidencePayloadFormatter ?? new RepairEvidencePayloadFormatter;
    }

    /**
     * @return array<int,string>
     */
    private function contractErrors(RepairRequest $request): array
    {
        $errors = [];

        if (trim($request->envelopeId) === '') {
            $errors[] = RepairReason::EnvelopeIdRequired->value;
        }

        if ($request->currentAttempt < 0) {
            $errors[] = RepairReason::CurrentAttemptMustBeNonNegative->value;
        }

        if ($request->policy->allowedStrategies === []) {
            $errors[] = RepairReason::AllowedStrategiesRequired->value;
        }

        return $errors;
    }
}
