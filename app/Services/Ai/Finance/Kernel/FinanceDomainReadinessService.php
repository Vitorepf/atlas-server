<?php

namespace App\Services\Ai\Finance\Kernel;

use App\Models\AiDomainManifest;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Schema;

class FinanceDomainReadinessService
{
    private const REQUIRED_SERVICES = [
        FinanceDomainManifestSeeder::class,
        FinanceRuntimeService::class,
        FinanceResearchDeskService::class,
        FinanceValuationService::class,
        FinancePortfolioReviewService::class,
        FinanceRiskReviewService::class,
        FinanceComplianceService::class,
        FinanceReportingService::class,
        FinancePaperTradingSimulationService::class,
        FinanceControlPlaneProjection::class,
    ];

    private const REQUIRED_TEST_FILES = [
        'tests/Feature/Ai/FinanceDomain/FinanceDomainReadinessTest.php',
        'tests/Feature/Ai/FinanceDomain/FinanceDomainManifestSeederTest.php',
        'tests/Feature/Ai/FinanceDomain/FinanceDomainResearchDeskTest.php',
        'tests/Feature/Ai/FinanceDomain/FinanceDomainValuationTest.php',
        'tests/Feature/Ai/FinanceDomain/FinanceDomainPortfolioReviewTest.php',
        'tests/Feature/Ai/FinanceDomain/FinanceDomainRiskReviewTest.php',
        'tests/Feature/Ai/FinanceDomain/FinanceDomainComplianceTest.php',
        'tests/Feature/Ai/FinanceDomain/FinanceDomainPaperTradingSimulationTest.php',
        'tests/Feature/Ai/FinanceDomain/FinanceDomainLiveTradeBlockedTest.php',
        'tests/Feature/Ai/FinanceDomain/FinanceDomainControlPlaneTest.php',
        'tests/Feature/Ai/FinanceDomain/FinanceDomainSmokeTest.php',
    ];

    public function __construct(private readonly Container $container) {}

    /**
     * @return array<string,mixed>
     */
    public function report(): array
    {
        $checks = [];

        foreach (self::REQUIRED_SERVICES as $service) {
            $resolvable = false;
            $detail = 'not resolvable';
            try {
                $resolved = $this->container->make($service);
                $resolvable = $resolved instanceof $service;
                $detail = $resolvable ? 'resolved' : 'not an instance';
            } catch (\Throwable $e) {
                $detail = 'exception: '.$e->getMessage();
            }
            $checks[] = [
                'name' => 'service:'.class_basename($service),
                'status' => $resolvable ? 'passed' : 'failed',
                'detail' => $detail,
            ];
        }

        foreach (self::REQUIRED_TEST_FILES as $relativePath) {
            $exists = file_exists(base_path($relativePath));
            $checks[] = [
                'name' => 'test:'.basename($relativePath),
                'status' => $exists ? 'passed' : 'failed',
                'detail' => $exists ? 'present' : "missing [{$relativePath}]",
            ];
        }

        $checks[] = $this->checkUpstreamFoundation();
        $checks[] = $this->checkManifestSeeded();
        $checks[] = $this->checkLiveTradingBlocked();

        $passed = collect($checks)->where('status', 'passed')->count();
        $failed = collect($checks)->where('status', 'failed')->count();

        return [
            'ok' => $failed === 0,
            'schema' => FinanceDomainCanon::SCHEMA_READINESS,
            'summary' => [
                'total' => count($checks),
                'passed' => $passed,
                'failed' => $failed,
            ],
            'invariants' => [
                'live_trading_blocked_default' => FinanceDomainCanon::liveTradingBlocked(),
                'forbidden_actions_count' => count(FinanceDomainCanon::FORBIDDEN_ACTIONS),
            ],
            'checks' => $checks,
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkUpstreamFoundation(): array
    {
        $needed = ['ai_missions', 'ai_objectives', 'ai_work_orders', 'ai_mission_events', 'ai_domain_manifests', 'ai_domain_runtime_records'];
        $missing = [];
        foreach ($needed as $table) {
            if (! Schema::hasTable($table)) {
                $missing[] = $table;
            }
        }

        return [
            'name' => 'foundation:mission+domain_runtime',
            'status' => $missing === [] ? 'passed' : 'failed',
            'detail' => $missing === [] ? 'foundation tables present' : 'missing: '.implode(',', $missing),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkManifestSeeded(): array
    {
        $present = Schema::hasTable('ai_domain_manifests')
            && AiDomainManifest::query()->where('domain_id', FinanceDomainCanon::DOMAIN_ID)->exists();

        return [
            'name' => 'seed:finance_manifest',
            'status' => $present ? 'passed' : 'failed',
            'detail' => $present
                ? 'finance manifest registered'
                : 'run FinanceDomainManifestSeeder::seed() (atlas:ai:finance-domain --action=smoke does it automatically)',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkLiveTradingBlocked(): array
    {
        $blocked = FinanceDomainCanon::liveTradingBlocked();

        return [
            'name' => 'invariant:live_trading_blocked_default',
            'status' => $blocked ? 'passed' : 'failed',
            'detail' => $blocked
                ? 'live trading is blocked by default (config: atlas.finance.live_trading_allowed=false)'
                : 'CRITICAL: live trading is NOT blocked by default. Reset config atlas.finance.live_trading_allowed to false.',
        ];
    }
}
