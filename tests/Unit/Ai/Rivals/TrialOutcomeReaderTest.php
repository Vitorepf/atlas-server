<?php

namespace Tests\Unit\Ai\Rivals;

use App\Services\Ai\EngineeringKernel\EngineeringRoleRoster;
use App\Services\Ai\Rivals\Core\AdversarialAdjudicationGate;
use App\Services\Ai\Rivals\Core\TrialOutcomeReader;
use Tests\TestCase;

class TrialOutcomeReaderTest extends TestCase
{
    private function obs(string $window, string $status): array
    {
        return ['window' => $window, 'metrics' => ['status' => $status], 'release_hash' => str_repeat('a', 64)];
    }

    public function test_healthy_windows_up_to_required_are_observed_and_elapsed(): void
    {
        $out = (new TrialOutcomeReader)->read([
            $this->obs('0h', 'healthy'),
            $this->obs('24h', 'healthy'),
            $this->obs('7d', 'healthy'),
            $this->obs('30d', 'healthy'),
        ], '30d');

        $this->assertTrue($out['observed']);
        $this->assertTrue($out['elapsed']);
        $this->assertFalse($out['contradictory']);
    }

    public function test_missing_required_window_is_not_elapsed(): void
    {
        $out = (new TrialOutcomeReader)->read([
            $this->obs('0h', 'healthy'),
            $this->obs('24h', 'healthy'),
        ], '30d');

        $this->assertTrue($out['observed']);
        $this->assertFalse($out['elapsed']);
    }

    public function test_disagreeing_windows_are_flagged_contradictory(): void
    {
        $out = (new TrialOutcomeReader)->read([
            $this->obs('0h', 'healthy'),
            $this->obs('7d', 'regressed'),
        ], '7d');

        $this->assertTrue($out['contradictory']);
    }

    public function test_no_observations_is_unobserved(): void
    {
        $out = (new TrialOutcomeReader)->read([], '30d');
        $this->assertFalse($out['observed']);
        $this->assertFalse($out['elapsed']);
    }

    public function test_reader_output_feeds_the_adjudication_gate_outcome_bundle(): void
    {
        $reader = new TrialOutcomeReader;
        $unelapsed = $reader->read([$this->obs('0h', 'healthy')], '30d');

        // an unelapsed outcome keeps the gate claim-ineligible with residual uncertainty
        $out = (new AdversarialAdjudicationGate)->evaluate([
            'risk_class' => 'R3',
            'verdict_green' => true,
            'judge' => ['model_family' => 'gemini', 'saw_author_defense' => false],
            'author' => ['model_family' => 'claude'],
            'oracles' => ['hidden_tests' => true, 'implementation_independent' => true, 'security' => 'passed', 'replay' => 'passed'],
            'outcome' => $unelapsed,
            'role_dispositions' => array_fill_keys(
                EngineeringRoleRoster::CANONICAL_ROLES,
                ['status' => 'pass'],
            ),
        ]);

        $this->assertFalse($out['claim_eligible']);
        $this->assertNotEmpty($out['uncertainty']);
    }
}
