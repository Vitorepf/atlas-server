<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L8LocalEnginePortfolioAdmissionGate;
use PHPUnit\Framework\TestCase;

final class L8LocalEnginePortfolioAdmissionGateTest extends TestCase
{
    private L8LocalEnginePortfolioAdmissionGate $gate;

    protected function setUp(): void
    {
        $this->gate = new L8LocalEnginePortfolioAdmissionGate();
    }

    public function testAdmitsLocalEngineWhenQualityMeetsExternalPathWithFallback(): void
    {
        $result = $this->gate->admit(
            [
                'task_class_id' => 'tc_summarize',
                'privacy_class' => 'general',
                'fallback_provider' => 'external_path',
            ],
            [
                'local_quality_score' => 0.92,
                'external_path_quality_score' => 0.90,
            ],
        );

        $this->assertSame('atlas.aaeos.l8.local_engine_portfolio_admission.v1', $result['schema_version']);
        $this->assertTrue($result['admitted']);
        $this->assertTrue($result['provider_port_contract_required']);
        $this->assertEqualsWithDelta(0.02, $result['quality_delta'], 1.0e-9);
        $this->assertSame('not_required', $result['privacy_gate_status']);
        $this->assertFalse($result['fallback_required']);
        $this->assertSame([], $result['blockers']);
    }

    public function testQualityBelowExternalPathRejects(): void
    {
        $result = $this->gate->admit(
            [
                'task_class_id' => 'tc_classify',
                'privacy_class' => 'general',
                'fallback_provider' => 'external_path',
            ],
            [
                'local_quality_score' => 0.71,
                'external_path_quality_score' => 0.88,
            ],
        );

        $this->assertFalse($result['admitted']);
        $this->assertTrue($result['provider_port_contract_required']);
        $this->assertEqualsWithDelta(-0.17, $result['quality_delta'], 1.0e-9);
        $this->assertContains('quality_below_external_path', $result['blockers']);
    }

    public function testMissingFallbackRejects(): void
    {
        $result = $this->gate->admit(
            [
                'task_class_id' => 'tc_extract',
                'privacy_class' => 'general',
            ],
            [
                'local_quality_score' => 0.95,
                'external_path_quality_score' => 0.90,
            ],
        );

        $this->assertFalse($result['admitted']);
        $this->assertTrue($result['fallback_required']);
        $this->assertContains('fallback_required', $result['blockers']);
    }

    public function testSensitiveClassWithoutLocalFirstFlagRejects(): void
    {
        $result = $this->gate->admit(
            [
                'task_class_id' => 'tc_secret_review',
                'privacy_class' => 'sensitive',
                'fallback_provider' => 'external_path',
            ],
            [
                'local_quality_score' => 0.97,
                'external_path_quality_score' => 0.90,
            ],
        );

        $this->assertFalse($result['admitted']);
        $this->assertSame('blocked', $result['privacy_gate_status']);
        $this->assertContains('sensitive_class_requires_local_first', $result['blockers']);
    }

    public function testSensitiveClassWithLocalFirstFlagAdmits(): void
    {
        $result = $this->gate->admit(
            [
                'task_class_id' => 'tc_secret_review',
                'privacy_class' => 'sensitive',
                'local_first' => true,
                'fallback_provider' => 'external_path',
            ],
            [
                'local_quality_score' => 0.97,
                'external_path_quality_score' => 0.90,
            ],
        );

        $this->assertTrue($result['admitted']);
        $this->assertSame('satisfied', $result['privacy_gate_status']);
        $this->assertEqualsWithDelta(0.07, $result['quality_delta'], 1.0e-9);
        $this->assertSame([], $result['blockers']);
    }

    public function testEqualQualityIsAdmittedBecauseDeltaIsNotBelowExternalPath(): void
    {
        $result = $this->gate->admit(
            [
                'task_class_id' => 'tc_route',
                'privacy_class' => 'general',
                'fallback_available' => true,
            ],
            [
                'local_quality_score' => 0.84,
                'external_path_quality_score' => 0.84,
            ],
        );

        $this->assertTrue($result['admitted']);
        $this->assertEqualsWithDelta(0.0, $result['quality_delta'], 1.0e-9);
        $this->assertFalse($result['fallback_required']);
    }

    public function testMultipleViolationsAccumulateBlockers(): void
    {
        $result = $this->gate->admit(
            [
                'task_class_id' => 'tc_secret_route',
                'privacy_class' => 'secret',
            ],
            [
                'local_quality_score' => 0.40,
                'external_path_quality_score' => 0.86,
            ],
        );

        $this->assertFalse($result['admitted']);
        $this->assertEqualsWithDelta(-0.46, $result['quality_delta'], 1.0e-9);
        $this->assertTrue($result['fallback_required']);
        $this->assertSame('blocked', $result['privacy_gate_status']);
        $this->assertSame(
            [
                'quality_below_external_path',
                'fallback_required',
                'sensitive_class_requires_local_first',
            ],
            $result['blockers'],
        );
    }

    public function testSensitiveClassWithWhitespaceOrMixedCaseStillEnforcesLocalFirst(): void
    {
        // A privacy_class carrying surrounding whitespace / mixed case must still be
        // recognised as a local-first class and fail closed without the local-first
        // flag — it must never slip through as 'general' and admit a sovereignty leak.
        foreach ([' sensitive ', "secret\n", 'CYBER', "  Secret"] as $privacyClass) {
            $result = $this->gate->admit(
                [
                    'task_class_id' => 'tc_padded',
                    'privacy_class' => $privacyClass,
                    'fallback_provider' => 'external_path',
                ],
                [
                    'local_quality_score' => 0.97,
                    'external_path_quality_score' => 0.90,
                ],
            );

            $this->assertFalse(
                $result['admitted'],
                "privacy_class '{$privacyClass}' must enforce local-first",
            );
            $this->assertSame('blocked', $result['privacy_gate_status'], $privacyClass);
            $this->assertContains('sensitive_class_requires_local_first', $result['blockers'], $privacyClass);
        }
    }

    public function testGeneralClassNeverRequiresLocalFirstFlag(): void
    {
        $result = $this->gate->admit(
            [
                'task_class_id' => 'tc_general',
                'privacy_class' => 'general',
                'fallback_available' => true,
            ],
            [
                'local_quality_score' => 0.60,
                'external_path_quality_score' => 0.55,
            ],
        );

        $this->assertSame('not_required', $result['privacy_gate_status']);
        $this->assertTrue($result['admitted']);
    }

    public function testBlockersIsListOfStrings(): void
    {
        $result = $this->gate->admit(
            [
                'task_class_id' => 'tc_secret',
                'privacy_class' => 'secret',
            ],
            [
                'local_quality_score' => 0.10,
                'external_path_quality_score' => 0.90,
            ],
        );

        $this->assertSame(array_values($result['blockers']), $result['blockers']);

        foreach ($result['blockers'] as $key => $blocker) {
            $this->assertIsInt($key);
            $this->assertIsString($blocker);
        }
    }

    public function testQualityDeltaIsFloatComputedFromInputs(): void
    {
        $result = $this->gate->admit(
            [
                'task_class_id' => 'tc_delta',
                'privacy_class' => 'general',
                'fallback_available' => true,
            ],
            [
                'local_quality_score' => 0.8125,
                'external_path_quality_score' => 0.5,
            ],
        );

        $this->assertIsFloat($result['quality_delta']);
        $this->assertEqualsWithDelta(0.3125, $result['quality_delta'], 1.0e-9);
    }

    public function testNonFiniteQualityScoreNeverSilentlyAdmits(): void
    {
        // A non-finite local quality (NAN/INF) is the residue of an upstream
        // divide-by-zero or overflow on a 0..1 score, not a real measurement. It
        // must be treated as the safe absent value (0.0) so quality_delta stays a
        // finite number and an unmeasured local path can never slip the `< 0.0`
        // quality gate and admit a port on garbage.
        foreach ([INF, -INF, NAN] as $poison) {
            $result = $this->gate->admit(
                [
                    'task_class_id' => 'tc_poison',
                    'privacy_class' => 'general',
                    'fallback_provider' => 'external_path',
                ],
                [
                    'local_quality_score' => $poison,
                    'external_path_quality_score' => 0.90,
                ],
            );

            $this->assertTrue(
                is_finite($result['quality_delta']),
                'quality_delta must stay finite for a non-finite input',
            );
            // local treated as 0.0 -> delta 0.0 - 0.90 = -0.90 -> below external -> blocked.
            $this->assertEqualsWithDelta(-0.90, $result['quality_delta'], 1.0e-9);
            $this->assertFalse($result['admitted']);
            $this->assertContains('quality_below_external_path', $result['blockers']);
        }
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $candidate = [
            'task_class_id' => 'tc_deterministic',
            'privacy_class' => 'sensitive',
            'local_first' => true,
            'fallback_provider' => 'external_path',
        ];
        $evaluation = [
            'local_quality_score' => 0.93,
            'external_path_quality_score' => 0.91,
        ];

        $first = $this->gate->admit($candidate, $evaluation);
        $second = $this->gate->admit($candidate, $evaluation);

        $this->assertSame($first, $second);
    }
}
