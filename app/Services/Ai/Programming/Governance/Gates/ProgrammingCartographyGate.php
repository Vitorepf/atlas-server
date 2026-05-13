<?php

namespace App\Services\Ai\Programming\Governance\Gates;

use App\Models\AtlasProgrammingWorkItem;

/**
 * Advisory gate: emits a `cartography_publishing_required` gap on the work item
 * when the change touches code (i.e. evidence references files/symbols).
 *
 * Never blocks. The actual cartography publishing pipeline is part of the
 * future Cartographic Knowledge OS.
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

            return ProgrammingGateOutcome::passed(
                ['gap_recorded' => 'cartography_publishing_required'],
                'cartography_gap_emitted_for_future_publisher',
                blocking: false,
            );
        }

        return ProgrammingGateOutcome::skipped('cartography_not_required_for_this_work_item');
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
