<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AtlasDecide\AtlasDecideMetaLearningService;

class AtlasDecideMetaLearningDeactivateCommand extends AtlasMutativeCommand
{
    protected $signature = 'atlas:atlas-decide:meta-learning:deactivate
        {--task-category= : Task category}
        {--role= : Operator role}
        {--framework= : Optional framework}
        {--actor=operator : Actor label}
        {--mode=plan : plan|dry-run|apply}
        {--check= : Required check code}
        {--confirm : Required flag in apply mode}
        {--json : Emit JSON envelope}';

    protected $description = 'Atlas Decide · deactivate a routing recommendation (return scope to native Atlas Decide policy).';

    private AtlasDecideMetaLearningService $svc;

    public function handle(AtlasDecideMetaLearningService $svc): int
    {
        $this->svc = $svc;

        return $this->handleMutative();
    }

    protected function mutativeName(): string
    {
        return 'atlas:atlas-decide:meta-learning:deactivate';
    }

    protected function availableCheckCodes(): array
    {
        return ['atlas-decide-meta-learning'];
    }

    protected function buildContext(): array
    {
        return [
            'task_category' => (string) ($this->option('task-category') ?? ''),
            'role' => (string) ($this->option('role') ?? ''),
            'framework' => $this->option('framework'),
            'actor' => (string) ($this->option('actor') ?? 'operator'),
            'action' => AtlasDecideMetaLearningService::ACTION_DEACTIVATE,
        ];
    }

    protected function planActions(array $context): array
    {
        return [
            'would_deactivate' => [
                'task_category' => $context['task_category'],
                'role' => $context['role'],
                'framework' => $context['framework'],
            ],
        ];
    }

    protected function dryRunActions(array $context): array
    {
        return $this->planActions($context);
    }

    protected function applyActions(array $context): array
    {
        $receipt = $this->svc->applyAction([
            'action' => $context['action'],
            'task_category' => $context['task_category'],
            'role' => $context['role'],
            'framework' => $context['framework'],
            'actor' => $context['actor'],
        ]);

        return [
            'activation_receipt' => $receipt,
            'routing_table_after' => $this->svc->routingTable(),
        ];
    }
}
