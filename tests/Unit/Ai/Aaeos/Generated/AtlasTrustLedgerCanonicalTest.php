<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasTrustLedgerCanonicalService;
use Tests\TestCase;

/**
 * Pins the canonical Trust Ledger contract: the weighted sigmoid score over the
 * rolling 90-day window, the threshold -> autonomy-level ladder, the L4+ same-
 * day freshness rule and the append-only invariant.
 *
 * @see docs/engineering-knowledge-base/atlas-trust-ledger-canonical.md
 */
final class AtlasTrustLedgerCanonicalTest extends TestCase
{
    private AtlasTrustLedgerCanonicalService $service;

    private const AS_OF = '2026-06-01T12:00:00+00:00';

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AtlasTrustLedgerCanonicalService(
            static fn (): string => self::AS_OF
        );
    }

    /**
     * "Score formula" + "Tabela de pesos": two cert_pass (weight 1.0, sign +)
     * fold to weighted_sum 2.0, norm 2.0, x = 1.0, score = sigmoid(1.0). That
     * score (0.731) lands in the >=0.70 / <0.80 band -> L4 eligible.
     */
    public function test_all_positive_window_folds_to_sigmoid_of_one(): void
    {
        $events = [
            ['kind' => 'cert_pass', 'at' => '2026-05-30T09:00:00+00:00'],
            ['kind' => 'cert_pass', 'at' => '2026-05-29T09:00:00+00:00'],
        ];

        $receipt = $this->service->score($events, self::AS_OF);

        $this->assertSame(2.0, $receipt['weighted_sum']);
        $this->assertSame(2.0, $receipt['norm']);
        $this->assertSame(2, $receipt['events_in_window']);
        $this->assertEqualsWithDelta(0.7310585786, $receipt['score'], 1e-9);
        $this->assertSame(4, $this->service->eligibleLevel($receipt['score'])['eligible_level']);
    }

    /**
     * "signature_breach" carries weight 3.0 with a negative sign — the heaviest
     * penalty. Alone it folds to x = -1.0, score = sigmoid(-1) = 0.269, which is
     * below the 0.50 floor: freeze runtime + Architect review.
     */
    public function test_signature_breach_drives_score_below_freeze_floor(): void
    {
        $events = [
            ['kind' => 'signature_breach', 'at' => '2026-05-20T09:00:00+00:00'],
        ];

        $receipt = $this->service->score($events, self::AS_OF);
        $eligible = $this->service->eligibleLevel($receipt['score']);

        $this->assertEqualsWithDelta(0.2689414214, $receipt['score'], 1e-9);
        $this->assertSame(0, $eligible['eligible_level']);
        $this->assertTrue($eligible['frozen']);
        $this->assertSame(AtlasTrustLedgerCanonicalService::EFFECT_FREEZE, $eligible['effect']);
    }

    /**
     * "rolling 90d": an event older than the 90-day window is excluded from the
     * fold. With only an out-of-window event present, the window is empty and
     * the score folds to the neutral sigmoid(0) = 0.5 -> L3 max (not frozen).
     */
    public function test_events_outside_ninety_day_window_are_ignored(): void
    {
        $events = [
            // 100 days before as-of -> outside the 90d window.
            ['kind' => 'cert_pass', 'at' => '2026-02-21T12:00:00+00:00'],
        ];

        $receipt = $this->service->score($events, self::AS_OF);
        $eligible = $this->service->eligibleLevel($receipt['score']);

        $this->assertSame(0, $receipt['events_in_window']);
        $this->assertSame(1, $receipt['events_ignored']);
        $this->assertEqualsWithDelta(0.5, $receipt['score'], 1e-12);
        $this->assertSame(3, $eligible['eligible_level']);
        $this->assertFalse($eligible['frozen']);
    }

    /**
     * "Thresholds" ladder: each documented band maps to the exact max level.
     */
    public function test_threshold_ladder_maps_scores_to_levels(): void
    {
        $this->assertSame(7, $this->service->eligibleLevel(0.96)['eligible_level']);
        $this->assertSame(6, $this->service->eligibleLevel(0.90)['eligible_level']);
        $this->assertSame(5, $this->service->eligibleLevel(0.80)['eligible_level']);
        $this->assertSame(4, $this->service->eligibleLevel(0.70)['eligible_level']);
        $this->assertSame(3, $this->service->eligibleLevel(0.69)['eligible_level']);
        $this->assertSame(0, $this->service->eligibleLevel(0.49)['eligible_level']);
    }

    /**
     * "Regras para IA": Promote L4+ exige score do dia. A score computed from a
     * different day is stale and BLOCKS an L4 promotion even when its number
     * clears the threshold; a same-day score of equal value is allowed.
     */
    public function test_l4_promotion_requires_same_day_score(): void
    {
        // High-scoring window (two cert_pass -> sigmoid(1) = 0.731 -> L4 OK).
        $events = [
            ['kind' => 'cert_pass', 'at' => '2026-05-30T09:00:00+00:00'],
            ['kind' => 'cert_pass', 'at' => '2026-05-29T09:00:00+00:00'],
        ];

        $sameDay = $this->service->gate($events, 4, self::AS_OF);
        $this->assertTrue($sameDay['allowed']);
        $this->assertSame('promotion_allowed', $sameDay['reason']);
        $this->assertTrue($sameDay['requires_same_day']);

        // A score computed YESTERDAY, used to request an L4 promotion TODAY.
        // The number clears 0.70, but the score is not "do dia" -> BLOCKED.
        $yesterdayScore = $this->service->score($events, '2026-05-31T12:00:00+00:00');
        $this->assertGreaterThanOrEqual(0.70, $yesterdayScore['score']);
        $stale = $this->service->gate($events, 4, self::AS_OF, $yesterdayScore);
        $this->assertFalse($stale['allowed']);
        $this->assertSame('stale_score', $stale['reason']);
        $this->assertFalse($stale['same_day']);

        // The SAME stale score requesting L3 (below the same-day floor) is fine.
        $staleL3 = $this->service->gate($events, 3, self::AS_OF, $yesterdayScore);
        $this->assertTrue($staleL3['allowed']);
        $this->assertFalse($staleL3['requires_same_day']);
    }

    /**
     * The same-day rule applies ONLY at L4 and above. A below-threshold score
     * blocks with score_below_threshold (L3 request), while L4+ flips to the
     * freeze reason when the score is under the 0.50 floor.
     */
    public function test_gate_block_reasons_are_documented(): void
    {
        // Empty window -> 0.5 -> eligible L3. Request L4 -> below threshold.
        $belowL4 = $this->service->gate([], 4, self::AS_OF);
        $this->assertFalse($belowL4['allowed']);
        $this->assertSame('score_below_threshold', $belowL4['reason']);

        // Frozen score requesting L5.
        $frozen = $this->service->gate(
            [['kind' => 'signature_breach', 'at' => '2026-05-20T09:00:00+00:00']],
            5,
            self::AS_OF
        );
        $this->assertFalse($frozen['allowed']);
        $this->assertSame(AtlasTrustLedgerCanonicalService::EFFECT_FREEZE, $frozen['reason']);
    }

    /**
     * "forbidden_changes" / "Regras para IA": the ledger is append-only. An
     * append is allowed; an update or delete is rejected.
     */
    public function test_append_only_invariant(): void
    {
        $this->assertTrue($this->service->classifyWrite('append')['allowed']);
        $this->assertFalse($this->service->classifyWrite('update')['allowed']);
        $this->assertFalse($this->service->classifyWrite('delete')['allowed']);
        $this->assertSame(
            'append_only_violation',
            $this->service->classifyWrite('update')['reason']
        );
    }
}
