<?php

declare(strict_types=1);

namespace Tests\Feature\CodeGraph;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * AP-815 · I-4 (Stage 2) — the MCP server defaults its workspace to the REAL indexed
 * primary (base_path → the stable 'atlas-server' id via CodeGraphWorkspaceIdentity) when
 * no --workspace is passed, instead of leaking a stale/foreign config('atlas.ai.workdir')
 * value that resolves to a workspace holding zero symbols.
 *
 * Proven through the --describe --json surface (no provider tokens, no DB writes): the
 * reported workspace/workspace_id must be the primary by default, and an explicit
 * --workspace must still be honoured verbatim.
 */
final class AtlasOpenBrainMcpCommandWorkspaceTest extends TestCase
{
    /**
     * @return array<string,mixed>
     */
    private function describe(array $params = []): array
    {
        $params['--describe'] = true;
        $params['--json'] = true;

        $exit = Artisan::call('atlas:open-brain:mcp', $params);
        $this->assertSame(0, $exit, '--describe --json must exit 0');

        $decoded = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($decoded, '--describe --json must emit a JSON object');

        return $decoded;
    }

    public function test_default_workspace_resolves_to_the_real_primary_not_a_stale_config_path(): void
    {
        // Simulate the stale/foreign value the command previously leaked into the workspace.
        config()->set('atlas.ai.workdir', '/Users/someone/Develop/some-other-repo');

        $payload = $this->describe();

        // The reported workspace is the running app's primary, NOT the stale config path...
        $this->assertSame(base_path(), $payload['workspace']);
        $this->assertNotSame('/Users/someone/Develop/some-other-repo', $payload['workspace']);
        // ...and it resolves to the stable primary id the code-graph read-model is keyed by.
        $this->assertSame('atlas-server', $payload['workspace_id']);
        // The code-graph find tool is advertised in the describe surface.
        $this->assertContains('atlas_code_find_relevant', $payload['tools']);
    }

    public function test_explicit_workspace_option_is_still_honoured(): void
    {
        $explicit = sys_get_temp_dir();

        $payload = $this->describe(['--workspace' => $explicit]);

        $this->assertSame(realpath($explicit) ?: $explicit, $payload['workspace']);
        // A non-primary path gets its OWN resolved id (never the primary default).
        $this->assertNotSame('atlas-server', $payload['workspace_id']);
    }
}
