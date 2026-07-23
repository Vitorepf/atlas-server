<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOutcomeLearner;
use Tests\TestCase;

final class AtlasExternalBrainOutcomeLearnerTest extends TestCase
{
    private function svc(): AtlasExternalBrainOutcomeLearner
    {
        return new AtlasExternalBrainOutcomeLearner;
    }

    private function outcome(string $outcome, string $family = 'task_runner', array $extra = []): array
    {
        return array_merge([
            'task_packet_id' => 'pkt_'.md5($outcome.$family),
            'pattern_family' => $family,
            'task_family'    => $family,
            'outcome'        => $outcome,
            'impact'         => 'high',
        ], $extra);
    }

    private function recByFamily(array $result, string $family): ?array
    {
        foreach ($result['recommendations'] as $r) {
            if ($r['task_family'] === $family) {
                return $r;
            }
        }
        return null;
    }

    // ── AC1: success/give_back/poison/quarantine alter promote/avoid/repair/self_heal ──

    public function test_ac1_success_outcome_creates_promote_recommendation(): void
    {
        $r = $this->svc()->learn([
            $this->outcome(AtlasExternalBrainOutcomeLearner::OUTCOME_DELIVERED, 'alpha', ['impact' => 'high']),
            $this->outcome(AtlasExternalBrainOutcomeLearner::OUTCOME_DELIVERED, 'alpha', ['impact' => 'high']),
        ]);

        $rec = $this->recByFamily($r, 'alpha');
        $this->assertNotNull($rec);
        $this->assertSame('promote', $rec['action']);
    }

    public function test_ac1_give_back_outcome_creates_avoid_recommendation(): void
    {
        $r = $this->svc()->learn([
            $this->outcome(AtlasExternalBrainOutcomeLearner::OUTCOME_GIVE_BACK, 'beta'),
            $this->outcome(AtlasExternalBrainOutcomeLearner::OUTCOME_GIVE_BACK, 'beta'),
        ]);

        $rec = $this->recByFamily($r, 'beta');
        $this->assertNotNull($rec);
        $this->assertSame('avoid', $rec['action']);
    }

    public function test_ac1_poison_outcome_creates_repair_recommendation(): void
    {
        $r = $this->svc()->learn([
            $this->outcome(AtlasExternalBrainOutcomeLearner::OUTCOME_POISON, 'gamma'),
        ]);

        $rec = $this->recByFamily($r, 'gamma');
        $this->assertNotNull($rec);
        $this->assertSame('repair', $rec['action']);
    }

    public function test_ac1_quarantine_outcome_creates_self_heal_recommendation(): void
    {
        $r = $this->svc()->learn([
            $this->outcome(AtlasExternalBrainOutcomeLearner::OUTCOME_QUARANTINE, 'delta'),
        ]);

        $rec = $this->recByFamily($r, 'delta');
        $this->assertNotNull($rec);
        $this->assertSame('self_heal', $rec['action']);
    }

    public function test_ac1_quarantine_outranks_poison_for_same_family(): void
    {
        $r = $this->svc()->learn([
            $this->outcome(AtlasExternalBrainOutcomeLearner::OUTCOME_POISON,     'mixed'),
            $this->outcome(AtlasExternalBrainOutcomeLearner::OUTCOME_QUARANTINE, 'mixed'),
        ]);

        $rec = $this->recByFamily($r, 'mixed');
        $this->assertSame('self_heal', $rec['action'],
            'quarantine must override poison when both present');
    }

    public function test_ac1_recommendation_has_required_fields(): void
    {
        $r = $this->svc()->learn([
            $this->outcome(AtlasExternalBrainOutcomeLearner::OUTCOME_DELIVERED, 'omega', ['impact' => 'high']),
            $this->outcome(AtlasExternalBrainOutcomeLearner::OUTCOME_DELIVERED, 'omega', ['impact' => 'high']),
        ]);

        $rec = $this->recByFamily($r, 'omega');
        $this->assertArrayHasKey('action',      $rec);
        $this->assertArrayHasKey('confidence',  $rec);
        $this->assertArrayHasKey('reason',      $rec);
        $this->assertIsFloat($rec['confidence']);
        $this->assertGreaterThan(0.0, $rec['confidence']);
        $this->assertLessThanOrEqual(1.0, $rec['confidence']);
    }

    // ── AC2: repeated negative outcomes lower future priority ─────────────────

    public function test_ac2_repeated_give_back_lowers_priority_delta(): void
    {
        // Single negative outcome
        $r1 = $this->svc()->learn([
            $this->outcome(AtlasExternalBrainOutcomeLearner::OUTCOME_GIVE_BACK, 'heavy'),
        ]);

        // Repeated negative outcomes
        $r2 = $this->svc()->learn([
            $this->outcome(AtlasExternalBrainOutcomeLearner::OUTCOME_GIVE_BACK, 'heavy'),
            $this->outcome(AtlasExternalBrainOutcomeLearner::OUTCOME_GIVE_BACK, 'heavy'),
            $this->outcome(AtlasExternalBrainOutcomeLearner::OUTCOME_GIVE_BACK, 'heavy'),
        ]);

        $delta1 = collect($r1['next_wave_adjustments'])->firstWhere('task_family', 'heavy')['priority_delta'];
        $delta2 = collect($r2['next_wave_adjustments'])->firstWhere('task_family', 'heavy')['priority_delta'];

        $this->assertLessThanOrEqual($delta1, $delta2,
            'repeated give_backs must produce a lower (or equal) priority_delta');
    }

    public function test_ac2_repeated_negatives_produce_avoid_recommendation_with_reason(): void
    {
        $r = $this->svc()->learn([
            $this->outcome(AtlasExternalBrainOutcomeLearner::OUTCOME_GIVE_BACK, 'trouble'),
            $this->outcome(AtlasExternalBrainOutcomeLearner::OUTCOME_GIVE_BACK, 'trouble'),
            $this->outcome(AtlasExternalBrainOutcomeLearner::OUTCOME_GIVE_BACK, 'trouble'),
        ]);

        $rec = $this->recByFamily($r, 'trouble');
        $this->assertSame('avoid', $rec['action']);
        $this->assertStringContainsString('repeated_negative', $rec['reason']);
    }

    public function test_ac2_poison_lowers_pattern_family_delta(): void
    {
        $r = $this->svc()->learn([
            $this->outcome(AtlasExternalBrainOutcomeLearner::OUTCOME_POISON, 'toxic'),
        ]);

        $adj = collect($r['priority_adjustments'])->firstWhere('pattern_family', 'toxic');
        $this->assertNotNull($adj);
        $this->assertLessThan(0.0, $adj['delta'],
            'poison outcome must produce a negative delta for the pattern_family');
    }

    public function test_ac2_quarantine_produces_most_negative_delta(): void
    {
        $rPoison     = $this->svc()->learn([$this->outcome(AtlasExternalBrainOutcomeLearner::OUTCOME_POISON,     'fam')]);
        $rQuarantine = $this->svc()->learn([$this->outcome(AtlasExternalBrainOutcomeLearner::OUTCOME_QUARANTINE, 'fam')]);

        $deltaPoison     = collect($rPoison['priority_adjustments'])->firstWhere('pattern_family', 'fam')['delta'];
        $deltaQuarantine = collect($rQuarantine['priority_adjustments'])->firstWhere('pattern_family', 'fam')['delta'];

        $this->assertLessThan($deltaPoison, $deltaQuarantine,
            'quarantine must produce a more negative delta than poison');
    }

    // ── AC3: high-confidence green → focus hint in next_wave_hints ───────────

    public function test_ac3_high_confidence_green_family_creates_focus_hint(): void
    {
        $r = $this->svc()->learn([
            $this->outcome(AtlasExternalBrainOutcomeLearner::OUTCOME_DELIVERED, 'star', ['impact' => 'high']),
            $this->outcome(AtlasExternalBrainOutcomeLearner::OUTCOME_DELIVERED, 'star', ['impact' => 'high']),
            $this->outcome(AtlasExternalBrainOutcomeLearner::OUTCOME_DELIVERED, 'star', ['impact' => 'high']),
        ]);

        $focusHints = array_filter($r['next_wave_hints'],
            fn ($h) => $h['source_category'] === 'compounding_focus');

        $this->assertNotEmpty($focusHints,
            'high-confidence green family must produce a compounding_focus hint');

        $hint = array_values($focusHints)[0];
        $this->assertStringContainsString('star', $hint['focus']);
        $this->assertGreaterThanOrEqual(0.90, $hint['priority']);
    }

    public function test_ac3_focus_hint_only_for_promote_with_high_confidence(): void
    {
        // Low delivery rate → no focus
        $r = $this->svc()->learn([
            $this->outcome(AtlasExternalBrainOutcomeLearner::OUTCOME_DELIVERED, 'weak', ['impact' => 'low']),
            $this->outcome(AtlasExternalBrainOutcomeLearner::OUTCOME_GIVE_BACK, 'weak'),
            $this->outcome(AtlasExternalBrainOutcomeLearner::OUTCOME_GIVE_BACK, 'weak'),
        ]);

        $focusHints = array_filter($r['next_wave_hints'],
            fn ($h) => $h['source_category'] === 'compounding_focus');

        $this->assertEmpty($focusHints,
            'low-confidence family must NOT receive a compounding_focus hint');
    }

    public function test_ac3_promote_recommendation_reason_mentions_compound(): void
    {
        $r = $this->svc()->learn([
            $this->outcome(AtlasExternalBrainOutcomeLearner::OUTCOME_DELIVERED, 'bright', ['impact' => 'high']),
            $this->outcome(AtlasExternalBrainOutcomeLearner::OUTCOME_DELIVERED, 'bright', ['impact' => 'high']),
        ]);

        $rec = $this->recByFamily($r, 'bright');
        $this->assertSame('promote', $rec['action']);
        $this->assertStringContainsString('compound', $rec['reason']);
    }

    // ── AC4: deterministic, pure ──────────────────────────────────────────────

    public function test_ac4_identical_input_yields_identical_output(): void
    {
        $outcomes = [
            $this->outcome(AtlasExternalBrainOutcomeLearner::OUTCOME_DELIVERED,  'a', ['impact' => 'high']),
            $this->outcome(AtlasExternalBrainOutcomeLearner::OUTCOME_GIVE_BACK,  'b'),
            $this->outcome(AtlasExternalBrainOutcomeLearner::OUTCOME_POISON,     'c'),
            $this->outcome(AtlasExternalBrainOutcomeLearner::OUTCOME_QUARANTINE, 'd'),
        ];

        $this->assertSame(
            json_encode($this->svc()->learn($outcomes), JSON_UNESCAPED_SLASHES),
            json_encode($this->svc()->learn($outcomes), JSON_UNESCAPED_SLASHES),
        );
    }

    public function test_ac4_recommendations_key_always_present(): void
    {
        $r = $this->svc()->learn([]);
        $this->assertArrayHasKey('recommendations', $r);
        $this->assertIsArray($r['recommendations']);
    }

    public function test_ac4_different_families_do_not_bleed_into_each_other(): void
    {
        $r = $this->svc()->learn([
            $this->outcome(AtlasExternalBrainOutcomeLearner::OUTCOME_QUARANTINE, 'bad'),
            $this->outcome(AtlasExternalBrainOutcomeLearner::OUTCOME_DELIVERED,  'good', ['impact' => 'high']),
            $this->outcome(AtlasExternalBrainOutcomeLearner::OUTCOME_DELIVERED,  'good', ['impact' => 'high']),
        ]);

        $good = $this->recByFamily($r, 'good');
        $bad  = $this->recByFamily($r, 'bad');

        $this->assertSame('promote',   $good['action'], 'good family must still be promoted');
        $this->assertSame('self_heal', $bad['action'],  'bad family must be self_heal');
    }
}
