<?php

namespace App\Services\Ai\ResearchDomain;

use App\Models\AiResearchClaim;
use App\Models\AiResearchRun;
use App\Models\AiResearchSource;
use App\Models\AiResearchSynthesis;

class ResearchControlPlaneProjection
{
    /**
     * @return array<string,mixed>
     */
    public function snapshot(int $limitRecent = 20): array
    {
        return [
            'schema' => 'atlas.ai.research_domain.control_plane.v1',
            'runs' => $this->runsSection($limitRecent),
            'sources' => $this->sourcesSection($limitRecent),
            'claims' => $this->claimsSection($limitRecent),
            'syntheses' => $this->synthesesSection($limitRecent),
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function runsSection(int $limit): array
    {
        $runs = AiResearchRun::query()->orderByDesc('created_at')->limit($limit)->get();

        return [
            'count' => AiResearchRun::query()->count(),
            'by_status' => AiResearchRun::query()
                ->selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status')
                ->all(),
            'by_certification_status' => AiResearchRun::query()
                ->whereNotNull('certification_status')
                ->selectRaw('certification_status, count(*) as total')
                ->groupBy('certification_status')
                ->pluck('total', 'certification_status')
                ->all(),
            'recent' => $runs->map(static fn (AiResearchRun $r): array => [
                'uuid' => $r->uuid,
                'question' => $r->question,
                'status' => $r->status,
                'certification_status' => $r->certification_status,
                'source_diversity' => (float) ($r->source_diversity ?? 0.0),
                'overall_confidence' => (float) ($r->overall_confidence ?? 0.0),
                'certification_hash' => $r->certification_hash,
                'evidence_pack_hash' => $r->evidence_pack_hash,
                'completed_at' => $r->completed_at?->toJSON(),
            ])->all(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function sourcesSection(int $limit): array
    {
        $sources = AiResearchSource::query()->orderByDesc('created_at')->limit($limit)->get();

        return [
            'count' => AiResearchSource::query()->count(),
            'by_status' => AiResearchSource::query()
                ->selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status')
                ->all(),
            'by_source_type' => AiResearchSource::query()
                ->selectRaw('source_type, count(*) as total')
                ->groupBy('source_type')
                ->pluck('total', 'source_type')
                ->all(),
            'rejected_reasons' => AiResearchSource::query()
                ->where('status', ResearchDomainCanon::SOURCE_STATUS_REJECTED)
                ->whereNotNull('reason_rejected')
                ->selectRaw('reason_rejected, count(*) as total')
                ->groupBy('reason_rejected')
                ->pluck('total', 'reason_rejected')
                ->all(),
            'recent' => $sources->map(static fn (AiResearchSource $s): array => [
                'uuid' => $s->uuid,
                'source_type' => $s->source_type,
                'status' => $s->status,
                'source_quality' => (float) ($s->source_quality ?? 0.0),
                'reason_rejected' => $s->reason_rejected,
                'citation_hash' => $s->citation_hash,
            ])->all(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function claimsSection(int $limit): array
    {
        $claims = AiResearchClaim::query()->orderByDesc('created_at')->limit($limit)->get();

        return [
            'count' => AiResearchClaim::query()->count(),
            'by_status' => AiResearchClaim::query()
                ->selectRaw('claim_status, count(*) as total')
                ->groupBy('claim_status')
                ->pluck('total', 'claim_status')
                ->all(),
            'by_contradiction_status' => AiResearchClaim::query()
                ->selectRaw('contradiction_status, count(*) as total')
                ->groupBy('contradiction_status')
                ->pluck('total', 'contradiction_status')
                ->all(),
            'recent' => $claims->map(static fn (AiResearchClaim $c): array => [
                'uuid' => $c->uuid,
                'claim_status' => $c->claim_status,
                'contradiction_status' => $c->contradiction_status,
                'confidence' => (float) ($c->confidence ?? 0.0),
                'source_ref_count' => is_array($c->source_refs) ? count($c->source_refs) : 0,
                'claim_hash' => $c->claim_hash,
            ])->all(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function synthesesSection(int $limit): array
    {
        $syntheses = AiResearchSynthesis::query()->orderByDesc('created_at')->limit($limit)->get();

        return [
            'count' => AiResearchSynthesis::query()->count(),
            'recent' => $syntheses->map(static fn (AiResearchSynthesis $s): array => [
                'uuid' => $s->uuid,
                'overall_confidence' => (float) ($s->overall_confidence ?? 0.0),
                'claim_ref_count' => is_array($s->claim_refs) ? count($s->claim_refs) : 0,
                'source_ref_count' => is_array($s->source_refs) ? count($s->source_refs) : 0,
                'synthesis_hash' => $s->synthesis_hash,
            ])->all(),
        ];
    }
}
