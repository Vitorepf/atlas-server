<?php

namespace Tests\Feature\ProgrammingGovernance;

use App\Models\AtlasProgrammingWorkItem;
use App\Services\Ai\Programming\Governance\ProgrammingGovernanceService;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CreatesAtlasProgrammingGovernanceTables;
use Tests\TestCase;

class SpecAndPlanCommandsTest extends TestCase
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

    public function test_spec_command_inline_options_persist_hash(): void
    {
        $code = $this->intake('Refatorar runner para suportar Forge');

        $exit = Artisan::call('atlas:programming:spec', [
            'work_item' => $code,
            '--objective' => 'Reuse runner across Forge OS work packets',
            '--context' => 'Forge OS depends on a packet-aware runner',
            '--expected-behavior' => 'Runner exposes packet hooks',
            '--likely-file' => ['app/Services/Ai/Programming/Governance/ProgrammingGateRunner.php'],
            '--risk' => ['Possible regression in existing programming flows'],
            '--test' => ['vendor/bin/phpunit tests/Feature/ProgrammingGovernance'],
            '--evidence-required' => ['phpunit_green', 'docs_health_ok'],
            '--rollback' => 'Revert commit and rerun tests',
            '--completion-criterion' => ['All gates green', 'Docs synced'],
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true);
        $this->assertNotNull($payload['spec_hash']);
        $this->assertSame('plan_required', $payload['status']);
    }

    public function test_spec_rejects_retroactive_attempt_after_execution(): void
    {
        $code = $this->intake('Refatorar runner para suportar Forge');

        Artisan::call('atlas:programming:spec', [
            'work_item' => $code,
            '--objective' => 'x', '--context' => 'x', '--expected-behavior' => 'x',
            '--likely-file' => ['x'], '--risk' => ['x'], '--test' => ['x'],
            '--evidence-required' => ['x'], '--rollback' => 'x',
            '--completion-criterion' => ['x'],
            '--json' => true,
        ]);

        Artisan::call('atlas:programming:plan', [
            'work_item' => $code,
            '--allowed-files' => ['app/x.php'],
            '--validation-command' => ['phpunit'],
            '--acceptance' => ['ok'],
            '--json' => true,
        ]);

        $exit = Artisan::call('atlas:programming:spec', [
            'work_item' => $code,
            '--objective' => 'overwrite',
            '--json' => true,
        ]);

        $this->assertSame(1, $exit, 'spec must be rejected after execution started');
    }

    public function test_plan_persists_task_contract_with_allowed_files(): void
    {
        $code = $this->intake('Refatorar runner');

        Artisan::call('atlas:programming:spec', [
            'work_item' => $code,
            '--objective' => 'x', '--context' => 'x', '--expected-behavior' => 'x',
            '--likely-file' => ['x'], '--risk' => ['x'], '--test' => ['x'],
            '--evidence-required' => ['x'], '--rollback' => 'x',
            '--completion-criterion' => ['x'],
            '--json' => true,
        ]);

        Artisan::call('atlas:programming:plan', [
            'work_item' => $code,
            '--allowed-files' => ['app/Services/Ai/Programming/Governance/ProgrammingGateRunner.php'],
            '--forbidden-files' => ['app/Models/AtlasUser.php'],
            '--validation-command' => ['vendor/bin/phpunit tests/Feature/ProgrammingGovernance'],
            '--acceptance' => ['feature complete', 'tests green'],
            '--cartography-required' => true,
            '--json' => true,
        ]);

        $item = AtlasProgrammingWorkItem::query()->firstOrFail();
        $this->assertCount(1, $item->tasks_json);
        $this->assertSame(['app/Services/Ai/Programming/Governance/ProgrammingGateRunner.php'], $item->tasks_json[0]['allowed_files']);
        $this->assertTrue($item->tasks_json[0]['cartography_required']);
        $this->assertSame('executing', $item->status);
    }

    public function test_plan_blocks_structural_without_spec(): void
    {
        /** @var ProgrammingGovernanceService $governance */
        $governance = app(ProgrammingGovernanceService::class);
        $intake = $governance->intake('Refatorar runner', []);
        $code = (string) $intake['code'];

        $exit = Artisan::call('atlas:programming:plan', [
            'work_item' => $code,
            '--allowed-files' => ['app/x.php'],
            '--validation-command' => ['phpunit'],
            '--json' => true,
        ]);

        $this->assertSame(1, $exit);
    }

    private function intake(string $intent): string
    {
        Artisan::call('atlas:programming:intake', ['intent' => $intent, '--json' => true]);

        return (string) json_decode(Artisan::output(), true)['code'];
    }
}
