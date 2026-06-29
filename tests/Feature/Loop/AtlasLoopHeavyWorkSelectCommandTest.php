<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the heavy-work selector is live at the operator surface and emits deterministic facts: a candidate
 * with strong measured evidence and larger scope is selected over a smaller one, while an under-evidenced
 * candidate is excluded by the panel. A missing --input is a usage error.
 */
final class AtlasLoopHeavyWorkSelectCommandTest extends TestCase
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

    public function test_requires_input(): void
    {
        $exit = Artisan::call('atlas:loop:heavy-work-select', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('usage_error', $decoded['status']);
    }

    public function test_selects_the_biggest_well_evidenced_candidate(): void
    {
        $decoded = $this->invoke([
            // strong evidence + larger scope ⇒ biggest leap
            ['candidateId' => 'big', 'class' => 'obra_candidate', 'node_count' => 6,
                'evidence' => ['refactor_leverage' => 0.95, 'cyclomatic_total' => 90, 'failure_evidence' => 0.8]],
            // evidenced but smaller value + scope
            ['candidateId' => 'small', 'class' => 'obra_candidate', 'node_count' => 1,
                'evidence' => ['refactor_leverage' => 0.2, 'cyclomatic_total' => 8]],
            // under-evidenced (1 lens signal < quorum) ⇒ excluded
            ['candidateId' => 'thin', 'class' => 'obra_candidate', 'node_count' => 1,
                'evidence' => ['refactor_leverage' => 0.5]],
        ]);

        $this->assertSame('atlas.loop.heavy_work_selection.v1', $decoded['schema_version']);
        $this->assertNotNull($decoded['pick']);
        $this->assertSame('big', $decoded['pick']['candidateId']);
        $this->assertArrayHasKey('gate', $decoded['pick']);
        $this->assertArrayHasKey('trust', $decoded['pick']);

        $rankedIds = array_column($decoded['ranked'], 'candidateId');
        $this->assertContains('big', $rankedIds);
        $this->assertContains('small', $rankedIds);
        $this->assertNotContains('thin', $rankedIds); // excluded by the panel quorum
    }

    /**
     * @param  list<array<string,mixed>>  $candidates
     * @return array<string,mixed>
     */
    private function invoke(array $candidates): array
    {
        $path = tempnam(sys_get_temp_dir(), 'heavywork_').'.json';
        $this->files[] = $path;
        file_put_contents($path, json_encode($candidates));

        $exit = Artisan::call('atlas:loop:heavy-work-select', ['--input' => $path, '--json' => true]);
        $this->assertSame(0, $exit);

        return json_decode(trim(Artisan::output()), true);
    }
}
