<?php

namespace Tests\Feature\ProgrammingGovernance;

use App\Models\AtlasProgrammingWorkItem;
use App\Services\Ai\Kernel\Architecture\AtlasFeaturePlacementService;
use App\Services\Engineering\EngineeringCodeIntelligenceService;
use App\Services\Engineering\EngineeringDocumentationHealthService;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CreatesAtlasProgrammingGovernanceTables;
use Tests\TestCase;

class VerifyCommandTest extends TestCase
{
    use CreatesAtlasProgrammingGovernanceTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAtlasProgrammingGovernanceTables();
        $this->bindStubServices();
    }

    protected function tearDown(): void
    {
        $this->dropAtlasProgrammingGovernanceTables();
        parent::tearDown();
    }

    public function test_verify_strict_fails_on_structural_without_spec(): void
    {
        $code = $this->intake('Refatorar harness para suportar Forge OS');

        $exit = Artisan::call('atlas:programming:verify', [
            'work_item' => $code,
            '--strict' => true,
            '--json' => true,
        ]);

        $this->assertSame(1, $exit, 'strict mode must fail when blocking gates are red');

        $payload = json_decode(Artisan::output(), true);
        $this->assertFalse($payload['gate_summary']['all_green']);
        $this->assertContains('spec-before-code', $payload['gate_summary']['blocking_failures']);
    }

    public function test_verify_without_strict_returns_zero_even_when_failing(): void
    {
        $code = $this->intake('Refatorar runner');

        $exit = Artisan::call('atlas:programming:verify', [
            'work_item' => $code,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
    }

    public function test_verify_runs_only_named_gates_when_requested(): void
    {
        $code = $this->intake('Refatorar runner');

        Artisan::call('atlas:programming:verify', [
            'work_item' => $code,
            '--gate' => ['evidence-required'],
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertSame(['evidence-required'], $payload['gate_summary']['gates_evaluated']);
    }

    public function test_verify_persists_gate_runs(): void
    {
        $code = $this->intake('Refatorar runner');

        Artisan::call('atlas:programming:verify', [
            'work_item' => $code,
            '--json' => true,
        ]);

        $item = AtlasProgrammingWorkItem::query()->firstOrFail();
        $this->assertGreaterThan(0, $item->gateRuns()->count());
    }

    private function intake(string $intent): string
    {
        Artisan::call('atlas:programming:intake', [
            'intent' => $intent,
            '--json' => true,
        ]);
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
                    return [
                        'status' => 'ok',
                        'placement' => ['layer' => 'kernel', 'domain' => 'programming'],
                        'owner_docs' => [],
                    ];
                }
            };
        });

        $this->app->bind(EngineeringCodeIntelligenceService::class, function (): EngineeringCodeIntelligenceService {
            return new class extends EngineeringCodeIntelligenceService
            {
                public function __construct() {}

                public function summary(array $options = []): array
                {
                    // Mirror the production EngineeringCodeIntelligenceService::summary() shape.
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
