<?php

namespace App\Services\Ai\MarketingDomain;

use App\Models\AiMarketingArtifact;
use App\Models\AiMarketingRun;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ICPPositioningService
{
    /**
     * @param  array<string,mixed>  $payload
     */
    public function defineICP(AiMarketingRun $run, array $payload): AiMarketingArtifact
    {
        foreach (['segment', 'pains', 'jobs_to_be_done', 'gains', 'channels'] as $required) {
            if (! isset($payload[$required])) {
                throw new InvalidArgumentException("icp requires field [{$required}]");
            }
        }

        return $this->persistArtifact(
            $run,
            MarketingDomainCanon::ARTIFACT_ICP,
            (string) ($payload['segment'] ?? 'ICP'),
            $payload,
        );
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function definePositioning(AiMarketingRun $run, array $payload): AiMarketingArtifact
    {
        foreach (['promise', 'differentiation', 'proof_points'] as $required) {
            if (! isset($payload[$required])) {
                throw new InvalidArgumentException("positioning requires field [{$required}]");
            }
        }
        $proof = (array) ($payload['proof_points'] ?? []);
        if ($proof === []) {
            throw new InvalidArgumentException('positioning requires at least one proof_point');
        }

        return $this->persistArtifact(
            $run,
            MarketingDomainCanon::ARTIFACT_POSITIONING,
            (string) ($payload['title'] ?? 'Positioning'),
            $payload,
        );
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function persistArtifact(
        AiMarketingRun $run,
        string $type,
        string $title,
        array $payload,
    ): AiMarketingArtifact {
        return AiMarketingArtifact::query()->create([
            'uuid' => (string) Str::uuid(),
            'marketing_run_id' => $run->id,
            'artifact_type' => $type,
            'title' => $title,
            'payload' => $payload,
            'status' => MarketingDomainCanon::ARTIFACT_READY_FOR_REVIEW,
            'artifact_hash' => MissionCanonicalHash::sha256([
                'run_uuid' => $run->uuid,
                'artifact_type' => $type,
                'payload' => $payload,
            ]),
        ]);
    }
}
