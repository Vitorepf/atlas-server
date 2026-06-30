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
}
