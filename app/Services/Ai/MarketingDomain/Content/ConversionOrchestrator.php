<?php

namespace App\Services\Ai\MarketingDomain\Content;

use App\Models\AiMarketingVslAsset;

/**
 * ConversionOrchestrator — the end-to-end pipeline that turns a raw VSL asset into a finished
 * conversion-optimized bridge page WITHOUT a human in the loop. It chains: seed bridge from the
 * dissected asset → AggressionAmplifier (audit + inject missing elite patterns until target grade) →
 * BridgePageHtmlRenderer (final HTML). Returns the HTML + the full audit trail. Provider-free —
 * everything comes from MY voice cristalized in the libraries, not the LLM. "Press one button"
 * entry point: asset → page that fires across all 10 conversion dimensions.
 */
class ConversionOrchestrator
{
    public function __construct(
        private readonly AggressionAmplifier $amplifier = new AggressionAmplifier,
        private readonly BridgePageHtmlRenderer $renderer = new BridgePageHtmlRenderer,
    ) {}

    /**
     * @param  array<string,mixed>  $opts  brand, thumbnail_svg, until (target grade), max_iterations
     * @return array{html:string,bridge:array<string,mixed>,before:array<string,mixed>,after:array<string,mixed>,injected:array<int,string>}
     */
    public function orchestrate(AiMarketingVslAsset $asset, array $opts = []): array
    {
        $bridge = $this->seed($asset);
        $amp = $this->amplifier->amplify($bridge, $asset, [
            'until' => (string) ($opts['until'] ?? 'strong'),
            'max_iterations' => (int) ($opts['max_iterations'] ?? 3),
            'niche' => (string) ($opts['niche'] ?? ''),
            'page_kind' => (string) ($opts['page_kind'] ?? 'bridge'),
        ]);
        $finalBridge = $amp['bridge'];
        $finalBridge['meta'] = is_array($finalBridge['meta'] ?? null) ? $finalBridge['meta'] : [];
        $finalBridge['meta']['lang'] = (string) ($finalBridge['meta']['lang'] ?? 'en');

        $html = $this->renderer->render($finalBridge, [
            'brand' => (string) ($opts['brand'] ?? 'The Daily Wellness Report'),
            'thumbnail_svg' => (string) ($opts['thumbnail_svg'] ?? ''),
        ]);

        return [
            'html' => $html,
            'bridge' => $finalBridge,
            'before' => $amp['before'],
            'after' => $amp['after'],
            'injected' => $amp['injected'],
        ];
    }

    /**
     * Build a minimal seed bridge from the asset's dissected fields. The forges and the amplifier
     * will fill in everything else. Kept tiny on purpose — we want the amplifier to do the work.
     *
     * @return array<string,mixed>
     */
    private function seed(AiMarketingVslAsset $asset): array
    {
        $headline = trim((string) $asset->core_promise) ?: trim((string) $asset->big_idea) ?: 'A new way forward';
        $sub = trim((string) $asset->hook) ?: trim((string) $asset->big_idea) ?: '';

        return [
            'meta' => ['lang' => $this->lang($asset)],
            'kicker' => 'Special Report',
            'headline' => mb_strimwidth($headline, 0, 140, ''),
            'subheadline' => mb_strimwidth($sub, 0, 200, ''),
            'lead_paragraph' => '',
            'body_sections' => [],
            'cta_blocks' => [],
            'objection_flips' => [],
            'proof_block' => [],
            'trust_bar' => ['As seen in the news', 'No needles', '60-day money-back'],
        ];
    }

    private function lang(AiMarketingVslAsset $asset): string
    {
        $geo = mb_strtolower((string) $asset->target_geo.' '.$asset->language);

        return (str_contains($geo, 'pt') || str_contains($geo, 'br') || str_contains($geo, 'portug')) ? 'pt' : 'en';
    }
}
