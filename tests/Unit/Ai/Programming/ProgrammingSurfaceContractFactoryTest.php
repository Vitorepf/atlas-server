<?php

namespace Tests\Unit\Ai\Programming;

use App\Services\Ai\Programming\ProgrammingSurfaceContractFactory;
use Tests\TestCase;

class ProgrammingSurfaceContractFactoryTest extends TestCase
{
    public function test_fix_contract_preserves_fix_origin_and_canonical_dev_repair_target(): void
    {
        $contract = app(ProgrammingSurfaceContractFactory::class)->fix([
            'provider' => 'codex_cli',
            'max_iterations' => 4,
        ], [
            'atlas:cli:dev',
            '--surface-origin=atlas_cli_fix',
            '--repair',
            '--allow-write',
            '--auto-test',
            '--plan-only',
        ]);

        $this->assertSame('atlas.cli_fix.contract.v1', $contract['schema_version']);
        $this->assertSame('atlas_cli_fix', $contract['surface']);
        $this->assertSame('atlas_cli_dev', $contract['canonical_surface']);
        $this->assertSame('atlas:cli:dev', $contract['target_command']);
        $this->assertSame('atlas_cli_dev', $contract['target_surface']);
        $this->assertSame('programming.repair', $contract['flow']);
        $this->assertSame('dev_repair_executor', $contract['runtime']);
        $this->assertSame('AtlasProgrammingOrchestrator', $contract['orchestrator']);
        $this->assertSame('repair', $contract['programming_intent']);
        $this->assertSame('codex_cli', $contract['provider']);
        $this->assertSame(4, $contract['max_iterations']);
        $this->assertTrue($contract['quality_required']);
        $this->assertTrue($contract['repair_required']);
        $this->assertTrue($contract['dev_flags']['repair']);
        $this->assertTrue($contract['dev_flags']['allow_write']);
        $this->assertTrue($contract['dev_flags']['auto_test']);
        $this->assertTrue($contract['dev_flags']['plan_only']);
    }

    public function test_resume_contract_preserves_continue_origin_and_canonical_dev_target(): void
    {
        $contract = app(ProgrammingSurfaceContractFactory::class)->resume([
            'plan_id' => 'plan_123',
            'thread_id' => 'thread_123',
            'trace_id' => 'trace_123',
            'programming_profile' => 'forge',
            'model' => 'gpt-5.5',
            'continuation_packet' => [
                'schema_version' => 'atlas.programming.continuation_packet.v1',
                'next_stage' => 'test',
            ],
        ], [
            'provider' => 'codex_cli',
            'programming_intent' => 'repair',
        ], [
            '/opt/homebrew/bin/php',
            'artisan',
            'atlas:cli:dev',
            'continue tarefa',
            '--resume=plan_123',
            '--forge',
            '--repair',
            '--allow-write',
            '--auto-test',
        ], [
            'mode' => 'required',
        ]);

        $this->assertSame('atlas.cli_continue.resume_contract.v1', $contract['schema_version']);
        $this->assertSame('atlas_cli_continue', $contract['surface']);
        $this->assertSame('atlas_cli_dev', $contract['canonical_surface']);
        $this->assertSame('atlas:cli:dev', $contract['target_command']);
        $this->assertSame('atlas_cli_dev', $contract['target_surface']);
        $this->assertSame('plan_123', $contract['plan_id']);
        $this->assertSame('thread_123', $contract['thread_id']);
        $this->assertSame('trace_123', $contract['trace_id']);
        $this->assertSame('forge', $contract['programming_profile']);
        $this->assertSame('repair', $contract['programming_intent']);
        $this->assertSame('codex_cli', $contract['provider']);
        $this->assertSame('gpt-5.5', $contract['model']);
        $this->assertSame('atlas.programming.continuation_packet.v1', data_get($contract, 'continuation_packet.schema_version'));
        $this->assertSame('test', data_get($contract, 'continuation_packet.next_stage'));
        $this->assertSame('required', $contract['open_brain']['mode']);
        $this->assertTrue($contract['dev_flags']['resume']);
        $this->assertTrue($contract['dev_flags']['forge']);
        $this->assertTrue($contract['dev_flags']['repair']);
        $this->assertTrue($contract['dev_flags']['allow_write']);
        $this->assertTrue($contract['dev_flags']['auto_test']);
    }

    public function test_chat_dev_contract_preserves_programming_dispatch_and_kernel_pipeline_binding(): void
    {
        $contract = app(ProgrammingSurfaceContractFactory::class)->chatDev([
            'plan_id' => 'plan_123',
            'kernel_pipeline' => [
                'schema_version' => 'atlas.kernel.pipeline.scaffold.v1',
                'provider_execution_allowed' => false,
                'input' => [
                    'surface_id' => 'atlas_ai_chat',
                    'safe_hints' => [
                        'flow' => 'programming.repair',
                        'runtime' => 'dev_repair_executor',
                    ],
                ],
                'surface_binding' => [
                    'input_mode' => 'chat_dev_auto_plan',
                ],
            ],
            'kernel_pipeline_contract' => [
                'required' => true,
            ],
        ], [
            'parent_plan_id' => 'plan_123',
            'programming_profile' => 'dev',
            'policy_profile' => [
                'profile_id' => 'programming.repair',
            ],
            'operator_intent' => [
                'kind' => 'repair',
            ],
            'executor_decision' => [
                'executor' => 'dev_repair_executor',
            ],
        ], [
            'dispatch_path' => 'ai_gateway_provider',
        ]);

        $this->assertSame('atlas.ai_chat.programming_contract.v1', $contract['schema_version']);
        $this->assertSame('atlas_ai_chat', $contract['surface']);
        $this->assertSame('AtlasProgrammingOrchestrator', $contract['orchestrator']);
        $this->assertSame('plan_123', $contract['parent_plan_id']);
        $this->assertSame('programming.repair', $contract['programming_flow']);
        $this->assertSame('repair', $contract['operator_intent']);
        $this->assertSame('dev_repair_executor', $contract['executor']);
        $this->assertSame('ai_gateway_provider', $contract['dispatch_path']);
        $this->assertSame('atlas.kernel.pipeline.scaffold.v1', $contract['kernel_pipeline_schema']);
        $this->assertSame('atlas_ai_chat', $contract['kernel_pipeline_surface']);
        $this->assertSame('programming.repair', $contract['kernel_pipeline_flow']);
        $this->assertSame('chat_dev_auto_plan', $contract['kernel_pipeline_input_mode']);
        $this->assertFalse($contract['kernel_pipeline_provider_execution_allowed']);
        $this->assertTrue($contract['kernel_pipeline_contract_required']);
    }

    public function test_forge_contract_preserves_programming_surface_flow_runtime_and_evidence_requirements(): void
    {
        $contract = app(ProgrammingSurfaceContractFactory::class)->forge([
            'programming_session_plan' => [
                'executor_decision' => [
                    'executor' => 'engineering_harness',
                ],
            ],
            'kernel_pipeline' => [
                'input' => [
                    'safe_hints' => [
                        'flow' => 'programming.forge',
                        'runtime' => 'engineering_harness',
                    ],
                ],
            ],
        ], [
            'status' => 'passed',
            'harness_payload' => [
                'run' => [
                    'id' => 'run_123',
                ],
            ],
        ]);

        $this->assertSame('atlas.cli_forge.contract.v1', $contract['schema_version']);
        $this->assertSame('atlas_cli_forge', $contract['surface']);
        $this->assertSame('forge', $contract['profile']);
        $this->assertSame('programming.forge', $contract['flow']);
        $this->assertSame('engineering_harness', $contract['runtime']);
        $this->assertSame('AtlasProgrammingOrchestrator', $contract['orchestrator']);
        $this->assertSame('engineering_harness', $contract['executor']);
        $this->assertSame('programming.forge', $contract['kernel_pipeline_flow']);
        $this->assertSame('engineering_harness', $contract['kernel_pipeline_runtime']);
        $this->assertTrue($contract['quality_required']);
        $this->assertTrue($contract['evidence_required']);
        $this->assertSame('passed', $contract['harness_result_status']);
        $this->assertSame('run_123', $contract['harness_run_id']);
    }
}
