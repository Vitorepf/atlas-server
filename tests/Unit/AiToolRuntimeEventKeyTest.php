<?php

namespace Tests\Unit;

use App\Services\Ai\Runtime\AiToolRuntime;
use App\Services\Ai\Runtime\ToolInvocation;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Fix 7c F2 — pin event_key generation as a deterministic, collision-resistant
 * function of (trace_id, tool, invocation_id, permission_status).
 *
 * Property under test: same inputs → same output (always); different inputs in
 * any of the 4 dimensions → different output (with overwhelming probability).
 *
 * Without this contract the UNIQUE event_key constraint added in
 * 2026_05_01_006000_harden_ai_tool_events would either be useless (all rows
 * unique by accident) or actively harmful (legitimate distinct events colliding).
 */
class AiToolRuntimeEventKeyTest extends TestCase
{
    public function test_same_inputs_produce_same_event_key(): void
    {
        $invocation = $this->invocation('inv-001', 'shell.run');

        $first = $this->callEventKeyFor('trace-A', $invocation, 'approved');
        $second = $this->callEventKeyFor('trace-A', $invocation, 'approved');

        $this->assertSame(
            $first,
            $second,
            'Same (trace, tool, invocation_id, status) MUST produce same event_key — '
                .'this is the entire point: idempotent retries collide on UNIQUE.'
        );
    }

    public function test_different_trace_id_produces_different_event_key(): void
    {
        $invocation = $this->invocation('inv-001', 'shell.run');

        $a = $this->callEventKeyFor('trace-A', $invocation, 'approved');
        $b = $this->callEventKeyFor('trace-B', $invocation, 'approved');

        $this->assertNotSame($a, $b,
            'Different traces invoking the same tool with the same invocation id (rare '
            .'but possible if id generator scope is global) must NOT collide.');
    }

    public function test_different_tool_produces_different_event_key(): void
    {
        $shellInv = $this->invocation('inv-shared', 'shell.run');
        $fileInv = $this->invocation('inv-shared', 'file.read');

        $a = $this->callEventKeyFor('trace-A', $shellInv, 'approved');
        $b = $this->callEventKeyFor('trace-A', $fileInv, 'approved');

        $this->assertNotSame($a, $b,
            'Defensive: if invocation_id is ever reused across tools (codepath bug), '
            .'tool name in the hash prevents silent overwrite.');
    }

    public function test_different_invocation_id_produces_different_event_key(): void
    {
        $a = $this->callEventKeyFor('trace-A', $this->invocation('inv-001', 'shell.run'), 'approved');
        $b = $this->callEventKeyFor('trace-A', $this->invocation('inv-002', 'shell.run'), 'approved');

        $this->assertNotSame($a, $b,
            'Two consecutive shell.run invocations in the same trace must produce '
            .'different keys — they are different events.');
    }

    public function test_denied_and_approved_for_same_invocation_have_different_keys(): void
    {
        // This is critical: AiToolRuntime calls recordToolEvent twice for the
        // permission-denied path: once at line 52 with status='denied', once
        // would be at line 84 if the call had succeeded. With permission_status
        // in the hash, the two paths produce distinct keys and both rows persist.
        // Without it, the second call would overwrite the first via updateOrCreate.
        $invocation = $this->invocation('inv-001', 'shell.run');

        $denied = $this->callEventKeyFor('trace-A', $invocation, 'denied');
        $approved = $this->callEventKeyFor('trace-A', $invocation, 'approved');

        $this->assertNotSame($denied, $approved,
            'Denial path and approval path for the same invocation must produce '
            .'different keys — both records carry distinct diagnostic value.');
    }

    public function test_event_key_format_is_namespaced_and_within_column_size(): void
    {
        $key = $this->callEventKeyFor('trace-A', $this->invocation('inv-001', 'shell.run'), 'approved');

        $this->assertStringStartsWith('tool:', $key,
            'Namespace prefix lets a future event_key column shared across event '
            .'types (telemetry, tool, etc.) avoid collisions on the same value.');
        $this->assertLessThanOrEqual(180, strlen($key),
            'Must fit in event_key column (varchar 180).');
        $this->assertSame(69, strlen($key), // 5 ('tool:') + 64 sha256 hex
            'Stable byte length proves the substr truncation is consistent.');
    }

    private function invocation(string $id, string $tool): ToolInvocation
    {
        return new ToolInvocation(
            id: $id,
            tool: $tool,
            workspace: '/tmp',
            arguments: [],
        );
    }

    /**
     * Calls the private eventKeyFor() method via reflection. Trade-off: this couples
     * the test to the method name, but the alternative (testing through the full
     * AiToolRuntime::execute) requires a deep dependency tree. Reflection is the
     * smallest surface that proves the algorithm.
     */
    private function callEventKeyFor(string $traceId, ToolInvocation $invocation, string $permissionStatus): string
    {
        $runtime = $this->app->make(AiToolRuntime::class);
        $method = new ReflectionMethod(AiToolRuntime::class, 'eventKeyFor');
        $method->setAccessible(true);

        return $method->invoke($runtime, $traceId, $invocation, $permissionStatus);
    }
}
