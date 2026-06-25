<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\AutonomousRuntime;

/**
 * Pure gate. Decides whether the autonomous self-construction runtime should STOP, HOLD, or CONTINUE.
 *
 * INPUT FACTS (no provider call, no worker self-report — only court/governor/queue facts):
 *   { verification_court:{verdict?:string, server_side_green?:bool},
 *     merge_governor:{decision?:string},
 *     rollback_gate:{conformant?:bool},
 *     malformed_task_sweep:{count?:int, sample?:list<string>},
 *     give_back_class:{repeated_class?:string|null, repeated_count?:int},
 *     scope_drift:{count?:int},
 *     context_freshness:{conformant?:bool, blockers?:list<string>} }
 *
 * OUTPUT:
 *   { schema, action ∈ {stop,hold,continue}, reasons:list<string>, evidence:array }
 *
 * STOP CLASSES (any present ⇒ stop):
 *   - verification_court_red
 *   - rollback_missing
 *   - malformed_task_sweep:<count>
 *   - repeated_give_back_class:<class>:<count>
 *   - scope_drift:<count>
 *   - merge_governor_rejected
 *
 * HOLD CLASSES (no STOPs but at least one ⇒ hold):
 *   - context_freshness_blocked
 *
 * INVARIANTS:
 *   - NEVER treats worker self-report as final safety evidence.
 *   - DETERMINISTIC envelope (reasons sorted).
 *   - PURE.
 */
final class AtlasAutonomousRuntimeSafetyStopGate
{
    public const SCHEMA = 'atlas.autonomousruntime.safety_stop.v1';

    public const ACTION_STOP = 'stop';

    public const ACTION_HOLD = 'hold';

    public const ACTION_CONTINUE = 'continue';

    public const REPEATED_GIVE_BACK_THRESHOLD = 3;

    /**
     * @param  array<string,array<string,mixed>>  $facts
     * @return array{schema:string, action:string, reasons:list<string>, evidence:array<string,mixed>}
     */
    public function evaluate(array $facts): array
    {
        $stop = [];
        $hold = [];

        // STOP: verification court red.
        $court = is_array($facts['verification_court'] ?? null) ? $facts['verification_court'] : [];
        $verdict = (string) ($court['verdict'] ?? '');
        $ssg = (bool) ($court['server_side_green'] ?? false);
        if ($verdict === 'failed' || ($verdict !== '' && ! $ssg)) {
            $stop[] = 'verification_court_red';
        }

        // STOP: rollback missing.
        $rollback = is_array($facts['rollback_gate'] ?? null) ? $facts['rollback_gate'] : [];
        if (! (bool) ($rollback['conformant'] ?? false)) {
            $stop[] = 'rollback_missing';
        }

        // STOP: malformed task sweep (any malformed packet detected).
        $malformed = is_array($facts['malformed_task_sweep'] ?? null) ? $facts['malformed_task_sweep'] : [];
        $malformedCount = (int) ($malformed['count'] ?? 0);
        if ($malformedCount > 0) {
            $stop[] = 'malformed_task_sweep:'.$malformedCount;
        }

        // STOP: repeated give-back class.
        $gb = is_array($facts['give_back_class'] ?? null) ? $facts['give_back_class'] : [];
        $gbClass = (string) ($gb['repeated_class'] ?? '');
        $gbCount = (int) ($gb['repeated_count'] ?? 0);
        if ($gbClass !== '' && $gbCount >= self::REPEATED_GIVE_BACK_THRESHOLD) {
            $stop[] = 'repeated_give_back_class:'.$gbClass.':'.$gbCount;
        }

        // STOP: scope drift.
        $scope = is_array($facts['scope_drift'] ?? null) ? $facts['scope_drift'] : [];
        $driftCount = (int) ($scope['count'] ?? 0);
        if ($driftCount > 0) {
            $stop[] = 'scope_drift:'.$driftCount;
        }

        // STOP: merge governor rejected.
        $mg = is_array($facts['merge_governor'] ?? null) ? $facts['merge_governor'] : [];
        $mgDecision = (string) ($mg['decision'] ?? '');
        if (in_array($mgDecision, ['rejected', 'blocked'], true)) {
            $stop[] = 'merge_governor_rejected';
        }

        // HOLD: context freshness blocked.
        $cf = is_array($facts['context_freshness'] ?? null) ? $facts['context_freshness'] : [];
        if (isset($cf['conformant']) && ! (bool) $cf['conformant']) {
            $hold[] = 'context_freshness_blocked';
            foreach ((array) ($cf['blockers'] ?? []) as $b) {
                $hold[] = 'context_freshness:'.(string) $b;
            }
        }

        $allReasons = array_merge($stop, $hold);
        $allReasons = array_values(array_unique($allReasons));
        sort($allReasons, SORT_STRING);

        $action = self::ACTION_CONTINUE;
        if ($stop !== []) {
            $action = self::ACTION_STOP;
        } elseif ($hold !== []) {
            $action = self::ACTION_HOLD;
        }

        return [
            'schema' => self::SCHEMA,
            'action' => $action,
            'reasons' => $allReasons,
            'evidence' => [
                'court_verdict' => $verdict,
                'court_server_side_green' => $ssg,
                'rollback_conformant' => (bool) ($rollback['conformant'] ?? false),
                'malformed_count' => $malformedCount,
                'give_back_repeated_class' => $gbClass,
                'give_back_repeated_count' => $gbCount,
                'scope_drift_count' => $driftCount,
                'merge_governor_decision' => $mgDecision,
                'context_freshness_conformant' => (bool) ($cf['conformant'] ?? false),
            ],
        ];
    }
}
