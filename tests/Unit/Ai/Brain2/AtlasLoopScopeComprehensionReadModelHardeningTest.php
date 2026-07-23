<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use PHPUnit\Framework\TestCase;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionReadModel;

final class AtlasLoopScopeComprehensionReadModelHardeningTest extends TestCase
{
    /**
     * pathFor must never use the raw snapshotId as a path component.
     */
    public function test_path_for_never_uses_raw_snapshot_id(): void
    {
        $tmpDir = sys_get_temp_dir().'/scope-readmodel-'.bin2hex(random_bytes(4));
        mkdir($tmpDir, 0o755, true);

        $model = new AtlasLoopScopeComprehensionReadModel();
        $model->setRootForTesting($tmpDir);

        $hostileId = '../etc/passwd';

        $method = new \ReflectionMethod($model, 'pathFor');
        $path = $method->invoke($model, $hostileId);

        $this->assertStringStartsWith($tmpDir, $path);
        $this->assertStringNotContainsString('../', $path);

        @rmdir($tmpDir);
    }

    /**
     * Normal snapshot IDs produce valid paths.
     */
    public function test_path_for_normal_id(): void
    {
        $tmpDir = sys_get_temp_dir().'/scope-readmodel-'.bin2hex(random_bytes(4));
        mkdir($tmpDir, 0o755, true);

        $model = new AtlasLoopScopeComprehensionReadModel();
        $model->setRootForTesting($tmpDir);

        $method = new \ReflectionMethod($model, 'pathFor');
        $path = $method->invoke($model, 'snapshot-abc123');

        $this->assertStringStartsWith($tmpDir, $path);
        $this->assertStringEndsWith('.json', $path);

        @rmdir($tmpDir);
    }

    /**
     * Verify the source code does not fall back to raw snapshotId.
     */
    public function test_source_does_not_fallback_to_raw_id(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopScopeComprehensionReadModel.php');

        $this->assertStringNotContainsString('?? $snapshotId', $source, 'pathFor must not fall back to raw snapshotId');
    }
}
