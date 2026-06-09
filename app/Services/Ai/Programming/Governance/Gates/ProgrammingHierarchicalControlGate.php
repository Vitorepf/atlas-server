<?php

namespace App\Services\Ai\Programming\Governance\Gates;

use App\Models\AtlasProgrammingWorkItem;
use App\Services\Ai\Programming\Governance\ProgrammingHierarchicalControlLoopService;

/**
 * Final pre-completion controller: blocks completion until the H-level
 * strategic contract and L-level execution truth converge on submit.
 *
 * @see docs/engineering-knowledge-base/atlas-hierarchical-control-loop.md
 */
class ProgrammingHierarchicalControlGate implements ProgrammingGateContract
{
    public function __construct(
        private readonly ProgrammingHierarchicalControlLoopService $controller,
    ) {}

    public function name(): string
    {
        return 'hierarchical-control';
    }

    public function evaluate(AtlasProgrammingWorkItem $workItem): ProgrammingGateOutcome
    {
        $payload = $this->controller->evaluate($workItem);
        $action = (string) data_get($payload, 'halt_decision.action');
        $reason = (string) data_get($payload, 'halt_decision.reason');

        if ($action === ProgrammingHierarchicalControlLoopService::ACTION_SUBMIT) {
            return ProgrammingGateOutcome::passed($payload, $reason);
        }

        return ProgrammingGateOutcome::failed(
            'hierarchical_control_requires_'.$action,
            $payload,
        );
    }
}
