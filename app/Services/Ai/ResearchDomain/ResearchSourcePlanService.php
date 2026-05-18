<?php

namespace App\Services\Ai\ResearchDomain;

use App\Models\AiResearchRun;
use App\Models\AiResearchSource;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ResearchSourcePlanService
{
    /**
     * Open a new research run with a question, optional hypothesis and a
     * source plan that the caller proposes. The source plan is a list of
     * candidate source descriptors with at least `source_type` and `source_ref`.
     *
     * @param  array<int,array<string,mixed>>  $sourcePlan
     * @param  array<string,mixed>  $context
     */
    public function plan(
        string $question,
        array $sourcePlan,
        ?string $hypothesis = null,
        array $context = [],
    ): AiResearchRun {
        $run = AiResearchRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'mission_id' => $context['mission_id'] ?? null,
            'work_order_id' => $context['work_order_id'] ?? null,
            'question' => $question,
            'hypothesis' => $hypothesis,
            'source_plan' => array_values(array_map(
                static fn (array $s): array => [
                    'source_type' => (string) ($s['source_type'] ?? ResearchDomainCanon::SOURCE_DOCS),
                    'source_ref' => (string) ($s['source_ref'] ?? ''),
                    'title' => $s['title'] ?? null,
                    'author' => $s['author'] ?? null,
                ],
                $sourcePlan,
            )),
            'status' => ResearchDomainCanon::STATUS_PLANNED,
        ]);

        foreach ($sourcePlan as $candidate) {
            $sourceRef = (string) ($candidate['source_ref'] ?? '');
            if ($sourceRef === '') {
                continue;
            }
            AiResearchSource::query()->create([
                'uuid' => (string) Str::uuid(),
                'research_run_id' => $run->id,
                'source_type' => (string) ($candidate['source_type'] ?? ResearchDomainCanon::SOURCE_DOCS),
                'source_ref' => $sourceRef,
                'title' => $candidate['title'] ?? null,
                'author' => $candidate['author'] ?? null,
                'published_at' => $candidate['published_at'] ?? null,
                'status' => ResearchDomainCanon::SOURCE_STATUS_PLANNED,
                'source_quality' => null,
                'quality_factors' => null,
                'reason_rejected' => null,
                'citation_hash' => MissionCanonicalHash::sha256([
                    'research_run_uuid' => $run->uuid,
                    'source_ref' => $sourceRef,
                    'source_type' => $candidate['source_type'] ?? null,
                ]),
                'metadata' => $candidate['metadata'] ?? null,
            ]);
        }

        return $run->refresh();
    }

    /**
     * @return Collection<int,AiResearchSource>
     */
    public function planned(AiResearchRun $run): Collection
    {
        return $run->sources()
            ->where('status', ResearchDomainCanon::SOURCE_STATUS_PLANNED)
            ->orderBy('created_at')
            ->get();
    }
}
