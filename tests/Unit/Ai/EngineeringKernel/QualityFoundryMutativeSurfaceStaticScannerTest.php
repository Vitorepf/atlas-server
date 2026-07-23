<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel;

use App\Services\Ai\EngineeringKernel\Quality\QualityFoundryMutativeSurfaceStaticScanner;
use PHPUnit\Framework\TestCase;

final class QualityFoundryMutativeSurfaceStaticScannerTest extends TestCase
{
    public function test_mutative_git_fs_release_and_deploy_tokens_are_blocked_outside_allowlist(): void
    {
        $result = (new QualityFoundryMutativeSurfaceStaticScanner)->scan([
            'app/Services/Ai/Surface/BadSurface.php' => "<?php\nexec('git commit -am x');\nStorage::put('release', 'x');\nProcess::run(['deploy']);",
            'app/Services/Ai/EngineeringKernel/MergeActuator.php' => "<?php\nexec('git commit -am x');",
        ]);

        self::assertSame('blocked', $result['status']);
        self::assertCount(3, $result['violations']);
        self::assertSame('app/Services/Ai/Surface/BadSurface.php', $result['violations'][0]['file']);
    }

    public function test_read_only_mentions_and_allowlisted_governed_mutation_pass(): void
    {
        $result = (new QualityFoundryMutativeSurfaceStaticScanner)->scan([
            'app/Services/Ai/Surface/ReadOnly.php' => "<?php\n/** git commit and deploy are forbidden here. */\npublic function release(): void {}\nreturn 'git commit is forbidden here';",
            'app/Services/Ai/SelfConstruction/Governance/AtlasTaskMergeActuator.php' => "<?php\nexec('git revert --no-edit');",
        ]);

        self::assertSame('pass', $result['status']);
        self::assertSame([], $result['violations']);
    }

    public function test_canonical_quality_foundry_sources_are_scanned_from_the_real_repository(): void
    {
        $root = dirname(__DIR__, 4);
        $paths = [
            'app/Services/Ai/EngineeringKernel/MergeActuator.php',
            'app/Services/Ai/EngineeringKernel/KernelEvidenceAuthority.php',
            'app/Services/Ai/EngineeringKernel/QualityFoundry/QualityFoundryReadinessManifest.php',
        ];
        $files = [];
        foreach ($paths as $path) {
            self::assertFileExists($root.'/'.$path);
            $files[$path] = (string) file_get_contents($root.'/'.$path);
        }

        $result = (new QualityFoundryMutativeSurfaceStaticScanner)->scan($files);

        self::assertSame('pass', $result['status']);
        self::assertSame([], $result['violations']);
    }
}
