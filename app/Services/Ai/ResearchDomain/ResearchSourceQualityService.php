<?php

namespace App\Services\Ai\ResearchDomain;

use App\Models\AiResearchRun;
use App\Models\AiResearchSource;
use Illuminate\Support\Collection;

class ResearchSourceQualityService
{
    /**
     * Score a single source and persist the result. Acceptance/rejection
     * rules are deterministic for Meta 8A v1 — the long-term roadmap pulls
     * signals from Content Intelligence / Curation runtime.
     *
     * Rules:
     * - Quality factors expected: `recency_days`, `peer_reviewed`,
     *   `primary`, `domain_authority`, `corroborates_other`, `vendor_bias`.
     * - Score range 0.00 — 1.00.
     * - Sources below 0.40 are rejected with a structured reason.
     *
     * @param  array<string,mixed>  $factors
     */
    public function score(AiResearchSource $source, array $factors = []): AiResearchSource
    {
        $score = 0.5;
        $contributions = [];

        if (in_array($source->source_type, ResearchDomainCanon::QUALITY_PRIMARY_TYPES, true)) {
            $score += 0.15;
            $contributions['primary_type'] = '+0.15';
        }
        if (! empty($factors['peer_reviewed'])) {
            $score += 0.20;
            $contributions['peer_reviewed'] = '+0.20';
        }
        if (! empty($factors['primary'])) {
            $score += 0.15;
            $contributions['primary_evidence'] = '+0.15';
        }
        if (isset($factors['recency_days'])) {
            $days = (int) $factors['recency_days'];
            if ($days <= 365) {
                $score += 0.05;
                $contributions['recency_year'] = '+0.05';
            } elseif ($days > 365 * 5) {
                $score -= 0.10;
                $contributions['recency_stale'] = '-0.10';
            }
        }
        if (isset($factors['domain_authority'])) {
            $authority = max(0.0, min(1.0, (float) $factors['domain_authority']));
            $delta = round($authority * 0.10, 4);
            $score += $delta;
            $contributions['domain_authority'] = "+{$delta}";
        }
        if (! empty($factors['corroborates_other'])) {
            $score += 0.05;
            $contributions['corroborates_other'] = '+0.05';
        }
        if (! empty($factors['vendor_bias'])) {
            $score -= 0.15;
            $contributions['vendor_bias'] = '-0.15';
        }
        if (in_array($source->source_type, ResearchDomainCanon::LOW_TRIANGULATION_TYPES, true)) {
            $score -= 0.05;
            $contributions['low_triangulation_type'] = '-0.05';
        }

        $score = max(0.0, min(1.0, round($score, 4)));
        $accepted = $score >= 0.40;
        $reason = null;
        if (! $accepted) {
            $reason = $this->rejectionReason($source, $factors, $score);
        }

        $source->source_quality = $score;
        $source->quality_factors = [
            'inputs' => $factors,
            'contributions' => $contributions,
            'final_score' => $score,
        ];
        $source->status = $accepted
            ? ResearchDomainCanon::SOURCE_STATUS_ACCEPTED
            : ResearchDomainCanon::SOURCE_STATUS_REJECTED;
        $source->reason_rejected = $reason;
        $source->save();

        return $source->refresh();
    }

    /**
     * Score every planned source in the run using the same factor map.
     *
     * @param  array<int,array<string,mixed>>  $factorsByCitationHash  citation_hash => factors
     * @return Collection<int,AiResearchSource>
     */
    public function scoreRun(AiResearchRun $run, array $factorsByCitationHash = []): Collection
    {
        $sources = $run->sources()->get();
        foreach ($sources as $source) {
            $factors = (array) ($factorsByCitationHash[$source->citation_hash] ?? []);
            $this->score($source, $factors);
        }

        $run->refresh();
        $accepted = $run->sources()
            ->where('status', ResearchDomainCanon::SOURCE_STATUS_ACCEPTED)
            ->get();
        $diversity = $accepted->pluck('source_type')->unique()->count();
        $run->source_diversity = $diversity === 0 ? null : min(1.0, round($diversity / 4, 4));
        $run->save();

        return $run->sources()->orderBy('created_at')->get();
    }

    /**
     * @param  array<string,mixed>  $factors
     */
    private function rejectionReason(AiResearchSource $source, array $factors, float $score): string
    {
        if (! empty($factors['vendor_bias'])) {
            return 'vendor_bias_high';
        }
        if (in_array($source->source_type, ResearchDomainCanon::LOW_TRIANGULATION_TYPES, true)
            && empty($factors['corroborates_other'])) {
            return 'low_triangulation_no_corroboration';
        }
        if (isset($factors['recency_days']) && (int) $factors['recency_days'] > 365 * 5) {
            return 'stale_more_than_5y';
        }

        return "quality_below_threshold:{$score}";
    }
}
