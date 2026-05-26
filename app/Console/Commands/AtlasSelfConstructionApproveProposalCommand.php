<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionSubsystemBuilderService;

class AtlasSelfConstructionApproveProposalCommand extends AtlasMutativeCommand
{
    protected $signature = 'atlas:self-construction:approve-proposal
        {--proposal-id= : Proposal UUID}
        {--proposal-hash= : Proposal hash}
        {--action=approve : approve|reject}
        {--actor=operator : Actor label}
        {--rationale= : Free-text rationale}
        {--mode=plan : plan|dry-run|apply}
        {--check= : Required check code}
        {--confirm : Required flag in apply mode}
        {--json : Emit JSON envelope}';

    protected $description = 'Atlas Self-Construction · approve or reject a proposal (append-only receipt).';

    private AtlasSelfConstructionSubsystemBuilderService $svc;

    public function handle(AtlasSelfConstructionSubsystemBuilderService $svc): int
    {
        $this->svc = $svc;

        return $this->handleMutative();
    }

    protected function mutativeName(): string
    {
        return 'atlas:self-construction:approve-proposal';
    }

    protected function availableCheckCodes(): array
    {
        return ['self-construction-approve'];
    }

    protected function buildContext(): array
    {
        return [
            'proposal_id' => (string) ($this->option('proposal-id') ?? ''),
            'proposal_hash' => (string) ($this->option('proposal-hash') ?? ''),
            'action' => (string) ($this->option('action') ?? 'approve'),
            'actor' => (string) ($this->option('actor') ?? 'operator'),
            'rationale' => (string) ($this->option('rationale') ?? ''),
        ];
    }

    protected function planActions(array $context): array
    {
        return [
            'would_record_approval' => [
                'proposal_id' => $context['proposal_id'],
                'action' => $context['action'],
            ],
        ];
    }

    protected function dryRunActions(array $context): array
    {
        return $this->planActions($context);
    }

    protected function applyActions(array $context): array
    {
        $receipt = $this->svc->approve($context);

        return ['approval_receipt' => $receipt];
    }
}
