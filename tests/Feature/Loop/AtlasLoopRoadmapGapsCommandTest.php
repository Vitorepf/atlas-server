<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricRoadmapGapMiner;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the roadmap gap miner is live at the operator surface: an uncovered, evidence-backed, in-scope row is
 * mined into a candidate naming its organ+capability; resolved / cosmetic / evidence-less / out-of-scope rows
 * are skipped.
 */
final class AtlasLoopRoadmapGapsCommandTest extends TestCase
{
    private function mine(array $rows): array
    {
        $exit = Artisan::call('atlas:loop:roadmap-gaps', [
            '--rows' => (string) json_encode($rows),
            '--json' => true,
        ]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_uncovered_row_is_mined_and_filters_apply(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->mine([
            // mined: in-scope organ, has evidence, unresolved, non-cosmetic
            ['organ' => 'Maestro', 'capability' => 'route obras', 'current_state' => 'manual', 'target_state' => 'autonomous', 'evidence_path' => 'docs/maestro.md', 'suggested_files' => ['app/Maestro/Router.php']],
            // skipped: resolved
            ['organ' => 'Task Fabric', 'capability' => 'done thing', 'evidence_path' => 'docs/x.md', 'resolved' => true],
            // skipped: cosmetic kind
            ['organ' => 'Maestro', 'capability' => 'tidy', 'evidence_path' => 'docs/y.md', 'kind' => 'cosmetic'],
            // skipped: no evidence
            ['organ' => 'Maestro', 'capability' => 'no evidence'],
            // skipped: unsupported organ
            ['organ' => 'Marketing', 'capability' => 'grow', 'evidence_path' => 'docs/z.md'],
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.roadmap_gaps.v1', $d['schema']);
        $this->assertSame(1, $d['gap_count'], (string) json_encode($d));
        $gap = $d['gaps'][0];
        $this->assertSame(AtlasTaskFabricRoadmapGapMiner::SCHEMA, $gap['schema_version']);
        $this->assertSame('Maestro', $gap['organ']);
        $this->assertSame('route obras', $gap['capability']);
        $this->assertStringContainsString('CURRENT: manual', $gap['capability_gap']);
        $this->assertStringContainsString('TARGET: autonomous', $gap['capability_gap']);
        $this->assertSame('atlas-native', $gap['owner_scope']);
    }

    public function test_all_filtered_rows_yield_no_gaps(): void
    {
        ['d' => $d] = $this->mine([
            ['organ' => 'Maestro', 'capability' => 'resolved', 'evidence_path' => 'docs/x.md', 'resolved' => true],
            ['organ' => 'Marketing', 'capability' => 'out of scope', 'evidence_path' => 'docs/y.md'],
        ]);

        $this->assertSame(0, $d['gap_count']);
        $this->assertSame([], $d['gaps']);
    }

    public function test_missing_rows_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:roadmap-gaps', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
