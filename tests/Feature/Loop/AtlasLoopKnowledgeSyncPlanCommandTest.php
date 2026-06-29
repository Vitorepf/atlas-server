<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the post-merge knowledge-sync planner is live at the operator surface and emits deterministic facts:
 * with conformant docs and a ready code index it plans the sync actions (with command hints); with docs
 * drift it is blocked. A missing input is a usage error.
 */
final class AtlasLoopKnowledgeSyncPlanCommandTest extends TestCase
{
    public function test_requires_all_inputs(): void
    {
        $exit = Artisan::call('atlas:loop:knowledge-sync-plan', [
            '--docs-drift' => '{}',
            '--code-index' => '{}',
            '--json' => true,
        ]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('usage_error', $decoded['status']);
    }

    public function test_conformant_ready_plan_lists_actions(): void
    {
        $decoded = $this->plan(
            ['project_ids' => ['blackink'], 'docs_dirs' => ['docs/kb'], 'code_index_targets' => ['app']],
            ['conformant' => true],
            ['ready' => true],
        );

        $this->assertSame('atlas.knowledge_sync.post_merge_plan.v1', $decoded['schema_version']);
        $this->assertFalse($decoded['blocked']);
        $this->assertNotEmpty($decoded['actions']);
        $this->assertNotEmpty($decoded['command_hints']);

        $actionKinds = array_column($decoded['actions'], 'action');
        $this->assertContains('sync_docs', $actionKinds);
        $this->assertContains('index_code', $actionKinds);
    }

    public function test_docs_drift_blocks_the_plan(): void
    {
        $decoded = $this->plan(
            ['project_ids' => ['blackink']],
            ['conformant' => false, 'blockers' => ['stale_module_doc']],
            ['ready' => true],
        );

        $this->assertTrue($decoded['blocked']);
        $this->assertContains('docs_drift_not_conformant', $decoded['blockers']);
        $this->assertSame([], $decoded['actions']);
    }

    /**
     * @param  array<string,mixed>  $artifacts
     * @param  array<string,mixed>  $docsDrift
     * @param  array<string,mixed>  $codeIndex
     * @return array<string,mixed>
     */
    private function plan(array $artifacts, array $docsDrift, array $codeIndex): array
    {
        $exit = Artisan::call('atlas:loop:knowledge-sync-plan', [
            '--artifacts' => json_encode($artifacts),
            '--docs-drift' => json_encode($docsDrift),
            '--code-index' => json_encode($codeIndex),
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);

        return json_decode(trim(Artisan::output()), true);
    }
}
