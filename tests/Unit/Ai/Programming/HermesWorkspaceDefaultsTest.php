<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming;

use App\Services\Ai\Programming\HermesWorkspaceDefaults;
use PHPUnit\Framework\TestCase;

/**
 * GUARANTEE tests: lock Hermes into its strongest, fully-autonomous AGENT mode
 * for every Atlas path that runs it as a workspace editor. If any of these flip,
 * CI fails — that is the operator's guarantee that Atlas never silently downgrades
 * Hermes to a weaker (answer-only) mode.
 */
final class HermesWorkspaceDefaultsTest extends TestCase
{
    public function test_tool_permissions_always_request_autonomous_danger_mode(): void
    {
        // 'danger' is the ONLY mode for which HermesCliProvider passes --yolo, which
        // is what turns Hermes into the autonomous AGENT that edits files via tools.
        // 'write' would make Hermes answer but never mutate; 'read' is weaker still.
        $perms = HermesWorkspaceDefaults::toolPermissions('/tmp/ws');

        $this->assertSame('danger', $perms['mode'], 'Hermes workspace edits MUST run in the autonomous agent (--yolo) mode.');
        $this->assertSame('/tmp/ws', $perms['workspace']);
        $this->assertNotSame('write', $perms['mode']);
        $this->assertNotSame('read', $perms['mode']);
    }

    public function test_model_is_the_self_select_sentinel_so_hermes_runs_its_strongest_model(): void
    {
        // The `_default` suffix makes HermesCliProvider omit --model, so Hermes runs
        // its OWN configured strongest model/profile (gpt-5.5/codex) instead of being
        // handed a downgraded or non-Hermes family.
        $model = HermesWorkspaceDefaults::model();

        $this->assertNotSame('', $model);
        $this->assertStringEndsWith('_default', $model, 'Hermes must self-select its strongest model (sentinel ends in _default).');
    }
}
