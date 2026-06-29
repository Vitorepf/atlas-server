<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the dedup proof is live at the operator surface: a clone shared by 2 baseline files that the candidate
 * unifies (both bodies replaced by delegation) reads as REMOVED; a laundered "dedup" that leaves both bodies
 * intact is NOT removed; a baseline with no shared clone has nothing to certify.
 */
final class AtlasLoopDedupProofCommandTest extends TestCase
{
    private string $input = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->input = sys_get_temp_dir().'/atlas-dedup-'.bin2hex(random_bytes(5)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->input);
        parent::tearDown();
    }

    private function clonedBody(string $class): string
    {
        return "<?php\nclass {$class} {\n  public function doX() {\n    return 1 + 2 + 3 + 4;\n  }\n}\n";
    }

    private function delegatedBody(string $class): string
    {
        return "<?php\nclass {$class} {\n  public function doX() {\n    return \\Helper::compute();\n  }\n}\n";
    }

    private function prove(array $baseline, array $candidate): array
    {
        file_put_contents($this->input, (string) json_encode(['baseline' => $baseline, 'candidate' => $candidate]));
        $exit = Artisan::call('atlas:loop:dedup-proof', ['--input' => $this->input, '--json' => true]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_unified_clone_reads_as_removed(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->prove(
            ['a/A.php' => $this->clonedBody('A'), 'b/B.php' => $this->clonedBody('B')],
            ['a/A.php' => $this->delegatedBody('A'), 'b/B.php' => $this->delegatedBody('B')],
        );

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.dedup_proof.v1', $d['schema']);
        $this->assertTrue($d['has_baseline_clone'], (string) json_encode($d));
        $this->assertSame(2, $d['baseline_count']);
        $this->assertSame(0, $d['candidate_count']);
        $this->assertTrue($d['removed']);
    }

    public function test_laundered_dedup_is_not_removed(): void
    {
        // candidate leaves BOTH clone bodies intact ⇒ still shared ⇒ not removed
        ['d' => $d] = $this->prove(
            ['a/A.php' => $this->clonedBody('A'), 'b/B.php' => $this->clonedBody('B')],
            ['a/A.php' => $this->clonedBody('A'), 'b/B.php' => $this->clonedBody('B')],
        );

        $this->assertTrue($d['has_baseline_clone']);
        $this->assertSame(2, $d['candidate_count']);
        $this->assertFalse($d['removed']);
    }

    public function test_no_baseline_clone_has_nothing_to_certify(): void
    {
        // distinct bodies ⇒ no shared clone on baseline
        ['d' => $d] = $this->prove(
            ['a/A.php' => $this->clonedBody('A'), 'b/B.php' => $this->delegatedBody('B')],
            ['a/A.php' => $this->delegatedBody('A'), 'b/B.php' => $this->delegatedBody('B')],
        );

        $this->assertFalse($d['has_baseline_clone']);
        $this->assertFalse($d['removed']);
    }

    public function test_missing_input_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:dedup-proof', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
