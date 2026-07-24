<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\AtlasTaskServingStack;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAmbiguityResolutionPlanner;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainContextHygieneIncidentTaskPlanner;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainGiveBackToQueueRepairPlanner;
use App\Services\Ai\SelfConstruction\TaskQuality\AtlasTaskQueueSelfHealingRespecPlanner;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Read-only operator surface: atlas:task:self-heal
 *
 * Scans blocked (quarantined) task-packet records through
 * {@see AtlasTaskQueueSelfHealingRespecPlanner} (per-packet malformation
 * detection), {@see AtlasExternalBrainGiveBackToQueueRepairPlanner}
 * (repair-plan ranking), and {@see AtlasExternalBrainAmbiguityResolutionPlanner}
 * (turns any uncertain model claim recorded on the blocked packet's metadata
 * into a deterministic local check instead of a speculative respec), and
 * emits repair-ready respec proposals.
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
    use EmitsCanonicalJson;

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
        $ambiguityPlanner = new AtlasExternalBrainAmbiguityResolutionPlanner;
        $contextHygienePlanner = new AtlasExternalBrainContextHygieneIncidentTaskPlanner;

        $respecProposals = [];
        $giveBackEvents = [];
        $ambiguityItems = [];
        $hygieneIncidents = [];

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

            $ambiguity = (array) ($metadata['ambiguity'] ?? []);
            if ($ambiguity !== []) {
                $ambiguityItems[] = ['task_packet_id' => $taskId] + $ambiguity;
            }

            // A blocked packet may carry AOBG/context-pack hygiene incidents (already sanitized
            // upstream by the memory safety gate) alongside its own root-cause metadata.
            foreach ((array) ($metadata['context_hygiene_incidents'] ?? []) as $incident) {
                if (is_array($incident)) {
                    $hygieneIncidents[] = $incident;
                }
            }
        }

        $repairResult = $repairPlanner->plan(['give_backs' => $giveBackEvents]);
        $ambiguityResult = $ambiguityPlanner->plan(['ambiguity_items' => $ambiguityItems]);
        $contextHygieneResult = $contextHygienePlanner->plan($hygieneIncidents);

        $payload = [
            'schema' => self::SCHEMA,
            'blocked_count' => count($blockedRecords),
            'respec_proposals' => $respecProposals,
            'repair_candidates' => $repairResult['repair_candidates'],
            // Ranked by safety_score/unblock_count/token_savings descending (see the repair
            // planner) — a real unblock path always outranks originating more fresh work.
            'ranked_repair_candidates' => $repairResult['ranked_repair_candidates'],
            'prioritization_note' => 'ranked_repair_candidates orders real unblock paths above origination; consume this list before enqueuing new work.',
            'ambiguity_resolution_actions' => $ambiguityResult['resolution_actions'],
            'unresolved_ambiguity_items' => $ambiguityResult['unresolved_items'],
            // A blocked packet whose recorded ambiguity cannot be resolved locally must never be
            // waved through as a speculative respec — the caller consumes this flag before acting.
            'ambiguity_task_creation_allowed' => $ambiguityResult['task_creation_allowed'],
            'context_hygiene_task_plan' => $contextHygieneResult['task_plan'],
        ];

        $this->line($this->encode($payload));

        return self::SUCCESS;
    }
}
