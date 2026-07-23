<?php

declare(strict_types=1);

namespace Tests\Feature\Foundry\Frontier;

use App\Services\Ai\Foundry\Frontier\Armor\FrontierDecomposerGate;
use App\Services\Ai\Foundry\Frontier\FrontierProposalPacketDecomposerService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\FindingSlicePlannerService;
use PHPUnit\Framework\TestCase;

final class FrontierDecomposerGateTest extends TestCase
{
    /**
     * A bounded, well-formed packet that the canonical planner slices: a real
     * source file paired with its matching focused test, plus acceptance.
     *
     * @return array<string,mixed>
     */
    private function goodPacket(int $n): array
    {
        $source = 'app/Services/Ai/Foundry/Frontier/Sample'.$n.'Service.php';
        $test = 'tests/Unit/Ai/Foundry/Frontier/Sample'.$n.'ServiceTest.php';

        return [
            'packet_id' => 'pkt_'.$n,
            'label' => 'Packet '.$n,
            'objective' => 'Add a measured behavior to Sample'.$n,
            'delivery' => 'Implement one focused method in Sample'.$n.'Service with a unit test.',
            'acceptance_criteria' => ['php artisan test '.$test.' passes'],
            'tests_required' => [$source, $test],
            'owner_candidate' => 'atlas_dev',
            'kind' => 'service',
            'canonical_property_mapping' => [
                'area_id' => 'agentic_engineering_os',
                'subsystem' => 'foundry_frontier',
                'schema' => 'atlas.foundry.evolution_proposal.v1',
                'measured_signal_ref' => 'signal:sample'.$n,
            ],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $packets
     * @return array<string,mixed>
     */
    private function proposal(array $packets): array
    {
        return [
            'proposal_id' => 'prop_alpha',
            'proposed_packets' => $packets,
        ];
    }

    public function test_good_proposal_with_bounded_sliceable_packets_survives(): void
    {
        $gate = new FrontierDecomposerGate;

        $result = $gate->adjudicate($this->proposal([
            $this->goodPacket(1),
            $this->goodPacket(2),
            $this->goodPacket(3),
        ]));

        $this->assertSame(FrontierDecomposerGate::STATUS_COMPLETE, $result['status']);
        $this->assertSame([], $result['drops']);
        $this->assertSame(3, $result['sliced_packets']);
        $this->assertSame(3, $result['packets_count']);
        // Zero canonical/code write — armor is decision-only.
        $this->assertFalse($result['mutates_repo']);
        $this->assertFalse($result['canonical_doc_write_allowed']);
        $this->assertFalse($result['executed']);
    }

    public function test_too_few_packets_dropped_with_decomposition_out_of_bounds(): void
    {
        $gate = new FrontierDecomposerGate;

        $result = $gate->adjudicate($this->proposal([
            $this->goodPacket(1),
            $this->goodPacket(2),
        ]));

        $this->assertSame(FrontierDecomposerGate::STATUS_BLOCKED, $result['status']);
        $this->assertCount(1, $result['drops']);
        $drop = $result['drops'][0];
        $this->assertSame('I7', $drop['drop_stage']);
        $this->assertSame(FrontierDecomposerGate::DROP_DECOMPOSITION_OUT_OF_BOUNDS, $drop['reason']);
        $this->assertNotSame('', $drop['detail']);
    }

    public function test_too_many_packets_dropped_with_decomposition_out_of_bounds(): void
    {
        $gate = new FrontierDecomposerGate;
        $packets = [];
        for ($i = 1; $i <= 13; $i++) {
            $packets[] = $this->goodPacket($i);
        }

        $result = $gate->adjudicate($this->proposal($packets));

        $this->assertSame(FrontierDecomposerGate::STATUS_BLOCKED, $result['status']);
        $this->assertSame(FrontierDecomposerGate::DROP_DECOMPOSITION_OUT_OF_BOUNDS, $result['drops'][0]['reason']);
        $this->assertSame(13, $result['packets_count']);
    }

    public function test_scaffold_only_packet_is_dropped_with_recorded_reason(): void
    {
        $gate = new FrontierDecomposerGate;

        $scaffold = [
            'packet_id' => 'pkt_scaffold',
            'label' => 'Empty contract',
            'objective' => 'Create the contract',
            'delivery' => 'Add a gate_compat_contract interface-only type-hint scaffold.',
            'acceptance_criteria' => [],
            'tests_required' => [],
            'owner_candidate' => 'atlas_dev',
            'kind' => 'contract',
            'canonical_property_mapping' => [],
        ];

        $result = $gate->adjudicate($this->proposal([
            $this->goodPacket(1),
            $scaffold,
            $this->goodPacket(3),
        ]));

        $this->assertSame(FrontierDecomposerGate::STATUS_BLOCKED, $result['status']);
        $this->assertSame(FrontierDecomposerGate::DROP_SCAFFOLD_ONLY_PACKET, $result['drops'][0]['reason']);
        $this->assertStringContainsString('scaffold-only', $result['drops'][0]['detail']);
    }

    public function test_is_scaffold_only_predicate(): void
    {
        // Empty acceptance + empty tests + scaffold delivery => scaffold-only.
        $this->assertTrue(FrontierDecomposerGate::isScaffoldOnly([
            'delivery' => 'just a stub',
            'acceptance_criteria' => [],
            'tests_required' => [],
        ]));
        // Empty acceptance + empty tests + empty delivery => scaffold-only.
        $this->assertTrue(FrontierDecomposerGate::isScaffoldOnly([
            'delivery' => '',
            'acceptance_criteria' => [],
            'tests_required' => [],
        ]));
        // Has tests => NOT scaffold-only even with scaffold delivery wording.
        $this->assertFalse(FrontierDecomposerGate::isScaffoldOnly([
            'delivery' => 'scaffold then implement',
            'acceptance_criteria' => [],
            'tests_required' => ['tests/Unit/X.php'],
        ]));
        // Has acceptance => NOT scaffold-only.
        $this->assertFalse(FrontierDecomposerGate::isScaffoldOnly([
            'delivery' => 'stub',
            'acceptance_criteria' => ['it passes'],
            'tests_required' => [],
        ]));
        // Real delivery, no scaffold marker => NOT scaffold-only.
        $this->assertFalse(FrontierDecomposerGate::isScaffoldOnly([
            'delivery' => 'implement the rule',
            'acceptance_criteria' => [],
            'tests_required' => [],
        ]));
    }

    public function test_packet_planner_block_yields_needs_operator_spec(): void
    {
        // Inject a decomposer whose planner callable refuses (operator review)
        // so the gate must drop with needs_operator_spec.
        $decomposer = new FrontierProposalPacketDecomposerService;
        $decomposer->setSlicePlannerCallableForTesting(static fn (array $input): array => [
            'decomposition_status' => FindingSlicePlannerService::STATUS_OPERATOR_REVIEW,
            'blockers' => [FindingSlicePlannerService::BLOCKER_OPERATOR_OR_ARCHITECT_SPEC_REQUIRED],
            'slices' => [],
        ]);

        $gate = new FrontierDecomposerGate($decomposer);

        $result = $gate->adjudicate($this->proposal([
            $this->goodPacket(1),
            $this->goodPacket(2),
            $this->goodPacket(3),
        ]));

        $this->assertSame(FrontierDecomposerGate::STATUS_BLOCKED, $result['status']);
        $this->assertSame(FrontierDecomposerGate::DROP_NEEDS_OPERATOR_SPEC, $result['drops'][0]['reason']);
    }

    public function test_finding_slice_planner_invoked_exactly_once_per_packet(): void
    {
        $calls = 0;
        $decomposer = new FrontierProposalPacketDecomposerService;
        $decomposer->setSlicePlannerCallableForTesting(static function (array $input) use (&$calls): array {
            $calls++;

            return [
                'decomposition_status' => FindingSlicePlannerService::STATUS_SLICED,
                'blockers' => [],
                'slices' => [['slice_id' => 'slice_x', 'allowed_files' => ['app/X.php']]],
            ];
        });

        $gate = new FrontierDecomposerGate($decomposer);

        $result = $gate->adjudicate($this->proposal([
            $this->goodPacket(1),
            $this->goodPacket(2),
            $this->goodPacket(3),
            $this->goodPacket(4),
        ]));

        $this->assertSame(FrontierDecomposerGate::STATUS_COMPLETE, $result['status']);
        // One planner call per packet — composition, not duplication.
        $this->assertSame(4, $calls);
    }
}
