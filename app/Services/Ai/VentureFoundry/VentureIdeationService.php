<?php

namespace App\Services\Ai\VentureFoundry;

use App\Models\AiOpportunity;
use App\Models\AiVentureIdea;
use App\Services\Ai\Strategy\OpportunityRadarService;
use App\Services\Ai\Strategy\StrategyCanonicalHash;
use Illuminate\Support\Str;

/**
 * Idea intake + deterministic scoring for the Venture Foundry.
 *
 * Ideas enter from the operator, from the Opportunity Radar (strategy domain)
 * or from future governed generators. Every idea is scored with an auditable
 * breakdown (pain, urgency, market, founder fit, sovereignty fit) so ranking
 * never depends on conversation memory.
 */
class VentureIdeationService
{
    public const STATUS_PROPOSED = 'proposed';

    public const STATUS_SHORTLISTED = 'shortlisted';

    public const STATUS_PROMOTED = 'promoted';

    public const STATUS_REJECTED = 'rejected';

    public const SOURCE_OPERATOR = 'operator';

    public const SOURCE_RADAR = 'radar';

    public const SOURCE_GENERATED = 'generated';

    public const ALLOWED_SOURCES = [
        self::SOURCE_OPERATOR,
        self::SOURCE_RADAR,
        self::SOURCE_GENERATED,
    ];

    private const URGENCY_FACTOR = [
        OpportunityRadarService::URGENCY_LOW => 0.25,
        OpportunityRadarService::URGENCY_MEDIUM => 0.5,
        OpportunityRadarService::URGENCY_HIGH => 0.75,
        OpportunityRadarService::URGENCY_CRITICAL => 1.0,
    ];

    /**
     * Register an idea candidate with required problem/ICP/pain fields and
     * deterministic score.
     *
     * @param  array<string,mixed>  $args
     */
    public function register(array $args): AiVentureIdea
    {
        $title = trim((string) ($args['title'] ?? ''));
        if ($title === '') {
            throw VentureFoundryException::missingField('idea', 'title');
        }

        foreach (['problem', 'icp', 'pain'] as $field) {
            $value = $args[$field] ?? '';
            if (! is_string($value) || trim($value) === '') {
                throw VentureFoundryException::missingField('idea', $field);
            }
        }

        $urgency = (string) ($args['urgency'] ?? OpportunityRadarService::URGENCY_MEDIUM);
        if (! array_key_exists($urgency, self::URGENCY_FACTOR)) {
            throw VentureFoundryException::invalidValue('idea', 'urgency', 'must be one of ['.implode(',', array_keys(self::URGENCY_FACTOR)).']');
        }

        $source = (string) ($args['source'] ?? self::SOURCE_OPERATOR);
        if (! in_array($source, self::ALLOWED_SOURCES, true)) {
            throw VentureFoundryException::invalidValue('idea', 'source', 'must be one of ['.implode(',', self::ALLOWED_SOURCES).']');
        }

        foreach (['pain_severity', 'founder_fit', 'sovereignty_fit'] as $field) {
            if (array_key_exists($field, $args)) {
                $value = (int) $args[$field];
                if ($value < 0 || $value > 5) {
                    throw VentureFoundryException::invalidValue('idea', $field, 'must be between 0 and 5');
                }
            }
        }

        $marketSize = isset($args['market_size_usd']) ? (float) $args['market_size_usd'] : null;
        if ($marketSize !== null && $marketSize < 0) {
            throw VentureFoundryException::invalidValue('idea', 'market_size_usd', 'must be >= 0');
        }

        $ideaId = (string) ($args['idea_id'] ?? Str::slug($title));
        if (AiVentureIdea::query()->where('idea_id', $ideaId)->exists()) {
            throw VentureFoundryException::invalidValue('idea', 'idea_id', "[{$ideaId}] already registered");
        }

        $painSeverity = (int) ($args['pain_severity'] ?? 3);
        $founderFit = (int) ($args['founder_fit'] ?? 3);
        $sovereigntyFit = (int) ($args['sovereignty_fit'] ?? 3);

        $scoring = $this->score($painSeverity, $urgency, $marketSize, $founderFit, $sovereigntyFit);

        $hashInput = [
            'idea_id' => $ideaId,
            'title' => $title,
            'problem' => $args['problem'],
            'icp' => $args['icp'],
            'pain' => $args['pain'],
            'urgency' => $urgency,
            'source' => $source,
            'opportunity_id' => $args['opportunity_id'] ?? null,
            'inputs' => [$painSeverity, $founderFit, $sovereigntyFit, $marketSize],
        ];

        return AiVentureIdea::query()->create([
            'uuid' => (string) Str::uuid(),
            'idea_id' => $ideaId,
            'title' => $title,
            'problem' => (string) $args['problem'],
            'icp' => (string) $args['icp'],
            'pain' => (string) $args['pain'],
            'urgency' => $urgency,
            'source' => $source,
            'opportunity_id' => $args['opportunity_id'] ?? null,
            'market_size_usd' => $marketSize,
            'pain_severity' => $painSeverity,
            'founder_fit' => $founderFit,
            'sovereignty_fit' => $sovereigntyFit,
            'score' => $scoring['score'],
            'score_breakdown' => $scoring['breakdown'],
            'generation_meta' => $args['generation_meta'] ?? null,
            'status' => self::STATUS_PROPOSED,
            'idea_hash' => StrategyCanonicalHash::sha256($hashInput),
        ]);
    }

    /**
     * Derive idea candidates from Opportunity Radar records that have no
     * idea yet. Deterministic: no synthetic content, every field comes from
     * the persisted opportunity.
     *
     * @return array<int,AiVentureIdea>
     */
    public function ideateFromRadar(): array
    {
        $known = AiVentureIdea::query()
            ->whereNotNull('opportunity_id')
            ->pluck('opportunity_id')
            ->all();

        $opportunities = AiOpportunity::query()
            ->whereIn('status', [OpportunityRadarService::STATUS_PROPOSED, OpportunityRadarService::STATUS_VALIDATED])
            ->when($known !== [], fn ($query) => $query->whereNotIn('id', $known))
            ->orderBy('created_at')
            ->get();

        $created = [];
        foreach ($opportunities as $opportunity) {
            $ideaId = 'radar-'.$opportunity->opportunity_id;
            if (AiVentureIdea::query()->where('idea_id', $ideaId)->exists()) {
                continue;
            }

            $market = (array) $opportunity->market;
            $marketSize = isset($market['tam']) && is_numeric($market['tam']) ? (float) $market['tam'] : null;

            $created[] = $this->register([
                'idea_id' => $ideaId,
                'title' => $opportunity->title,
                'problem' => $opportunity->problem,
                'icp' => $opportunity->icp,
                'pain' => $opportunity->pain,
                'urgency' => $opportunity->urgency,
                'source' => self::SOURCE_RADAR,
                'opportunity_id' => $opportunity->id,
                'market_size_usd' => $marketSize,
                'pain_severity' => $this->severityFromUrgency($opportunity->urgency),
            ]);
        }

        return $created;
    }

    /**
     * Deterministic 0-100 score with auditable breakdown.
     *
     * @return array{score: float, breakdown: array<string,mixed>}
     */
    public function score(int $painSeverity, string $urgency, ?float $marketSizeUsd, int $founderFit, int $sovereigntyFit): array
    {
        $painComponent = round(($painSeverity / 5) * 30, 2);
        $urgencyComponent = round((self::URGENCY_FACTOR[$urgency] ?? 0.5) * 15, 2);
        $marketComponent = 0.0;
        if ($marketSizeUsd !== null && $marketSizeUsd > 0) {
            $marketComponent = round(min(log10($marketSizeUsd) / 12, 1.0) * 25, 2);
        }
        $founderComponent = round(($founderFit / 5) * 15, 2);
        $sovereigntyComponent = round(($sovereigntyFit / 5) * 15, 2);

        $score = round($painComponent + $urgencyComponent + $marketComponent + $founderComponent + $sovereigntyComponent, 2);

        return [
            'score' => $score,
            'breakdown' => [
                'pain' => ['input' => $painSeverity, 'weight' => 30, 'component' => $painComponent],
                'urgency' => ['input' => $urgency, 'weight' => 15, 'component' => $urgencyComponent],
                'market' => ['input' => $marketSizeUsd, 'weight' => 25, 'component' => $marketComponent],
                'founder_fit' => ['input' => $founderFit, 'weight' => 15, 'component' => $founderComponent],
                'sovereignty_fit' => ['input' => $sovereigntyFit, 'weight' => 15, 'component' => $sovereigntyComponent],
            ],
        ];
    }

    private function severityFromUrgency(string $urgency): int
    {
        return match ($urgency) {
            OpportunityRadarService::URGENCY_CRITICAL => 5,
            OpportunityRadarService::URGENCY_HIGH => 4,
            OpportunityRadarService::URGENCY_LOW => 2,
            default => 3,
        };
    }
}
