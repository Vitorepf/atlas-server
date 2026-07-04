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

    // ── AC2: contradiction_evidence → decayed ─────────────────────────────────

    public function test_contradicted_record_is_decayed(): void
    {
        $r = $this->policy()->evaluate(['learning_records' => [
            $this->rec(['utility_score' => 0.8, 'age_days' => 5, 'contradiction_evidence' => ['newer_run_failed']]),
        ]]);

        $this->assertSame(1, $r['decaying_count']);
        $this->assertSame('contradicted_by_outcome_evidence', $r['decaying'][0]['reason']);
    }

    public function test_contradiction_reason_wins_over_old_unconfirmed_hint(): void
    {
        // Unconfirmed + older than the 14-day decay window would normally yield
        // old_unconfirmed_hint, but explicit contradiction evidence must take priority.
        $r = $this->policy()->evaluate(['learning_records' => [
            $this->rec([
                'confirmed' => false,
                'age_days' => 30,
                'contradiction_evidence' => ['newer_run_failed'],
            ]),
        ]]);

        $this->assertSame(1, $r['decaying_count']);
        $this->assertSame('contradicted_by_outcome_evidence', $r['decaying'][0]['reason']);
    }

    public function test_high_utility_recent_is_not_contradicted_when_evidence_empty(): void
    {
        $r = $this->policy()->evaluate(['learning_records' => [
            $this->rec(['utility_score' => 0.8, 'age_days' => 5, 'contradiction_evidence' => []]),
        ]]);

        $this->assertSame(1, $r['retained_count']);
    }

    public function test_contradiction_overrides_high_utility_retain(): void
    {
        // High utility + recent + confirmed — but has contradiction evidence → must decay, not retain
        $r = $this->policy()->evaluate(['learning_records' => [
            $this->rec([
                'utility_score'         => 0.9,
                'age_days'              => 5,
                'confirmed'             => true,
                'contradiction_evidence' => ['evidence_a', 'evidence_b'],
            ]),
        ]]);

        $this->assertSame(1, $r['decaying_count']);
        $this->assertSame(0, $r['retained_count']);
        $this->assertSame('contradicted_by_outcome_evidence', $r['decaying'][0]['reason']);
    }

    // ── AC3: revalidation_needed ──────────────────────────────────────────────

    public function test_revalidation_needed_key_always_present(): void
    {
        $r = $this->policy()->evaluate([]);

        $this->assertArrayHasKey('revalidation_needed', $r);
        $this->assertArrayHasKey('revalidation_needed_count', $r);
        $this->assertSame([], $r['revalidation_needed']);
    }

    public function test_high_utility_stale_unconfirmed_goes_to_revalidation(): void
    {
        $r = $this->policy()->evaluate(['learning_records' => [
            $this->rec(['utility_score' => 0.8, 'age_days' => 45, 'confirmed' => false]),
        ]]);

        $this->assertSame(1, $r['revalidation_needed_count']);
        $this->assertSame('high_utility_stale_needs_revalidation', $r['revalidation_needed'][0]['reason']);
        $this->assertSame(0, $r['decaying_count']);
    }

    public function test_high_utility_stale_confirmed_does_not_go_to_revalidation(): void
    {
        // confirmed=true → skips rule 3.5 → falls to default_decay
        $r = $this->policy()->evaluate(['learning_records' => [
            $this->rec(['utility_score' => 0.8, 'age_days' => 45, 'confirmed' => true]),
        ]]);

        $this->assertSame(0, $r['revalidation_needed_count']);
        $this->assertSame(1, $r['decaying_count']);
    }

    public function test_revalidation_needed_entry_has_non_empty_revalidation_chain(): void
    {
        $r = $this->policy()->evaluate(['learning_records' => [
            $this->rec(['id' => 'r-stale', 'utility_score' => 0.8, 'age_days' => 45, 'confirmed' => false]),
        ]]);

        $entry = $r['revalidation_needed'][0];
        $this->assertArrayHasKey('revalidation_chain', $entry);
        $this->assertNotEmpty($entry['revalidation_chain']);
        foreach ($entry['revalidation_chain'] as $step) {
            $this->assertArrayHasKey('evidence_to_refresh', $step);
            $this->assertTrue(array_key_exists('expires_at', $step) || array_key_exists('ttl_days', $step));
            $this->assertArrayHasKey('next_decision_intent', $step);
        }
    }

    public function test_fresh_confirmed_high_utility_remains_retained_without_revalidation(): void
    {
        $r = $this->policy()->evaluate(['learning_records' => [
            $this->rec(['utility_score' => 0.9, 'age_days' => 5, 'confirmed' => true]),
        ]]);

        $this->assertSame(1, $r['retained_count']);
        $this->assertSame(0, $r['revalidation_needed_count']);
        $this->assertSame('high_utility_recent', $r['retained'][0]['reason']);
    }

    public function test_contradiction_evidence_decays_even_when_high_utility_stale_and_unconfirmed(): void
    {
        // Same shape that would otherwise route to revalidation_needed — contradiction wins.
        $r = $this->policy()->evaluate(['learning_records' => [
            $this->rec([
                'utility_score' => 0.8,
                'age_days' => 45,
                'confirmed' => false,
                'contradiction_evidence' => ['outcome contradicted the hint'],
            ]),
        ]]);

        $this->assertSame(0, $r['revalidation_needed_count']);
        $this->assertSame(1, $r['decaying_count']);
        $this->assertSame('contradicted_by_outcome_evidence', $r['decaying'][0]['reason']);
    }

    // ── AC: poison-amplifying lessons are retired, not retained as poison-avoidance ──

    public function test_poison_amplifying_lesson_is_retired_not_retained(): void
    {
        $r = $this->policy()->evaluate(['learning_records' => [
            $this->rec([
                'type'              => 'poison_pattern',
                'age_days'          => 30,
                'actionable'        => true,
                'poison_amplifying' => true,
            ]),
        ]]);

        $this->assertSame(1, $r['retired_count']);
        $this->assertSame(0, $r['retained_count']);
        $this->assertSame('poison_amplifying_lesson_retired', $r['retired'][0]['reason']);
    }

    public function test_poison_amplifying_flag_ignored_for_non_poison_type(): void
    {
        // poison_amplifying only matters for POISON_TYPES; a regular hint ignores it.
        $r = $this->policy()->evaluate(['learning_records' => [
            $this->rec([
                'type'              => 'success_note',
                'utility_score'     => 0.9,
                'age_days'          => 5,
                'poison_amplifying' => true,
            ]),
        ]]);

        $this->assertSame(1, $r['retained_count']);
        $this->assertSame('high_utility_recent', $r['retained'][0]['reason']);
    }

    public function test_non_amplifying_poison_pattern_still_retained_via_longevity(): void
    {
        $r = $this->policy()->evaluate(['learning_records' => [
            $this->rec([
                'type'              => 'poison_pattern',
                'age_days'          => 30,
                'poison_amplifying' => false,
            ]),
        ]]);

        $this->assertSame(1, $r['retained_count']);
        $this->assertSame('poison_pattern_longevity', $r['retained'][0]['reason']);
    }

    public function test_poison_amplifying_takes_priority_over_overridden_check_order(): void
    {
        // overridden still wins outright (checked first) — poison_amplifying is priority 2.5.
        $r = $this->policy()->evaluate(['learning_records' => [
            $this->rec([
                'type'              => 'poison_pattern',
                'age_days'          => 30,
                'overridden'        => true,
                'poison_amplifying' => true,
            ]),
        ]]);

        $this->assertSame('overridden_by_newer_learning', $r['retired'][0]['reason']);
    }

    // ── source_refs, refresh_due, needs_refresh, dedup ──

    public function test_retained_entry_has_source_refs_refresh_due_and_needs_refresh(): void
    {
        $r = $this->policy()->evaluate(['learning_records' => [
            $this->rec([
                'utility_score' => 0.9,
                'age_days' => 5,
                'source_refs' => ['mission:abc', 'outcome:123'],
            ]),
        ]]);

        $entry = $r['retained'][0];
        $this->assertSame(['mission:abc', 'outcome:123'], $entry['source_refs']);
        $this->assertArrayHasKey('refresh_due', $entry);
        $this->assertArrayHasKey('needs_refresh', $entry);
    }

    public function test_needs_refresh_is_true_for_stale_unconfirmed(): void
    {
        $r = $this->policy()->evaluate(['learning_records' => [
            $this->rec(['utility_score' => 0.5, 'age_days' => 20, 'confirmed' => false]),
        ]]);

        $entry = $r['decaying'][0];
        $this->assertTrue($entry['needs_refresh']);
    }

    public function test_needs_refresh_is_false_for_fresh_record(): void
    {
        $r = $this->policy()->evaluate(['learning_records' => [
            $this->rec(['utility_score' => 0.9, 'age_days' => 3, 'confirmed' => true]),
        ]]);

        $entry = $r['retained'][0];
        $this->assertFalse($entry['needs_refresh']);
    }

    public function test_deduplicate_keeps_highest_utility_version(): void
    {
        $r = $this->policy()->evaluate(['learning_records' => [
            $this->rec(['id' => 'dup', 'utility_score' => 0.3, 'age_days' => 5]),
            $this->rec(['id' => 'dup', 'utility_score' => 0.9, 'age_days' => 5]),
        ]]);

        $this->assertSame(1, $r['retained_count']);
        $this->assertSame(0.9, $r['retained'][0]['utility_score']);
    }
}
