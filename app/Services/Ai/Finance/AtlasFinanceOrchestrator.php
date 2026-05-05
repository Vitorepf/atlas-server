<?php

namespace App\Services\Ai\Finance;

use App\Services\Ai\Kernel\Domain\AtlasDomainOrchestrator;
use Illuminate\Support\Str;

class AtlasFinanceOrchestrator implements AtlasDomainOrchestrator
{
    public const FLOW_MARKET_RESEARCH = AtlasFinanceDomainContract::FLOW_MARKET_RESEARCH;

    public const FLOW_RISK_REVIEW = AtlasFinanceDomainContract::FLOW_RISK_REVIEW;

    public const FLOW_PORTFOLIO_ANALYSIS = AtlasFinanceDomainContract::FLOW_PORTFOLIO_ANALYSIS;

    public const FLOW_TRADE_THESIS = AtlasFinanceDomainContract::FLOW_TRADE_THESIS;

    public const FLOW_MACRO_REVIEW = AtlasFinanceDomainContract::FLOW_MACRO_REVIEW;

    public const FLOW_EARNINGS_REVIEW = AtlasFinanceDomainContract::FLOW_EARNINGS_REVIEW;

    public const FLOW_NEWS_IMPACT = AtlasFinanceDomainContract::FLOW_NEWS_IMPACT;

    public const FLOW_COMPLIANCE_REVIEW = AtlasFinanceDomainContract::FLOW_COMPLIANCE_REVIEW;

    public const FLOW_BACKTEST_PLAN = AtlasFinanceDomainContract::FLOW_BACKTEST_PLAN;

    public const FLOW_FORGE = AtlasFinanceDomainContract::FLOW_FORGE;

    /**
     * @var array<int,string>
     */
    public const FLOWS = AtlasFinanceDomainContract::FLOWS;

    public function __construct(
        private readonly AtlasFinanceDomainContract $contract,
        private readonly AtlasFinanceRuntime $runtime,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function flowPlan(string $flow = self::FLOW_MARKET_RESEARCH, array $options = []): array
    {
        $flow = $this->normalizeFlow($flow);
        $request = AtlasFinanceReviewRequest::fromOptions($options);
        $contract = $this->flowContracts()[$flow];
        $humanApprovalRequired = $flow === self::FLOW_FORGE || $request->humanApprovalRequired;

        return [
            'schema_version' => 1,
            'plan_id' => (string) Str::orderedUuid(),
            'orchestrator' => $this->orchestratorId(),
            'domain' => AtlasFinanceDomainContract::DOMAIN_ID,
            'flow' => $flow,
            'runtime' => AtlasFinanceDomainContract::RUNTIME_ID,
            'status' => $humanApprovalRequired ? 'awaiting_human_approval' : 'ready_for_review',
            'output_mode' => AtlasFinanceDomainContract::OUTPUT_MODE,
            'autonomy' => AtlasFinanceDomainContract::AUTONOMY,
            'background_allowed' => false,
            'market_execution_allowed' => false,
            'destructive_actions_allowed' => false,
            'human_approval_required' => $humanApprovalRequired,
            'compliance_gate_required' => true,
            'required_gates' => $contract['required_gates'],
            'required_evidence' => $contract['required_evidence'],
            'context_sources' => $this->contract->contextSources(),
            'memory_policy' => $this->contract->memoryPolicy(),
            'tool_policy' => $this->contract->toolPolicy(),
            'forbidden_actions' => AtlasFinanceDomainContract::FORBIDDEN_MARKET_ACTIONS,
            'operator_options' => $request->operatorOptions(),
            'audit' => [
                'requires_evidence_ledger_event' => true,
                'receipt_kind' => 'finance_analysis_review_plan',
                'review_scope_hash' => $this->scopeHash($request->scopePayload($flow)),
            ],
            'created_at' => now()->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function executeFlow(string $flow = self::FLOW_MARKET_RESEARCH, array $options = []): array
    {
        return $this->runtime->review($this->flowPlan($flow, $options));
    }

    public function orchestratorId(): string
    {
        return AtlasFinanceDomainContract::ORCHESTRATOR_ID;
    }

    public function supportedDomains(): array
    {
        return [AtlasFinanceDomainContract::DOMAIN_ID];
    }

    public function supportedFlows(): array
    {
        return self::FLOWS;
    }

    public function maturity(): string
    {
        return 'implemented';
    }

    public function plan(string $flow, array $input = [], array $context = []): array
    {
        return $this->flowPlan($flow, array_merge($context, $input));
    }

    public function execute(array $plan, array $context = []): array
    {
        return $this->runtime->review(array_merge($plan, [
            'sdk_context' => $context,
        ]));
    }

    public function repair(array $failure, array $context = []): array
    {
        return [
            'schema_version' => 1,
            'orchestrator' => $this->orchestratorId(),
            'status' => 'human_review_required',
            'reason' => 'finance_repair_requires_operator_review_and_no_market_execution',
            'failure' => $failure,
            'context' => $context,
        ];
    }

    public function summarize(array $result, array $context = []): array
    {
        return [
            'schema_version' => 1,
            'orchestrator' => $this->orchestratorId(),
            'status' => (string) ($result['status'] ?? 'unknown'),
            'market_execution_allowed' => false,
            'evidence_refs' => (array) ($result['evidence_refs'] ?? []),
            'context' => $context,
        ];
    }

    private function normalizeFlow(string $flow): string
    {
        $flow = trim($flow);
        if ($flow === '') {
            $flow = self::FLOW_MARKET_RESEARCH;
        }

        if (! str_starts_with($flow, 'finance.')) {
            $flow = 'finance.'.$flow;
        }

        if (! in_array($flow, self::FLOWS, true)) {
            throw new \InvalidArgumentException("Unsupported finance flow [{$flow}].");
        }

        return $flow;
    }

    /**
     * @return array<string,array<string,array<int,string>>>
     */
    private function flowContracts(): array
    {
        return array_map(fn (array $definition): array => [
            'required_gates' => (array) $definition['required_gates'],
            'required_evidence' => (array) $definition['required_evidence'],
        ], $this->contract->flowDefinitions());
    }

    /**
     * @param  array<string,mixed>  $scope
     */
    private function scopeHash(array $scope): string
    {
        return hash('sha256', json_encode($scope, JSON_THROW_ON_ERROR));
    }
}
