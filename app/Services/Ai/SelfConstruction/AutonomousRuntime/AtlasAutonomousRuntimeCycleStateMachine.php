<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\AutonomousRuntime;

/**
 * Pure Atlas-native 24/7 self-construction cycle state machine.
 *
 * Ordered cycle:
 *   observe → decide → architect → packetize → schedule → execute → verify → merge_or_reject
 *   → knowledge_sync → learn → replan → observe (loop)
 *
 * Any active state may be interrupted into `safety_stop`. The ONLY way out of safety_stop is an
 * explicit Atlas-native resume FACT carrying `atlas_native_resume = true`; the machine returns to
 * `observe` so the next cycle starts from a clean orient.
 *
 * Pure — no providers, no clocks read internally, no filesystem mutation. The state lives on the
 * instance.
 */
final class AtlasAutonomousRuntimeCycleStateMachine
{
    public const OBSERVE = 'observe';

    public const DECIDE = 'decide';

    public const ARCHITECT = 'architect';

    public const PACKETIZE = 'packetize';

    public const SCHEDULE = 'schedule';

    public const EXECUTE = 'execute';

    public const VERIFY = 'verify';

    public const MERGE_OR_REJECT = 'merge_or_reject';

    public const KNOWLEDGE_SYNC = 'knowledge_sync';

    public const LEARN = 'learn';

    public const REPLAN = 'replan';

    public const SAFETY_STOP = 'safety_stop';

    public const CYCLE = [
        self::OBSERVE,
        self::DECIDE,
        self::ARCHITECT,
        self::PACKETIZE,
        self::SCHEDULE,
        self::EXECUTE,
        self::VERIFY,
        self::MERGE_OR_REJECT,
        self::KNOWLEDGE_SYNC,
        self::LEARN,
        self::REPLAN,
    ];

    private string $state = self::OBSERVE;

    public function state(): string
    {
        return $this->state;
    }

    /**
     * Attempt a transition. Returns a verdict envelope.
     *
     * @param  array<string,mixed>  $fact  optional facts the caller attached to the transition request
     * @return array{accepted:bool, from:string, to:string, reason:?string}
     */
    public function transitionTo(string $next, array $fact = []): array
    {
        $from = $this->state;

        if ($next === self::SAFETY_STOP) {
            if ($from === self::SAFETY_STOP) {
                return $this->reject($from, $next, 'already_in_safety_stop');
            }
            $this->state = self::SAFETY_STOP;

            return ['accepted' => true, 'from' => $from, 'to' => self::SAFETY_STOP, 'reason' => null];
        }

        if ($from === self::SAFETY_STOP) {
            if (! (bool) ($fact['atlas_native_resume'] ?? false)) {
                return $this->reject($from, $next, 'safety_stop_requires_atlas_native_resume_fact');
            }
            if ($next !== self::OBSERVE) {
                return $this->reject($from, $next, 'safety_stop_only_resumes_to_observe');
            }
            $this->state = self::OBSERVE;

            return ['accepted' => true, 'from' => $from, 'to' => self::OBSERVE, 'reason' => null];
        }

        $expected = $this->nextDeclared($from);
        if ($expected === null) {
            return $this->reject($from, $next, 'unknown_active_state:'.$from);
        }
        if ($next !== $expected) {
            return $this->reject($from, $next, 'invalid_transition_expected:'.$expected);
        }

        $this->state = $next;

        return ['accepted' => true, 'from' => $from, 'to' => $next, 'reason' => null];
    }

    /**
     * @return array{accepted:false, from:string, to:string, reason:string}
     */
    private function reject(string $from, string $to, string $reason): array
    {
        return ['accepted' => false, 'from' => $from, 'to' => $to, 'reason' => $reason];
    }

    private function nextDeclared(string $current): ?string
    {
        $idx = array_search($current, self::CYCLE, true);
        if ($idx === false) {
            return null;
        }

        return self::CYCLE[($idx + 1) % count(self::CYCLE)];
    }
}
