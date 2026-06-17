<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * ACDE DC4 — the CHANGE-CLASS landing-rate prior (the decide-time read-back on the objective_kind axis).
 *
 * M1's {@see AtlasLoopWorkClassPriorService} priors by TARGET PATH (where the code lives). DC4 priors by
 * CHANGE CLASS (what KIND of change it is — refactor / feature / characterization …), the orthogonal axis a
 * path token cannot capture: a `feature_*` obra and a `refactor_*` obra on the same file land at very
 * different rates on a weak engine. DC4 reads the EXISTING `atlas_loop_decomposition_outcomes` ledger (which
 * already records {objective_kind, certified} per executed obra — NO new table, the M1 anti-refragmentation
 * precedent), groups by a normalized change-class, and returns a Wilson-lower-bound certified-rate per class.
 *
 * THE CLASS-STRING SEMANTIC FIX: the ledger stores the FULL objective_kind (refactor_extract_method,
 * refactor_extract_class, feature_sequenced, …). Bucketing on the raw string fragments a sparse corpus into
 * thin per-variant cells that never reach significance AND mismatches the planner's family semantics
 * ({@see \App\Services\Ai\AutonomousEvolution\AtlasLoopObraExecutionAdapter::planAbstentionReceipt}, which
 * keys on the family = the token before the first `_`). {@see changeClass()} normalizes to that SAME family
 * token — lower/trim, empty/null => 'unknown' — so samples accumulate per family and the class string means
 * the same thing on both the write (abstention receipt) and read (this prior) sides.
 *
 * Anchored on the machine-resolved terminal `certified` boolean only, never a model self-report. The DB read
 * is fail-OPEN (missing table / query error / container-less caller => empty prior => never a false
 * hopeless verdict). The pure {@see aggregate} core takes raw rows so it is unit-testable without a database.
 */
final class AtlasLoopChangeClassPriorService
{
    /**
     * Canonical normalized change-class token for an objective_kind: the family (the segment before the first
     * `_`), lower/trimmed; empty/null => 'unknown'. The SINGLE source of the change-class string so the write
     * (abstention receipt family) and read (this prior) sides bucket identically.
     */
    public function changeClass(string $objectiveKind): string
    {
        $kind = mb_strtolower(trim($objectiveKind));
        if ($kind === '') {
            return 'unknown';
        }
        $family = (string) (explode('_', $kind, 2)[0] ?? $kind);

        return $family !== '' ? $family : 'unknown';
    }

    /**
     * The landing-rate prior for the change-class of $objectiveKind over a recent window of the decomposition
     * outcomes ledger. Fail-OPEN: any missing table / query error / container-less context returns the empty
     * prior (enough_samples=false, hopeless=false), so the decider never abstains on no evidence.
     *
     * @return array{change_class:string, samples:int, certified:int, landing_rate:float, wilson_lower:float,
     *               enough_samples:bool, hopeless:bool, min_samples:int, floor_rate:float}
     */
    public function priorFor(string $objectiveKind, ?int $hours = null): array
    {
        $changeClass = $this->changeClass($objectiveKind);
        $minSamples = max(1, (int) config('atlas.loop.change_class_prior_min_samples', 8));
        $floorRate = max(0.0, min(1.0, (float) config('atlas.loop.change_class_prior_floor_rate', 0.15)));
        $window = max(1, min(8760, (int) ($hours ?? config('atlas.loop.change_class_prior_window_hours', 720))));

        $empty = $this->emptyPrior($changeClass, $minSamples, $floorRate);

        if (! DatabaseTableAvailability::all(['atlas_loop_decomposition_outcomes'])) {
            return $empty;
        }

        try {
            $rows = DB::table('atlas_loop_decomposition_outcomes')
                ->where('updated_at', '>=', Carbon::now()->subHours($window))
                ->limit(8000)
                ->get(['objective_kind', 'certified'])
                ->map(static fn ($r): array => [
                    'objective_kind' => (string) ($r->objective_kind ?? ''),
                    'certified' => (bool) $r->certified,
                ])
                ->all();
        } catch (Throwable) {
            return $empty;
        }

        $byClass = $this->aggregate($rows, $minSamples, $floorRate);

        return $byClass[$changeClass] ?? $empty;
    }

    /**
     * PURE aggregation core (no DB, no container) — group raw outcome rows by normalized change-class and
     * compute a Wilson-lower-bound certified-rate per class. Unit-testable with synthetic rows.
     *
     * @param  list<array{objective_kind:string, certified:bool}>  $rows
     * @return array<string, array{change_class:string, samples:int, certified:int, landing_rate:float,
     *                  wilson_lower:float, enough_samples:bool, hopeless:bool, min_samples:int, floor_rate:float}>
     */
    public function aggregate(array $rows, int $minSamples, float $floorRate): array
    {
        $minSamples = max(1, $minSamples);
        $floorRate = max(0.0, min(1.0, $floorRate));

        /** @var array<string, array{samples:int, certified:int}> $tally */
        $tally = [];
        foreach ($rows as $row) {
            $class = $this->changeClass((string) ($row['objective_kind'] ?? ''));
            $tally[$class] ??= ['samples' => 0, 'certified' => 0];
            $tally[$class]['samples']++;
            if ((bool) ($row['certified'] ?? false)) {
                $tally[$class]['certified']++;
            }
        }

        $out = [];
        foreach ($tally as $class => $t) {
            $samples = (int) $t['samples'];
            $certified = (int) $t['certified'];
            $wilson = $this->wilsonLower($certified, $samples);
            $enough = $samples >= $minSamples;
            $out[$class] = [
                'change_class' => $class,
                'samples' => $samples,
                'certified' => $certified,
                'landing_rate' => $samples > 0 ? round($certified / $samples, 4) : 0.0,
                'wilson_lower' => round($wilson, 4),
                'enough_samples' => $enough,
                // HOPELESS only with enough samples AND a Wilson-LB below the floor — never on a thin cell.
                'hopeless' => $enough && $wilson < $floorRate,
                'min_samples' => $minSamples,
                'floor_rate' => $floorRate,
            ];
        }

        return $out;
    }

    /**
     * ACDE DC6 — MAX-UNCERTAINTY-with-negative-lean: the change-class has SOME evidence (>=1 sample) but not
     * yet enough to call it hopeless, AND that thin evidence already leans BELOW the floor. This is the danger
     * band where the loop has the least basis to judge yet the early signal is bad — the calibrated move is to
     * ASK the operator before burning more budget, not to guess. Distinct from {@see priorFor}'s `hopeless`
     * (which needs enough_samples): DC6 fires PRE-hopeless. A zero-sample (truly unknown) class never fires —
     * abstaining on no evidence at all would stall the loop on every fresh class.
     *
     * @param  array{samples?:int, enough_samples?:bool, hopeless?:bool, landing_rate?:float, floor_rate?:float}  $prior
     */
    public function thinPriorMaxUncertainty(array $prior): bool
    {
        $samples = (int) ($prior['samples'] ?? 0);
        if ($samples < 1 || ($prior['enough_samples'] ?? false) === true || ($prior['hopeless'] ?? false) === true) {
            return false; // no evidence, or already enough/hopeless (DC4 owns those) => not DC6's band
        }

        return (float) ($prior['landing_rate'] ?? 0.0) < (float) ($prior['floor_rate'] ?? 0.15);
    }

    /**
     * @return array{change_class:string, samples:int, certified:int, landing_rate:float, wilson_lower:float,
     *               enough_samples:bool, hopeless:bool, min_samples:int, floor_rate:float}
     */
    private function emptyPrior(string $changeClass, int $minSamples, float $floorRate): array
    {
        return [
            'change_class' => $changeClass,
            'samples' => 0,
            'certified' => 0,
            'landing_rate' => 0.0,
            'wilson_lower' => 0.0,
            'enough_samples' => false,
            'hopeless' => false,
            'min_samples' => $minSamples,
            'floor_rate' => $floorRate,
        ];
    }

    /** Wilson score lower bound (95%, z=1.96) for s successes in n trials. 0 when n<=0. */
    private function wilsonLower(int $s, int $n): float
    {
        if ($n <= 0) {
            return 0.0;
        }
        $z = 1.96;
        $phat = $s / $n;
        $z2 = $z * $z;
        $denom = 1.0 + $z2 / $n;
        $centre = $phat + $z2 / (2 * $n);
        $margin = $z * sqrt(($phat * (1.0 - $phat) + $z2 / (4 * $n)) / $n);

        return max(0.0, ($centre - $margin) / $denom);
    }
}
