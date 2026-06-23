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
        $bridge = $this->seed($asset, $opts);
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
    private function seed(AiMarketingVslAsset $asset, array $opts = []): array
    {
        $headline = trim((string) $asset->core_promise) ?: trim((string) $asset->big_idea) ?: 'A new way forward';
        $sub = trim((string) $asset->hook) ?: trim((string) $asset->big_idea) ?: '';
        $pinAngle = (string) ($opts['pin_angle'] ?? '');
        $pinHook = (string) ($opts['pin_hook'] ?? '');

        $seed = [
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

        // Pre-seed copy slots with the markers of the pinned axes so the amplifier doesn't
        // overwrite them — guarantees variants DIFFER along the axes they were asked to differ.
        if ($pinAngle !== '') {
            $seed['kicker'] = $this->kickerForAngle($pinAngle);
        }
        if ($pinHook !== '') {
            $seed['lead_paragraph'] = $this->leadForHook($pinHook);
        }

        return $seed;
    }

    private function kickerForAngle(string $angle): string
    {
        return match ($angle) {
            'hidden_cause' => 'The real reason · special report',
            'common_enemy' => 'LEAKED · Big Pharma fights this',
            'forbidden_discovery' => 'LEAKED · before it is removed',
            'contrarian_truth' => 'Everything you know is wrong',
            'new_opportunity' => 'A new way — never seen before',
            'shortcut_secret' => 'The secret the wealthy use',
            default => 'Special Report',
        };
    }

    private function leadForHook(string $hook): string
    {
        return match ($hook) {
            'hook_callout_specific' => 'If you are over 40 and have tried everything, stop scrolling. This is for you.',
            'hook_warning' => 'WARNING: stop before you spend another month on injections. Read this first.',
            'hook_question' => 'Do you wonder why nothing has worked? You are not alone — and the reason will surprise you.',
            'hook_shocking_stat' => '9 out of 10 women in this group failed. Here is what the 10th did differently.',
            'hook_contrarian' => 'Everything you know about losing weight is wrong. The opposite is true.',
            'hook_story_open' => 'It was 3:47 am when she finally got it — and her body started cooperating again.',
            default => '',
        };
    }

    private function lang(AiMarketingVslAsset $asset): string
    {
        $geo = mb_strtolower((string) $asset->target_geo.' '.$asset->language);

        return (str_contains($geo, 'pt') || str_contains($geo, 'br') || str_contains($geo, 'portug')) ? 'pt' : 'en';
    }
}
