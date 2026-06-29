<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the task-packet spec validator is live at the operator surface and emits deterministic facts: a
 * complete spec is self-sufficient with no blockers; an empty spec is not, surfacing the structural blockers.
 * A missing --spec is a usage error.
 */
final class AtlasLoopSpecValidateCommandTest extends TestCase
{
    public function test_requires_spec(): void
    {
        $exit = Artisan::call('atlas:loop:spec-validate', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('usage_error', $decoded['status']);
    }

    public function test_complete_spec_is_self_sufficient(): void
    {
        $decoded = $this->validate([
            'objective' => 'Arm the dormant X by creating a read-only command',
            'allowed_files' => ['app/Console/Commands/Foo.php', 'tests/Feature/FooTest.php'],
            'scope_in' => ['app/Console/Commands/Foo.php'],
            'acceptance_criteria' => ['php artisan test --filter=FooTest passes'],
            'required_evidence' => ['tests_or_gates_result'],
            'rollback_hint' => 'git revert the commit',
            'workspace_policy' => ['execution_topology' => 'shared_local_main_with_scope_lock'],
            'simplicity_contract' => 'atlas_native',
        ]);

        $this->assertSame('atlas.taskfabric.packet_spec_validation.v1', $decoded['schema']);
        $this->assertTrue($decoded['self_sufficient']);
        $this->assertSame([], $decoded['blockers']);
    }

    public function test_empty_spec_surfaces_blockers(): void
    {
        $decoded = $this->validate([]);

        $this->assertFalse($decoded['self_sufficient']);
        $this->assertContains('missing_objective', $decoded['blockers']);
        $this->assertContains('missing_allowed_files', $decoded['blockers']);
    }

    /**
     * @param  array<string,mixed>  $spec
     * @return array<string,mixed>
     */
    private function validate(array $spec): array
    {
        $exit = Artisan::call('atlas:loop:spec-validate', [
            '--spec' => json_encode((object) $spec),
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);

        return json_decode(trim(Artisan::output()), true);
    }
}
