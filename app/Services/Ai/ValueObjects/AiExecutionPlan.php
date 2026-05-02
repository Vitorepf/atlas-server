<?php

namespace App\Services\Ai\ValueObjects;

class AiExecutionPlan
{
    public function __construct(
        private readonly array $data,
    ) {}

    public static function fromTask(AiTaskRequest $task, string $agent, ?string $provider, array $options): self
    {
        $taskType = $task->taskType();
        $mode = $task->desiredMode();
        $risk = $task->riskLevel();
        $requestedProvider = data_get($options, 'payload.requested_provider');

        return new self([
            'schema_version' => 1,
            'workflow' => self::workflow($taskType, $mode),
            'selected_skill' => $agent,
            'selected_provider' => $provider ?: 'auto',
            'requested_provider' => $requestedProvider,
            'agents' => self::agents($taskType, $mode, $agent),
            'tools_allowed' => self::tools($taskType, $mode),
            'quality_gates' => self::gates($taskType, $mode, $risk),
            'requires_human_confirmation' => in_array($risk, ['high', 'irreversible'], true),
            'escalation_policy' => 'Escalar para provider alternativo ou Vitor se gate falhar duas vezes.',
        ]);
    }

    public function toArray(): array
    {
        return $this->data;
    }

    public function toPromptSection(): string
    {
        $lines = [
            '# Execution Plan Atlas',
            '',
            '- workflow: '.$this->data['workflow'],
            '- skill: '.$this->data['selected_skill'],
            '- provider selecionado: '.$this->data['selected_provider'],
            '- provider solicitado: '.($this->data['requested_provider'] ?: 'atlas_decide'),
            '- confirmacao humana: '.($this->data['requires_human_confirmation'] ? 'sim' : 'nao'),
            '',
            '## Agentes / Papeis',
        ];

        foreach ($this->data['agents'] as $agent) {
            $lines[] = '- '.$agent;
        }

        $lines[] = '';
        $lines[] = '## Ferramentas Permitidas';
        foreach ($this->data['tools_allowed'] as $tool) {
            $lines[] = '- '.$tool;
        }

        $lines[] = '';
        $lines[] = '## Quality Gates';
        foreach ($this->data['quality_gates'] as $gate) {
            $lines[] = '- '.$gate;
        }

        return implode("\n", $lines);
    }

    private static function workflow(string $taskType, string $mode): string
    {
        if ($mode === 'semantic_clarification') {
            return 'memory.extract_classify_validate';
        }

        return match ($taskType) {
            'dev' => 'plan_execute_test_review_summarize',
            'debug' => 'reproduce_localize_patch_regress',
            'review' => 'read_diff_find_risks_recommend',
            'research' => 'plan_source_extract_synthesize',
            'decision' => 'frame_options_tradeoffs_recommend',
            'memory' => 'extract_classify_validate_store_candidate',
            'planning' => 'frame_plan_risks_next_step',
            default => 'direct_answer_with_context',
        };
    }

    private static function agents(string $taskType, string $mode, string $agent): array
    {
        if ($mode === 'review' || $taskType === 'review') {
            return ['reviewer', 'evaluator'];
        }

        return match ($taskType) {
            'dev' => ['planner', 'executor', 'reviewer'],
            'debug' => ['debugger', 'executor', 'reviewer'],
            'research' => ['researcher', 'source_checker', 'synthesizer'],
            'decision' => ['decision_advisor', 'skeptic'],
            'memory' => ['memory_writer', 'evaluator'],
            default => [$agent],
        };
    }

    private static function tools(string $taskType, string $mode): array
    {
        $tools = ['semantic_search', 'session.search'];

        if (in_array($taskType, ['dev', 'debug', 'review'], true)) {
            $tools[] = 'repo_context';
            $tools[] = 'git_diff';
        }

        if (in_array($taskType, ['research'], true)) {
            $tools[] = 'source_retrieval';
        }

        if ($mode === 'semantic_clarification' || $taskType === 'memory') {
            $tools[] = 'memory_schema';
        }

        return array_values(array_unique($tools));
    }

    private static function gates(string $taskType, string $mode, string $risk): array
    {
        $gates = ['answer_grounded_in_context_or_lacuna_declared'];

        if ($mode === 'review' || $taskType === 'review') {
            $gates[] = 'findings_before_summary';
            $gates[] = 'risks_and_missing_tests_checked';
        }

        if (in_array($taskType, ['dev', 'debug'], true)) {
            $gates[] = 'diff_summary_required';
            $gates[] = 'tests_or_not_run_reason_required';
        }

        if ($taskType === 'research') {
            $gates[] = 'sources_required_when_claiming_facts';
        }

        if ($taskType === 'decision') {
            $gates[] = 'tradeoffs_and_reversibility_required';
            $gates[] = 'human_authorship_preserved';
        }

        if ($taskType === 'memory' || $mode === 'semantic_clarification') {
            $gates[] = 'memory_origin_scope_confidence_required';
        }

        if (in_array($risk, ['high', 'irreversible'], true)) {
            $gates[] = 'human_confirmation_required';
        }

        return array_values(array_unique($gates));
    }
}
