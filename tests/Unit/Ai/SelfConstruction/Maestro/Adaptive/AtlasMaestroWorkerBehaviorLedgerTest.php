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

    public function test_recall_returns_counts_and_timestamps_without_a_synthesized_quality_score(): void
    {
        $path = $this->path();
        config(['atlas.maestro.adaptive.behavior_ledger_enabled' => true]);
        $ledger = new AtlasMaestroWorkerBehaviorLedger($path, static fn (): int => 1234);

        $ledger->record('success', 'claude-1', 'bugfix');
        $facts = $ledger->recall('claude-1', 'bugfix');

        // No opaque blended/weighted ranking value — only named, traceable per-event facts.
        $this->assertArrayNotHasKey('score', $facts);
        $this->assertArrayNotHasKey('rank', $facts);
        $this->assertArrayNotHasKey('ratio', $facts);
        $this->assertSame(1, $facts['success']);
        $this->assertSame(1234, $facts['last_seen_at']);
    }

    // ── reliability rate facts ──────────────────────────────────────────────

    public function test_success_give_back_and_gate_rejected_rates_are_derived_from_counts(): void
    {
        $path = $this->path();
        config(['atlas.maestro.adaptive.behavior_ledger_enabled' => true]);
        $ledger = new AtlasMaestroWorkerBehaviorLedger($path, static fn (): int => 1000);

        $ledger->record('served', 'codex-1', 'feature');
        $ledger->record('success', 'codex-1', 'feature');
        $ledger->record('give_back', 'codex-1', 'feature');
        $ledger->record('gate_rejected', 'codex-1', 'feature');
        $facts = $ledger->recall('codex-1', 'feature');

        // 4 total events: served, success, give_back, gate_rejected — each counted once.
        $this->assertSame(4, $facts['total_events']);
        $this->assertSame(0.25, $facts['success_rate']);
        $this->assertSame(0.25, $facts['give_back_rate']);
        $this->assertSame(0.25, $facts['gate_rejected_rate']);
    }

    public function test_rates_are_zero_when_total_events_is_zero(): void
    {
        $path = $this->path();
        config(['atlas.maestro.adaptive.behavior_ledger_enabled' => true]);
        $ledger = new AtlasMaestroWorkerBehaviorLedger($path, static fn (): int => 1000);

        $facts = $ledger->recall('never-seen', 'feature');

        $this->assertSame(0, $facts['total_events']);
        $this->assertSame(0.0, $facts['success_rate']);
        $this->assertSame(0.0, $facts['give_back_rate']);
        $this->assertSame(0.0, $facts['gate_rejected_rate']);
    }

    public function test_record_return_value_includes_rate_facts_consistent_with_recall(): void
    {
        $path = $this->path();
        config(['atlas.maestro.adaptive.behavior_ledger_enabled' => true]);
        $ledger = new AtlasMaestroWorkerBehaviorLedger($path, static fn (): int => 1000);

        $ledger->record('success', 'codex-1', 'feature');
        $afterRecord = $ledger->record('give_back', 'codex-1', 'feature');
        $afterRecall = $ledger->recall('codex-1', 'feature');

        $this->assertSame($afterRecall['success_rate'], $afterRecord['success_rate']);
        $this->assertSame($afterRecall['give_back_rate'], $afterRecord['give_back_rate']);
        $this->assertSame($afterRecall['total_events'], $afterRecord['total_events']);
    }

    public function test_flag_off_recall_has_zero_rate_facts(): void
    {
        $path = $this->path();
        config(['atlas.maestro.adaptive.behavior_ledger_enabled' => false]);
        $ledger = new AtlasMaestroWorkerBehaviorLedger($path, static fn (): int => 1000);

        $record = $ledger->record('served', 'codex-1', 'feature');

        $this->assertSame(0, $record['total_events']);
        $this->assertSame(0.0, $record['success_rate']);
        $this->assertSame([], $record['recent_events']);
    }

    // ── recent_events bounded list ──────────────────────────────────────────

    public function test_recent_events_is_present_and_records_event_and_timestamp(): void
    {
        $path = $this->path();
        config(['atlas.maestro.adaptive.behavior_ledger_enabled' => true]);
        $clock = 1000;
        $ledger = new AtlasMaestroWorkerBehaviorLedger($path, static function () use (&$clock): int {
            return $clock++;
        });

        $ledger->record('served', 'codex-1', 'feature');
        $facts = $ledger->record('success', 'codex-1', 'feature');

        $this->assertCount(2, $facts['recent_events']);
        $this->assertSame('served', $facts['recent_events'][0]['event']);
        $this->assertSame(1000, $facts['recent_events'][0]['at']);
        $this->assertSame('success', $facts['recent_events'][1]['event']);
        $this->assertSame(1001, $facts['recent_events'][1]['at']);
    }

    public function test_recent_events_is_bounded_and_keeps_newest_last(): void
    {
        $path = $this->path();
        config(['atlas.maestro.adaptive.behavior_ledger_enabled' => true]);
        $clock = 0;
        $ledger = new AtlasMaestroWorkerBehaviorLedger($path, static function () use (&$clock): int {
            return $clock++;
        });

        $facts = [];
        for ($i = 0; $i < 25; $i++) {
            $facts = $ledger->record('served', 'codex-1', 'feature');
        }

        // Bounded to the last 20 entries, chronological (newest-last).
        $this->assertCount(20, $facts['recent_events']);
        $this->assertSame(24, $facts['recent_events'][19]['at']);
        $this->assertSame(5, $facts['recent_events'][0]['at']);
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
