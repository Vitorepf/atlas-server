<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasPacketQueueContractService;
use Tests\TestCase;

/**
 * Pins the documented Packet Queue contract: the queue_state closed set and its
 * precedence, the Ranking Rules, the Required Guarantees, and the Non Goals
 * (blocked/withheld work is never hidden). Pure, no DB, no RefreshDatabase.
 *
 * @see docs/engineering-knowledge-base/self-construction/packet-queue-contract.md
 */
class AtlasPacketQueueContractTest extends TestCase
{
    private function service(): AtlasPacketQueueContractService
    {
        return new AtlasPacketQueueContractService;
    }

    /**
     * Build a clean, dependency-free, validatable, low-risk packet.
     *
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function packet(array $overrides = []): array
    {
        return array_merge([
            'packet_id' => 'AIP-SPLIT-DOCS-0001',
            'lane' => 'docs',
            'objective' => 'Author a documentation contract.',
            'depends_on' => [],
            'allowed_files' => ['docs/engineering-knowledge-base/self-construction/packet-queue-contract.md'],
            'forbidden_files' => ['routes/', 'database/migrations/'],
            'collision_risk' => 'none',
            'claim_policy' => 'single_owner',
            'provider_profile' => 'claude',
            'scope_validator_command' => 'php artisan atlas:engineering:knowledge docs-health --json',
        ], $overrides);
    }

    /**
     * Index entries by packet_id for assertion convenience.
     *
     * @param  array<string,mixed>  $result
     * @return array<string,array<string,mixed>>
     */
    private function entriesById(array $result): array
    {
        $byId = [];
        foreach ($result['queue']['entries'] as $entry) {
            $byId[$entry['packet_id']] = $entry;
        }

        return $byId;
    }

    public function test_required_guarantees_are_always_false_and_state_set_is_honoured(): void
    {
        $result = $this->service()->preview(['packets' => [$this->packet()]]);

        // Required Guarantees — the preview MUST emit all four as false.
        $this->assertFalse($result['execution_allowed']);
        $this->assertFalse($result['claim_persisted']);
        $this->assertFalse($result['ledger_write_allowed']);
        $this->assertFalse($result['queue_write_allowed']);
        // Non Goals — completion is never enabled.
        $this->assertFalse($result['completion_allowed']);
        $this->assertSame(AtlasPacketQueueContractService::STATUS_READY, $result['status']);
        $this->assertSame(AtlasPacketQueueContractService::SCHEMA, $result['schema_version']);

        // A clean packet with no reservation is `available` and recommended.
        $entry = $this->entriesById($result)['AIP-SPLIT-DOCS-0001'];
        $this->assertSame(AtlasPacketQueueContractService::STATE_AVAILABLE, $entry['queue_state']);
        $this->assertSame(1, $entry['rank']);
        $this->assertTrue($entry['recommended']);
        $this->assertSame('AIP-SPLIT-DOCS-0001', $result['queue']['recommended_packet_id']);
    }

    public function test_queue_state_precedence_completed_claimed_blocked_available(): void
    {
        // Four packets exercise every reservation/dependency branch at once.
        $result = $this->service()->preview([
            'packets' => [
                $this->packet(['packet_id' => 'P-AVAIL', 'depends_on' => []]),
                $this->packet(['packet_id' => 'P-BLOCKED', 'depends_on' => ['P-NOT-DONE']]),
                $this->packet(['packet_id' => 'P-CLAIMED']),
                $this->packet(['packet_id' => 'P-DONE']),
            ],
            'active_reservations' => [
                'P-CLAIMED' => ['reservation_id' => 'RES-A', 'actor' => 'codex-a', 'session' => 'session-a'],
            ],
            'completed_reservations' => [
                'P-DONE' => ['reservation_id' => 'RES-Z', 'actor' => 'gemini-a', 'completed_at' => '2026-06-01T00:00:00Z'],
            ],
        ]);

        $byId = $this->entriesById($result);
        $this->assertSame(AtlasPacketQueueContractService::STATE_AVAILABLE, $byId['P-AVAIL']['queue_state']);
        $this->assertSame(AtlasPacketQueueContractService::STATE_BLOCKED, $byId['P-BLOCKED']['queue_state']);
        $this->assertSame(AtlasPacketQueueContractService::STATE_CLAIMED, $byId['P-CLAIMED']['queue_state']);
        $this->assertSame(AtlasPacketQueueContractService::STATE_COMPLETED, $byId['P-DONE']['queue_state']);

        // Reservation owner/session are surfaced (claim_persisted=false but the
        // queue may still REPORT claims/completions made elsewhere).
        $this->assertSame('codex-a', $byId['P-CLAIMED']['active_reservation_actor']);
        $this->assertSame('gemini-a', $byId['P-DONE']['completion_actor']);

        // Only the available packet is ranked/recommended; the rest carry null.
        $this->assertSame(1, $byId['P-AVAIL']['rank']);
        $this->assertNull($byId['P-BLOCKED']['rank']);
        $this->assertNull($byId['P-CLAIMED']['rank']);
        $this->assertSame('P-AVAIL', $result['queue']['recommended_packet_id']);

        // Counts reflect every state.
        $this->assertSame(1, $result['queue']['available_count']);
        $this->assertSame(1, $result['queue']['blocked_count']);
        $this->assertSame(1, $result['queue']['claimed_count']);
        $this->assertSame(1, $result['queue']['completed_count']);
    }

    public function test_completed_dependency_unlocks_blocked_packet(): void
    {
        // Same dependent packet, but now its dependency is completed → available.
        $result = $this->service()->preview([
            'packets' => [
                $this->packet(['packet_id' => 'P-DEP', 'depends_on' => ['P-ROOT']]),
            ],
            'completed_reservations' => [
                'P-ROOT' => ['reservation_id' => 'RES-ROOT', 'actor' => 'local-a', 'completed_at' => '2026-06-01T00:00:00Z'],
            ],
        ]);

        $entry = $this->entriesById($result)['P-DEP'];
        $this->assertSame(AtlasPacketQueueContractService::STATE_AVAILABLE, $entry['queue_state']);
        $this->assertSame(1, $entry['rank']);
    }

    public function test_ranking_prefers_dependency_free_disjoint_non_hot_packet(): void
    {
        // Three available packets; ranking must put the dependency-free, disjoint,
        // cold-scope, validatable one first regardless of input order.
        $hot = $this->packet([
            'packet_id' => 'P-HOT',
            'allowed_files' => ['runtimes/python/voice_realtime/realtime_session.py'],
            'collision_risk' => 'low',
        ]);
        $best = $this->packet([
            'packet_id' => 'P-BEST',
            'allowed_files' => ['docs/engineering-knowledge-base/self-construction/best.md'],
            'collision_risk' => 'none',
        ]);
        $shared = $this->packet([
            'packet_id' => 'P-SHARED',
            // Shares the SAME allowed file as P-BEST → disjoint penalty.
            'allowed_files' => ['docs/engineering-knowledge-base/self-construction/best.md'],
            'collision_risk' => 'none',
        ]);

        $result = $this->service()->preview(['packets' => [$hot, $best, $shared]]);
        $byId = $this->entriesById($result);

        // Best (cold, disjoint owner, no deps, low risk) ranks #1 and recommended.
        $this->assertSame(1, $byId['P-BEST']['rank']);
        $this->assertSame('P-BEST', $result['queue']['recommended_packet_id']);
        $this->assertTrue($byId['P-BEST']['recommended']);

        // The hot Voice scope packet ranks strictly worse than the cold one.
        $this->assertGreaterThan($byId['P-BEST']['rank'], $byId['P-HOT']['rank']);

        // The later sharer of P-BEST's file is flagged non-disjoint and ranks
        // worse than the file owner.
        $this->assertSame(['docs/engineering-knowledge-base/self-construction/best.md'], $byId['P-SHARED']['shared_allowed_files']);
        $this->assertGreaterThan($byId['P-BEST']['rank'], $byId['P-SHARED']['rank']);

        // Score sanity (computed directly from the source packets): a clean,
        // disjoint, cold packet scores strictly higher than the hot one.
        $this->assertGreaterThan(
            $this->service()->rankScore($hot, []),
            $this->service()->rankScore($best, []),
        );
        // The hot-scope penalty alone separates them: same packet minus the hot
        // path scores higher than with it.
        $this->assertGreaterThan(
            $this->service()->rankScore($hot, []),
            $this->service()->rankScore(array_merge($hot, ['allowed_files' => ['app/Services/Ai/Cold.php']]), []),
        );
    }

    public function test_withheld_hot_work_is_visible_not_assignable_and_not_hidden(): void
    {
        // Non Goal: "Do not hide blocked or withheld work." Withheld work appears
        // as its own entry, never assignable, never ranked.
        $result = $this->service()->preview([
            'packets' => [$this->packet(['packet_id' => 'P-OK'])],
            'withheld' => [
                [
                    'packet_id' => 'P-WITHHELD',
                    'reason' => 'Hot external work owned by another active front.',
                    'forbidden_scope' => ['runtimes/python/voice_realtime/x.py'],
                ],
            ],
        ]);

        $byId = $this->entriesById($result);
        $this->assertArrayHasKey('P-WITHHELD', $byId);
        $this->assertSame(AtlasPacketQueueContractService::STATE_WITHHELD, $byId['P-WITHHELD']['queue_state']);
        $this->assertSame('not_assignable', $byId['P-WITHHELD']['claim_policy']);
        $this->assertNull($byId['P-WITHHELD']['rank']);
        $this->assertFalse($byId['P-WITHHELD']['recommended']);
        $this->assertSame(1, $result['queue']['withheld_count']);
        // Every input packet (assignable + withheld) is present exactly once.
        $this->assertSame(2, $result['queue']['entry_count']);
    }

    public function test_queue_hash_is_deterministic_and_changes_with_content(): void
    {
        $a = $this->service()->preview(['packets' => [$this->packet()]]);
        $b = $this->service()->preview(['packets' => [$this->packet()]]);
        $c = $this->service()->preview(['packets' => [$this->packet(['collision_risk' => 'high'])]]);

        $this->assertSame($a['queue_hash'], $b['queue_hash']);
        $this->assertNotSame($a['queue_hash'], $c['queue_hash']);
    }
}
