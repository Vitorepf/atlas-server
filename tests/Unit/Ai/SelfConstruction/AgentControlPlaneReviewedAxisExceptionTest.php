<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\AtlasTaskPacketQualityInspector;
use PHPUnit\Framework\TestCase;

/**
 * Proves the narrow reviewed-axis-exception path on AgentControlPlaneTaskPacketBuilder:
 * a packet from the lead-authoring source (autonomous-gov-bootstrap) may exempt an exact
 * FORBIDDEN_AXES prefix and records axis_exception_granted; every other source keeps the
 * axis rejection byte-identical to today; a pétreo self-target rejection (enforced downstream
 * by AtlasTaskPacketQualityInspector, untouched by this mechanism) still applies; and an
 * unknown exception prefix leaves the axis enforced.
 */
final class AgentControlPlaneReviewedAxisExceptionTest extends TestCase
{
    private function builder(): AgentControlPlaneTaskPacketBuilder
    {
        return new AgentControlPlaneTaskPacketBuilder;
    }

    private function baseInput(array $overrides = []): array
    {
        return array_merge([
            'objective' => 'Reviewed gov-lane packet for the Programming pipeline',
            'allowed_files' => ['app/Services/Ai/Programming/AtlasCodeGenerator.php'],
            'acceptance_criteria' => ['php artisan test --filter=AtlasCodeGeneratorTest exits 0'],
            'required_evidence' => ['tests_or_gates_result'],
        ], $overrides);
    }

    // ── (a) autonomous-gov-bootstrap + reviewed exception builds as planned ──────

    public function test_gov_bootstrap_source_with_programming_axis_exception_builds_planned_with_grant_recorded(): void
    {
        $packet = $this->builder()->build($this->baseInput([
            'source' => 'autonomous-gov-bootstrap',
            'reviewed_axis_exceptions' => [AgentControlPlaneTaskPacketBuilder::FORBIDDEN_AXES[1]], // Programming/
        ]));

        $this->assertSame('planned', $packet['status']);
        $this->assertNotNull($packet['axis_exception_granted']);
        $this->assertSame([AgentControlPlaneTaskPacketBuilder::FORBIDDEN_AXES[1]], $packet['axis_exception_granted']['axes']);
        $this->assertSame('autonomous-gov-bootstrap', $packet['axis_exception_granted']['source']);
        $this->assertSame([], $packet['normalized_scope']['forbidden_axis_hits']);
    }

    // ── (b) any other source keeps the axis rejection exactly as today ───────────

    public function test_other_sources_keep_axis_rejection_even_with_the_same_exception_entry(): void
    {
        foreach (['operator_intake', 'external_brain_originator', 'autonomous_replenisher', ''] as $source) {
            $overrides = ['reviewed_axis_exceptions' => [AgentControlPlaneTaskPacketBuilder::FORBIDDEN_AXES[1]]];
            if ($source !== '') {
                $overrides['source'] = $source;
            }
            $packet = $this->builder()->build($this->baseInput($overrides));

            $this->assertSame('blocked', $packet['status'], "source={$source} must stay blocked");
            $this->assertNull($packet['axis_exception_granted'], "source={$source} must not grant an exception");
            $this->assertNotEmpty($packet['normalized_scope']['forbidden_axis_hits']);
        }
    }

    // ── (c) petreo self-target rejection is unaffected by the exception mechanism ──

    public function test_petreo_self_target_rejection_persists_despite_axis_exception(): void
    {
        $packet = $this->builder()->build($this->baseInput([
            'source' => 'autonomous-gov-bootstrap',
            'allowed_files' => ['app/Services/Ai/Programming/AtlasLoopHarnessGuard.php'],
            'reviewed_axis_exceptions' => [AgentControlPlaneTaskPacketBuilder::FORBIDDEN_AXES[1]],
        ]));

        // The builder's own axis gate is exempted...
        $this->assertSame('planned', $packet['status']);
        $this->assertNotNull($packet['axis_exception_granted']);

        // ...but the pétreo self-target rejection is a SEPARATE downstream mechanism
        // (AtlasTaskPacketQualityInspector), never touched by this change, and still fires.
        $quality = (new AtlasTaskPacketQualityInspector)->inspect($packet);
        $this->assertFalse($quality['self_sufficient']);
        $this->assertContains('forbidden_self_target_in_allowed_files', $quality['blocking_deficiencies']);
    }

    // ── (d) an unknown exception prefix leaves the axis enforced ──────────────────

    public function test_unknown_exception_prefix_leaves_the_axis_enforced(): void
    {
        $packet = $this->builder()->build($this->baseInput([
            'source' => 'autonomous-gov-bootstrap',
            'reviewed_axis_exceptions' => ['app/Services/Ai/NotARealAxis/'],
        ]));

        $this->assertSame('blocked', $packet['status']);
        $this->assertNull($packet['axis_exception_granted']);
        $this->assertNotEmpty($packet['normalized_scope']['forbidden_axis_hits']);
    }

    public function test_no_reviewed_axis_exceptions_input_behaves_exactly_as_before(): void
    {
        $packet = $this->builder()->build($this->baseInput(['source' => 'autonomous-gov-bootstrap']));

        $this->assertSame('blocked', $packet['status']);
        $this->assertNull($packet['axis_exception_granted']);
    }
}
