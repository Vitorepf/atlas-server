<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AtlasDecide\AtlasDecideMetaLearningService;

/**
 * Atlas Decide · Meta-Learning activate routing recommendation (Doctor 3-Tier).
 *
 *   php artisan atlas:atlas-decide:meta-learning:activate \
 *     --task-category=frontend --role=builder \
 *     --mode=apply --check=atlas-decide-meta-learning --confirm
 */
class AtlasDecideMetaLearningActivateCommand extends AtlasMutativeCommand
{
    protected $signature = 'atlas:atlas-decide:meta-learning:activate
        {--task-category= : Task category to activate}
        {--role= : Operator role}
        {--framework= : Optional framework}
        {--actor=operator : Actor label for receipt}
        {--mode=plan : plan|dry-run|apply}
        {--check= : Required check code for dry-run and apply}
        {--confirm : Required flag in apply mode}
        {--json : Emit JSON envelope}';

    protected $description = 'Atlas Decide · activate a meta-learning routing recommendation as authoritative for (task_category, role[, framework]).';

    public function handle(AtlasDecideMetaLearningService $svc): int
    {
        $this->svc = $svc;

        return $this->handleMutative();
    }

    private AtlasDecideMetaLearningService $svc;

    protected function mutativeName(): string
    {
        return 'atlas:atlas-decide:meta-learning:activate';
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
            'action' => AtlasDecideMetaLearningService::ACTION_ACTIVATE,
        ];
    }

    protected function planActions(array $context): array
    {
        $rec = $this->svc->recommend([
            'task_category' => $context['task_category'],
            'role' => $context['role'],
            'framework' => $context['framework'],
        ]);

        return [
            'would_activate' => [
                'task_category' => $context['task_category'],
                'role' => $context['role'],
                'framework' => $context['framework'],
            ],
            'recommendation_snapshot' => [
                'provider' => $rec['recommended_provider'],
                'model' => $rec['recommended_model'],
                'actionable' => $rec['actionable'],
                'confidence' => $rec['confidence'],
                'evidence_count' => $rec['evidence_count'],
                'reason' => $rec['reason'],
            ],
        ];
    }

    protected function dryRunActions(array $context): array
    {
        // No DB mutation — activation log is JSONL. Simulate by recommending.
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

    protected function rollbackRef(array $context): ?string
    {
        return sprintf(
            'atlas:atlas-decide:meta-learning:deactivate --task-category=%s --role=%s --mode=apply --check=atlas-decide-meta-learning --confirm',
            $context['task_category'],
            $context['role']
        );
    }
}
