<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneCertificationMutationGuard;
use PHPUnit\Framework\TestCase;

final class AgentControlPlaneCertificationMutationGuardTest extends TestCase
{
    private function guard(): AgentControlPlaneCertificationMutationGuard
    {
        $ref = new \ReflectionClass(AgentControlPlaneCertificationMutationGuard::class);

        return $ref->newInstanceWithoutConstructor();
    }

    private function mutation(string $mutationId, string $targetCheck, string $kind, bool $stillBlocking): array
    {
        return [
            'mutation_id' => $mutationId,
            'target_check' => $targetCheck,
            'kind' => $kind,
            'still_blocking' => $stillBlocking,
        ];
    }

    public function test_removed_critical_check_is_killed_when_still_blocking(): void
    {
        $result = $this->guard()->evaluateMutations([
            'mutations' => [
                $this->mutation('m1', 'scope_check', AgentControlPlaneCertificationMutationGuard::MUTATION_KIND_REMOVED, true),
            ],
        ]);

        $this->assertSame(1, $result['killed_count']);
        $this->assertSame(0, $result['survived_count']);
        $this->assertSame(1.0, $result['kill_ratio']);
        $this->assertTrue($result['results'][0]['killed']);
        $this->assertFalse($result['results'][0]['survived']);
    }

    public function test_removed_critical_check_survives_when_not_blocking(): void
    {
        $result = $this->guard()->evaluateMutations([
            'mutations' => [
                $this->mutation('m1', 'scope_check', AgentControlPlaneCertificationMutationGuard::MUTATION_KIND_REMOVED, false),
            ],
        ]);

        $this->assertSame(0, $result['killed_count']);
        $this->assertSame(1, $result['survived_count']);
        $this->assertSame(0.0, $result['kill_ratio']);
        $this->assertFalse($result['results'][0]['killed']);
        $this->assertTrue($result['results'][0]['survived']);
        $this->assertNotNull($result['results'][0]['required_test_gap']);
    }

    public function test_weakened_to_advisory_is_killed_when_still_blocking(): void
    {
        $result = $this->guard()->evaluateMutations([
            'mutations' => [
                $this->mutation('m1', 'proof_check', AgentControlPlaneCertificationMutationGuard::MUTATION_KIND_WEAKENED_ADVISORY, true),
            ],
        ]);

        $this->assertSame(1, $result['killed_count']);
        $this->assertTrue($result['results'][0]['killed']);
    }

    public function test_weakened_to_advisory_survives_when_not_blocking(): void
    {
        $result = $this->guard()->evaluateMutations([
            'mutations' => [
                $this->mutation('m1', 'proof_check', AgentControlPlaneCertificationMutationGuard::MUTATION_KIND_WEAKENED_ADVISORY, false),
            ],
        ]);

        $this->assertSame(1, $result['survived_count']);
        $this->assertTrue($result['results'][0]['survived']);
        $this->assertStringContainsString('advisory-only', $result['results'][0]['required_test_gap']);
    }

    public function test_harmless_refactor_is_neither_killed_nor_survived(): void
    {
        $result = $this->guard()->evaluateMutations([
            'mutations' => [
                $this->mutation('m1', 'scope_check', AgentControlPlaneCertificationMutationGuard::MUTATION_KIND_HARMLESS_REFACTOR, false),
            ],
        ]);

        $this->assertSame(0, $result['killed_count']);
        $this->assertSame(0, $result['survived_count']);
        $this->assertSame(1, $result['harmless_refactor_count']);
        $this->assertTrue($result['results'][0]['harmless_refactor']);
        $this->assertFalse($result['results'][0]['killed']);
        $this->assertFalse($result['results'][0]['survived']);
        $this->assertNull($result['results'][0]['required_test_gap']);
    }

    public function test_all_critical_checks_can_be_evaluated(): void
    {
        $mutations = [];
        foreach (AgentControlPlaneCertificationMutationGuard::CRITICAL_CHECK_IDS as $checkId) {
            $mutations[] = $this->mutation($checkId.'_removed', $checkId, AgentControlPlaneCertificationMutationGuard::MUTATION_KIND_REMOVED, false);
        }

        $result = $this->guard()->evaluateMutations(['mutations' => $mutations]);

        $this->assertCount(5, $result['results']);
        $this->assertSame(5, $result['survived_count']);
        $this->assertSame(0.0, $result['kill_ratio']);
    }

    public function test_output_includes_mutation_kind_target_check_and_blocking_reason(): void
    {
        $result = $this->guard()->evaluateMutations([
            'mutations' => [
                $this->mutation('m1', 'freshness_check', AgentControlPlaneCertificationMutationGuard::MUTATION_KIND_REMOVED, false),
            ],
        ]);

        $r = $result['results'][0];
        $this->assertSame('m1', $r['mutation_id']);
        $this->assertSame('freshness_check', $r['target_check']);
        $this->assertSame(AgentControlPlaneCertificationMutationGuard::MUTATION_KIND_REMOVED, $r['kind']);
        $this->assertTrue($r['survived']);
        $this->assertNotNull($r['required_test_gap']);
        $this->assertStringContainsString('freshness_check', $r['required_test_gap']);
    }

    public function test_harmless_refactor_does_not_block_kill_ratio(): void
    {
        $result = $this->guard()->evaluateMutations([
            'mutations' => [
                $this->mutation('m1', 'scope_check', AgentControlPlaneCertificationMutationGuard::MUTATION_KIND_REMOVED, false),
                $this->mutation('m2', 'proof_check', AgentControlPlaneCertificationMutationGuard::MUTATION_KIND_HARMLESS_REFACTOR, false),
            ],
        ]);

        $this->assertSame(0, $result['killed_count']);
        $this->assertSame(1, $result['survived_count']);
        $this->assertSame(1, $result['harmless_refactor_count']);
        $this->assertSame(0.0, $result['kill_ratio']);
    }

    public function test_empty_mutations_yield_perfect_kill_ratio(): void
    {
        $result = $this->guard()->evaluateMutations([]);

        $this->assertSame([], $result['results']);
        $this->assertSame(0, $result['killed_count']);
        $this->assertSame(0, $result['survived_count']);
        $this->assertSame(1.0, $result['kill_ratio']);
    }
}
