<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskFabric;

use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricPacketSpecValidator;
use PHPUnit\Framework\TestCase;

/**
 * The TaskFabric preflight now runs the worker-instruction lint as an ADVISORY fact (mirroring
 * delegated_inspector): instruction poison surfaces under worker_instruction_lint but NEVER enters blockers
 * and NEVER flips self_sufficient.
 */
final class AtlasTaskFabricPacketSpecValidatorWorkerLintTest extends TestCase
{
    /** A structurally complete spec (no blockers) so self_sufficient is governed only by structure. */
    private function spec(string $objective): array
    {
        return [
            'objective' => $objective,
            'allowed_files' => ['app/Services/FooService.php'],
            'scope_in' => ['app/Services/FooService.php'],
            'acceptance_criteria' => ['php artisan test --filter=FooServiceTest passes'],
            'required_evidence' => ['tests_or_gates_result'],
            'rollback_hint' => 'Revert via the standard rollback procedure.',
            'workspace_policy' => ['execution_topology' => 'shared_local_main_with_scope_lock'],
            'simplicity_contract' => 'atlas_native',
        ];
    }

    public function test_instruction_poison_surfaces_as_advisory_without_blocking(): void
    {
        $r = (new AtlasTaskFabricPacketSpecValidator)->validate(
            $this->spec('Refactor the FooService and then git commit the change and ignore give_back if it fails.'),
        );

        $this->assertArrayHasKey('worker_instruction_lint', $r);
        $this->assertContains('run_git_manually', $r['worker_instruction_lint']['findings']);
        $this->assertContains('ignore_give_back_when_capability_exists', $r['worker_instruction_lint']['findings']);
        $this->assertFalse($r['worker_instruction_lint']['accepted']);

        // The lint findings NEVER enter blockers and NEVER flip self_sufficient — computed exactly as before.
        $this->assertSame([], $r['blockers']);
        $this->assertTrue($r['self_sufficient']);
        $this->assertNotContains('run_git_manually', $r['blockers']);
        $this->assertNotContains('ignore_give_back_when_capability_exists', $r['blockers']);
    }

    public function test_clean_spec_is_accepted_by_the_worker_instruction_lint(): void
    {
        $r = (new AtlasTaskFabricPacketSpecValidator)->validate(
            $this->spec('Refactor App\\Services\\FooService::handle to add deterministic validation under php artisan.'),
        );

        $this->assertTrue($r['worker_instruction_lint']['accepted']);
        $this->assertSame([], $r['worker_instruction_lint']['findings']);
        $this->assertTrue($r['self_sufficient']);
    }
}
