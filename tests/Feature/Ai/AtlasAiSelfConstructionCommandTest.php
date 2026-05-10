<?php

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AtlasAiSelfConstructionCommandTest extends TestCase
{
    public function test_command_returns_self_construction_readiness_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_readiness.v1', data_get($payload, 'schema_version'));
        $this->assertSame('read_only_advisory', data_get($payload, 'mode'));
        $this->assertSame('ready_for_phase_2', data_get($payload, 'status'));
        $this->assertSame('phase_2_read_only_gap_report', data_get($payload, 'summary.runtime_phase'));
        $this->assertFalse(data_get($payload, 'summary.autonomous_execution_allowed'));
        $this->assertFalse(data_get($payload, 'safety_contract.self_programming_allowed'));
        $this->assertFalse(data_get($payload, 'safety_contract.write_tools_allowed'));
        $this->assertSame(0, data_get($payload, 'summary.missing_doc_count'));
        $this->assertGreaterThanOrEqual(13, data_get($payload, 'summary.required_doc_count'));
        $this->assertContains('spec_operating_system', data_get($payload, 'build_graph.prerequisites'));
        $this->assertContains('Meta-SDD artifact generator', collect(data_get($payload, 'next_safe_blocks'))->pluck('block')->all());
        $this->assertContains('php artisan atlas:ai:self-construction --traceability --json', data_get($payload, 'recommended_commands'));
        $this->assertContains('git diff --check', data_get($payload, 'recommended_commands'));
    }

    public function test_command_human_output_lists_safe_blocks(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction');
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Self-programming allowed', $output);
        $this->assertStringContainsString('Next safe blocks', $output);
        $this->assertStringContainsString('Meta-SDD artifact generator', $output);
    }

    public function test_command_returns_meta_sdd_candidate_packet_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--meta-sdd' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_meta_sdd.v1', data_get($payload, 'schema_version'));
        $this->assertSame('candidate_ready', data_get($payload, 'status'));
        $this->assertSame('read_only_candidate', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertSame('0.8-self-construction', data_get($payload, 'meta_spec.target_layer'));
        $this->assertSame('meta_sdd_artifact_generator', data_get($payload, 'meta_spec.target_capability'));
        $this->assertSame('L2_meta_sdd_artifact_generator', data_get($payload, 'meta_spec.target_maturity'));
        $this->assertContains('no code patch execution', data_get($payload, 'meta_spec.non_goals'));
        $this->assertSame('P0', data_get($payload, 'priority.p_level'));
        $this->assertGreaterThanOrEqual(3, count(data_get($payload, 'tasks')));
        $this->assertContains('php artisan atlas:ai:self-construction --traceability --json', data_get($payload, 'required_gates'));
        $this->assertContains('php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php', data_get($payload, 'required_gates'));
        $this->assertFalse(data_get($payload, 'safety_contract.self_programming_allowed'));
    }

    public function test_command_human_output_lists_meta_sdd_candidate(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--meta-sdd' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Execution allowed', $output);
        $this->assertStringContainsString('Target capability', $output);
        $this->assertStringContainsString('Candidate Meta-SDD packet', $output);
    }

    public function test_command_returns_receipt_preview_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--receipt-preview' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_receipt_preview.v1', data_get($payload, 'schema_version'));
        $this->assertSame('preview_ready', data_get($payload, 'status'));
        $this->assertSame('receipt_preview_only', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertSame('DR-PREVIEW-SELF-CONSTRUCTION-PHASE-4', data_get($payload, 'receipt_preview.id'));
        $this->assertSame('L0_preview_only', data_get($payload, 'receipt_preview.autonomy_level'));
        $this->assertContains('generate_receipt_preview', data_get($payload, 'receipt_preview.scope.allowed_actions'));
        $this->assertContains('enable_self_programming_writes', data_get($payload, 'receipt_preview.scope.forbidden_actions'));
        $this->assertContains('runtimes/python/voice_realtime/**', data_get($payload, 'receipt_preview.scope.forbidden_files'));
        $this->assertContains('php artisan atlas:ai:self-construction --traceability --json', data_get($payload, 'receipt_preview.gates.required'));
        $this->assertContains('php artisan migrate', data_get($payload, 'receipt_preview.scope.forbidden_commands'));
        $this->assertTrue(data_get($payload, 'receipt_preview.evidence.append_only'));
    }

    public function test_command_human_output_lists_receipt_preview(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--receipt-preview' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Receipt', $output);
        $this->assertStringContainsString('Execution allowed', $output);
        $this->assertStringContainsString('Receipt preview is ready', $output);
    }

    public function test_command_returns_traceability_audit_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--traceability' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_traceability_audit.v1', data_get($payload, 'schema_version'));
        $this->assertSame('traceable', data_get($payload, 'status'));
        $this->assertSame('read_only_traceability_audit', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertSame(0, data_get($payload, 'summary.violation_count'));
        $this->assertGreaterThanOrEqual(13, data_get($payload, 'summary.required_doc_count'));
        $this->assertContains('php artisan atlas:ai:self-construction --traceability --json', data_get($payload, 'required_gates'));
        $this->assertFalse(data_get($payload, 'safety_contract.self_programming_allowed'));

        $root = collect(data_get($payload, 'traceability_items'))->firstWhere('path', 'docs/engineering-knowledge-base/atlas-ai-self-construction-os.md');

        $this->assertTrue(data_get($root, 'exists'));
        $this->assertTrue(data_get($root, 'declares_self_construction_tag'));
        $this->assertTrue(data_get($root, 'declares_layer'));
        $this->assertTrue(data_get($root, 'listed_by_root_doc'));
    }

    public function test_command_human_output_lists_traceability_audit(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--traceability' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Required docs', $output);
        $this->assertStringContainsString('Violations', $output);
        $this->assertStringContainsString('Traceability audit is read-only', $output);
    }

    public function test_command_returns_promotion_gate_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--promotion-gate' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_promotion_gate.v1', data_get($payload, 'schema_version'));
        $this->assertSame('promotion_ready', data_get($payload, 'status'));
        $this->assertSame('read_only_promotion_gate', data_get($payload, 'mode'));
        $this->assertFalse(data_get($payload, 'execution_allowed'));
        $this->assertSame('phase_4_5_traceability_guardrail', data_get($payload, 'current_phase'));
        $this->assertSame('phase_5_low_risk_agent_execution_candidate', data_get($payload, 'recommended_next_phase'));
        $this->assertSame([], data_get($payload, 'blocking_failures'));
        $this->assertSame('L0_not_allowed', data_get($payload, 'maturity_delta.autonomous_self_programming'));
        $this->assertContains('docs_only', data_get($payload, 'promotion_conditions.allowed_first_execution_scope'));
        $this->assertContains('voice_realtime_runtime_change', data_get($payload, 'promotion_conditions.forbidden_first_execution_scope'));
        $this->assertContains('php artisan atlas:ai:self-construction --promotion-gate --json', data_get($payload, 'required_gates'));
        $this->assertTrue(data_get($payload, 'promotion_conditions.human_review_required'));
    }

    public function test_command_human_output_lists_promotion_gate(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--promotion-gate' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Self-Construction OS', $output);
        $this->assertStringContainsString('Current phase', $output);
        $this->assertStringContainsString('Recommended next phase', $output);
        $this->assertStringContainsString('Blocking failures', $output);
        $this->assertStringContainsString('ready for human-reviewed Phase 5 candidate planning', $output);
    }
}
