<?php

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AtlasAiRuntimeBoundaryCommandTest extends TestCase
{
    public function test_runtime_boundary_command_exposes_ap201_status_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:runtime-boundary', [
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.runtime_language_boundary_report.v1', $payload['schema_version']);
        $this->assertSame('ok', $payload['status']);
        $this->assertTrue(data_get($payload, 'boundary.valid'));
        $this->assertSame(0, data_get($payload, 'boundary.violation_count'));
        $this->assertSame([], data_get($payload, 'boundary.violations'));
        $this->assertSame(
            'kernel_maestro_decides_and_governs',
            data_get($payload, 'doctrine.laravel'),
        );
        $this->assertSame(
            'rag_ml_embeddings_multimodal_analytics_only_behind_decision_receipt',
            data_get($payload, 'doctrine.python_ai_data'),
        );
        $this->assertContains('app/Services/Semantic', data_get($payload, 'protected_scopes'));
        $this->assertContains(
            'docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md',
            data_get($payload, 'owner_docs'),
        );
        $this->assertContains(
            'FAISS/Chroma/LlamaIndex/LangGraph/NetworkX in Laravel app',
            data_get($payload, 'forbidden_without_runtime_ap'),
        );
        $this->assertSame('atlas.runtime_invocation_contract.v1', data_get($payload, 'runtime_invocation_contract.schema_version'));
        $this->assertSame('atlas.runtime_boundary_preflight_gate.v1', data_get($payload, 'preflight_gate.schema_version'));
        $this->assertContains('run_feature_placement_strict', data_get($payload, 'preflight_gate.required_before_runtime_work'));
        $this->assertContains('skip_decision_receipt_for_runtime', data_get($payload, 'preflight_gate.forbidden_preflight_shortcuts'));
        $this->assertSame('atlas.runtime_promotion_policy.v1', data_get($payload, 'runtime_promotion_policy.schema_version'));
        $this->assertFalse(data_get($payload, 'runtime_promotion_policy.auto_promotion_allowed'));
        $this->assertTrue(data_get($payload, 'runtime_promotion_policy.rollback_plan_required'));
        $this->assertTrue(data_get($payload, 'runtime_invocation_contract.kernel_first'));
        $this->assertContains('decision_receipt_hash', data_get($payload, 'runtime_invocation_contract.required_fields'));
        $this->assertContains('evidence_sink', data_get($payload, 'runtime_invocation_contract.required_fields'));
        $this->assertContains('create_parallel_context_store', data_get($payload, 'runtime_invocation_contract.forbidden_runtime_authority'));
        $this->assertContains('python_ai_data', data_get($payload, 'runtime_invocation_contract.allowed_runtime_families'));
        $this->assertContains('runtimes/python', [data_get($payload, 'runtime_owner_map.python_ai_data.allowed_write_scope')]);
        $this->assertContains('runtimes/go', [data_get($payload, 'runtime_owner_map.go_edge.allowed_write_scope')]);
        $this->assertContains('runtimes/swift', [data_get($payload, 'runtime_owner_map.swift_native_mac.allowed_write_scope')]);
        $this->assertSame('php artisan atlas:ai:runtime-boundary --json', data_get($payload, 'surfaces.cli'));
        $this->assertSame('/ai/runtime-boundary', data_get($payload, 'surfaces.api'));
        $this->assertSame('atlas_runtime_boundary', data_get($payload, 'surfaces.mcp'));
        $this->assertFalse($payload['writes']);
        $this->assertSame(
            'runtime_boundary_clear_continue_with_feature_placement_before_runtime_work',
            $payload['next_action'],
        );
    }
}
