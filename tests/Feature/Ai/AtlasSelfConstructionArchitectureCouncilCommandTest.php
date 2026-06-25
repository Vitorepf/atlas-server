<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves atlas:self-construction:architecture-council: inspect lists services; critic accepts a clean
 * contract and rejects a bad one; invariants passes the declared list through; boundaries returns
 * allowed/forbidden edges; slices designs implementation briefs; unknown action yields unknown_action.
 */
final class AtlasSelfConstructionArchitectureCouncilCommandTest extends TestCase
{
    private string $contractPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->contractPath = sys_get_temp_dir().'/atlas_ac_contract_'.bin2hex(random_bytes(6)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->contractPath);
        parent::tearDown();
    }

    private function writeJson(array $data): void
    {
        file_put_contents($this->contractPath, json_encode($data, JSON_UNESCAPED_SLASHES));
    }

    private function goodContract(): array
    {
        return [
            'organ' => 'Task Fabric',
            'capability' => 'compile_contract',
            'responsibilities' => ['compile contracts into atomic packet specs'],
            'non_authority' => ['cannot verify outcome'],
            'inputs' => ['architecture_contract'],
            'outputs' => ['packet_spec_drafts'],
            'invariants' => ['no scoring'],
            'forbidden_side_effects' => ['execute commands', 'shell', 'git', 'merge', 'call external provider'],
            'evidence_refs' => ['docs/x.md'],
            'verifies' => ['organ' => 'Verification Court'],
        ];
    }

    public function test_inspect_lists_services_and_non_execution_guarantees(): void
    {
        $exit = Artisan::call('atlas:self-construction:architecture-council', ['action' => 'inspect', '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertSame(0, $exit);
        $this->assertFalse($p['non_execution_guarantees']['enqueues_tasks']);
    }

    public function test_critic_accepts_clean_contract(): void
    {
        $this->writeJson($this->goodContract());
        Artisan::call('atlas:self-construction:architecture-council', ['action' => 'critic', '--contract' => $this->contractPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertTrue($p['critic']['accepted']);
    }

    public function test_critic_blocks_bad_contract_with_findings(): void
    {
        $c = $this->goodContract();
        $c['non_authority'] = []; // forces missing_non_authority finding
        $this->writeJson($c);
        Artisan::call('atlas:self-construction:architecture-council', ['action' => 'critic', '--contract' => $this->contractPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertFalse($p['critic']['accepted']);
        $this->assertContains('missing_non_authority', $p['critic']['findings']);
    }

    public function test_invariants_passes_declared_list_through(): void
    {
        $this->writeJson($this->goodContract());
        Artisan::call('atlas:self-construction:architecture-council', ['action' => 'invariants', '--contract' => $this->contractPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertSame(['no scoring'], $p['invariants']);
    }

    public function test_boundaries_returns_allowed_and_forbidden_edges(): void
    {
        $this->writeJson([
            'contracts' => [
                ['organ' => 'Worker Swarm', 'non_authority' => ['x'], 'integrations' => [
                    ['from' => 'Worker Swarm', 'to' => 'Merge Governor', 'action' => 'execute_merge'],
                ]],
            ],
        ]);
        Artisan::call('atlas:self-construction:architecture-council', ['action' => 'boundaries', '--contract' => $this->contractPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertNotEmpty($p['boundary_map']['forbidden_edges']);
    }

    public function test_slices_designs_implementation_brief_from_valid_input(): void
    {
        $this->writeJson([
            'critique' => ['accepted' => true],
            'invariants' => ['no_scoring'],
            'boundary_map' => ['forbidden_edges' => []],
            'capability_gap' => [
                'organ' => 'TF',
                'capability' => 'cap',
                'target_files' => [
                    ['kind' => 'service', 'path' => 'app/Demo/X.php'],
                    ['kind' => 'test', 'path' => 'tests/Unit/Demo/XTest.php'],
                ],
                'acceptance_seed' => ['ok'],
                'evidence_seed' => ['test_run_id'],
            ],
        ]);
        Artisan::call('atlas:self-construction:architecture-council', ['action' => 'slices', '--contract' => $this->contractPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertCount(1, $p['slices']['slice_briefs']);
    }

    public function test_unknown_action_yields_unknown_action(): void
    {
        $exit = Artisan::call('atlas:self-construction:architecture-council', ['action' => 'bogus', '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertNotSame(0, $exit);
        $this->assertSame('unknown_action', $p['status']);
    }
}
