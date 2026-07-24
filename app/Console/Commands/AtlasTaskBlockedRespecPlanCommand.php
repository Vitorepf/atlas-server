<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\AtlasTaskServingStack;
use App\Services\Ai\SelfConstruction\TaskQuality\AtlasTaskBlockedPacketFamilyClassifier;
use App\Services\Ai\SelfConstruction\TaskQuality\AtlasTaskBlockedPacketFieldRecoveryMiner;
use App\Services\Ai\SelfConstruction\TaskQuality\AtlasTaskBlockedQueueRespecDrafter;
use App\Services\Ai\SelfConstruction\TaskQuality\AtlasTaskBlockedReplacementDraftCompleter;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Read-only operator surface: atlas:task:blocked-respec-plan
 *
 * Reads the task-serving queue, classifies every blocked packet into a family, counts
 * them, lists retire_only duplicates, and emits ordered replacement drafts from
 * AtlasTaskBlockedQueueRespecDrafter. Zero queue mutations — never calls updateStatus,
 * never enqueues, never touches leases.
 *
 * Output (always JSON):
 *   schema, blocked_count, blocked_counts (family→count), retire_only_ids,
 *   replacement_drafts (ordered by wave; each annotated with can_submit + missing_fields),
 *   draft_summary
 */
final class AtlasTaskBlockedRespecPlanCommand extends Command
{
    use EmitsCanonicalJson;

    private const SCHEMA = 'atlas.task.blocked_respec_plan.v1';

    private const DRAFT_REQUIRED_FIELDS = ['allowed_files', 'acceptance_criteria', 'required_evidence'];

    /** @var string */
    protected $signature = 'atlas:task:blocked-respec-plan {--json : Emit JSON output (always on)}';

    /** @var string */
    protected $description = 'Read-only: summarise blocked queue packets and emit ordered replacement drafts.';

    public function handle(): int
    {
        $repo = AtlasTaskServingStack::queueRepo();
        $blockedRecords = $repo->list(['status' => 'blocked']);

        $packetsForClassifier = array_map(
            static function (array $record): array {
                $tp = is_array($record['task_packet'] ?? null) ? (array) $record['task_packet'] : [];

                return array_merge($tp, [
                    'task_packet_id' => (string) ($record['task_packet_id'] ?? ($tp['task_packet_id'] ?? '')),
                    'status'         => (string) ($record['status'] ?? ''),
                    'give_back_count' => (int) ($record['metadata']['give_back_count'] ?? 0),
                ]);
            },
            $blockedRecords,
        );

        $classifier = new AtlasTaskBlockedPacketFamilyClassifier;
        $classified = $classifier->classifyBatch($packetsForClassifier);

        $blockedCounts = array_count_values(array_column($classified, 'family'));

        $retireOnlyIds = array_values(
            array_column(
                array_filter(
                    $classified,
                    static fn (array $r): bool => $r['family'] === AtlasTaskBlockedPacketFamilyClassifier::FAMILY_DUPLICATE_ALREADY_DONE,
                ),
                'task_packet_id',
            ),
        );

        $draftResult = (new AtlasTaskBlockedQueueRespecDrafter)->draft($classified);

        $packetsById = [];
        foreach ($packetsForClassifier as $packet) {
            $packetsById[(string) ($packet['task_packet_id'] ?? '')] = $packet;
        }

        $miner = new AtlasTaskBlockedPacketFieldRecoveryMiner;
        $completer = new AtlasTaskBlockedReplacementDraftCompleter;

        $annotatedDrafts = array_map(
            function (array $draft) use ($packetsById, $miner, $completer): array {
                $missingFields = array_values(
                    array_filter(
                        self::DRAFT_REQUIRED_FIELDS,
                        static fn (string $f): bool => ! isset($draft[$f]) || $draft[$f] === [] || $draft[$f] === '',
                    ),
                );

                $draft = array_merge($draft, [
                    'can_submit'    => $missingFields === [],
                    'missing_fields' => $missingFields,
                ]);

                $sourceIds = (array) ($draft['source_packet_ids'] ?? []);
                if ($missingFields === [] || count($sourceIds) !== 1) {
                    return $draft;
                }

                $packet = $packetsById[(string) $sourceIds[0]] ?? null;
                if ($packet === null) {
                    return $draft;
                }

                $recovery = $miner->recover([
                    'objective' => (string) ($packet['objective'] ?? ''),
                    'allowed_files' => (array) ($draft['allowed_files'] ?? []),
                    'acceptance_criteria' => (array) ($draft['acceptance_criteria'] ?? []),
                    'required_evidence' => (array) ($draft['required_evidence'] ?? []),
                    'scope_in' => (array) ($packet['scope_in'] ?? []),
                    'known_existing_paths' => (array) ($packet['scope_in'] ?? []),
                    'metadata' => (array) ($packet['metadata'] ?? []),
                    'source_packet_id' => (string) $sourceIds[0],
                ]);

                $fieldRecovery = array_merge($recovery['recovered_fields'], [
                    'trust' => $recovery['refusal_reasons'] === [] ? AtlasTaskBlockedReplacementDraftCompleter::TRUST_TRUSTED : 'untrusted',
                    'confidence' => $recovery['confidence'],
                ]);

                $completed = $completer->complete([
                    ['draft' => array_merge($draft, ['task_packet_id' => (string) $sourceIds[0]]), 'field_recovery' => $fieldRecovery],
                ])['completed_drafts'][0] ?? null;

                if ($completed === null) {
                    return $draft;
                }

                return array_merge($draft, [
                    'can_submit' => (bool) $completed['can_submit'],
                    'missing_fields' => (array) $completed['missing_fields'],
                    'allowed_files' => (array) $completed['allowed_files'],
                    'acceptance_criteria' => (array) $completed['acceptance_criteria'],
                    'required_evidence' => (array) $completed['required_evidence'],
                    'refusal_reasons' => (array) $completed['refusal_reasons'],
                    'replacement_task_packet_id' => $completed['replacement_task_packet_id'],
                ]);
            },
            $draftResult['drafts'],
        );

        // ACTIONABILITY METRICS — a direct signal for whether the blocked backlog is
        // improving, derived purely from the classification + completed drafts above.
        // Never enqueues/retires/mutates; read-only like the rest of this command.
        $unknownCount = (int) ($blockedCounts[AtlasTaskBlockedPacketFamilyClassifier::FAMILY_UNKNOWN] ?? 0);
        $actionableFamilyCount = count(array_filter(
            array_keys($blockedCounts),
            static fn (string $family): bool => $family !== AtlasTaskBlockedPacketFamilyClassifier::FAMILY_UNKNOWN,
        ));
        $submittableReplacementCount = count(array_filter(
            $annotatedDrafts,
            static fn (array $draft): bool => (bool) ($draft['can_submit'] ?? false),
        ));

        $refusalReasonCounts = [];
        foreach ($annotatedDrafts as $draft) {
            foreach ((array) ($draft['refusal_reasons'] ?? []) as $reason) {
                $reason = (string) $reason;
                if ($reason === '') {
                    continue;
                }
                $refusalReasonCounts[$reason] = ($refusalReasonCounts[$reason] ?? 0) + 1;
            }
        }
        arsort($refusalReasonCounts);
        $topRefusalReasons = [];
        foreach (array_slice($refusalReasonCounts, 0, 5, true) as $reason => $count) {
            $topRefusalReasons[] = ['reason' => $reason, 'count' => $count];
        }

        $payload = [
            'schema'              => self::SCHEMA,
            'blocked_count'       => count($blockedRecords),
            'blocked_counts'      => $blockedCounts,
            'retire_only_ids'     => $retireOnlyIds,
            'replacement_drafts'  => $annotatedDrafts,
            'draft_summary'       => $draftResult['summary'],
            'actionability_metrics' => [
                'actionable_family_count' => $actionableFamilyCount,
                'unknown_count' => $unknownCount,
                'submittable_replacement_count' => $submittableReplacementCount,
                'top_refusal_reasons' => $topRefusalReasons,
            ],
        ];

        $this->line($this->encode($payload));

        return self::SUCCESS;
    }
}
