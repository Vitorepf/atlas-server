<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AtlasDecide\AtlasDecideMetaLearningService;

class AtlasDecideMetaLearningResetCommand extends AtlasMutativeCommand
{
    protected $signature = 'atlas:atlas-decide:meta-learning:reset
        {--actor=operator : Actor label}
        {--mode=plan : plan|dry-run|apply}
        {--check= : Required check code}
        {--confirm : Required flag in apply mode}
        {--json : Emit JSON envelope}';

    protected $description = 'Atlas Decide · reset routing table back to native Atlas Decide policy (append-only reset receipt).';

    private AtlasDecideMetaLearningService $svc;

    public function handle(AtlasDecideMetaLearningService $svc): int
    {
        $this->svc = $svc;

        return $this->handleMutative();
    }

    protected function mutativeName(): string
    {
        return 'atlas:atlas-decide:meta-learning:reset';
    }

    protected function availableCheckCodes(): array
    {
        return ['atlas-decide-meta-learning'];
    }

    protected function buildContext(): array
    {
        return [
            'actor' => (string) ($this->option('actor') ?? 'operator'),
        ];
    }

    protected function planActions(array $context): array
    {
        $table = $this->svc->routingTable();

        return [
            'would_reset' => true,
            'active_entries_to_be_cleared' => $table['active_entries'] ?? 0,
        ];
    }

    protected function dryRunActions(array $context): array
    {
        return $this->planActions($context);
    }

    protected function applyActions(array $context): array
    {
        $receipt = $this->svc->applyAction([
            'action' => AtlasDecideMetaLearningService::ACTION_RESET,
            'actor' => $context['actor'],
        ]);

        return [
            'activation_receipt' => $receipt,
            'routing_table_after' => $this->svc->routingTable(),
        ];
    }
}
