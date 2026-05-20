<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\BenchmarkReadiness;

use App\Services\Ai\LongHorizon\AtlasTeosFinalCertificationService;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Product\AtlasAiProductCertificationService;
use App\Services\Ai\Programming\ProgrammingRivalsReadinessService;
use App\Services\Ai\RuntimeReleaseGate\AtlasAiRuntimeReleaseGateService;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Final readiness gate before the operator authorizes a real benchmark.
 *
 * This service never executes benchmark/rivals/providers. It answers one
 * question: "is the Atlas programming runtime ready for a supervised benchmark
 * session if the human explicitly authorizes it later?"
 */
class AtlasPreBenchmarkReadinessService
{
    public const SCHEMA_VERSION = 'atlas.programming.pre_benchmark_readiness.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_BLOCKED = 'blocked';

    /** @var list<string> */
    private const ACCEPTED_RUNTIME_WARNINGS = [
        'control_plane_runtime',
    ];

    public function __construct(
        private readonly AtlasAiProductCertificationService $productCertification,
        private readonly AtlasAiRuntimeReleaseGateService $runtimeReleaseGate,
        private readonly AtlasTeosFinalCertificationService $teosFinalCertification,
        private readonly BenchmarkReadinessHarness $benchmarkReadiness,
        private readonly ProgrammingRivalsReadinessService $rivalsReadiness,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function report(): array
    {
        $now = CarbonImmutable::now();
        $checks = [
            $this->productCheck(),
            $this->runtimeReleaseGateCheck(),
            $this->teosFinalCheck(),
            $this->benchmarkSuiteCheck(),
            $this->rivalsExecutionPreflightCheck(),
            $this->claimPolicyCheck(),
        ];

        $status = $this->status($checks);
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'generated_at' => $now->toJSON(),
            'summary' => $this->summary($checks),
            'checks' => $checks,
            'blockers' => $this->findings($checks, 'fail'),
            'warnings' => $this->findings($checks, 'warn'),
            'accepted_warnings' => $this->acceptedWarnings($checks),
            'required_operator_action' => [
                'next_action' => $status === self::STATUS_READY
                    ? 'authorize_benchmark_in_separate_command'
                    : 'resolve_pre_benchmark_blockers_before_authorization',
                'benchmark_authorization_required' => $status === self::STATUS_READY,
                'do_not_run_benchmark_from_this_gate' => true,
            ],
            'claim_policy' => [
                'benchmark_not_run' => true,
                'rivals_compared' => false,
                'provider_calls_made' => false,
                'allows_external_superiority_claim' => false,
                'ready_to_request_human_benchmark_authorization' => $status === self::STATUS_READY,
            ],
            'writes' => false,
        ];
        $payload['readiness_hash'] = $this->hashReport($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function productCheck(): array
    {
        try {
            $payload = $this->productCertification->certify();
        } catch (Throwable $exception) {
            return $this->check('product_certification', 'fail', 'critical', $exception->getMessage(), []);
        }

        $status = (string) ($payload['status'] ?? 'unknown');

        return $this->check(
            'product_certification',
            $status === 'ready' ? 'pass' : 'fail',
            'critical',
            'Atlas AI product certification status: '.$status,
            [
                'certification_hash' => $payload['certification_hash'] ?? null,
                'summary' => $payload['summary'] ?? null,
            ],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function runtimeReleaseGateCheck(): array
    {
        try {
            $payload = $this->runtimeReleaseGate->report();
        } catch (Throwable $exception) {
            return $this->check('runtime_release_gate', 'fail', 'critical', $exception->getMessage(), []);
        }

        $status = (string) ($payload['status'] ?? 'unknown');
        $blockers = array_values((array) ($payload['blockers'] ?? []));
        $warnings = array_values((array) ($payload['warnings'] ?? []));
        $unacceptedWarnings = array_values(array_diff($warnings, self::ACCEPTED_RUNTIME_WARNINGS));
        $accepted = $status === AtlasAiRuntimeReleaseGateService::STATUS_READY
            || ($status === AtlasAiRuntimeReleaseGateService::STATUS_PARTIAL && $blockers === [] && $unacceptedWarnings === []);

        return $this->check(
            'runtime_release_gate',
            $accepted ? 'pass' : 'fail',
            'critical',
            'Runtime release gate status: '.$status,
            [
                'certification_hash' => $payload['certification_hash'] ?? null,
                'summary' => $payload['summary'] ?? null,
                'blockers' => $blockers,
                'warnings' => $warnings,
                'accepted_warnings' => array_values(array_intersect($warnings, self::ACCEPTED_RUNTIME_WARNINGS)),
                'unaccepted_warnings' => $unacceptedWarnings,
            ],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function teosFinalCheck(): array
    {
        try {
            $payload = $this->teosFinalCertification->certify();
        } catch (Throwable $exception) {
            return $this->check('teos_final_certification', 'fail', 'critical', $exception->getMessage(), []);
        }

        $status = (string) ($payload['status'] ?? 'unknown');

        return $this->check(
            'teos_final_certification',
            $status === AtlasTeosFinalCertificationService::STATUS_READY ? 'pass' : 'fail',
            'critical',
            'TEOS final certification status: '.$status,
            [
                'certification_hash' => $payload['certification_hash'] ?? null,
                'summary' => $payload['summary'] ?? null,
                'blockers' => $payload['blockers'] ?? [],
                'warnings' => $payload['warnings'] ?? [],
            ],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function benchmarkSuiteCheck(): array
    {
        try {
            $payload = $this->benchmarkReadiness->validate();
        } catch (Throwable $exception) {
            return $this->check('benchmark_suite_readiness', 'fail', 'critical', $exception->getMessage(), []);
        }

        $validationStatus = (string) data_get($payload, 'validation.status', 'unknown');
        $benchmarkStatus = (string) ($payload['benchmark_status'] ?? 'unknown');
        $claimPolicy = (array) ($payload['claim_policy'] ?? []);
        $passed = $validationStatus === BenchmarkReadinessCanon::READINESS_PASSED
            && $benchmarkStatus === BenchmarkReadinessCanon::STATUS_NOT_RUN
            && ($claimPolicy['benchmark_not_run'] ?? null) === true
            && ($claimPolicy['rivals_compared'] ?? null) === false
            && ($claimPolicy['rival_provider_invoked'] ?? null) === false
            && ($claimPolicy['allows_external_superiority_claim'] ?? null) === false;

        return $this->check(
            'benchmark_suite_readiness',
            $passed ? 'pass' : 'fail',
            'critical',
            'Benchmark suite validation: '.$validationStatus.'; status: '.$benchmarkStatus,
            [
                'benchmark_suite_id' => $payload['benchmark_suite_id'] ?? null,
                'suite_hash' => $payload['suite_hash'] ?? null,
                'case_count' => $payload['case_count'] ?? count((array) ($payload['case_manifest'] ?? [])),
                'benchmark_status' => $benchmarkStatus,
                'validation' => $payload['validation'] ?? null,
                'claim_policy' => $claimPolicy,
            ],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function rivalsExecutionPreflightCheck(): array
    {
        try {
            $payload = $this->rivalsReadiness->report(base_path(), false);
        } catch (Throwable $exception) {
            return $this->check('rivals_execution_preflight', 'fail', 'critical', $exception->getMessage(), []);
        }

        $status = (string) ($payload['status'] ?? 'unknown');
        $localFoundationReady = (bool) data_get($payload, 'summary.local_programming_foundation_ready', false);
        $workspaceReady = (bool) data_get($payload, 'current_workspace_preflight.ready_for_provider_battery', false);
        $operatorPacket = (array) ($payload['operator_execution_packet'] ?? []);
        $recommendedCommand = (string) data_get($operatorPacket, 'recommended_first_run.command', '');
        $benchmarkNeverAttempted = (bool) data_get($payload, 'summary.real_provider_battery_attempted', false) === false;
        $acceptedStatus = in_array($status, ['external_battery_required', 'claim_ready'], true);
        $passed = $localFoundationReady
            && $workspaceReady
            && $recommendedCommand !== ''
            && $benchmarkNeverAttempted
            && $acceptedStatus;

        return $this->check(
            'rivals_execution_preflight',
            $passed ? 'pass' : 'fail',
            'critical',
            'Rivals execution preflight status: '.$status,
            [
                'rivals_readiness_status' => $status,
                'local_programming_foundation_ready' => $localFoundationReady,
                'current_workspace_ready_for_provider_battery' => $workspaceReady,
                'current_workspace_status' => data_get($payload, 'current_workspace_preflight.status'),
                'dirty_count' => data_get($payload, 'current_workspace_preflight.dirty_count'),
                'blocking_reasons' => data_get($operatorPacket, 'blocking_reasons', []),
                'recommended_first_run' => data_get($operatorPacket, 'recommended_first_run'),
                'provider_dispatches_now' => data_get($operatorPacket, 'provider_dispatches_now'),
                'operator_approval_required' => data_get($operatorPacket, 'operator_approval_required'),
                'real_provider_battery_attempted' => data_get($payload, 'summary.real_provider_battery_attempted'),
                'claim_ready' => data_get($payload, 'summary.claim_ready'),
            ],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function claimPolicyCheck(): array
    {
        return $this->check(
            'no_benchmark_or_rivals_executed',
            'pass',
            'critical',
            'Pre-benchmark readiness is read-only and does not execute benchmark/rivals/providers',
            [
                'benchmark_not_run' => true,
                'rivals_compared' => false,
                'provider_calls_made' => false,
                'external_superiority_claim_allowed' => false,
            ],
        );
    }

    /**
     * @param  array<string,mixed>  $evidence
     * @return array<string,mixed>
     */
    private function check(string $id, string $status, string $severity, string $summary, array $evidence): array
    {
        return [
            'id' => $id,
            'status' => $status,
            'severity' => $severity,
            'summary' => $summary,
            'evidence' => $evidence,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $checks
     */
    private function status(array $checks): string
    {
        foreach ($checks as $check) {
            if (($check['status'] ?? null) === 'fail' && ($check['severity'] ?? null) === 'critical') {
                return self::STATUS_BLOCKED;
            }
        }
        foreach ($checks as $check) {
            if (($check['status'] ?? null) !== 'pass') {
                return self::STATUS_PARTIAL;
            }
        }

        return self::STATUS_READY;
    }

    /**
     * @param  array<int,array<string,mixed>>  $checks
     * @return array<string,int>
     */
    private function summary(array $checks): array
    {
        return [
            'total' => count($checks),
            'pass' => count(array_filter($checks, fn (array $check): bool => ($check['status'] ?? null) === 'pass')),
            'warn' => count(array_filter($checks, fn (array $check): bool => ($check['status'] ?? null) === 'warn')),
            'fail' => count(array_filter($checks, fn (array $check): bool => ($check['status'] ?? null) === 'fail')),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $checks
     * @return array<int,array<string,mixed>>
     */
    private function findings(array $checks, string $status): array
    {
        return array_values(array_filter($checks, fn (array $check): bool => ($check['status'] ?? null) === $status));
    }

    /**
     * @param  array<int,array<string,mixed>>  $checks
     * @return array<int,string>
     */
    private function acceptedWarnings(array $checks): array
    {
        $warnings = [];
        foreach ($checks as $check) {
            foreach ((array) data_get($check, 'evidence.accepted_warnings', []) as $warning) {
                if (is_string($warning) && $warning !== '') {
                    $warnings[] = $warning;
                }
            }
        }

        return array_values(array_unique($warnings));
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hashReport(array $payload): string
    {
        unset($payload['generated_at']);

        return MissionCanonicalHash::sha256($payload);
    }
}
