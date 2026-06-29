<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the complex-target decomposer is live at the operator surface: a file with a method whose cyclomatic
 * complexity clears the material bar yields ≥1 material sub-refactor atom (worst-method-first); a missing
 * --file is a usage error. Material-by-construction — no sub-target below the bar is ever invented.
 */
final class AtlasLoopComplexTargetDecomposeCommandTest extends TestCase
{
    private ?string $fixture = null;

    protected function tearDown(): void
    {
        if ($this->fixture !== null && is_file($this->fixture)) {
            @unlink($this->fixture);
        }
        parent::tearDown();
    }

    public function test_requires_a_file(): void
    {
        $exit = Artisan::call('atlas:loop:complex-target-decompose', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('usage_error', $decoded['status']);
    }

    public function test_decomposes_a_complex_method_into_material_subtargets(): void
    {
        config(['atlas.loop.material_refactor_min_cyclomatic' => 12]);

        $this->fixture = tempnam(sys_get_temp_dir(), 'decompose_').'.php';
        file_put_contents($this->fixture, $this->highComplexitySource());

        $exit = Artisan::call('atlas:loop:complex-target-decompose', ['--file' => $this->fixture, '--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.complex_target_decompose.v1', $decoded['schema']);
        $this->assertGreaterThanOrEqual(1, $decoded['subtarget_count']);

        $first = $decoded['subtargets'][0];
        $this->assertSame('FixtureComplexTarget::tangled', $first['method']);
        $this->assertTrue($first['material']);
        $this->assertSame('decompose_subrefactor', $first['shape']);
        $this->assertGreaterThanOrEqual(12, $first['cyclomatic']);
    }

    /** A single method with ~25 decision points — comfortably above the material cyclomatic bar of 12. */
    private function highComplexitySource(): string
    {
        $branches = '';
        for ($i = 1; $i <= 25; $i++) {
            $branches .= "        if (\$x === {$i}) { return {$i}; }\n";
        }

        return "<?php\n\nclass FixtureComplexTarget\n{\n    public function tangled(int \$x): int\n    {\n{$branches}        return 0;\n    }\n}\n";
    }
}
