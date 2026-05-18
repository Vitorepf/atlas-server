<?php

namespace App\Services\Ai\ResearchDomain;

use App\Models\AiResearchRun;
use App\Models\AiResearchSynthesis;

class ResearchRuntimeService
{
    public function __construct(
        private readonly ResearchSourcePlanService $sourcePlan,
        private readonly ResearchSourceQualityService $sourceQuality,
        private readonly ResearchClaimService $claims,
        private readonly ResearchSynthesisService $synthesis,
    ) {}

    /**
     * Orchestrate the full Research Company Runtime pipeline:
     *
     *   plan -> score sources -> record claims -> contradiction check
     *        -> synthesize -> certify
     *
     * `payload` shape:
     * [
     *   'question' => string,
     *   'hypothesis' => ?string,
     *   'sources' => [
     *     ['source_type' => '...', 'source_ref' => '...', 'factors' => [...]],
     *     ...
     *   ],
     *   'claims' => [
     *     ['statement' => '...', 'source_refs' => ['citation_hash', ...], 'confidence' => 0.8, 'metadata' => [...]],
     *     ...
     *   ],
     *   'brief' => string,
     *   'context' => array<string,mixed>,
     * ]
     *
     * @param  array<string,mixed>  $payload
     * @return array{run: AiResearchRun, synthesis: AiResearchSynthesis}
     */
    public function run(array $payload): array
    {
        $sources = $payload['sources'] ?? [];
        $factorsByHash = [];
        $run = $this->sourcePlan->plan(
            (string) ($payload['question'] ?? ''),
            $sources,
            $payload['hypothesis'] ?? null,
            (array) ($payload['context'] ?? []),
        );

        // Map factors by the citation hash that plan() just generated.
        $sourceModels = $run->sources()->orderBy('created_at')->get();
        foreach ($sourceModels as $i => $source) {
            $factorsByHash[$source->citation_hash] = $sources[$i]['factors'] ?? [];
        }

        $this->sourceQuality->scoreRun($run, $factorsByHash);

        foreach ((array) ($payload['claims'] ?? []) as $claim) {
            $this->claims->record(
                $run,
                (string) ($claim['statement'] ?? ''),
                (array) ($claim['source_refs'] ?? []),
                isset($claim['confidence']) ? (float) $claim['confidence'] : null,
                (array) ($claim['metadata'] ?? []),
            );
        }

        $this->claims->runContradictionCheck($run);

        $synthesis = $this->synthesis->synthesize(
            $run->refresh(),
            (string) ($payload['brief'] ?? 'Synthesis pending narrative.'),
            (array) ($payload['context'] ?? []),
        );

        return ['run' => $run->refresh(), 'synthesis' => $synthesis];
    }

    /**
     * End-to-end deterministic smoke flow. Builds a run with 2 accepted
     * primary-type sources and 1 rejected vendor-biased source, records one
     * supported claim with attribution, runs contradiction check and
     * certifies. Used by the CLI smoke action and the smoke feature test.
     *
     * @return array{run: AiResearchRun, synthesis: AiResearchSynthesis}
     */
    public function smokeRun(): array
    {
        $run = $this->sourcePlan->plan(
            'Atlas Research smoke: melhores praticas para sintese de pesquisa com fontes citaveis',
            [
                [
                    'source_type' => ResearchDomainCanon::SOURCE_ACADEMIC,
                    'source_ref' => 'doi:10.0000/research-method-canon',
                    'title' => 'Research Methods Canon',
                ],
                [
                    'source_type' => ResearchDomainCanon::SOURCE_STANDARD,
                    'source_ref' => 'iso:30134',
                    'title' => 'ISO Standard 30134',
                ],
                [
                    'source_type' => ResearchDomainCanon::SOURCE_BLOG,
                    'source_ref' => 'https://example.com/vendor-blog',
                    'title' => 'Vendor Blog',
                ],
            ],
            'Sintese auditavel exige >=2 fontes diversas, claim attribution e contradiction check.',
            ['source' => 'cli-smoke'],
        );

        $sources = $run->sources()->orderBy('created_at')->get();
        $factorsByHash = [
            $sources[0]->citation_hash => [
                'peer_reviewed' => true,
                'primary' => true,
                'recency_days' => 200,
                'domain_authority' => 0.9,
            ],
            $sources[1]->citation_hash => [
                'primary' => true,
                'recency_days' => 800,
                'domain_authority' => 0.85,
            ],
            $sources[2]->citation_hash => [
                'vendor_bias' => true,
                'recency_days' => 30,
            ],
        ];
        $this->sourceQuality->scoreRun($run, $factorsByHash);

        $accepted = $run->refresh()->sources()
            ->where('status', ResearchDomainCanon::SOURCE_STATUS_ACCEPTED)
            ->orderBy('created_at')
            ->get();
        $citationHashes = $accepted->pluck('citation_hash')->all();

        $this->claims->record(
            $run,
            'Source diversity reduces single-vendor bias risk.',
            $citationHashes,
            0.85,
            ['assertion' => 'source_diversity_reduces_bias'],
        );
        $this->claims->runContradictionCheck($run);

        $synthesis = $this->synthesis->synthesize(
            $run->refresh(),
            'Research synthesis smoke: 2 sources accepted, 1 rejected for vendor_bias.',
            ['source' => 'cli-smoke'],
        );

        return ['run' => $run->refresh(), 'synthesis' => $synthesis];
    }
}
