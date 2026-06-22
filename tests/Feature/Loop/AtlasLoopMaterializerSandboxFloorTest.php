<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopOrphanWiringAuthoringEngine;
use App\Services\Ai\AutonomousEvolution\AtlasLoopWorkspaceMaterializerSupport2;
use App\Services\Ai\AutonomousEvolution\Framework\AtlasLoopFrameworkMaterializer;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/**
 * SANDBOX FLOOR — the test-isolation + pétreo-organ guard for every loop workspace writer.
 *
 * A real incident: a misconfigured base (a campaign/test handing `base_path()` to a
 * materializer) drove `writeFile()` to overwrite the REAL 417-line cert organ
 * `AtlasLoopBroaderRegressionGate.php` down to a stub, which then failed to resolve and
 * errored every AtlasLoopAutoMergeServiceTest. A loop workspace is ALWAYS a scoped
 * temp/worktree copy — the live edit is applied later by the auto-merge service via git,
 * never by these writers — so a write that resolves onto the live `app/` source tree can
 * only be a bug. This freezes the floor: every writer FAILS CLOSED on such a write, so no
 * pétreo organ can be clobbered, and the suite cannot dirty the project's own source tree.
 */
final class AtlasLoopMaterializerSandboxFloorTest extends TestCase
{
    /** @var list<string> */
    private array $tmp = [];

    /** The exact organ the real incident clobbered — the canonical regression anchor. */
    private const PETREO_ORGAN = 'app/Services/Ai/AutonomousEvolution/AtlasLoopBroaderRegressionGate.php';

    protected function tearDown(): void
    {
        foreach ($this->tmp as $d) {
            if (is_dir($d)) {
                exec('rm -rf '.escapeshellarg($d));
            }
        }
        parent::tearDown();
    }

    private function tempBase(): string
    {
        $d = sys_get_temp_dir().'/atlas-sandbox-floor-'.bin2hex(random_bytes(5));
        mkdir($d, 0o755, true);
        $this->tmp[] = $d;

        return $d;
    }

    public function test_guard_throws_for_a_path_inside_the_live_app_source_tree(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('refusing to write inside the live application source tree');

        AtlasLoopWorkspaceMaterializerSupport2::assertOutsideLiveSource(base_path(self::PETREO_ORGAN));
    }

    public function test_guard_throws_for_a_not_yet_existing_file_inside_the_app_tree(): void
    {
        // A brand-new path under app/ that does not exist yet must still be refused
        // (the deepest existing ancestor canonicalizes back into the source tree).
        $this->expectException(RuntimeException::class);

        AtlasLoopWorkspaceMaterializerSupport2::assertOutsideLiveSource(app_path('Newly/Invented/Organ.php'));
    }

    public function test_guard_is_a_noop_for_a_temp_sandbox_even_with_an_app_subdir(): void
    {
        $base = $this->tempBase();

        // A temp dir that itself contains an app/ subtree is NOT the live source tree.
        AtlasLoopWorkspaceMaterializerSupport2::assertOutsideLiveSource($base.'/app/Services/Ai/AutonomousEvolution/AtlasLoopBroaderRegressionGate.php');

        $this->assertTrue(true, 'a scoped temp workspace is always writable');
    }

    public function test_support2_writeFile_refuses_a_live_app_base_and_never_clobbers_the_organ(): void
    {
        $organAbs = base_path(self::PETREO_ORGAN);
        $before = (string) file_get_contents($organAbs);
        $this->assertStringContainsString('class AtlasLoopBroaderRegressionGate', $before, 'sanity: the real organ is intact before');

        try {
            (new AtlasLoopWorkspaceMaterializerSupport2)->writeFile(
                base_path(),
                self::PETREO_ORGAN,
                "<?php\n\necho \"write_file test marker\";\n",
            );
            $this->fail('writeFile must refuse a base that resolves onto the live source tree');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('refusing to write inside the live application source tree', $e->getMessage());
        }

        $after = (string) file_get_contents($organAbs);
        $this->assertSame($before, $after, 'the real cert organ must be byte-identical — never touched by the refused write');
    }

    public function test_support2_writeFile_still_writes_into_a_temp_sandbox(): void
    {
        $base = $this->tempBase();

        (new AtlasLoopWorkspaceMaterializerSupport2)->writeFile($base, 'app/Services/Foo.php', "<?php\nclass Foo {}\n");

        $this->assertFileExists($base.'/app/Services/Foo.php');
        $this->assertStringContainsString('class Foo', (string) file_get_contents($base.'/app/Services/Foo.php'));
    }

    public function test_framework_materializer_writeFile_refuses_a_live_app_base(): void
    {
        $m = new ReflectionMethod(AtlasLoopFrameworkMaterializer::class, 'writeFile');
        $m->setAccessible(true);

        $this->expectException(RuntimeException::class);
        $m->invoke(app(AtlasLoopFrameworkMaterializer::class), base_path(), self::PETREO_ORGAN, "<?php\n");
    }

    public function test_orphan_wiring_authoring_writeFile_refuses_a_live_app_base(): void
    {
        $m = new ReflectionMethod(AtlasLoopOrphanWiringAuthoringEngine::class, 'writeFile');
        $m->setAccessible(true);

        $this->expectException(RuntimeException::class);
        $m->invoke(new AtlasLoopOrphanWiringAuthoringEngine, base_path(), self::PETREO_ORGAN, "<?php\n");
    }
}
