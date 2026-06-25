<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\LiveCycle\Nesting\AtlasLoopSubCycleReceiptLedger;
use App\Services\Ai\AutonomousEvolution\LiveCycle\Nesting\AtlasLoopSubCycleResultMerger;
use App\Services\Ai\AutonomousEvolution\LiveCycle\Nesting\AtlasLoopSubCycleSpawner;
use App\Services\Ai\AutonomousEvolution\LiveCycle\Nesting\ParentMergeRecord;
use App\Services\Ai\AutonomousEvolution\LiveCycle\Nesting\RejectionRecord;
use App\Services\Ai\AutonomousEvolution\LiveCycle\Nesting\SubCycleSpawnRecord;
use Illuminate\Console\Command;

/**
 * Operator observability + manual control for nested loop cycles (W1160).
 *   atlas:loop:cycle:nest inspect [--json]
 *   atlas:loop:cycle:nest spawn   --parent=X --phase=ARCHITECT [--json]
 *   atlas:loop:cycle:nest merge   --parent=X --child=Y --payload=/path/to/facts.json [--json]
 *   atlas:loop:cycle:nest history --parent=X [--json]
 *
 * Read-and-observe by default; spawn/merge are explicit operator actions.
 */
final class AtlasLoopCycleNestCommand extends Command
{
    public const EXIT_USAGE = 2;

    protected $signature = 'atlas:loop:cycle:nest {action : inspect|spawn|merge|history}
        {--parent= : parent cycle id}
        {--phase= : parent phase (spawn)}
        {--child= : child cycle id (merge)}
        {--payload= : JSON file path with child outcome facts (merge)}
        {--json}';

    protected $description = 'Operator surface for nested loop cycles (inspect|spawn|merge|history).';

    public function handle(
        AtlasLoopSubCycleSpawner $spawner,
        AtlasLoopSubCycleResultMerger $merger,
        AtlasLoopSubCycleReceiptLedger $ledger,
    ): int {
        $action = (string) $this->argument('action');

        return match ($action) {
            'inspect' => $this->inspect($ledger),
            'spawn' => $this->spawn($spawner, $ledger),
            'merge' => $this->merge($merger, $ledger),
            'history' => $this->history($ledger),
            default => $this->failWith('unknown_action:'.$action),
        };
    }

    private function inspect(AtlasLoopSubCycleReceiptLedger $ledger): int
    {
        $maxDepth = (int) (function_exists('config') ? config('atlas.loop.nesting.max_depth', AtlasLoopSubCycleSpawner::DEFAULT_MAX_DEPTH) : AtlasLoopSubCycleSpawner::DEFAULT_MAX_DEPTH);
        $counts = ['SPAWN' => 0, 'MERGE' => 0, 'CLOSE' => 0];
        $path = $this->readLedgerPath($ledger);
        if ($path !== '' && is_file($path)) {
            foreach ((array) file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $row = json_decode((string) $line, true);
                $event = is_array($row) ? (string) ($row['event_type'] ?? '') : '';
                if ($event !== '' && isset($counts[$event])) {
                    $counts[$event]++;
                }
            }
        }
        $this->emit(['max_depth' => $maxDepth, 'receipts_by_type' => $counts]);

        return 0;
    }

    private function spawn(AtlasLoopSubCycleSpawner $spawner, AtlasLoopSubCycleReceiptLedger $ledger): int
    {
        $parent = (string) ($this->option('parent') ?? '');
        $phase = (string) ($this->option('phase') ?? '');
        if ($parent === '' || $phase === '') {
            return $this->failWith('spawn_requires_parent_and_phase');
        }
        $record = $spawner->spawn(
            parent: ['cycle_id' => $parent, 'phase' => $phase, 'depth' => 0],
            childScope: [],
            childIndex: 0,
        );

        $ledger->append(
            eventType: AtlasLoopSubCycleReceiptLedger::EVENT_SPAWN,
            parentCycleId: $record->parentCycleId !== '' ? $record->parentCycleId : $parent,
            childCycleId: $record->childCycleId,
            depth: $record->depth,
            recordedAt: gmdate('Y-m-d\TH:i:s\Z'),
            payload: ['granted' => $record->granted, 'refusal_reason' => $record->refusalReason],
        );

        $this->emit($record->toArray());

        return 0;
    }

    private function merge(AtlasLoopSubCycleResultMerger $merger, AtlasLoopSubCycleReceiptLedger $ledger): int
    {
        $parent = (string) ($this->option('parent') ?? '');
        $child = (string) ($this->option('child') ?? '');
        $payloadPath = (string) ($this->option('payload') ?? '');
        if ($parent === '' || $child === '' || $payloadPath === '') {
            return $this->failWith('merge_requires_parent_child_payload');
        }
        if (! is_file($payloadPath)) {
            return $this->failWith('payload_file_not_found:'.$payloadPath);
        }
        $payload = json_decode((string) file_get_contents($payloadPath), true);
        if (! is_array($payload)) {
            return $this->failWith('payload_not_valid_json');
        }
        // Reconstruct a spawn record from CLI identifiers (P1 child_cycle_id is deterministic).
        $spawnRecord = SubCycleSpawnRecord::granted(
            parentCycleId: $parent,
            parentPhase: 'OPERATOR-MERGE',
            childCycleId: $child,
            depth: 1,
            spawnedAt: gmdate('Y-m-d\TH:i:s\Z'),
            scopeDigest: 'sha256:cli',
        );

        $result = $merger->merge($spawnRecord, $payload);

        if ($result instanceof ParentMergeRecord) {
            $ledger->append(
                eventType: AtlasLoopSubCycleReceiptLedger::EVENT_MERGE,
                parentCycleId: $parent,
                childCycleId: $child,
                depth: 1,
                recordedAt: gmdate('Y-m-d\TH:i:s\Z'),
                payload: ['fact_count' => $result->factCount, 'fact_keys_sorted' => $result->factKeysSorted],
            );
        }

        $this->emit($result->toArray());

        return $result instanceof RejectionRecord ? self::EXIT_USAGE : 0;
    }

    private function history(AtlasLoopSubCycleReceiptLedger $ledger): int
    {
        $parent = (string) ($this->option('parent') ?? '');
        if ($parent === '') {
            return $this->failWith('history_requires_parent');
        }
        $chain = $ledger->chain($parent);
        $this->emit($chain);

        return 0;
    }

    private function readLedgerPath(AtlasLoopSubCycleReceiptLedger $ledger): string
    {
        try {
            $reflection = new \ReflectionClass($ledger);
            $prop = $reflection->getProperty('path');
            $prop->setAccessible(true);

            return (string) $prop->getValue($ledger);
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @param  array<int|string,mixed>  $payload
     */
    private function emit(array $payload): void
    {
        if ($this->option('json')) {
            $this->getOutput()->writeln((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return;
        }
        foreach ($payload as $k => $v) {
            $this->getOutput()->writeln($k.': '.(is_scalar($v) || $v === null ? (string) $v : json_encode($v, JSON_UNESCAPED_SLASHES)));
        }
    }

    private function failWith(string $reason): int
    {
        $this->getOutput()->writeln($reason);

        return self::EXIT_USAGE;
    }
}
