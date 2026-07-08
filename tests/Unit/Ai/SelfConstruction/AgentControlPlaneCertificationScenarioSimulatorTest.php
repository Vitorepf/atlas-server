<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneCertificationScenarioSimulator;
use Tests\TestCase;

final class AgentControlPlaneCertificationScenarioSimulatorTest extends TestCase
{
    private function simulator(): AgentControlPlaneCertificationScenarioSimulator
    {
        return $this->app->make(AgentControlPlaneCertificationScenarioSimulator::class);
    }

    public function test_incomplete_evidence_returns_fail_closed(): void
    {
        $result = $this->simulator()->simulateTaskServingScenarios([
            'scenarios' => [
                ['scenario_id' => 's1', 'kind' => 'stale_lease', 'observed_decision' => 'accept'],
            ],
        ]);

        $this->assertSame(1, $result['fail_closed_count']);
        $this->assertSame(0, $result['match_count']);
        $this->assertSame(0, $result['regression_count']);
        $this->assertSame(AgentControlPlaneCertificationScenarioSimulator::REGRESSION_INCOMPLETE_EVIDENCE_FAIL_CLOSED, $result['results'][0]['regression_status']);
        $this->assertNull($result['results'][0]['expected_decision']);
    }

    public function test_stale_lease_detects_regression_when_expected_blocker_absent(): void
    {
        $result = $this->simulator()->simulateTaskServingScenarios([
            'scenarios' => [
                [
                    'scenario_id' => 's1',
                    'kind' => 'stale_lease',
                    'lease_expires_at_is_past' => true,
                    'observed_decision' => 'accept', // should have been give_back
                ],
            ],
        ]);

        $this->assertSame(1, $result['regression_count']);
        $this->assertSame(0, $result['match_count']);
        $this->assertSame('give_back', $result['results'][0]['expected_decision']);
        $this->assertSame('accept', $result['results'][0]['observed_decision']);
        $this->assertSame(AgentControlPlaneCertificationScenarioSimulator::REGRESSION_REGRESSION, $result['results'][0]['regression_status']);
    }

    public function test_stale_lease_matches_when_give_back_observed(): void
    {
        $result = $this->simulator()->simulateTaskServingScenarios([
            'scenarios' => [
                [
                    'scenario_id' => 's1',
                    'kind' => 'stale_lease',
                    'lease_expires_at_is_past' => true,
                    'observed_decision' => 'give_back',
                ],
            ],
        ]);

        $this->assertSame(1, $result['match_count']);
        $this->assertSame(0, $result['regression_count']);
        $this->assertSame(AgentControlPlaneCertificationScenarioSimulator::REGRESSION_MATCH, $result['results'][0]['regression_status']);
    }

    public function test_duplicate_target_detects_regression_when_expected_blocker_absent(): void
    {
        $result = $this->simulator()->simulateTaskServingScenarios([
            'scenarios' => [
                [
                    'scenario_id' => 's1',
                    'kind' => 'duplicate_target',
                    'target_in_existing_queue' => true,
                    'observed_decision' => 'accept', // should have been give_back
                ],
            ],
        ]);

        $this->assertSame(1, $result['regression_count']);
        $this->assertSame('give_back', $result['results'][0]['expected_decision']);
        $this->assertSame(AgentControlPlaneCertificationScenarioSimulator::REGRESSION_REGRESSION, $result['results'][0]['regression_status']);
    }

    public function test_missing_proof_detects_regression_when_expected_blocker_absent(): void
    {
        $result = $this->simulator()->simulateTaskServingScenarios([
            'scenarios' => [
                [
                    'scenario_id' => 's1',
                    'kind' => 'missing_proof',
                    'required_evidence_present' => false,
                    'observed_decision' => 'accept', // should have been reject
                ],
            ],
        ]);

        $this->assertSame(1, $result['regression_count']);
        $this->assertSame('reject', $result['results'][0]['expected_decision']);
        $this->assertSame(AgentControlPlaneCertificationScenarioSimulator::REGRESSION_REGRESSION, $result['results'][0]['regression_status']);
    }

    public function test_clean_happy_path_matches_when_all_evidence_present(): void
    {
        $result = $this->simulator()->simulateTaskServingScenarios([
            'scenarios' => [
                [
                    'scenario_id' => 's1',
                    'kind' => 'clean_happy_path',
                    'lease_expires_at_is_past' => false,
                    'target_in_existing_queue' => false,
                    'required_evidence_present' => true,
                    'observed_decision' => 'accept',
                ],
            ],
        ]);

        $this->assertSame(1, $result['match_count']);
        $this->assertSame(0, $result['regression_count']);
        $this->assertSame(0, $result['fail_closed_count']);
        $this->assertSame('accept', $result['results'][0]['expected_decision']);
        $this->assertSame(AgentControlPlaneCertificationScenarioSimulator::REGRESSION_MATCH, $result['results'][0]['regression_status']);
    }

    public function test_clean_happy_path_fail_closed_when_evidence_incomplete(): void
    {
        $result = $this->simulator()->simulateTaskServingScenarios([
            'scenarios' => [
                [
                    'scenario_id' => 's1',
                    'kind' => 'clean_happy_path',
                    'lease_expires_at_is_past' => false,
                    'target_in_existing_queue' => false,
                    // required_evidence_present missing
                    'observed_decision' => 'accept',
                ],
            ],
        ]);

        $this->assertSame(1, $result['fail_closed_count']);
        $this->assertSame(AgentControlPlaneCertificationScenarioSimulator::REGRESSION_INCOMPLETE_EVIDENCE_FAIL_CLOSED, $result['results'][0]['regression_status']);
    }

    public function test_worker_mismatch_detects_regression(): void
    {
        $result = $this->simulator()->simulateTaskServingScenarios([
            'scenarios' => [
                [
                    'scenario_id' => 's1',
                    'kind' => 'worker_mismatch',
                    'required_capabilities' => ['php', 'ai'],
                    'worker_capabilities' => ['php'],
                    'observed_decision' => 'accept', // should have been reject
                ],
            ],
        ]);

        $this->assertSame(1, $result['regression_count']);
        $this->assertSame('reject', $result['results'][0]['expected_decision']);
        $this->assertSame(AgentControlPlaneCertificationScenarioSimulator::REGRESSION_REGRESSION, $result['results'][0]['regression_status']);
    }

    public function test_worker_mismatch_matches_when_capabilities_aligned(): void
    {
        $result = $this->simulator()->simulateTaskServingScenarios([
            'scenarios' => [
                [
                    'scenario_id' => 's1',
                    'kind' => 'worker_mismatch',
                    'required_capabilities' => ['php', 'ai'],
                    'worker_capabilities' => ['php', 'ai'],
                    'observed_decision' => 'accept',
                ],
            ],
        ]);

        $this->assertSame(1, $result['match_count']);
        $this->assertSame(AgentControlPlaneCertificationScenarioSimulator::REGRESSION_MATCH, $result['results'][0]['regression_status']);
    }

    public function test_output_has_required_keys(): void
    {
        $result = $this->simulator()->simulateTaskServingScenarios([
            'scenarios' => [],
        ]);

        foreach (['schema_version', 'results', 'match_count', 'regression_count', 'fail_closed_count'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
    }
}
