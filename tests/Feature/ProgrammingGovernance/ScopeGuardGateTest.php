<?php

namespace Tests\Feature\ProgrammingGovernance;

use App\Models\AtlasProgrammingWorkItem;
use App\Services\Ai\Programming\Governance\Gates\ProgrammingScopeGuardGate;
use Tests\Concerns\CreatesAtlasProgrammingGovernanceTables;
use Tests\TestCase;

class ScopeGuardGateTest extends TestCase
{
    use CreatesAtlasProgrammingGovernanceTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAtlasProgrammingGovernanceTables();
    }

    protected function tearDown(): void
    {
        $this->dropAtlasProgrammingGovernanceTables();
        parent::tearDown();
    }

    public function test_skips_when_no_task_contracts_attached_yet(): void
    {
        $item = $this->makeWorkItem(tasks: [], evidence: []);
        $outcome = (new ProgrammingScopeGuardGate())->evaluate($item);

        $this->assertSame('skipped', $outcome->status);
        $this->assertSame('no_task_contracts_attached_yet', $outcome->reason);
    }

    public function test_fails_when_task_contract_lacks_allowed_files(): void
    {
        $item = $this->makeWorkItem(
            tasks: [['allowed_files' => [], 'forbidden_files' => []]],
            evidence: [],
        );
        $outcome = (new ProgrammingScopeGuardGate())->evaluate($item);

        $this->assertSame('failed', $outcome->status);
        $this->assertSame('task_contract_missing_allowed_files', $outcome->reason);
    }

    public function test_passes_when_all_files_are_inside_allowed(): void
    {
        $item = $this->makeWorkItem(
            tasks: [[
                'allowed_files' => ['app/Foo.php', 'app/Bar/*.php'],
                'forbidden_files' => [],
            ]],
            evidence: [
                ['files' => ['app/Foo.php', 'app/Bar/Baz.php'], 'storage' => ['persisted' => true]],
            ],
        );
        $outcome = (new ProgrammingScopeGuardGate())->evaluate($item);

        $this->assertSame('passed', $outcome->status);
        $this->assertSame(2, count($outcome->payload['verified_files']));
    }

    public function test_blocks_file_outside_allowed(): void
    {
        $item = $this->makeWorkItem(
            tasks: [[
                'allowed_files' => ['app/Foo.php'],
                'forbidden_files' => [],
            ]],
            evidence: [
                ['files' => ['app/Foo.php', 'app/Sneaky.php'], 'storage' => ['persisted' => true]],
            ],
        );
        $outcome = (new ProgrammingScopeGuardGate())->evaluate($item);

        $this->assertSame('failed', $outcome->status);
        $this->assertSame('files_outside_task_contract', $outcome->reason);
        $this->assertSame(['app/Sneaky.php'], $outcome->payload['out_of_scope']);
    }

    public function test_blocks_file_in_forbidden_even_when_listed_in_allowed_via_glob(): void
    {
        $item = $this->makeWorkItem(
            tasks: [[
                'allowed_files' => ['app/**'],
                'forbidden_files' => ['app/Models/AtlasUser.php'],
            ]],
            evidence: [
                ['files' => ['app/Foo.php', 'app/Models/AtlasUser.php'], 'storage' => ['persisted' => true]],
            ],
        );
        $outcome = (new ProgrammingScopeGuardGate())->evaluate($item);

        $this->assertSame('failed', $outcome->status);
        $this->assertSame(['app/Models/AtlasUser.php'], $outcome->payload['forbidden_hits']);
    }

    public function test_directory_prefix_pattern_works(): void
    {
        $item = $this->makeWorkItem(
            tasks: [[
                'allowed_files' => ['app/Services/Ai/Programming/Governance/'],
                'forbidden_files' => [],
            ]],
            evidence: [
                [
                    'files' => [
                        'app/Services/Ai/Programming/Governance/Gates/ProgrammingScopeGuardGate.php',
                        'app/Services/Ai/Programming/Governance/ProgrammingScopeMode.php',
                    ],
                    'storage' => ['persisted' => true],
                ],
            ],
        );
        $outcome = (new ProgrammingScopeGuardGate())->evaluate($item);

        $this->assertSame('passed', $outcome->status);
    }

    /**
     * @param  list<array<string,mixed>>  $tasks
     * @param  list<array<string,mixed>>  $evidence
     */
    private function makeWorkItem(array $tasks, array $evidence): AtlasProgrammingWorkItem
    {
        return AtlasProgrammingWorkItem::query()->create([
            'code' => 'TST-'.bin2hex(random_bytes(4)),
            'intent_text' => 'scope guard test',
            'intent_type' => 'feature',
            'scope_mode' => 'structural',
            'risk_level' => 'medium',
            'status' => 'executing',
            'current_stage' => 'execution',
            'placement_json' => [],
            'code_intelligence_json' => [],
            'spec_json' => [],
            'plan_json' => [],
            'tasks_json' => $tasks,
            'evidence_refs_json' => $evidence,
            'gaps_json' => [],
            'metadata_json' => [],
        ]);
    }
}
