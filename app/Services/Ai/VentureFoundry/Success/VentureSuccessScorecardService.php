<?php

namespace App\Services\Ai\VentureFoundry\Success;

use App\Models\AiVenture;
use App\Models\AiVentureAdmissionDecision;

/**
 * The TRIPLE scorecard for the company success engine (meta >=70%).
 *
 * Reports three numbers TOGETHER so the target can't be gamed:
 *   1. success_rate  — succeeded / resolved (resolved = succeeded + failed)
 *   2. admission_rate — admitted / assessed  (guards against cowardly under-admission)
 *   3. success_count — absolute number of succeeded ventures
 *
 * Broken down by origin (created vs managed). Computed entirely from persisted
 * MRR observations + the admission ledger; never auto-declared.
 */
class VentureSuccessScorecardService
{
    public function __construct(private readonly VentureSuccessEvaluator $evaluator) {}

    /**
     * @return array<string,mixed>
     */
    public function scorecard(): array
    {
        $ventures = AiVenture::query()->orderBy('created_at')->get();
        $origins = $this->latestOriginByVenture();

        $rows = [];
        foreach ($ventures as $venture) {
            $eval = $this->evaluator->evaluate($venture);
            $rows[] = [
                'venture_id' => $venture->venture_id,
                'name' => $venture->name,
                'origin' => $origins[$venture->id] ?? AiVentureAdmissionDecision::ORIGIN_MANAGED,
                'status' => $eval['status'],
                'trailing_streak' => $eval['trailing_streak'],
                'months_observed' => $eval['months_observed'],
            ];
        }

        $target = 0.70;
        $overall = $this->aggregate($rows);
        $byOrigin = [
            AiVentureAdmissionDecision::ORIGIN_CREATED => $this->aggregate(array_values(array_filter($rows, fn ($r) => $r['origin'] === AiVentureAdmissionDecision::ORIGIN_CREATED))),
            AiVentureAdmissionDecision::ORIGIN_MANAGED => $this->aggregate(array_values(array_filter($rows, fn ($r) => $r['origin'] === AiVentureAdmissionDecision::ORIGIN_MANAGED))),
        ];

        $admission = $this->admissionStats();

        return [
            'target' => $target,
            'target_met' => $overall['success_rate'] !== null && $overall['success_rate'] >= $target,
            'overall' => $overall,
            'by_origin' => $byOrigin,
            'admission' => $admission,
            'ventures' => $rows,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     * @return array<string,mixed>
     */
    private function aggregate(array $rows): array
    {
        $counts = [
            VentureSuccessEvaluator::STATUS_SUCCEEDED => 0,
            VentureSuccessEvaluator::STATUS_FAILED => 0,
            VentureSuccessEvaluator::STATUS_NOT_YET => 0,
            VentureSuccessEvaluator::STATUS_INSUFFICIENT => 0,
        ];
        foreach ($rows as $row) {
            $counts[$row['status']] = ($counts[$row['status']] ?? 0) + 1;
        }

        $succeeded = $counts[VentureSuccessEvaluator::STATUS_SUCCEEDED];
        $failed = $counts[VentureSuccessEvaluator::STATUS_FAILED];
        $resolved = $succeeded + $failed;
        $cohort = count($rows);

        return [
            'cohort_size' => $cohort,
            'success_count' => $succeeded,
            'resolved' => $resolved,
            'pending' => $cohort - $resolved,
            'counts' => $counts,
            // The headline number: succeeded over resolved (by-milestone, no deadline).
            'success_rate' => $resolved > 0 ? round($succeeded / $resolved, 4) : null,
            // Conservative view: succeeded over the whole cohort (pending drags it down).
            'success_rate_over_cohort' => $cohort > 0 ? round($succeeded / $cohort, 4) : null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function admissionStats(): array
    {
        $decisions = AiVentureAdmissionDecision::query()->get();
        $assessed = $decisions->count();
        $admitted = $decisions->where('decision', AiVentureAdmissionDecision::DECISION_ADMITTED)->count();
        $rejected = $decisions->where('decision', AiVentureAdmissionDecision::DECISION_REJECTED)->count();
        $parked = $decisions->where('decision', AiVentureAdmissionDecision::DECISION_PARKED)->count();

        return [
            'assessed' => $assessed,
            'admitted' => $admitted,
            'rejected' => $rejected,
            'parked' => $parked,
            'admission_rate' => $assessed > 0 ? round($admitted / $assessed, 4) : null,
        ];
    }

    /**
     * Latest admission origin per venture id.
     *
     * @return array<string,string>
     */
    private function latestOriginByVenture(): array
    {
        $map = [];
        $decisions = AiVentureAdmissionDecision::query()
            ->whereNotNull('venture_id')
            ->orderBy('created_at')
            ->get();
        foreach ($decisions as $decision) {
            $map[(string) $decision->venture_id] = (string) $decision->origin; // latest wins
        }

        return $map;
    }
}
