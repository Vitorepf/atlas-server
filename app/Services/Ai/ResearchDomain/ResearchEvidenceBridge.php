<?php

namespace App\Services\Ai\ResearchDomain;

use App\Models\AiMissionEvidenceRef;
use App\Models\AiResearchRun;
use App\Models\AiResearchSource;
use App\Models\AiResearchSynthesis;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Str;

class ResearchEvidenceBridge
{
    /**
     * Project the research run into the Mission Foundation evidence ledger.
     * Tolerant: when ai_mission_evidence_refs does not exist (Mission
     * Foundation absent), it returns an empty array without crashing.
     *
     * @return array<int,array<string,mixed>>
     */
    public function projectToMissionEvidence(AiResearchRun $run, AiResearchSynthesis $synthesis): array
    {
        if (! DatabaseTableAvailability::has('ai_mission_evidence_refs')) {
            return [];
        }
        if ($run->mission_id === null) {
            return [];
        }

        $projected = [];

        foreach ($run->sources()->where('status', ResearchDomainCanon::SOURCE_STATUS_ACCEPTED)->get() as $source) {
            $projected[] = $this->attach($run, $source, 'source');
        }
        $projected[] = AiMissionEvidenceRef::query()->create([
            'uuid' => (string) Str::uuid(),
            'mission_id' => $run->mission_id,
            'work_order_id' => $run->work_order_id,
            'evidence_type' => 'doc',
            'evidence_ref' => 'research_synthesis:'.$synthesis->uuid,
            'evidence_hash' => $synthesis->synthesis_hash,
            'metadata' => [
                'research_run_uuid' => $run->uuid,
                'synthesis_uuid' => $synthesis->uuid,
            ],
        ])->toArray();

        return $projected;
    }

    /**
     * Build the deterministic evidence pack envelope used by the synthesis
     * service.
     *
     * @return array<string,mixed>
     */
    public function buildEvidencePack(AiResearchRun $run, AiResearchSynthesis $synthesis): array
    {
        $sources = $run->sources()->orderBy('created_at')->get();
        $claims = $run->claims()->orderBy('created_at')->get();

        return [
            'research_run_uuid' => $run->uuid,
            'synthesis_uuid' => $synthesis->uuid,
            'sources_accepted' => $sources
                ->where('status', ResearchDomainCanon::SOURCE_STATUS_ACCEPTED)
                ->map(static fn (AiResearchSource $s): array => [
                    'citation_hash' => $s->citation_hash,
                    'source_type' => $s->source_type,
                    'source_ref' => $s->source_ref,
                    'source_quality' => (float) ($s->source_quality ?? 0.0),
                ])->values()->all(),
            'sources_rejected' => $sources
                ->where('status', ResearchDomainCanon::SOURCE_STATUS_REJECTED)
                ->map(static fn (AiResearchSource $s): array => [
                    'citation_hash' => $s->citation_hash,
                    'source_type' => $s->source_type,
                    'source_ref' => $s->source_ref,
                    'reason_rejected' => $s->reason_rejected,
                ])->values()->all(),
            'claims' => $claims->map(static fn ($c): array => [
                'claim_hash' => $c->claim_hash,
                'claim_status' => $c->claim_status,
                'contradiction_status' => $c->contradiction_status,
                'source_refs' => $c->source_refs,
            ])->values()->all(),
            'synthesis_hash' => $synthesis->synthesis_hash,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function attach(AiResearchRun $run, AiResearchSource $source, string $kind): array
    {
        return AiMissionEvidenceRef::query()->create([
            'uuid' => (string) Str::uuid(),
            'mission_id' => $run->mission_id,
            'work_order_id' => $run->work_order_id,
            'evidence_type' => 'source',
            'evidence_ref' => "research_source:{$source->citation_hash}",
            'evidence_hash' => $source->citation_hash,
            'metadata' => [
                'research_run_uuid' => $run->uuid,
                'source_uuid' => $source->uuid,
                'source_type' => $source->source_type,
                'kind' => $kind,
            ],
        ])->toArray();
    }
}
