<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the atlas:task:project-lanes CLI: inspect returns the action contract; admit-happy-path returns
 * admitted=true; admit on a malformed manifest returns admitted=false with blocking_reasons; health
 * surfaces stale context_pack as a blocker; unknown verb is rejected.
 */
final class AtlasProjectLaneStewardshipCommandTest extends TestCase
{
    private string $manifestPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manifestPath = sys_get_temp_dir().'/atlas_lane_manifest_'.bin2hex(random_bytes(6)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->manifestPath);
        parent::tearDown();
    }

    private function writeManifest(array $manifest): string
    {
        file_put_contents($this->manifestPath, json_encode($manifest, JSON_UNESCAPED_SLASHES));

        return $this->manifestPath;
    }

    private function happyManifest(int $now): array
    {
        return [
            'project_id' => 'demo-lane',
            'repo_root' => '/Users/vitorepf/develop/Atlas/atlas-server',
            'objective' => 'Demonstrate a healthy lane manifest.',
            'mainline_branch' => 'main',
            'allowed_scope_roots' => ['/Users/vitorepf/develop/Atlas/atlas-server/app'],
            'verification_commands' => ['php artisan test'],
            'merge_policy' => ['mode' => 'fast_forward'],
            'rollback_policy' => ['mode' => 'revert_commit'],
            'knowledge_sync_policy' => ['mode' => 'daily'],
            'freshness_window_seconds' => ['docs_sync' => 3600, 'code_index' => 3600, 'context_pack' => 3600],
            'context_observations' => [
                'now_unix' => $now,
                'docs_sync_last_unix' => $now - 60,
                'code_index_last_unix' => $now - 120,
                'context_pack_hash' => 'sha256:'.str_repeat('a', 8),
                'context_pack_last_unix' => $now - 30,
            ],
        ];
    }

    public function test_inspect_returns_required_manifest_fields_and_zero_side_effects(): void
    {
        $exit = Artisan::call('atlas:task:project-lanes', ['action' => 'inspect', '--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertContains('project_id', $payload['required_manifest_fields']);
        $this->assertContains('repo_root', $payload['required_manifest_fields']);
        $this->assertFalse($payload['side_effects']['writes_storage']);
        $this->assertFalse($payload['side_effects']['starts_workers']);
        $this->assertFalse($payload['side_effects']['calls_external_providers']);
        $this->assertFalse($payload['side_effects']['invokes_shell_or_git']);
    }

    public function test_admit_happy_path_returns_admitted_true_with_canonical_schema(): void
    {
        $this->writeManifest($this->happyManifest(time()));
        $exit = Artisan::call('atlas:task:project-lanes', ['action' => 'admit', '--manifest' => $this->manifestPath, '--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertTrue($payload['admitted'], 'happy manifest must be admitted');
        $this->assertSame('demo-lane', $payload['project_id']);
        $this->assertSame([], $payload['blocking_reasons']);
    }

    public function test_admit_blocked_manifest_reports_blocking_reasons_no_side_effects(): void
    {
        // Missing required fields: only project_id supplied.
        $this->writeManifest(['project_id' => 'bad-lane']);
        $exit = Artisan::call('atlas:task:project-lanes', ['action' => 'admit', '--manifest' => $this->manifestPath, '--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertFalse($payload['admitted']);
        $this->assertNotEmpty($payload['blocking_reasons']);
    }

    public function test_health_with_stale_context_pack_lists_blocker(): void
    {
        $now = time();
        $manifest = $this->happyManifest($now);
        // Force context_pack to be 1 day old, beyond the 3600s window.
        $manifest['context_observations']['context_pack_last_unix'] = $now - 86400;
        $this->writeManifest($manifest);

        Artisan::call('atlas:task:project-lanes', ['action' => 'health', '--manifest' => $this->manifestPath, '--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertContains('context_pack_stale', $payload['lane_health']['blockers']);
        $this->assertFalse($payload['lane_health']['conformant']);
        // Anti-Goodhart: NO scalar hype score field anywhere in lane_health.
        foreach (array_keys($payload['lane_health']) as $key) {
            $this->assertDoesNotMatchRegularExpression('/score|grade|percent|hype/i', $key);
        }
    }

    public function test_unknown_action_returns_unknown_action_error(): void
    {
        $exit = Artisan::call('atlas:task:project-lanes', ['action' => 'bogus', '--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertNotSame(0, $exit);
        $this->assertSame('unknown_action', $payload['status']);
    }
}
