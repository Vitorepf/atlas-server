<?php

declare(strict_types=1);

namespace Tests\Feature\Engineering;

use App\Services\Engineering\AtlasCodeIntelligenceAutomaticGateService;
use App\Services\Engineering\EngineeringCodeIntelligenceService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasCodeIntelligenceAutomaticGateServiceTest extends TestCase
{
    public function test_ready_index_passes_without_writes_or_provider_calls(): void
    {
        $service = new AtlasCodeIntelligenceAutomaticGateService($this->fakeCodeIntelligence(
            summary: $this->readySummary(),
            readiness: $this->readyReadiness(),
        ));

        $payload = $service->evaluate(['strict_freshness' => true]);

        $this->assertSame(AtlasCodeIntelligenceAutomaticGateService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame([], $payload['blockers']);
        $this->assertFalse($payload['writes']);
        $this->assertFalse(data_get($payload, 'claim_policy.provider_calls_made'));
        $this->assertTrue(data_get($payload, 'automatic_gate.blocks_dev_forge_when_blocked'));
        $this->assertSame('php artisan atlas:engineering:knowledge code-gate --auto-refresh --strict --json', data_get($payload, 'automatic_gate.auto_gate_command'));
        $this->assertSame(11, data_get($payload, 'metrics.consumer_count'));
        $this->assertSame(0, data_get($payload, 'metrics.consumer_missing_count'));
        $this->assertContains('avcel', collect($payload['consumers'])->pluck('id')->all());
        $this->assertNotEmpty($payload['gate_hash']);
    }

    public function test_auto_refresh_repairs_drift_and_rechecks_readiness(): void
    {
        $code = $this->fakeCodeIntelligence(
            summary: $this->readySummary(),
            readiness: $this->blockedReadiness(['audit_not_fresh', 'drift_detected']),
            refreshedSummary: $this->readySummary(symbols: 12),
            refreshedReadiness: $this->readyReadiness(symbols: 12),
        );
        $service = new AtlasCodeIntelligenceAutomaticGateService($code);

        $payload = $service->evaluate([
            'strict_freshness' => true,
            'auto_refresh' => true,
        ]);

        $this->assertSame('ready', $payload['status']);
        $this->assertTrue(data_get($payload, 'refresh.attempted'));
        $this->assertTrue(data_get($payload, 'refresh.succeeded'));
        $this->assertTrue($payload['writes']);
        $this->assertSame(0, data_get($payload, 'metrics.drift_total'));
        $this->assertSame([], $payload['blockers']);
    }

    public function test_tables_missing_blocks_and_does_not_claim_context_trust(): void
    {
        $service = new AtlasCodeIntelligenceAutomaticGateService($this->fakeCodeIntelligence(
            summary: [
                'status' => 'not_migrated',
                'table_exists' => false,
                'module_count' => 0,
                'symbol_count' => 0,
                'doc_link_count' => 0,
            ],
            readiness: $this->blockedReadiness(['tables_missing', 'index_not_ready']),
        ));

        $payload = $service->evaluate(['strict_freshness' => true, 'auto_refresh' => true]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('code_intelligence_tables_missing', $payload['blockers']);
        $this->assertFalse(data_get($payload, 'claim_policy.context_can_be_trusted_when_blocked'));
        $this->assertFalse(data_get($payload, 'refresh.succeeded'));
    }

    public function test_stale_index_blocks_when_strict_freshness_is_enabled(): void
    {
        $summary = $this->readySummary();
        $summary['last_indexed_at'] = '2026-05-01T00:00:00Z';
        $service = new AtlasCodeIntelligenceAutomaticGateService($this->fakeCodeIntelligence(
            summary: $summary,
            readiness: $this->readyReadiness(),
        ));

        $payload = $service->evaluate([
            'strict_freshness' => true,
            'max_age_minutes' => 60,
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('code_intelligence_index_stale_by_age', $payload['blockers']);
        $this->assertFalse(data_get($payload, 'claim_policy.stale_index_allowed'));
    }

    public function test_primary_workspace_warns_when_doc_links_are_empty(): void
    {
        $summary = $this->readySummary();
        $summary['doc_link_count'] = 0;

        $readiness = $this->readyReadiness();
        $readiness['summary']['doc_link_count'] = 0;

        $service = new AtlasCodeIntelligenceAutomaticGateService($this->fakeCodeIntelligence(
            summary: $summary,
            readiness: $readiness,
        ));

        $payload = $service->evaluate(['strict_freshness' => true]);

        $this->assertSame('watch', $payload['status']);
        $this->assertContains('doc_links_empty', $payload['warnings']);
    }

    public function test_non_primary_workspace_allows_empty_doc_links_when_readiness_is_fresh(): void
    {
        $summary = $this->readySummary();
        $summary['doc_link_count'] = 0;

        $readiness = $this->readyReadiness();
        $readiness['summary']['doc_link_count'] = 0;

        $service = new AtlasCodeIntelligenceAutomaticGateService($this->fakeCodeIntelligence(
            summary: $summary,
            readiness: $readiness,
        ));

        $payload = $service->evaluate([
            'workspace' => sys_get_temp_dir().'/atlas-umbrella-fixture',
            'strict_freshness' => true,
        ]);

        $this->assertSame('ready', $payload['status']);
        $this->assertNotContains('doc_links_empty', $payload['warnings']);
    }

    public function test_artisan_code_gate_emits_canonical_json(): void
    {
        $this->app->instance(EngineeringCodeIntelligenceService::class, $this->fakeCodeIntelligence(
            summary: $this->readySummary(),
            readiness: $this->readyReadiness(),
        ));

        $exit = Artisan::call('atlas:engineering:knowledge', [
            'action' => 'code-gate',
            '--json' => true,
            '--strict' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasCodeIntelligenceAutomaticGateService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
    }

    /**
     * @param  array<string,mixed>  $summary
     * @param  array<string,mixed>  $readiness
     * @param  array<string,mixed>|null  $refreshedSummary
     * @param  array<string,mixed>|null  $refreshedReadiness
     */
    private function fakeCodeIntelligence(
        array $summary,
        array $readiness,
        ?array $refreshedSummary = null,
        ?array $refreshedReadiness = null,
    ): EngineeringCodeIntelligenceService {
        return new class($summary, $readiness, $refreshedSummary, $refreshedReadiness) extends EngineeringCodeIntelligenceService
        {
            private bool $refreshed = false;

            public function __construct(
                private readonly array $summaryFixture,
                private readonly array $readinessFixture,
                private readonly ?array $refreshedSummaryFixture,
                private readonly ?array $refreshedReadinessFixture,
            ) {}

            public function summary(array $options = []): array
            {
                return $this->refreshed && $this->refreshedSummaryFixture !== null
                    ? $this->refreshedSummaryFixture
                    : $this->summaryFixture;
            }

            public function readiness(array $options = []): array
            {
                return $this->refreshed && $this->refreshedReadinessFixture !== null
                    ? $this->refreshedReadinessFixture
                    : $this->readinessFixture;
            }

            public function index(array $options = []): array
            {
                $this->refreshed = true;

                return [
                    'ok' => true,
                    'summary' => $this->summary(),
                    'performance' => [
                        'status' => 'healthy',
                        'cache' => ['hit_rate' => 1],
                    ],
                ];
            }
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function readySummary(int $symbols = 10): array
    {
        return [
            'status' => 'ready',
            'table_exists' => true,
            'module_count' => 2,
            'symbol_count' => $symbols,
            'doc_link_count' => 5,
            'route_count' => 1,
            'command_count' => 1,
            'migration_count' => 1,
            'test_count' => 1,
            'last_indexed_at' => now()->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function readyReadiness(int $symbols = 10): array
    {
        return [
            'schema_version' => 'atlas.code_intelligence.readiness.v1',
            'status' => 'ready',
            'summary' => [
                'critical_failures' => 0,
                'warnings' => 0,
                'module_count' => 2,
                'symbol_count' => $symbols,
                'doc_link_count' => 5,
                'drift_total' => 0,
                'audit_duration_ms' => 123,
            ],
            'critical_failures' => [],
            'warnings' => [],
            'audit' => [
                'status' => 'fresh',
                'performance' => [
                    'status' => 'healthy',
                    'cache' => ['hit_rate' => 1, 'hits' => 10, 'misses' => 0],
                ],
            ],
        ];
    }

    /**
     * @param  list<string>  $failures
     * @return array<string,mixed>
     */
    private function blockedReadiness(array $failures): array
    {
        return [
            'schema_version' => 'atlas.code_intelligence.readiness.v1',
            'status' => 'blocked',
            'summary' => [
                'critical_failures' => count($failures),
                'warnings' => 0,
                'module_count' => 2,
                'symbol_count' => 10,
                'doc_link_count' => 5,
                'drift_total' => in_array('drift_detected', $failures, true) ? 7 : null,
                'audit_duration_ms' => 123,
            ],
            'critical_failures' => $failures,
            'warnings' => [],
            'audit' => ['status' => 'drift_detected'],
        ];
    }
}
