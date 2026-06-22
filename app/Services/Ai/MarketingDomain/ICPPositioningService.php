<?php

namespace App\Services\Ai\MarketingDomain;

use App\Models\AiMarketingArtifact;
use App\Models\AiMarketingRun;
use App\Services\Ai\MarketingDomain\Knowledge\MarketingPlaybook;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ICPPositioningService
{
    public function __construct(private readonly MarketingPlaybook $playbook = new MarketingPlaybook) {}

    /**
     * Deterministic positioning/offer brief — offer-doctor + grand-slam-builder + awareness-router.
     * Scores the offer on the Value Equation (leverage is the denominator), prescribes the Grand
     * Slam stack + pricing psychology, ties the Life Force 8 desire, and routes the lead/sophistication
     * by awareness. This is how Atlas raises CVR without touching traffic.
     *
     * @return array<string,mixed>
     */
    public function blueprint(?string $awareness = null): array
    {
        return [
            'skill' => 'offer-doctor + grand-slam-builder + awareness-router',
            'awareness_routing' => $this->playbook->routeAwareness($awareness),
            'value_equation' => $this->playbook->valueEquation(),
            'grand_slam' => $this->playbook->grandSlam(),
            'life_force_8' => $this->playbook->lifeForce8(),
            'sophistication_stages' => $this->playbook->sophisticationStages(),
        ];
    }

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
