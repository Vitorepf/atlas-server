<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\VentureFoundry\Comprehension;

use App\Models\AiVentureComprehensionFinding;
use App\Models\AiVentureComprehensionRun;
use App\Services\Ai\Strategy\StrategyCanonicalHash;
use App\Services\Ai\VentureFoundry\Comprehension\Capabilities\VentureAudienceUsageProfileService;
use App\Services\Ai\VentureFoundry\Comprehension\ComprehensionRecorder;
use App\Services\Ai\VentureFoundry\Comprehension\WorkspaceReader;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesVentureComprehensionTables;
use Tests\TestCase;

class VentureAudienceUsageProfileTest extends TestCase
{
    use CreatesVentureComprehensionTables;

    private string $ws;

    private AiVentureComprehensionRun $run;

    private VentureAudienceUsageProfileService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createVentureComprehensionTables();
        $this->ws = $this->makeFakeWorkspace();

        $this->run = AiVentureComprehensionRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'venture_id' => (string) Str::uuid(),
            'workspace_path' => $this->ws,
            'status' => 'running',
            'run_hash' => StrategyCanonicalHash::sha256(['x' => (string) Str::uuid()]),
        ]);

        $this->service = new VentureAudienceUsageProfileService(new ComprehensionRecorder);
    }

    protected function tearDown(): void
    {
        $this->removeFakeWorkspace($this->ws);
        $this->dropVentureComprehensionTables();
        parent::tearDown();
    }

    private function report(): array
    {
        return $this->service->scan($this->run, new WorkspaceReader($this->ws));
    }

    public function test_capability_id_is_audience_usage(): void
    {
        $this->assertSame(
            AiVentureComprehensionFinding::CAPABILITY_AUDIENCE_USAGE,
            $this->service->capability(),
        );
    }

    public function test_detects_three_plan_tiers_cited_to_config(): void
    {
        $report = $this->report();

        $names = array_map(static fn (array $t): string => $t['name'], $report['plan_tiers']);
        sort($names);
        $this->assertSame(['abissal', 'raso', 'recife'], $names);

        foreach ($report['plan_tiers'] as $tier) {
            $this->assertSame('config/plans.php', $tier['path'], 'plan tier must cite config/plans.php');
            $this->assertGreaterThan(0, $tier['line']);
        }

        // Persisted findings carry path + line evidence.
        $tierFindings = AiVentureComprehensionFinding::query()
            ->where('run_id', $this->run->id)
            ->where('kind', 'plan_tier')
            ->get();
        $this->assertCount(3, $tierFindings);
        foreach ($tierFindings as $f) {
            $this->assertSame('config/plans.php', $f->evidence_path);
            $this->assertNotNull($f->evidence_line);
            $this->assertSame('observed', $f->evidence_kind);
        }

        // Price payload is structured (raso = 149.90 BRL).
        $raso = collect($report['plan_tiers'])->firstWhere('name', 'raso');
        $this->assertSame(149.90, $raso['price']);
        $this->assertSame('BRL', $raso['currency']);
    }

    public function test_detects_three_locales(): void
    {
        $report = $this->report();

        $codes = array_map(static fn (array $l): string => $l['code'], $report['locales']);
        sort($codes);
        $this->assertSame(['en', 'es', 'pt-BR'], $codes);

        $localeFindings = AiVentureComprehensionFinding::query()
            ->where('run_id', $this->run->id)
            ->where('kind', 'locale')
            ->get();
        $this->assertCount(3, $localeFindings);
        foreach ($localeFindings as $f) {
            $this->assertStringContainsString('locales/', (string) $f->evidence_path);
            $this->assertNotNull($f->evidence_line);
        }
    }

    public function test_detects_stripe_and_cashier_integrations_from_composer(): void
    {
        $report = $this->report();

        $packages = array_map(static fn (array $i): string => $i['package'], $report['integrations']);
        $this->assertContains('stripe/stripe-php', $packages);
        $this->assertContains('laravel/cashier', $packages);
        $this->assertContains('react', $packages);
        $this->assertContains('i18next', $packages);

        $stripe = collect($report['integrations'])->firstWhere('package', 'stripe/stripe-php');
        $this->assertSame('composer.json', $stripe['path']);
        $this->assertNotNull($stripe['line'], 'integration must cite the manifest line');

        $integrationFindings = AiVentureComprehensionFinding::query()
            ->where('run_id', $this->run->id)
            ->where('kind', 'integration')
            ->get();
        $this->assertGreaterThanOrEqual(4, $integrationFindings->count());
        $cashier = $integrationFindings->firstWhere(
            fn (AiVentureComprehensionFinding $f): bool => ($f->payload['package'] ?? null) === 'laravel/cashier',
        );
        $this->assertNotNull($cashier);
        $this->assertSame('composer.json', $cashier->evidence_path);
    }

    public function test_detects_schema_entities_cited_to_migration(): void
    {
        $report = $this->report();

        $entities = array_map(static fn (array $e): string => $e['entity'], $report['schema_entities']);
        foreach (['campaigns', 'clicks', 'conversions'] as $expected) {
            $this->assertContains($expected, $entities, "schema entity {$expected} must be detected");
        }

        $entityFindings = AiVentureComprehensionFinding::query()
            ->where('run_id', $this->run->id)
            ->where('kind', 'schema_entity')
            ->get();
        foreach ($entityFindings as $f) {
            $this->assertStringContainsString('migrations/', (string) $f->evidence_path);
            $this->assertNotNull($f->evidence_line);
            $this->assertSame('observed', $f->evidence_kind);
        }
    }

    public function test_surface_size_counts_controllers(): void
    {
        $report = $this->report();

        $this->assertGreaterThanOrEqual(1, $report['surface_size']);

        $surfaceFinding = AiVentureComprehensionFinding::query()
            ->where('run_id', $this->run->id)
            ->where('kind', 'surface')
            ->first();
        $this->assertNotNull($surfaceFinding);
        $this->assertNotNull($surfaceFinding->evidence_path);
    }

    public function test_declares_honest_blind_spots_as_inferred(): void
    {
        $report = $this->report();

        $this->assertNotEmpty($report['blind_spots'], 'honest blind spots are required');

        $blindFindings = AiVentureComprehensionFinding::query()
            ->where('run_id', $this->run->id)
            ->where('kind', 'blind_spot')
            ->get();

        $this->assertGreaterThanOrEqual(1, $blindFindings->count());
        foreach ($blindFindings as $f) {
            $this->assertSame(
                AiVentureComprehensionFinding::EVIDENCE_INFERRED,
                $f->evidence_kind,
                'blind spots must be inferred, not observed',
            );
            $this->assertNull($f->evidence_path, 'blind spots have no code location');
            $this->assertNotNull($f->confidence);
            $this->assertLessThan(0.5, (float) $f->confidence, 'blind spots are low confidence');
            $this->assertNotNull($f->recommendation, 'blind spot must recommend wiring a data source');
        }

        $gaps = array_map(static fn (AiVentureComprehensionFinding $f): string => $f->payload['gap'] ?? '', $blindFindings->all());
        $this->assertContains('live_usage', $gaps);
        $this->assertContains('real_revenue', $gaps);
    }

    public function test_report_shape_is_complete(): void
    {
        $report = $this->report();

        foreach (['capability', 'plan_tiers', 'locales', 'integrations', 'schema_entities', 'surface_size', 'blind_spots'] as $key) {
            $this->assertArrayHasKey($key, $report);
        }
        $this->assertSame(AiVentureComprehensionFinding::CAPABILITY_AUDIENCE_USAGE, $report['capability']);
        $this->assertIsInt($report['surface_size']);
    }
}
