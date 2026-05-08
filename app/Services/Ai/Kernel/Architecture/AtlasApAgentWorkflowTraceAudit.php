<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApAgentWorkflowTraceAudit
{
    private const SCHEMA_VERSION = 'atlas.ap_agent_workflow_trace_audit.v1';

    public function __construct(
        private readonly AtlasApAgentWorkflowTransitionPolicy $transitionPolicy,
    ) {}

    /**
     * @param  array<int,mixed>  $steps
     * @return array<string,mixed>
     */
    public function audit(array $steps): array
    {
        $shapeErrors = $this->shapeErrors($steps);
        $normalizedSteps = $this->normalizedSteps($steps);
        $blockedTransitions = $shapeErrors === []
            ? $this->blockedTransitions($normalizedSteps)
            : [];
        $terminalReached = $this->terminalReached($normalizedSteps);
        $valid = $shapeErrors === [] && $blockedTransitions === [] && $terminalReached;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $valid ? 'valid_trace' : 'invalid_trace',
            'mode' => 'read_only_trace_audit',
            'authority' => 'ap_agent_workflow_trace_audit_only_no_execution',
            'step_count' => count($normalizedSteps),
            'transition_count' => max(0, count($normalizedSteps) - 1),
            'steps' => $normalizedSteps,
            'terminal_reached' => $terminalReached,
            'shape_error_count' => count($shapeErrors),
            'shape_errors' => $shapeErrors,
            'blocked_transition_count' => count($blockedTransitions),
            'blocked_transitions' => $blockedTransitions,
            'next_action' => $valid
                ? 'accept_agent_workflow_trace_as_following_declared_policy'
                : 'repair_agent_workflow_trace_before_claiming_ordered_execution',
            'guardrails' => [
                'writes_files' => false,
                'executes_commands' => false,
                'advances_workflow' => false,
                'repairs_trace' => false,
                'creates_parallel_flow' => false,
            ],
        ];
    }

    /**
     * @param  array<int,mixed>  $steps
     * @return array<int,array<string,mixed>>
     */
    private function shapeErrors(array $steps): array
    {
        $errors = [];
        if (count($steps) < 2) {
            $errors[] = [
                'reason' => 'trace_requires_at_least_two_steps',
                'step_count' => count($steps),
            ];
        }

        foreach ($steps as $index => $step) {
            if (! is_string($step) || trim($step) === '') {
                $errors[] = [
                    'reason' => 'trace_step_must_be_non_empty_string',
                    'index' => $index,
                    'actual_type' => get_debug_type($step),
                ];
            }
        }

        return $errors;
    }

    /**
     * @param  array<int,mixed>  $steps
     * @return array<int,string>
     */
    private function normalizedSteps(array $steps): array
    {
        return array_values(array_map(
            fn (mixed $step): string => is_string($step) ? strtoupper(trim($step)) : '',
            $steps,
        ));
    }

    /**
     * @param  array<int,string>  $steps
     * @return array<int,array<string,mixed>>
     */
    private function blockedTransitions(array $steps): array
    {
        $blocked = [];
        foreach (array_values(array_slice($steps, 0, -1)) as $index => $fromStep) {
            $toStep = $steps[$index + 1];
            $transition = $this->transitionPolicy->validateTransition($fromStep, $toStep);
            if (($transition['status'] ?? null) !== 'allowed') {
                $blocked[] = [
                    'index' => $index,
                    'from_step' => $fromStep,
                    'to_step' => $toStep,
                    'errors' => $transition['errors'],
                ];
            }
        }

        return $blocked;
    }

    /**
     * @param  array<int,string>  $steps
     */
    private function terminalReached(array $steps): bool
    {
        $terminalSteps = (array) $this->transitionPolicy->policy()['terminal_steps'];

        return in_array(end($steps), $terminalSteps, true);
    }
}
