<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainDoneSetLedger;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class AtlasBrainSeedDryRunDoneSetCommandTest extends TestCase
{
    /** @var string */
    private string $tmpDir;

    /** @var string */
    private string $doneSetRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir().'/atlas-brain-seed-dry-run-'.bin2hex(random_bytes(4));
        if (! mkdir($this->tmpDir) && ! is_dir($this->tmpDir)) {
            $this->markTestSkipped('Cannot create tmp dir for fixtures');
        }

        $this->doneSetRoot = $this->tmpDir.'/done-set';
        Config::set('atlas.task_serving.queue_disk', 'atlas_task_serving_test');
        Config::set('atlas.brain.done_set_root', $this->doneSetRoot);
        Config::set('atlas.brain.master_enabled', true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            File::deleteDirectory($this->tmpDir);
        }

        parent::tearDown();
    }

    public function test_dry_run_gated_ok_does_not_record_done_set(): void
    {
        $targetPath = 'app/Services/Ai/NonExistentDryRunTarget.php';
        $specPath = $this->tmpDir.'/dry-run-spec.json';
        file_put_contents($specPath, json_encode([
            'packets' => [[
                'task_packet_id' => 'brain-dry-run-001',
                'objective' => 'Add AtlasRuntimeInvariantValidator to guard brain-cycle invariants at runtime entry',
                'problem' => 'Brain cycle lacks invariant validation causing silent failures in production systems',
                'expected_delta' => 'AtlasRuntimeInvariantValidator class added with 5 green test cases and runtime proof captured',
                'value' => 'Prevents silent runtime failures; proof of test coverage eliminates manual review overhead',
                'duplicate_key' => 'atlas-runtime-invariant-validator-brain-cycle-check-v1',
                'freshness_check' => 'Verified 2026-07-09 via atlas:brain:next dry-run probe, no stale flag returned by scan',
                'anti_proxy' => 'Implements real invariant check with failing test evidence, not just scaffold or stub wire',
                'allowed_files' => [$targetPath],
                'acceptance_criteria' => [
                    '/opt/homebrew/bin/php artisan test --filter=AtlasRuntimeInvariantValidator exits 0 with 5 assertions',
                ],
                'required_evidence' => ['tests_or_gates_result'],
                'modifies_existing_files' => false,
            ]],
        ]));

        $exit = Artisan::call('atlas:brain:seed', [
            '--specs' => $specPath,
            '--scope' => 'autonomous',
            '--actor' => 'test',
            '--require-actor' => true,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $output = json_decode(Artisan::output(), true);
        $this->assertSame(0, $exit);
        $this->assertSame('ok', $output['status'] ?? null);
        $this->assertSame(1, $output['counts']['dry_run'] ?? 0);
        $this->assertSame(0, $output['counts']['enqueued'] ?? -1);

        $ledger = new AtlasBrainDoneSetLedger('autonomous', $this->doneSetRoot);
        $this->assertFalse($ledger->isDone($targetPath), 'Dry-run must NOT record target into done-set.');
    }

    public function test_real_seed_after_dry_run_does_not_return_skipped_done_set(): void
    {
        $targetPath = 'app/Services/Ai/NonExistentDryRunThenRealTarget.php';
        $specPath = $this->tmpDir.'/dry-run-then-real-spec.json';
        file_put_contents($specPath, json_encode([
            'packets' => [[
                'task_packet_id' => 'brain-dry-then-real-001',
                'objective' => 'Add AtlasRuntimeInvariantValidator to guard brain-cycle invariants at runtime entry',
                'problem' => 'Brain cycle lacks invariant validation causing silent failures in production systems',
                'expected_delta' => 'AtlasRuntimeInvariantValidator class added with 5 green test cases and runtime proof captured',
                'value' => 'Prevents silent runtime failures; proof of test coverage eliminates manual review overhead',
                'duplicate_key' => 'atlas-runtime-invariant-validator-brain-cycle-check-v1',
                'freshness_check' => 'Verified 2026-07-09 via atlas:brain:next dry-run probe, no stale flag returned by scan',
                'anti_proxy' => 'Implements real invariant check with failing test evidence, not just scaffold or stub wire',
                'allowed_files' => [$targetPath],
                'acceptance_criteria' => [
                    '/opt/homebrew/bin/php artisan test --filter=AtlasRuntimeInvariantValidator exits 0 with 5 assertions',
                ],
                'required_evidence' => ['tests_or_gates_result'],
                'modifies_existing_files' => false,
            ]],
        ]));

        // 1. Dry-run first.
        $dryExit = Artisan::call('atlas:brain:seed', [
            '--specs' => $specPath,
            '--scope' => 'autonomous',
            '--actor' => 'test',
            '--require-actor' => true,
            '--dry-run' => true,
            '--json' => true,
        ]);
        $dryOutput = json_decode(Artisan::output(), true);
        $this->assertSame(0, $dryExit);
        $this->assertSame('dry_run', $dryOutput['results'][0]['status'] ?? null);

        // 2. Real seed next — must NOT be skipped_done_set.
        $realExit = Artisan::call('atlas:brain:seed', [
            '--specs' => $specPath,
            '--scope' => 'autonomous',
            '--actor' => 'test',
            '--require-actor' => true,
            '--json' => true,
        ]);
        $realOutput = json_decode(Artisan::output(), true);

        $this->assertSame(0, $realExit);
        $this->assertNotSame('skipped_done_set', $realOutput['results'][0]['status'] ?? null, 'Dry-run must not burn the target in done-set.');
    }
}