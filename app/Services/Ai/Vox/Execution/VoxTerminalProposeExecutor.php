<?php

declare(strict_types=1);

namespace App\Services\Ai\Vox\Execution;

use App\Services\Ai\Vox\VoxActionOutcomeService;
use App\Services\Ai\Vox\VoxSchema;

/**
 * V3 terminal_propose · ALWAYS propose-only. Never spawns Process. Never
 * exec(). Just packages the proposed command for the Desktop to copy or
 * paste. `metadata.command_executed=false` is hard-coded by
 * VoxActionOutcomeService::terminalProposed.
 *
 * If the operator's voice somehow slipped a destructive command past the
 * R4 classifier AND past the hard-veto in VoxExecutionGate, the executor
 * still re-runs the hard veto here as defense in depth — it will block
 * with status=aborted instead of proposing it.
 */
final class VoxTerminalProposeExecutor implements VoxExecutor
{
    public function __construct(
        private readonly VoxActionOutcomeService $outcomes,
    ) {}

    public function id(): string
    {
        return VoxSchema::EXECUTOR_TERMINAL_PROPOSE;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function unavailableReason(): ?string
    {
        return null;
    }

    public function dispatch(array $intentPacket, array $receipt, array $context): array
    {
        $proposed = (string) ($intentPacket['compiled_prompt'] ?? '');
        if ($proposed === '') {
            $proposed = (string) ($intentPacket['human_input_text'] ?? '');
        }

        // Defense in depth: even though the gate already vetoed, a faulty
        // call site could still hit dispatch directly. Re-veto here.
        $violation = VoxHardVetoList::firstViolation($proposed);
        if ($violation !== null) {
            return $this->outcomes->executorBlocked(
                intentPacket: $intentPacket,
                receipt: $receipt,
                executor: $this->id(),
                reasonCode: 'hard_veto_inside_executor_'.$violation,
                message: "terminal_propose refused to surface a hard-vetoed command (label '{$violation}').",
            );
        }

        $explanation = (string) ($intentPacket['risk_reasoning'] ?? 'comando proposto pelo operador via Vox V3 — execução é manual.');

        return $this->outcomes->terminalProposed(
            intentPacket: $intentPacket,
            receipt: $receipt,
            proposedCommand: $proposed,
            explanation: $explanation,
            metadataExtras: [
                'risk_class' => (string) ($intentPacket['risk_class'] ?? VoxSchema::RISK_R0),
                'output_format' => (string) ($intentPacket['output_format'] ?? 'command_proposal'),
            ],
        );
    }
}
