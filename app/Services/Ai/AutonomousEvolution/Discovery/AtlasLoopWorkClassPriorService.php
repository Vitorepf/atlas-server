<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * ACDE lever M1 — the work-class landing-rate prior (the DECIDE-front read-back).
 *
 * The loop's deciders (next-work priority, EV, ambition) pick WHAT to grind next with NO memory of which
 * CLASSES of work actually land. The per-TARGET hopeless gate ({@see AtlasLoopExplorerStrategyBanditService::
 * targetPathVerdict}) only fires AFTER a single file has burned N attempts; it cannot generalize to a brand-new
 * file of a class that empirically never certifies. M1 closes that gap: it reads the EXISTING, proven
 * `atlas_loop_explorations` + `atlas_loop_tasks` ledger (no new table, no new write — the per-attempt
 * {target_path, passed} is already recorded), groups attempts by a DETERMINISTIC work-class token derived from
 * the target path, and returns a Wilson-lower-bound landing rate per class. A class with many REAL attempts and
 * a low Wilson-LB is "hopeless" — the next-work priority de-prioritizes it BEFORE it burns budget.
 *
 * Why a WEAK engine beats a strong single pass here: a strong model in one pass guesses, from the prompt alone,
 * whether a class of work will land; M1 replaces the guess with the loop's own measured landing rate over the
 * last N attempts in THIS repo — a cross-delivery memory no single pass has. The signal is machine-resolved
 * (the `passed` boolean is the frozen gate's verdict), never a model self-report.
 *
 * Anti-gaming: ONLY attempts that genuinely invoked a provider with a non-trivial token count are counted — the
 * SAME fail-closed rule the bandit uses — so an injected/fabricated row cannot manufacture a prior. The DB read
 * is fail-OPEN (a missing table / query error / container-less caller returns an EMPTY prior, never a false
 * de-prioritization). The pure {@see aggregate} core takes raw rows so it is unit-testable without a database.
 */
final class AtlasLoopWorkClassPriorService
{
    /**
     * Deterministic work-class token for a target path: up to the first 3 directory segments (the "kind of
     * code" bucket — coarse enough to accumulate samples across files, fine enough to separate app/Services/Ai
     * from app/Models or database/migrations). Provider-safe (a path family, never content).
     */
    public function workClass(string $targetPath): string
    {
        $path = ltrim(str_replace('\\', '/', trim($targetPath)), '/');
        if ($path === '') {
            return 'unknown';
        }
        $dir = trim((string) (str_contains($path, '/') ? dirname($path) : ''), '/');
        if ($dir === '' || $dir === '.') {
            return 'root';
        }
        $segments = array_values(array_filter(explode('/', $dir), static fn (string $s): bool => $s !== ''));

        return implode('/', array_slice($segments, 0, 3));
    }

    /**
     * The landing-rate prior for the work-class of $targetPath, computed over a recent window of the existing
     * explorations ledger. Fail-OPEN: any missing table / query error / container-less context returns the
     * empty prior (enough_samples=false, hopeless=false), so the decider never de-prioritizes on no evidence.
     *
     * @return array{work_class:string, real_attempts:int, certified:int, landing_rate:float, wilson_lower:float,
     *               enough_samples:bool, hopeless:bool, min_attempts:int, floor_rate:float}
     */
    public function priorFor(string $targetPath, ?int $hours = null): array
    {
        $workClass = $this->workClass($targetPath);
        $minAttempts = max(1, (int) config('atlas.loop.work_class_prior_min_attempts', 8));
        $floorRate = max(0.0, min(1.0, (float) config('atlas.loop.work_class_prior_floor_rate', 0.15)));
        $minRealTokens = max(1, (int) config('atlas.loop.explorer_bandit.min_real_tokens', 10));
        $window = max(1, min(2160, (int) ($hours ?? config('atlas.loop.work_class_prior_window_hours', 336))));

        $empty = $this->emptyPrior($workClass, $minAttempts, $floorRate);

        if (! DatabaseTableAvailability::all(['atlas_loop_explorations', 'atlas_loop_tasks'])) {
            return $empty;
        }

        try {
            $rows = DB::table('atlas_loop_explorations as e')
                ->join('atlas_loop_tasks as t', 't.id', '=', 'e.task_id')
                ->where('e.updated_at', '>=', Carbon::now()->subHours($window))
                ->limit(4000)
                ->get(['t.target_path', 'e.attempt_metrics'])
                ->map(static fn ($r): array => ['target_path' => (string) ($r->target_path ?? ''), 'attempt_metrics' => $r->attempt_metrics ?? null])
                ->all();
        } catch (Throwable) {
            return $empty;
        }

        $byClass = $this->aggregate($rows, $minRealTokens, $minAttempts, $floorRate);

        return $byClass[$workClass] ?? $empty;
    }

    /**
     * PURE aggregation core (no DB, no container) — group raw explorations rows by derived work-class and
     * compute a Wilson-lower-bound landing rate per class. Unit-testable with synthetic rows.
     *
     * @param  list<array{target_path:string, attempt_metrics:mixed}>  $rows
     * @return array<string, array{work_class:string, real_attempts:int, certified:int, landing_rate:float,
     *                  wilson_lower:float, enough_samples:bool, hopeless:bool, min_attempts:int, floor_rate:float}>
     */
    public function aggregate(array $rows, int $minRealTokens, int $minAttempts, float $floorRate): array
    {
        $minRealTokens = max(1, $minRealTokens);
        $minAttempts = max(1, $minAttempts);
        $floorRate = max(0.0, min(1.0, $floorRate));

        /** @var array<string, array{real:int, certified:int}> $tally */
        $tally = [];
        foreach ($rows as $row) {
            $class = $this->workClass((string) ($row['target_path'] ?? ''));
            foreach ($this->arrayPayload($row['attempt_metrics'] ?? null) as $attempt) {
                if (! is_array($attempt)) {
                    continue;
                }
                $providerInvoked = ($attempt['provider_invoked'] ?? null) === true;
                $tokens = is_numeric($attempt['tokens_used'] ?? null) ? max(0, (int) $attempt['tokens_used']) : null;
                if (! $providerInvoked || $tokens === null || $tokens < $minRealTokens) {
                    continue; // only REAL attempts count (anti-gaming, identical to the bandit's rule)
                }
                $tally[$class] ??= ['real' => 0, 'certified' => 0];
                $tally[$class]['real']++;
                if ((bool) ($attempt['passed'] ?? false)) {
                    $tally[$class]['certified']++;
                }
            }
        }

        $out = [];
        foreach ($tally as $class => $t) {
            $real = (int) $t['real'];
            $certified = (int) $t['certified'];
            $wilson = $this->wilsonLower($certified, $real);
            $enough = $real >= $minAttempts;
            $out[$class] = [
                'work_class' => $class,
                'real_attempts' => $real,
                'certified' => $certified,
                'landing_rate' => $real > 0 ? round($certified / $real, 4) : 0.0,
                'wilson_lower' => round($wilson, 4),
                'enough_samples' => $enough,
                // HOPELESS only with enough REAL samples AND a Wilson-LB below the floor — never on a thin cell.
                'hopeless' => $enough && $wilson < $floorRate,
                'min_attempts' => $minAttempts,
                'floor_rate' => $floorRate,
            ];
        }

        return $out;
    }

    /**
     * A normalized de-prioritization weight in [0,1] for a prior: 0 when not hopeless (no nudge), rising toward
     * 1 as the Wilson-LB falls further below the floor. Deterministic; the caller multiplies it against the
     * band OFFSET so the nudge can never cross a shape band.
     *
     * @param  array{hopeless?:bool, wilson_lower?:float, floor_rate?:float}  $prior
     */
    public function deprioritizationWeight(array $prior): float
    {
        if (($prior['hopeless'] ?? false) !== true) {
            return 0.0;
        }
        $floor = max(1e-6, (float) ($prior['floor_rate'] ?? 0.15));
        $wilson = max(0.0, (float) ($prior['wilson_lower'] ?? 0.0));

        return max(0.0, min(1.0, 1.0 - ($wilson / $floor)));
    }

    /**
     * @return array{work_class:string, real_attempts:int, certified:int, landing_rate:float, wilson_lower:float,
     *               enough_samples:bool, hopeless:bool, min_attempts:int, floor_rate:float}
     */
    private function emptyPrior(string $workClass, int $minAttempts, float $floorRate): array
    {
        return [
            'work_class' => $workClass,
            'real_attempts' => 0,
            'certified' => 0,
            'landing_rate' => 0.0,
            'wilson_lower' => 0.0,
            'enough_samples' => false,
            'hopeless' => false,
            'min_attempts' => $minAttempts,
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

    /**
     * @return list<mixed>
     */
    private function arrayPayload(mixed $payload): array
    {
        if (is_array($payload)) {
            return array_values($payload);
        }
        if (! is_string($payload) || trim($payload) === '') {
            return [];
        }
        $decoded = json_decode($payload, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }
}
