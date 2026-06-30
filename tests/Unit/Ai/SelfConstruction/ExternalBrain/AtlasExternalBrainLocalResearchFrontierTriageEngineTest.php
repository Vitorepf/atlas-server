<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainLocalResearchFrontierTriageEngine;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainLocalResearchFrontierTriageEngineTest extends TestCase
{
    private function engine(): AtlasExternalBrainLocalResearchFrontierTriageEngine
    {
        return new AtlasExternalBrainLocalResearchFrontierTriageEngine;
    }

    private function row(array $overrides = []): array
    {
        return array_merge([
            'id'               => 'r1',
            'title'            => 'Test paper',
            'evidence_strength' => 0.8,
            'has_code'         => true,
            'has_benchmark'    => true,
            'hype_signals'     => [],
        ], $overrides);
    }

    // ── Output shape ──────────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->engine()->triage([]);
        $this->assertSame(AtlasExternalBrainLocalResearchFrontierTriageEngine::SCHEMA, $r['schema_version']);
        $this->assertArrayHasKey('promising', $r);
        $this->assertArrayHasKey('exploratory', $r);
        $this->assertArrayHasKey('hype_rejected', $r);
        $this->assertArrayHasKey('ungrounded_rejected', $r);
        $this->assertArrayHasKey('promising_count', $r);
    }

    public function test_empty_rows_yields_zero_counts(): void
    {
        $r = $this->engine()->triage(['frontier_rows' => []]);
        $this->assertSame(0, $r['promising_count']);
        $this->assertSame(0, $r['exploratory_count']);
        $this->assertSame(0, $r['hype_rejected_count']);
        $this->assertSame(0, $r['ungrounded_rejected_count']);
    }

    // ── Promising ─────────────────────────────────────────────────────────────

    public function test_high_evidence_with_code_is_promising(): void
    {
        $r = $this->engine()->triage([
            'frontier_rows' => [$this->row(['evidence_strength' => 0.90, 'has_code' => true])],
        ]);
        $this->assertSame(1, $r['promising_count']);
        $this->assertEmpty($r['exploratory']);
    }

    public function test_high_evidence_with_benchmark_but_no_code_is_promising(): void
    {
        $r = $this->engine()->triage([
            'frontier_rows' => [$this->row(['evidence_strength' => 0.75, 'has_code' => false, 'has_benchmark' => true])],
        ]);
        $this->assertSame(1, $r['promising_count']);
    }

    public function test_high_evidence_but_no_code_no_benchmark_is_exploratory(): void
    {
        $r = $this->engine()->triage([
            'frontier_rows' => [$this->row(['evidence_strength' => 0.80, 'has_code' => false, 'has_benchmark' => false])],
        ]);
        $this->assertSame(0, $r['promising_count']);
        $this->assertSame(1, $r['exploratory_count']);
    }

    // ── Exploratory ───────────────────────────────────────────────────────────

    public function test_moderate_evidence_with_code_is_exploratory(): void
    {
        $r = $this->engine()->triage([
            'frontier_rows' => [$this->row(['evidence_strength' => 0.55, 'has_code' => true])],
        ]);
        $this->assertSame(1, $r['exploratory_count']);
        $this->assertSame(0, $r['promising_count']);
    }

    public function test_moderate_evidence_without_code_above_ungrounded_cap_is_exploratory(): void
    {
        // evidence 0.35 ≥ UNGROUNDED_CAP(0.30), no code, no benchmark → exploratory (not rejected).
        $r = $this->engine()->triage([
            'frontier_rows' => [$this->row(['evidence_strength' => 0.35, 'has_code' => false, 'has_benchmark' => false])],
        ]);
        $this->assertSame(1, $r['exploratory_count']);
        $this->assertSame(0, $r['ungrounded_rejected_count']);
    }

    // ── AC2: hype rejection ───────────────────────────────────────────────────

    public function test_two_hype_signals_with_low_evidence_is_hype_rejected(): void
    {
        $r = $this->engine()->triage([
            'frontier_rows' => [$this->row([
                'evidence_strength' => 0.2,
                'hype_signals'      => ['revolutionary', 'paradigm-shifting'],
            ])],
        ]);
        $this->assertSame(1, $r['hype_rejected_count']);
        $this->assertSame('hype_signals_with_low_evidence', $r['hype_rejected'][0]['reason']);
    }

    public function test_many_hype_signals_but_high_evidence_is_not_hype_rejected(): void
    {
        // evidence >= 0.40 threshold → survives hype check.
        $r = $this->engine()->triage([
            'frontier_rows' => [$this->row([
                'evidence_strength' => 0.50,
                'has_code'          => true,
                'hype_signals'      => ['revolutionary', 'breakthrough', 'paradigm'],
            ])],
        ]);
        $this->assertSame(0, $r['hype_rejected_count']);
    }

    public function test_one_hype_signal_does_not_trigger_hype_rejection(): void
    {
        // < HYPE_SIGNAL_MIN(2) → not rejected for hype.
        $r = $this->engine()->triage([
            'frontier_rows' => [$this->row([
                'evidence_strength' => 0.1,
                'hype_signals'      => ['revolutionary'],
                'has_code'          => false,
                'has_benchmark'     => false,
            ])],
        ]);
        $this->assertSame(0, $r['hype_rejected_count']);
        $this->assertSame(1, $r['ungrounded_rejected_count']); // falls to ungrounded
    }

    // ── AC2: ungrounded rejection ─────────────────────────────────────────────

    public function test_no_code_no_benchmark_low_evidence_is_ungrounded_rejected(): void
    {
        $r = $this->engine()->triage([
            'frontier_rows' => [$this->row([
                'evidence_strength' => 0.20,
                'has_code'          => false,
                'has_benchmark'     => false,
                'hype_signals'      => [],
            ])],
        ]);
        $this->assertSame(1, $r['ungrounded_rejected_count']);
        $this->assertSame('no_code_no_benchmark_low_evidence', $r['ungrounded_rejected'][0]['reason']);
    }

    public function test_hype_check_runs_before_ungrounded_check(): void
    {
        // 2 hype signals, evidence 0.1, no code → hype wins, not ungrounded.
        $r = $this->engine()->triage([
            'frontier_rows' => [$this->row([
                'evidence_strength' => 0.10,
                'has_code'          => false,
                'has_benchmark'     => false,
                'hype_signals'      => ['revolutionary', 'paradigm-shifting'],
            ])],
        ]);
        $this->assertSame(1, $r['hype_rejected_count']);
        $this->assertSame(0, $r['ungrounded_rejected_count']);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $facts = ['frontier_rows' => [
            $this->row(['id' => 'a', 'evidence_strength' => 0.9]),
            $this->row(['id' => 'b', 'evidence_strength' => 0.1, 'has_code' => false, 'has_benchmark' => false]),
        ]];
        $a = $this->engine()->triage($facts);
        $b = $this->engine()->triage($facts);
        $this->assertSame(json_encode($a), json_encode($b));
    }
}
