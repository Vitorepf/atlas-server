<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\ModelCheck;

use App\Services\Ai\AutonomousEvolution\AtlasLoopCycleGitContract;
use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;

/**
 * Read-only extractor that emits a deterministic finite state machine of the canonical Loop cycle.
 *
 * States: the 8 canonical phases + ENTRY + ERROR + TERMINAL. Each transition references a real
 * Atlas symbol (class::method or class::CONST) as the guard source so the artifact is
 * model-checkable without inferring fake guards.
 *
 * Pure: byte-identical when ATLAS_LOOP_MASTER_ENABLED is OFF; no cycle execution, no git mutation.
 */
final class AtlasLoopCycleStateMachineExtractor
{
    public const SCHEMA = 'atlas.loop.cycle_state_machine.v1';

    public const STATE_ENTRY = 'ENTRY';

    public const STATE_ORIENT = 'orient';

    public const STATE_COMPREHEND = 'comprehend';

    public const STATE_DECIDE_LEVERAGE = 'decide_leverage';

    public const STATE_ARCHITECT = 'architect';

    public const STATE_DECOMPOSE = 'decompose';

    public const STATE_IMPLEMENT = 'implement';

    public const STATE_CERTIFY = 'certify';

    public const STATE_CLOSE_ON_MAIN = 'close_on_main';

    public const STATE_LEARN = 'learn';

    public const STATE_ERROR = 'ERROR';

    public const STATE_TERMINAL = 'TERMINAL';

    /**
     * @return array<string,mixed>
     */
    public function extract(): array
    {
        $states = [
            self::STATE_ENTRY,
            self::STATE_ORIENT,
            self::STATE_COMPREHEND,
            self::STATE_DECIDE_LEVERAGE,
            self::STATE_ARCHITECT,
            self::STATE_DECOMPOSE,
            self::STATE_IMPLEMENT,
            self::STATE_CERTIFY,
            self::STATE_CLOSE_ON_MAIN,
            self::STATE_LEARN,
            self::STATE_ERROR,
            self::STATE_TERMINAL,
        ];

        $masterSwitch = AtlasLoopMasterSwitch::class.'::KEY';
        $gitContract = AtlasLoopCycleGitContract::class;
        $commit = $gitContract.'::commitCycle';
        $merge = $gitContract.'::mergeToMain';
        $start = $gitContract.'::startCycle';
        $discard = $gitContract.'::discardCycle';

        $transitions = [
            ['from' => self::STATE_ENTRY, 'to' => self::STATE_ORIENT, 'guard' => $masterSwitch.'==true'],
            ['from' => self::STATE_ENTRY, 'to' => self::STATE_TERMINAL, 'guard' => $masterSwitch.'==false'],
            ['from' => self::STATE_ORIENT, 'to' => self::STATE_COMPREHEND, 'guard' => $start.'.success'],
            ['from' => self::STATE_COMPREHEND, 'to' => self::STATE_DECIDE_LEVERAGE, 'guard' => 'comprehension.passed'],
            ['from' => self::STATE_DECIDE_LEVERAGE, 'to' => self::STATE_ARCHITECT, 'guard' => 'leverage.decided'],
            ['from' => self::STATE_ARCHITECT, 'to' => self::STATE_DECOMPOSE, 'guard' => 'architecture.approved'],
            ['from' => self::STATE_DECOMPOSE, 'to' => self::STATE_IMPLEMENT, 'guard' => 'decomposition.complete'],
            ['from' => self::STATE_IMPLEMENT, 'to' => self::STATE_CERTIFY, 'guard' => $commit.'.success'],
            ['from' => self::STATE_CERTIFY, 'to' => self::STATE_CLOSE_ON_MAIN, 'guard' => 'verification.passed'],
            ['from' => self::STATE_CERTIFY, 'to' => self::STATE_ERROR, 'guard' => 'verification.failed'],
            ['from' => self::STATE_CLOSE_ON_MAIN, 'to' => self::STATE_LEARN, 'guard' => $merge.'.success'],
            ['from' => self::STATE_CLOSE_ON_MAIN, 'to' => self::STATE_ERROR, 'guard' => $merge.'.failed'],
            ['from' => self::STATE_LEARN, 'to' => self::STATE_TERMINAL, 'guard' => 'learning.recorded'],
            ['from' => self::STATE_ERROR, 'to' => self::STATE_TERMINAL, 'guard' => $discard.'.success'],
        ];

        sort($states, SORT_STRING);
        usort($transitions, static function (array $a, array $b): int {
            $c = strcmp($a['from'], $b['from']);

            return $c !== 0 ? $c : strcmp($a['to'], $b['to']);
        });

        $payload = [
            'schema' => self::SCHEMA,
            'entry_state' => self::STATE_ENTRY,
            'terminal_states' => [self::STATE_TERMINAL, self::STATE_ERROR],
            'master_switch_gate' => $masterSwitch,
            'states' => $states,
            'transitions' => $transitions,
        ];
        $payload['fingerprint'] = 'fsm_'.substr(hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), 0, 24);

        return $payload;
    }
}
