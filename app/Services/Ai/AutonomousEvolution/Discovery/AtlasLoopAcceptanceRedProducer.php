<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHermeticCommandEnvironment;
use Symfony\Component\Process\Process;

/**
 * §11.3 — OBRA EARNED-RED BRIDGE. Today obra plan-readiness accepts a node's acceptance by
 * FIELD PRESENCE / keyword overlap: if the node carries an acceptance string that overlaps the
 * objective, it is treated as satisfiable — but the acceptance is NEVER proven to actually FAIL
 * first. That is the same Goodhart hole the bug-fix lane closed for the reproduction case: a
 * criterion that was never reproducibly RED proves nothing, because you cannot "fix" something
 * that already passes. A green-on-arrival acceptance command is not a bar; it is a no-op the
 * implementation can satisfy by changing nothing.
 *
 * This producer fuses an asserted acceptance criterion into a Lane-1 acceptance artifact and
 * VERIFIES the criterion is RED against the pre-implementation tree — an EARNED red, not an
 * asserted one. The acceptance command is run for real in the workspace; only a command that
 * currently FAILS (non-zero exit) clears the earned-RED bar. This converts "the node has an
 * acceptance field" into "the node has an acceptance that is provably unsatisfied today and
 * therefore can be earned by the work".
 *
 * DETERMINISTIC + provider-free + fail-closed:
 *  - No acceptance_command on the criterion ⇒ null. A node with no runnable acceptance is never
 *    accepted; field presence alone is not a contract.
 *  - The RED verdict is derived from the REAL exit code of the REAL command, not from any
 *    field the caller asserted. The producer cannot be talked into earned_red:true by a node
 *    that merely claims it.
 */
final class AtlasLoopAcceptanceRedProducer
{
    /** Advisory work-shape tag for the obra-node acceptance artifact. */
    public const SHAPE = 'obra_node';

    /** Hard ceiling (seconds) so a hung acceptance command can never wedge discovery. */
    private const COMMAND_TIMEOUT_SECONDS = 120;

    /**
     * Run an acceptance command against the pre-implementation tree and report whether it is
     * currently RED (the earned-RED bar). A command that exits 0 is already GREEN and is
     * therefore NOT a valid acceptance-RED — you cannot earn a criterion that already passes.
     *
     * @return array{ran:bool, red:bool, exit:int, command:string}
     */
    public function verifyRed(string $acceptanceCommand, string $cwd): array
    {
        $process = Process::fromShellCommandline(
            $acceptanceCommand,
            $cwd,
            AtlasLoopHermeticCommandEnvironment::forAcceptance(),
            null,
            (float) self::COMMAND_TIMEOUT_SECONDS,
        );

        $process->run();

        $exit = $process->getExitCode() ?? 1;

        return [
            'ran' => true,
            // RED == the acceptance currently FAILS. Non-zero exit is the earned-RED bar;
            // exit 0 (already green) is red=false — not a valid acceptance-RED.
            'red' => $exit !== 0,
            'exit' => $exit,
            'command' => $acceptanceCommand,
        ];
    }

    /**
     * Fuse a criterion into a Lane-1 earned-RED acceptance artifact, or null when the criterion
     * carries no runnable acceptance (fail-closed: never accept a node with no runnable
     * acceptance).
     *
     * Input shape: {objective, acceptance_command}.
     *
     * @param  array<string,mixed>  $criterion
     * @return array{objective:string, acceptance:array{commands:array<int,string>, red_required:true}, earned_red:bool, shape:string}|null
     */
    public function toEarnedRedAcceptance(array $criterion, string $cwd): ?array
    {
        $command = $this->normalizeCommand($criterion['acceptance_command'] ?? null);

        // Fail-closed: no runnable acceptance ⇒ no node. Field presence is not a contract.
        if ($command === null) {
            return null;
        }

        $verification = $this->verifyRed($command, $cwd);

        return [
            'objective' => (string) ($criterion['objective'] ?? ''),
            'acceptance' => [
                'commands' => [$command],
                'red_required' => true,
            ],
            // earned_red is the REAL verifyRed verdict on the command, not an asserted field.
            'earned_red' => $verification['red'],
            'shape' => self::SHAPE,
        ];
    }

    /**
     * Normalize the asserted acceptance command to a non-empty runnable string, or null when it
     * is absent/blank. A whitespace-only command is treated as no command (fail-closed).
     */
    private function normalizeCommand(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        $trimmed = trim($raw);

        return $trimmed === '' ? null : $trimmed;
    }
}
