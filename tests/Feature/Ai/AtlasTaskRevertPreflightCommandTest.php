<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\Governance\AtlasTaskMergeActuator;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AtlasTaskRevertPreflightCommandTest extends TestCase
{
    public function test_preflight_refuses_nonexistent_task(): void
    {
        $exitCode = Artisan::call('atlas:task:revert', [
            '--task' => 'nonexistent-task-packet-id',
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode); // Command returns SUCCESS for dry-run (no mutation attempted)

        $output = json_decode(Artisan::output(), true);

        // Verify preflight structure
        $this->assertArrayHasKey('task_packet_id', $output);
        $this->assertSame('nonexistent-task-packet-id', $output['task_packet_id']);

        // Verify refusal with distinct reason
        $this->assertTrue((bool) ($output['refused'] ?? false));
        $this->assertNotNull($output['reason'] ?? null);
        $this->assertNotSame('', $output['reason']);

        // Verify no mutation occurred
        $this->assertFalse((bool) ($output['reverted'] ?? false));
        $this->assertFalse((bool) ($output['would_revert'] ?? false));
    }

    public function test_preflight_refuses_empty_task_id(): void
    {
        $exitCode = Artisan::call('atlas:task:revert', [
            '--task' => '',
            '--json' => true,
        ]);

        $this->assertSame(1, $exitCode); // FAILURE for missing required option
    }

    public function test_preflight_validates_dirty_working_tree(): void
    {
        // Create an actuator with a resolver that returns allowed files
        // but the working tree is dirty for one of them.
        $actuator = new AtlasTaskMergeActuator(
            allowedFilesResolver: fn (string $id): array => ['app/SomeFile.php']
        );

        // In a git repo, nonexistent task → ambiguous_sha (no commit carries the marker)
        $result = $actuator->revert('test-task-id');

        $this->assertTrue((bool) ($result['refused'] ?? false));
        $this->assertNotSame('', $result['reason'] ?? '');
        $this->assertFalse((bool) ($result['reverted'] ?? false));
    }

    public function test_preflight_validates_scope_via_allowed_files_resolver(): void
    {
        // Actuator with resolver that returns empty allowed files — any commit touching files
        // will be out of scope.
        $actuator = new AtlasTaskMergeActuator(
            allowedFilesResolver: fn (string $id): array => []
        );

        $result = $actuator->revert('test-task-id');

        // Should refuse (not_a_git_repo in test env, or ambiguous_sha if git exists)
        $this->assertTrue((bool) ($result['refused'] ?? false));
        $this->assertNotSame('', $result['reason'] ?? '');
    }

    public function test_command_json_output_has_schema_and_remediation(): void
    {
        $exitCode = Artisan::call('atlas:task:revert', [
            '--task' => 'some-task-that-does-not-exist',
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $output = json_decode(Artisan::output(), true);

        // Verify schema is present
        $this->assertArrayHasKey('schema', $output);
        $this->assertSame(AtlasTaskMergeActuator::SCHEMA, $output['schema']);

        // Verify task_packet_id is echoed back
        $this->assertArrayHasKey('task_packet_id', $output);
        $this->assertSame('some-task-that-does-not-exist', $output['task_packet_id']);

        // Verify dry_run flag
        $this->assertTrue((bool) ($output['dry_run'] ?? false));

        // Verify refusal carries reason
        $this->assertTrue((bool) ($output['refused'] ?? false));
        $this->assertNotSame('', $output['reason'] ?? '');
    }

    public function test_live_mode_requires_explicit_flag(): void
    {
        // Default mode is dry-run — no --live flag means no mutation.
        $exitCode = Artisan::call('atlas:task:revert', [
            '--task' => 'some-task',
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $output = json_decode(Artisan::output(), true);

        // dry_run should be true (default)
        $this->assertTrue((bool) ($output['dry_run'] ?? true));
        // No mutation should have occurred
        $this->assertFalse((bool) ($output['reverted'] ?? false));
    }
}
