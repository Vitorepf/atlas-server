<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\AgenticEngineeringOs;

use App\Services\Ai\AgenticEngineeringOs\AtlasUniversalGatesEvaluator;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;
use Throwable;

/**
 * GOD-DEBULK characterization judge for {@see AtlasUniversalGatesEvaluator}.
 *
 * Freezes two things so the god-file split cannot change behaviour:
 *   1. The full structure + report_hash of a representative evaluate() call.
 *   2. A single sha256 over the output (or thrown exception class) of EVERY
 *      public `*Observe` method invoked with an empty array — the entire
 *      surface that the split relocates. Because bodies move verbatim and the
 *      façade delegates, this hash must stay byte-identical across the split.
 *
 * If either assertion changes, the extraction was not behaviour-preserving.
 */
final class AtlasUniversalGatesEvaluatorGoldenTest extends TestCase
{
    /**
     * sha256 over the canonical json of every `*Observe` method's output on []
     * plus catalogue(). Captured green on main before the split.
     */
    private const GOLDEN_SURFACE_HASH = 'sha256:169b088d55a8de36e24d18a60f79e4eeb78d4d38387e8dd160362a59000e40f2'; // TRI-HYGIENE slim façade

    private const GOLDEN_EVALUATE_HASH = 'sha256:0e9579e818b7e88cca21eaad67a800b2bfac274dfbca0c90ea989da483337604';

    protected function setUp(): void
    {
        parent::setUp();
        // Freeze wall-clock: a handful of Observe methods embed now()/Carbon
        // (freshness deltas, verified-share windows) — pin them for a stable hash.
        Carbon::setTestNow('2026-07-22T00:00:00+00:00');
        CarbonImmutable::setTestNow('2026-07-22T00:00:00+00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_evaluate_golden_structure_is_frozen(): void
    {
        $gates = app(AtlasUniversalGatesEvaluator::class);

        $report = $gates->evaluate(
            'intent-god-debulk-golden',
            [
                'lint_green' => true,
                'typecheck_green' => true,
                'tests_green' => true,
                'coverage_min_threshold' => false,
                'scope_guard_ok' => true,
                'security_scan_clean' => 'exception',
                'dependency_audit_clean' => null,
                'secret_scan_clean' => true,
                'sovereignty_boundary_respected' => true,
                'decision_receipt_v2_signed' => true,
                'evidence_traceable' => true,
                'rollback_plan_present' => true,
                'review_packet_signed' => true,
                'delivery_pack_completeness_min_0_95' => true,
                'learning_capsule_registered' => true,
            ],
            ['security_scan_clean' => 'rcpt-123'],
        );

        self::assertSame([
            'schema', 'intent_id', 'gate_count', 'required', 'passed', 'blocked',
            'exception', 'missing', 'outcome', 'pass_rate', 'provider_safe',
            'evaluated_at', 'report_hash',
        ], array_keys($report));

        self::assertSame('red', $report['outcome']); // coverage_min_threshold blocked
        self::assertSame(15, $report['gate_count']);
        self::assertContains('coverage_min_threshold', $report['blocked']);
        self::assertContains('dependency_audit_clean', $report['missing']);
        self::assertSame([['gate' => 'security_scan_clean', 'receipt_id' => 'rcpt-123']], $report['exception']);

        // Volatile field excluded from the frozen hash.
        $stable = $report;
        unset($stable['evaluated_at']);
        $hash = hash('sha256', (string) json_encode($stable));

        if (self::GOLDEN_EVALUATE_HASH === '__PENDING__') {
            self::fail('CAPTURE GOLDEN_EVALUATE_HASH=sha256:'.$hash);
        }
        self::assertSame(self::GOLDEN_EVALUATE_HASH, 'sha256:'.$hash);
    }

    public function test_full_observe_surface_hash_is_frozen(): void
    {
        $gates = app(AtlasUniversalGatesEvaluator::class);
        $ref = new ReflectionClass($gates);

        $surface = [];
        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $name = $method->getName();
            if (! str_ends_with($name, 'Observe')) {
                continue;
            }
            try {
                $result = $gates->{$name}([]);
                $surface[$name] = self::maskTimestamps((string) json_encode($result));
            } catch (Throwable $e) {
                $surface[$name] = 'threw:'.get_class($e);
            }
        }
        ksort($surface);
        $surface['__catalogue__'] = self::maskTimestamps((string) json_encode($gates->catalogue()));

        self::assertSame(759, count($surface) - 1, 'Observe method count drifted');

        $hash = hash('sha256', (string) json_encode($surface));
        if (self::GOLDEN_SURFACE_HASH === '__PENDING__') {
            self::fail('CAPTURE GOLDEN_SURFACE_HASH=sha256:'.$hash);
        }
        self::assertSame(self::GOLDEN_SURFACE_HASH, 'sha256:'.$hash);
    }

    /**
     * A few Observe methods stamp native gmdate()/date() wall-clock into their
     * output; mask ISO-8601 instants so the judge tracks structure+logic, not
     * the clock. Any non-timestamp change still moves the hash.
     */
    private static function maskTimestamps(string $json): string
    {
        return (string) preg_replace(
            '/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})/',
            '<TS>',
            $json,
        );
    }
}
