<?php

declare(strict_types=1);

namespace App\Services\Ai\Analysis;

/**
 * G6 — cross-domain multi-perspective analysis panel.
 *
 * Mirrors the Frontier judge-panel PATTERN (ports + strict majority +
 * default-refute) over deterministic lens judges, composed with the
 * metric-family-aware {@see AnalysisHonestyGate}:
 *
 *  1. the honesty gate runs FIRST — if it refuses, the analysis is not
 *     certified (decision `honesty_refused`), but the panel still runs so the
 *     report shows every lens verdict;
 *  2. otherwise the lens judges vote under default-refute (any non-accept
 *     counts as refute); certification requires a strict majority of accepts
 *     (floor(N/2)+1, i.e. 2 of 3 seats) AND the honesty pass.
 *
 * Decision-only: never writes, never merges, never invokes a provider.
 */
final class AnalysisJudgePanelService
{
    public const DECISION_CERTIFIED = 'certified';

    public const DECISION_HONESTY_REFUSED = 'honesty_refused';

    public const DECISION_MAJORITY_REFUTE = 'majority_refute';

    /** @var list<AnalysisJudgePort> */
    private readonly array $judges;

    /**
     * @param  list<AnalysisJudgePort>  $judges  defaults to the three G6 lenses
     */
    public function __construct(
        private readonly AnalysisHonestyGate $honestyGate,
        array $judges = [],
    ) {
        $this->judges = $judges !== [] ? array_values($judges) : [
            new EvidenceLensJudge,
            new ConsistencyLensJudge,
            new UncertaintyLensJudge,
        ];
    }

    /**
     * @param  array<string,mixed>  $analysis
     * @return array{
     *     certified:bool,
     *     decision:string,
     *     reasons:list<string>,
     *     panel:array{seats:list<array<string,mixed>>, accept_count:int, refute_count:int, majority_threshold:int, majority_confirmed:bool, default_refute:bool},
     *     honesty:array{certified:bool, reasons:list<string>, metric_family:string, report:array<string,mixed>}
     * }
     */
    public function run(array $analysis): array
    {
        // 1. Honesty gate first — family-aware, fail-closed.
        $honesty = $this->honestyGate->evaluate($analysis);

        // 2. Panel always runs (even after an honesty refusal) for the report.
        $seats = [];
        $acceptCount = 0;
        $refuteCount = 0;

        foreach ($this->judges as $judge) {
            $raw = $judge->judge($analysis);

            // Default-refute: anything that is not an explicit accept refutes.
            $verdict = ($raw['verdict'] ?? '') === 'accept' ? 'accept' : 'refute';
            $reasons = array_values(array_map(
                static fn ($reason): string => (string) $reason,
                is_array($raw['reasons'] ?? null) ? $raw['reasons'] : []
            ));

            if ($verdict === 'accept') {
                $acceptCount++;
            } else {
                $refuteCount++;
                if ($reasons === []) {
                    $reasons = ['default_refute'];
                }
            }

            $seats[] = [
                'lens' => (string) ($raw['lens'] ?? 'unknown'),
                'verdict' => $verdict,
                'reasons' => $reasons,
            ];
        }

        $judgeCount = count($this->judges);
        // Strict majority: floor(N/2)+1 (2 of 3 seats).
        $majorityThreshold = intdiv($judgeCount, 2) + 1;
        $majorityConfirmed = $acceptCount >= $majorityThreshold;

        $certified = $honesty['certified'] && $majorityConfirmed;

        if (! $honesty['certified']) {
            $decision = self::DECISION_HONESTY_REFUSED;
        } elseif ($majorityConfirmed) {
            $decision = self::DECISION_CERTIFIED;
        } else {
            $decision = self::DECISION_MAJORITY_REFUTE;
        }

        $reasons = $honesty['reasons'];
        foreach ($seats as $seat) {
            if ($seat['verdict'] === 'refute') {
                $reasons = [...$reasons, ...$seat['reasons']];
            }
        }

        return [
            'certified' => $certified,
            'decision' => $decision,
            'reasons' => array_values(array_unique($reasons)),
            'panel' => [
                'seats' => $seats,
                'accept_count' => $acceptCount,
                'refute_count' => $refuteCount,
                'majority_threshold' => $majorityThreshold,
                'majority_confirmed' => $majorityConfirmed,
                'default_refute' => true,
            ],
            'honesty' => $honesty,
        ];
    }
}
