<?php

namespace Tests\Unit\Ai\Programming;

use App\Services\Ai\Programming\AtlasDevRuntimeService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Garantias do Atlas Dev Runtime (Meta 7):
 *  - workspace obrigatório para mode=programming;
 *  - flow normalizado para programming.dev/review/repair;
 *  - decision_mode coerente com provider explícito ou auto;
 *  - slice atlas_dev_runtime emitido com expected_artifacts canônicos;
 *  - surfaces fora do conjunto Atlas AI permanecem intocadas.
 */
class AtlasDevRuntimeServiceTest extends TestCase
{
    public function test_programming_without_workspace_is_rejected(): void
    {
        $service = new AtlasDevRuntimeService;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Workspace');

        $service->apply([
            'input_text' => 'corrigir bug X',
            'payload' => [
                'surface_id' => 'atlas_desktop_ai',
                'atlas_mode' => 'programming',
                'routing_task' => 'dev',
            ],
        ]);
    }

    public function test_dev_runtime_emits_canonical_slice_for_dev_flow(): void
    {
        $service = new AtlasDevRuntimeService;

        $result = $service->apply([
            'input_text' => 'feature pequena',
            'payload' => [
                'surface_id' => 'atlas_desktop_ai',
                'atlas_mode' => 'programming',
                'routing_task' => 'dev',
                'workspace' => '/repos/atlas',
                'decision_mode' => 'atlas_decide',
            ],
        ]);

        $slice = $result['payload']['atlas_dev_runtime'];

        $this->assertSame('atlas.dev_runtime.v1', $slice['schema_version']);
        $this->assertSame('programming.dev', $slice['flow_id']);
        $this->assertSame('programming', $slice['mode']);
        $this->assertSame('dev', $slice['task']);
        $this->assertSame('/repos/atlas', $slice['workspace']);
        $this->assertSame('atlas_decide', $slice['decision_mode']);
        $this->assertNull($slice['provider']);
        $this->assertSame(['plan', 'diff_or_reason', 'tests_or_reason', 'risks'], $slice['expected_artifacts']);
        $this->assertFalse($slice['requires_obra']);
        $this->assertSame('payload.workspace', $slice['workspace_source']);
    }

    public function test_debug_task_routes_to_repair_flow(): void
    {
        $service = new AtlasDevRuntimeService;

        $result = $service->apply([
            'input_text' => 'investigar bug',
            'payload' => [
                'surface_id' => 'atlas_app',
                'atlas_mode' => 'programming',
                'routing_task' => 'debug',
                'workspace' => '/repos/blackink',
            ],
        ]);

        $this->assertSame('programming.repair', $result['payload']['atlas_dev_runtime']['flow_id']);
        $this->assertSame('debug', $result['payload']['atlas_dev_runtime']['task']);
    }

    public function test_review_task_routes_to_review_flow(): void
    {
        $service = new AtlasDevRuntimeService;

        $result = $service->apply([
            'input_text' => 'revisar diff',
            'payload' => [
                'surface_id' => 'atlas_desktop_ai',
                'atlas_mode' => 'programming',
                'routing_task' => 'review',
                'workspace' => '/repos/atlas',
            ],
        ]);

        $this->assertSame('programming.review', $result['payload']['atlas_dev_runtime']['flow_id']);
    }

    public function test_manual_provider_yields_manual_override_decision_mode(): void
    {
        $service = new AtlasDevRuntimeService;

        $result = $service->apply([
            'input_text' => 'corrigir bug',
            'payload' => [
                'surface_id' => 'atlas_desktop_ai',
                'atlas_mode' => 'programming',
                'routing_task' => 'dev',
                'workspace' => '/repos/atlas',
                'decision_mode' => 'manual_override',
                'operator_requested_provider' => 'claude_cli',
            ],
        ]);

        $slice = $result['payload']['atlas_dev_runtime'];

        $this->assertSame('manual_override', $slice['decision_mode']);
        $this->assertSame('claude_cli', $slice['provider']);
    }

    public function test_legacy_manual_string_is_normalized_to_manual_override(): void
    {
        $service = new AtlasDevRuntimeService;

        $result = $service->apply([
            'input_text' => 'corrigir bug',
            'payload' => [
                'surface_id' => 'atlas_desktop_ai',
                'atlas_mode' => 'programming',
                'routing_task' => 'dev',
                'workspace' => '/repos/atlas',
                'decision_mode' => 'manual',
                'operator_requested_provider' => 'codex_cli',
            ],
        ]);

        $this->assertSame('manual_override', $result['payload']['atlas_dev_runtime']['decision_mode']);
        $this->assertSame('codex_cli', $result['payload']['atlas_dev_runtime']['provider']);
    }

    public function test_auto_provider_yields_atlas_decide_without_provider(): void
    {
        $service = new AtlasDevRuntimeService;

        $result = $service->apply([
            'input_text' => 'corrigir bug',
            'payload' => [
                'surface_id' => 'atlas_desktop_ai',
                'atlas_mode' => 'programming',
                'routing_task' => 'dev',
                'workspace' => '/repos/atlas',
                'operator_requested_provider' => 'auto',
            ],
        ]);

        $this->assertSame('atlas_decide', $result['payload']['atlas_dev_runtime']['decision_mode']);
        $this->assertNull($result['payload']['atlas_dev_runtime']['provider']);
    }

    public function test_non_programming_mode_is_ignored(): void
    {
        $service = new AtlasDevRuntimeService;

        $result = $service->apply([
            'input_text' => 'pergunta geral',
            'payload' => [
                'surface_id' => 'atlas_desktop_ai',
                'atlas_mode' => 'general',
            ],
        ]);

        $this->assertArrayNotHasKey('atlas_dev_runtime', $result['payload']);
    }

    public function test_atlas_code_surface_is_left_to_forge_binding(): void
    {
        $service = new AtlasDevRuntimeService;

        $result = $service->apply([
            'input_text' => 'forge run',
            'payload' => [
                'surface_id' => 'atlas_code',
                'atlas_mode' => 'programming',
                'routing_task' => 'dev',
            ],
        ]);

        $this->assertArrayNotHasKey('atlas_dev_runtime', $result['payload']);
    }

    public function test_workspace_can_come_from_tool_permissions(): void
    {
        $service = new AtlasDevRuntimeService;

        $result = $service->apply([
            'input_text' => 'feature',
            'payload' => [
                'surface_id' => 'atlas_desktop_ai',
                'atlas_mode' => 'programming',
                'routing_task' => 'dev',
                'tool_permissions' => ['workspace' => '/repos/atlas'],
            ],
        ]);

        $this->assertSame('/repos/atlas', $result['payload']['atlas_dev_runtime']['workspace']);
        $this->assertSame('payload.tool_permissions.workspace', $result['payload']['atlas_dev_runtime']['workspace_source']);
    }

    public function test_explicit_supported_flow_id_is_preserved(): void
    {
        $service = new AtlasDevRuntimeService;

        $result = $service->apply([
            'input_text' => 'review diff',
            'payload' => [
                'surface_id' => 'atlas_desktop_ai',
                'atlas_mode' => 'programming',
                'routing_task' => 'dev',
                'workspace' => '/repos/atlas',
                'flow_id' => 'programming.review',
            ],
        ]);

        $this->assertSame('programming.review', $result['payload']['atlas_dev_runtime']['flow_id']);
    }

    public function test_supported_flows_constant_is_stable(): void
    {
        $service = new AtlasDevRuntimeService;

        $this->assertSame(
            ['programming.dev', 'programming.review', 'programming.repair'],
            $service->supportedFlows(),
        );
    }
}
