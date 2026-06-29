<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopCoverageDeficitSource;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the coverage-deficit source is live at the operator surface: a decision-dense source file with NO
 * sibling characterization test scores a HIGH deficit; the same file with a sibling test present scores LOW.
 */
final class AtlasLoopCoverageDeficitCommandTest extends TestCase
{
    private string $dir = '';

    private string $testsRoot = '';

    private string $sourceAbs = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/atlas-cov-deficit-'.bin2hex(random_bytes(5));
        $this->testsRoot = $this->dir.'/tests';
        @mkdir($this->testsRoot, 0o755, true);

        // A decision-dense file: many DISTINCT frozen mutation operators apply (=== !== == != > >= <= < and
        // literal/integer/bool returns), so its radius-1 mutant count saturates the deficit scale.
        $this->sourceAbs = $this->dir.'/Dense.php';
        file_put_contents($this->sourceAbs, <<<'PHP'
            <?php
            namespace Fixture;
            final class Dense {
                public function f(int $a, int $b): mixed {
                    if ($a === $b) { return true; }
                    if ($a !== $b) { return false; }
                    if ($a == $b) { return 'eq'; }
                    if ($a != $b) { return 7; }
                    if ($a > 0) { return $a; }
                    if ($a >= $b) { return $a; }
                    if ($a <= $b) { return $b; }
                    if ($a < $b) { return $b; }
                    return 0;
                }
            }
            PHP);
    }

    protected function tearDown(): void
    {
        @unlink($this->sourceAbs);
        @unlink($this->testsRoot.'/DenseTest.php');
        @rmdir($this->testsRoot);
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function score(): array
    {
        $exit = Artisan::call('atlas:loop:coverage-deficit', [
            '--file' => $this->sourceAbs,
            '--tests-root' => $this->testsRoot,
            '--json' => true,
        ]);

        return ['exit' => $exit, 'decoded' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_untested_dense_file_is_high_deficit(): void
    {
        ['exit' => $exit, 'decoded' => $decoded] = $this->score();

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.coverage_deficit.v1', $decoded['schema']);
        $this->assertFalse($decoded['has_sibling_test']);
        $this->assertGreaterThan(1, $decoded['mutants'], (string) json_encode($decoded));
        $this->assertGreaterThanOrEqual(AtlasLoopCoverageDeficitSource::HIGH_DEFICIT_THRESHOLD, $decoded['deficit']);
        $this->assertTrue($decoded['is_high_deficit']);
    }

    public function test_sibling_test_present_lowers_deficit(): void
    {
        file_put_contents($this->testsRoot.'/DenseTest.php', "<?php\n");

        ['exit' => $exit, 'decoded' => $decoded] = $this->score();

        $this->assertSame(0, $exit);
        $this->assertTrue($decoded['has_sibling_test']);
        $this->assertLessThan(AtlasLoopCoverageDeficitSource::HIGH_DEFICIT_THRESHOLD, $decoded['deficit']);
        $this->assertFalse($decoded['is_high_deficit']);
    }

    public function test_missing_file_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:coverage-deficit', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
