<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainLearningRetentionPolicy;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainLearningRetentionPolicyTest extends TestCase
{
    private AtlasExternalBrainLearningRetentionPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new AtlasExternalBrainLearningRetentionPolicy;
    }

    private function eval(array $records): array
    {
        return $this->policy->evaluate(['learning_records' => $records]);
    }

    private function rec(array $overrides = []): array
    {
        return array_merge([
            'id'                     => 'rec-1',
            'type'                   => 'hint',
            'utility_score'          => 0.5,
            'age_days'               => 5,
            'confirmed'              => true,
            'overridden'             => false,
            'actionable'             => true,
            'contradiction_evidence' => [],
        ], $overrides);
    }

    // ── AC2: overridden / too-old → retired ───────────────────────────────────

    public function test_overridden_record_is_retired(): void
    {
        $r = $this->eval([$this->rec(['overridden' => true])]);

        $this->assertCount(1, $r['retired']);
        $this->assertSame('overridden_by_newer_learning', $r['retired'][0]['reason']);
    }

    public function test_record_exceeding_max_age_is_retired(): void
    {
        $r = $this->eval([$this->rec(['age_days' => 181])]);

        $this->assertCount(1, $r['retired']);
        $this->assertSame('exceeded_maximum_age', $r['retired'][0]['reason']);
    }

    public function test_overridden_takes_priority_over_age(): void
    {
        $r = $this->eval([$this->rec(['overridden' => true, 'age_days' => 200])]);

        $this->assertSame('overridden_by_newer_learning', $r['retired'][0]['reason']);
    }

    // ── AC3: poison types within window → retained ────────────────────────────

    public function test_poison_pattern_within_window_is_retained(): void
    {
        $r = $this->eval([$this->rec(['type' => 'poison_pattern', 'age_days' => 60, 'confirmed' => false])]);

        $this->assertCount(1, $r['retained']);
        $this->assertSame('poison_pattern_longevity', $r['retained'][0]['reason']);
    }

    public function test_give_back_hint_within_window_is_retained(): void
    {
        $r = $this->eval([$this->rec(['type' => 'give_back_hint', 'age_days' => 45, 'confirmed' => false])]);

        $this->assertCount(1, $r['retained']);
        $this->assertSame('poison_pattern_longevity', $r['retained'][0]['reason']);
    }

    public function test_failure_pattern_within_window_is_retained(): void
    {
        $r = $this->eval([$this->rec(['type' => 'failure_pattern', 'age_days' => 30, 'confirmed' => false])]);

        $this->assertCount(1, $r['retained']);
        $this->assertSame('poison_pattern_longevity', $r['retained'][0]['reason']);
    }

    public function test_non_actionable_poison_pattern_is_not_retained_via_longevity(): void
    {
        $r = $this->eval([$this->rec(['type' => 'poison_pattern', 'age_days' => 60, 'actionable' => false, 'confirmed' => false, 'utility_score' => 0.0])]);

        $reasons = array_column($r['retained'], 'reason');
        $this->assertNotContains('poison_pattern_longevity', $reasons);
    }

    // ── AC4: high-utility stale unconfirmed → revalidation; contradiction → decay

    public function test_high_utility_stale_unconfirmed_goes_to_revalidation(): void
    {
        $r = $this->eval([$this->rec([
            'utility_score' => 0.85,
            'age_days'      => 45,   // > RECENT_DAYS (30)
            'confirmed'     => false,
        ])]);

        $this->assertCount(1, $r['revalidation_needed']);
        $this->assertSame('high_utility_stale_needs_revalidation', $r['revalidation_needed'][0]['reason']);
    }

    public function test_confirmed_high_utility_stale_does_not_go_to_revalidation(): void
    {
        // confirmed=true skips the revalidation_needed branch
        $r = $this->eval([$this->rec([
            'utility_score' => 0.85,
            'age_days'      => 45,
            'confirmed'     => true,
        ])]);

        $this->assertEmpty($r['revalidation_needed']);
    }

    public function test_contradiction_evidence_causes_decay(): void
    {
        $r = $this->eval([$this->rec([
            'utility_score'          => 0.9,
            'age_days'               => 5,
            'confirmed'              => true,
            'contradiction_evidence' => ['outcome:2026-06-30:fail'],
        ])]);

        $this->assertCount(1, $r['decaying']);
        $this->assertSame('contradicted_by_outcome_evidence', $r['decaying'][0]['reason']);
    }

    // ── additional classification paths ──────────────────────────────────────

    public function test_high_utility_recent_confirmed_is_retained(): void
    {
        $r = $this->eval([$this->rec([
            'utility_score' => 0.80,
            'age_days'      => 10,
            'confirmed'     => true,
        ])]);

        $this->assertCount(1, $r['retained']);
        $this->assertSame('high_utility_recent', $r['retained'][0]['reason']);
    }

    public function test_old_unconfirmed_hint_decays(): void
    {
        $r = $this->eval([$this->rec([
            'utility_score' => 0.5,
            'age_days'      => 20,
            'confirmed'     => false,
            'type'          => 'hint',
        ])]);

        $this->assertCount(1, $r['decaying']);
        $this->assertSame('old_unconfirmed_hint', $r['decaying'][0]['reason']);
    }

    // ── deterministic ─────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $records = [
            $this->rec(['type' => 'poison_pattern', 'age_days' => 30]),
            $this->rec(['id' => 'rec-2', 'overridden' => true]),
        ];

        $this->assertSame(json_encode($this->eval($records)), json_encode($this->eval($records)));
    }

    public function test_schema_is_set(): void
    {
        $r = $this->eval([]);

        $this->assertSame(AtlasExternalBrainLearningRetentionPolicy::SCHEMA, $r['schema_version']);
    }
}
