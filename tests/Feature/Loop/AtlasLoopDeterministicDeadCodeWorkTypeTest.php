<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopDeterministicDeadCodeWorkType;
use PhpParser\ParserFactory;
use Tests\TestCase;

/**
 * The deterministic dead-code work-type, END-TO-END. This is the front-#6 evidence in test form: the loop
 * can MILL → AUTHOR → CERTIFY a real value-bearing change (removing provably-dead code) with ZERO provider
 * calls — the path that closes the live-proof gap AROUND the declined 1-line hermes fix. The wiring into the
 * live CampaignSupervisor (so this runs in a campaign) is the next slice; this proves the chain certifies.
 */
final class AtlasLoopDeterministicDeadCodeWorkTypeTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/atlas-dcwt-'.bin2hex(random_bytes(5));
        @mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->dir);
        parent::tearDown();
    }

    private function rmrf(string $path): void
    {
        if (is_dir($path)) {
            foreach (glob($path.'/*') ?: [] as $child) {
                $this->rmrf($child);
            }
            @rmdir($path);
        } elseif (is_file($path)) {
            @unlink($path);
        }
    }

    public function test_mills_authors_and_certifies_a_dead_code_removal_with_no_provider(): void
    {
        file_put_contents($this->dir.'/Sample.php', <<<'PHP'
<?php

class Sample
{
    public function entry(): int
    {
        return $this->liveHelper();
    }

    /** dead — referenced by nobody in-class. */
    private function deadHelper(): string
    {
        return 'never called';
    }

    private function liveHelper(): int
    {
        return 42;
    }

    private const DEAD_K = 'unused';
}
PHP);

        $result = (new AtlasLoopDeterministicDeadCodeWorkType)->produceCertifiedRemoval($this->dir, 'Sample.php');

        $this->assertNotNull($result, 'a file with provably-dead members yields a CERTIFIED removal');
        $this->assertTrue($result['certified']);
        $this->assertFalse($result['provider_used'], 'the entire mill→author→cert chain used NO provider');
        $this->assertNotContains('not_a_net_reduction', $result['gate_reasons'], 'no objection leaked');
        $this->assertNotContains('removed_unflagged_members', $result['gate_reasons'], 'the cert saw no live-member removal');
        $this->assertStringNotContainsString('deadHelper', $result['proposed'], 'the dead method is gone in the proposed content');
        $this->assertStringNotContainsString('DEAD_K', $result['proposed'], 'the dead const is gone');
        $this->assertStringContainsString('liveHelper', $result['proposed'], 'the live member is preserved');
        $this->assertCount(2, $result['removed']);

        // the proposed content is real, mergeable PHP
        $parses = (new ParserFactory)->createForHostVersion()->parse($result['proposed']) !== null;
        $this->assertTrue($parses, 'the certified proposal is valid PHP');
    }

    public function test_sweeps_a_directory_certifying_only_files_with_dead_code(): void
    {
        @mkdir($this->dir.'/sub', 0777, true);
        file_put_contents($this->dir.'/Dead.php', <<<'PHP'
<?php

class Dead
{
    public function go(): int
    {
        return $this->live();
    }

    private function live(): int
    {
        return 1;
    }

    private const UNUSED = 'x';
}
PHP);
        file_put_contents($this->dir.'/sub/Clean.php', <<<'PHP'
<?php

class Clean
{
    public function go(): int
    {
        return $this->live();
    }

    private function live(): int
    {
        return 2;
    }
}
PHP);

        $sweep = (new AtlasLoopDeterministicDeadCodeWorkType)->sweepDirectory($this->dir, '', 50);

        $this->assertSame(2, $sweep['scanned'], 'both files scanned (recursively)');
        $this->assertFalse($sweep['provider_used'], 'the sweep used NO provider');
        $this->assertCount(1, $sweep['certified'], 'only the file with dead code yields a certified removal');
        $this->assertSame('Dead.php', $sweep['certified'][0]['rel_path']);
    }

    public function test_returns_null_on_a_clean_file_no_silent_noop(): void
    {
        file_put_contents($this->dir.'/Clean.php', <<<'PHP'
<?php

class Clean
{
    public function entry(): int
    {
        return $this->helper();
    }

    private function helper(): int
    {
        return 1;
    }
}
PHP);

        $this->assertNull(
            (new AtlasLoopDeterministicDeadCodeWorkType)->produceCertifiedRemoval($this->dir, 'Clean.php'),
            'no dead members => null (a clean file is not a fake "win")'
        );
    }
}
