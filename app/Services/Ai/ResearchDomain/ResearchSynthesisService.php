<?php

namespace App\Services\Ai\ResearchDomain;

use App\Models\AiResearchClaim;
use App\Models\AiResearchRun;
use App\Models\AiResearchSource;
use App\Models\AiResearchSynthesis;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ResearchSynthesisService
{
    public function __construct(private readonly ResearchEvidenceBridge $evidence) {}

    /**
     * Compose the final research synthesis and certify the run. Certification
     * fails when minimum source diversity / accepted sources / claims are not
     * met OR contradictions remain detected without resolution/declaration.
     *
     * @param  array<string,mixed>  $context
     */
    public function synthesize(AiResearchRun $run, string $brief, array $context = []): AiResearchSynthesis
    {
        $sources = $run->sources()->get();
        $accepted = $sources->where('status', ResearchDomainCanon::SOURCE_STATUS_ACCEPTED);
        $rejected = $sources->where('status', ResearchDomainCanon::SOURCE_STATUS_REJECTED);
        $claims = $run->claims()->get();

        $missing = $this->computeMissingRequirements($accepted->all(), $claims->all());

        $contradictions = $claims
            ->filter(static fn ($c): bool => $c->contradiction_status === ResearchDomainCanon::CONTRADICTION_DETECTED)
            ->map(static fn ($c): array => [
                'claim_hash' => $c->claim_hash,
                'contradiction_refs' => $c->contradiction_refs ?? [],
            ])->values()->all();

        $overallConfidence = $this->computeOverallConfidence($accepted->all(), $claims->all());

        $synthesis = AiResearchSynthesis::query()->create([
            'uuid' => (string) Str::uuid(),
            'research_run_id' => $run->id,
            'brief' => $brief,
            'claim_refs' => $claims->pluck('claim_hash')->all(),
            'source_refs' => $accepted->pluck('citation_hash')->values()->all(),
            'contradictions' => $contradictions === [] ? null : $contradictions,
            'open_questions' => $context['open_questions'] ?? null,
            'overall_confidence' => $overallConfidence,
            'evidence_refs' => $context['evidence_refs'] ?? null,
            'synthesis_hash' => MissionCanonicalHash::sha256([
                'research_run_uuid' => $run->uuid,
                'brief' => $brief,
                'claims' => $claims->pluck('claim_hash')->all(),
                'sources' => $accepted->pluck('citation_hash')->values()->all(),
                'contradictions' => $contradictions,
            ]),
        ]);

        $pack = $this->evidence->buildEvidencePack($run, $synthesis);
        $synthesis->evidence_refs = $pack;
        $synthesis->save();

        $certificationStatus = $missing === [] && $contradictions === []
            ? ResearchDomainCanon::CERT_PASSED
            : ResearchDomainCanon::CERT_FAILED;

        $run->fill([
            'status' => $certificationStatus === ResearchDomainCanon::CERT_PASSED
                ? ResearchDomainCanon::STATUS_COMPLETED
                : ResearchDomainCanon::STATUS_FAILED,
            'overall_confidence' => $overallConfidence,
            'certification_status' => $certificationStatus,
            'missing_requirements' => $missing === [] ? null : $missing,
            'certification_hash' => MissionCanonicalHash::sha256([
                'research_run_uuid' => $run->uuid,
                'synthesis_hash' => $synthesis->synthesis_hash,
                'missing' => $missing,
                'contradictions' => $contradictions,
                'certification_status' => $certificationStatus,
            ]),
            'evidence_pack_hash' => MissionCanonicalHash::sha256($pack),
            'completed_at' => $certificationStatus === ResearchDomainCanon::CERT_PASSED
                ? Carbon::now()
                : null,
        ]);
        $run->save();

        return $synthesis->refresh();
    }

    /**
     * @param  array<int,AiResearchSource>  $accepted
     * @param  array<int,AiResearchClaim>  $claims
     * @return array<int,string>
     */
    private function computeMissingRequirements(array $accepted, array $claims): array
    {
        $missing = [];
        if (count($accepted) < ResearchDomainCanon::MIN_ACCEPTED_SOURCES) {
            $missing[] = 'accepted_sources_below_minimum';
        }
        $diversity = collect($accepted)->pluck('source_type')->unique()->count();
        if ($diversity < ResearchDomainCanon::MIN_SOURCE_DIVERSITY) {
            $missing[] = 'source_diversity_below_minimum';
        }
        if (count($claims) < ResearchDomainCanon::MIN_CLAIMS) {
            $missing[] = 'claims_below_minimum';
        }
        $unattributedClaims = collect($claims)
            ->filter(static fn ($c): bool => empty($c->source_refs))
            ->count();
        if ($unattributedClaims > 0) {
            $missing[] = 'claims_without_source_refs';
        }

        return $missing;
    }

    /**
     * @param  array<int,AiResearchSource>  $accepted
     * @param  array<int,AiResearchClaim>  $claims
     */
    private function computeOverallConfidence(array $accepted, array $claims): float
    {
        if ($claims === []) {
            return 0.0;
        }
        $claimAvg = collect($claims)
            ->avg(static fn ($c): float => (float) ($c->confidence ?? 0.6));
        $sourceAvg = $accepted === []
            ? 0.0
            : collect($accepted)->avg(static fn ($s): float => (float) ($s->source_quality ?? 0.5));

        return round(min(0.99, 0.6 * $claimAvg + 0.4 * $sourceAvg), 4);
    }
}
