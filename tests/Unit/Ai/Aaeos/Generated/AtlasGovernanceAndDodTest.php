<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasGovernanceAndDodService;
use Tests\TestCase;

/**
 * Pins the documented governance contracts from
 * docs/engineering-knowledge-base/operating-system/governance-and-dod.md.
 *
 * Pure, no DB, no RefreshDatabase.
 */
final class AtlasGovernanceAndDodTest extends TestCase
{
    private function service(): AtlasGovernanceAndDodService
    {
        return new AtlasGovernanceAndDodService();
    }

    /** The ten Horizontal Layers must be exactly the doc list, in doc order. */
    public function test_horizontal_layers_are_the_ten_doc_layers_in_order(): void
    {
        $result = $this->service()->horizontalLayers();

        $this->assertSame([
            'intent', 'context', 'policy', 'decide', 'tools',
            'memory', 'validation', 'repair', 'evidence', 'evolution',
        ], $result['layers']);
        $this->assertSame(10, $result['count']);
    }

    /** Anti-Duplication rule 1: a multi-surface feature routes to Core. */
    public function test_rule1_multi_surface_capability_routes_to_core(): void
    {
        $result = $this->service()->classifyCapability([
            'surfaces' => ['desktop', 'mobile', 'api'],
        ]);

        $this->assertSame(AtlasGovernanceAndDodService::ROUTE_CORE, $result['route']);
        $this->assertSame(1, $result['rule']);
        $this->assertFalse($result['is_violation']);
        $this->assertSame('Atlas AI Core', $result['owner']);
    }

    /**
     * Anti-Duplication rule 8 (highest priority): an alias that re-implements
     * its own logic while a canonical flow already exists is a duplication
     * violation — even if it is also multi-surface.
     */
    public function test_rule8_alias_reimplementing_canonical_flow_is_a_violation(): void
    {
        $result = $this->service()->classifyCapability([
            'is_alias' => true,
            'canonical_flow_exists' => true,
            'implements_own_logic' => true,
            // multi-surface too, but rule 8 must win:
            'surfaces' => ['desktop', 'mobile'],
        ]);

        $this->assertSame(AtlasGovernanceAndDodService::ROUTE_DUPLICATION_VIOLATION, $result['route']);
        $this->assertSame(8, $result['rule']);
        $this->assertTrue($result['is_violation']);
    }

    /**
     * Rule 7: a Forge gate relevant to Dev that is neither shared nor justified
     * is a violation; once shared/justified it routes to the engineering
     * harness owner.
     */
    public function test_rule7_forge_dev_gate_requires_shared_or_justified(): void
    {
        $svc = $this->service();

        $violation = $svc->classifyCapability(['forge_gate_relevant_to_dev' => true]);
        $this->assertTrue($violation['is_violation']);
        $this->assertSame(7, $violation['rule']);

        $ok = $svc->classifyCapability([
            'forge_gate_relevant_to_dev' => true,
            'shared_or_justified' => true,
        ]);
        $this->assertFalse($ok['is_violation']);
        $this->assertSame(AtlasGovernanceAndDodService::ROUTE_FORGE_SHARED, $ok['route']);
        $this->assertSame('Engineering Harness', $ok['owner']);
    }

    /** Ownership table: known layer resolves; unknown layer is a governance gap. */
    public function test_owner_for_layer_resolves_known_and_flags_unknown(): void
    {
        $svc = $this->service();

        $tools = $svc->ownerForLayer('Tools');
        $this->assertTrue($tools['known']);
        $this->assertSame('Super Tool Runtime', $tools['owner']);

        $unknown = $svc->ownerForLayer('quantum-layer');
        $this->assertFalse($unknown['known']);
        $this->assertNull($unknown['owner']);
    }

    /**
     * Flow DoD: a flow with all 11 parts present (learning applicable) is
     * mature; dropping a single mandatory part (regression_tests) flips it to
     * not_mature and names the gap.
     */
    public function test_flow_dod_requires_every_part_and_names_gaps(): void
    {
        $svc = $this->service();

        $allParts = [
            'domain_and_owner' => true,
            'canonical_pipeline' => true,
            'governed_context_and_memory' => true,
            'policy_profile' => true,
            'executor' => true,
            'gates' => true,
            'repair_escalation' => true,
            'evidence_packet' => true,
            'learning' => true,
            'canonical_docs' => true,
            'regression_tests' => true,
        ];

        $mature = $svc->evaluateFlowDod(['parts' => $allParts, 'learning_applicable' => true]);
        $this->assertTrue($mature['is_mature']);
        $this->assertSame('mature', $mature['verdict']);
        $this->assertSame(11, $mature['total_required']);
        $this->assertSame([], $mature['missing_parts']);

        // Drop the mandatory regression_tests part.
        $withoutTests = $allParts;
        $withoutTests['regression_tests'] = false;
        $notMature = $svc->evaluateFlowDod(['parts' => $withoutTests, 'learning_applicable' => true]);
        $this->assertFalse($notMature['is_mature']);
        $this->assertSame('not_mature', $notMature['verdict']);
        $this->assertContains('regression_tests', $notMature['missing_parts']);
    }

    /**
     * "learning when applicable": when learning is NOT applicable it is excluded
     * from the required set, so a flow missing only learning is still mature and
     * the required count drops to 10.
     */
    public function test_learning_is_only_required_when_applicable(): void
    {
        $svc = $this->service();

        $parts = [
            'domain_and_owner' => true,
            'canonical_pipeline' => true,
            'governed_context_and_memory' => true,
            'policy_profile' => true,
            'executor' => true,
            'gates' => true,
            'repair_escalation' => true,
            'evidence_packet' => true,
            // learning intentionally absent
            'canonical_docs' => true,
            'regression_tests' => true,
        ];

        $result = $svc->evaluateFlowDod(['parts' => $parts, 'learning_applicable' => false]);
        $this->assertTrue($result['is_mature']);
        $this->assertSame(10, $result['total_required']);
        $this->assertNotContains('learning', $result['required_parts']);
    }
}
