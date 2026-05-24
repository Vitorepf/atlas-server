<?php

namespace Tests\Unit\Ai\Programming\BenchmarkReadiness;

use App\Services\Ai\Programming\BenchmarkReadiness\BenchmarkReadinessAuthorizationException;
use App\Services\Ai\Programming\BenchmarkReadiness\BenchmarkReadinessCanon;
use App\Services\Ai\Programming\BenchmarkReadiness\BenchmarkReadinessHarness;
use PHPUnit\Framework\TestCase;

class BenchmarkReadinessHarnessTest extends TestCase
{
    private BenchmarkReadinessHarness $harness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->harness = new BenchmarkReadinessHarness;
    }

    public function test_suite_covers_every_canonical_case_type_with_valid_manifest(): void
    {
        $suite = $this->harness->suite();

        $this->assertSame(BenchmarkReadinessCanon::SUITE_SCHEMA_VERSION, $suite['schema_version']);
        $this->assertSame(BenchmarkReadinessCanon::STATUS_NOT_RUN, $suite['benchmark_status']);
        $this->assertTrue($suite['human_authorization_required']);
        $this->assertSame(BenchmarkReadinessCanon::PROVIDER_SLOTS, $suite['provider_slots']);
        $this->assertCount(count(BenchmarkReadinessCanon::CASE_TYPES), $suite['case_manifest']);

        $present = array_unique(array_map(
            static fn (array $c): string => $c['case_type'],
            $suite['case_manifest'],
        ));
        sort($present);
        $expected = BenchmarkReadinessCanon::CASE_TYPES;
        sort($expected);
        $this->assertSame($expected, array_values($present));
    }

    public function test_every_case_carries_well_formed_scoring_rubric(): void
    {
        $suite = $this->harness->suite();

        foreach ($suite['case_manifest'] as $case) {
            $rubric = $case['scoring_rubric'];
            $this->assertSame(
                BenchmarkReadinessCanon::RUBRIC_SCHEMA_VERSION,
                $rubric['schema_version'],
                "rubric schema for {$case['case_id']}",
            );
            $this->assertNotEmpty($rubric['dimensions'], "dimensions for {$case['case_id']}");
            $this->assertNotEmpty($rubric['weights'], "weights for {$case['case_id']}");
            foreach ($rubric['weights'] as $dimension => $weight) {
                $this->assertContains(
                    $dimension,
                    BenchmarkReadinessCanon::SCORING_DIMENSIONS,
                    "rubric uses unknown dimension {$dimension}",
                );
                $this->assertGreaterThanOrEqual(0.0, $weight);
                $this->assertLessThanOrEqual(1.0, $weight);
            }
            $sum = array_sum(array_map('floatval', $rubric['weights']));
            $this->assertEqualsWithDelta(1.0, $sum, 0.001, "weights for {$case['case_id']} must sum to 1.0");
            $this->assertIsNumeric($rubric['pass_threshold']);
        }
    }

    public function test_human_authorization_required_is_true_on_suite_and_every_case(): void
    {
        $suite = $this->harness->suite();

        $this->assertTrue($suite['human_authorization_required']);
        foreach ($suite['case_manifest'] as $case) {
            $this->assertTrue(
                $case['human_authorization_required'],
                "case {$case['case_id']} must declare human_authorization_required=true",
            );
        }
    }

    public function test_benchmark_status_is_not_run_everywhere(): void
    {
        $suite = $this->harness->suite();

        $this->assertSame(BenchmarkReadinessCanon::STATUS_NOT_RUN, $suite['benchmark_status']);
        foreach ($suite['case_manifest'] as $case) {
            $this->assertSame(
                BenchmarkReadinessCanon::STATUS_NOT_RUN,
                $case['benchmark_status'],
                "case {$case['case_id']} must declare benchmark_status=benchmark_not_run",
            );
        }
        $this->assertTrue($suite['claim_policy']['benchmark_not_run']);
        $this->assertFalse($suite['claim_policy']['rivals_compared']);
        $this->assertFalse($suite['claim_policy']['rival_provider_invoked']);
        $this->assertFalse($suite['claim_policy']['allows_external_superiority_claim']);
    }

    public function test_run_without_any_authorization_is_blocked(): void
    {
        $this->expectException(BenchmarkReadinessAuthorizationException::class);
        $this->expectExceptionMessage(BenchmarkReadinessAuthorizationException::REASON_MISSING_AUTHORIZATION);

        $this->harness->run();
    }

    public function test_run_with_partial_authorization_is_blocked_with_missing_fields(): void
    {
        try {
            $this->harness->run([
                'external_battery_authorized' => true,
                // missing human_operator_signature, authorization_reason, expires_at
            ]);
            $this->fail('Expected BenchmarkReadinessAuthorizationException');
        } catch (BenchmarkReadinessAuthorizationException $exception) {
            $this->assertStringContainsString(
                BenchmarkReadinessAuthorizationException::REASON_MISSING_AUTHORIZATION,
                $exception->getMessage(),
            );
            $this->assertStringContainsString('human_operator_signature', $exception->getMessage());
        }
    }

    public function test_run_even_with_valid_shaped_authorization_still_refuses_in_readiness_only_slice(): void
    {
        try {
            $this->harness->run([
                'external_battery_authorized' => true,
                'human_operator_signature' => 'operator:vitor#sig',
                'authorization_reason' => 'M10 dry-run smoke',
                'expires_at' => '2026-06-01T00:00:00Z',
            ]);
            $this->fail('Expected BenchmarkReadinessAuthorizationException');
        } catch (BenchmarkReadinessAuthorizationException $exception) {
            $this->assertStringContainsString(
                BenchmarkReadinessAuthorizationException::REASON_RUNTIME_NOT_WIRED,
                $exception->getMessage(),
            );
        }
    }

    public function test_validate_returns_passed_on_canonical_suite(): void
    {
        $validated = $this->harness->validate();

        $this->assertSame(
            BenchmarkReadinessCanon::READINESS_PASSED,
            $validated['validation']['status'],
        );
        $this->assertSame(0, $validated['validation']['violation_count']);
        $this->assertSame([], $validated['validation']['violations']);
    }

    public function test_validate_flags_tampered_suite(): void
    {
        $suite = $this->harness->suite();
        $suite['benchmark_status'] = 'completed';
        $suite['claim_policy']['benchmark_not_run'] = false;
        $suite['claim_policy']['allows_external_superiority_claim'] = true;
        unset($suite['case_manifest'][0]);

        $validated = $this->harness->validate($suite);

        $this->assertSame(
            BenchmarkReadinessCanon::READINESS_BLOCKED,
            $validated['validation']['status'],
        );
        $this->assertContains('benchmark_status_must_be_not_run', $validated['validation']['violations']);
        $this->assertContains('claim_policy_benchmark_not_run_must_be_true', $validated['validation']['violations']);
        $this->assertContains(
            'claim_policy_must_refuse_external_superiority_claim',
            $validated['validation']['violations'],
        );
        $this->assertContains('case_type_coverage_incomplete', $validated['validation']['violations']);
    }

    public function test_readiness_invariants_all_pass(): void
    {
        $checks = $this->harness->suite()['readiness_checks'];

        $this->assertSame(BenchmarkReadinessCanon::READINESS_PASSED, $checks['status']);
        $this->assertSame(0, $checks['failed_count']);
        $invariantIds = array_map(static fn (array $i): string => $i['id'], $checks['invariants']);
        foreach ([
            'every_case_type_present',
            'every_case_has_rubric_summing_to_one',
            'every_case_declares_required_evidence',
            'every_case_declares_required_telemetry',
            'rival_placeholder_unbound',
            'no_destructive_tools',
            'authorization_required',
            'runtime_not_wired',
        ] as $id) {
            $this->assertContains($id, $invariantIds, "invariant {$id} must be declared");
        }
    }

    public function test_rival_placeholder_is_unbound_in_every_case(): void
    {
        foreach ($this->harness->suite()['case_manifest'] as $case) {
            $this->assertSame(
                'unbound_no_run',
                $case['provider_slots']['rival_placeholder'],
                "case {$case['case_id']} must keep rival_placeholder unbound_no_run",
            );
        }
    }

    public function test_suite_payload_is_json_stable_across_calls(): void
    {
        $first = $this->harness->suite();
        $second = $this->harness->suite();

        $this->assertSame($first['benchmark_suite_id'], $second['benchmark_suite_id']);
        $this->assertSame($first['suite_hash'], $second['suite_hash']);
        $this->assertSame(
            json_encode($first, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            json_encode($second, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );

        // Per-case hashes must also be stable.
        foreach ($first['case_manifest'] as $idx => $case) {
            $this->assertSame(
                $case['case_hash'],
                $second['case_manifest'][$idx]['case_hash'],
                "case {$case['case_id']} hash drifted",
            );
            $this->assertSame(64, strlen($case['case_hash']));
        }
    }

    public function test_readiness_and_manifest_json_are_subsets_of_suite(): void
    {
        $suite = $this->harness->suite();
        $readiness = $this->harness->readinessJson();
        $manifest = $this->harness->manifestJson();

        $this->assertSame($suite['benchmark_suite_id'], $readiness['benchmark_suite_id']);
        $this->assertSame($suite['benchmark_suite_id'], $manifest['benchmark_suite_id']);
        $this->assertSame($suite['suite_hash'], $readiness['suite_hash']);
        $this->assertSame($suite['suite_hash'], $manifest['suite_hash']);
        $this->assertSame($suite['case_manifest'], $manifest['case_manifest']);
        $this->assertTrue($readiness['human_authorization_required']);
        $this->assertSame($suite['benchmark_status'], $readiness['benchmark_status']);
    }

    public function test_allowed_tools_never_include_destructive_operations(): void
    {
        $forbidden = ['code.patch.apply', 'file.delete', 'force_push', 'rm_rf', 'db.migrate.rollback'];
        foreach ($this->harness->suite()['case_manifest'] as $case) {
            foreach ($case['allowed_tools'] as $tool) {
                $this->assertNotContains($tool, $forbidden, "case {$case['case_id']} cannot expose destructive tool {$tool}");
            }
        }
    }
}
