<?php

namespace Tests\Feature\ProgrammingGovernance;

use App\Models\AtlasProgrammingWorkItem;
use App\Services\Ai\Kernel\Architecture\AtlasFeaturePlacementService;
use App\Services\Engineering\EngineeringCodeIntelligenceService;
use App\Services\Engineering\EngineeringDocumentationHealthService;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CreatesAtlasProgrammingGovernanceTables;
use Tests\TestCase;

class CompletionGateTest extends TestCase
{
    use CreatesAtlasProgrammingGovernanceTables;

    private string $workspace = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAtlasProgrammingGovernanceTables();
        $this->workspace = $this->makeProgrammingWorkspace(['app/x.php']);
        $this->bindStubServices();
    }

    protected function tearDown(): void
    {
        $this->cleanupProgrammingWorkspaces();
        $this->dropAtlasProgrammingGovernanceTables();
        parent::tearDown();
    }

    public function test_completion_blocked_without_evidence_or_review(): void
    {
        $code = $this->intake('Refatorar runner para suportar Forge');
        $this->attachSpec($code);
        $this->attachPlan($code);

        $exit = Artisan::call('atlas:programming:complete', [
            'work_item' => $code,
            '--review' => 'approved',
            '--strict' => true,
            '--json' => true,
        ]);

        $this->assertSame(1, $exit, 'completion must fail when there is no evidence');

        $item = AtlasProgrammingWorkItem::query()->firstOrFail();
        $this->assertNotSame('closed', $item->status);
    }

    public function test_completion_blocked_when_review_not_approved(): void
    {
        $code = $this->fullyPreparedItem();

        $exit = Artisan::call('atlas:programming:complete', [
            'work_item' => $code,
            '--review' => 'changes_requested',
            '--strict' => true,
            '--json' => true,
        ]);

        $this->assertSame(1, $exit);
        $payload = json_decode(Artisan::output(), true);
        $this->assertSame('hierarchical-control', data_get($payload, 'gate_summary.gate_runs.0.gate_name'));
        $this->assertSame('completion', data_get($payload, 'gate_summary.gate_runs.1.gate_name'));
        $this->assertSame('completion_blocked_review_not_approved', data_get($payload, 'gate_summary.gate_runs.1.reason'));
    }

    public function test_completion_passes_with_evidence_and_approved_review_and_all_gates_green(): void
    {
        $code = $this->fullyPreparedItem();

        Artisan::call('atlas:programming:verify', [
            'work_item' => $code,
            '--strict' => true,
            '--json' => true,
        ]);

        $exit = Artisan::call('atlas:programming:complete', [
            'work_item' => $code,
            '--review' => 'approved',
            '--summary' => 'All gates green, ready to ship',
            '--decided-by' => 'vitor',
            '--strict' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);

        $item = AtlasProgrammingWorkItem::query()->firstOrFail();
        $this->assertSame('closed', $item->status);
        $this->assertNotNull($item->closed_at);
        $this->assertSame(1, $item->reviews()->count());
    }

    public function test_status_command_returns_full_timeline(): void
    {
        $code = $this->fullyPreparedItem();
        Artisan::call('atlas:programming:verify', ['work_item' => $code, '--strict' => true, '--json' => true]);
        Artisan::call('atlas:programming:complete', ['work_item' => $code, '--review' => 'approved', '--decided-by' => 'vitor', '--strict' => true, '--json' => true]);

        Artisan::call('atlas:programming:status', ['work_item' => $code, '--json' => true]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame('closed', $payload['status']);
        $this->assertNotEmpty($payload['gate_runs']);
        $this->assertSame('approved', $payload['reviews'][0]['result']);
    }

    private function fullyPreparedItem(): string
    {
        $code = $this->intake('Refatorar runner para suportar Forge');
        $this->attachSpec($code);
        $this->attachPlan($code);
        $this->attachReceipt($code);

        return $code;
    }

    private function intake(string $intent): string
    {
        Artisan::call('atlas:programming:intake', [
            'intent' => $intent,
            '--workspace' => $this->workspace,
            '--json' => true,
        ]);

        return (string) json_decode(Artisan::output(), true)['code'];
    }

    private function attachSpec(string $code): void
    {
        Artisan::call('atlas:programming:spec', [
            'work_item' => $code,
            '--objective' => 'x', '--context' => 'x', '--expected-behavior' => 'x',
            '--likely-file' => ['x'], '--risk' => ['x'], '--test' => ['x'],
            '--evidence-required' => ['x'], '--rollback' => 'x',
            '--completion-criterion' => ['x'],
            '--json' => true,
        ]);
    }

    private function attachPlan(string $code): void
    {
        Artisan::call('atlas:programming:plan', [
            'work_item' => $code,
            '--allowed-files' => ['app/x.php'],
            '--validation-command' => ['phpunit'],
            '--acceptance' => ['ok'],
            '--json' => true,
        ]);
    }

    private function attachReceipt(string $code): void
    {
        Artisan::call('atlas:programming:receipt', [
            'work_item' => $code,
            '--command' => 'vendor/bin/phpunit',
            '--output' => 'OK',
            '--file' => ['app/x.php'],
            '--json' => true,
        ]);
    }

    private function bindStubServices(): void
    {
        $this->app->bind(AtlasFeaturePlacementService::class, function (): AtlasFeaturePlacementService {
            return new class extends AtlasFeaturePlacementService
            {
                public function __construct() {}

                public function place(string $intent, array $hints = []): array
                {
                    return ['status' => 'ok', 'placement' => ['layer' => 'kernel', 'domain' => 'programming'], 'owner_docs' => []];
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
