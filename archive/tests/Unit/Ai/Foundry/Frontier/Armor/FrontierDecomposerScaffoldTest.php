<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Foundry\Frontier\Armor;

use App\Services\Ai\Foundry\Frontier\Armor\FrontierDecomposerGate;
use PHPUnit\Framework\TestCase;

/**
 * Finding 22 armor: the I7 scaffold-only detector must scan delivery markers
 * UNCONDITIONALLY. A scaffold delivery paired with a single acceptance/test line
 * that is ITSELF a scaffold marker (placeholder/stub) must NOT bypass the
 * no-scaffold gate. Genuine non-marker acceptance/tests still pass; non-scaffold
 * deliveries and genuinely-bounded packets are unaffected (no length-based gating).
 */
final class FrontierDecomposerScaffoldTest extends TestCase
{
    public function test_scaffold_delivery_with_marker_acceptance_line_is_scaffold_only(): void
    {
        // Regression for Finding 22: a junk/marker acceptance line used to
        // short-circuit isScaffoldOnly() to false and bypass the gate.
        $packet = [
            'packet_id' => 'pkt_marker_accept',
            'delivery' => 'Add a gate_compat_contract interface-only scaffold.',
            'acceptance_criteria' => ['placeholder assertion'],
            'tests_required' => [],
        ];

        $this->assertTrue(
            FrontierDecomposerGate::isScaffoldOnly($packet),
            'A scaffold delivery rescued only by a marker acceptance line must remain scaffold-only.',
        );
    }

    public function test_scaffold_delivery_with_marker_test_line_is_scaffold_only(): void
    {
        $packet = [
            'packet_id' => 'pkt_marker_test',
            'delivery' => 'Provide a stub type-hint skeleton only.',
            'acceptance_criteria' => [],
            'tests_required' => ['add a no-op test'],
        ];

        $this->assertTrue(
            FrontierDecomposerGate::isScaffoldOnly($packet),
            'A scaffold delivery rescued only by a marker test line must remain scaffold-only.',
        );
    }

    public function test_scaffold_delivery_with_genuine_acceptance_line_passes(): void
    {
        $packet = [
            'packet_id' => 'pkt_genuine_accept',
            'delivery' => 'Add a gate_compat_contract interface-only scaffold.',
            'acceptance_criteria' => ['php artisan test passes with measured signal X'],
            'tests_required' => [],
        ];

        $this->assertFalse(
            FrontierDecomposerGate::isScaffoldOnly($packet),
            'A scaffold delivery with a genuine (non-marker) acceptance line must pass I7.2.',
        );
    }

    public function test_scaffold_delivery_with_genuine_test_line_passes(): void
    {
        $packet = [
            'packet_id' => 'pkt_genuine_test',
            'delivery' => 'Provide a stub interface-only scaffold.',
            'acceptance_criteria' => [],
            'tests_required' => ['tests/Unit/Ai/Foundry/Frontier/RealBehaviorTest.php'],
        ];

        $this->assertFalse(
            FrontierDecomposerGate::isScaffoldOnly($packet),
            'A scaffold delivery with a genuine (non-marker) test line must pass I7.2.',
        );
    }

    public function test_non_scaffold_delivery_with_no_acceptance_or_tests_is_not_scaffold_only(): void
    {
        $packet = [
            'packet_id' => 'pkt_real_delivery',
            'delivery' => 'Implement one focused method computing the rollback signal.',
            'acceptance_criteria' => [],
            'tests_required' => [],
        ];

        $this->assertFalse(
            FrontierDecomposerGate::isScaffoldOnly($packet),
            'A non-scaffold delivery is never scaffold-only, regardless of acceptance/test count.',
        );
    }

    public function test_empty_delivery_with_no_acceptance_or_tests_is_scaffold_only(): void
    {
        $packet = [
            'packet_id' => 'pkt_empty',
            'delivery' => '',
            'acceptance_criteria' => [],
            'tests_required' => [],
        ];

        $this->assertTrue(
            FrontierDecomposerGate::isScaffoldOnly($packet),
            'An empty delivery with no acceptance and no tests is the canonical empty-contract case.',
        );
    }

    public function test_empty_delivery_with_genuine_acceptance_is_not_scaffold_only(): void
    {
        $packet = [
            'packet_id' => 'pkt_empty_with_accept',
            'delivery' => '',
            'acceptance_criteria' => ['real measured criterion'],
            'tests_required' => [],
        ];

        $this->assertFalse(
            FrontierDecomposerGate::isScaffoldOnly($packet),
            'An empty delivery rescued by a genuine acceptance line is not scaffold-only.',
        );
    }

    /**
     * Genuinely-bounded packet: short but real delivery + real acceptance + real
     * test. Gating is on marker-matching, NOT length — so this must pass. Proves
     * the no-scaffold gate is no weaker (still strictly tighter, no regression).
     */
    public function test_genuinely_bounded_packet_still_passes_no_length_regression(): void
    {
        $packet = [
            'packet_id' => 'pkt_bounded',
            'delivery' => 'Add metric M.',
            'acceptance_criteria' => ['metric M emitted'],
            'tests_required' => ['tests/Unit/MTest.php'],
        ];

        $this->assertFalse(
            FrontierDecomposerGate::isScaffoldOnly($packet),
            'A genuinely-bounded packet must still pass; gating is on markers, not length.',
        );
    }

    /**
     * Invariant guard: the detector is strictly TIGHTER than before. The exact
     * case the old length-based short-circuit let through (scaffold delivery +
     * single marker acceptance line) is now caught, and every case the old gate
     * already caught (scaffold delivery + empty acceptance + empty tests) is still
     * caught. No previously-dropped scaffold becomes passing.
     */
    public function test_detector_is_no_weaker_than_legacy(): void
    {
        $alreadyCaught = [
            'packet_id' => 'pkt_legacy',
            'delivery' => 'Add a scaffold stub.',
            'acceptance_criteria' => [],
            'tests_required' => [],
        ];
        $this->assertTrue(
            FrontierDecomposerGate::isScaffoldOnly($alreadyCaught),
            'Legacy scaffold-only case (empty acceptance + empty tests) must still be caught.',
        );

        $newlyCaught = [
            'packet_id' => 'pkt_new',
            'delivery' => 'Add a scaffold stub.',
            'acceptance_criteria' => ['stub'],
            'tests_required' => ['noop'],
        ];
        $this->assertTrue(
            FrontierDecomposerGate::isScaffoldOnly($newlyCaught),
            'Marker-only acceptance/test lines must no longer bypass the gate.',
        );
    }

    public function test_scaffold_delivery_drops_scaffold_only_packet_end_to_end(): void
    {
        $gate = new FrontierDecomposerGate;

        $scaffold = [
            'packet_id' => 'pkt_scaffold',
            'delivery' => 'Add a gate_compat_contract interface-only scaffold.',
            'acceptance_criteria' => ['placeholder'],
            'tests_required' => [],
        ];

        $result = $gate->adjudicate([
            'proposal_id' => 'prop_finding22',
            'proposed_packets' => [$scaffold, $scaffold, $scaffold],
        ]);

        $this->assertSame(FrontierDecomposerGate::STATUS_BLOCKED, $result['status']);
        $this->assertCount(1, $result['drops']);
        $this->assertSame(
            FrontierDecomposerGate::DROP_SCAFFOLD_ONLY_PACKET,
            $result['drops'][0]['reason'],
        );
        $this->assertFalse($result['mutates_repo']);
        $this->assertFalse($result['canonical_doc_write_allowed']);
        $this->assertFalse($result['executed']);
    }
}
