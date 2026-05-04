<?php

namespace Tests\Unit;

use App\Services\Ai\Cli\AtlasCliDevWorkflowService;
use App\Services\Ai\FairClaudePolicy;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasCliDevWorkflowServiceTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir().'/atlas-cli-dev-workflow-test-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->workspace);
        $this->workspace = realpath($this->workspace) ?: $this->workspace;
        File::put($this->workspace.'/composer.json', json_encode([
            'scripts' => [
                'test' => 'php -r "exit(0);"',
            ],
        ], JSON_PRETTY_PRINT));

        config([
            'atlas.ai.workdir' => $this->workspace,
            'atlas.ai.runtime.profile_cache_ttl_seconds' => 0,
            'atlas.ai.tool_permissions.allowed_roots' => [$this->workspace],
            'atlas.ai.default_provider' => 'claude_cli',
            'atlas.cli.php_binary' => '/opt/homebrew/bin/php',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace);

        parent::tearDown();
    }

    public function test_preflight_builds_native_dev_workflow_packet(): void
    {
        $payload = app(AtlasCliDevWorkflowService::class)->preflight(
            workspace: $this->workspace,
            task: 'implementar workflow',
            provider: 'codex_cli',
        );

        $this->assertSame($this->workspace, $payload['workspace']);
        $this->assertSame('implementar workflow', $payload['task']);
        $this->assertSame('codex_cli', $payload['selected_provider']);
        $this->assertArrayHasKey('preflight_quality', $payload);
        $this->assertFalse($payload['requires_override']);
    }

    public function test_fair_preflight_locks_claude_and_does_not_emit_atlas_decide_decision(): void
    {
        $payload = app(AtlasCliDevWorkflowService::class)->preflight(
            workspace: $this->workspace,
            task: 'implementar fair mode',
            provider: FairClaudePolicy::PROVIDER_LOCK,
            fairMode: true,
        );

        $this->assertSame('claude_cli', $payload['selected_provider']);
        $this->assertNull($payload['policy_profile_id']);
        $this->assertSame('fair_mode_disabled', data_get($payload, 'operational_decision.decision_mode'));
        $this->assertTrue((bool) data_get($payload, 'operational_decision.atlas_decide_disabled_by_fair_mode'));
        $this->assertNull(data_get($payload, 'operational_decision.decision_id'));
        $this->assertNull(data_get($payload, 'operational_decision.fallback_provider'));
    }

    public function test_chat_command_wraps_ai_chat_in_dev_mode(): void
    {
        $command = app(AtlasCliDevWorkflowService::class)->chatCommand(
            task: 'corrigir bug',
            workspace: $this->workspace,
            provider: 'codex_cli',
            model: null,
            permission: 'write',
            allowWrite: true,
            autoTest: false,
            timeout: 900,
            stream: true,
            noRun: true,
        );

        $this->assertSame('/opt/homebrew/bin/php', $command[0]);
        $this->assertContains('atlas:ai:chat', $command);
        $this->assertContains(base_path('artisan'), $command);
        $this->assertContains('corrigir bug', $command);
        $this->assertContains('--dev', $command);
        $this->assertContains('--new-thread', $command);
        $this->assertContains('--provider=codex_cli', $command);
        $this->assertContains('--no-quality-gate', $command);
        $this->assertContains('--no-run', $command);
    }

    public function test_chat_command_forwards_image_options(): void
    {
        $command = app(AtlasCliDevWorkflowService::class)->chatCommand(
            task: 'corrigir UI',
            workspace: $this->workspace,
            provider: 'codex_cli',
            model: null,
            permission: 'write',
            allowWrite: true,
            autoTest: false,
            timeout: 900,
            stream: true,
            noRun: true,
            imagePaths: ['/tmp/tela.png'],
            clipboardImage: true,
            noAutoImage: true,
        );

        $this->assertContains('--image=/tmp/tela.png', $command);
        $this->assertContains('--clipboard-image', $command);
        $this->assertContains('--no-auto-image', $command);
    }

    public function test_chat_command_forwards_model_override(): void
    {
        $command = app(AtlasCliDevWorkflowService::class)->chatCommand(
            task: 'corrigir bug',
            workspace: $this->workspace,
            provider: 'codex_cli',
            model: 'gpt-5.3-codex-spark',
            permission: 'write',
            allowWrite: true,
            autoTest: false,
            timeout: 900,
            stream: true,
            noRun: true,
        );

        $this->assertContains('--model=gpt-5.3-codex-spark', $command);
    }

    public function test_chat_command_forwards_open_brain_policy_options(): void
    {
        $command = app(AtlasCliDevWorkflowService::class)->chatCommand(
            task: 'corrigir bug',
            workspace: $this->workspace,
            provider: 'codex_cli',
            model: null,
            permission: 'write',
            allowWrite: true,
            autoTest: false,
            timeout: 900,
            stream: false,
            noRun: true,
            openBrain: [
                'mode' => 'required',
                'refresh' => true,
                'budget_chars' => 6000,
            ],
        );

        $this->assertContains('--require-open-brain', $command);
        $this->assertContains('--open-brain-refresh', $command);
        $this->assertContains('--open-brain-budget=6000', $command);
    }

    public function test_quality_gate_skills_auto_attach_for_complete_or_multi_iteration(): void
    {
        $workflow = app(AtlasCliDevWorkflowService::class);

        $completeSkills = $workflow->qualityGateSkills(['code-reviewer'], complete: true, maxIterations: 1);
        $multiIterationSkills = $workflow->qualityGateSkills([], complete: false, maxIterations: 3);
        $singleShotSkills = $workflow->qualityGateSkills([], complete: false, maxIterations: 1);

        $this->assertSame(['code-reviewer', 'dev-quality-gate'], $completeSkills);
        $this->assertSame(['dev-quality-gate'], $multiIterationSkills);
        $this->assertSame([], $singleShotSkills);
    }

    public function test_engineering_contract_skills_attach_blueprint_once(): void
    {
        $skills = app(AtlasCliDevWorkflowService::class)->engineeringContractSkills([
            'code-reviewer',
            'engineering-blueprint',
        ]);

        $this->assertSame(['code-reviewer', 'engineering-blueprint'], $skills);
    }

    public function test_prompt_with_engineering_contract_wraps_task_scope_and_acceptance(): void
    {
        $prompt = app(AtlasCliDevWorkflowService::class)->promptWithEngineeringContract('implementar contrato', [
            'type' => 'feature',
            'goal' => 'Gerar contrato tecnico normalizado.',
            'acceptance_criteria' => ['Plan-only expoe engineering_contract.'],
            'test_coverage' => ['Rodar teste do comando.'],
            'refs' => ['task_id' => '33333333-3333-4333-8333-333333333333'],
        ]);

        $this->assertStringContainsString('# Atlas Engineering Task Contract', $prompt);
        $this->assertStringContainsString('Objetivo: Gerar contrato tecnico normalizado.', $prompt);
        $this->assertStringContainsString('- Plan-only expoe engineering_contract.', $prompt);
        $this->assertStringContainsString('# Pedido do operador', $prompt);
        $this->assertStringContainsString('implementar contrato', $prompt);
    }

    public function test_fair_claude_prompt_contract_wraps_task_with_benchmark_constraints(): void
    {
        $prompt = app(AtlasCliDevWorkflowService::class)->fairClaudePromptContract('implementar feature');

        $this->assertStringContainsString('# Atlas Fair Claude Mode', $prompt);
        $this->assertStringContainsString('Provider is locked to claude_cli.', $prompt);
        $this->assertStringContainsString('Model is locked to the configured Claude Opus premium model.', $prompt);
        $this->assertStringContainsString('Forbidden providers: codex_cli, gemini_cli.', $prompt);
        $this->assertStringContainsString('Do not suggest switching provider', $prompt);
        $this->assertStringContainsString('implementar feature', $prompt);
        $this->assertStringContainsString('## Machine-Readable Contract', $prompt);
        $this->assertStringContainsString('"kind": "fair_claude_prompt_contract"', $prompt);
        $this->assertStringContainsString('"forbidden_providers"', $prompt);
    }

    public function test_fair_claude_prompt_contract_emits_machine_readable_block_with_engineering_contract(): void
    {
        $contract = [
            'acceptance_criteria' => [
                'Prompt contract has explicit acceptance criteria.',
                'Plan-only output exposes fair metadata without invoking another provider.',
            ],
            'definition_of_done' => [
                'Diff limitado ao escopo do contrato.',
            ],
            'likely_files' => ['app/Services/Ai/Cli/AtlasCliDevWorkflowService.php'],
            'allowed_paths' => ['app/Services/Ai/Cli'],
            'strict_file_scope' => true,
            'test_coverage' => ['php artisan test --filter=AtlasCliDevWorkflowServiceTest'],
            'refs' => ['task_id' => '019df390-f3c8-73ed-92db-46b42daacb1f'],
        ];

        $prompt = app(AtlasCliDevWorkflowService::class)->fairClaudePromptContract('implementar matriz', $contract);

        $this->assertStringContainsString('## Machine-Readable Contract', $prompt);
        $this->assertStringContainsString('Prompt contract has explicit acceptance criteria.', $prompt);
        $this->assertStringContainsString('app/Services/Ai/Cli/AtlasCliDevWorkflowService.php', $prompt);
        $this->assertStringContainsString('php artisan test --filter=AtlasCliDevWorkflowServiceTest', $prompt);
        $this->assertStringContainsString('"strict": true', $prompt);
    }

    public function test_fair_claude_prompt_contract_metadata_lists_locks_and_forbidden_capabilities(): void
    {
        $metadata = app(AtlasCliDevWorkflowService::class)->fairClaudePromptContractMetadata([
            'acceptance_criteria' => ['Critério X.'],
            'likely_files' => ['app/Foo.php'],
        ]);

        $this->assertSame('fair_claude_prompt_contract', $metadata['kind']);
        $this->assertSame('claude_cli', $metadata['provider_lock']);
        $this->assertSame('opus', $metadata['model_lock']);
        $this->assertSame('premium', $metadata['model_tier']);
        $this->assertSame(['codex_cli', 'gemini_cli'], $metadata['forbidden_providers']);
        $this->assertSame(['fallback', 'council', 'atlas_decide', 'external_reviewer'], $metadata['forbidden_capabilities']);
        $this->assertSame(['Critério X.'], $metadata['acceptance_criteria']);
        $this->assertSame(['app/Foo.php'], $metadata['file_scope']['likely_files']);
        $this->assertTrue($metadata['deterministic_gates']['required']);
        $this->assertTrue($metadata['deterministic_gates']['pass_without_human_requires_gate_pass']);
    }

    public function test_quality_gate_policy_requires_passed_status_in_complete_mode(): void
    {
        $policy = app(AtlasCliDevWorkflowService::class)->qualityGatePolicy(
            complete: true,
            maxIterations: 3,
            skills: ['dev-quality-gate'],
        );

        $this->assertTrue($policy['complete_mode']);
        $this->assertSame('passed', $policy['required_final_status']);
        $this->assertSame('plan_validate_execute', $policy['procedure']);
    }

    public function test_fair_quality_gate_policy_requires_verified_pass_even_without_complete_mode(): void
    {
        $policy = app(AtlasCliDevWorkflowService::class)->qualityGatePolicy(
            complete: false,
            maxIterations: 1,
            skills: [],
            fairMode: true,
        );

        $this->assertTrue($policy['fair_mode']);
        $this->assertSame('passed', $policy['required_final_status']);
        $this->assertTrue($policy['deterministic_gate_required']);
        $this->assertFalse($policy['unverified_counts_as_passed']);
    }

    public function test_fair_protocol_status_does_not_count_needs_review_as_passed(): void
    {
        $result = app(AtlasCliDevWorkflowService::class)->fairClaudeProtocolStatus([
            'status' => 'needs_review',
            'quality_gates' => [
                ['name' => 'git_status', 'status' => 'passed'],
                ['name' => 'tests', 'status' => 'needs_review'],
            ],
        ], providerOk: true);

        $this->assertSame('unverified', $result['status']);
        $this->assertFalse($result['deterministic_gates_passed']);
        $this->assertFalse($result['pass_without_human']);
        $this->assertContains('quality_status_needs_review', $result['blocking_reasons']);
    }

    public function test_fair_protocol_status_allows_pass_without_human_only_for_verified_gates(): void
    {
        $result = app(AtlasCliDevWorkflowService::class)->fairClaudeProtocolStatus([
            'status' => 'passed',
            'quality_gates' => [
                ['name' => 'git_status', 'status' => 'passed'],
                ['name' => 'git_diff', 'status' => 'passed'],
                ['name' => 'tests', 'status' => 'passed'],
            ],
        ], providerOk: true, humanInterventionCount: 0);

        $this->assertSame('valid', $result['status']);
        $this->assertTrue($result['deterministic_gates_passed']);
        $this->assertTrue($result['pass_without_human']);
        $this->assertSame([], $result['blocking_reasons']);
    }

    public function test_fair_claude_repair_capsule_keeps_same_provider_and_model_contract(): void
    {
        $capsule = app(AtlasCliDevWorkflowService::class)->fairClaudeRepairCapsule(
            task: 'implementar feature',
            completion: [
                'status' => 'failed',
                'changed_files' => ['app/Foo.php'],
                'completion_packet' => [
                    'tests' => [[
                        'command' => 'php artisan test tests/Feature/FooTest.php',
                        'ok' => false,
                        'exit_code' => 2,
                        'error' => 'AssertionError: Expected 1 got 0',
                    ]],
                    'risks' => ['Teste falhou.'],
                ],
            ],
            iteration: 2,
            maxIterations: 3,
            devPlan: [
                'plan_id' => 'plan_1',
                'selected_provider' => 'claude_cli',
                'selected_model' => ['model' => 'claude-opus-test'],
                'fair_mode' => ['fair_mode' => true],
            ],
        );

        $this->assertStringContainsString('# Atlas Fair Claude Repair Capsule', $capsule);
        $this->assertStringContainsString('claude_cli + Claude Opus', $capsule);
        $this->assertStringContainsString('Do not switch provider', $capsule);
        $this->assertStringContainsString('unverified, needs_review, missing gates, or self-assessment never count as passed', $capsule);
        $this->assertStringContainsString('implementar feature', $capsule);
        $this->assertStringContainsString('app/Foo.php', $capsule);
        $this->assertStringContainsString('Failure signal (command, exit code, primary error):', $capsule);
        $this->assertStringContainsString('php artisan test tests/Feature/FooTest.php', $capsule);
        $this->assertStringContainsString('"exit_code": 2', $capsule);
        $this->assertStringContainsString('AssertionError: Expected 1 got 0', $capsule);
    }

    public function test_fair_claude_repair_capsule_falls_back_to_quality_gates_when_test_signal_missing(): void
    {
        $capsule = app(AtlasCliDevWorkflowService::class)->fairClaudeRepairCapsule(
            task: 'corrigir lint',
            completion: [
                'status' => 'failed',
                'changed_files' => ['app/Bar.php'],
                'quality_gates' => [
                    ['name' => 'git_status', 'status' => 'passed', 'detail' => 'Git status executado.'],
                    ['name' => 'workspace_changes', 'status' => 'failed', 'detail' => 'Workspace divergente do plano declarado.'],
                ],
                'completion_packet' => [
                    'tests' => [],
                    'risks' => ['Workspace divergente.'],
                ],
            ],
            iteration: 3,
            maxIterations: 3,
            devPlan: [
                'plan_id' => 'plan_2',
                'selected_provider' => 'claude_cli',
                'selected_model' => ['model' => 'claude-opus-test'],
                'fair_mode' => ['fair_mode' => true],
            ],
        );

        $this->assertStringContainsString('Failure signal (command, exit code, primary error):', $capsule);
        $this->assertStringContainsString('atlas:cli:quality:workspace_changes', $capsule);
        $this->assertStringContainsString('Workspace divergente do plano declarado.', $capsule);
        $this->assertStringContainsString('"source": "quality_gates"', $capsule);
    }

    public function test_fair_claude_final_packet_requires_valid_protocol_for_passed_status(): void
    {
        $packet = app(AtlasCliDevWorkflowService::class)->fairClaudeFinalPacket(
            completion: [
                'status' => 'needs_review',
                'changed_files' => ['app/Foo.php'],
                'diff_hash' => 'diff-hash',
                'quality_gates' => [
                    ['name' => 'git_status', 'status' => 'passed'],
                    ['name' => 'tests', 'status' => 'needs_review'],
                ],
                'completion_packet' => [
                    'files_changed' => ['app/Foo.php'],
                    'tests' => [],
                    'quality_gates' => [
                        ['name' => 'git_status', 'status' => 'passed'],
                        ['name' => 'tests', 'status' => 'needs_review'],
                    ],
                ],
            ],
            fairProtocol: [
                'status' => 'unverified',
                'quality_status' => 'needs_review',
                'deterministic_gates_passed' => false,
                'pass_without_human' => false,
                'human_intervention_count' => 0,
                'blocking_reasons' => ['quality_status_needs_review', 'deterministic_gates_not_passed'],
            ],
            devPlan: [
                'selected_model' => ['model' => 'claude-opus-test'],
                'iterations' => ['max' => 3],
            ],
            runs: [
                ['iteration' => 1, 'exit_code' => 0, 'trace_id' => 'trace-1', 'model' => 'claude-opus-test'],
            ],
            ok: false,
        );

        $this->assertSame('atlas_cli_dev_fair_claude_final_packet', $packet['kind']);
        $this->assertSame('failed', $packet['status']);
        $this->assertSame('claude_cli', $packet['provider_lock']);
        $this->assertSame('opus', $packet['model_lock']);
        $this->assertFalse($packet['protocol_valid']);
        $this->assertFalse($packet['pass_without_human']);
        $this->assertFalse($packet['unverified_counts_as_passed']);
        $this->assertContains('deterministic_gates_not_passed', data_get($packet, 'gates.blocking_reasons'));
    }

    public function test_fair_claude_final_packet_marks_repair_conversion_when_green_after_retry(): void
    {
        $packet = app(AtlasCliDevWorkflowService::class)->fairClaudeFinalPacket(
            completion: [
                'status' => 'passed',
                'quality_gates' => [
                    ['name' => 'git_status', 'status' => 'passed'],
                    ['name' => 'tests', 'status' => 'passed'],
                ],
                'completion_packet' => [
                    'files_changed' => ['app/Foo.php', 'tests/FooTest.php'],
                    'tests' => [['command' => 'php artisan test', 'ok' => true]],
                ],
            ],
            fairProtocol: [
                'status' => 'valid',
                'quality_status' => 'passed',
                'deterministic_gates_passed' => true,
                'pass_without_human' => true,
                'human_intervention_count' => 0,
                'blocking_reasons' => [],
            ],
            devPlan: [
                'selected_model' => ['model' => 'claude-opus-test'],
                'iterations' => ['max' => 3],
            ],
            runs: [
                ['iteration' => 1, 'exit_code' => 0, 'trace_id' => 'trace-1', 'model' => 'claude-opus-test'],
                ['iteration' => 2, 'exit_code' => 0, 'trace_id' => 'trace-2', 'model' => 'claude-opus-test'],
            ],
            ok: true,
        );

        $this->assertSame('passed', $packet['status']);
        $this->assertTrue($packet['protocol_valid']);
        $this->assertTrue($packet['pass_without_human']);
        $this->assertSame(2, $packet['attempts']);
        $this->assertSame(1, data_get($packet, 'repair.repair_attempt_count'));
        $this->assertTrue(data_get($packet, 'repair.converted_to_green'));
        $this->assertSame('trace-1', $packet['trace_id']);
        $this->assertSame(['trace-1', 'trace-2'], $packet['trace_ids']);
    }
}
