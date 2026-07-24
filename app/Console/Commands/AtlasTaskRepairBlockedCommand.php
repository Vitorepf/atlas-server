<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\AtlasTaskServingStack;
use App\Services\Ai\SelfConstruction\E2E\AtlasSelfConstructionSelfHealingQueueRepairPlan;
use Illuminate\Console\Command;
use App\Support\YesNo;

/**
 * Repairs blocked task-serving packets instead of letting "blocked" become a graveyard. The first repair class
 * is forbidden-self-target scope repair: keep the same task id, remove uncommittable write paths, and reopen.
 */
class AtlasTaskRepairBlockedCommand extends Command
{
    protected $signature = 'atlas:task:repair-blocked
        {--limit=0 : Maximum blocked packets to repair; 0 uses the safety window}
        {--dry-run : Inspect and report only}
        {--actor=task_repair : actor label recorded in queue metadata/receipts}
        {--json : Print machine-readable JSON}';

    protected $description = 'Repair blocked task-serving packets into claimable, committable packets when safe.';

    public function handle(): int
    {
        $orchestrator = AtlasTaskServingStack::orchestrator();
        $limit = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');
        $actor = (string) $this->option('actor');

        // Two repair classes, both fail-safe (same task id, no fork). (1) forbidden-self-target still IN
        // allowed_files; (2) scope-repair-doomed — the pétreo target was already moved to forbidden_files but the
        // acceptance still demands it (the dominant jam cause), plus stale self-sufficient quarantines.
        $forbidden = $orchestrator->repairBlockedForbiddenSelfTargetTasks(limit: $limit, dryRun: $dryRun, actor: $actor);
        $blockedRepair = (string) ($forbidden['status'] ?? '') === 'blocked' ? $forbidden : null;
        $scope = $blockedRepair === null ? $orchestrator->repairScopeBlockedTasks(limit: $limit, dryRun: $dryRun, actor: $actor) : null;
        if ($scope !== null && (string) ($scope['status'] ?? '') === 'blocked') {
            $blockedRepair = $scope;
        }

        if ($blockedRepair !== null) {
            $result = [
                'status' => 'blocked',
                'reason' => (string) ($blockedRepair['reason'] ?? 'unknown_reason'),
                'repair_path' => $scope === null ? 'forbidden_self_target_repair' : 'scope_repair',
                'repair' => $blockedRepair,
            ];
            if ($this->option('json')) {
                $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

                return self::FAILURE;
            }

            $this->error('Blocked task repair: '.$result['reason']);

            return self::FAILURE;
        }

        // Self-heal quarantine: repeated-give-back packets that repairScopeBlockedTasks bails as UNREPAIRABLE
        // (reason 'repeated_give_back_quarantine_requires_respec') stay blocked forever. The self-healing organ
        // plans a 'cancel_until_respec' action for them — we execute it (blocked→cancelled), destravando dependents.
        $selfHeal = $this->selfHealQuarantine($scope['unrepairable'] ?? [], $dryRun, $limit, $actor);

        $result = ['dry_run' => $dryRun, 'forbidden_self_target_repair' => $forbidden, 'scope_repair' => $scope, 'self_heal' => $selfHeal];

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->line('');
        $this->line('  <fg=cyan>TASK-SERVING BLOCKED REPAIR</>  dry_run='.(YesNo::format($dryRun)));
        $this->line('  [forbidden-self-target] inspected='.$forbidden['inspected_blocked'].'  repairable='.$forbidden['repairable_count'].'  repaired='.$forbidden['repaired_count'].'  retired='.$forbidden['retired_count'].'  unrepairable='.$forbidden['unrepairable_count']);
        $this->line('  [scope-repair-doomed]   inspected='.$scope['inspected_blocked'].'  reopened='.$scope['reopened_count'].'  retired='.$scope['retired_count'].'  planned='.$scope['planned_count'].'  unrepairable='.$scope['unrepairable_count']);
        foreach (array_slice((array) ($dryRun ? $scope['plan'] : array_merge($scope['reopened'], $scope['retired'])), 0, 30) as $item) {
            $this->line('    - '.($item['action'] ?? '?').'  '.($item['task_packet_id'] ?? '?').'  scrub=['.implode(',', (array) ($item['scrubbed_paths'] ?? [])).']');
        }
        $this->line('  [self-heal quarantine]  cancelled='.count($selfHeal['cancelled'] ?? []).'  planned='.count($selfHeal['plan'] ?? []));
        foreach (array_slice($dryRun ? ($selfHeal['plan'] ?? []) : ($selfHeal['cancelled'] ?? []), 0, 30) as $item) {
            $this->line('    - '.($item['action'] ?? '?').'  '.($item['packet_id'] ?? '?'));
        }
        $this->line('');

        return self::SUCCESS;
    }

    /**
     * Self-heal quarantine: wire the self-healing repair planner over unrepairable repeated-give-back
     * packets. The planner returns a 'cancel_until_respec' action for bucket 'quarantine' — we execute it.
     *
     * @param  list<array<string,mixed>>  $unrepairable
     * @return array{planned:list<array<string,mixed>>, cancelled:list<array<string,mixed>>, plan:list<array<string,mixed>>}
     */
    private function selfHealQuarantine(array $unrepairable, bool $dryRun, int $limit, string $actor): array
    {
        $inputs = [];
        foreach ($unrepairable as $item) {
            $reason = (string) ($item['reason'] ?? '');
            if ($reason !== 'repeated_give_back_quarantine_requires_respec') {
                continue;
            }
            $packetId = (string) ($item['task_packet_id'] ?? '');
            if ($packetId === '') {
                continue;
            }

            // Extract N from the blocking deficiency 'repeated_give_back_N'.
            $deficiencies = (array) ($item['blocking_deficiencies'] ?? []);
            $repeated = AtlasSelfConstructionSelfHealingQueueRepairPlan::QUARANTINE_THRESHOLD;
            foreach ($deficiencies as $def) {
                if (preg_match('/repeated_give_back_(\d+)/', (string) $def, $m)) {
                    $repeated = max($repeated, (int) $m[1]);
                }
            }

            $inputs[] = ['packet_id' => $packetId, 'repeated_returns' => $repeated];
        }

        if ($inputs === []) {
            return ['planned' => [], 'cancelled' => [], 'plan' => []];
        }

        $plan = (new AtlasSelfConstructionSelfHealingQueueRepairPlan)->plan($inputs);

        $planned = [];
        $cancelled = [];
        $queue = AtlasTaskServingStack::queueRepo();
        $applied = 0;
        foreach ($plan['actions'] ?? [] as $action) {
            if (($action['bucket'] ?? '') !== AtlasSelfConstructionSelfHealingQueueRepairPlan::BUCKET_QUARANTINE) {
                continue;
            }
            $packetId = (string) ($action['packet_id'] ?? '');
            if ($packetId === '') {
                continue;
            }

            if ($dryRun) {
                $planned[] = $action;

                continue;
            }

            $t = $queue->updateStatus($packetId, 'cancelled', [
                'reason' => 'self_heal_quarantine_cancel_until_respec',
                'agent_id' => $actor,
            ]);
            if ((string) ($t['status'] ?? '') === 'ok') {
                $cancelled[] = $action;
                $applied++;
            }

            if ($limit > 0 && $applied >= $limit) {
                break;
            }
        }

        return ['planned' => $planned, 'cancelled' => $cancelled, 'plan' => $plan['actions'] ?? []];
    }
}
