<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopGateWorkspaceProvisioner;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasLoopGateWorkspaceProvisionerTest extends TestCase
{
    private string $base = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir().'/atlas-gate-prov-base-'.bin2hex(random_bytes(4));
        mkdir($this->base, 0o755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->base)) {
            (new Process(['rm', '-rf', $this->base], null, null, null, 30.0))->run();
        }
        parent::tearDown();
    }

    public function test_materialize_rejects_missing_base_workspace(): void
    {
        $provisioner = new AtlasLoopGateWorkspaceProvisioner();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('base workspace missing');
        $provisioner->materialize('/nonexistent-'.bin2hex(random_bytes(4)), 'diff');
    }

    public function test_materialize_rejects_empty_or_truncated_diff(): void
    {
        $provisioner = new AtlasLoopGateWorkspaceProvisioner();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('proposal diff missing or truncated');
        $provisioner->materialize($this->base, 'something…');
    }

    public function test_materialize_provisions_non_git_base_then_applies_diff_and_remove_cleans_up(): void
    {
        file_put_contents($this->base.'/target.txt', "alpha\n");

        $diff = <<<DIFF
diff --git a/target.txt b/target.txt
index 0000000..1111111 100644
--- a/target.txt
+++ b/target.txt
@@ -1 +1 @@
-alpha
+beta
DIFF;

        $provisioner = new AtlasLoopGateWorkspaceProvisioner();
        $workspace = $provisioner->materialize($this->base, $diff."\n");

        $this->assertDirectoryExists($workspace);
        $this->assertDirectoryExists($workspace.'/.git');
        $this->assertSame("beta\n", file_get_contents($workspace.'/target.txt'));
        $this->assertDirectoryExists($workspace.'/storage/logs');

        $provisioner->remove($this->base, $workspace);
        $this->assertDirectoryDoesNotExist($workspace);
    }

    public function test_remove_is_a_no_op_when_workspace_does_not_exist(): void
    {
        $provisioner = new AtlasLoopGateWorkspaceProvisioner();
        $provisioner->remove($this->base, '/nonexistent-'.bin2hex(random_bytes(4)));
        $this->assertTrue(true);
    }
}
