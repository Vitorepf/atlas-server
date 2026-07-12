<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\AcosMax;

use App\Services\Ai\Cognition\Watchdog\Checks\DailyCanaryReplayByRefsWatchdogCheck;
use App\Services\Ai\Context\AtlasDeliveredPackLedger;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * MAXG-06 — Daily canary replay-by-refs plugin (WDG-01).
 *
 * Acceptance clauses proven by these tests (frontier plan §1132-1136):
 *
 *  - `evidence.flows_checked`, `evidence.ref_stability`, `evidence.golden_recall_at_5`
 *    are emitted by every run (present as keys) — the acceptance shape.
 *  - Provider-safe by construction: the run assertion rejects any evidence key
 *    that would smell like a raw query/context/body/markdown (grep-safe).
 *  - Insufficient signal: with zero ledger entries and no golden signal, the
 *    check returns `skipped` with `reason=insufficient_signal` (never fabricates ok).
 *  - Alert: golden recall_at_5 below the pinned floor OR ref_stability below
 *    the pinned floor OR improper_floor_discards > 0 ⇒ alert with named violations.
 *  - Ok path: canary within floors with a healthy sample.
 */
final class Maxg06DailyCanaryReplayByRefsTest extends TestCase
{
    private string $ledgerPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerPath = sys_get_temp_dir().'/maxg06-canary-'.uniqid('', true).'.jsonl';
        if (file_exists($this->ledgerPath)) {
            unlink($this->ledgerPath);
        }
    }

    protected function tearDown(): void
    {
        if (file_exists($this->ledgerPath)) {
            @unlink($this->ledgerPath);
        }
        parent::tearDown();
    }

    #[Test]
    public function schema_version_and_id_are_pinned(): void
    {
        $this->assertSame(
            'atlas.acos.watchdog.daily_canary_replay_by_refs.v1',
            DailyCanaryReplayByRefsWatchdogCheck::SCHEMA_VERSION,
        );

        $check = $this->makeCheck(fn (): array => []);
        $this->assertSame('wdg-01.daily_canary_replay_by_refs', $check->id());
    }

    #[Test]
    public function evidence_carries_flows_checked_ref_stability_and_golden_recall_at_5(): void
    {
        $now = CarbonImmutable::parse('2026-07-12T12:00:00Z');
        $this->seedRow(['code:'.str_repeat('a', 32), 'memory:'.str_repeat('b', 32)], $now->subHours(2));

        $check = $this->makeCheck(
            goldenReport: fn (): array => $this->syntheticGoldenReport(0.72, 0),
            now: $now,
        );

        $result = $check->run();
        $evidence = $result->evidence;

        $this->assertArrayHasKey('flows_checked', $evidence);
        $this->assertArrayHasKey('ref_stability', $evidence);
        $this->assertArrayHasKey('golden_recall_at_5', $evidence);
        $this->assertSame(1, $evidence['flows_checked']);
        $this->assertSame(1.0, $evidence['ref_stability']);
        $this->assertSame(0.72, $evidence['golden_recall_at_5']);
    }

    #[Test]
    public function insufficient_signal_when_ledger_empty_and_golden_unavailable(): void
    {
        $now = CarbonImmutable::parse('2026-07-12T12:00:00Z');
        $check = $this->makeCheck(
            goldenReport: fn (): ?array => null,
            now: $now,
        );

        $result = $check->run();

        $this->assertSame('skipped', $result->status);
        $this->assertSame('insufficient_signal', $result->evidence['reason']);
        $this->assertSame(0, $result->evidence['flows_checked']);
        $this->assertNull($result->evidence['ref_stability']);
        $this->assertNull($result->evidence['golden_recall_at_5']);
    }

    #[Test]
    public function outdated_entries_outside_window_do_not_count(): void
    {
        $now = CarbonImmutable::parse('2026-07-12T12:00:00Z');
        // Two hours ago: in window (24h default).
        $this->seedRow(['memory:'.str_repeat('c', 32)], $now->subHours(2));
        // 48 hours ago: outside 24h window.
        $this->seedRow(['memory:'.str_repeat('d', 32)], $now->subHours(48));

        $check = $this->makeCheck(
            goldenReport: fn (): array => $this->syntheticGoldenReport(0.80, 0),
            now: $now,
        );

        $result = $check->run();
        $this->assertSame(1, $result->evidence['flows_checked']);
    }

    #[Test]
    public function non_canonical_refs_drop_ref_stability_and_trigger_alert(): void
    {
        $now = CarbonImmutable::parse('2026-07-12T12:00:00Z');
        // 20 canonical refs + 5 non-canonical (title-only legacy form) ⇒ stability=0.80 < 0.95 floor.
        $refs = [];
        for ($i = 0; $i < 20; $i++) {
            $refs[] = 'memory:'.substr(str_pad(dechex($i + 1), 32, '0', STR_PAD_LEFT).str_repeat('a', 32), 0, 32);
        }
        for ($i = 0; $i < 5; $i++) {
            $refs[] = 'Some Legacy Ref '.$i;
        }
        $this->seedRow($refs, $now->subHour());

        $check = $this->makeCheck(
            goldenReport: fn (): array => $this->syntheticGoldenReport(0.80, 0),
            now: $now,
        );

        $result = $check->run();
        $this->assertSame('alert', $result->status);
        $this->assertContains('ref_stability', array_column((array) ($result->alert['violations'] ?? []), 'metric'));
        $this->assertSame(20, $result->evidence['refs_canonical']);
        $this->assertSame(25, $result->evidence['refs_total']);
        $this->assertSame(0.80, $result->evidence['ref_stability']);
    }

    #[Test]
    public function golden_recall_below_floor_triggers_alert(): void
    {
        $now = CarbonImmutable::parse('2026-07-12T12:00:00Z');
        $this->seedRow(['memory:'.str_repeat('e', 32)], $now->subHour());

        $check = $this->makeCheck(
            goldenReport: fn (): array => $this->syntheticGoldenReport(0.20, 0),
            now: $now,
        );

        $result = $check->run();
        $this->assertSame('alert', $result->status);
        $violations = array_column((array) ($result->alert['violations'] ?? []), 'metric');
        $this->assertContains('golden_recall_at_5', $violations);
    }

    #[Test]
    public function improper_floor_discards_greater_than_zero_triggers_alert(): void
    {
        $now = CarbonImmutable::parse('2026-07-12T12:00:00Z');
        $this->seedRow(['memory:'.str_repeat('e', 32)], $now->subHour());

        $check = $this->makeCheck(
            goldenReport: fn (): array => $this->syntheticGoldenReport(0.90, 2),
            now: $now,
        );

        $result = $check->run();
        $this->assertSame('alert', $result->status);
        $violations = array_column((array) ($result->alert['violations'] ?? []), 'metric');
        $this->assertContains('improper_floor_discards', $violations);
    }

    #[Test]
    public function ok_when_all_signals_within_floors(): void
    {
        $now = CarbonImmutable::parse('2026-07-12T12:00:00Z');
        for ($i = 0; $i < 5; $i++) {
            $this->seedRow([
                'code:'.str_repeat(dechex($i + 1), 32),
                'memory:'.str_repeat(dechex($i + 9), 32),
            ], $now->subHours($i + 1));
        }

        $check = $this->makeCheck(
            goldenReport: fn (): array => $this->syntheticGoldenReport(0.85, 0),
            now: $now,
        );

        $result = $check->run();
        $this->assertSame('ok', $result->status);
        $this->assertSame(5, $result->evidence['flows_checked']);
        $this->assertSame(1.0, $result->evidence['ref_stability']);
    }

    #[Test]
    public function evidence_never_carries_raw_query_or_context_keys(): void
    {
        $now = CarbonImmutable::parse('2026-07-12T12:00:00Z');
        $this->seedRow(['memory:'.str_repeat('e', 32)], $now->subHour());

        $check = $this->makeCheck(
            goldenReport: fn (): array => $this->syntheticGoldenReport(0.85, 0),
            now: $now,
        );

        $result = $check->run();
        $serialized = json_encode($result->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertIsString($serialized);

        // The pack's raw markdown/context was NEVER persisted in the ledger, so it
        // cannot leak here. The check asserts the invariant on every run — this
        // regression test flags any future evidence key that would smell like
        // raw content.
        $keys = $this->flattenKeys($result->evidence);
        $forbidden = preg_grep(DailyCanaryReplayByRefsWatchdogCheck::FORBIDDEN_EVIDENCE_KEY_PATTERN, $keys);
        $this->assertSame([], (array) $forbidden, 'MAXG-06 evidence key would leak raw content: '.implode(',', (array) $forbidden));
    }

    #[Test]
    public function only_v2_report_shape_is_consumed_when_present(): void
    {
        $now = CarbonImmutable::parse('2026-07-12T12:00:00Z');
        $this->seedRow(['memory:'.str_repeat('e', 32)], $now->subHour());

        // Simulate a live report that carries both v1 and v2; v2 must win.
        $report = [
            'memory_recall_golden_versions' => [
                'v1' => ['recall_at_5' => 0.10, 'improper_floor_discards' => 3, 'status' => 'attention'],
                'v2' => ['recall_at_5' => 0.90, 'improper_floor_discards' => 0, 'status' => 'passed'],
            ],
        ];
        $check = $this->makeCheck(goldenReport: fn (): array => $report, now: $now);
        $result = $check->run();

        $this->assertSame('v2', $result->evidence['golden_version']);
        $this->assertSame(0.90, $result->evidence['golden_recall_at_5']);
        $this->assertSame(0, $result->evidence['improper_floor_discards']);
        $this->assertSame('ok', $result->status);
    }

    /**
     * @param  callable():(array<string,mixed>|null)  $goldenReport
     */
    private function makeCheck(callable $goldenReport, ?CarbonImmutable $now = null): DailyCanaryReplayByRefsWatchdogCheck
    {
        return new DailyCanaryReplayByRefsWatchdogCheck(
            deliveredPackLedger: new AtlasDeliveredPackLedger($this->ledgerPath),
            benchmark: null,
            windowHours: DailyCanaryReplayByRefsWatchdogCheck::DEFAULT_WINDOW_HOURS,
            topNFlows: DailyCanaryReplayByRefsWatchdogCheck::DEFAULT_TOP_N_FLOWS,
            goldenReportProvider: $goldenReport,
            nowProvider: $now === null ? null : static fn (): CarbonImmutable => $now,
        );
    }

    /**
     * MAXG-06 canary only needs the `delivered_refs` + `ts` columns of the ledger,
     * so we write the JSONL row directly instead of round-tripping through
     * `AtlasDeliveredPackLedger::record()` (which would re-derive refs from the
     * pack shape and hide the non-canonical drift case). The row schema matches
     * `AtlasDeliveredPackLedger::SCHEMA` exactly.
     *
     * @param  list<string>  $refs
     */
    private function seedRow(array $refs, CarbonImmutable $ts): void
    {
        $entry = [
            'schema' => AtlasDeliveredPackLedger::SCHEMA,
            'context_pack_hash' => hash('sha256', implode('|', $refs).$ts->toIso8601String()),
            'delivered_refs' => array_values($refs),
            'delivered_chars' => 0,
            'budgets' => [],
            'policy_snapshot' => [],
            'timings_ms' => [],
            'cache' => [],
            'ts' => $ts->toIso8601String(),
        ];

        $dir = dirname($this->ledgerPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents(
            $this->ledgerPath,
            json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL,
            FILE_APPEND,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function syntheticGoldenReport(float $recallAt5, int $improperFloorDiscards): array
    {
        return [
            'memory_recall_golden_versions' => [
                'v2' => [
                    'recall_at_5' => $recallAt5,
                    'improper_floor_discards' => $improperFloorDiscards,
                    'status' => $recallAt5 >= 0.80 ? 'passed' : 'attention',
                ],
            ],
        ];
    }

    /**
     * @param  array<string|int,mixed>  $data
     * @return list<string>
     */
    private function flattenKeys(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $out[] = $prefix === '' ? $key : $prefix.'.'.$key;
                if (is_array($value)) {
                    $out = array_merge($out, $this->flattenKeys($value, $prefix === '' ? $key : $prefix.'.'.$key));
                }
            } elseif (is_array($value)) {
                $out = array_merge($out, $this->flattenKeys($value, $prefix));
            }
        }

        return $out;
    }
}
