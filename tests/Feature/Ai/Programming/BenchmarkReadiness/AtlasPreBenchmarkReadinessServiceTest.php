<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\BenchmarkReadiness;

use App\Services\Ai\LongHorizon\AtlasTeosFinalCertificationService;
use App\Services\Ai\Product\AtlasAiProductCertificationService;
use App\Services\Ai\Programming\BenchmarkReadiness\AtlasPreBenchmarkReadinessService;
use App\Services\Ai\Programming\BenchmarkReadiness\BenchmarkReadinessCanon;
use App\Services\Ai\Programming\BenchmarkReadiness\BenchmarkReadinessHarness;
use App\Services\Ai\Programming\ProgrammingRivalsReadinessService;
use App\Services\Ai\RuntimeReleaseGate\AtlasAiRuntimeReleaseGateService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AtlasPreBenchmarkReadinessServiceTest extends TestCase
{
    public function test_ready_accepts_only_control_plane_runtime_warning_from_release_gate(): void
    {
        $report = $this->service(
            runtime: $this->runtime(
                status: AtlasAiRuntimeReleaseGateService::STATUS_PARTIAL,
                warnings: ['control_plane_runtime'],
            ),
        )->report();

        $this->assertSame(AtlasPreBenchmarkReadinessService::STATUS_READY, $report['status']);
        $this->assertSame(['control_plane_runtime'], $report['accepted_warnings']);
        $this->assertSame([], $report['blockers']);
        $this->assertTrue($report['claim_policy']['benchmark_not_run']);
        $this->assertFalse($report['claim_policy']['rivals_compared']);
        $this->assertFalse($report['claim_policy']['provider_calls_made']);
        $this->assertFalse($report['claim_policy']['allows_external_superiority_claim']);
    }

    public function test_blocks_unaccepted_runtime_warning(): void
    {
        $report = $this->service(
            runtime: $this->runtime(
                status: AtlasAiRuntimeReleaseGateService::STATUS_PARTIAL,
                warnings: ['desktop_hyperflow_integration'],
            ),
        )->report();

        $this->assertSame(AtlasPreBenchmarkReadinessService::STATUS_BLOCKED, $report['status']);
        $this->assertSame('runtime_release_gate', $report['blockers'][0]['id']);
        $this->assertSame(['desktop_hyperflow_integration'], $report['blockers'][0]['evidence']['unaccepted_warnings']);
    }

    public function test_blocks_runtime_blockers_even_if_warning_is_accepted(): void
    {
        $report = $this->service(
            runtime: $this->runtime(
                status: AtlasAiRuntimeReleaseGateService::STATUS_PARTIAL,
                blockers: ['router_runtime_readiness'],
                warnings: ['control_plane_runtime'],
            ),
        )->report();

        $this->assertSame(AtlasPreBenchmarkReadinessService::STATUS_BLOCKED, $report['status']);
        $this->assertSame(['router_runtime_readiness'], $report['blockers'][0]['evidence']['blockers']);
    }

    public function test_blocks_if_benchmark_suite_is_not_not_run(): void
    {
        $report = $this->service(
            benchmark: $this->benchmark([
                'benchmark_status' => 'executed',
                'claim_policy' => [
                    'benchmark_not_run' => false,
                    'rivals_compared' => true,
                    'rival_provider_invoked' => true,
                    'allows_external_superiority_claim' => true,
                ],
            ]),
        )->report();

        $this->assertSame(AtlasPreBenchmarkReadinessService::STATUS_BLOCKED, $report['status']);
        $this->assertSame('benchmark_suite_readiness', $report['blockers'][0]['id']);
        $this->assertSame('executed', $report['blockers'][0]['evidence']['benchmark_status']);
        $this->assertTrue($report['claim_policy']['benchmark_not_run']);
        $this->assertFalse($report['claim_policy']['allows_external_superiority_claim']);
    }

    public function test_blocks_when_rivals_execution_preflight_workspace_is_not_clean(): void
    {
        $report = $this->service(
            rivals: $this->rivals(workspaceReady: false, blockingReasons: ['current_workspace_not_provider_battery_ready']),
        )->report();

        $this->assertSame(AtlasPreBenchmarkReadinessService::STATUS_BLOCKED, $report['status']);
        $this->assertSame('rivals_execution_preflight', $report['blockers'][0]['id']);
        $this->assertFalse($report['blockers'][0]['evidence']['current_workspace_ready_for_provider_battery']);
        $this->assertSame(
            ['current_workspace_not_provider_battery_ready'],
            $report['blockers'][0]['evidence']['blocking_reasons'],
        );
        $this->assertFalse($report['claim_policy']['ready_to_request_human_benchmark_authorization']);
        $this->assertSame(
            'resolve_pre_benchmark_blockers_before_authorization',
            $report['required_operator_action']['next_action'],
        );
    }

    public function test_hash_is_deterministic_and_required_operator_action_is_explicit(): void
    {
        $service = $this->service();

        $first = $service->report();
        $second = $service->report();

        $this->assertSame($first['readiness_hash'], $second['readiness_hash']);
        $this->assertSame(
            'authorize_benchmark_in_separate_command',
            $first['required_operator_action']['next_action'],
        );
        $this->assertTrue($first['required_operator_action']['benchmark_authorization_required']);
        $this->assertTrue($first['required_operator_action']['do_not_run_benchmark_from_this_gate']);
        $this->assertFalse($first['writes']);
    }

    public function test_command_emits_json_and_strict_succeeds_only_when_ready(): void
    {
        $this->app->bind(
            AtlasPreBenchmarkReadinessService::class,
            fn (): FakePreBenchmarkReadinessService => new FakePreBenchmarkReadinessService([
                'schema_version' => AtlasPreBenchmarkReadinessService::SCHEMA_VERSION,
                'status' => AtlasPreBenchmarkReadinessService::STATUS_READY,
                'summary' => ['total' => 5, 'pass' => 5, 'warn' => 0, 'fail' => 0],
                'readiness_hash' => str_repeat('a', 64),
            ]),
        );

        $exitCode = Artisan::call('atlas:programming:pre-benchmark-readiness', [
            '--json' => true,
            '--strict' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(AtlasPreBenchmarkReadinessService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame(AtlasPreBenchmarkReadinessService::STATUS_READY, $payload['status']);
    }

    private function service(
        ?FakeProductCertificationService $product = null,
        ?FakeRuntimeReleaseGateService $runtime = null,
        ?FakeTeosFinalCertificationService $teos = null,
        ?FakeBenchmarkReadinessHarness $benchmark = null,
        ?FakeProgrammingRivalsReadinessService $rivals = null,
    ): AtlasPreBenchmarkReadinessService {
        return new AtlasPreBenchmarkReadinessService(
            $product ?? $this->product(),
            $runtime ?? $this->runtime(),
            $teos ?? $this->teos(),
            $benchmark ?? $this->benchmark(),
            $rivals ?? $this->rivals(),
        );
    }

    private function product(string $status = 'ready'): FakeProductCertificationService
    {
        return new FakeProductCertificationService($status);
    }

    private function runtime(
        string $status = AtlasAiRuntimeReleaseGateService::STATUS_READY,
        array $blockers = [],
        array $warnings = [],
    ): FakeRuntimeReleaseGateService {
        return new FakeRuntimeReleaseGateService($status, $blockers, $warnings);
    }

    private function teos(string $status = AtlasTeosFinalCertificationService::STATUS_READY): FakeTeosFinalCertificationService
    {
        return new FakeTeosFinalCertificationService($status);
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function benchmark(array $overrides = []): FakeBenchmarkReadinessHarness
    {
        return new FakeBenchmarkReadinessHarness($overrides);
    }

    private function rivals(bool $workspaceReady = true, array $blockingReasons = []): FakeProgrammingRivalsReadinessService
    {
        return new FakeProgrammingRivalsReadinessService($workspaceReady, $blockingReasons);
    }
}

final class FakeProductCertificationService extends AtlasAiProductCertificationService
{
    public function __construct(private readonly string $status) {}

    public function certify(): array
    {
        return [
            'status' => $this->status,
            'certification_hash' => str_repeat('1', 64),
            'summary' => ['total' => 12, 'passed' => $this->status === 'ready' ? 12 : 11],
        ];
    }
}

final class FakeRuntimeReleaseGateService extends AtlasAiRuntimeReleaseGateService
{
    public function __construct(
        private readonly string $status,
        private readonly array $blockers,
        private readonly array $warnings,
    ) {}

    public function report(): array
    {
        return [
            'status' => $this->status,
            'certification_hash' => str_repeat('2', 64),
            'summary' => [
                'total' => 11,
                'passed' => $this->status === AtlasAiRuntimeReleaseGateService::STATUS_READY ? 11 : 10,
                'partial' => 0,
                'failed' => $this->status === AtlasAiRuntimeReleaseGateService::STATUS_READY ? 0 : 1,
                'critical_failed' => 0,
                'warn_failed' => count($this->warnings),
            ],
            'blockers' => $this->blockers,
            'warnings' => $this->warnings,
        ];
    }
}

final class FakeTeosFinalCertificationService extends AtlasTeosFinalCertificationService
{
    public function __construct(private readonly string $status) {}

    public function certify(array $input = []): array
    {
        return [
            'status' => $this->status,
            'certification_hash' => str_repeat('3', 64),
            'summary' => ['total' => 6, 'pass' => $this->status === self::STATUS_READY ? 6 : 5],
            'blockers' => $this->status === self::STATUS_READY ? [] : [['id' => 'teos_gap']],
            'warnings' => [],
        ];
    }
}

final class FakeBenchmarkReadinessHarness extends BenchmarkReadinessHarness
{
    /**
     * @param  array<string,mixed>  $overrides
     */
    public function __construct(private readonly array $overrides = []) {}

    public function validate(?array $payload = null): array
    {
        return array_replace_recursive([
            'benchmark_suite_id' => 'apbsr_fake',
            'suite_hash' => str_repeat('4', 64),
            'case_count' => count(BenchmarkReadinessCanon::CASE_TYPES),
            'benchmark_status' => BenchmarkReadinessCanon::STATUS_NOT_RUN,
            'validation' => [
                'status' => BenchmarkReadinessCanon::READINESS_PASSED,
                'violation_count' => 0,
                'violations' => [],
            ],
            'claim_policy' => [
                'benchmark_not_run' => true,
                'rivals_compared' => false,
                'rival_provider_invoked' => false,
                'allows_external_superiority_claim' => false,
            ],
        ], $this->overrides);
    }
}

final class FakeProgrammingRivalsReadinessService extends ProgrammingRivalsReadinessService
{
    public function __construct(
        private readonly bool $workspaceReady,
        private readonly array $blockingReasons,
    ) {}

    public function report(string $workspace, bool $refreshLocalBenchmarks = false): array
    {
        return [
            'status' => 'external_battery_required',
            'summary' => [
                'local_programming_foundation_ready' => true,
                'real_provider_battery_attempted' => false,
                'claim_ready' => false,
            ],
            'current_workspace_preflight' => [
                'ready_for_provider_battery' => $this->workspaceReady,
                'status' => $this->workspaceReady ? 'ready' : 'blocked',
                'dirty_count' => $this->workspaceReady ? 0 : 7,
            ],
            'operator_execution_packet' => [
                'status' => $this->workspaceReady ? 'blocked_until_operator_approval' : 'blocked_until_clean_worktree',
                'provider_dispatches_now' => false,
                'operator_approval_required' => true,
                'blocking_reasons' => $this->blockingReasons,
                'recommended_first_run' => [
                    'command' => 'php artisan atlas:engineering:benchmark:rivals run --quick --confirm-runbook-reviewed --confirm-provider-cost --json',
                ],
            ],
        ];
    }
}

final class FakePreBenchmarkReadinessService extends AtlasPreBenchmarkReadinessService
{
    /**
     * @param  array<string,mixed>  $payload
     */
    public function __construct(private readonly array $payload) {}

    public function report(): array
    {
        return $this->payload;
    }
}
