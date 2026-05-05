<?php

namespace Tests\Unit\Ai\Kernel\Repair;

use App\Services\Ai\Kernel\Decision\DecisionRepairPolicy;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Kernel\Failure\FailureClassification;
use App\Services\Ai\Kernel\Failure\FailureDomain;
use App\Services\Ai\Kernel\Repair\AtlasRepairOrchestrator;
use App\Services\Ai\Kernel\Repair\RepairDecisionStatus;
use App\Services\Ai\Kernel\Repair\RepairEvidencePayloadFormatter;
use App\Services\Ai\Kernel\Repair\RepairPolicy;
use App\Services\Ai\Kernel\Repair\RepairReason;
use App\Services\Ai\Kernel\Repair\RepairRequest;
use App\Services\Ai\Kernel\Repair\RepairRequestFactory;
use App\Services\Ai\Kernel\Repair\RepairStrategy;
use App\Services\Ai\Kernel\Repair\RepairStrategyResolver;
use Tests\TestCase;

class AtlasRepairOrchestratorTest extends TestCase
{
    public function test_repair_blocks_when_max_attempts_reached(): void
    {
        $decision = (new AtlasRepairOrchestrator)->plan($this->request(
            domain: FailureDomain::ProviderTimeout,
            maxAttempts: 2,
            currentAttempt: 2,
        ));

        $this->assertSame(RepairDecisionStatus::RepairExhausted, $decision->status);
        $this->assertContains(RepairReason::MaxAttemptsReached->value, $decision->reasons);
    }

    public function test_repair_dry_run_blocks_real_execution(): void
    {
        $result = (new AtlasRepairOrchestrator)->attempt($this->request(
            domain: FailureDomain::ProviderTimeout,
            dryRun: true,
        ));

        $this->assertFalse($result->executed);
        $this->assertNotNull($result->attempt);
        $this->assertFalse($result->attempt->executed);
        $this->assertContains(RepairReason::ExecutionBlockedByDryRun->value, $result->attempt->reasons);
        $this->assertFalse($result->evidencePayload['repair_executed']);
    }

    public function test_repair_chooses_strategy_by_failure_domain(): void
    {
        $orchestrator = new AtlasRepairOrchestrator;

        $this->assertSame(RepairStrategy::RetryProvider->value, $orchestrator->strategyFor(FailureDomain::ProviderTimeout));
        $this->assertSame(RepairStrategy::RefreshContext->value, $orchestrator->strategyFor(FailureDomain::ContextPackFailed));
        $this->assertSame(RepairStrategy::CollectEvidence->value, $orchestrator->strategyFor(FailureDomain::GateFailed));
        $this->assertSame(RepairStrategy::RerunHarness->value, $orchestrator->strategyFor(FailureDomain::HarnessFailed));
    }

    public function test_repair_heavy_strategy_requires_evidence_refs(): void
    {
        $decision = (new AtlasRepairOrchestrator)->plan($this->request(
            domain: FailureDomain::HarnessFailed,
            evidenceRefs: [],
            allowedStrategies: [RepairStrategy::RerunHarness->value],
        ));

        $this->assertSame(RepairDecisionStatus::RepairBlocked, $decision->status);
        $this->assertSame(RepairStrategy::RerunHarness->value, $decision->strategy);
        $this->assertContains(RepairReason::HeavyRepairRequiresEvidenceRefs->value, $decision->reasons);
    }

    public function test_repair_blocks_malformed_contract_request(): void
    {
        $decision = (new AtlasRepairOrchestrator)->plan(new RepairRequest(
            envelopeId: '',
            receiptId: null,
            failure: new FailureClassification(
                domain: FailureDomain::ProviderTimeout,
                source: 'unit_test',
            ),
            policy: new RepairPolicy(
                enabled: true,
                maxAttempts: 3,
                allowedStrategies: [],
            ),
            currentAttempt: 0,
        ));

        $this->assertSame(RepairDecisionStatus::RepairBlocked, $decision->status);
        $this->assertContains(RepairReason::EnvelopeIdRequired->value, $decision->reasons);
        $this->assertContains(RepairReason::AllowedStrategiesRequired->value, $decision->reasons);
    }

    public function test_repair_blocks_negative_current_attempt_from_payload(): void
    {
        $request = RepairRequest::fromArray([
            'envelope_id' => 'env_negative_payload',
            'failure_classification' => [
                'failure_domain' => FailureDomain::ProviderTimeout->value,
            ],
            'policy' => [
                'enabled' => true,
                'max_attempts' => 2,
                'allowed_strategies' => [RepairStrategy::RetryProvider->value],
            ],
            'current_attempt' => -1,
        ]);

        $decision = (new AtlasRepairOrchestrator)->plan($request);

        $this->assertSame(-1, $request->currentAttempt);
        $this->assertSame(RepairDecisionStatus::RepairBlocked, $decision->status);
        $this->assertContains(RepairReason::CurrentAttemptMustBeNonNegative->value, $decision->reasons);
    }

    public function test_repair_factory_preserves_negative_current_attempt_for_contract_validation(): void
    {
        $request = (new RepairRequestFactory)->fromKernelContext(
            envelopeId: 'env_negative_factory',
            receiptId: null,
            failure: new FailureClassification(
                domain: FailureDomain::ProviderTimeout,
                source: 'factory_test',
            ),
            policy: [
                'enabled' => true,
                'max_attempts' => 2,
                'allowed_strategies' => [RepairStrategy::RetryProvider->value],
            ],
            currentAttempt: -2,
        );

        $decision = (new AtlasRepairOrchestrator)->plan($request);

        $this->assertSame(-2, $request->currentAttempt);
        $this->assertSame(RepairDecisionStatus::RepairBlocked, $decision->status);
        $this->assertContains(RepairReason::CurrentAttemptMustBeNonNegative->value, $decision->reasons);
    }

    public function test_repair_human_review_required_for_compliance_domain(): void
    {
        $decision = (new AtlasRepairOrchestrator)->plan($this->request(
            domain: FailureDomain::ComplianceViolation,
        ));

        $this->assertSame(RepairDecisionStatus::NeedsHumanReview, $decision->status);
        $this->assertTrue($decision->requiresHumanReview());
        $this->assertSame(RepairStrategy::HumanReview->value, $decision->strategy);
    }

    public function test_repair_compliance_report_covers_basic_contracts(): void
    {
        $report = (new AtlasRepairOrchestrator)->complianceReport();

        $this->assertTrue($report['ok'], implode("\n", $report['errors']));
        $this->assertFalse($report['execution_enabled']);
        $this->assertSame(1, $report['contract_version']);
        $this->assertContains('repair_allowed', $report['decisions']);
        $this->assertContains('needs_human_review', $report['decisions']);
        $this->assertContains(RepairStrategy::RerunHarness->value, $report['strategies']);
        $this->assertContains(RepairReason::RepairPlanned->value, $report['reasons']);
    }

    public function test_repair_decision_and_attempt_reasons_are_closed_vocabulary(): void
    {
        $orchestrator = new AtlasRepairOrchestrator;
        $decisions = [
            $orchestrator->plan($this->request(domain: FailureDomain::ProviderTimeout)),
            $orchestrator->plan($this->request(domain: FailureDomain::HarnessFailed, evidenceRefs: [])),
            $orchestrator->plan($this->request(domain: FailureDomain::ComplianceViolation)),
            $orchestrator->plan($this->request(domain: FailureDomain::ProviderTimeout, currentAttempt: 3, maxAttempts: 3)),
        ];

        foreach ($decisions as $decision) {
            foreach ($decision->reasons as $reason) {
                $this->assertContains($reason, RepairReason::values());
            }
        }

        $attempt = $orchestrator->attempt($this->request(domain: FailureDomain::ProviderTimeout, dryRun: true))->attempt;
        $this->assertNotNull($attempt);

        foreach ($attempt->reasons as $reason) {
            $this->assertContains($reason, RepairReason::values());
        }
    }

    public function test_repair_evidence_payload_is_ledger_ready_and_hashable(): void
    {
        $orchestrator = new AtlasRepairOrchestrator;
        $request = $this->request(domain: FailureDomain::ProviderTimeout);

        $first = $orchestrator->attempt($request);
        $second = $orchestrator->attempt($request);

        $this->assertSame(LedgerEventType::RepairInitiated->value, $first->decision->evidencePayload['event_type']);
        $this->assertSame(LedgerEventType::RepairCompleted->value, $first->evidencePayload['event_type']);
        $this->assertNotEmpty($first->decision->evidencePayload['decision_hash']);
        $this->assertNotEmpty($first->evidencePayload['result_hash']);
        $this->assertSame($first->decision->evidencePayload['decision_hash'], $second->decision->evidencePayload['decision_hash']);
        $this->assertSame($first->evidencePayload['result_hash'], $second->evidencePayload['result_hash']);
    }

    public function test_repair_evidence_formatter_creates_ledger_ready_decision_payload(): void
    {
        $formatter = new RepairEvidencePayloadFormatter;
        $payload = $formatter->decisionPayload(
            request: $this->request(domain: FailureDomain::ProviderTimeout),
            status: RepairDecisionStatus::RepairAllowed,
            strategy: RepairStrategy::RetryProvider->value,
            reasons: [RepairReason::RepairPlanned->value],
        );

        $this->assertSame(LedgerEventType::RepairInitiated->value, $payload['event_type']);
        $this->assertSame(AtlasRepairOrchestrator::CONTRACT_VERSION, $payload['schema_version']);
        $this->assertSame('env_123', $payload['envelope_id']);
        $this->assertSame(RepairDecisionStatus::RepairAllowed->value, $payload['decision']['status']);
        $this->assertSame(RepairStrategy::RetryProvider->value, $payload['decision']['strategy']);
        $this->assertFalse($payload['repair_executed']);
        $this->assertNotEmpty($payload['decision_hash']);
    }

    public function test_repair_hash_is_stable_for_equivalent_associative_metadata(): void
    {
        $formatter = new RepairEvidencePayloadFormatter;

        $first = $formatter->stableHash([
            'outer' => [
                'alpha' => 1,
                'beta' => 2,
            ],
            'list' => ['a', 'b'],
        ]);
        $second = $formatter->stableHash([
            'list' => ['a', 'b'],
            'outer' => [
                'beta' => 2,
                'alpha' => 1,
            ],
        ]);

        $this->assertSame($first, $second);
    }

    public function test_repair_hash_preserves_list_order(): void
    {
        $formatter = new RepairEvidencePayloadFormatter;

        $this->assertNotSame(
            $formatter->stableHash(['list' => ['a', 'b']]),
            $formatter->stableHash(['list' => ['b', 'a']]),
        );
    }

    public function test_repair_request_from_array_preserves_kernel_fields(): void
    {
        $request = RepairRequest::fromArray([
            'envelope_id' => 'env_from_array',
            'receipt_id' => 'receipt_from_array',
            'failure_classification' => [
                'failure_domain' => FailureDomain::GateFailed->value,
                'source' => 'gate',
                'signals' => ['gate_blocked'],
            ],
            'policy' => [
                'enabled' => true,
                'max_attempts' => 2,
                'allowed_strategies' => [RepairStrategy::CollectEvidence->value, 'unknown_strategy'],
            ],
            'current_attempt' => 1,
            'evidence_refs' => ['ledger:event:gate'],
        ]);

        $this->assertSame('env_from_array', $request->envelopeId);
        $this->assertSame('receipt_from_array', $request->receiptId);
        $this->assertSame(FailureDomain::GateFailed, $request->failure->domain);
        $this->assertSame([RepairStrategy::CollectEvidence->value], $request->policy->allowedStrategies);
        $this->assertTrue($request->dryRun);
    }

    public function test_repair_request_from_array_treats_malformed_failure_as_unknown(): void
    {
        $request = RepairRequest::fromArray([
            'envelope_id' => 'env_malformed_failure',
            'failure_classification' => 'not_an_array',
            'policy' => [
                'enabled' => true,
                'max_attempts' => 1,
                'allowed_strategies' => [RepairStrategy::RetryProvider->value],
            ],
        ]);

        $decision = (new AtlasRepairOrchestrator)->plan($request);

        $this->assertSame(FailureDomain::Unknown, $request->failure->domain);
        $this->assertSame(RepairDecisionStatus::NeedsHumanReview, $decision->status);
    }

    public function test_repair_request_from_array_normalizes_audit_strings_and_confidence(): void
    {
        $request = RepairRequest::fromArray([
            'envelope_id' => '  env_normalized  ',
            'receipt_id' => '  ',
            'failure_classification' => [
                'failure_domain' => FailureDomain::ProviderTimeout->value,
                'source' => '  ',
                'signals' => [' provider timeout ', '', 'retryable'],
                'confidence' => 3.2,
            ],
            'policy' => [
                'enabled' => true,
                'max_attempts' => 1,
                'allowed_strategies' => [RepairStrategy::RetryProvider->value],
            ],
        ]);

        $this->assertSame('env_normalized', $request->envelopeId);
        $this->assertNull($request->receiptId);
        $this->assertSame('repair_request', $request->failure->source);
        $this->assertSame(['provider timeout', 'retryable'], $request->failure->signals);
        $this->assertSame(1.0, $request->failure->confidence);
    }

    public function test_repair_request_and_policy_parse_boolean_strings_safely(): void
    {
        $request = RepairRequest::fromArray([
            'envelope_id' => 'env_boolean_strings',
            'failure_classification' => [
                'failure_domain' => FailureDomain::ProviderTimeout->value,
            ],
            'policy' => [
                'enabled' => 'false',
                'max_attempts' => 1,
                'allowed_strategies' => [RepairStrategy::RetryProvider->value],
                'requires_evidence_for_heavy_repair' => 'false',
            ],
            'dry_run' => 'false',
        ]);

        $decision = (new AtlasRepairOrchestrator)->plan($request);

        $this->assertFalse($request->policy->enabled);
        $this->assertFalse($request->policy->requiresEvidenceForHeavyRepair);
        $this->assertFalse($request->dryRun);
        $this->assertSame(RepairDecisionStatus::RepairBlocked, $decision->status);
        $this->assertContains(RepairReason::RepairPolicyDisabled->value, $decision->reasons);
    }

    public function test_repair_policy_filters_strategy_whitespace_duplicates_and_unknown_values(): void
    {
        $policy = RepairPolicy::fromArray([
            'enabled' => true,
            'max_attempts' => 1,
            'allowed_strategies' => [
                ' '.RepairStrategy::RetryProvider->value.' ',
                RepairStrategy::RetryProvider->value,
                'unknown_strategy',
            ],
        ]);

        $this->assertSame([RepairStrategy::RetryProvider->value], $policy->allowedStrategies);
    }

    public function test_repair_policy_unknown_enabled_string_fails_closed(): void
    {
        $policy = RepairPolicy::fromArray([
            'enabled' => 'definitely-not-a-boolean',
            'max_attempts' => 1,
            'allowed_strategies' => [RepairStrategy::RetryProvider->value],
        ]);

        $this->assertFalse($policy->enabled);
    }

    public function test_repair_policy_can_be_built_from_decision_repair_policy(): void
    {
        $policy = RepairPolicy::fromDecisionRepairPolicy(DecisionRepairPolicy::fromArray([
            'enabled' => true,
            'max_attempts' => 4,
            'allowed_strategies' => [
                RepairStrategy::RetryProvider->value,
                RepairStrategy::RefreshContext->value,
            ],
            'requires_evidence_for_heavy_repair' => false,
            'metadata' => [
                'source' => 'decision_receipt',
            ],
        ]));

        $this->assertTrue($policy->enabled);
        $this->assertSame(4, $policy->maxAttempts);
        $this->assertSame([
            RepairStrategy::RetryProvider->value,
            RepairStrategy::RefreshContext->value,
        ], $policy->allowedStrategies);
        $this->assertFalse($policy->requiresEvidenceForHeavyRepair);
        $this->assertSame('decision_receipt', $policy->metadata['source']);
    }

    public function test_repair_request_factory_builds_request_from_kernel_context(): void
    {
        $factory = new RepairRequestFactory;
        $request = $factory->fromKernelContext(
            envelopeId: 'env_factory',
            receiptId: 'receipt_factory',
            failure: new FailureClassification(
                domain: FailureDomain::ProviderTimeout,
                source: 'factory_test',
            ),
            policy: DecisionRepairPolicy::fromArray([
                'enabled' => true,
                'max_attempts' => 2,
                'allowed_strategies' => [RepairStrategy::RetryProvider->value],
            ]),
            currentAttempt: 1,
            evidenceRefs: ['ledger:event:factory', '', 'ledger:event:factory'],
            dryRun: true,
            metadata: ['caller' => 'future_ai_worker'],
        );

        $this->assertSame('env_factory', $request->envelopeId);
        $this->assertSame('receipt_factory', $request->receiptId);
        $this->assertSame(FailureDomain::ProviderTimeout, $request->failure->domain);
        $this->assertSame([RepairStrategy::RetryProvider->value], $request->policy->allowedStrategies);
        $this->assertSame(['ledger:event:factory', 'ledger:event:factory'], $request->evidenceRefs);
        $this->assertSame('future_ai_worker', $request->metadata['caller']);
    }

    public function test_repair_request_factory_default_policy_is_safe_and_explicit(): void
    {
        $policy = (new RepairRequestFactory)->defaultPolicy();

        $this->assertTrue($policy->enabled);
        $this->assertSame(1, $policy->maxAttempts);
        $this->assertSame(RepairStrategy::values(), $policy->allowedStrategies);
        $this->assertTrue($policy->requiresEvidenceForHeavyRepair);
    }

    public function test_repair_request_factory_default_policy_filters_unknown_strategies(): void
    {
        $policy = (new RepairRequestFactory)->defaultPolicy(
            allowedStrategies: [
                ' '.RepairStrategy::RetryProvider->value.' ',
                'unknown_strategy',
            ],
        );

        $this->assertSame([RepairStrategy::RetryProvider->value], $policy->allowedStrategies);
    }

    public function test_repair_strategy_mapping_covers_all_failure_domains(): void
    {
        $resolver = new RepairStrategyResolver;

        foreach (FailureDomain::cases() as $domain) {
            $this->assertContains($resolver->strategyFor($domain), RepairStrategy::values(), "Failure domain [{$domain->value}] must map to a known repair strategy.");
        }
    }

    public function test_repair_strategy_resolver_marks_human_review_domains(): void
    {
        $resolver = new RepairStrategyResolver;

        $this->assertTrue($resolver->requiresHumanReview(FailureDomain::ComplianceViolation));
        $this->assertTrue($resolver->requiresHumanReview(FailureDomain::SecurityFinding));
        $this->assertTrue($resolver->requiresHumanReview(FailureDomain::Unknown));
        $this->assertFalse($resolver->requiresHumanReview(FailureDomain::ProviderTimeout));
    }

    public function test_repair_strategy_resolver_compliance_report_is_complete(): void
    {
        $report = (new RepairStrategyResolver)->complianceReport();

        $this->assertTrue($report['ok'], implode("\n", $report['errors']));
        $this->assertSame(count(FailureDomain::cases()), $report['mapped_domains']);
        $this->assertSame(RepairStrategy::values(), $report['strategies']);
        $this->assertContains(FailureDomain::ComplianceViolation->value, $report['human_review_domains']);
        $this->assertContains(FailureDomain::Unknown->value, $report['human_review_domains']);
    }

    /**
     * @param  array<int,string>|null  $allowedStrategies
     * @param  array<int,string>  $evidenceRefs
     */
    private function request(
        FailureDomain $domain,
        int $maxAttempts = 3,
        int $currentAttempt = 0,
        ?array $allowedStrategies = null,
        array $evidenceRefs = ['ledger:event:1'],
        bool $dryRun = false,
        array $failureMetadata = [],
        array $metadata = [],
    ): RepairRequest {
        return new RepairRequest(
            envelopeId: 'env_123',
            receiptId: 'receipt_123',
            failure: new FailureClassification(
                domain: $domain,
                source: 'unit_test',
                metadata: $failureMetadata,
            ),
            policy: new RepairPolicy(
                enabled: true,
                maxAttempts: $maxAttempts,
                allowedStrategies: $allowedStrategies ?? RepairStrategy::values(),
            ),
            currentAttempt: $currentAttempt,
            evidenceRefs: $evidenceRefs,
            dryRun: $dryRun,
            metadata: $metadata,
        );
    }
}
