<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Aaeos;

use App\Services\Ai\Aaeos\Control\AaeosCockpitAttentionPartition;
use Tests\TestCase;

/**
 * P2e / R81: cockpit partitions sovereign H1–H7 from read-only review; never eng pass / global halt.
 */
final class AaeosCockpitAttentionPartitionTest extends TestCase
{
    public function test_h_gate_signal_is_sovereign_attention(): void
    {
        $part = AaeosCockpitAttentionPartition::partition([
            'h_gate' => 'H4',
            'attention_kind' => 'review',
        ]);

        $this->assertTrue($part['accepted']);
        $this->assertTrue($part['sovereign_attention']);
        $this->assertSame(AaeosCockpitAttentionPartition::KIND_SOVEREIGN, $part['attention_kind']);
        $this->assertFalse($part['can_mint_engineering_outcome']);
        $this->assertFalse($part['can_global_halt']);
        $this->assertFalse($part['blocks_unrelated_work']);
    }

    public function test_read_only_review_fail_open_display_but_cannot_qualify(): void
    {
        $part = AaeosCockpitAttentionPartition::partition([
            'attention_kind' => 'read_only_review',
        ]);

        $this->assertTrue($part['accepted']);
        $this->assertTrue($part['read_only_review']);
        $this->assertTrue($part['fail_open_display']);
        $this->assertTrue($part['fail_closed_qualification']);
        $this->assertFalse($part['can_mint_engineering_outcome']);
    }

    public function test_technical_queue_as_halt_and_human_accept_as_eng_pass_blocked(): void
    {
        $halt = AaeosCockpitAttentionPartition::partition([
            'attention_kind' => 'technical_history',
            'technical_queue_as_halt' => true,
            'global_halt' => true,
        ]);
        $this->assertFalse($halt['accepted']);
        $this->assertContains('technical_review_queue_not_global_halt', $halt['blockers']);
        $this->assertContains('cockpit_global_halt_forbidden', $halt['blockers']);

        $eng = AaeosCockpitAttentionPartition::partition([
            'attention_kind' => 'sovereign_attention',
            'human_accept_as_eng_pass' => true,
            'mints_engineering_outcome' => true,
        ]);
        $this->assertFalse($eng['accepted']);
        $this->assertContains('human_accept_reject_cannot_mint_eng_pass', $eng['blockers']);
        $this->assertContains('cockpit_cannot_mint_engineering_outcome', $eng['blockers']);
    }
}
