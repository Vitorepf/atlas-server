<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasLoopLiveCycleCommandTest extends TestCase
{
    private string $stateDir = '';

    private string $factsFile = '';

    private string $fakeEnvPath = '';

    private ?string $prevOverride = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stateDir = sys_get_temp_dir().'/atlas-livecycle-cli-'.bin2hex(random_bytes(6));
        @mkdir($this->stateDir, 0o755, true);
        $this->factsFile = $this->stateDir.'/facts.jsonl';
        $this->fakeEnvPath = $this->stateDir.'/.env';
        $this->prevOverride = AtlasLoopMasterSwitch::$envPathOverride;
        AtlasLoopMasterSwitch::$envPathOverride = $this->fakeEnvPath;
    }

    protected function tearDown(): void
    {
        AtlasLoopMasterSwitch::$envPathOverride = $this->prevOverride;
        $this->rrmdir($this->stateDir);
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach ((array) scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir.'/'.$entry;
            if (is_dir($full)) {
                $this->rrmdir($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($dir);
    }

    private function setMaster(bool $on): void
    {
        file_put_contents($this->fakeEnvPath, AtlasLoopMasterSwitch::KEY.'='.($on ? '1' : '0')."\n");
    }

    public function test_start_with_master_on_prints_cycle_id_and_root_hash_and_emits_facts(): void
    {
        $this->setMaster(true);
        $exit = Artisan::call('atlas:loop:cycle:run', [
            'action' => 'start',
            '--state-dir' => $this->stateDir,
            '--facts-file' => $this->factsFile,
            '--cycle-id' => 'cyc-test',
        ]);
        $out = trim(Artisan::output());
        $decoded = json_decode($out, true);

        $this->assertSame(0, $exit);
        $this->assertIsArray($decoded);
        $this->assertSame('cyc-test', $decoded['cycle_id']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $decoded['root_hash']);

        $this->assertFileExists($this->factsFile);
        $factsText = (string) file_get_contents($this->factsFile);
        $this->assertStringContainsString('cycle.started', $factsText);
        $this->assertStringContainsString('cycle.phase.completed', $factsText);
        $this->assertStringContainsString('cycle.receipt.composed', $factsText);
        $this->assertStringContainsString('cycle.completed', $factsText);
    }

    public function test_master_off_makes_start_print_OFF_and_exit_0_with_zero_facts(): void
    {
        $this->setMaster(false);
        $exit = Artisan::call('atlas:loop:cycle:run', [
            'action' => 'start',
            '--state-dir' => $this->stateDir,
            '--facts-file' => $this->factsFile,
        ]);
        $out = trim(Artisan::output());

        $this->assertSame(0, $exit);
        $this->assertSame('OFF', $out);
        $this->assertFileDoesNotExist($this->factsFile);
    }

    public function test_master_off_makes_resume_print_OFF_and_exit_0(): void
    {
        $this->setMaster(false);
        $exit = Artisan::call('atlas:loop:cycle:run', [
            'action' => 'resume',
            '--state-dir' => $this->stateDir,
            '--cycle-id' => 'cyc-test',
        ]);
        $this->assertSame(0, $exit);
        $this->assertSame('OFF', trim(Artisan::output()));
    }

    public function test_status_returns_master_switch_state_even_with_no_cycle(): void
    {
        $this->setMaster(true);
        $exit = Artisan::call('atlas:loop:cycle:run', [
            'action' => 'status',
            '--state-dir' => $this->stateDir,
            '--cycle-id' => 'cyc-unknown',
        ]);
        $decoded = json_decode(trim(Artisan::output()), true);
        $this->assertSame(0, $exit);
        $this->assertSame('on', $decoded['master_switch']);
        $this->assertSame(0, $decoded['last_phase_index']);
    }

    public function test_audit_returns_real_data_even_when_master_off(): void
    {
        // Seed: run with master ON, then flip OFF and ensure audit still reads facts.
        $this->setMaster(true);
        Artisan::call('atlas:loop:cycle:run', [
            'action' => 'start',
            '--state-dir' => $this->stateDir,
            '--facts-file' => $this->factsFile,
            '--cycle-id' => 'cyc-audit',
        ]);
        Artisan::output();

        $this->setMaster(false);
        $exit = Artisan::call('atlas:loop:cycle:run', [
            'action' => 'audit',
            '--facts-file' => $this->factsFile,
            '--cycle-id' => 'cyc-audit',
        ]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('cyc-audit', $decoded['cycle_id']);
        $this->assertFalse($decoded['empty']);
        $this->assertSame(8, $decoded['phase_count_completed']);
    }
}
