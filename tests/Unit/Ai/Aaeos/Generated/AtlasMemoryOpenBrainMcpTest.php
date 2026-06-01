<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasMemoryOpenBrainMcpService;
use Tests\TestCase;

/**
 * Pins the Open Brain access boundary, required export/injection shapes and the
 * Operational Rule routing from the doc. Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/memory/open-brain-mcp.md
 */
class AtlasMemoryOpenBrainMcpTest extends TestCase
{
    private function service(): AtlasMemoryOpenBrainMcpService
    {
        return new AtlasMemoryOpenBrainMcpService;
    }

    /** Returns a minimal export that satisfies the Required Export Shape. */
    private function validExport(): array
    {
        return [
            'schema_version' => 'v1',
            'requester_surface' => 'mcp_consumer',
            'workspace_task_hints' => ['workspace' => 'ws-hash'],
            'policy_result' => 'auto',
            'context_refs' => [],
            'provider_safe_summaries' => [],
            'truncation_budget_metadata' => ['budget' => 1000],
            'audit_ref' => 'trace-abc',
            'generated_at' => '2026-06-01T00:00:00Z',
            'safety' => [
                'schema' => AtlasMemoryOpenBrainMcpService::CONTEXT_PACK_SAFETY_SCHEMA,
                'provider_safe_only' => true,
                'raw_content_exposed' => false,
                'raw_content_persisted' => false,
                'audit_query_raw_content_persisted' => false,
                'raw_content_persisted_count' => 0,
            ],
        ];
    }

    public function test_boundary_allows_context_export_but_forbids_decision_and_promotion(): void
    {
        $svc = $this->service();

        // "It can" — build/export context packs.
        $this->assertTrue($svc->mayPerform('build_export_context_packs'));
        $this->assertTrue($svc->mayPerform('return_docs_code_memory_refs'));

        // "It cannot" — choose providers, execute tools, promote memory silently.
        $this->assertFalse($svc->mayPerform('choose_providers'));
        $this->assertFalse($svc->mayPerform('execute_runtime_tools'));
        $this->assertFalse($svc->mayPerform('promote_memory_silently'));
        $this->assertFalse($svc->mayPerform('become_source_of_truth'));

        // Closed boundary: an unknown capability is denied by default.
        $this->assertFalse($svc->mayPerform('anything_not_listed'));
    }

    public function test_valid_export_passes_required_shape_and_is_provider_safe(): void
    {
        $r = $this->service()->validateExport($this->validExport());

        $this->assertTrue($r['valid']);
        $this->assertTrue($r['provider_safe']);
        $this->assertSame([], $r['missing_fields']);
        $this->assertSame([], $r['violations']);
    }

    public function test_export_missing_required_fields_is_invalid(): void
    {
        $export = $this->validExport();
        unset($export['audit_ref'], $export['generated_at']);

        $r = $this->service()->validateExport($export);

        $this->assertFalse($r['valid']);
        $this->assertContains('audit_ref', $r['missing_fields']);
        $this->assertContains('generated_at', $r['missing_fields']);
    }

    public function test_export_that_exposes_or_persists_raw_content_is_blocked(): void
    {
        $export = $this->validExport();
        $export['safety']['raw_content_exposed'] = true;
        $export['safety']['raw_content_persisted'] = true;
        $export['safety']['raw_content_persisted_count'] = 3;

        $r = $this->service()->validateExport($export);

        $this->assertFalse($r['valid']);
        $this->assertContains('raw_content_exposed_must_be_false', $r['violations']);
        $this->assertContains('raw_content_persisted_must_be_false', $r['violations']);
        $this->assertContains('raw_content_persisted_count_must_be_zero', $r['violations']);
    }

    public function test_export_with_wrong_safety_schema_is_blocked(): void
    {
        $export = $this->validExport();
        $export['safety']['schema'] = 'something.else.v9';

        $r = $this->service()->validateExport($export);

        $this->assertFalse($r['valid']);
        $this->assertContains('safety_schema_must_be_context_pack_safety_v1', $r['violations']);
    }

    public function test_injection_result_is_never_a_decision_receipt(): void
    {
        $r = $this->service()->validateInjectionResult([
            'status' => 'injected',
            'context_pack_hash' => 'h1',
            'prompt_section_hash' => 'h2',
            'audit_ref' => 'trace-1',
            'ref_counts' => ['memory' => 2, 'docs' => 1, 'code' => 0, 'tool' => 0],
            'provider_safe' => true,
            'truncation' => ['truncated' => false],
            'warnings' => [],
        ]);

        $this->assertTrue($r['valid']);
        $this->assertSame('injected', $r['status']);
        // "It is not a Decision Receipt and does not authorize provider calls."
        $this->assertFalse($r['is_decision_receipt']);
        $this->assertFalse($r['authorizes_provider_calls']);
    }

    public function test_injection_result_with_unknown_status_is_invalid(): void
    {
        $r = $this->service()->validateInjectionResult([
            'status' => 'approved',
            'context_pack_hash' => 'h1',
            'prompt_section_hash' => 'h2',
            'audit_ref' => 'trace-1',
            'ref_counts' => [],
            'provider_safe' => true,
            'truncation' => [],
            'warnings' => [],
        ]);

        $this->assertFalse($r['valid']);
        $this->assertContains('status_must_be_a_known_injection_status', $r['violations']);
        // Even invalid, it can never authorize provider calls.
        $this->assertFalse($r['authorizes_provider_calls']);
    }

    public function test_operational_rule_routes_context_to_open_brain_and_mutations_to_kernel(): void
    {
        $svc = $this->service();

        $context = $svc->route('context');
        $this->assertSame(AtlasMemoryOpenBrainMcpService::TARGET_OPEN_BRAIN, $context['target']);
        $this->assertFalse($context['requires_decision_receipt']);

        foreach (['decide', 'execute', 'repair', 'learn', 'persist'] as $intent) {
            $routed = $svc->route($intent);
            $this->assertSame(
                AtlasMemoryOpenBrainMcpService::TARGET_KERNEL,
                $routed['target'],
                "intent {$intent} must route to the kernel",
            );
            $this->assertTrue($routed['requires_decision_receipt']);
        }
    }

    public function test_request_hints_require_valid_enums_and_workspace_objective(): void
    {
        $svc = $this->service();

        $ok = $svc->validateRequestHints([
            'surface' => 'cli_dev',
            'mode' => 'programming',
            'policy_mode' => 'required',
            'workspace' => '/repo',
            'objective' => 'implement feature',
        ]);
        $this->assertTrue($ok['valid']);

        $bad = $svc->validateRequestHints([
            'surface' => 'telepathy',
            'mode' => 'yolo',
            'policy_mode' => 'maybe',
            'workspace' => '',
            'objective' => '',
        ]);
        $this->assertFalse($bad['valid']);
        $this->assertContains('surface_must_be_a_supported_surface', $bad['violations']);
        $this->assertContains('mode_must_be_kernel_approved', $bad['violations']);
        $this->assertContains('policy_mode_must_be_off_auto_or_required', $bad['violations']);
        $this->assertContains('workspace_is_required', $bad['violations']);
        $this->assertContains('objective_is_required', $bad['violations']);
    }

    public function test_future_scope_capabilities_require_a_dedicated_ap(): void
    {
        $svc = $this->service();

        $this->assertTrue($svc->requiresAp('streamable_http_sse'));
        $this->assertTrue($svc->requiresAp('multiuser_open_brain_sync'));
        $this->assertTrue($svc->requiresAp('autonomous_memory_promotion'));

        // A capability already in scope is not gated behind an AP.
        $this->assertFalse($svc->requiresAp('build_export_context_packs'));

        // Future-scope items are also not performable today.
        $this->assertFalse($svc->mayPerform('streamable_http_sse'));
    }

    public function test_manifest_pins_boundary_enums_and_invariants(): void
    {
        $m = $this->service()->manifest();

        $this->assertCount(9, $m['required_export_fields']);
        $this->assertCount(8, $m['required_injection_fields']);
        $this->assertCount(5, $m['injection_statuses']);
        $this->assertCount(7, $m['surfaces']);
        $this->assertCount(5, $m['kernel_intents']);
        $this->assertSame('access_layer_not_independent_authority', $m['authority']);
        $this->assertContains('injection_result_is_not_a_decision_receipt', $m['invariants']);
        $this->assertContains('raw_content_never_exposed_or_persisted', $m['invariants']);
    }
}
