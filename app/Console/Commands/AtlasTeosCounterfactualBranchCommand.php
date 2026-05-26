<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Teos\AtlasTeosI3CounterfactualService;

class AtlasTeosCounterfactualBranchCommand extends AtlasMutativeCommand
{
    protected $signature = 'atlas:teos:counterfactual:branch
        {--mission-id= : mission scope}
        {--work-order-id= : work order scope}
        {--obra-id= : obra scope}
        {--anchor-decision-id= : decision anchor}
        {--alternative-kind=provider_swap : policy_swap|provider_swap|escalation|abort|replan}
        {--alternative-value= : free-text or json}
        {--factual-score=0.5 : factual outcome score [0..1]}
        {--projected-score=0.5 : projected outcome score [0..1]}
        {--depth=2 : projected path depth (max 6)}
        {--mode=plan : plan|dry-run|apply}
        {--check= : Required check code}
        {--confirm : Required flag in apply mode}
        {--json : JSON envelope}';

    protected $description = 'TEOS-I3 · build a counterfactual branch (Doctor 3-Tier).';

    private AtlasTeosI3CounterfactualService $svc;

    public function handle(AtlasTeosI3CounterfactualService $svc): int
    {
        $this->svc = $svc;

        return $this->handleMutative();
    }

    protected function mutativeName(): string
    {
        return 'atlas:teos:counterfactual:branch';
    }

    protected function availableCheckCodes(): array
    {
        return ['teos-i3-branch'];
    }

    protected function buildContext(): array
    {
        return [
            'scope' => [
                'mission_id' => $this->option('mission-id'),
                'work_order_id' => $this->option('work-order-id'),
                'obra_id' => $this->option('obra-id'),
            ],
            'anchor_decision_id' => (string) ($this->option('anchor-decision-id') ?? ''),
            'alternative' => [
                'decision_kind' => (string) ($this->option('alternative-kind') ?? 'provider_swap'),
                'value' => $this->option('alternative-value'),
            ],
            'factual_outcome_score' => (float) ($this->option('factual-score') ?? 0.5),
            'projected_outcome_score' => (float) ($this->option('projected-score') ?? 0.5),
            'depth' => (int) ($this->option('depth') ?? 2),
        ];
    }

    protected function planActions(array $context): array
    {
        return ['would_build_branch' => $context];
    }

    protected function dryRunActions(array $context): array
    {
        return $this->planActions($context);
    }

    protected function applyActions(array $context): array
    {
        return ['branch' => $this->svc->branch($context)];
    }
}
