<?php

namespace App\Services\Ai\Programming\Governance\Gates;

use App\Models\AtlasProgrammingWorkItem;
use App\Services\Ai\Programming\Cartography\AtlasProgrammingCartographyPublisherService;
use Throwable;

/**
 * Advisory gate: emits a `cartography_publishing_required` gap on the work item
 * when the change touches code (i.e. evidence references files/symbols) and
 * invokes {@see AtlasProgrammingCartographyPublisherService} on the live path.
 *
 * Never blocks.
 *
 * @see docs/engineering-knowledge-base/atlas-programming-governance-system-contracts.md (Contrato 7)
 * @see docs/engineering-knowledge-base/atlas-cartographic-knowledge-os.md
 */
class ProgrammingCartographyGate implements ProgrammingGateContract
{
    public function name(): string
    {
        return 'cartography-update';
    }

    public function evaluate(AtlasProgrammingWorkItem $workItem): ProgrammingGateOutcome
    {
        $touchesCode = false;
        foreach ((array) $workItem->evidence_refs_json as $ref) {
            if (! is_array($ref)) {
                continue;
            }
            $files = (array) ($ref['files'] ?? []);
            if ($files !== []) {
                $touchesCode = true;
                break;
            }
        }

        foreach ((array) $workItem->tasks_json as $task) {
            if (! is_array($task)) {
                continue;
            }
            if ((bool) ($task['cartography_required'] ?? false)) {
                $touchesCode = true;
                break;
            }
        }

        if ($touchesCode) {
            $this->recordGap($workItem, 'cartography_publishing_required', 'work_item_touched_code_or_declared_cartography_required');
            $publish = $this->publishCartographySnapshot($workItem);

            return ProgrammingGateOutcome::passed(
                [
                    'gap_recorded' => 'cartography_publishing_required',
                    'cartography_publish' => $publish,
                ],
                'cartography_snapshot_published_on_governance_path',
                blocking: false,
            );
        }

        return ProgrammingGateOutcome::skipped('cartography_not_required_for_this_work_item');
    }

    /**
     * @return array<string,mixed>
     */
    private function publishCartographySnapshot(AtlasProgrammingWorkItem $workItem): array
    {
        try {
            if (! class_exists(AtlasProgrammingCartographyPublisherService::class)) {
                return ['status' => 'skipped', 'reason' => 'publisher_unavailable'];
            }

            return app(AtlasProgrammingCartographyPublisherService::class)->publish([
                'workspace' => (string) ($workItem->workspace ?? ''),
                'work_item_codes' => array_filter([(string) ($workItem->code ?? '')]),
                'limit' => 25,
            ]);
        } catch (Throwable $e) {
            return ['status' => 'failed', 'reason' => $e->getMessage()];
        }
    }

    private function recordGap(AtlasProgrammingWorkItem $workItem, string $gapName, ?string $reason): void
    {
        $gaps = (array) $workItem->gaps_json;
        foreach ($gaps as $existing) {
            if (is_array($existing) && ($existing['name'] ?? null) === $gapName) {
                return;
            }
        }
        $gaps[] = ['name' => $gapName, 'reason' => $reason, 'recorded_at' => now()->toJSON()];
        $workItem->forceFill(['gaps_json' => array_values($gaps)])->save();
    }
}
