<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\GovernedTargets;

use App\Services\Ai\SelfConstruction\GovernedTargets\AtlasTaskPropertyGatedTargetPolicy;
use PHPUnit\Framework\TestCase;

final class AtlasTaskPropertyGatedTargetPolicyTest extends TestCase
{
    private function policy(): AtlasTaskPropertyGatedTargetPolicy
    {
        return new AtlasTaskPropertyGatedTargetPolicy;
    }

    public function test_normal_brain_organ_is_property_gated(): void
    {
        $p = $this->policy();
        $this->assertSame(
            AtlasTaskPropertyGatedTargetPolicy::CLASSIFICATION_PROPERTY_GATED,
            $p->classify('app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainSomethingNormal.php'),
            'a non-lock Brain organ must be property_gated, not forbidden or ordinary'
        );
        // A plain AutonomousEvolution organ (not Brain/) is also property_gated.
        $this->assertSame(
            AtlasTaskPropertyGatedTargetPolicy::CLASSIFICATION_PROPERTY_GATED,
            $p->classify('app/Services/Ai/AutonomousEvolution/AtlasLoopFrontierGapModel.php')
        );
    }

    public function test_ordinary_app_file_is_ordinary(): void
    {
        $p = $this->policy();
        $this->assertSame(
            AtlasTaskPropertyGatedTargetPolicy::CLASSIFICATION_ORDINARY,
            $p->classify('app/Services/SomeService.php')
        );
        $this->assertSame(
            AtlasTaskPropertyGatedTargetPolicy::CLASSIFICATION_ORDINARY,
            $p->classify('app/Http/Controllers/ApiController.php')
        );
    }

    public function test_petreo_forbidden_files_remain_forbidden(): void
    {
        $p = $this->policy();
        $cases = [
            'app/Services/Ai/AutonomousEvolution/AtlasLoopHarnessGuard.php',
            'app/Services/Ai/AutonomousEvolution/AtlasLoopMasterSwitch.php',
            'app/Services/Ai/AutonomousEvolution/Constitution/AtlasLoopConstitutionGateService.php',
            'config/atlas.php',
            'bin/atlas-loop-watchdog.sh',
            'app/Services/Ai/SelfConstruction/AtlasTaskScopedCommitter.php',
            'app/Services/Ai/SelfConstruction/AtlasTaskPacketQualityInspector.php',
        ];
        foreach ($cases as $path) {
            $this->assertSame(
                AtlasTaskPropertyGatedTargetPolicy::CLASSIFICATION_FORBIDDEN,
                $p->classify($path),
                "pétreo path must be forbidden: {$path}"
            );
        }
    }

    public function test_external_brain_core_commands_and_seed_gate_are_forbidden_not_ordinary(): void
    {
        // AtlasLoopHarnessGuard::FORBIDDEN_SELF_TARGETS already lists these as pétreo — the réu never
        // edits its own seed harness. Preflight must agree, not classify them as ordinary.
        $p = $this->policy();
        $cases = [
            'app/Console/Commands/AtlasBrainNextCommand.php',
            'app/Console/Commands/AtlasBrainWorkerPromptCommand.php',
            'app/Console/Commands/AtlasBrainAuditCommand.php',
            'app/Console/Commands/AtlasBrainSummaryCommand.php',
            'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainSeedQualityGate.php',
        ];
        foreach ($cases as $path) {
            $this->assertSame(
                AtlasTaskPropertyGatedTargetPolicy::CLASSIFICATION_FORBIDDEN,
                $p->classify($path),
                "external brain core target must be forbidden, matching the seed harness: {$path}"
            );
        }
    }

    public function test_forbidden_wins_over_property_gated_for_petreo_paths_inside_autonomous_evolution(): void
    {
        // AtlasLoopHarnessGuard lives under AutonomousEvolution/ but must remain forbidden.
        $p = $this->policy();
        $this->assertSame(
            AtlasTaskPropertyGatedTargetPolicy::CLASSIFICATION_FORBIDDEN,
            $p->classify('app/Services/Ai/AutonomousEvolution/AtlasLoopHarnessGuard.php'),
            'forbidden check must win over property_gated even when path is under AutonomousEvolution/'
        );
    }

    public function test_classify_all_groups_paths_by_classification(): void
    {
        $p = $this->policy();
        $result = $p->classifyAll([
            'app/Services/Other.php',
            'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainOrgan.php',
            'app/Services/Ai/AutonomousEvolution/AtlasLoopHarnessGuard.php',
            'config/atlas.php',
        ]);
        $this->assertSame(['app/Services/Other.php'], $result['ordinary']);
        $this->assertSame(
            ['app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainOrgan.php'],
            $result['property_gated']
        );
        $this->assertSame(
            ['app/Services/Ai/AutonomousEvolution/AtlasLoopHarnessGuard.php', 'config/atlas.php'],
            $result['forbidden']
        );
    }

    // ── classifyPacket(): AC2/AC3/AC4 ──────────────────────────────────────────

    public function test_packet_with_forbidden_file_is_forbidden_self_target(): void
    {
        $p = $this->policy();
        $r = $p->classifyPacket([
            'allowed_files' => ['config/atlas.php', 'app/Services/Foo.php'],
        ]);

        $this->assertSame(AtlasTaskPropertyGatedTargetPolicy::PACKET_FORBIDDEN_SELF_TARGET, $r['classification']);
        $this->assertNotNull($r['blocking_reason']);
        $this->assertNotNull($r['repair_hint']);
    }

    public function test_packet_with_property_gated_file_is_operator_only(): void
    {
        $p = $this->policy();
        $r = $p->classifyPacket([
            'allowed_files' => ['app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainOrgan.php'],
        ]);

        $this->assertSame(AtlasTaskPropertyGatedTargetPolicy::PACKET_OPERATOR_ONLY, $r['classification']);
        $this->assertNotNull($r['blocking_reason']);
        $this->assertNotNull($r['repair_hint']);
    }

    public function test_packet_with_only_test_files_is_test_only_scope(): void
    {
        $p = $this->policy();
        $r = $p->classifyPacket([
            'allowed_files' => ['tests/Unit/FooTest.php', 'tests/Unit/BarSpec.php'],
        ]);

        $this->assertSame(AtlasTaskPropertyGatedTargetPolicy::PACKET_TEST_ONLY_SCOPE, $r['classification']);
        $this->assertNotNull($r['blocking_reason']);
        $this->assertNotNull($r['repair_hint']);
    }

    public function test_packet_with_file_outside_declared_scope_is_cross_scope(): void
    {
        $p = $this->policy();
        $r = $p->classifyPacket([
            'allowed_files' => ['app/Services/Foo/Bar.php', 'app/Other/Unrelated.php'],
            'scope_in' => ['app/Services/Foo'],
        ]);

        $this->assertSame(AtlasTaskPropertyGatedTargetPolicy::PACKET_CROSS_SCOPE, $r['classification']);
        $this->assertNotNull($r['blocking_reason']);
        $this->assertNotNull($r['repair_hint']);
    }

    public function test_packet_fully_within_scope_is_safe_autonomous(): void
    {
        $p = $this->policy();
        $r = $p->classifyPacket([
            'allowed_files' => ['app/Services/Foo/Bar.php', 'tests/Unit/Foo/BarTest.php'],
            'scope_in' => ['app/Services/Foo', 'tests/Unit/Foo'],
        ]);

        $this->assertSame(AtlasTaskPropertyGatedTargetPolicy::PACKET_SAFE_AUTONOMOUS, $r['classification']);
        $this->assertNull($r['blocking_reason']);
        $this->assertNull($r['repair_hint']);
    }

    // ── AC4: mixed allowed_files classification — priority order ──────────────

    public function test_mixed_allowed_files_forbidden_wins_over_operator_only_and_test_only(): void
    {
        $p = $this->policy();
        $r = $p->classifyPacket([
            'allowed_files' => [
                'config/atlas.php',
                'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainOrgan.php',
                'tests/Unit/FooTest.php',
            ],
        ]);

        $this->assertSame(AtlasTaskPropertyGatedTargetPolicy::PACKET_FORBIDDEN_SELF_TARGET, $r['classification']);
    }

    public function test_mixed_allowed_files_operator_only_wins_over_test_only_and_cross_scope(): void
    {
        $p = $this->policy();
        $r = $p->classifyPacket([
            'allowed_files' => [
                'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainOrgan.php',
                'tests/Unit/FooTest.php',
                'app/Other/Unrelated.php',
            ],
            'scope_in' => ['app/Services/Foo'],
        ]);

        $this->assertSame(AtlasTaskPropertyGatedTargetPolicy::PACKET_OPERATOR_ONLY, $r['classification']);
    }

    public function test_mixed_allowed_files_with_impl_and_test_file_within_scope_is_safe(): void
    {
        $p = $this->policy();
        $r = $p->classifyPacket([
            'allowed_files' => ['app/Services/Foo/Bar.php', 'tests/Unit/Foo/BarTest.php', 'app/Services/Foo/Baz.php'],
            'scope_in' => ['app/Services/Foo', 'tests/Unit/Foo'],
        ]);

        $this->assertSame(AtlasTaskPropertyGatedTargetPolicy::PACKET_SAFE_AUTONOMOUS, $r['classification']);
    }
}
