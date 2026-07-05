<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\GoalValue;

use App\Services\Ai\SelfConstruction\GoalValue\AtlasSelfConstructionGoalToCapabilityTraceMapper;
use Tests\TestCase;

final class AtlasSelfConstructionGoalToCapabilityTraceMapperTest extends TestCase
{
    private function mapper(): AtlasSelfConstructionGoalToCapabilityTraceMapper
    {
        return new AtlasSelfConstructionGoalToCapabilityTraceMapper;
    }

    private function validCandidate(): array
    {
        return [
            'objective' => 'Implement the Foo service with bar method',
            'capability' => 'task_serving',
            'touched_organ' => 'AgentControlPlane',
            'runnable_proof' => 'php artisan test tests/Unit/FooTest.php',
        ];
    }

    // ── AC: each accepted task links objective, capability, touched organ and runnable proof ──

    public function test_valid_candidate_passes(): void
    {
        $result = $this->mapper()->map($this->validCandidate());

        $this->assertTrue($result['passed']);
        $this->assertSame([], $result['failures']);
        $this->assertFalse($result['is_slogan_only']);
    }

    // ── AC: slogan-only candidates fail ──

    public function test_missing_objective_fails(): void
    {
        $candidate = $this->validCandidate();
        $candidate['objective'] = '';

        $result = $this->mapper()->map($candidate);

        $this->assertFalse($result['passed']);
        $this->assertContains('objective_missing_or_too_short', $result['failures']);
    }

    public function test_short_objective_fails(): void
    {
        $candidate = $this->validCandidate();
        $candidate['objective'] = 'short';

        $result = $this->mapper()->map($candidate);

        $this->assertFalse($result['passed']);
    }

    public function test_missing_capability_fails(): void
    {
        $candidate = $this->validCandidate();
        $candidate['capability'] = '';

        $result = $this->mapper()->map($candidate);

        $this->assertFalse($result['passed']);
        $this->assertContains('capability_not_named', $result['failures']);
    }

    public function test_missing_touched_organ_fails(): void
    {
        $candidate = $this->validCandidate();
        $candidate['touched_organ'] = '';

        $result = $this->mapper()->map($candidate);

        $this->assertFalse($result['passed']);
        $this->assertContains('touched_organ_not_named', $result['failures']);
    }

    public function test_missing_runnable_proof_fails(): void
    {
        $candidate = $this->validCandidate();
        $candidate['runnable_proof'] = '';

        $result = $this->mapper()->map($candidate);

        $this->assertFalse($result['passed']);
        $this->assertContains('runnable_proof_missing', $result['failures']);
    }

    public function test_unrecognized_runnable_proof_fails(): void
    {
        $candidate = $this->validCandidate();
        $candidate['runnable_proof'] = 'code review passes';

        $result = $this->mapper()->map($candidate);

        $this->assertFalse($result['passed']);
        $this->assertContains('runnable_proof_not_recognized', $result['failures']);
    }

    // ── batch ──

    public function test_batch_mapping(): void
    {
        $result = $this->mapper()->mapBatch([
            $this->validCandidate(),
            array_merge($this->validCandidate(), ['capability' => '']),
        ]);

        $this->assertSame(2, $result['total']);
        $this->assertSame(1, $result['passed']);
        $this->assertSame(1, $result['failed']);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->mapper()->map($this->validCandidate());

        $this->assertSame(AtlasSelfConstructionGoalToCapabilityTraceMapper::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('passed', $result);
        $this->assertArrayHasKey('failures', $result);
        $this->assertArrayHasKey('is_slogan_only', $result);
    }

    public function test_result_is_deterministic(): void
    {
        $candidate = $this->validCandidate();
        $a = $this->mapper()->map($candidate);
        $b = $this->mapper()->map($candidate);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
