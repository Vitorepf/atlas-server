<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainFrontierExhaustionEscalator;
use Tests\TestCase;

final class AtlasExternalBrainFrontierExhaustionEscalatorTest extends TestCase
{
    private function svc(): AtlasExternalBrainFrontierExhaustionEscalator
    {
        return new AtlasExternalBrainFrontierExhaustionEscalator;
    }

    private function thinWave(int $verified, int $dupes, float $cost, string $surface = 'scanned', ?string $leads = null, ?string $yield = null, ?string $whyNot = null): array
    {
        $w = ['verified_findings' => $verified, 'duplicate_findings' => $dupes, 'token_cost' => $cost, 'inspected_surface' => $surface];
        if ($leads !== null) {
            $w['rejected_false_leads'] = $leads;
        }
        if ($yield !== null) {
            $w['expected_yield_range'] = $yield;
        }
        if ($whyNot !== null) {
            $w['why_not_stop'] = $whyNot;
        }

        return $w;
    }

    // ── never template_farm ───────────────────────────────────────────────────

    public function test_never_returns_template_farm_from_any_context(): void
    {
        foreach (AtlasExternalBrainFrontierExhaustionEscalator::PASS_LADDER as $pass) {
            $r = $this->svc()->escalate(
                [$this->thinWave(10, 8, 200), $this->thinWave(3, 7, 400)],
                ['current_pass' => $pass, 'remaining_high_risk_domains' => ['auth', 'payments']],
            );
            $this->assertNotSame('template_farm', $r['next_pass'], "next_pass must never be template_farm (from pass={$pass})");
        }

        // explicit forbidden pass as current_pass is sanitised
        $r = $this->svc()->escalate(
            [$this->thinWave(5, 6, 100), $this->thinWave(1, 9, 300)],
            ['current_pass' => 'template_farm'],
        );
        $this->assertNotSame('template_farm', $r['next_pass']);
    }

    // ── thinning escalation ───────────────────────────────────────────────────

    public function test_thin_surface_escalates_from_bug_hunt_to_compression(): void
    {
        // wave 1: many findings, low cost; wave 2: fewer findings, higher cost, many dupes
        $waves = [
            $this->thinWave(20, 2, 100),
            $this->thinWave(5, 10, 300),
        ];

        $r = $this->svc()->escalate($waves, ['current_pass' => 'bug_hunt']);

        $this->assertSame('compression', $r['next_pass']);
        $this->assertStringContainsString('declining_verified_findings', $r['escalation_reason']);
        $this->assertStringContainsString('rising_token_cost', $r['escalation_reason']);
        $this->assertStringContainsString('high_duplicate_rate', $r['escalation_reason']);
    }

    public function test_escalates_each_rung_in_order(): void
    {
        // Each wave carries the full evidence floor for every prior pass, so
        // escalation is never blocked by a missing-evidence gate — this test
        // proves rung ordering, not evidence gating (see test_evidence_floor_gates_advancement).
        $waves = [
            $this->thinWave(20, 2, 100, 'scanned', '5 leads', '2-4 findings', 'more surface remains'),
            $this->thinWave(5, 10, 300, 'scanned', '5 leads', '2-4 findings', 'more surface remains'),
        ];
        $ctx = ['remaining_high_risk_domains' => []];

        $expected = [
            'bug_hunt' => 'compression',
            'compression' => 'research_transfer',
            'research_transfer' => 'contract_mismatch_deepening',
            'contract_mismatch_deepening' => 'stop_with_evidence',
            'stop_with_evidence' => 'stop_with_evidence', // last rung stays
        ];

        foreach ($expected as $current => $expectedNext) {
            $r = $this->svc()->escalate($waves, array_merge($ctx, ['current_pass' => $current]));
            $this->assertSame($expectedNext, $r['next_pass'], "from {$current} expected {$expectedNext}");
        }
    }

    public function test_evidence_floor_gates_advancement_even_when_surface_thinning(): void
    {
        // Thinning signals fire, but the current pass's own evidence floor
        // (rejected_false_leads for compression) is missing — must not advance.
        $waves = [
            $this->thinWave(20, 2, 100),
            $this->thinWave(5, 10, 300), // no rejected_false_leads
        ];

        $r = $this->svc()->escalate($waves, ['current_pass' => 'compression']);

        $this->assertSame('compression', $r['next_pass']);
        $this->assertFalse($r['evidence_floor_satisfied']);
    }

    // ── stable surface stays ──────────────────────────────────────────────────

    public function test_stable_surface_stays_on_current_pass(): void
    {
        // wave 2 has MORE findings and LOWER cost → not thin
        $waves = [
            $this->thinWave(5, 1, 300),
            $this->thinWave(12, 1, 200),
        ];

        $r = $this->svc()->escalate($waves, ['current_pass' => 'bug_hunt']);

        $this->assertSame('bug_hunt', $r['next_pass']);
        $this->assertSame('surface_stable', $r['escalation_reason']);
    }

    // ── remaining high-risk domains ───────────────────────────────────────────

    public function test_remaining_high_risk_domains_triggers_escalation_when_findings_declining(): void
    {
        $waves = [
            $this->thinWave(15, 1, 100),
            $this->thinWave(4, 1, 100), // declining only, no cost rise, no dupes
        ];

        $r = $this->svc()->escalate($waves, [
            'current_pass' => 'bug_hunt',
            'remaining_high_risk_domains' => ['auth', 'payments'],
        ]);

        $this->assertSame('compression', $r['next_pass']);
        $this->assertStringContainsString('remaining_high_risk_domains', $r['escalation_reason']);
    }

    // ── evidence floor ────────────────────────────────────────────────────────

    public function test_evidence_floor_grows_stronger_per_pass(): void
    {
        $floors = AtlasExternalBrainFrontierExhaustionEscalator::EVIDENCE_FLOOR_BY_PASS;

        // each later pass must have a superset of the previous pass's floor
        $prev = [];
        foreach (AtlasExternalBrainFrontierExhaustionEscalator::PASS_LADDER as $pass) {
            $floor = $floors[$pass];
            foreach ($prev as $field) {
                $this->assertContains($field, $floor, "pass={$pass} must require {$field} (cumulative floor)");
            }
            $prev = $floor;
        }

        // specific increments
        $this->assertContains('rejected_false_leads', $floors['compression']);
        $this->assertNotContains('rejected_false_leads', $floors['bug_hunt']);
        $this->assertContains('expected_yield_range', $floors['research_transfer']);
        $this->assertContains('why_not_stop', $floors['contract_mismatch_deepening']);
        $this->assertContains('why_not_stop', $floors['stop_with_evidence']);
    }

    public function test_missing_evidence_floor_is_reported_in_missing_evidence(): void
    {
        // current_pass=compression requires inspected_surface + rejected_false_leads
        // wave has only inspected_surface
        $waves = [$this->thinWave(5, 1, 100)]; // no rejected_false_leads

        $r = $this->svc()->escalate($waves, ['current_pass' => 'compression']);

        $this->assertContains('rejected_false_leads', $r['missing_evidence']);
        $this->assertFalse($r['evidence_floor_satisfied']);
    }

    public function test_evidence_floor_satisfied_when_all_required_fields_present(): void
    {
        $waves = [$this->thinWave(5, 1, 100, 'scanned', '5 leads')]; // has rejected_false_leads

        $r = $this->svc()->escalate($waves, ['current_pass' => 'compression']);

        $this->assertNotContains('rejected_false_leads', $r['missing_evidence']);
        $this->assertTrue($r['evidence_floor_satisfied']);
    }

    // ── wave analysis ─────────────────────────────────────────────────────────

    public function test_wave_analysis_reflects_thinning_signals(): void
    {
        $waves = [
            $this->thinWave(20, 2, 100),
            $this->thinWave(5, 10, 300),
        ];

        $r = $this->svc()->escalate($waves);

        $wa = $r['wave_analysis'];
        $this->assertSame(2, $wa['wave_count']);
        $this->assertTrue($wa['declining_findings']);
        $this->assertTrue($wa['rising_cost']);
        $this->assertTrue($wa['high_duplicate_rate']);
    }

    public function test_schema_version_and_current_pass_are_present(): void
    {
        $r = $this->svc()->escalate([], ['current_pass' => 'research_transfer']);

        $this->assertSame(AtlasExternalBrainFrontierExhaustionEscalator::SCHEMA, $r['schema_version']);
        $this->assertSame('research_transfer', $r['current_pass']);
    }

    public function test_single_wave_cannot_trigger_declining_or_rising_signals(): void
    {
        $r = $this->svc()->escalate(
            [$this->thinWave(3, 10, 500)],
            ['current_pass' => 'bug_hunt'],
        );

        $this->assertFalse($r['wave_analysis']['declining_findings']);
        $this->assertFalse($r['wave_analysis']['rising_cost']);
        // high_duplicate_rate can still trigger from a single wave (dupes vs total)
        $this->assertTrue($r['wave_analysis']['high_duplicate_rate']);
        // a single strong thinning signal (high duplicates alone) is enough to escalate
        $this->assertSame('compression', $r['next_pass']);
    }

    // ── recommendStrategyChange() ───────────────────────────────────────────────

    public function test_no_signals_yields_no_exhaustion(): void
    {
        $r = $this->svc()->recommendStrategyChange([$this->thinWave(10, 0, 100)]);

        $this->assertFalse($r['exhaustion_proven']);
        $this->assertNull($r['recommended_strategy']);
        $this->assertFalse($r['must_not_repeat_same_strategy']);
    }

    public function test_forbidden_wall_rate_recommends_unblock(): void
    {
        $wave = ['verified_findings' => 5, 'duplicate_findings' => 0, 'token_cost' => 100, 'forbidden_wall_hits' => 4, 'attempts' => 10];

        $r = $this->svc()->recommendStrategyChange([$wave]);

        $this->assertTrue($r['exhaustion_proven']);
        $this->assertSame(AtlasExternalBrainFrontierExhaustionEscalator::STRATEGY_UNBLOCK_FORBIDDEN_WALL, $r['recommended_strategy']);
        $this->assertContains('forbidden_wall_rate', $r['exhaustion_signals']);
    }

    public function test_repeated_weak_proposals_recommends_simplify(): void
    {
        $r = $this->svc()->recommendStrategyChange([$this->thinWave(5, 0, 100)], ['weak_proposal_streak' => 3]);

        $this->assertSame(AtlasExternalBrainFrontierExhaustionEscalator::STRATEGY_SIMPLIFY, $r['recommended_strategy']);
    }

    public function test_rising_duplicate_rate_recommends_consolidate(): void
    {
        $r = $this->svc()->recommendStrategyChange([$this->thinWave(3, 10, 100)]);

        $this->assertSame(AtlasExternalBrainFrontierExhaustionEscalator::STRATEGY_CONSOLIDATE, $r['recommended_strategy']);
    }

    public function test_low_marginal_yield_recommends_audit_code(): void
    {
        $r = $this->svc()->recommendStrategyChange([
            $this->thinWave(10, 0, 100),
            $this->thinWave(3, 0, 200),
        ]);

        $this->assertSame(AtlasExternalBrainFrontierExhaustionEscalator::STRATEGY_AUDIT_CODE, $r['recommended_strategy']);
    }

    public function test_exhaustion_proven_never_recommends_same_strategy_repeat(): void
    {
        $r = $this->svc()->recommendStrategyChange([$this->thinWave(3, 10, 100)]);

        $this->assertTrue($r['must_not_repeat_same_strategy']);
        $this->assertContains($r['recommended_strategy'], AtlasExternalBrainFrontierExhaustionEscalator::CHANGE_STRATEGY_ACTIONS);
    }

    public function test_forbidden_wall_takes_precedence_over_other_signals(): void
    {
        $wave = ['verified_findings' => 3, 'duplicate_findings' => 10, 'token_cost' => 100, 'forbidden_wall_hits' => 5, 'attempts' => 10];

        $r = $this->svc()->recommendStrategyChange([$wave], ['weak_proposal_streak' => 5]);

        $this->assertSame(AtlasExternalBrainFrontierExhaustionEscalator::STRATEGY_UNBLOCK_FORBIDDEN_WALL, $r['recommended_strategy']);
    }

    // ── AC: repeated same-pattern low yield recommends switch_pattern ─────────

    public function test_repeated_same_pattern_low_yield_recommends_switch_pattern(): void
    {
        $r = $this->svc()->recommendStrategyChange(
            [$this->thinWave(10, 0, 100), $this->thinWave(3, 0, 200)],
            ['current_pattern' => 'bug_hunt_same_shape'],
        );

        $this->assertTrue($r['exhaustion_proven']);
        $this->assertSame(AtlasExternalBrainFrontierExhaustionEscalator::ACTION_SWITCH_PATTERN, $r['action']);
    }

    // ── AC: strategy changes include next_probe_pattern and forbidden_repeat_pattern ──

    public function test_strategy_change_includes_next_probe_pattern_and_forbidden_repeat_pattern(): void
    {
        $r = $this->svc()->recommendStrategyChange(
            [$this->thinWave(3, 10, 100)],
            ['current_pattern' => 'bug_hunt_same_shape'],
        );

        $this->assertNotEmpty($r['next_probe_pattern']);
        $this->assertSame('bug_hunt_same_shape', $r['forbidden_repeat_pattern']);
        $this->assertNotSame($r['next_probe_pattern'], $r['forbidden_repeat_pattern']);
    }

    public function test_forbidden_repeat_pattern_defaults_when_current_pattern_not_supplied(): void
    {
        $r = $this->svc()->recommendStrategyChange([$this->thinWave(3, 10, 100)]);

        $this->assertSame('same_search_shape_as_last_wave', $r['forbidden_repeat_pattern']);
    }

    // ── AC: high-yield wave history keeps the current strategy ────────────────

    public function test_high_yield_wave_history_keeps_current_strategy(): void
    {
        $waves = [
            $this->thinWave(5, 1, 300),
            $this->thinWave(12, 1, 200),
        ];

        $r = $this->svc()->recommendStrategyChange($waves, ['current_strategy' => AtlasExternalBrainFrontierExhaustionEscalator::STRATEGY_AUDIT_CODE]);

        $this->assertFalse($r['exhaustion_proven']);
        $this->assertSame(AtlasExternalBrainFrontierExhaustionEscalator::ACTION_KEEP_CURRENT_STRATEGY, $r['action']);
        $this->assertSame(AtlasExternalBrainFrontierExhaustionEscalator::STRATEGY_AUDIT_CODE, $r['recommended_strategy']);
        $this->assertNull($r['next_probe_pattern']);
        $this->assertNull($r['forbidden_repeat_pattern']);
    }

    // AC: exhaustion_confidence present and reflects signal count
    public function test_exhaustion_confidence_reflects_signals(): void
    {
        $esc = new AtlasExternalBrainFrontierExhaustionEscalator();
        // All evidence floor fields present, declining findings + rising cost = 2 signals
        $r = $esc->escalate([
            ['verified_findings' => 10, 'token_cost' => 100, 'duplicate_findings' => 0, 'inspected_surface' => 'app/Services'],
            ['verified_findings' => 5, 'token_cost' => 200, 'duplicate_findings' => 0, 'inspected_surface' => 'app/Services'],
        ], ['current_pass' => 'bug_hunt']);

        $this->assertArrayHasKey('exhaustion_confidence', $r);
        $this->assertIsFloat($r['exhaustion_confidence']);
        $this->assertGreaterThan(0, $r['exhaustion_confidence']);
    }

    // AC: next_strategy present
    public function test_next_strategy_present(): void
    {
        $esc = new AtlasExternalBrainFrontierExhaustionEscalator();
        $r = $esc->escalate([
            ['verified_findings' => 10, 'token_cost' => 100, 'duplicate_findings' => 0, 'inspected_surface' => 'app/Services'],
            ['verified_findings' => 5, 'token_cost' => 200, 'duplicate_findings' => 0, 'inspected_surface' => 'app/Services'],
        ], ['current_pass' => 'bug_hunt']);

        $this->assertArrayHasKey('next_strategy', $r);
        // With declining findings + rising cost, should escalate to compression
        $this->assertNotNull($r['next_strategy']);
    }

    // AC: missing_search_evidence present
    public function test_missing_search_evidence_present(): void
    {
        $esc = new AtlasExternalBrainFrontierExhaustionEscalator();
        // Missing evidence floor → missing_search_evidence populated
        $r = $esc->escalate([
            ['verified_findings' => 5, 'token_cost' => 200, 'duplicate_findings' => 0],
        ], ['current_pass' => 'compression']);

        $this->assertArrayHasKey('missing_search_evidence', $r);
        $this->assertIsArray($r['missing_search_evidence']);
        $this->assertNotEmpty($r['missing_search_evidence']);
    }

    // AC: exhausted only when all surfaces searched
    public function test_not_exhausted_when_unsearched_surfaces_remain(): void
    {
        $esc = new AtlasExternalBrainFrontierExhaustionEscalator();
        // Missing evidence floor → low exhaustion confidence
        $r = $esc->escalate([
            ['verified_findings' => 5, 'token_cost' => 200, 'duplicate_findings' => 0],
        ], ['current_pass' => 'compression']);

        // Low confidence because evidence floor not met
        $this->assertLessThan(1.0, $r['exhaustion_confidence']);
        // next_strategy should be null when evidence floor not met
        $this->assertNull($r['next_strategy']);
    }
}
