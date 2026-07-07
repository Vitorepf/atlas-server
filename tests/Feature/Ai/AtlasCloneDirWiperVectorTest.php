<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Support\AtlasCloneDir;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * P6 (Obra #19) — adversarial proof the wiper vector is DEAD: a worktree that
 * CLONES `vendor` (APFS clonefile) is isolated, so `composer dump-autoload`
 * inside it can never rewrite the LIVE autoload. The contrast case shows a
 * SYMLINK would have poisoned it — the exact incident.
 */
final class AtlasCloneDirWiperVectorTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-p6-'.bin2hex(random_bytes(5));
        @mkdir($this->root, 0775, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_cloned_vendor_isolates_the_live_autoload_from_dump_autoload(): void
    {
        $liveVendor = $this->root.'/live/vendor';
        @mkdir($liveVendor, 0775, true);
        file_put_contents($liveVendor.'/autoload.php', "<?php // LIVE\n");

        $worktree = $this->root.'/worktree';
        @mkdir($worktree, 0775, true);
        $clonedVendor = $worktree.'/vendor';

        $ok = AtlasCloneDir::copy($liveVendor, $clonedVendor);

        $this->assertTrue($ok, 'clone must succeed');
        $this->assertFalse(is_link($clonedVendor), 'the clone is a real copy, NEVER a symlink');
        $this->assertDirectoryExists($clonedVendor);
        $this->assertFileExists($clonedVendor.'/autoload.php');

        // Simulate `composer dump-autoload` rewriting the worktree's autoload.
        file_put_contents($clonedVendor.'/autoload.php', "<?php // REWRITTEN BY DUMP-AUTOLOAD\n");

        // The LIVE autoload is UNTOUCHED — the wiper vector is dead.
        $this->assertSame("<?php // LIVE\n", file_get_contents($liveVendor.'/autoload.php'));
    }

    public function test_contrast_a_symlinked_vendor_would_have_poisoned_the_live_autoload(): void
    {
        $liveVendor = $this->root.'/live2/vendor';
        @mkdir($liveVendor, 0775, true);
        file_put_contents($liveVendor.'/autoload.php', "<?php // LIVE\n");

        $worktree = $this->root.'/worktree2';
        @mkdir($worktree, 0775, true);
        $symlinkedVendor = $worktree.'/vendor';
        symlink($liveVendor, $symlinkedVendor); // the OLD behavior

        // A write "in the worktree" follows the link and rewrites the LIVE tree.
        file_put_contents($symlinkedVendor.'/autoload.php', "<?php // POISONED\n");

        $this->assertSame(
            "<?php // POISONED\n",
            file_get_contents($liveVendor.'/autoload.php'),
            'proves WHY vendor must be cloned, not symlinked',
        );
    }

    /**
     * The P6 floor across EVERY provisioner: while ANY of them symlinks vendor, the wiper
     * vector survives — `composer dump-autoload` inside the worktree follows the link and
     * rewrites the LIVE autoload of the source repo. Each provisioner must route vendor
     * through the AtlasCloneDir clonefile helper. This is the audit's own detector
     * (`symlink\([^)]*vendor`) frozen as a permanent regression gate, plus a positive check
     * that the clone helper is wired. A new provisioner MUST be added to this list.
     *
     * NOTE — AtlasLoopProposalMaterializer.php also symlinks vendor but is a pétreo
     * FORBIDDEN_SELF_TARGET (AtlasLoopHarnessGuard): the loop's scoped committer refuses
     * to land it, so its clonefile fix must go through an operator-gated channel. It is
     * deliberately excluded here rather than silently covered.
     */
    #[DataProvider('vendorProvisioners')]
    public function test_no_provisioner_symlinks_vendor(string $relativePath): void
    {
        $source = (string) file_get_contents(base_path($relativePath));

        $this->assertSame(
            0,
            preg_match('/\bsymlink\s*\([^)]*vendor/i', $source),
            $relativePath.' still symlinks vendor — the wiper vector. Clone it with AtlasCloneDir instead.',
        );
        $this->assertStringContainsString(
            'AtlasCloneDir',
            $source,
            $relativePath.' must route vendor through the AtlasCloneDir clonefile helper.',
        );
    }

    /** @return array<string,array{0:string}> */
    public static function vendorProvisioners(): array
    {
        return [
            'repo-verified-delivery' => ['app/Services/Ai/RealExecution/AtlasRepoVerifiedDeliveryService.php'],
            'governed-branch' => ['app/Services/Ai/RealExecution/GovernedBranchMaterializationService.php'],
            'engineering-workspace' => ['app/Services/Engineering/EngineeringWorkspaceService.php'],
            'bench-suite-adapter' => ['app/Services/Ai/Rivals/Adapters/AtlasBenchSuiteAdapter.php'],
            'loop-framework-materializer' => ['app/Services/Ai/AutonomousEvolution/Framework/AtlasLoopFrameworkMaterializer.php'],
            'owner-sandbox-runtime' => ['app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipOwnerSandboxRuntimeRunnerService.php'],
            'area-focus-branch-sandbox' => ['app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusBranchSandboxMaterializerService.php'],
        ];
    }
}
