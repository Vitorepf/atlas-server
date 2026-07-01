<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Governance;

use App\Services\Ai\SelfConstruction\Governance\AtlasTaskGovernanceObserveDossier;
use App\Services\Ai\SelfConstruction\VerificationCourt\AtlasVerificationCourtVerdictLedger;
use PHPUnit\Framework\TestCase;

final class AtlasTaskGovernanceObserveDossierTest extends TestCase
{
    private string $ledgerPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerPath = sys_get_temp_dir().'/atlas-gov-dossier-'.bin2hex(random_bytes(6)).'.jsonl';
    }

    protected function tearDown(): void
    {
        if (is_file($this->ledgerPath)) {
            unlink($this->ledgerPath);
        }
        parent::tearDown();
    }

    private function ledger(): AtlasVerificationCourtVerdictLedger
    {
        return new AtlasVerificationCourtVerdictLedger($this->ledgerPath);
    }

    private function dossier(): AtlasTaskGovernanceObserveDossier
    {
        return new AtlasTaskGovernanceObserveDossier($this->ledgerPath);
    }

    private function seedVerdict(
        string $taskId,
        string $verdict,
        array $reasons,
        string $decidedAt,
        string $riskLevel = 'medium',
    ): void {
        $reasons = array_merge($reasons, ["risk_level:{$riskLevel}"]);
        $this->ledger()->append([
            'task_packet_id' => $taskId,
            'evidence_hash' => hash('sha256', $taskId.$verdict.$decidedAt),
            'replay_plan_hash' => hash('sha256', 'plan-'.$taskId),
            'verdict' => $verdict,
            'reasons' => $reasons,
            'replay_outcome_hash' => hash('sha256', 'outcome-'.$taskId),
            'decided_at' => $decidedAt,
        ]);
    }

    public function test_schema_present(): void
    {
        $result = $this->dossier()->compile();

        $this->assertSame(AtlasTaskGovernanceObserveDossier::SCHEMA, $result['schema']);
    }

    public function test_empty_ledger_yields_zero_counts(): void
    {
        $result = $this->dossier()->compile();

        $this->assertSame(0, $result['total_verdicts']);
        $this->assertSame(0, $result['would_block_count']);
        $this->assertSame(0.0, $result['would_block_rate']);
        $this->assertSame([], $result['blockers_by_reason']);
        $this->assertSame([], $result['verdicts_by_risk']);
        $this->assertSame([], $result['top_blocked_tasks']);
    }

    // ── counts + rate math ─────────────────────────────────────────────────

    public function test_counts_and_rate_math_are_correct(): void
    {
        $this->seedVerdict('t-1', 'passed', [], '2026-01-01T00:00:00Z');
        $this->seedVerdict('t-2', 'passed', [], '2026-01-01T00:01:00Z');
        $this->seedVerdict('t-3', 'blocked', ['reason_a'], '2026-01-01T00:02:00Z');
        $this->seedVerdict('t-4', 'failed', ['reason_b'], '2026-01-01T00:03:00Z');

        $result = $this->dossier()->compile();

        $this->assertSame(4, $result['total_verdicts']);
        $this->assertSame(2, $result['would_block_count']);
        $this->assertEqualsWithDelta(0.5, $result['would_block_rate'], 0.0001);
    }

    // ── per-reason blocker grouping ──────────────────────────────────────────

    public function test_blockers_by_reason_groups_and_counts_correctly(): void
    {
        $this->seedVerdict('t-1', 'blocked', ['reason_a'], '2026-01-01T00:00:00Z');
        $this->seedVerdict('t-2', 'blocked', ['reason_a'], '2026-01-01T00:01:00Z');
        $this->seedVerdict('t-3', 'failed', ['reason_b'], '2026-01-01T00:02:00Z');
        $this->seedVerdict('t-4', 'passed', [], '2026-01-01T00:03:00Z');

        $result = $this->dossier()->compile();
        $byReason = array_column($result['blockers_by_reason'], 'count', 'reason');

        $this->assertSame(2, $byReason['reason_a']);
        $this->assertSame(1, $byReason['reason_b']);
        // Admitted (passed) verdicts never contribute reasons to the blocker report.
        $this->assertArrayNotHasKey('risk_level:medium', $byReason);
    }

    public function test_blockers_by_reason_is_sorted_by_count_descending(): void
    {
        $this->seedVerdict('t-1', 'blocked', ['rare_reason'], '2026-01-01T00:00:00Z');
        $this->seedVerdict('t-2', 'blocked', ['common_reason'], '2026-01-01T00:01:00Z');
        $this->seedVerdict('t-3', 'blocked', ['common_reason'], '2026-01-01T00:02:00Z');

        $result = $this->dossier()->compile();

        $this->assertSame('common_reason', $result['blockers_by_reason'][0]['reason']);
        $this->assertSame(2, $result['blockers_by_reason'][0]['count']);
    }

    // ── since filter ──────────────────────────────────────────────────────────

    public function test_since_filter_excludes_earlier_verdicts(): void
    {
        $this->seedVerdict('t-old', 'blocked', ['reason_a'], '2026-01-01T00:00:00Z');
        $this->seedVerdict('t-new', 'blocked', ['reason_b'], '2026-01-03T00:00:00Z');

        $result = $this->dossier()->compile('2026-01-02T00:00:00Z');

        $this->assertSame(1, $result['total_verdicts']);
        $this->assertSame(['t-new'], array_column($result['top_blocked_tasks'], 'task_packet_id'));
    }

    public function test_empty_since_includes_full_ledger(): void
    {
        $this->seedVerdict('t-1', 'passed', [], '2020-01-01T00:00:00Z');
        $this->seedVerdict('t-2', 'passed', [], '2026-01-01T00:00:00Z');

        $result = $this->dossier()->compile('');

        $this->assertSame(2, $result['total_verdicts']);
    }

    // ── top_blocked_tasks ─────────────────────────────────────────────────────

    public function test_top_blocked_tasks_aggregates_per_task(): void
    {
        $this->seedVerdict('t-repeat', 'blocked', ['reason_a'], '2026-01-01T00:00:00Z');
        $this->seedVerdict('t-repeat', 'failed', ['reason_b'], '2026-01-01T00:01:00Z');
        $this->seedVerdict('t-once', 'blocked', ['reason_a'], '2026-01-01T00:02:00Z');

        $result = $this->dossier()->compile();
        $byTask = array_column($result['top_blocked_tasks'], 'would_block_count', 'task_packet_id');

        $this->assertSame(2, $byTask['t-repeat']);
        $this->assertSame(1, $byTask['t-once']);
    }

    // ── verdicts_by_risk + arming_recommendation flips at threshold ──────────

    public function test_arming_recommendation_is_ready_when_would_block_rate_is_low(): void
    {
        // 1/20 blocked = 5% < 10% threshold -> ready
        for ($i = 0; $i < 19; $i++) {
            $this->seedVerdict("t-ok-{$i}", 'passed', [], "2026-01-01T00:00:{$this->pad($i)}Z", 'high');
        }
        $this->seedVerdict('t-bad', 'blocked', ['reason_a'], '2026-01-01T00:00:19Z', 'high');

        $result = $this->dossier()->compile();

        $this->assertSame('ready', $result['arming_recommendation']['high']['status']);
        $this->assertSame(20, $result['verdicts_by_risk']['high']['total']);
        $this->assertSame(1, $result['verdicts_by_risk']['high']['would_block']);
    }

    public function test_arming_recommendation_is_hold_when_would_block_rate_is_high(): void
    {
        // 3/10 blocked = 30% > 10% threshold -> hold
        for ($i = 0; $i < 7; $i++) {
            $this->seedVerdict("t-ok-{$i}", 'passed', [], "2026-01-01T00:00:{$this->pad($i)}Z", 'low');
        }
        $this->seedVerdict('t-bad-1', 'blocked', ['reason_a'], '2026-01-01T00:00:07Z', 'low');
        $this->seedVerdict('t-bad-2', 'blocked', ['reason_a'], '2026-01-01T00:00:08Z', 'low');
        $this->seedVerdict('t-bad-3', 'failed', ['reason_b'], '2026-01-01T00:00:09Z', 'low');

        $result = $this->dossier()->compile();

        $this->assertSame('hold', $result['arming_recommendation']['low']['status']);
        $this->assertEqualsWithDelta(0.3, $result['arming_recommendation']['low']['would_block_rate'], 0.0001);
    }

    public function test_verdicts_by_risk_separates_risk_levels_independently(): void
    {
        $this->seedVerdict('t-1', 'blocked', ['reason_a'], '2026-01-01T00:00:00Z', 'critical');
        $this->seedVerdict('t-2', 'passed', [], '2026-01-01T00:00:01Z', 'low');

        $result = $this->dossier()->compile();

        $this->assertSame(1, $result['verdicts_by_risk']['critical']['total']);
        $this->assertSame(1, $result['verdicts_by_risk']['critical']['would_block']);
        $this->assertSame(1, $result['verdicts_by_risk']['low']['total']);
        $this->assertSame(0, $result['verdicts_by_risk']['low']['would_block']);
    }

    private function pad(int $i): string
    {
        return str_pad((string) $i, 2, '0', STR_PAD_LEFT);
    }
}
