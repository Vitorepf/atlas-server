<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\WorkerSwarm\AtlasSelfConstructionWorkerCapabilityContract;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves atlas:self-construction:workers: capability evaluates a profile; match emits eligible workers
 * per packet; envelope builds an Atlas-native execution envelope; normalize canonicalises worker result
 * fields; invalid --facts yields usage_error; unknown action yields unknown_action.
 */
final class AtlasSelfConstructionWorkersCommandTest extends TestCase
{
    private string $factsPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factsPath = sys_get_temp_dir().'/atlas_workers_facts_'.bin2hex(random_bytes(6)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->factsPath);
        parent::tearDown();
    }

    private function writeJson(array $data): void
    {
        file_put_contents($this->factsPath, json_encode($data, JSON_UNESCAPED_SLASHES));
    }

    public function test_capability_action_evaluates_worker_profile(): void
    {
        $this->writeJson([
            'worker_id' => 'w-1',
            'runtime_owner' => AtlasSelfConstructionWorkerCapabilityContract::RUNTIME_OWNER_NATIVE,
            'scope_roots' => ['app/Demo'],
            'declared_capabilities' => ['inspect_task_packet'],
            'declared_actions' => ['inspect'],
            'evidence_emits' => AtlasSelfConstructionWorkerCapabilityContract::REQUIRED_EVIDENCE,
        ]);
        Artisan::call('atlas:self-construction:workers', ['action' => 'capability', '--facts' => $this->factsPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertTrue($p['capability']['accepted']);
    }

    public function test_match_action_pairs_workers_to_packets_by_capability(): void
    {
        $this->writeJson([
            'workers' => [
                ['worker_id' => 'w-A', 'declared_capabilities' => ['inspect_task_packet', 'run_gates']],
                ['worker_id' => 'w-B', 'declared_capabilities' => ['inspect_task_packet']],
            ],
            'packets' => [
                ['id' => 'pkt-1', 'required_capabilities' => ['run_gates']],
                ['id' => 'pkt-2', 'required_capabilities' => ['inspect_task_packet']],
            ],
        ]);
        Artisan::call('atlas:self-construction:workers', ['action' => 'match', '--facts' => $this->factsPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $byPacket = [];
        foreach ($p['matches'] as $m) {
            $byPacket[$m['packet_id']] = $m['eligible_workers'];
        }
        $this->assertSame(['w-A'], $byPacket['pkt-1']);
        $this->assertSame(['w-A', 'w-B'], $byPacket['pkt-2']);
    }

    public function test_envelope_action_builds_atlas_native_envelope(): void
    {
        $this->writeJson([
            'objective' => 'Add a small thing.',
            'allowed_files' => ['app/Demo/Foo.php'],
            'acceptance_criteria' => ['phpunit green'],
            'required_evidence' => ['test_run_id'],
            'simplicity_contract' => 'atlas_native',
        ]);
        Artisan::call('atlas:self-construction:workers', ['action' => 'envelope', '--facts' => $this->factsPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertSame('atlas_native', $p['envelope']['runtime_owner']);
        $this->assertNull($p['envelope']['provider_prompt']);
    }

    public function test_envelope_action_fails_closed_on_missing_allowed_files(): void
    {
        $this->writeJson(['objective' => 'no allowed_files']);
        Artisan::call('atlas:self-construction:workers', ['action' => 'envelope', '--facts' => $this->factsPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertSame('envelope_invalid', $p['status']);
    }

    public function test_normalize_action_canonicalises_worker_result_fields(): void
    {
        $this->writeJson([
            'task_packet_id' => 'pkt-1',
            'lease_id' => 'lease-1',
            'files_changed' => ['app/Foo.php'],
            'commands_run' => [['name' => 'phpunit']],
            'tests_or_gates_result' => ['passed' => true],
            'evidence_hash' => 'evh',
            'scope_deviations' => [],
            'residual_risks' => ['none'],
            'runtime_owner' => 'atlas_native',
        ]);
        Artisan::call('atlas:self-construction:workers', ['action' => 'normalize', '--facts' => $this->factsPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertSame('atlas_native', $p['normalized']['runtime_owner']);
        $this->assertSame('evh', $p['normalized']['evidence_hash']);
    }

    public function test_missing_facts_yields_usage_error(): void
    {
        $exit = Artisan::call('atlas:self-construction:workers', ['action' => 'capability', '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertNotSame(0, $exit);
        $this->assertSame('usage_error', $p['status']);
    }

    public function test_unknown_action_yields_unknown_action(): void
    {
        $this->writeJson(['worker_id' => 'w']);
        $exit = Artisan::call('atlas:self-construction:workers', ['action' => 'bogus', '--facts' => $this->factsPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertNotSame(0, $exit);
        $this->assertSame('unknown_action', $p['status']);
    }
}
