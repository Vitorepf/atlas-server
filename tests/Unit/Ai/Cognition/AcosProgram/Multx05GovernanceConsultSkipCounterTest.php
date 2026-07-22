<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Cognition\AcosProgram;

use App\Services\Ai\Governance\GovernanceConsultSkipCounter;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * MULTX-05 (partial, no enforce flip) — governance_consult_skipped counter.
 *
 * The full frontier plan §2836-2840 flip observe→enforce is NOT part of this
 * slice. What IS proven here:
 *
 *   - The counter appends provider-safe rows (schema pinned, no prompt/context)
 *     — the acceptance clause "counter exposed" (part b) of the plan.
 *   - `report(windowHours)` returns a denominator broken by surface, executor,
 *     provider and reason — matches "denominador honesto" ELEV-09.
 *   - Rows outside the window are dropped from `report`.
 *   - Unknown reasons are collapsed to the pinned enum (never fabricates a new
 *     reason that would silently create a new bucket).
 *   - Sanitizer caps identifier length + collapses empty to `unknown` — never
 *     lets a huge free-text string in through the surface/executor/provider
 *     dimensions.
 */
final class Multx05GovernanceConsultSkipCounterTest extends TestCase
{
    private string $tempPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempPath = sys_get_temp_dir().'/multx05-consult-skip-'.uniqid('', true).'.jsonl';
    }

    protected function tearDown(): void
    {
        if (is_file($this->tempPath)) {
            @unlink($this->tempPath);
        }
        parent::tearDown();
    }

    #[Test]
    public function schema_version_and_reason_enum_are_pinned(): void
    {
        $this->assertSame('atlas.governance.consult_skipped.v1', GovernanceConsultSkipCounter::SCHEMA_VERSION);
        $this->assertSame([
            GovernanceConsultSkipCounter::REASON_SEAM_UNBOUND,
            GovernanceConsultSkipCounter::REASON_METHOD_MISSING,
            GovernanceConsultSkipCounter::REASON_CONTAINER_ABSENT,
            GovernanceConsultSkipCounter::REASON_SEAM_THREW,
        ], GovernanceConsultSkipCounter::REASONS);
    }

    #[Test]
    public function record_appends_provider_safe_row(): void
    {
        $counter = new GovernanceConsultSkipCounter($this->tempPath);
        $counter->record([
            'surface' => 'dev-claude-gateway',
            'executor' => 'dev',
            'provider' => 'claude_cli',
            'reason' => GovernanceConsultSkipCounter::REASON_SEAM_UNBOUND,
            'ts' => '2026-07-12T10:00:00+00:00',
        ]);

        $lines = file($this->tempPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertCount(1, $lines);

        $row = json_decode((string) $lines[0], true);
        $this->assertSame('atlas.governance.consult_skipped.v1', $row['schema_version']);
        $this->assertSame('dev-claude-gateway', $row['surface']);
        $this->assertSame('dev', $row['executor']);
        $this->assertSame('claude_cli', $row['provider']);
        $this->assertSame('seam_unbound', $row['reason']);
        $this->assertSame('2026-07-12T10:00:00+00:00', $row['ts']);

        // Provider-safe: no `prompt`, `context`, `body`, `markdown`, `text`, `raw`.
        foreach (['prompt', 'context', 'body', 'markdown', 'text', 'raw'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $row);
        }
    }

    #[Test]
    public function unknown_reason_is_collapsed_to_seam_unbound(): void
    {
        $counter = new GovernanceConsultSkipCounter($this->tempPath);
        $counter->record([
            'surface' => 'x',
            'executor' => 'y',
            'provider' => 'z',
            'reason' => 'not-a-real-reason',
        ]);

        $row = json_decode(trim((string) file_get_contents($this->tempPath)), true);
        $this->assertSame('seam_unbound', $row['reason']);
    }

    #[Test]
    public function report_aggregates_by_dimension_within_window(): void
    {
        $counter = new GovernanceConsultSkipCounter($this->tempPath);
        $now = CarbonImmutable::parse('2026-07-12T12:00:00Z');

        // Inside window (last hour).
        $counter->record(['surface' => 'dev', 'executor' => 'dev', 'provider' => 'claude_cli', 'reason' => 'seam_unbound', 'ts' => $now->subMinutes(30)->toIso8601String()]);
        $counter->record(['surface' => 'dev', 'executor' => 'dev', 'provider' => 'claude_cli', 'reason' => 'consult_method_missing', 'ts' => $now->subMinutes(20)->toIso8601String()]);
        $counter->record(['surface' => 'forge', 'executor' => 'forge', 'provider' => 'codex_cli', 'reason' => 'container_absent', 'ts' => $now->subMinutes(10)->toIso8601String()]);
        // Outside window.
        $counter->record(['surface' => 'dev', 'executor' => 'dev', 'provider' => 'claude_cli', 'reason' => 'seam_threw', 'ts' => $now->subDays(3)->toIso8601String()]);

        $report = $counter->report(24, $now);
        $this->assertSame('atlas.governance.consult_skipped.v1', $report['schema_version']);
        $this->assertSame(24, $report['window_hours']);
        $this->assertSame(3, $report['total']);
        $this->assertSame(['dev' => 2, 'forge' => 1], $report['by_surface']);
        $this->assertSame(['dev' => 2, 'forge' => 1], $report['by_executor']);
        $this->assertSame(['claude_cli' => 2, 'codex_cli' => 1], $report['by_provider']);
        $this->assertSame([
            'consult_method_missing' => 1,
            'container_absent' => 1,
            'seam_unbound' => 1,
        ], $report['by_reason']);
    }

    #[Test]
    public function empty_ledger_returns_zero_report(): void
    {
        $counter = new GovernanceConsultSkipCounter($this->tempPath);
        $now = CarbonImmutable::parse('2026-07-12T12:00:00Z');
        $report = $counter->report(24, $now);

        $this->assertSame(0, $report['total']);
        $this->assertSame([], $report['by_surface']);
        $this->assertSame([], $report['by_executor']);
        $this->assertSame([], $report['by_reason']);
    }

    #[Test]
    public function sanitize_caps_length_and_replaces_empty_with_unknown(): void
    {
        $counter = new GovernanceConsultSkipCounter($this->tempPath);
        $counter->record([
            'surface' => str_repeat('A', 500),
            'executor' => '',
            'provider' => '   ',
            'reason' => GovernanceConsultSkipCounter::REASON_SEAM_UNBOUND,
        ]);

        $row = json_decode(trim((string) file_get_contents($this->tempPath)), true);
        $this->assertSame(96, strlen($row['surface']));
        $this->assertSame('unknown', $row['executor']);
        $this->assertSame('unknown', $row['provider']);
    }

    #[Test]
    public function report_never_creates_a_new_reason_bucket_for_unknowns(): void
    {
        $counter = new GovernanceConsultSkipCounter($this->tempPath);
        $now = CarbonImmutable::parse('2026-07-12T12:00:00Z');

        $counter->record(['surface' => 'x', 'executor' => 'y', 'provider' => 'z', 'reason' => 'made-up-reason', 'ts' => $now->subMinute()->toIso8601String()]);

        $report = $counter->report(24, $now);
        $this->assertSame(['seam_unbound' => 1], $report['by_reason']);
    }
}
