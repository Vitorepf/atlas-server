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
        $waves = [
            $this->thinWave(20, 2, 100),
            $this->thinWave(5, 10, 300),
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
        // but without declining+rising+dupes all true, stays on bug_hunt
        $this->assertSame('bug_hunt', $r['next_pass']);
    }
}
