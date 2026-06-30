<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainLearningRetentionPolicy;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainLearningRetentionPolicyTest extends TestCase
{
    private function policy(): AtlasExternalBrainLearningRetentionPolicy
    {
        return new AtlasExternalBrainLearningRetentionPolicy;
    }

    private function rec(array $overrides = []): array
    {
        return array_merge([
            'id'            => 'r1',
            'type'          => 'success_note',
            'utility_score' => 0.5,
            'age_days'      => 7,
            'confirmed'     => true,
            'overridden'    => false,
            'actionable'    => true,
        ], $overrides);
    }

    // ── Output shape ──────────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->policy()->evaluate([]);
        $this->assertSame(AtlasExternalBrainLearningRetentionPolicy::SCHEMA, $r['schema_version']);
        $this->assertArrayHasKey('retained', $r);
        $this->assertArrayHasKey('decaying', $r);
        $this->assertArrayHasKey('retired', $r);
        $this->assertArrayHasKey('retained_count', $r);
        $this->assertArrayHasKey('decaying_count', $r);
        $this->assertArrayHasKey('retired_count', $r);
    }

    public function test_empty_records_produce_zero_counts(): void
    {
        $r = $this->policy()->evaluate(['learning_records' => []]);
        $this->assertSame(0, $r['retained_count']);
        $this->assertSame(0, $r['decaying_count']);
        $this->assertSame(0, $r['retired_count']);
    }

    // ── Retired ───────────────────────────────────────────────────────────────

    public function test_overridden_record_is_retired(): void
    {
        $r = $this->policy()->evaluate([
            'learning_records' => [$this->rec(['overridden' => true])],
        ]);
        $this->assertSame(1, $r['retired_count']);
        $this->assertSame('overridden_by_newer_learning', $r['retired'][0]['reason']);
    }

    public function test_record_older_than_max_age_is_retired(): void
    {
        $r = $this->policy()->evaluate([
            'learning_records' => [$this->rec(['age_days' => 181, 'overridden' => false])],
        ]);
        $this->assertSame(1, $r['retired_count']);
        $this->assertSame('exceeded_maximum_age', $r['retired'][0]['reason']);
    }

    public function test_overridden_takes_priority_over_max_age(): void
    {
        $r = $this->policy()->evaluate([
            'learning_records' => [$this->rec(['age_days' => 200, 'overridden' => true])],
        ]);
        $this->assertSame('overridden_by_newer_learning', $r['retired'][0]['reason']);
    }

    // ── AC3: poison-pattern longevity ────────────────────────────────────────

    public function test_poison_pattern_retained_within_90_days(): void
    {
        $r = $this->policy()->evaluate([
            'learning_records' => [$this->rec([
                'type'          => 'poison_pattern',
                'age_days'      => 60,
                'utility_score' => 0.2,
                'confirmed'     => false,
            ])],
        ]);
        $this->assertSame(1, $r['retained_count']);
        $this->assertSame('poison_pattern_longevity', $r['retained'][0]['reason']);
    }

    public function test_give_back_hint_retained_within_90_days(): void
    {
        $r = $this->policy()->evaluate([
            'learning_records' => [$this->rec([
                'type'          => 'give_back_hint',
                'age_days'      => 45,
                'utility_score' => 0.1,
            ])],
        ]);
        $this->assertSame(1, $r['retained_count']);
        $this->assertSame('poison_pattern_longevity', $r['retained'][0]['reason']);
    }

    public function test_poison_pattern_not_retained_when_actionable_is_false(): void
    {
        // Non-actionable poison still runs through later rules (low utility → decaying).
        $r = $this->policy()->evaluate([
            'learning_records' => [$this->rec([
                'type'          => 'poison_pattern',
                'age_days'      => 30,
                'utility_score' => 0.1,
                'actionable'    => false,
            ])],
        ]);
        $this->assertSame(0, $r['retained_count']);
        $this->assertSame('low_utility_learning', $r['decaying'][0]['reason']);
    }

    public function test_poison_pattern_beyond_90_days_falls_through_to_later_rules(): void
    {
        // age > 90 but <= 180: not poison-retained, unconfirmed+old → decaying.
        $r = $this->policy()->evaluate([
            'learning_records' => [$this->rec([
                'type'          => 'poison_pattern',
                'age_days'      => 100,
                'confirmed'     => false,
                'utility_score' => 0.5,
                'actionable'    => true,
            ])],
        ]);
        $this->assertSame(0, $r['retained_count']);
        $this->assertSame(1, $r['decaying_count']);
        $this->assertSame('old_unconfirmed_hint', $r['decaying'][0]['reason']);
    }

    // ── AC2: decay old unconfirmed hints ─────────────────────────────────────

    public function test_unconfirmed_record_older_than_14_days_decays(): void
    {
        $r = $this->policy()->evaluate([
            'learning_records' => [$this->rec([
                'confirmed'     => false,
                'age_days'      => 15,
                'utility_score' => 0.8,
            ])],
        ]);
        $this->assertSame(1, $r['decaying_count']);
        $this->assertSame('old_unconfirmed_hint', $r['decaying'][0]['reason']);
    }

    public function test_unconfirmed_record_within_14_days_is_eligible_for_retain(): void
    {
        // 10 days, unconfirmed, high utility + recent → retained.
        $r = $this->policy()->evaluate([
            'learning_records' => [$this->rec([
                'confirmed'     => false,
                'age_days'      => 10,
                'utility_score' => 0.9,
            ])],
        ]);
        $this->assertSame(1, $r['retained_count']);
        $this->assertSame('high_utility_recent', $r['retained'][0]['reason']);
    }

    // ── AC2: keep recent high-utility ────────────────────────────────────────

    public function test_high_utility_recent_record_is_retained(): void
    {
        $r = $this->policy()->evaluate([
            'learning_records' => [$this->rec(['utility_score' => 0.9, 'age_days' => 5])],
        ]);
        $this->assertSame(1, $r['retained_count']);
        $this->assertSame('high_utility_recent', $r['retained'][0]['reason']);
    }

    public function test_high_utility_old_record_does_not_retain_via_high_utility_path(): void
    {
        // age > RECENT_DAYS(30), high utility, confirmed: falls to default_decay.
        $r = $this->policy()->evaluate([
            'learning_records' => [$this->rec(['utility_score' => 0.8, 'age_days' => 45, 'confirmed' => true])],
        ]);
        $this->assertSame(1, $r['decaying_count']);
        $this->assertSame('default_decay', $r['decaying'][0]['reason']);
    }

    // ── Low utility decay ─────────────────────────────────────────────────────

    public function test_low_utility_record_decays(): void
    {
        $r = $this->policy()->evaluate([
            'learning_records' => [$this->rec(['utility_score' => 0.1, 'age_days' => 5, 'confirmed' => true])],
        ]);
        $this->assertSame(1, $r['decaying_count']);
        $this->assertSame('low_utility_learning', $r['decaying'][0]['reason']);
    }

    // ── Default decay ─────────────────────────────────────────────────────────

    public function test_mid_utility_mid_age_confirmed_defaults_to_decay(): void
    {
        // utility 0.5, age 20, confirmed — no rule matches above → default_decay.
        $r = $this->policy()->evaluate([
            'learning_records' => [$this->rec(['utility_score' => 0.5, 'age_days' => 20])],
        ]);
        $this->assertSame(1, $r['decaying_count']);
        $this->assertSame('default_decay', $r['decaying'][0]['reason']);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $facts = ['learning_records' => [
            $this->rec(['id' => 'a', 'type' => 'poison_pattern', 'age_days' => 10]),
            $this->rec(['id' => 'b', 'type' => 'success_note',   'age_days' => 20, 'utility_score' => 0.9]),
            $this->rec(['id' => 'c', 'overridden' => true]),
        ]];
        $a = $this->policy()->evaluate($facts);
        $b = $this->policy()->evaluate($facts);
        $this->assertSame(json_encode($a), json_encode($b));
    }
}
