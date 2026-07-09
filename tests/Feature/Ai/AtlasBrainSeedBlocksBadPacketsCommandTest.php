<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainMasterSwitch;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Dedicated coverage for `atlas:brain:seed` refusing proxy/junk packets with actionable stage/reasons.
 * Isolated from live serving disk, ledgers and the operator's .env.
 */
final class AtlasBrainSeedBlocksBadPacketsCommandTest extends TestCase
{
    private const TEST_DISK = 'atlas_serving_brain_seed_bad_packets_test';

    private string $doneSetRoot;

    private string $journalRoot;

    private string $envPath;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.task_serving.queue_disk', self::TEST_DISK);
        Storage::fake(self::TEST_DISK);
        Storage::fake('local');

        $base = sys_get_temp_dir().'/atlas-brain-seed-bad-'.bin2hex(random_bytes(6));
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

    private function specsFile(array $packets): string
    {
        $path = sys_get_temp_dir().'/brain-specs-bad-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($path, (string) json_encode(['packets' => $packets]));

        return $path;
    }

    public function test_proxy_packet_is_blocked_with_stage_and_reasons(): void
    {
        $packet = [
            'task_packet_id' => 'brain:proxy-1',
            'objective' => 'refactor and rename things',
            'allowed_files' => ['app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainTaskSpecTranslator.php'],
            'scope_in' => ['app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainTaskSpecTranslator.php'],
            'acceptance_criteria' => ['it looks better'],
            'evidence_requirements' => ['tests_or_gates_result'],
        ];
        $path = $this->specsFile([$packet]);

        $exit = Artisan::call('atlas:brain:seed', [
            '--specs' => $path,
            '--scope' => 'autonomous',
            '--actor' => 'test',
            '--require-actor' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame('ok', $payload['status']);
        $this->assertSame(1, (int) $payload['counts']['blocked']);
        $this->assertSame(0, (int) $payload['counts']['enqueued']);
        $this->assertSame('blocked', $payload['results'][0]['status']);
        $this->assertSame('classifier', $payload['results'][0]['stage']);
        $this->assertContains('rejected_proxy', $payload['results'][0]['reasons']);
        $this->assertArrayHasKey('repair_hints', $payload['results'][0]);

        @unlink($path);
    }

    public function test_vague_non_proxy_packet_is_blocked_at_seed_quality_gate(): void
    {
        $packet = [
            'task_packet_id' => 'brain:vague-1',
            'objective' => 'do the thing',
            'allowed_files' => ['app/Models/AtlasNonHarnessTarget.php'],
            'scope_in' => ['app/Models/AtlasNonHarnessTarget.php'],
            'acceptance_criteria' => ['it is nicer'],
            'evidence_requirements' => ['tests_or_gates_result'],
        ];
        $path = $this->specsFile([$packet]);

        $exit = Artisan::call('atlas:brain:seed', [
            '--specs' => $path,
            '--scope' => 'autonomous',
            '--actor' => 'test',
            '--require-actor' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame('blocked', $payload['results'][0]['status']);
        $this->assertSame('seed_quality', $payload['results'][0]['stage']);
        $this->assertContains('vague_objective', $payload['results'][0]['reasons']);
        $this->assertContains('acceptance_not_runnable', $payload['results'][0]['reasons']);
        $this->assertArrayHasKey('repair_hints', $payload['results'][0]);

        @unlink($path);
    }

    public function test_missing_task_packet_id_is_blocked_at_input_stage(): void
    {
        $packet = [
            'objective' => 'valid objective that is concrete and runnable',
            'allowed_files' => ['app/Models/AtlasNonHarnessTarget.php'],
            'scope_in' => ['app/Models/AtlasNonHarnessTarget.php'],
            'acceptance_criteria' => ['php artisan test passes'],
            'evidence_requirements' => ['tests_or_gates_result'],
        ];
        $path = $this->specsFile([$packet]);

        $exit = Artisan::call('atlas:brain:seed', [
            '--specs' => $path,
            '--scope' => 'autonomous',
            '--actor' => 'test',
            '--require-actor' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame('blocked', $payload['results'][0]['status']);
        $this->assertSame('input', $payload['results'][0]['stage']);
        $this->assertContains('missing_task_packet_id', $payload['results'][0]['reasons']);

        @unlink($path);
    }
}
