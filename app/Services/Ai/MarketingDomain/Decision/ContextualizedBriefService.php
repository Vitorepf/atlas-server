<?php

namespace App\Services\Ai\MarketingDomain\Decision;

use App\Models\AiMarketingVslAsset;
use App\Models\AiMarketingWinningPattern;

/**
 * ContextualizedBriefService — binds the decision engine to the briefs. Where MarketingActionPlaybookResolver
 * returns a GENERIC playbook for an action, this enriches it with the live VSL fields (hook snippet,
 * advertorial titles, ad headlines) + the real Nivor pattern grounding, so the recommendation is
 * specific to THIS offer/niche, not a template. Deterministic.
 */
class ContextualizedBriefService
{
    public function __construct(
        private readonly MarketingActionPlaybookResolver $resolver,
        private readonly BriefGroundingHelper $grounding = new BriefGroundingHelper,
    ) {}

    /**
     * @param  array<string,mixed>  $diagnosis  output of MarketingSymptomActionTree / advisor
     * @return array<string,mixed>
     */
    public function contextual(array $diagnosis, AiMarketingVslAsset $vsl, ?AiMarketingWinningPattern $pattern = null): array
    {
        $primary = (array) ($diagnosis['primary'] ?? []);
        $action = (string) ($primary['action'] ?? '');
        $awareness = trim((string) $vsl->awareness_level) ?: null;

        $playbook = $this->resolver->resolve($action, $awareness);

        return [
            'action' => $action,
            'lever' => $primary['lever'] ?? null,
            'vsl_block' => $primary['vsl_block'] ?? null,
            'playbook' => $playbook,
            'vsl_context' => [
                'big_idea' => $this->str($vsl->big_idea),
                'mechanism_name' => $this->str($vsl->mechanism_name),
                'hook_snippets' => array_slice(array_map('strval', (array) $vsl->power_phrases), 0, 3),
                'advertorial_titles' => array_slice((array) (is_array($vsl->advertorial_brief) ? ($vsl->advertorial_brief['title_options'] ?? []) : []), 0, 3),
                'ad_headlines' => array_slice((array) (is_array($vsl->ad_assets) ? ($vsl->ad_assets['headlines'] ?? []) : []), 0, 5),
            ],
            'pattern_grounding' => $this->grounding->groundBriefFromPattern($pattern, $action),
            'numeric_rule' => $primary['numeric_rule'] ?? null,
            'note' => $playbook === null
                ? 'Ação sem playbook de execução (ex.: HOLD/escala) — seguir a regra numérica.'
                : 'Playbook da skill contextualizado com a VSL real + padrão vencedor do nicho.',
        ];
    }

    private function str(mixed $v): ?string
    {
        if (! is_string($v)) {
            return null;
        }
        $v = trim($v);

        return $v === '' ? null : $v;
    }
}
