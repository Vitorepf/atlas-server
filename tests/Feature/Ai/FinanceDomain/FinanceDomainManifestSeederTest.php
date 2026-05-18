<?php

namespace Tests\Feature\Ai\FinanceDomain;

use App\Services\Ai\DomainRuntime\DomainManifestRegistryService;
use App\Services\Ai\Finance\Kernel\FinanceDomainCanon;
use App\Services\Ai\Finance\Kernel\FinanceDomainManifestSeeder;
use Tests\Concerns\CreatesFinanceDomainTables;
use Tests\TestCase;

class FinanceDomainManifestSeederTest extends TestCase
{
    use CreatesFinanceDomainTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createFinanceDomainTables();
    }

    protected function tearDown(): void
    {
        $this->dropFinanceDomainTables();
        parent::tearDown();
    }

    public function test_seeder_registers_all_company_capabilities(): void
    {
        $manifest = app(FinanceDomainManifestSeeder::class)->seed();

        $this->assertSame('finance', $manifest->domain_id);
        $this->assertSame('active', $manifest->status);
        $capabilityIds = $manifest->capabilities()->orderBy('capability_id')->pluck('capability_id')->all();
        foreach (FinanceDomainCanon::CAPABILITIES as $expected) {
            $this->assertContains($expected, $capabilityIds, "missing capability [{$expected}]");
        }
    }

    public function test_seeder_extends_existing_manifest_without_throwing(): void
    {
        $registry = app(DomainManifestRegistryService::class);
        $registry->register([
            'domain_id' => 'finance',
            'name' => 'Pre-existing finance manifest (review-only)',
            'status' => 'active',
            'maturity_stage' => 2,
            'charter' => ['mission' => 'review only'],
            'ontology' => ['asset'],
            'departments' => ['research_desk'],
            'flow_profiles' => ['finance.research_note'],
            'tools_allowed' => ['docs.search'],
            'evidence_schema' => ['source_ref'],
            'quality_gates' => ['sources_attributed'],
            'handoff_rules' => ['allowed' => [], 'forbidden' => []],
            'delivery_types' => ['research_note'],
            'metrics' => ['source_diversity'],
            'forbidden_actions' => ['execute_live_trade'],
            'capabilities' => [
                [
                    'capability_id' => 'finance.research_note',
                    'name' => 'Pre-existing research_note',
                    'input_schema' => ['type' => 'object'],
                    'output_schema' => ['type' => 'object'],
                    'allowed_tools' => ['docs.search'],
                    'risk_level' => 'low',
                    'required_gates' => ['sources_attributed'],
                    'evidence_required' => ['source_ref'],
                    'maturity_level' => 2,
                ],
            ],
        ]);

        $manifest = app(FinanceDomainManifestSeeder::class)->seed();
        $capabilityIds = $manifest->capabilities()->pluck('capability_id')->all();

        $this->assertContains('finance.research_note', $capabilityIds, 'pre-existing capability preserved');
        foreach (FinanceDomainCanon::CAPABILITIES as $expected) {
            $this->assertContains($expected, $capabilityIds, "missing capability [{$expected}] after seed");
        }
    }

    public function test_seeder_is_idempotent(): void
    {
        $seeder = app(FinanceDomainManifestSeeder::class);
        $first = $seeder->seed();
        $second = $seeder->seed();

        $this->assertSame($first->id, $second->id);
        $this->assertSame(
            count(FinanceDomainCanon::CAPABILITIES),
            $second->capabilities()->count(),
        );
    }
}
