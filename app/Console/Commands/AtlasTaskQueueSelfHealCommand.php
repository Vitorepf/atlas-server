<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\AtlasTaskServingStack;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainGiveBackToQueueRepairPlanner;
use App\Services\Ai\SelfConstruction\TaskQuality\AtlasTaskQueueSelfHealingRespecPlanner;
use Illuminate\Console\Command;

/**
 * Read-only operator surface: atlas:task:self-heal
 *
 * Scans blocked (quarantined) task-packet records through
 * {@see AtlasTaskQueueSelfHealingRespecPlanner} (per-packet malformation
 * detection) and {@see AtlasExternalBrainGiveBackToQueueRepairPlanner}
 * (repair-plan ranking), and emits repair-ready respec proposals.
 *
 * Zero queue mutations — never calls updateStatus, never enqueues, never
 * touches leases. Repair candidates are ranked by safety_score so a real
 * unblock path always outranks originating more fresh work.
 *
 * Output (always JSON):
 *   schema, blocked_count, respec_proposals, repair_candidates,
 *   ranked_repair_candidates, prioritization_note
 */
final class AtlasTaskQueueSelfHealCommand extends Command
{
    private const SCHEMA = 'atlas.task.self_heal.v1';

    /** @var string */
    protected $signature = 'atlas:task:self-heal {--json : Emit JSON output (always on)}';

    /** @var string */
    protected $description = 'Read-only: scan blocked packets for self-healing respec + repair proposals.';

    public function handle(): int
    {
        $repo = AtlasTaskServingStack::queueRepo();
        $blockedRecords = $repo->list(['status' => 'blocked']);

        $respecPlanner = new AtlasTaskQueueSelfHealingRespecPlanner;
        $repairPlanner = new AtlasExternalBrainGiveBackToQueueRepairPlanner;

        $respecProposals = [];
        $giveBackEvents = [];

        foreach ($blockedRecords as $record) {
            $taskPacket = is_array($record['task_packet'] ?? null) ? (array) $record['task_packet'] : [];
            $taskId = (string) ($record['task_packet_id'] ?? ($taskPacket['task_packet_id'] ?? ''));
            if ($taskId === '') {
                continue;
            }
            $metadata = (array) ($record['metadata'] ?? []);
            $allowedFiles = (array) data_get($taskPacket, 'normalized_scope.allowed_files', $taskPacket['allowed_files'] ?? []);

            $respecInput = [
                'allowed_files' => $allowedFiles,
                'acceptance_criteria' => (array) ($taskPacket['acceptance_criteria'] ?? []),
                'target' => (string) ($taskPacket['target'] ?? $taskId),
                'forbidden_targets' => (array) ($metadata['forbidden_targets'] ?? []),
                'scope_repair_removed_impl' => (bool) ($metadata['scope_repair_removed_impl'] ?? false),
                'has_contradictory_acceptance' => (bool) ($metadata['has_contradictory_acceptance'] ?? false),
            ];
            $respecResult = $respecPlanner->plan($respecInput);
            $respecProposals[] = ['task_packet_id' => $taskId] + $respecResult;

            $giveBackEvents[] = [
                'task_id' => $taskId,
                'root_cause' => (string) ($metadata['root_cause'] ?? ''),
                'allowed_files' => $allowedFiles,
                'missing_files' => (array) ($metadata['missing_files'] ?? []),
                'forbidden_target' => (bool) ($metadata['forbidden_target'] ?? false),
                'duplicate_capability' => (bool) ($metadata['duplicate_capability'] ?? false),
                'acceptance_contradiction' => (bool) ($metadata['has_contradictory_acceptance'] ?? false),
                'token_savings' => (float) ($metadata['token_savings'] ?? 0.0),
                'unblock_count' => (int) ($metadata['unblock_count'] ?? 0),
            ];
        }

        $repairResult = $repairPlanner->plan(['give_backs' => $giveBackEvents]);

        $payload = [
            'schema' => self::SCHEMA,
            'blocked_count' => count($blockedRecords),
            'respec_proposals' => $respecProposals,
            'repair_candidates' => $repairResult['repair_candidates'],
            // Ranked by safety_score/unblock_count/token_savings descending (see the repair
            // planner) — a real unblock path always outranks originating more fresh work.
            'ranked_repair_candidates' => $repairResult['ranked_repair_candidates'],
            'prioritization_note' => 'ranked_repair_candidates orders real unblock paths above origination; consume this list before enqueuing new work.',
        ];

        $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
