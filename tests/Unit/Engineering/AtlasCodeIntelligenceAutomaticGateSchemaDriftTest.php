<?php

declare(strict_types=1);

namespace Tests\Unit\Engineering;

use App\Services\Engineering\AtlasCodeIntelligenceAutomaticGateService;
use App\Services\Engineering\CodeIntelligenceSchemaDriftAuditor;
use App\Services\Engineering\EngineeringCodeIntelligenceService;
use Tests\TestCase;

final class AtlasCodeIntelligenceAutomaticGateSchemaDriftTest extends TestCase
{
    private function fakeCodeIntelligence(array $summary, array $readiness): EngineeringCodeIntelligenceService
    {
        return new class($summary, $readiness) extends EngineeringCodeIntelligenceService
        {
            public function __construct(
                private readonly array $summaryFixture,
                private readonly array $readinessFixture,
            ) {}

            public function summary(array $options = []): array
            {
                return $this->summaryFixture;
            }

            public function readiness(array $options = []): array
            {
                return $this->readinessFixture;
            }

            public function index(array $options = []): array
            {
                return ['ok' => true, 'summary' => $this->summaryFixture, 'performance' => ['status' => 'healthy']];
            }
        };
    }

    private function driftAuditor(array $tableFacts, array $columnFacts, array $migrationFacts): CodeIntelligenceSchemaDriftAuditor
    {
        return new class($tableFacts, $columnFacts, $migrationFacts) extends CodeIntelligenceSchemaDriftAuditor
        {
            public function __construct(
                private readonly array $tableFactsFixture,
                private readonly array $columnFactsFixture,
                private readonly array $migrationFactsFixture,
            ) {}

            public function audit(
                ?array $tableFacts = null,
                ?array $columnFacts = null,
                ?array $migrationFacts = null,
            ): array {
                return parent::audit(
                    $this->tableFactsFixture,
                    $this->columnFactsFixture,
                    $this->migrationFactsFixture,
                );
            }
        };
    }

    private function readySummary(int $symbols = 10, bool $tableExists = true): array
    {
        return [
            'status' => $tableExists ? 'ready' : 'tables_missing',
            'table_exists' => $tableExists,
            'module_count' => $tableExists ? 2 : 0,
            'symbol_count' => $tableExists ? $symbols : 0,
            'doc_link_count' => $tableExists ? 5 : 0,
            'route_count' => $tableExists ? 1 : 0,
            'command_count' => $tableExists ? 1 : 0,
            'migration_count' => $tableExists ? 1 : 0,
            'test_count' => $tableExists ? 1 : 0,
            'last_indexed_at' => now()->toJSON(),
        ];
    }

    private function readyReadiness(): array
    {
        return [
            'schema_version' => 'atlas.code_intelligence.readiness.v1',
            'status' => 'ready',
            'summary' => [
                'critical_failures' => 0,
                'warnings' => 0,
                'module_count' => 2,
                'symbol_count' => 10,
                'doc_link_count' => 5,
                'drift_total' => 0,
                'audit_duration_ms' => 123,
            ],
            'critical_failures' => [],
            'warnings' => [],
            'audit' => [],
            'duration_ms' => 10,
        ];
    }

    private function allTablesPresent(bool $value): array
    {
        return array_fill_keys([
            'atlas_engineering_code_modules',
            'atlas_engineering_code_symbols',
            'atlas_engineering_doc_links',
            'atlas_engineering_code_file_snapshots',
        ], $value);
    }

    private function allColumnsPresent(bool $value): array
    {
        $facts = [];
        foreach ($this->allTablesPresent(true) as $table => $_) {
            $facts[$table]['workspace_id'] = $value;
        }

        return $facts;
    }

    private function allMigrationsRan(bool $value): array
    {
        return array_fill_keys([
            '2026_05_02_010000_create_atlas_engineering_code_intelligence_tables',
            '2026_05_21_211200_create_atlas_engineering_code_file_snapshots_table',
            '2026_06_08_233000_add_workspace_id_to_code_intelligence_tables',
        ], $value);
    }

    public function test_missing_schema_without_stamped_migrations_blocks_with_tables_missing(): void
    {
        $auditor = $this->driftAuditor(
            tableFacts: $this->allTablesPresent(false),
            columnFacts: $this->allColumnsPresent(false),
            migrationFacts: $this->allMigrationsRan(false),
        );
        $gate = new AtlasCodeIntelligenceAutomaticGateService(
            $this->fakeCodeIntelligence($this->readySummary(tableExists: false), $this->readyReadiness()),
            $auditor,
        );

        $payload = $gate->evaluate(['strict_freshness' => false]);

        $this->assertSame('not_migrated', $payload['schema_drift']['status']);
        $this->assertContains('code_intelligence_tables_missing', $payload['blockers']);
        $this->assertNotContains('code_intelligence_schema_drift_migration_stamped_tables_missing', $payload['blockers']);
        $this->assertSame('run_migrations', $payload['repair_guidance']['recommended_actions'][0]['action']);
    }

    public function test_stamped_migrations_with_missing_tables_blocks_with_schema_drift(): void
    {
        $auditor = $this->driftAuditor(
            tableFacts: $this->allTablesPresent(false),
            columnFacts: $this->allColumnsPresent(false),
            migrationFacts: $this->allMigrationsRan(true),
        );
        $gate = new AtlasCodeIntelligenceAutomaticGateService(
            $this->fakeCodeIntelligence($this->readySummary(tableExists: false), $this->readyReadiness()),
            $auditor,
        );

        $payload = $gate->evaluate(['strict_freshness' => false]);

        $this->assertSame('schema_drift', $payload['schema_drift']['status']);
        $this->assertSame('stamped_but_tables_missing', $payload['schema_drift']['drift_type']);
        $this->assertContains('code_intelligence_schema_drift_migration_stamped_tables_missing', $payload['blockers']);
        $this->assertNotContains('code_intelligence_tables_missing', $payload['blockers']);

        $action = $payload['repair_guidance']['recommended_actions'][0];
        $this->assertSame('run_schema_drift_repair_migration', $action['action']);
        $this->assertSame(AtlasCodeIntelligenceAutomaticGateService::SCHEMA_DRIFT_REPAIR_MIGRATION, $action['migration_path']);
    }

    public function test_healthy_schema_with_empty_index_does_not_falsely_blame_schema(): void
    {
        $emptySummary = [
            'status' => 'empty',
            'table_exists' => true,
            'module_count' => 0,
            'symbol_count' => 0,
            'doc_link_count' => 0,
            'route_count' => 0,
            'command_count' => 0,
            'migration_count' => 0,
            'test_count' => 0,
            'last_indexed_at' => null,
        ];
        $auditor = $this->driftAuditor(
            tableFacts: $this->allTablesPresent(true),
            columnFacts: $this->allColumnsPresent(true),
            migrationFacts: $this->allMigrationsRan(true),
        );
        $gate = new AtlasCodeIntelligenceAutomaticGateService(
            $this->fakeCodeIntelligence($emptySummary, $this->readyReadiness()),
            $auditor,
        );

        $payload = $gate->evaluate(['strict_freshness' => false]);

        $this->assertSame('healthy', $payload['schema_drift']['status']);
        $this->assertNotContains('code_intelligence_tables_missing', $payload['blockers']);
        $this->assertNotContains('code_intelligence_schema_drift_migration_stamped_tables_missing', $payload['blockers']);
        $this->assertSame('refresh_code_intelligence_index', $payload['repair_guidance']['recommended_actions'][0]['action']);
    }

    public function test_healthy_ready_summary_reports_no_repair_needed_other_than_refresh(): void
    {
        $auditor = $this->driftAuditor(
            tableFacts: $this->allTablesPresent(true),
            columnFacts: $this->allColumnsPresent(true),
            migrationFacts: $this->allMigrationsRan(true),
        );
        $gate = new AtlasCodeIntelligenceAutomaticGateService(
            $this->fakeCodeIntelligence($this->readySummary(), $this->readyReadiness()),
            $auditor,
        );

        $payload = $gate->evaluate(['strict_freshness' => true]);

        $this->assertSame('ready', $payload['status']);
        $this->assertSame([], $payload['blockers']);
        $this->assertSame('healthy', $payload['schema_drift']['status']);
        $this->assertSame('refresh_code_intelligence_index', $payload['repair_guidance']['recommended_actions'][0]['action']);
    }
}
