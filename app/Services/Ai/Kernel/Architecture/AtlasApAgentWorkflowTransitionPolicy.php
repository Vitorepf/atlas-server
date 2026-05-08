<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApAgentWorkflowTransitionPolicy
{
    private const SCHEMA_VERSION = 'atlas.ap_agent_workflow_transition_policy.v1';

    public function __construct(
        private readonly AtlasApAgentWorkflowRegistry $registry,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function policy(): array
    {
        $workflow = $this->registry->workflow();

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'implemented',
            'mode' => 'read_only_transition_policy',
            'authority' => 'ap_agent_workflow_transition_policy_only_no_execution',
            'workflow_schema_version' => $workflow['schema_version'],
            'workflow_id' => $workflow['workflow_id'],
            'transition_count' => count($this->transitions($workflow)),
            'transitions' => $this->transitions($workflow),
            'terminal_steps' => $this->terminalSteps($workflow),
            'next_action' => 'validate_step_transition_before_advancing_agent_ap_workflow',
            'guardrails' => [
                'writes_files' => false,
                'executes_commands' => false,
                'advances_workflow' => false,
                'allows_unknown_steps' => false,
                'allows_terminal_step_exit' => false,
                'creates_parallel_flow' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function validateTransition(string $fromStep, string $toStep): array
    {
        $workflow = $this->registry->workflow();
        $steps = $this->stepsByAp($workflow);
        $from = strtoupper(trim($fromStep));
        $to = strtoupper(trim($toStep));
        $errors = [];

        if (! isset($steps[$from])) {
            $errors[] = [
                'step' => $from,
                'reason' => 'unknown_from_step',
            ];
        }

        if (! isset($steps[$to])) {
            $errors[] = [
                'step' => $to,
                'reason' => 'unknown_to_step',
            ];
        }

        if ($errors === [] && ((array) $steps[$from]['allowed_next_steps']) === []) {
            $errors[] = [
                'step' => $from,
                'reason' => 'from_step_is_terminal',
            ];
        }

        if (
            $errors === []
            && ! in_array($to, (array) $steps[$from]['allowed_next_steps'], true)
        ) {
            $errors[] = [
                'step' => $from,
                'to_step' => $to,
                'reason' => 'transition_not_allowed_by_workflow_registry',
                'allowed_next_steps' => (array) $steps[$from]['allowed_next_steps'],
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $errors === [] ? 'allowed' : 'blocked',
            'mode' => 'read_only_transition_check',
            'authority' => 'ap_agent_workflow_transition_policy_only_no_execution',
            'from_step' => $from,
            'to_step' => $to,
            'errors' => $errors,
            'next_action' => $errors === []
                ? 'advance_to_next_declared_workflow_step'
                : 'repair_workflow_transition_before_advancing',
            'guardrails' => $this->policy()['guardrails'],
        ];
    }

    /**
     * @param  array<string,mixed>  $workflow
     * @return array<int,array<string,mixed>>
     */
    private function transitions(array $workflow): array
    {
        $transitions = [];
        foreach ((array) $workflow['steps'] as $step) {
            foreach ((array) $step['allowed_next_steps'] as $nextStep) {
                $transitions[] = [
                    'from_step' => $step['ap'],
                    'to_step' => $nextStep,
                    'from_component' => $step['component'],
                ];
            }
        }

        return $transitions;
    }

    /**
     * @param  array<string,mixed>  $workflow
     * @return array<int,string>
     */
    private function terminalSteps(array $workflow): array
    {
        return array_values(array_map(
            fn (array $step): string => (string) $step['ap'],
            array_filter(
                (array) $workflow['steps'],
                fn (array $step): bool => ((array) $step['allowed_next_steps']) === [],
            ),
        ));
    }

    /**
     * @param  array<string,mixed>  $workflow
     * @return array<string,array<string,mixed>>
     */
    private function stepsByAp(array $workflow): array
    {
        $steps = [];
        foreach ((array) $workflow['steps'] as $step) {
            $steps[(string) $step['ap']] = $step;
        }

        return $steps;
    }
}
