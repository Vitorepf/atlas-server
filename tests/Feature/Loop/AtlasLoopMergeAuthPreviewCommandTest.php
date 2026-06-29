<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMultiRepoMergeAuthority;
use App\Services\Ai\AutonomousEvolution\V2\AtlasLoopV2AuditJournal;
use App\Services\Ai\AutonomousEvolution\V2\AtlasLoopV2BlastRadiusCalculator;
use App\Services\Ai\AutonomousEvolution\V2\AtlasLoopV2EnterpriseMergeGate;
use App\Services\Ai\AutonomousEvolution\V2\AtlasLoopV2ScopeManifestRegistry;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the advisory merge-auth preview CLI runs the real {@see AtlasLoopV2EnterpriseMergeGate::authorize()}:
 * (a) a proposal whose blast radius exceeds the scope's risk tier is denied with a non-empty reasons list;
 * (b) a proposal meeting the preconditions is authorized.
 */
final class AtlasLoopMergeAuthPreviewCommandTest extends TestCase
{
    public function test_proposal_over_scope_tier_is_denied_with_reasons(): void
    {
        $this->bindGate();

        // critical blast (a changed file under the critical 'app/' prefix) on a LOW-risk scope ⇒ denied.
        $this->app->bind('atlas.loop.merge_auth_preview.proposal', fn (): array => [
            'changed_files' => ['app/Services/Foo.php'],
            'critical_paths' => ['app/'],
        ]);

        $exit = Artisan::call('atlas:loop:merge-auth-preview', ['--scope' => 'demo', '--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertFalse($decoded['authorized']);
        $this->assertNotEmpty($decoded['reasons']);
        $this->assertContains('blast_radius_above_scope_tier', $decoded['reasons']);
    }

    public function test_proposal_within_preconditions_is_authorized(): void
    {
        $this->bindGate();

        // safe blast (no changed files / no critical paths) ⇒ authorized.
        $this->app->bind('atlas.loop.merge_auth_preview.proposal', fn (): array => [
            'changed_files' => [],
            'critical_paths' => [],
        ]);

        $exit = Artisan::call('atlas:loop:merge-auth-preview', ['--scope' => 'demo', '--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertTrue($decoded['authorized']);
        $this->assertSame([], $decoded['reasons']);
    }

    /**
     * Bind a real gate over a controlled in-memory manifest (home repo, low risk) + a temp audit journal, so
     * the test depends on neither the real scope registry nor real config scopes. The home repo is authorized
     * by enabling auto_merge_to_main; the gate's own decision logic is what we exercise.
     */
    private function bindGate(): void
    {
        config(['atlas.ai.loop.auto_merge_to_main' => true]);

        $manifest = [
            'id' => 'demo',
            'territory_name' => 'Demo Territory',
            'repo_root_absolute' => base_path(),
            'discovery_roots' => ['app/'],
            'frozen_safety_files' => [],
            'risk_tier' => 'low',
            'max_parallel_workers' => 1,
            'promotion_required_certified_leaps' => 1,
        ];

        $journalPath = tempnam(sys_get_temp_dir(), 'merge_auth_preview_').'.ndjson';

        $this->app->instance(AtlasLoopV2EnterpriseMergeGate::class, new AtlasLoopV2EnterpriseMergeGate(
            new AtlasLoopV2ScopeManifestRegistry([$manifest]),
            new AtlasLoopMultiRepoMergeAuthority,
            new AtlasLoopV2BlastRadiusCalculator,
            new AtlasLoopV2AuditJournal($journalPath),
        ));
    }
}
