<?php

namespace App\Services\Ai\Finance;

class AtlasFinanceProfileFactory
{
    public function __construct(
        private readonly AtlasFinanceDomainContract $contract = new AtlasFinanceDomainContract,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function domainProfile(mixed $now): array
    {
        return [
            'id' => AtlasFinanceDomainContract::DOMAIN_ID,
            'label' => 'Finance',
            'status' => 'active',
            'default_flow' => AtlasFinanceDomainContract::FLOW_MARKET_RESEARCH,
            'orchestrator' => AtlasFinanceDomainContract::ORCHESTRATOR_ID,
            'runtime_family' => 'finance',
            'description' => 'Financial research, portfolio analysis, risk review, thesis planning, macro and issuer review, compliance checks, and backtest planning for analysis/review only.',
            'autonomy_default' => AtlasFinanceDomainContract::AUTONOMY,
            'background_allowed' => false,
            'context_policy' => $this->contextPolicy(),
            'tool_policy' => $this->contract->toolPolicy(),
            'memory_policy' => $this->contract->memoryPolicy(),
            'gate_policy' => $this->contract->domainGatePolicy(),
            'metadata' => $this->domainMetadata(),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function flowProfiles(): array
    {
        return collect($this->contract->flowDefinitions())
            ->map(fn (array $definition, string $id): array => $this->flowProfile($id, $definition))
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    public function contextPolicy(): array
    {
        return [
            'preset' => 'finance_review_context',
            'require_context_pack' => true,
            'sources' => $this->contract->contextSources(),
            'budget' => [
                'max_prompt_tokens' => 14000,
                'reserved_output_tokens' => 4000,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function domainMetadata(): array
    {
        return [
            'seed' => 'finance_domain_contract',
            'surfaces' => ['cli:atlas ai --domain=finance', 'api:ai/domains', 'app:finance', 'mcp:open_brain'],
            'surface_policy' => [
                'analysis_review_only' => true,
                'market_orders_forbidden' => true,
                'broker_execution_requires_separate_non_ai_system' => true,
            ],
            'integration_status' => 'profiles_seeded_orchestrator_not_registered',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function flowMetadata(bool $requiresApproval): array
    {
        return [
            'seed' => 'finance_domain_contract',
            'surfaces' => ['cli', 'api', 'app', 'mcp'],
            'learning' => [
                'record_evidence' => true,
                'promote_reviewed_findings' => true,
                'feed_self_improvement' => true,
            ],
            'requires_human_approval' => $requiresApproval,
            'forbidden_actions' => AtlasFinanceDomainContract::FORBIDDEN_MARKET_ACTIONS,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function flowProfile(string $id, array $definition): array
    {
        $requiresApproval = (bool) ($definition['requires_human_approval'] ?? false);

        return [
            'id' => $id,
            'domain_id' => AtlasFinanceDomainContract::DOMAIN_ID,
            'label' => (string) $definition['label'],
            'status' => 'active',
            'orchestrator' => AtlasFinanceDomainContract::ORCHESTRATOR_ID,
            'runtime' => (string) $definition['runtime'],
            'description' => "{$definition['label']} canonical Finance flow for analysis/review only.",
            'autonomy' => AtlasFinanceDomainContract::AUTONOMY,
            'background_allowed' => false,
            'requires_human_approval_for_destructive' => true,
            'context_policy' => $this->contextPolicy(),
            'tool_policy' => $this->contract->toolPolicy(),
            'memory_policy' => $this->contract->memoryPolicy(),
            'gate_policy' => [
                'required' => (array) $definition['required_gates'],
                'requires_final_summary' => true,
                'autonomy_ceiling' => AtlasFinanceDomainContract::OUTPUT_MODE,
                'market_execution_allowed' => false,
            ],
            'execution_policy' => [
                'executor_preference' => $requiresApproval ? 'finance_forge_review_runtime' : 'finance_review_runtime',
                'analysis_only' => true,
                'market_execution_allowed' => false,
                'order_generation_allowed' => false,
                'requires_human_approval' => $requiresApproval,
                'required_evidence' => (array) $definition['required_evidence'],
            ],
            'metadata' => $this->flowMetadata($requiresApproval),
        ];
    }
}
