<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\AtlasTaskServingStack;
use App\Services\Ai\SelfConstruction\TaskQuality\AtlasTaskBlockedPacketFamilyClassifier;
use App\Services\Ai\SelfConstruction\TaskQuality\AtlasTaskBlockedQueueRespecDrafter;
use Illuminate\Console\Command;

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

        $annotatedDrafts = array_map(
            static function (array $draft): array {
                $missingFields = array_values(
                    array_filter(
                        self::DRAFT_REQUIRED_FIELDS,
                        static fn (string $f): bool => ! isset($draft[$f]) || $draft[$f] === [] || $draft[$f] === '',
                    ),
                );

                return array_merge($draft, [
                    'can_submit'    => $missingFields === [],
                    'missing_fields' => $missingFields,
                ]);
            },
            $draftResult['drafts'],
        );

        $payload = [
            'schema'              => self::SCHEMA,
            'blocked_count'       => count($blockedRecords),
            'blocked_counts'      => $blockedCounts,
            'retire_only_ids'     => $retireOnlyIds,
            'replacement_drafts'  => $annotatedDrafts,
            'draft_summary'       => $draftResult['summary'],
        ];

        $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
