<?php

namespace App\Services\Ai\MarketingDomain;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Knowledge\MarketingPlaybook;

/**
 * CreativeVariationGeneratorService — the creative-pipeline skill. Generates N distinct hook concepts
 * for a VSL (the #1 scale lever on YouTube), each grounded in the extracted fields (problem_mechanism,
 * power_phrases, big_idea, top_terms) and tagged with a pattern-interrupt type + primary emotion
 * (LF8/Cialdini) + production format. Deterministic: same asset + index → same concept.
 */
class CreativeVariationGeneratorService
{
    /** Distinct pattern-interrupt archetypes to rotate across variations. */
    private const ARCHETYPES = [
        ['type' => 'shocking_stat', 'emotion' => 'freedom_from_fear', 'format' => 'UGC handheld + bold on-screen number'],
        ['type' => 'bold_claim', 'emotion' => 'superiority', 'format' => 'talking-head avatar (HeyGen)'],
        ['type' => 'provocative_question', 'emotion' => 'social_approval', 'format' => 'UGC selfie, direct address'],
        ['type' => 'contrarian', 'emotion' => 'commitment', 'format' => 'pattern-interrupt cut at 4s'],
        ['type' => 'relatable_pain', 'emotion' => 'survival', 'format' => 'story open, 1st person'],
        ['type' => 'curiosity_gap', 'emotion' => 'reciprocity', 'format' => 'demo/reveal teaser'],
    ];

    public function __construct(private readonly MarketingPlaybook $playbook = new MarketingPlaybook) {}

    /**
     * @return array<int,array<string,mixed>>
     */
    public function generateHookVariations(AiMarketingVslAsset $asset, int $count = 6): array
    {
        $count = max(1, min(12, $count));
        $angles = $this->angles($asset);
        $retention = $this->playbook->videoAdStructure()['retention_rules'];

        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $arch = self::ARCHETYPES[$i % count(self::ARCHETYPES)];
            $angle = $angles[$i % count($angles)];
            $out[] = [
                'n' => $i + 1,
                'pattern_interrupt_type' => $arch['type'],
                'primary_emotion' => $arch['emotion'],
                'production_format' => $arch['format'],
                'angle' => $angle,
                'hook_seed' => $this->seed($arch['type'], $angle),
                'retention_rule' => $retention[$i % count($retention)] ?? '',
                'no_brand_first_2s' => true,
            ];
        }

        return $out;
    }

    /**
     * @return array<int,string>
     */
    private function angles(AiMarketingVslAsset $asset): array
    {
        $angles = array_values(array_filter([
            trim((string) $asset->big_idea),
            trim((string) $asset->problem_mechanism),
            trim((string) $asset->core_promise),
            trim((string) $asset->mechanism_name),
            ...array_map('strval', array_slice((array) $asset->power_phrases, 0, 3)),
            ...array_map('strval', array_slice((array) $asset->top_terms, 0, 3)),
        ], static fn (string $s): bool => $s !== ''));

        return $angles !== [] ? $angles : ['o ângulo principal da VSL'];
    }

    private function seed(string $type, string $angle): string
    {
        return match ($type) {
            'shocking_stat' => "Abrir com um número surpreendente sobre: {$angle}",
            'bold_claim' => "Claim ousado e contrário sobre: {$angle}",
            'provocative_question' => "Pergunta provocativa que para o skip sobre: {$angle}",
            'contrarian' => "\"Todo mundo erra isso\" sobre: {$angle}",
            'relatable_pain' => "Dor relatável em 1ª pessoa sobre: {$angle}",
            default => "Curiosity gap sobre: {$angle}",
        };
    }
}
