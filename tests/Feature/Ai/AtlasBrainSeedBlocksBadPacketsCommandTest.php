<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class AtlasBrainSeedBlocksBadPacketsCommandTest extends TestCase
{
    /** @var string */
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir().'/atlas-brain-seed-bad-packets-'.bin2hex(random_bytes(4));
        if (! mkdir($this->tmpDir) && ! is_dir($this->tmpDir)) {
            $this->markTestSkipped('Cannot create tmp dir for fixtures');
        }

        Config::set('atlas.task_serving.queue_disk', 'atlas_task_serving_test');
        Config::set('atlas.brain.done_set_root', $this->tmpDir.'/done-set');
        Config::set('atlas.brain.master_enabled', true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            File::deleteDirectory($this->tmpDir);
        }

        parent::tearDown();
    }

    public function test_bad_proxy_objective_returns_blocked_with_stage_and_reasons_without_enqueue(): void
    {
        $specPath = $this->tmpDir.'/bad-proxy-spec.json';
        file_put_contents($specPath, json_encode([
            'packets' => [[
                'task_packet_id' => 'brain-bad-proxy-001',
                'objective' => 'Wire the built-but-unused AtlasLegacyRouter into the live flow to improve routing in production',
                'problem' => 'Legacy router is unwired and a candidate dormant proxy',
                'expected_delta' => 'AtlasLegacyRouter is reachable through a production call path with runnable evidence',
                'value' => 'runtime value delivered by wiring the dormant router, not just decoration',
                'duplicate_key' => 'atlas-legacy-router-wiring-v1',
                'freshness_check' => 'Verified via atlas:brain:next dry-run probe on 2026-07-09, no stale flag',
                'anti_proxy' => 'Implements real call path with failing test evidence, not stub wire',
                'allowed_files' => ['app/Services/Ai/NonExistentLegacyRouterWiring.php'],
                'acceptance_criteria' => ['artisan test --filter=LegacyRouterWiring exits 0 with 5 assertions'],
                'required_evidence' => ['tests_or_gates_result'],
                'modifies_existing_files' => false,
            ]],
        ]));

        $exit = Artisan::call('atlas:brain:seed', [
            '--specs' => $specPath,
            '--scope' => 'autonomous',
            '--actor' => 'test',
            '--require-actor' => true,
            '--json' => true,
        ]);

        $output = json_decode(Artisan::output(), true);
        $this->assertIsArray($output);
        $this->assertSame(0, $exit, 'Bad-packet seed returns 0 (handler completes) but must surface blocked stages.');
        $this->assertSame('ok', $output['status'] ?? null);

        $this->assertArrayHasKey('results', $output);
        $this->assertNotEmpty($output['results']);
        $first = $output['results'][0];
        $this->assertSame('blocked', $first['status'] ?? null);
        $this->assertContains($first['stage'] ?? '', ['classifier', 'harness_guard', 'seed_quality', 'cycle_progress']);
        $this->assertNotEmpty($first['reasons'] ?? []);

        $this->assertSame(0, $output['counts']['enqueued'] ?? -1);
        $this->assertSame(0, $output['counts']['credited'] ?? -1);
        $this->assertGreaterThanOrEqual(1, $output['counts']['blocked'] ?? 0);
    }

    public function test_missing_required_evidence_blocks_at_seed_quality(): void
    {
        $specPath = $this->tmpDir.'/missing-evidence-spec.json';
        file_put_contents($specPath, json_encode([
            'packets' => [[
                'task_packet_id' => 'brain-missing-evidence-001',
                'objective' => 'Add AtlasRuntimeInvariantValidator to guard brain-cycle invariants at runtime entry',
                'problem' => 'Brain cycle lacks invariant validation causing silent failures in production systems',
                'expected_delta' => 'AtlasRuntimeInvariantValidator class added with 5 green test cases and runtime proof captured',
                'value' => 'Prevents silent runtime failures; proof of test coverage eliminates manual review overhead',
                'duplicate_key' => 'atlas-runtime-invariant-validator-brain-cycle-check-v1',
                'freshness_check' => 'Verified 2026-07-09 via atlas:brain:next dry-run probe, no stale flag returned by scan',
                'anti_proxy' => 'Implements real invariant check with failing test evidence, not just scaffold or stub wire',
                'allowed_files' => ['app/Services/Ai/NonExistentAtlasRuntimeInvariantValidator.php'],
                'acceptance_criteria' => [
                    '/opt/homebrew/bin/php artisan test --filter=AtlasRuntimeInvariantValidator exits 0 with 5 assertions',
                ],
                'required_evidence' => [],
                'modifies_existing_files' => false,
            ]],
        ]));

        $exit = Artisan::call('atlas:brain:seed', [
            '--specs' => $specPath,
            '--scope' => 'autonomous',
            '--actor' => 'test',
            '--require-actor' => true,
            '--json' => true,
        ]);

        $output = json_decode(Artisan::output(), true);
        $this->assertSame(0, $exit);
        $this->assertSame('ok', $output['status'] ?? null);

        $first = $output['results'][0];
        $this->assertSame('blocked', $first['status'] ?? null);
        $this->assertNotEmpty($first['reasons'] ?? []);
        $this->assertSame(0, $output['counts']['enqueued'] ?? -1);
    }

    public function test_require_actor_blocks_when_actor_is_empty(): void
    {
        $specPath = $this->tmpDir.'/no-actor-spec.json';
        file_put_contents($specPath, json_encode([
            'packets' => [[
                'task_packet_id' => 'brain-no-actor-001',
                'objective' => 'Add AtlasRuntimeInvariantValidator to guard brain-cycle invariants at runtime entry',
                'allowed_files' => ['app/Services/Ai/NonExistentAtlasRuntimeInvariantValidator.php'],
            ]],
        ]));

        $exit = Artisan::call('atlas:brain:seed', [
            '--specs' => $specPath,
            '--scope' => 'autonomous',
            '--actor' => '',
            '--require-actor' => true,
            '--json' => true,
        ]);

        $this->assertSame(1, $exit);
        $output = json_decode(Artisan::output(), true);
        $this->assertSame('missing_actor', $output['status'] ?? null);
        $this->assertSame(0, $output['counts']['enqueued'] ?? -1);
    }
}