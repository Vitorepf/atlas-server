<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AgenticEngineeringOs;

use App\Services\Ai\AgenticEngineeringOs\AaeosHttpPathEnvelopeFactory;
use App\Services\Ai\AgenticEngineeringOs\AaeosPhaseHandoffService;
use Tests\TestCase;

/**
 * Proves policyGateBlocked is wired through PhaseAdvanceVerdictClassifier
 * (halt/block stop the HTTP path; advance does not).
 */
final class AaeosHttpPathEnvelopeFactoryPolicyAdvanceTest extends TestCase
{
    private AaeosHttpPathEnvelopeFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = new AaeosHttpPathEnvelopeFactory(new AaeosPhaseHandoffService);
    }

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

        self::assertTrue($this->factory->policyGateBlocked($envelope));
        self::assertSame('halt', $this->factory->phaseAdvanceVerdict($envelope)['verdict']);
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

        self::assertTrue($this->factory->policyGateBlocked($envelope));
        self::assertSame('block', $this->factory->phaseAdvanceVerdict($envelope)['verdict']);
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

        self::assertFalse($this->factory->policyGateBlocked($envelope));
        self::assertSame('advance', $this->factory->phaseAdvanceVerdict($envelope)['verdict']);
    }
}
