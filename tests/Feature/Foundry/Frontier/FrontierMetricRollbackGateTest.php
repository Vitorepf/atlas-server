<?php

declare(strict_types=1);

namespace Tests\Feature\Foundry\Frontier;

use App\Services\Ai\Foundry\Frontier\Armor\FrontierMetricRollbackGate;
use App\Services\Ai\Foundry\FoundrySchemas;
use App\Services\Ai\Mission\MissionCanonicalHash;
use PHPUnit\Framework\TestCase;

final class FrontierMetricRollbackGateTest extends TestCase
{
    private function gate(): FrontierMetricRollbackGate
    {
        return new FrontierMetricRollbackGate;
    }

    /**
     * A fully-provenanced proposal: the 13 canonical keys PLUS sibling-shape
     * provenance keys that MUST be stripped before validateShape.
     *
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function provenancedProposal(array $overrides = []): array
    {
        $base = [
            'proposal_id' => 'prop-001',
            'horizon' => 'h2',
            'title' => 'Reduce p95 latency on area focus loop',
            'thesis' => 'Caching the dossier projection removes redundant scans.',
            'evidence_refs' => [
                ['anchor_id' => 'a1', 'anchor_hash' => 'sha256:aaa', 'anchor_type' => 'cycle'],
            ],
            'why_it_multiplies' => 'Compounds every downstream scan.',
            'success_metric' => 'p95_latency_ms <= 120',
            'rollback' => ['rollback_condition' => 'p95_latency_ms > 200 for 3 cycles'],
            'risk_level' => 'medium',
            'dependencies' => [],
            'proposed_packets' => [
                [
                    'packet_id' => 'pk1',
                    'label' => 'cache projection',
                    'objective' => 'cache',
                    'delivery' => 'service',
                    'acceptance_criteria' => ['latency drops'],
                    'tests_required' => ['LatencyTest'],
                    'owner_candidate' => 'foundry',
                    'kind' => 'optimization',
                    'canonical_property_mapping' => [
                        'area_id' => 'agentic_engineering_os',
                        'subsystem' => 'area_focus_loop',
                        'schema' => 'atlas.x.v1',
                        'measured_signal_ref' => 'sig-1',
                    ],
                ],
            ],
            'provider_tier_required' => 'premium',
            'anti_pattern_self_check' => 'no scaffold',
            // ---- sibling provenance keys (NOT part of the 13-key schema) ----
            'generator_label' => 'fixture:deterministic',
            'generator_input_hash' => 'sha256:deadbeef',
            'proposal_hash' => 'sha256:cafe',
        ];

        return array_replace($base, $overrides);
    }

    public function test_passes_falsifiable_metric_with_complete_rollback(): void
    {
        $result = $this->gate()->evaluate($this->provenancedProposal());

        $this->assertTrue($result['admit']);
        $this->assertNull($result['drop_reason']);
        $this->assertSame('I2', $result['stage']);
        $this->assertSame(
            ['operator' => '<=', 'baseline' => 'p95_latency_ms', 'threshold' => '120'],
            $result['falsifiable_metric'],
        );

        // Rollback contract mirrors merge-governor handoff shape.
        $this->assertSame(
            ['metric_id', 'baseline_hash', 'rollback_condition'],
            array_keys($result['rollback_contract']),
        );
        $this->assertSame(
            'p95_latency_ms > 200 for 3 cycles',
            $result['rollback_contract']['rollback_condition'],
        );
        // Deterministic stamping via MissionCanonicalHash.
        $this->assertSame(
            MissionCanonicalHash::sha256('p95_latency_ms'),
            $result['rollback_contract']['baseline_hash'],
        );
    }

    public function test_revalidates_shape_on_13_key_projection_for_provenanced_proposal(): void
    {
        // The raw provenanced object carries unexpected (provenance) keys that
        // must NOT reach the shape gate.
        $raw = $this->provenancedProposal();
        $rawShape = FoundrySchemas::validateShape(FoundrySchemas::EVOLUTION_PROPOSAL, $raw);
        $this->assertNotEmpty($rawShape['unexpected_keys']);
        $this->assertContains('proposal_hash', $rawShape['unexpected_keys']);

        // The gate strips provenance and the projection re-check passes.
        $result = $this->gate()->evaluate($raw);
        $this->assertTrue($result['projection_shape_valid']);
        $this->assertTrue($result['projection_shape']['valid']);
        $this->assertSame([], $result['projection_shape']['unexpected_keys']);
        $this->assertSame([], $result['projection_shape']['missing']);
    }

    public function test_drops_when_success_metric_empty(): void
    {
        $result = $this->gate()->evaluate($this->provenancedProposal(['success_metric' => '']));

        $this->assertFalse($result['admit']);
        $this->assertSame('metric_or_rollback_absent', $result['drop_reason']);
        $this->assertNotSame('', (string) $result['detail']);
        $this->assertNull($result['rollback_contract']);
    }

    public function test_drops_when_rollback_empty(): void
    {
        $result = $this->gate()->evaluate($this->provenancedProposal(['rollback' => []]));

        $this->assertFalse($result['admit']);
        $this->assertSame('metric_or_rollback_absent', $result['drop_reason']);
    }

    public function test_drops_when_metric_not_falsifiable(): void
    {
        $result = $this->gate()->evaluate($this->provenancedProposal([
            'success_metric' => 'make it faster please',
        ]));

        $this->assertFalse($result['admit']);
        $this->assertSame('metric_not_falsifiable', $result['drop_reason']);
        $this->assertNull($result['falsifiable_metric']);
    }

    public function test_drops_structured_metric_missing_threshold(): void
    {
        $result = $this->gate()->evaluate($this->provenancedProposal([
            'success_metric' => ['operator' => '<=', 'baseline' => 'p95'],
        ]));

        $this->assertFalse($result['admit']);
        $this->assertSame('metric_not_falsifiable', $result['drop_reason']);
    }

    public function test_drops_when_rollback_missing_condition(): void
    {
        $result = $this->gate()->evaluate($this->provenancedProposal([
            'rollback' => ['some_other_key' => 'noise'],
        ]));

        $this->assertFalse($result['admit']);
        $this->assertSame('rollback_contract_incomplete', $result['drop_reason']);
        $this->assertNull($result['rollback_contract']);
    }

    public function test_accepts_structured_falsifiable_metric(): void
    {
        $result = $this->gate()->evaluate($this->provenancedProposal([
            'success_metric' => ['operator' => '>=', 'baseline' => '40', 'threshold' => '45'],
        ]));

        $this->assertTrue($result['admit']);
        $this->assertSame('>=', $result['falsifiable_metric']['operator']);
    }

    public function test_gate_is_pure_read_only_no_side_effects(): void
    {
        $proposal = $this->provenancedProposal();
        $snapshot = $proposal;

        $result = $this->gate()->evaluate($proposal);

        // ZERO mutation of the provenanced input (no canonical/code write path).
        $this->assertSame($snapshot, $proposal);
        $this->assertArrayHasKey('generator_label', $proposal);
        $this->assertTrue($result['admit']);
    }
}
