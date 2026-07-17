<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AgenticEngineeringOs;

use App\Services\Ai\AgenticEngineeringOs\AaeosHttpPathEnvelopeFactory;
use Tests\TestCase;

/**
 * Proves policyGateBlocked is wired through PhaseAdvanceVerdictClassifier
 * (halt/block stop the HTTP path; advance does not).
 */
final class AaeosHttpPathEnvelopeFactoryPolicyAdvanceTest extends TestCase
{
    public function test_policy_gate_blocked_when_classifier_halts(): void
    {
        $envelope = [
            'phase_out' => 'policy_gate',
            'gates' => [
                'required' => ['policy_decision_allowed_true'],
                'passed' => [],
                'blocked' => ['policy_decision_allowed_true'],
            ],
            'blockers' => [],
        ];

        self::assertTrue(AaeosHttpPathEnvelopeFactory::policyGateBlocked($envelope));
        self::assertSame('halt', AaeosHttpPathEnvelopeFactory::phaseAdvanceVerdict($envelope)['verdict']);
    }

    public function test_policy_gate_blocked_when_high_severity_blocker(): void
    {
        $envelope = [
            'phase_out' => 'policy_gate',
            'gates' => [
                'required' => ['policy_decision_allowed_true'],
                'passed' => [],
                'blocked' => ['policy_decision_allowed_true'],
            ],
            'blockers' => [
                ['id' => 'missing_workspace', 'severity' => 'high', 'owner' => 'atlas-dev'],
            ],
        ];

        self::assertTrue(AaeosHttpPathEnvelopeFactory::policyGateBlocked($envelope));
        self::assertSame('block', AaeosHttpPathEnvelopeFactory::phaseAdvanceVerdict($envelope)['verdict']);
    }

    public function test_policy_gate_not_blocked_when_decision_token_passed(): void
    {
        $envelope = [
            'phase_out' => 'policy_gate',
            'gates' => [
                'required' => ['policy_decision_allowed_true'],
                'passed' => ['policy_decision_allowed_true'],
                'blocked' => [],
            ],
            'blockers' => [],
        ];

        self::assertFalse(AaeosHttpPathEnvelopeFactory::policyGateBlocked($envelope));
        self::assertSame('advance', AaeosHttpPathEnvelopeFactory::phaseAdvanceVerdict($envelope)['verdict']);
    }
}
