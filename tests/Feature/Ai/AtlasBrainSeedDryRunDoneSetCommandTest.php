<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainDoneSetLedger;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainMasterSwitch;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Dedicated coverage for `atlas:brain:seed --dry-run` not polluting the done-set,
 * so a subsequent real seed of the same target is not skipped as already done.
 * Isolated from live serving disk, ledgers and the operator's .env.
 */
final class AtlasBrainSeedDryRunDoneSetCommandTest extends TestCase
{
    private const TEST_DISK = 'atlas_serving_brain_seed_dryrun_test';

    private string $doneSetRoot;

    private string $journalRoot;

    private string $envPath;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.task_serving.queue_disk', self::TEST_DISK);
        Storage::fake(self::TEST_DISK);
        Storage::fake('local');

        $base = sys_get_temp_dir().'/atlas-brain-seed-dryrun-'.bin2hex(random_bytes(6));
        $this->doneSetRoot = $base.'/done-set';
        $this->journalRoot = $base.'/journal';
        @mkdir($this->doneSetRoot, 0775, true);
        @mkdir($this->journalRoot, 0775, true);
        config()->set('atlas.brain.done_set_root', $this->doneSetRoot);
        config()->set('atlas.brain.journal_root', $this->journalRoot);

        $this->envPath = $base.'/.env';
        file_put_contents($this->envPath, "APP_ENV=testing\n");
        AtlasBrainMasterSwitch::$envPathOverride = $this->envPath;

        config()->set('atlas.brain.default_scope', 'loop');
        config()->set('atlas.brain.scopes.loop', [
            'label' => 'test',
            'roots' => ['app/Services/Ai/AutonomousEvolution'],
            'docs_roots' => [],
            'meta_harness' => true,
        ]);

        $this->brainOn();
    }

    protected function tearDown(): void
    {
        AtlasBrainMasterSwitch::$envPathOverride = null;
        parent::tearDown();
    }

    private function brainOn(): void
    {
        file_put_contents($this->envPath, "APP_ENV=testing\n".AtlasBrainMasterSwitch::KEY."=true\n");
    }

    private function cleanPacket(): array
    {
        return [
            'task_packet_id' => 'brain:dryrun-'.bin2hex(random_bytes(4)),
            'objective' => 'Harden App\\Models\\AtlasNonHarnessTarget so the computed field stays consistent — add the missing validation guard and cover it.',
            'allowed_files' => ['app/Models/AtlasNonHarnessTarget.php'],
            'scope_in' => ['app/Models/AtlasNonHarnessTarget.php'],
            'acceptance_criteria' => ['php artisan test tests/Unit passes (no regressions)'],
            'evidence_requirements' => ['tests_or_gates_result'],
            'problem' => 'The clean anti-fake packet needs credited runtime proof before seed quota can move.',
            'expected_delta' => 'AtlasNonHarnessTarget changes behavior and the listed test gate proves the runtime delta.',
            'value' => 'This adds runtime test proof for Atlas autonomy and prevents proxy quota credit.',
            'duplicate_key' => 'dryrun-clean|runtime-target|test-proof',
            'freshness_check' => 'Re-check the allowed file before seeding so this clean packet is not stale.',
            'anti_proxy' => 'Invalid if it only wraps, renames, formats, or exposes dormant code without behavior proof.',
            'modifies_existing_files' => true,
            'existing_file_delta' => 'Existing targets receive a concrete behavior delta proved by the listed gate.',
            'risk_level' => 'medium',
        ];
    }

    private function specsFile(array $packets): string
    {
        $path = sys_get_temp_dir().'/brain-specs-dryrun-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($path, (string) json_encode(['packets' => $packets]));

        return $path;
    }

    public function test_dry_run_gated_ok_does_not_record_done_set(): void
    {
        $packet = $this->cleanPacket();
        $path = $this->specsFile([$packet]);

        $exit = Artisan::call('atlas:brain:seed', [
            '--specs' => $path,
            '--scope' => 'autonomous',
            '--actor' => 'test',
            '--require-actor' => true,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $first = json_decode(trim(Artisan::output()), true);

        $this->assertSame('ok', $first['status']);
        $this->assertTrue((bool) $first['dry_run']);
        $this->assertSame('dry_run', $first['results'][0]['status']);
        $this->assertSame('gated_ok', $first['results'][0]['stage']);

        $ledger = new AtlasBrainDoneSetLedger('loop', $this->doneSetRoot);
        $this->assertFalse(
            $ledger->isDone($packet['allowed_files'][0]),
            'dry-run must not burn the target into the done-set'
        );

        @unlink($path);
    }

    public function test_real_seed_after_dry_run_enqueues_and_records_done_set(): void
    {
        $packet = $this->cleanPacket();
        $path = $this->specsFile([$packet]);

        // Dry-run first
        Artisan::call('atlas:brain:seed', [
            '--specs' => $path,
            '--scope' => 'autonomous',
            '--actor' => 'test',
            '--require-actor' => true,
            '--dry-run' => true,
            '--json' => true,
        ]);

        // Real seed second
        $exit = Artisan::call('atlas:brain:seed', [
            '--specs' => $path,
            '--scope' => 'autonomous',
            '--actor' => 'test',
            '--require-actor' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $second = json_decode(trim(Artisan::output()), true);

        $this->assertSame('enqueued', $second['results'][0]['status']);
        $this->assertSame(1, (int) $second['counts']['enqueued']);
        $this->assertSame(0, (int) $second['counts']['skipped_done_set']);

        $ledger = new AtlasBrainDoneSetLedger('loop', $this->doneSetRoot);
        $this->assertTrue(
            $ledger->isDone($packet['allowed_files'][0]),
            'real seed must record the target into the done-set'
        );

        @unlink($path);
    }
}
