<?php

namespace Tests\Feature\ProgrammingGovernance;

use App\Models\AtlasEngineeringEvidence;
use App\Models\AtlasProgrammingGateRun;
use App\Models\AtlasProgrammingReview;
use App\Models\AtlasProgrammingWorkItem;
use App\Services\Ai\Kernel\Architecture\AtlasFeaturePlacementService;
use App\Services\Engineering\EngineeringCodeIntelligenceService;
use App\Services\Engineering\EngineeringDocumentationHealthService;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CreatesAtlasProgrammingGovernanceTables;
use Tests\TestCase;

/**
 * Smoke covering the canonical lifecycle from intake to closeout.
 * Mirrors the Definition Of Done in the runbook ("Implementacao Atual").
 */
class EndToEndFlowTest extends TestCase
{
    use CreatesAtlasProgrammingGovernanceTables;

    private string $workspace = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAtlasProgrammingGovernanceTables();
        $this->workspace = $this->makeProgrammingWorkspace([
            'app/Services/Ai/Programming/Governance/ProgrammingGateRunner.php',
        ]);
        $this->bindStubServices();
    }

    protected function tearDown(): void
    {
        $this->cleanupProgrammingWorkspaces();
        $this->dropAtlasProgrammingGovernanceTables();
        parent::tearDown();
    }

    public function test_full_lifecycle_from_intake_to_closeout(): void
    {
        // 1. Intake
        $exit = Artisan::call('atlas:programming:intake', [
            'intent' => 'Refatorar runner para suportar Forge OS sem regressao',
            '--owner' => 'vitor',
            '--workspace' => $this->workspace,
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);
        $intake = json_decode(Artisan::output(), true);
        $code = (string) $intake['code'];
        $this->assertSame('refactor', $intake['intent_type']);
        $this->assertSame('structural', $intake['scope_mode']);
        $this->assertSame('spec_required', $intake['status']);

        // 2. Verify (strict) before spec → fails on spec-before-code
        $exit = Artisan::call('atlas:programming:verify', [
            'work_item' => $code,
            '--strict' => true,
            '--json' => true,
        ]);
        $this->assertSame(1, $exit, 'verify must fail before spec exists');
        $verifyPayload = json_decode(Artisan::output(), true);
        $this->assertContains('spec-before-code', $verifyPayload['gate_summary']['blocking_failures']);

        // 3. Attach spec
        $exit = Artisan::call('atlas:programming:spec', [
            'work_item' => $code,
            '--objective' => 'Reuse runner across Forge OS work packets',
            '--context' => 'Forge OS depends on packet-aware runner',
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

        // 4. Plan + task contract
        $exit = Artisan::call('atlas:programming:plan', [
            'work_item' => $code,
            '--allowed-files' => ['app/Services/Ai/Programming/Governance/ProgrammingGateRunner.php'],
            '--forbidden-files' => ['app/Models/AtlasUser.php'],
            '--validation-command' => ['vendor/bin/phpunit tests/Feature/ProgrammingGovernance'],
            '--acceptance' => ['feature complete', 'all gates green'],
            '--cartography-required' => true,
            '--rollback' => 'git revert',
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);

        // 5. Reject narrative-only receipt
        $exit = Artisan::call('atlas:programming:receipt', [
            'work_item' => $code,
            '--summary' => 'tudo certo, eu juro',
            '--json' => true,
        ]);
        $this->assertSame(1, $exit, 'narrative-only receipt must be rejected');

        // 6. Append a real receipt
        $exit = Artisan::call('atlas:programming:receipt', [
            'work_item' => $code,
            '--command' => 'vendor/bin/phpunit tests/Feature/ProgrammingGovernance',
            '--output' => 'OK (49 tests, 100 assertions)',
            '--file' => ['app/Services/Ai/Programming/Governance/ProgrammingGateRunner.php'],
            '--test' => ['Tests\\Feature\\ProgrammingGovernance\\EndToEndFlowTest'],
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);

        // 7. Verify before review → fails on completion (no review yet)
        $exit = Artisan::call('atlas:programming:verify', [
            'work_item' => $code,
            '--strict' => true,
            '--json' => true,
        ]);
        $this->assertSame(1, $exit, 'verify must still fail without an approved review');

        // 8. Complete with approved review
        $exit = Artisan::call('atlas:programming:complete', [
            'work_item' => $code,
            '--review' => 'approved',
            '--summary' => 'All gates green, ready to ship',
            '--decided-by' => 'vitor',
            '--strict' => true,
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);

        $item = AtlasProgrammingWorkItem::query()->where('code', $code)->firstOrFail();
        $this->assertSame('closed', $item->status);
        $this->assertNotNull($item->closed_at);

        // 9. Status snapshot has full timeline
        Artisan::call('atlas:programming:status', ['work_item' => $code, '--json' => true]);
        $status = json_decode(Artisan::output(), true);
        $this->assertSame('closed', $status['status']);
        $gateNames = array_map(static fn (array $r): string => $r['gate_name'], $status['gate_runs']);
        $this->assertContains('spec-before-code', $gateNames);
        $this->assertContains('evidence-required', $gateNames);
        $this->assertContains('completion', $gateNames);
        $this->assertSame('approved', $status['reviews'][0]['result']);

        // 10. Persisted side effects in shared engineering_evidence
        $this->assertSame(1, AtlasEngineeringEvidence::query()->count());
        $this->assertGreaterThan(0, AtlasProgrammingGateRun::query()->count());
        $this->assertSame(1, AtlasProgrammingReview::query()->count());

        // 11. Cartography gap recorded
        $gapNames = array_map(static fn (array $g): string => $g['name'], $item->gaps_json);
        $this->assertContains('cartography_publishing_required', $gapNames);
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
