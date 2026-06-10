<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Finance\PolymarketShadow;

use App\Services\Ai\Finance\PolymarketShadow\ImplicationRelationParser;
use PHPUnit\Framework\TestCase;

final class ImplicationRelationParserTest extends TestCase
{
    /**
     * @param  list<array<string, mixed>>  $markets
     */
    private function pairs(array $markets): array
    {
        return ImplicationRelationParser::pairs($markets);
    }

    private function market(string $key, string $question, ?string $endDate = null, string $slug = ''): array
    {
        // Realistic default: Polymarket slugs are slugified questions.
        $slug = $slug !== '' ? $slug : trim((string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($question)), '-');

        return ['key' => $key, 'question' => $question, 'slug' => $slug, 'end_date' => $endDate];
    }

    // ── Family 1: deadline monotonicity ────────────────────────────────────

    public function test_deadline_pair_earlier_implies_later(): void
    {
        $pairs = $this->pairs([
            $this->market('jul', 'Will OpenAI release GPT-6 by July 31?', '2026-07-31T00:00:00Z'),
            $this->market('jun', 'Will OpenAI release GPT-6 by June 30?', '2026-06-30T00:00:00Z'),
        ]);

        $this->assertCount(1, $pairs);
        $this->assertSame(ImplicationRelationParser::FAMILY_DEADLINE, $pairs[0]['family']);
        $this->assertSame('jun', $pairs[0]['implicant_key']);
        $this->assertSame('jul', $pairs[0]['implied_key']);
        $this->assertSame('2026-06-30', $pairs[0]['evidence']['implicant']['deadline']);
        $this->assertSame('2026-07-31', $pairs[0]['evidence']['implied']['deadline']);
    }

    public function test_deadline_explicit_years_cross_year_boundary(): void
    {
        $pairs = $this->pairs([
            $this->market('jan27', 'Will X happen by January 2027?'),
            $this->market('dec26', 'Will X happen by December 2026?'),
        ]);

        $this->assertCount(1, $pairs);
        $this->assertSame('dec26', $pairs[0]['implicant_key']);
    }

    public function test_deadline_year_inferred_from_end_date_across_boundary(): void
    {
        // No year in either title; endDates anchor December 2026 < January 2027.
        $pairs = $this->pairs([
            $this->market('jan', 'Will X happen by January 31?', '2027-01-31T12:00:00Z'),
            $this->market('dec', 'Will X happen by December 31?', '2026-12-31T12:00:00Z'),
        ]);

        $this->assertCount(1, $pairs);
        $this->assertSame('dec', $pairs[0]['implicant_key']);
        $this->assertSame('2026-12-31', $pairs[0]['evidence']['implicant']['deadline']);
        $this->assertSame('2027-01-31', $pairs[0]['evidence']['implied']['deadline']);
    }

    public function test_deadline_without_year_and_without_end_date_is_omitted(): void
    {
        $this->assertSame([], $this->pairs([
            $this->market('a', 'Will X happen by June 30?'),
            $this->market('b', 'Will X happen by July 31?'),
        ]));
    }

    public function test_deadline_year_only_form(): void
    {
        $pairs = $this->pairs([
            $this->market('y27', 'Will humans land on Mars by 2027?'),
            $this->market('y26', 'Will humans land on Mars by 2026?'),
        ]);

        $this->assertCount(1, $pairs);
        $this->assertSame('y26', $pairs[0]['implicant_key']);
    }

    public function test_in_month_window_is_not_cumulative_and_omitted(): void
    {
        // "in June" / "in July" are windows, not deadlines: P ordering does not hold.
        $this->assertSame([], $this->pairs([
            $this->market('a', 'Will X happen in June?', '2026-06-30T00:00:00Z'),
            $this->market('b', 'Will X happen in July?', '2026-07-31T00:00:00Z'),
        ]));
    }

    public function test_negated_questions_are_omitted(): void
    {
        $this->assertSame([], $this->pairs([
            $this->market('a', 'Will X NOT happen by June 30, 2026?'),
            $this->market('b', 'Will X NOT happen by July 31, 2026?'),
        ]));
    }

    public function test_different_stems_never_pair(): void
    {
        $this->assertSame([], $this->pairs([
            $this->market('a', 'Will OpenAI release GPT-6 by June 30, 2026?'),
            $this->market('b', 'Will Google release Gemini 4 by July 31, 2026?'),
        ]));
    }

    public function test_equal_deadlines_are_omitted(): void
    {
        $this->assertSame([], $this->pairs([
            $this->market('a', 'Will X happen by June 30, 2026?'),
            $this->market('b', 'Will X happen by June 30, 2026?'),
        ]));
    }

    public function test_two_dates_in_one_question_is_ambiguous_and_omitted(): void
    {
        $this->assertSame([], $this->pairs([
            $this->market('a', 'Will X happen by June 30, 2026 or by July 31, 2026?'),
            $this->market('b', 'Will X happen by August 31, 2026?'),
        ]));
    }

    public function test_deadline_end_of_month_phrasing_defaults_to_month_end(): void
    {
        $pairs = $this->pairs([
            $this->market('jun', 'Will X happen by the end of June 2026?'),
            $this->market('jun15', 'Will X happen by June 15, 2026?'),
        ]);

        $this->assertCount(1, $pairs);
        $this->assertSame('jun15', $pairs[0]['implicant_key']);
        $this->assertSame('2026-06-30', $pairs[0]['evidence']['implied']['deadline']);
    }

    public function test_three_deadlines_emit_all_ordered_pairs(): void
    {
        $pairs = $this->pairs([
            $this->market('jun', 'Will X happen by June 30, 2026?'),
            $this->market('jul', 'Will X happen by July 31, 2026?'),
            $this->market('aug', 'Will X happen by August 31, 2026?'),
        ]);

        $this->assertCount(3, $pairs);
        $ordered = array_map(fn (array $p) => $p['implicant_key'].'=>'.$p['implied_key'], $pairs);
        sort($ordered);
        $this->assertSame(['jul=>aug', 'jun=>aug', 'jun=>jul'], $ordered);
    }

    public function test_slug_fallback_when_question_missing(): void
    {
        $pairs = $this->pairs([
            ['key' => 'a', 'question' => '', 'slug' => 'will-x-happen-by-june-30-2026', 'end_date' => null],
            ['key' => 'b', 'question' => '', 'slug' => 'will-x-happen-by-july-31-2026', 'end_date' => null],
        ]);

        $this->assertCount(1, $pairs);
        $this->assertSame('a', $pairs[0]['implicant_key']);
    }

    // ── Family 2: nested thresholds ────────────────────────────────────────

    public function test_above_brackets_higher_threshold_implies_lower(): void
    {
        $pairs = $this->pairs([
            $this->market('k150', 'Will Bitcoin be above $150,000 on December 31, 2026?'),
            $this->market('k200', 'Will Bitcoin be above $200,000 on December 31, 2026?'),
        ]);

        $this->assertCount(1, $pairs);
        $this->assertSame(ImplicationRelationParser::FAMILY_THRESHOLD, $pairs[0]['family']);
        $this->assertSame('k200', $pairs[0]['implicant_key']);
        $this->assertSame('k150', $pairs[0]['implied_key']);
        $this->assertSame('above', $pairs[0]['evidence']['direction']);
        $this->assertEqualsWithDelta(200000.0, $pairs[0]['evidence']['implicant']['threshold'], 1e-9);
    }

    public function test_synonyms_and_suffixes_normalize_into_one_series(): void
    {
        // "reach $200k" and "above $150,000" share the above-class placeholder.
        $pairs = $this->pairs([
            $this->market('a', 'Will Bitcoin reach $200k by 2026?'),
            $this->market('b', 'Will Bitcoin reach $150,000 by 2026?'),
        ]);

        $this->assertCount(1, $pairs);
        $this->assertSame('a', $pairs[0]['implicant_key']);
    }

    public function test_below_brackets_lower_threshold_implies_higher(): void
    {
        $pairs = $this->pairs([
            $this->market('d80', 'Will Bitcoin dip to $80,000 by June 30, 2026?'),
            $this->market('d90', 'Will Bitcoin dip to $90,000 by June 30, 2026?'),
        ]);

        $this->assertCount(1, $pairs);
        $this->assertSame('d80', $pairs[0]['implicant_key']);
        $this->assertSame('d90', $pairs[0]['implied_key']);
        $this->assertSame('below', $pairs[0]['evidence']['direction']);
    }

    public function test_mixed_direction_classes_never_pair(): void
    {
        $this->assertSame([], $this->pairs([
            $this->market('a', 'Will Bitcoin be above $100,000 on June 30, 2026?'),
            $this->market('b', 'Will Bitcoin be below $100,000 on June 30, 2026?'),
        ]));
    }

    public function test_different_deadlines_break_threshold_stems(): void
    {
        // ">$200k by July" does NOT imply ">$150k by June": stems differ, omitted.
        $this->assertSame([], $this->pairs([
            $this->market('a', 'Will Bitcoin be above $200,000 by July 31, 2026?'),
            $this->market('b', 'Will Bitcoin be above $150,000 by June 30, 2026?'),
        ]));
    }

    public function test_equal_thresholds_are_omitted(): void
    {
        $this->assertSame([], $this->pairs([
            $this->market('a', 'Will Bitcoin be above $150,000 on June 30, 2026?'),
            $this->market('b', 'Will Bitcoin be above $150k on June 30, 2026?'),
        ]));
    }

    public function test_range_questions_with_two_numbers_are_omitted(): void
    {
        $this->assertSame([], $this->pairs([
            $this->market('a', 'Will Bitcoin be above $100,000 but below $150,000 on June 30, 2026?'),
            $this->market('b', 'Will Bitcoin be above $120,000 but below $170,000 on June 30, 2026?'),
        ]));
    }

    public function test_million_suffix_parses(): void
    {
        $pairs = $this->pairs([
            $this->market('m2', 'Will the token reach $2 million market cap by 2027?'),
            $this->market('m1', 'Will the token reach $1.5 million market cap by 2027?'),
        ]);

        $this->assertCount(1, $pairs);
        $this->assertSame('m2', $pairs[0]['implicant_key']);
        $this->assertEqualsWithDelta(2_000_000.0, $pairs[0]['evidence']['implicant']['threshold'], 1e-9);
    }

    public function test_temperature_brackets_pair_within_same_unit_stem(): void
    {
        $pairs = $this->pairs([
            $this->market('t95', 'Will the NYC high temperature be above 95F on June 21, 2026?'),
            $this->market('t90', 'Will the NYC high temperature be above 90F on June 21, 2026?'),
        ]);

        $this->assertCount(1, $pairs);
        $this->assertSame('t95', $pairs[0]['implicant_key']);
    }

    // ── Family 3: group winner implies advancing ───────────────────────────

    public function test_world_cup_win_group_implies_advance(): void
    {
        $pairs = $this->pairs([
            $this->market('win', 'Will Brazil win Group C at the 2026 World Cup?'),
            $this->market('adv', 'Will Brazil advance from Group C at the 2026 World Cup?'),
        ]);

        $this->assertCount(1, $pairs);
        $this->assertSame(ImplicationRelationParser::FAMILY_GROUP, $pairs[0]['family']);
        $this->assertSame('win', $pairs[0]['implicant_key']);
        $this->assertSame('adv', $pairs[0]['implied_key']);
        $this->assertSame('c', $pairs[0]['evidence']['group']);
    }

    public function test_group_pairing_requires_same_team_and_group(): void
    {
        // Different team: residual stems differ.
        $this->assertSame([], $this->pairs([
            $this->market('a', 'Will Brazil win Group C at the 2026 World Cup?'),
            $this->market('b', 'Will Mexico advance from Group C at the 2026 World Cup?'),
        ]));
        // Different group letter.
        $this->assertSame([], $this->pairs([
            $this->market('c', 'Will Brazil win Group C at the 2026 World Cup?'),
            $this->market('d', 'Will Brazil advance from Group D at the 2026 World Cup?'),
        ]));
    }

    public function test_qualifies_out_of_group_variant(): void
    {
        $pairs = $this->pairs([
            $this->market('win', 'Will Argentina win Group A at the 2026 World Cup?'),
            $this->market('adv', 'Will Argentina qualify out of Group A at the 2026 World Cup?'),
        ]);

        $this->assertCount(1, $pairs);
        $this->assertSame('win', $pairs[0]['implicant_key']);
    }

    public function test_two_winners_never_pair_with_each_other(): void
    {
        $this->assertSame([], $this->pairs([
            $this->market('a', 'Will Brazil win Group C at the 2026 World Cup?'),
            $this->market('b', 'Will Brazil win Group C at the 2026 World Cup?'),
        ]));
    }

    // ── Cross-family interplay and hygiene ─────────────────────────────────

    public function test_market_with_date_and_threshold_joins_both_families_safely(): void
    {
        $pairs = $this->pairs([
            $this->market('a', 'Will Bitcoin reach $150k by June 30, 2026?'),
            $this->market('b', 'Will Bitcoin reach $150k by July 31, 2026?'),
            $this->market('c', 'Will Bitcoin reach $200k by June 30, 2026?'),
        ]);

        $byFamily = [];
        foreach ($pairs as $pair) {
            $byFamily[$pair['family']][] = $pair['implicant_key'].'=>'.$pair['implied_key'];
        }

        // Deadline family: same threshold, June implies July.
        $this->assertSame(['a=>b'], $byFamily[ImplicationRelationParser::FAMILY_DEADLINE] ?? []);
        // Threshold family: same deadline, 200k implies 150k.
        $this->assertSame(['c=>a'], $byFamily[ImplicationRelationParser::FAMILY_THRESHOLD] ?? []);
    }

    public function test_markets_without_key_or_text_are_skipped(): void
    {
        $this->assertSame([], $this->pairs([
            ['key' => '', 'question' => 'Will X happen by June 30, 2026?', 'slug' => 'x', 'end_date' => null],
            ['key' => 'b', 'question' => '', 'slug' => '', 'end_date' => null],
        ]));
    }

    public function test_generic_questions_from_unrelated_events_never_pair(): void
    {
        // Live-proven false positive: esports "round total" questions repeat
        // verbatim across unrelated matches; the subject lives only in the slug.
        $this->assertSame([], $this->pairs([
            ['key' => 'a', 'question' => 'Will the map have more than 20.5 rounds?', 'slug' => 'val-fut1-nrg-2026-06-10-game1-round-total-20pt5', 'end_date' => null],
            ['key' => 'b', 'question' => 'Will the map have more than 21.5 rounds?', 'slug' => 'cs2-mglz-bb3-2026-06-11-game1-round-total-21pt5', 'end_date' => null],
        ]));
    }

    public function test_same_event_slug_family_still_pairs(): void
    {
        $pairs = $this->pairs([
            ['key' => 'a', 'question' => 'Will the map have more than 20.5 rounds?', 'slug' => 'val-fut1-nrg-2026-06-10-game1-round-total-20pt5', 'end_date' => null],
            ['key' => 'b', 'question' => 'Will the map have more than 21.5 rounds?', 'slug' => 'val-fut1-nrg-2026-06-10-game1-round-total-21pt5', 'end_date' => null],
        ]);

        $this->assertCount(1, $pairs);
        $this->assertSame('b', $pairs[0]['implicant_key']);
    }

    public function test_over_under_combo_questions_are_omitted(): void
    {
        // Live-proven: "Over/Under N" names both sides; which side the primary
        // token prices is not derivable from the text.
        $this->assertSame([], $this->pairs([
            ['key' => 'a', 'question' => 'Map 1 Total Rounds: Over/Under 20.5', 'slug' => 'val-fut1-nrg-2026-06-10-game1-round-total-20pt5', 'end_date' => null],
            ['key' => 'b', 'question' => 'Map 1 Total Rounds: Over/Under 21.5', 'slug' => 'val-fut1-nrg-2026-06-10-game1-round-total-21pt5', 'end_date' => null],
        ]));
    }

    public function test_low_token_after_number_inverts_semantics_and_is_omitted(): void
    {
        // Live-proven: "hit $6,500 (LOW)" is a touch-from-above, not an
        // exceedance; the above-class prefix would order the pair backwards.
        $this->assertSame([], $this->pairs([
            $this->market('a', 'Will S&P 500 (SPX) hit $6,500 (LOW) in June?'),
            $this->market('b', 'Will S&P 500 (SPX) hit $6,000 (LOW) in June?'),
        ]));
    }

    public function test_pair_explosion_is_capped(): void
    {
        $markets = [];
        for ($i = 1; $i <= 20; $i++) {
            $markets[] = $this->market('m'.$i, sprintf('Will X happen by June %d, 2026?', $i));
        }

        $pairs = $this->pairs($markets);
        $this->assertNotEmpty($pairs);
        $this->assertLessThanOrEqual(40, count($pairs));
    }
}
