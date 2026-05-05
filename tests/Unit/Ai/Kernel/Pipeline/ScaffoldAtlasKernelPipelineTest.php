<?php

namespace Tests\Unit\Ai\Kernel\Pipeline;

use App\Services\Ai\Kernel\Pipeline\AtlasKernelPipeline;
use App\Services\Ai\Kernel\Pipeline\KernelPipelineContract;
use App\Services\Ai\Kernel\Pipeline\KernelPipelineStage;
use App\Services\Ai\Kernel\Pipeline\PipelineInput;
use App\Services\Ai\Kernel\Pipeline\ScaffoldAtlasKernelPipeline;
use Tests\TestCase;

class ScaffoldAtlasKernelPipelineTest extends TestCase
{
    public function test_pipeline_exposes_canonical_stage_order(): void
    {
        $pipeline = new ScaffoldAtlasKernelPipeline();

        $this->assertInstanceOf(AtlasKernelPipeline::class, $pipeline);
        $this->assertSame(
            KernelPipelineStage::orderedValues(),
            array_map(fn ($stage): string => $stage->stage->value, $pipeline->stages()),
        );
    }

    public function test_operation_envelope_is_created_before_routing_and_decision_receipt_is_attached_before_domain(): void
    {
        $order = KernelPipelineStage::orderedValues();

        $this->assertLessThan(array_search('intent', $order, true), array_search('operation_envelope', $order, true));
        $this->assertLessThan(array_search('decide', $order, true), array_search('operation_envelope', $order, true));
        $this->assertLessThan(array_search('domain', $order, true), array_search('decision_receipt', $order, true));
    }

    public function test_each_stage_declares_read_and_write_slots(): void
    {
        $pipeline = new ScaffoldAtlasKernelPipeline();

        foreach ($pipeline->stages() as $stage) {
            $this->assertNotSame([], $stage->reads, "{$stage->stage->value} must declare reads.");
            $this->assertNotSame([], $stage->writes, "{$stage->stage->value} must declare writes.");
        }
    }

    public function test_stage_contract_has_unique_orders_stages_and_write_slots(): void
    {
        $pipeline = new ScaffoldAtlasKernelPipeline();
        $stages = $pipeline->stages();
        $orders = array_map(fn ($stage): int => $stage->order, $stages);
        $stageNames = array_map(fn ($stage): string => $stage->stage->value, $stages);
        $writeSlots = array_merge(...array_map(fn ($stage): array => $stage->writes, $stages));

        $this->assertSame(range(1, count($stages)), $orders);
        $this->assertSame($stageNames, array_values(array_unique($stageNames)));
        $this->assertSame($writeSlots, array_values(array_unique($writeSlots)));

        foreach ($stages as $stage) {
            foreach (array_merge($stage->reads, $stage->writes) as $slot) {
                $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/', $slot);
            }
        }
    }

    public function test_scaffold_execution_never_attempts_provider_execution(): void
    {
        $result = (new ScaffoldAtlasKernelPipeline())->execute(PipelineInput::fromArray([
            'text' => 'corrija esse bug sem executar provider',
            'surface_id' => 'unit_test',
            'operator_id' => 'tester',
            'hints' => ['flow' => 'programming.repair'],
            'dry_run' => false,
        ]));

        $this->assertTrue($result->dryRun);
        $this->assertFalse($result->providerExecutionAttempted);
        $this->assertFalse($result->auditPlan['provider_execution_allowed']);
        $this->assertFalse($result->auditPlan['runtime_execution_allowed']);
        $this->assertFalse($result->auditPlan['execution_guards']['dry_run_requested']);
        $this->assertTrue($result->auditPlan['execution_guards']['dry_run_effective']);
        $this->assertFalse($result->auditPlan['execution_guards']['surface_runtime_migration_allowed']);
    }

    public function test_audit_plan_exposes_stable_schema_flow_guards_and_slot_manifest(): void
    {
        $pipeline = new ScaffoldAtlasKernelPipeline();
        $plan = $pipeline->plan(PipelineInput::fromArray([
            'text' => 'planeje sem executar',
            'surface_id' => 'unit_test',
            'hints' => ['domain_id' => 'programming', 'flow_id' => 'programming.dev'],
        ]));

        $this->assertSame(KernelPipelineContract::SCHEMA_VERSION, $plan['schema_version']);
        $this->assertSame(KernelPipelineContract::MODE, $plan['mode']);
        $this->assertSame(KernelPipelineContract::STATUS, $plan['status']);
        $this->assertSame(KernelPipelineContract::canonicalFlow(), $plan['canonical_flow']);
        $this->assertSame(KernelPipelineContract::canonicalFlowHash(), $plan['canonical_flow_hash']);
        $this->assertSame(count($pipeline->stages()), $plan['stage_count']);
        $this->assertSame(['surface.raw_input'], $plan['slot_manifest']['initial_slots']);
        $this->assertContains('decision.receipt', $plan['slot_manifest']['write_slots']);
        $this->assertSame(['output.render_plan'], $plan['slot_manifest']['terminal_slots']);
        $this->assertSame($plan['input']['input_fingerprint'], $plan['input_fingerprint']);
        $this->assertFalse($plan['execution_guards']['provider_execution_allowed']);
        $this->assertFalse($plan['execution_guards']['runtime_execution_allowed']);
    }

    public function test_audit_plan_hashes_input_and_keeps_only_safe_hints(): void
    {
        $result = (new ScaffoldAtlasKernelPipeline())->execute(PipelineInput::fromArray([
            'text' => 'segredo operacional que nao deve aparecer no plano',
            'hints' => [
                'flow' => 'programming.dev',
                'secret_context' => 'nao incluir',
            ],
            'metadata' => [
                'raw_secret' => 'nao incluir',
            ],
        ]));
        $input = $result->auditPlan['input'];

        $this->assertSame(['flow' => 'programming.dev'], $input['safe_hints']);
        $this->assertArrayHasKey('hints_hash', $input);
        $this->assertSame(['raw_secret'], $input['metadata_keys']);
        $this->assertStringNotContainsString('segredo operacional', json_encode($result->toArray(), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('nao incluir', json_encode($result->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_result_includes_evidence_and_trace_placeholders_for_every_stage(): void
    {
        $pipeline = new ScaffoldAtlasKernelPipeline();
        $result = $pipeline->execute(PipelineInput::fromArray(['text' => 'planeje pipeline']));

        $this->assertCount(count($pipeline->stages()), $result->stageResults);
        $this->assertCount(count($pipeline->stages()), $result->evidenceRefs);
        $this->assertCount(count($pipeline->stages()), $result->traceRefs);

        foreach ($result->stageResults as $stageResult) {
            $this->assertNotSame([], $stageResult->evidenceRefs);
            $this->assertNotSame([], $stageResult->traceRefs);
            $this->assertStringStartsWith('evidence://kernel-pipeline/', $stageResult->evidenceRefs[0]);
            $this->assertStringStartsWith('trace://kernel-pipeline/', $stageResult->traceRefs[0]);
        }
    }

    public function test_execution_result_uses_same_pipeline_id_in_audit_plan_and_refs(): void
    {
        $result = (new ScaffoldAtlasKernelPipeline())->execute(PipelineInput::fromArray([
            'text' => 'planeje com id consistente',
            'hints' => ['flow' => 'programming.dev'],
        ]));

        $this->assertSame($result->pipelineId, $result->auditPlan['pipeline_id']);
        $this->assertStringEndsWith(substr($result->auditPlan['input_fingerprint'], 0, 16), $result->pipelineId);
        $this->assertStringContainsString($result->pipelineId, $result->evidenceRefs[0]);
        $this->assertStringContainsString($result->pipelineId, $result->traceRefs[0]);
    }

    public function test_plan_and_plan_hash_are_deterministic_for_same_input(): void
    {
        $pipeline = new ScaffoldAtlasKernelPipeline();
        $input = PipelineInput::fromArray([
            'text' => 'mesmo input deve produzir mesmo plano auditavel',
            'surface_id' => 'unit_test',
            'hints' => ['flow' => 'programming.dev'],
        ]);

        $leftPlan = $pipeline->plan($input);
        $rightPlan = $pipeline->plan($input);
        $leftResult = $pipeline->execute($input);
        $rightResult = $pipeline->execute($input);

        $this->assertSame($leftPlan, $rightPlan);
        $this->assertSame($leftResult->pipelineId, $rightResult->pipelineId);
        $this->assertSame($leftResult->planHash, $rightResult->planHash);
        $this->assertSame(
            hash('sha256', json_encode($leftPlan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
            $leftResult->planHash,
        );
    }

    public function test_execution_result_serializes_schema_and_plan_hash(): void
    {
        $result = (new ScaffoldAtlasKernelPipeline())->execute(PipelineInput::fromArray([
            'text' => 'serializar contrato publico',
        ]));
        $payload = $result->toArray();

        $this->assertSame(KernelPipelineContract::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame(KernelPipelineContract::STATUS, $payload['status']);
        $this->assertSame(hash('sha256', json_encode($result->auditPlan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)), $payload['plan_hash']);
        $this->assertSame($payload['plan_hash'], $result->planHash);
    }

    public function test_stage_reads_only_reference_initial_or_prior_written_slots(): void
    {
        $available = ['surface.raw_input' => true];

        foreach ((new ScaffoldAtlasKernelPipeline())->stages() as $stage) {
            foreach ($stage->reads as $slot) {
                $this->assertArrayHasKey($slot, $available, "{$stage->stage->value} reads [{$slot}] before it is available.");
            }

            foreach ($stage->writes as $slot) {
                $available[$slot] = true;
            }
        }
    }

    public function test_compliance_report_passes_for_scaffold_pipeline(): void
    {
        $report = (new ScaffoldAtlasKernelPipeline())->complianceReport();

        $this->assertTrue($report['ok'], implode("\n", $report['errors']));
        $this->assertSame(KernelPipelineContract::SCHEMA_VERSION, $report['schema_version']);
        $this->assertSame(14, $report['stage_count']);
        $this->assertSame(KernelPipelineStage::orderedValues(), $report['stage_order']);
        $this->assertSame(KernelPipelineContract::canonicalFlowHash(), $report['stage_order_hash']);
        $this->assertTrue($report['slot_flow_valid']);
        $this->assertFalse($report['provider_execution_allowed']);
        $this->assertFalse($report['runtime_execution_allowed']);
    }
}
