<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Health;

use App\Services\Ai\SelfConstruction\Maestro\Health\AtlasMaestroQueueAgeValueDecayJoiner;
use Tests\TestCase;

final class AtlasMaestroQueueAgeValueDecayJoinerTest extends TestCase
{
    private function svc(): AtlasMaestroQueueAgeValueDecayJoiner
    {
        return new AtlasMaestroQueueAgeValueDecayJoiner;
    }

    private function packet(string $id, int $ageSeconds, float $value, int $giveBack = 0, int $malformed = 0, bool $blockedFamily = false): array
    {
        return [
            'task_packet_id' => $id,
            'packet_age_facts' => ['age_seconds' => $ageSeconds],
            'packet_value_facts' => ['value_score' => $value],
            'give_back_risk_facts' => ['give_back_count' => $giveBack, 'malformed_count' => $malformed],
            'blocked_family_facts' => ['is_blocked_family' => $blockedFamily],
            'current_priority' => ['value' => 5],
        ];
    }

    // ── output structure ─────────────────────────────────────────────────────

    public function test_output_has_schema_and_recommendations(): void
    {
        $r = $this->svc()->join([$this->packet('t1', 100, 0.5)]);

        $this->assertSame(AtlasMaestroQueueAgeValueDecayJoiner::SCHEMA, $r['schema']);
        $this->assertArrayHasKey('recommendations', $r);
        $this->assertCount(1, $r['recommendations']);
    }

    public function test_recommendation_has_required_fields(): void
    {
        $r = $this->svc()->join([$this->packet('t1', 100, 0.5)]);
        $rec = $r['recommendations'][0];

        foreach (['task_packet_id', 'age_bucket', 'value_bucket', 'decay_score', 'action', 'reason_codes'] as $key) {
            $this->assertArrayHasKey($key, $rec, "missing key: {$key}");
        }
    }

    // ── AC1: old high-value low-risk → drain_first ──────────────────────────────

    public function test_old_high_value_low_risk_is_drain_first(): void
    {
        $r = $this->svc()->join([$this->packet('t1', 200000, 0.9)]);
        $rec = $r['recommendations'][0];

        $this->assertSame('old', $rec['age_bucket']);
        $this->assertSame('high', $rec['value_bucket']);
        $this->assertSame(AtlasMaestroQueueAgeValueDecayJoiner::ACTION_DRAIN_FIRST, $rec['action']);
    }

    // ── AC2: old low-value/high-risk → decay_or_review ──────────────────────────

    public function test_old_low_value_is_decay_or_review(): void
    {
        $r = $this->svc()->join([$this->packet('t1', 200000, 0.1)]);
        $rec = $r['recommendations'][0];

        $this->assertSame(AtlasMaestroQueueAgeValueDecayJoiner::ACTION_DECAY_OR_REVIEW, $rec['action']);
    }

    public function test_old_high_value_but_high_risk_is_decay_or_review_not_drain_first(): void
    {
        $r = $this->svc()->join([$this->packet('t1', 200000, 0.9, giveBack: 4)]);
        $rec = $r['recommendations'][0];

        // High value alone is not enough to drain_first when risk is high — old tasks aren't
        // blindly treated as important.
        $this->assertSame(AtlasMaestroQueueAgeValueDecayJoiner::ACTION_DECAY_OR_REVIEW, $rec['action']);
    }

    public function test_old_blocked_family_is_decay_or_review_even_with_high_value(): void
    {
        $r = $this->svc()->join([$this->packet('t1', 200000, 0.9, blockedFamily: true)]);
        $rec = $r['recommendations'][0];

        $this->assertSame(AtlasMaestroQueueAgeValueDecayJoiner::ACTION_DECAY_OR_REVIEW, $rec['action']);
        $this->assertContains('blocked_family', $rec['reason_codes']);
    }

    // ── AC3: fresh high-value → protect_priority ────────────────────────────────

    public function test_fresh_high_value_is_protect_priority(): void
    {
        $r = $this->svc()->join([$this->packet('t1', 50, 0.9)]);
        $rec = $r['recommendations'][0];

        $this->assertSame('fresh', $rec['age_bucket']);
        $this->assertSame(AtlasMaestroQueueAgeValueDecayJoiner::ACTION_PROTECT_PRIORITY, $rec['action']);
    }

    public function test_fresh_low_value_is_not_protect_priority(): void
    {
        $r = $this->svc()->join([$this->packet('t1', 50, 0.1)]);
        $rec = $r['recommendations'][0];

        $this->assertNotSame(AtlasMaestroQueueAgeValueDecayJoiner::ACTION_PROTECT_PRIORITY, $rec['action']);
    }

    // ── aging bucket / keep waiting ───────────────────────────────────────────────

    public function test_aging_medium_value_is_keep_waiting(): void
    {
        $r = $this->svc()->join([$this->packet('t1', 5000, 0.5)]);
        $rec = $r['recommendations'][0];

        $this->assertSame('aging', $rec['age_bucket']);
        $this->assertSame(AtlasMaestroQueueAgeValueDecayJoiner::ACTION_KEEP_WAITING, $rec['action']);
    }

    // ── multiple packets sorted deterministically ───────────────────────────────

    public function test_multiple_packets_are_each_scored_and_sorted_by_task_id(): void
    {
        $r = $this->svc()->join([
            $this->packet('zzz', 50, 0.9),
            $this->packet('aaa', 200000, 0.9),
        ]);

        $this->assertCount(2, $r['recommendations']);
        $this->assertSame(['aaa', 'zzz'], array_column($r['recommendations'], 'task_packet_id'));
    }

    // ── decay_score ──────────────────────────────────────────────────────────────

    public function test_decay_score_is_higher_for_older_lower_value_riskier_packets(): void
    {
        $low = $this->svc()->join([$this->packet('t1', 50, 0.9)])['recommendations'][0]['decay_score'];
        $high = $this->svc()->join([$this->packet('t2', 200000, 0.1, giveBack: 5)])['recommendations'][0]['decay_score'];

        $this->assertGreaterThan($low, $high);
    }

    public function test_decay_score_is_a_float(): void
    {
        $r = $this->svc()->join([$this->packet('t1', 100, 0.5)]);

        $this->assertIsFloat($r['recommendations'][0]['decay_score']);
    }

    // ── AC4: deterministic, read-only, no side effects ──────────────────────────

    public function test_join_does_not_touch_the_filesystem(): void
    {
        $dir = sys_get_temp_dir();
        $countBefore = count(scandir($dir) ?: []);

        $this->svc()->join([$this->packet('t1', 100, 0.5)]);

        $countAfter = count(scandir($dir) ?: []);
        $this->assertSame($countBefore, $countAfter);
    }

    public function test_join_is_deterministic(): void
    {
        $packets = [$this->packet('t1', 200000, 0.9), $this->packet('t2', 50, 0.2)];

        $a = $this->svc()->join($packets);
        $b = $this->svc()->join($packets);

        $this->assertSame(json_encode($a, JSON_UNESCAPED_SLASHES), json_encode($b, JSON_UNESCAPED_SLASHES));
    }

    public function test_empty_input_produces_empty_recommendations(): void
    {
        $r = $this->svc()->join([]);

        $this->assertSame([], $r['recommendations']);
    }
}
