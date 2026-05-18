<?php

namespace App\Services\Ai\ResearchDomain;

use App\Models\AiResearchClaim;
use App\Models\AiResearchRun;
use App\Models\AiResearchSource;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ResearchClaimService
{
    /**
     * Record a claim attributed to one or more accepted sources. Throws when
     * no source_refs are provided — the Research Company Runtime forbids
     * inference sem citation.
     *
     * @param  array<int,string>  $sourceCitationHashes
     * @param  array<string,mixed>  $metadata
     */
    public function record(
        AiResearchRun $run,
        string $statement,
        array $sourceCitationHashes,
        ?float $confidence = null,
        array $metadata = [],
    ): AiResearchClaim {
        if ($sourceCitationHashes === []) {
            throw new InvalidArgumentException('claim requires at least one source citation_hash');
        }

        $sources = AiResearchSource::query()
            ->where('research_run_id', $run->id)
            ->whereIn('citation_hash', $sourceCitationHashes)
            ->get();

        if ($sources->count() !== count(array_unique($sourceCitationHashes))) {
            throw new InvalidArgumentException('claim references unknown source citation_hash for this run');
        }

        $rejected = $sources->where('status', ResearchDomainCanon::SOURCE_STATUS_REJECTED);
        if ($rejected->isNotEmpty()) {
            throw new InvalidArgumentException(
                'claim cites rejected sources: '.$rejected->pluck('citation_hash')->implode(',')
            );
        }

        $claimHash = MissionCanonicalHash::sha256([
            'research_run_uuid' => $run->uuid,
            'statement' => $statement,
            'sources' => $sourceCitationHashes,
        ]);

        return AiResearchClaim::query()->create([
            'uuid' => (string) Str::uuid(),
            'research_run_id' => $run->id,
            'statement' => $statement,
            'source_refs' => array_values(array_unique($sourceCitationHashes)),
            'confidence' => $confidence,
            'claim_status' => ResearchDomainCanon::CLAIM_SUPPORTED,
            'contradiction_refs' => null,
            'contradiction_status' => ResearchDomainCanon::CONTRADICTION_NONE,
            'claim_hash' => $claimHash,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }

    /**
     * Detect contradictions between claims. Two claims are flagged as
     * contradicting when their `metadata.assertion` opposes another (the
     * canonical signal in v1; future versions can plug in an LLM or
     * embedding-based comparator). Updates both claims atomically.
     *
     * @return Collection<int,AiResearchClaim>
     */
    public function runContradictionCheck(AiResearchRun $run): Collection
    {
        $claims = $run->claims()->get();

        $rows = [];
        foreach ($claims as $claim) {
            $assertion = mb_strtolower(trim((string) ($claim->metadata['assertion'] ?? $claim->statement)));
            $negates = mb_strtolower(trim((string) ($claim->metadata['negates'] ?? '')));
            $rows[$claim->id] = [
                'claim' => $claim,
                'assertion' => $assertion,
                'negates' => $negates,
            ];
        }

        $matches = array_fill_keys(array_keys($rows), []);
        $ids = array_keys($rows);
        $n = count($ids);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $rows[$ids[$i]];
                $b = $rows[$ids[$j]];
                if ($this->contradictsPair($a, $b)) {
                    $matches[$a['claim']->id][] = $b['claim']->claim_hash;
                    $matches[$b['claim']->id][] = $a['claim']->claim_hash;
                }
            }
        }

        foreach ($rows as $id => $row) {
            $claim = $row['claim'];
            $refs = array_values(array_unique(array_filter($matches[$id])));
            if ($refs !== []) {
                $claim->contradiction_refs = $refs;
                $claim->contradiction_status = ResearchDomainCanon::CONTRADICTION_DETECTED;
                $claim->claim_status = ResearchDomainCanon::CLAIM_CONTRADICTED;
            } else {
                $claim->contradiction_refs = null;
                if ($claim->contradiction_status === ResearchDomainCanon::CONTRADICTION_NONE
                    && $claim->claim_status === ResearchDomainCanon::CLAIM_CONTRADICTED) {
                    $claim->claim_status = ResearchDomainCanon::CLAIM_SUPPORTED;
                }
            }
            $claim->save();
        }

        return $run->refresh()->claims()->get();
    }

    /**
     * @param  array{claim:AiResearchClaim,assertion:string,negates:string}  $a
     * @param  array{claim:AiResearchClaim,assertion:string,negates:string}  $b
     */
    private function contradictsPair(array $a, array $b): bool
    {
        if ($a['negates'] !== '' && $a['negates'] === $b['assertion']) {
            return true;
        }
        if ($b['negates'] !== '' && $b['negates'] === $a['assertion']) {
            return true;
        }
        $invertedA = preg_replace('/^(not |nao |não )/u', '', $a['assertion']);
        $invertedB = preg_replace('/^(not |nao |não )/u', '', $b['assertion']);
        if ($invertedA !== $a['assertion'] && $invertedA === $b['assertion']) {
            return true;
        }
        if ($invertedB !== $b['assertion'] && $invertedB === $a['assertion']) {
            return true;
        }

        return false;
    }

    /**
     * Declare a contradiction as accepted (open question or operator decision).
     */
    public function declareContradiction(AiResearchClaim $claim, string $reason): AiResearchClaim
    {
        $claim->contradiction_status = ResearchDomainCanon::CONTRADICTION_DECLARED;
        $metadata = (array) ($claim->metadata ?? []);
        $metadata['declared_contradiction_reason'] = $reason;
        $claim->metadata = $metadata;
        $claim->save();

        return $claim;
    }
}
