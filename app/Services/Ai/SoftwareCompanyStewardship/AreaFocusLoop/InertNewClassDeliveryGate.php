<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * HARD LAW (operator mandate, 2026-05-31): a "new class + passing test" cycle
 * that is INERT at runtime — the class is added under app/, a unit test proves
 * it in isolation, but NOTHING in the running product references it — must NOT
 * count as a useful autonomy cycle.
 *
 * This is the dominant progress-theater pattern that survives every existing
 * gate: {@see FinalDeliveryQualityGateService} only catches self-incriminating
 * scaffold/mock markers; a clean, fully-implemented, well-tested class that no
 * runtime path calls passes all of them. Over an unattended month the loop
 * accretes a library of orphan deciders, each "100% final" and green, while the
 * running system behaves identically to day zero.
 *
 * This decider is PURE: dependency-free, no I/O, no git, no filesystem. The
 * caller computes — out of band — which new product classes a cycle introduced
 * and which of those are runtime-consumed (referenced by a non-test file under
 * app/ other than the class's own file), then passes those two lists here.
 *
 * Verdicts:
 *   - runtime_integrated      — >=1 newly-introduced class is runtime-consumed.
 *                               The cycle wired something real; useful.
 *   - library_pending_wiring  — new classes exist, none are consumed, but EVERY
 *                               unconsumed new class is HONESTLY declared pending
 *                               (operator/finding-marked library work) and the
 *                               cycle does NOT falsely claim runtime usefulness.
 *                               An allowed, honest outcome — but it is NOT
 *                               autonomy and must NOT be counted as a useful
 *                               runtime cycle.
 *   - blocked_inert_delivery  — new classes exist, none are consumed, and they
 *                               are NOT honestly marked pending. This is the
 *                               progress-theater case: BLOCK it.
 *
 * A cycle that introduced NO new product class (pure edit/bugfix to existing
 * runtime code) is runtime_integrated by construction — it changed live code,
 * so there is no inert-new-class risk to gate.
 */
final class InertNewClassDeliveryGate
{
    public const SCHEMA_VERSION = 'atlas.software_company_stewardship.inert_new_class_delivery_gate.v1';

    public const STATUS_RUNTIME_INTEGRATED = 'runtime_integrated';

    public const STATUS_LIBRARY_PENDING = 'library_pending_wiring';

    public const STATUS_BLOCKED_INERT = 'blocked_inert_delivery';

    public const BLOCKER = 'inert_new_class_delivery_not_runtime_wired';

    /**
     * @param  list<string>  $changedClasses           every NEW product class the cycle introduced
     * @param  list<string>  $runtimeConsumedClasses   the subset referenced by a non-test app/ file other than the class's own file
     * @param  list<string>  $libraryPendingClasses    new classes the operator/finding honestly declared as pending-wiring library work
     * @return array{
     *     schema_version: string,
     *     useful_runtime_wiring: bool,
     *     changed_classes: list<string>,
     *     runtime_consumed_classes: list<string>,
     *     inert_new_classes: list<string>,
     *     library_pending_classes: list<string>,
     *     cycle_usefulness_status: 'runtime_integrated'|'library_pending_wiring'|'blocked_inert_delivery',
     *     blocker: string|null,
     *     reason: string
     * }
     */
    public function evaluate(array $changedClasses, array $runtimeConsumedClasses, array $libraryPendingClasses = []): array
    {
        $changed = $this->normalizeList($changedClasses);
        $consumed = array_values(array_intersect($this->normalizeList($runtimeConsumedClasses), $changed));
        $pending = array_values(array_intersect($this->normalizeList($libraryPendingClasses), $changed));

        // Inert = introduced this cycle, not consumed by any runtime file.
        $inert = array_values(array_diff($changed, $consumed));

        // No new product class at all: the cycle edited existing runtime code,
        // there is no inert-new-class risk — treat as runtime-integrated.
        if ($changed === []) {
            return $this->result(true, $changed, $consumed, $inert, $pending, self::STATUS_RUNTIME_INTEGRATED, null, 'no_new_product_class_introduced');
        }

        // At least one NEW class is actually wired into a runtime path: useful.
        if ($consumed !== []) {
            return $this->result(true, $changed, $consumed, $inert, $pending, self::STATUS_RUNTIME_INTEGRATED, null, 'new_class_runtime_consumed:'.implode(',', $consumed));
        }

        // From here: new classes exist and NONE is runtime-consumed.
        // Honest library work => every inert class must be explicitly pending.
        $unpending = array_values(array_diff($inert, $pending));
        if ($unpending === []) {
            return $this->result(false, $changed, $consumed, $inert, $pending, self::STATUS_LIBRARY_PENDING, null, 'all_inert_new_classes_declared_pending_wiring:'.implode(',', $inert));
        }

        // New, unconsumed, and NOT honestly declared pending => progress theater.
        return $this->result(false, $changed, $consumed, $inert, $pending, self::STATUS_BLOCKED_INERT, self::BLOCKER, 'inert_new_classes_not_runtime_wired_and_not_marked_pending:'.implode(',', $unpending));
    }

    /**
     * @param  list<string>  $changed
     * @param  list<string>  $consumed
     * @param  list<string>  $inert
     * @param  list<string>  $pending
     * @return array{schema_version:string, useful_runtime_wiring:bool, changed_classes:list<string>, runtime_consumed_classes:list<string>, inert_new_classes:list<string>, library_pending_classes:list<string>, cycle_usefulness_status:string, blocker:string|null, reason:string}
     */
    private function result(bool $useful, array $changed, array $consumed, array $inert, array $pending, string $status, ?string $blocker, string $reason): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'useful_runtime_wiring' => $useful,
            'changed_classes' => $changed,
            'runtime_consumed_classes' => $consumed,
            'inert_new_classes' => $inert,
            'library_pending_classes' => $pending,
            'cycle_usefulness_status' => $status,
            'blocker' => $blocker,
            'reason' => $reason,
        ];
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function normalizeList(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }
            $trimmed = trim($value);
            if ($trimmed === '') {
                continue;
            }
            $out[$trimmed] = true;
        }

        return array_keys($out);
    }
}
