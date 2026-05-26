<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionSubsystemBuilderService;

class AtlasSelfConstructionProposeSubsystemCommand extends AtlasMutativeCommand
{
    protected $signature = 'atlas:self-construction:propose-subsystem
        {--gap-kind=operator_request : missing_service_class|partial_canon|pipeline_not_proven|coverage_drift|operator_request}
        {--subsystem-acronym= : Acronym for the proposed subsystem}
        {--subsystem-name= : Human-readable name}
        {--group=self_construction : group label}
        {--rationale= : Free-text rationale}
        {--mode=plan : plan|dry-run|apply}
        {--check= : Required check code}
        {--confirm : Required flag in apply mode}
        {--json : Emit JSON envelope}';

    protected $description = 'Atlas Self-Construction · propose a new subsystem (Doctor 3-Tier; never auto-merges code).';

    private AtlasSelfConstructionSubsystemBuilderService $svc;

    public function handle(AtlasSelfConstructionSubsystemBuilderService $svc): int
    {
        $this->svc = $svc;

        return $this->handleMutative();
    }

    protected function mutativeName(): string
    {
        return 'atlas:self-construction:propose-subsystem';
    }

    protected function availableCheckCodes(): array
    {
        return ['self-construction-propose'];
    }

    protected function buildContext(): array
    {
        return [
            'gap_kind' => (string) ($this->option('gap-kind') ?? 'operator_request'),
            'subsystem_acronym' => (string) ($this->option('subsystem-acronym') ?? ''),
            'subsystem_name' => (string) ($this->option('subsystem-name') ?? ''),
            'group' => (string) ($this->option('group') ?? 'self_construction'),
            'rationale' => (string) ($this->option('rationale') ?? ''),
        ];
    }

    protected function planActions(array $context): array
    {
        return [
            'would_propose' => [
                'acronym' => $context['subsystem_acronym'],
                'group' => $context['group'],
                'gap_kind' => $context['gap_kind'],
            ],
            'note' => 'Plan mode does not persist a proposal. Use --mode=apply --check=self-construction-propose --confirm.',
        ];
    }

    protected function dryRunActions(array $context): array
    {
        // Build (and discard) — proposals are not DB-bound but we still avoid persisting.
        return $this->planActions($context);
    }

    protected function applyActions(array $context): array
    {
        $proposal = $this->svc->propose([
            'gap_kind' => $context['gap_kind'],
            'subsystem_acronym' => $context['subsystem_acronym'],
            'subsystem_name' => $context['subsystem_name'],
            'group' => $context['group'],
            'rationale' => $context['rationale'],
        ]);

        return ['proposal' => $proposal];
    }
}
