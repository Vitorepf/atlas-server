<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsActionDispatcher;
use Tests\TestCase;

/**
 * End-to-end gate for the canonical local_fake release run:
 *
 *     php artisan atlas:forge:rivals run-battery
 *         --case-set=release --mode=local_fake
 *         --atlas-model=claude_sonnet --rival=claude_sonnet
 *         --preset=release --json --strict
 *
 * The 40-case battery MUST finish comparable with dirty_after_run=false
 * and no fixture blockers. This test exists because the seed-staging
 * step was previously contaminating dirty_after_run with pre-run setup
 * issues, tripping the verdict_comparable + dirty_after_run_false hard
 * gates even though no arm dirtied a workspace.
 */
final class AtlasForgeRivalsRunBatteryLocalFakeReleaseIntegrationTest extends TestCase
{
    public function test_local_fake_release_40_cases_finishes_comparable_with_clean_workspace(): void
    {
        $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);

        $response = $dispatcher->dispatch('run-battery', [
            'mode' => 'local_fake',
            'atlas_model' => 'claude_sonnet',
            'rival' => 'claude_sonnet',
            'preset' => 'release',
            'case_set' => 'release',
            'strict' => true,
            // local_fake bypasses the three real-provider confirmations; the
            // dispatcher treats them as no-ops in this mode.
            'confirmations' => [
                'runbook_reviewed' => false,
                'provider_cost' => false,
                'real_provider_call' => false,
            ],
        ]);

        $this->assertSame('ok', $response['status'], (string) json_encode($response, JSON_PRETTY_PRINT));
        $this->assertFalse($response['external_provider_call']);
        $this->assertFalse($response['provider_tokens_spent']);

        $runId = (string) ($response['run_id'] ?? '');
        $this->assertNotSame('', $runId, 'run_id must be returned');

        $manifestPath = base_path('../Atlas-rivals/runs/'.$runId.'/evidence/manifest.json');
        if (! is_file($manifestPath)) {
            // Some test environments place runs under a different absolute root
            // (the path is operator-host-dependent). Probe the canonical
            // evidence_paths array first.
            foreach ((array) ($response['evidence_paths'] ?? []) as $candidate) {
                if (str_ends_with((string) $candidate, '/manifest.json') && is_file((string) $candidate)) {
                    $manifestPath = (string) $candidate;
                    break;
                }
            }
        }
        $this->assertFileExists($manifestPath, 'manifest.json must be on disk for the release run');

        $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(40, (int) ($manifest['case_count'] ?? -1), 'release run must execute 40 cases');
        $this->assertSame('comparable', (string) ($manifest['verdict'] ?? ''));
        $this->assertFalse((bool) ($manifest['dirty_after_run'] ?? true));
        $this->assertSame([], (array) ($manifest['workspace_blockers'] ?? null));
        $this->assertSame([], (array) ($manifest['fixture_blockers'] ?? null));
        $this->assertSame([], (array) ($manifest['aggregate_workspace_blockers'] ?? null));
        $this->assertSame([], (array) ($manifest['aggregate_fixture_blockers'] ?? null));

        foreach ((array) ($manifest['cases'] ?? []) as $case) {
            $this->assertSame(
                'comparable',
                (string) ($case['verdict'] ?? ''),
                'case '.($case['case_id'] ?? '?').' must be comparable in local_fake release run',
            );
            $this->assertSame([], (array) ($case['workspace_blockers'] ?? null));
            $this->assertSame([], (array) ($case['fixture_blockers'] ?? null));
        }
    }

    public function test_local_fake_release_hard_gates_verdict_comparable_and_dirty_after_run_false_are_green(): void
    {
        $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);
        $response = $dispatcher->dispatch('run-battery', [
            'mode' => 'local_fake',
            'atlas_model' => 'claude_sonnet',
            'rival' => 'claude_sonnet',
            'preset' => 'release',
            'case_set' => 'release',
            'strict' => true,
        ]);

        $this->assertSame('ok', $response['status'], (string) json_encode($response['blockers'] ?? [], JSON_PRETTY_PRINT));

        $hardGates = (array) data_get($response, 'scorecard.hard_gates', []);
        $verdictGate = null;
        $dirtyGate = null;
        foreach ($hardGates as $gate) {
            if (($gate['code'] ?? null) === 'verdict_comparable') {
                $verdictGate = $gate;
            }
            if (($gate['code'] ?? null) === 'dirty_after_run_false') {
                $dirtyGate = $gate;
            }
        }

        $this->assertNotNull($verdictGate, 'scorecard must publish verdict_comparable gate');
        $this->assertTrue((bool) ($verdictGate['ok'] ?? false), 'verdict_comparable must be true');
        $this->assertSame('comparable', (string) ($verdictGate['detail'] ?? ''));

        $this->assertNotNull($dirtyGate, 'scorecard must publish dirty_after_run_false gate');
        $this->assertTrue((bool) ($dirtyGate['ok'] ?? false), 'dirty_after_run_false must be true');

        $this->assertSame([], (array) data_get($response, 'scorecard.hard_failures', null));
    }
}
