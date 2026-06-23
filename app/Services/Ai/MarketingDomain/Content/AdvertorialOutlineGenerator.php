<?php

namespace App\Services\Ai\MarketingDomain\Content;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Knowledge\MarketingPlaybook;
use App\Services\Ai\MarketingDomain\Scoring\MessageMatchScorer;

/**
 * AdvertorialOutlineGenerator — the bridge-builder skill. Generates listicle advertorial outlines
 * (the #1 cold-traffic format) from a VSL angle, grounded in the extracted fields, and scores the
 * outline's congruency against the VSL promise (MessageMatchScorer). Deterministic.
 */
class AdvertorialOutlineGenerator
{
    public function __construct(
        private readonly MarketingPlaybook $playbook = new MarketingPlaybook,
        private readonly MessageMatchScorer $messageMatch = new MessageMatchScorer,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function generate(AiMarketingVslAsset $asset, string $angle): array
    {
        $spec = $this->playbook->advertorialSpec();
        $promise = trim((string) $asset->core_promise) ?: trim((string) $asset->big_idea) ?: $angle;
        $audience = trim((string) $asset->niche) ?: 'o público';

        $titles = [
            "{count} Razões Por Que {$audience} Está Trocando Para {$angle}",
            "{count} Formas Que {$angle} Resolve {$promise}",
            "A Verdade Sobre {$angle} Que {$audience} Precisa Saber",
        ];
        $count = (string) (5 + (strlen($angle) % 5)); // deterministic 5-9 (same input → same outline)
        $titles = array_map(static fn (string $t): string => str_replace('{count}', $count, $t), $titles);

        $bullets = [
            "Item 1 — o problema real ({$asset->problem_mechanism})",
            'Item 2 — por que as soluções comuns falham',
            "Item 3 — o mecanismo único ({$asset->mechanism_name})",
            'Item 4 — prova/depoimento específico',
            'Item 5 — como começar (CTA pra VSL)',
        ];

        $congruency = $this->messageMatch->score($angle, $titles[0], $promise);

        return [
            'skill' => 'bridge-builder',
            'format' => $spec['format'],
            'title_options' => $titles,
            'bullet_outline' => $bullets,
            'length_target' => $spec['length'],
            'congruency_score' => $congruency['score'],
            'elements' => $spec['elements'],
            'note' => 'Advertorial listicle com conteúdo original — converte E mantém a conta no ar (uptime).',
        ];
    }
}
