<?php

namespace Tests\Feature\ProgrammingGovernance;

use App\Models\AtlasProgrammingGateRun;
use App\Models\AtlasProgrammingWorkItem;
use App\Services\Ai\Kernel\Architecture\AtlasFeaturePlacementService;
use App\Services\Engineering\EngineeringCodeIntelligenceService;
use App\Services\Engineering\EngineeringDocumentationHealthService;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CreatesAtlasProgrammingGovernanceTables;
use Tests\TestCase;

class HierarchicalControlLoopTest extends TestCase
{
    use CreatesAtlasProgrammingGovernanceTables;

    private string $workspace = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAtlasProgrammingGovernanceTables();
        $this->workspace = $this->makeProgrammingWorkspace([
            'app/Services/Ai/Programming/Governance/ProgrammingHierarchicalControlLoopService.php',
        ]);
        $this->bindStubServices();
    }

    protected function tearDown(): void
    {
        $this->cleanupProgrammingWorkspaces();
        $this->dropAtlasProgrammingGovernanceTables();
        parent::tearDown();
    }

    public function test_structural_work_item_without_spec_replans(): void
    {
        $code = $this->intakeStructuralWorkItem('Refatorar runtime de programação com controle hierárquico');

        $exit = Artisan::call('atlas:programming:hierarchical-control', [
            'work_item' => $code,
            '--strict' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exit);
        $this->assertSame('replan', data_get($payload, 'halt_decision.action'));
        $this->assertSame('h_level_spec_missing', data_get($payload, 'halt_decision.reason'));
        $this->assertSame(true, data_get($payload, 'halt_decision.h_cycle_required'));
    }

    public function test_ready_work_item_passes_hierarchical_control_before_completion(): void
    {
        $code = $this->prepareReadyWorkItem();

        $exit = Artisan::call('atlas:programming:complete', [
            'work_item' => $code,
            '--review' => 'approved',
            '--summary' => 'Contrato H/L convergiu; pronto para fechar',
            '--decided-by' => 'vitor',
            '--strict' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertSame(['hierarchical-control', 'completion'], data_get($payload, 'gate_summary.gates_evaluated'));

        $item = AtlasProgrammingWorkItem::query()->where('code', $code)->firstOrFail();
        $this->assertSame('closed', $item->status);

        $gate = AtlasProgrammingGateRun::query()
            ->where('work_item_id', $item->id)
            ->where('gate_name', 'hierarchical-control')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->firstOrFail();

        $this->assertSame('passed', $gate->status);
        $this->assertSame('submit', data_get($gate->payload_json, 'halt_decision.action'));
        $this->assertSame(9.5, data_get($gate->payload_json, 'readiness_score.score'));

        $exit = Artisan::call('atlas:programming:hierarchical-control', [
            'work_item' => $code,
            '--strict' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertSame('submit', data_get($payload, 'halt_decision.action'));
    }

    public function test_changes_requested_review_returns_repair_decision(): void
    {
        $code = $this->prepareReadyWorkItem();

        $exit = Artisan::call('atlas:programming:complete', [
            'work_item' => $code,
            '--review' => 'changes_requested',
            '--summary' => 'Ajustar evidence e refazer verificacao',
            '--decided-by' => 'qa',
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);

        $exit = Artisan::call('atlas:programming:hierarchical-control', [
            'work_item' => $code,
            '--strict' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exit);
        $this->assertSame('repair', data_get($payload, 'halt_decision.action'));
        $this->assertSame('h_level_review_requested_changes', data_get($payload, 'halt_decision.reason'));
        $this->assertSame(true, data_get($payload, 'halt_decision.l_cycle_required'));
    }

    private function prepareReadyWorkItem(): string
    {
        $code = $this->intakeStructuralWorkItem('Refatorar runtime de programação com controle hierárquico');

        $exit = Artisan::call('atlas:programming:spec', [
            'work_item' => $code,
            '--objective' => 'Criar AHCL como gate governado do Programming Governance',
            '--context' => 'Sessões longas precisam de decisão H/L antes de completion',
            '--expected-behavior' => 'Gate hierárquico decide submit somente quando spec, plan, gates, evidence e review convergem',
            '--likely-file' => ['app/Services/Ai/Programming/Governance/ProgrammingHierarchicalControlLoopService.php'],
            '--risk' => ['Completion pode fechar cedo demais se o gate não existir'],
            '--test' => ['php artisan test tests/Feature/ProgrammingGovernance/HierarchicalControlLoopTest.php'],
            '--evidence-required' => ['ahcl_gate_passed', 'phpunit_green'],
            '--rollback' => 'Remover gate e restaurar required gates anteriores',
            '--completion-criterion' => ['AHCL submit antes de completion'],
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);

        $exit = Artisan::call('atlas:programming:plan', [
            'work_item' => $code,
            '--allowed-files' => ['app/Services/Ai/Programming/Governance/ProgrammingHierarchicalControlLoopService.php'],
            '--validation-command' => ['php artisan test tests/Feature/ProgrammingGovernance/HierarchicalControlLoopTest.php'],
            '--acceptance' => ['AHCL submit ready', 'Completion only after AHCL pass'],
            '--cartography-required' => true,
            '--rollback' => 'git revert',
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);

        $exit = Artisan::call('atlas:programming:receipt', [
            'work_item' => $code,
            '--command' => 'php artisan test tests/Feature/ProgrammingGovernance/HierarchicalControlLoopTest.php',
            '--output' => 'PASS  Tests\\Feature\\ProgrammingGovernance\\HierarchicalControlLoopTest',
            '--file' => ['app/Services/Ai/Programming/Governance/ProgrammingHierarchicalControlLoopService.php'],
            '--test' => ['Tests\\Feature\\ProgrammingGovernance\\HierarchicalControlLoopTest'],
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);

        $exit = Artisan::call('atlas:programming:verify', [
            'work_item' => $code,
            '--strict' => true,
            '--json' => true,
        ]);
        $this->assertSame(1, $exit, 'AHCL must block verify until review exists.');

        $payload = json_decode(Artisan::output(), true);
        $this->assertContains('hierarchical-control', data_get($payload, 'gate_summary.blocking_failures'));

        return $code;
    }

    private function intakeStructuralWorkItem(string $intent): string
    {
        $exit = Artisan::call('atlas:programming:intake', [
            'intent' => $intent,
            '--owner' => 'vitor',
            '--workspace' => $this->workspace,
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);

        $payload = json_decode(Artisan::output(), true);

        return (string) $payload['code'];
    }

    private function bindStubServices(): void
    {
        $this->app->bind(AtlasFeaturePlacementService::class, function (): AtlasFeaturePlacementService {
            return new class extends AtlasFeaturePlacementService
            {
                public function __construct() {}

                public function place(string $intent, array $hints = []): array
                {
                    return ['status' => 'ok', 'placement' => ['layer' => 'domain', 'domain' => 'programming'], 'owner_docs' => []];
                }
            };
        });

        $this->app->bind(EngineeringCodeIntelligenceService::class, function (): EngineeringCodeIntelligenceService {
            return new class extends EngineeringCodeIntelligenceService
            {
                public function __construct() {}

                public function summary(): array
                {
                    return [
                        'status' => 'ready',
                        'table_exists' => true,
                        'module_count' => 23,
                        'symbol_count' => 39419,
                        'doc_link_count' => 61791,
                    ];
                }
            };
        });

        $this->app->bind(EngineeringDocumentationHealthService::class, function (): EngineeringDocumentationHealthService {
            return new class extends EngineeringDocumentationHealthService
            {
                public function __construct() {}

                public function report(): array
                {
                    return ['status' => 'ok', 'required_missing' => [], 'oversized' => []];
                }
            };
        });
    }
}
