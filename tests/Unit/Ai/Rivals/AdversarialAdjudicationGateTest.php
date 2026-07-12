<?php

namespace Tests\Unit\Ai\Rivals;

use App\Services\Ai\EngineeringKernel\EngineeringRoleRoster;
use App\Services\Ai\Rivals\Core\AdversarialAdjudicationGate;
use Tests\TestCase;

/**
 * Adjudication is independent, conjunctive and outcome-bound. Each scenario below is
 * a way a green verdict could be manufactured; the gate must refuse it.
 */
class AdversarialAdjudicationGateTest extends TestCase
{
    /** @param array<string,string> $statusOverrides */
    private function dispositions(array $statusOverrides = []): array
    {
        $out = [];
        foreach (EngineeringRoleRoster::CANONICAL_ROLES as $role) {
            $out[$role] = ['status' => $statusOverrides[$role] ?? 'pass'];
        }

        return $out;
    }

    private function cleanBundle(array $overrides = []): array
    {
        return array_replace([
            'risk_class' => 'R5',
            'verdict_green' => true,
            'judge' => ['model_family' => 'gemini', 'version' => '2.1', 'saw_author_defense' => false, 'disagreements' => []],
            'author' => ['model_family' => 'claude'],
            'oracles' => [
                'hidden_tests' => true,
                'implementation_independent' => true,
                'security' => 'passed',
                'replay' => 'passed',
            ],
            'outcome' => ['observed' => true, 'elapsed' => true, 'contradictory' => false],
            'role_dispositions' => $this->dispositions(),
        ], $overrides);
    }

    public function test_clean_independent_adjudication_is_claim_eligible(): void
    {
        $out = (new AdversarialAdjudicationGate)->evaluate($this->cleanBundle());
        $this->assertSame([], $out['blockers']);
        $this->assertTrue($out['claim_eligible']);
        $this->assertSame('gemini', $out['judge_family']);
    }

    public function test_judge_seeing_author_defense_blocks(): void
    {
        $out = (new AdversarialAdjudicationGate)->evaluate($this->cleanBundle([
            'judge' => ['model_family' => 'gemini', 'saw_author_defense' => true],
        ]));
        $this->assertContains('judge_saw_author_defense', $out['blockers']);
        $this->assertFalse($out['claim_eligible']);
    }

    public function test_same_model_family_author_and_judge_at_high_risk_blocks(): void
    {
        $out = (new AdversarialAdjudicationGate)->evaluate($this->cleanBundle([
            'judge' => ['model_family' => 'claude', 'saw_author_defense' => false],
            'author' => ['model_family' => 'claude'],
        ]));
        $this->assertContains('author_judge_same_family_high_risk', $out['blockers']);
    }

    public function test_hidden_test_omitted_blocks(): void
    {
        $b = $this->cleanBundle();
        $b['oracles']['hidden_tests'] = false;
        $this->assertContains('hidden_test_omitted', (new AdversarialAdjudicationGate)->evaluate($b)['blockers']);
    }

    public function test_implementation_dependent_oracle_blocks(): void
    {
        $b = $this->cleanBundle();
        $b['oracles']['implementation_independent'] = false;
        $this->assertContains('implementation_dependent_oracle', (new AdversarialAdjudicationGate)->evaluate($b)['blockers']);
    }

    public function test_security_or_replay_failure_is_never_ignored(): void
    {
        $b = $this->cleanBundle();
        $b['oracles']['replay'] = 'failed';
        $this->assertContains('security_or_replay_failure_ignored', (new AdversarialAdjudicationGate)->evaluate($b)['blockers']);
    }

    public function test_missing_outcome_scored_green_blocks(): void
    {
        $b = $this->cleanBundle();
        $b['outcome'] = ['observed' => false, 'elapsed' => false, 'contradictory' => false];
        $out = (new AdversarialAdjudicationGate)->evaluate($b);
        $this->assertContains('missing_outcome_scored_green', $out['blockers']);
        $this->assertNotEmpty($out['uncertainty']);
    }

    public function test_contradictory_outcome_is_not_ignored(): void
    {
        $b = $this->cleanBundle();
        $b['outcome']['contradictory'] = true;
        $this->assertContains('contradictory_outcome_ignored', (new AdversarialAdjudicationGate)->evaluate($b)['blockers']);
    }

    public function test_missing_role_disposition_and_critical_block_are_flagged(): void
    {
        $gate = new AdversarialAdjudicationGate;

        $b = $this->cleanBundle();
        unset($b['role_dispositions']['appsec_privacy']);
        $this->assertContains('role_disposition_missing:appsec_privacy', $gate->evaluate($b)['blockers']);

        $b = $this->cleanBundle(['role_dispositions' => $this->dispositions(['qa_test' => 'block'])]);
        $this->assertContains('critical_block:qa_test', $gate->evaluate($b)['blockers']);
    }
}
