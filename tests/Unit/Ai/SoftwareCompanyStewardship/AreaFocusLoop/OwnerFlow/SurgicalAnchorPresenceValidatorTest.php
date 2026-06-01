<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\SurgicalAnchorPresenceValidator;
use Tests\TestCase;

final class SurgicalAnchorPresenceValidatorTest extends TestCase
{
    public function testTargetMethodYieldsConcreteDecision(): void
    {
        $result = $this->validator()->validate(['target_method' => 'handleX']);

        $this->assertSame('anchor_concrete', $result['decision']);
        $this->assertSame('target_method', $result['matched_path']);
    }

    public function testPlannerStyleSurgicalAnchorWithMethodYieldsConcreteDecision(): void
    {
        $result = $this->validator()->validate([
            'surgical_anchor' => 'file:Foo.php; target_method:bar; constraint:modify_existing_runtime_surface_and_focused_test_only',
        ]);

        $this->assertSame('anchor_concrete', $result['decision']);
        $this->assertSame('surgical_anchor', $result['matched_path']);
    }

    public function testVagueSurgicalAnchorYieldsMissingOrVagueDecision(): void
    {
        $result = $this->validator()->validate(['surgical_anchor' => 'make it better']);

        $this->assertSame('anchor_missing_or_vague', $result['decision']);
        $this->assertNull($result['matched_path']);
    }

    public function testTargetSymbolAcceptsConcreteSymbolAndRejectsRuntimeSignalPlaceholder(): void
    {
        $concrete = $this->validator()->validate(['target_symbol' => 'Foo::bar']);
        $placeholder = $this->validator()->validate(['target_symbol' => 'runtime_signal:foo']);

        $this->assertSame('anchor_concrete', $concrete['decision']);
        $this->assertSame('target_symbol', $concrete['matched_path']);
        $this->assertSame('anchor_missing_or_vague', $placeholder['decision']);
        $this->assertNull($placeholder['matched_path']);
    }

    public function testEmptyFindingYieldsMissingOrVagueDecision(): void
    {
        $result = $this->validator()->validate([]);

        $this->assertSame('anchor_missing_or_vague', $result['decision']);
        $this->assertNull($result['matched_path']);
    }

    public function testNestedSelfConstructionTaskPacketAnchorMatchesExistingOwnerFlowPath(): void
    {
        $result = $this->validator()->validate([
            'self_construction_packet' => [
                'task_packet' => [
                    'target_method' => 'resumePacket',
                ],
            ],
        ]);

        $this->assertSame('anchor_concrete', $result['decision']);
        $this->assertSame('self_construction_packet.task_packet.target_method', $result['matched_path']);
    }

    public function testMethodCallSymbolMatchesExistingOwnerFlowRegex(): void
    {
        $result = $this->validator()->validate(['target_symbol' => 'resumePacket()']);

        $this->assertSame('anchor_concrete', $result['decision']);
        $this->assertSame('target_symbol', $result['matched_path']);
    }

    public function testSymbolTokenInsideMutationAnchorRejectsRuntimeSignalPlaceholder(): void
    {
        $result = $this->validator()->validate([
            'mutation_anchor' => 'file:Foo.php; target_symbol:runtime_signal:foo; constraint:bounded',
        ]);

        $this->assertSame('anchor_missing_or_vague', $result['decision']);
        $this->assertNull($result['matched_path']);
    }

    private function validator(): SurgicalAnchorPresenceValidator
    {
        return new SurgicalAnchorPresenceValidator();
    }
}
