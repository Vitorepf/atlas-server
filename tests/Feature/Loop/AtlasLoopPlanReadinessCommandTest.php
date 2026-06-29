<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the plan-readiness gate is live at the operator surface and emits deterministic facts: an impeccable
 * plan (every node concrete, scoped, with a pre-defined acceptance) is IMPLEMENT-ready; a plan with a vague
 * node REPLANS with the gaps named. A missing plan is a usage error.
 */
final class AtlasLoopPlanReadinessCommandTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        parent::tearDown();
    }

    public function test_requires_plan(): void
    {
        $exit = Artisan::call('atlas:loop:plan-readiness', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('usage_error', $decoded['status']);
    }

    public function test_impeccable_plan_is_implement_ready(): void
    {
        $decoded = $this->invoke([
            'plan' => [
                'plan_id' => 'plan-1',
                'nodes' => [
                    [
                        'id' => 'n1',
                        'request' => 'Refactor the helper in app/Foo.php to reduce its cyclomatic complexity while preserving behaviour',
                        'target_area' => 'app/Foo.php',
                        'acceptance' => ['commands' => ['php artisan test --filter=FooTest']],
                    ],
                    [
                        'id' => 'n2',
                        'request' => 'Extract the validation block in app/Bar.php into a dedicated private method, behaviour preserved',
                        'target_area' => 'app/Bar.php',
                        'acceptance' => ['commands' => ['php artisan test --filter=BarTest']],
                    ],
                ],
            ],
            'allowed_files' => ['app/Foo.php', 'app/Bar.php'],
        ]);

        $this->assertSame('atlas.loop.plan_readiness.v1', $decoded['schema']);
        $this->assertSame('implement', $decoded['decision']);
        $this->assertTrue($decoded['ready']);
        $this->assertTrue($decoded['structural_valid']);
        $this->assertEquals(1.0, $decoded['readiness_score']); // JSON serialises 1.0 as 1; compare loosely
        $this->assertSame([], $decoded['gaps']);
    }

    public function test_vague_node_replans_with_gaps(): void
    {
        $decoded = $this->invoke([
            'plan' => [
                'plan_id' => 'plan-2',
                'nodes' => [
                    [
                        'id' => 'n1',
                        'request' => 'Refactor the helper in app/Foo.php to reduce its cyclomatic complexity while preserving behaviour',
                        'target_area' => 'app/Foo.php',
                        'acceptance' => ['commands' => ['php artisan test --filter=FooTest']],
                    ],
                    [
                        'id' => 'n2',
                        'request' => 'fix bar', // too vague, no acceptance, does not reference its target
                        'target_area' => 'app/Bar.php',
                    ],
                ],
            ],
            'allowed_files' => ['app/Foo.php', 'app/Bar.php'],
        ]);

        $this->assertSame('replan', $decoded['decision']);
        $this->assertFalse($decoded['ready']);
        $this->assertNotEmpty($decoded['gaps']);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function invoke(array $payload): array
    {
        $path = tempnam(sys_get_temp_dir(), 'planready_').'.json';
        $this->files[] = $path;
        file_put_contents($path, json_encode($payload));

        $exit = Artisan::call('atlas:loop:plan-readiness', ['--input' => $path, '--json' => true]);
        $this->assertSame(0, $exit);

        return json_decode(trim(Artisan::output()), true);
    }
}
