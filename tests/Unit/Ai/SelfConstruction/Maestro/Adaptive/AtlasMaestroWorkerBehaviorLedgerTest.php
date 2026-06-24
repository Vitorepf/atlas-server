<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Adaptive;

use App\Services\Ai\SelfConstruction\Maestro\Adaptive\AtlasMaestroWorkerBehaviorLedger;
use Tests\TestCase;

final class AtlasMaestroWorkerBehaviorLedgerTest extends TestCase
{
    public function test_record_appends_rows_and_increments_matching_counter(): void
    {
        $path = $this->path();
        config(['atlas.maestro.adaptive.behavior_ledger_enabled' => true]);
        $clock = 1000;
        $ledger = new AtlasMaestroWorkerBehaviorLedger($path, static function () use (&$clock): int {
            return $clock++;
        });

        $ledger->record('served', 'codex-1', 'feature');
        $facts = $ledger->record('give_back', 'codex-1', 'feature');

        $this->assertSame(1, $facts['served']);
        $this->assertSame(1, $facts['give_back']);
        $this->assertSame(2, count(file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)));
        $this->assertSame('give_back', $facts['last_event']);
    }

    public function test_flag_off_is_byte_identical_noop(): void
    {
        $path = $this->path();
        config(['atlas.maestro.adaptive.behavior_ledger_enabled' => false]);
        $ledger = new AtlasMaestroWorkerBehaviorLedger($path, static fn (): int => 1000);
        $before = is_file($path) ? (string) file_get_contents($path) : '';

        $record = $ledger->record('served', 'codex-1', 'feature');
        $after = is_file($path) ? (string) file_get_contents($path) : '';

        $this->assertSame($before, $after);
        $this->assertSame($ledger->recall('codex-1', 'feature'), $record);
        $this->assertSame(0, $record['served']);
        $this->assertSame([], $ledger->topGiveBackClasses(3));
    }

    public function test_recall_returns_integer_counts_and_timestamps_only_without_scores_or_ratios(): void
    {
        $path = $this->path();
        config(['atlas.maestro.adaptive.behavior_ledger_enabled' => true]);
        $ledger = new AtlasMaestroWorkerBehaviorLedger($path, static fn (): int => 1234);

        $ledger->record('success', 'claude-1', 'bugfix');
        $facts = $ledger->recall('claude-1', 'bugfix');

        foreach ($facts as $key => $value) {
            $this->assertIsNotFloat($value, $key);
        }
        $this->assertArrayNotHasKey('score', $facts);
        $this->assertArrayNotHasKey('rank', $facts);
        $this->assertArrayNotHasKey('ratio', $facts);
        $this->assertSame(1, $facts['success']);
        $this->assertSame(1234, $facts['last_seen_at']);
    }

    public function test_top_give_back_classes_uses_facts_only(): void
    {
        $path = $this->path();
        config(['atlas.maestro.adaptive.behavior_ledger_enabled' => true]);
        $ledger = new AtlasMaestroWorkerBehaviorLedger($path, static fn (): int => 1234);

        $ledger->record('give_back', 'codex-1', 'poison');
        $ledger->record('give_back', 'claude-1', 'poison');
        $ledger->record('give_back', 'codex-1', 'scope');

        $top = $ledger->topGiveBackClasses(1);

        $this->assertSame([['task_class' => 'poison', 'give_back' => 2, 'last_seen_at' => 1234]], $top);
    }

    private function path(): string
    {
        return sys_get_temp_dir().'/atlas-maestro-worker-behavior-'.bin2hex(random_bytes(6)).'.jsonl';
    }
}
