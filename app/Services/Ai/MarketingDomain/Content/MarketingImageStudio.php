<?php

namespace App\Services\Ai\MarketingDomain\Content;

use App\Models\AiMarketingVslAsset;

/**
 * MarketingImageStudio — generates the LEGITIMATE visual creatives for a funnel (product mockup,
 * mechanism illustration, lifestyle, thumbnail background), grounded in the VSL's own ammunition.
 * It deliberately does NOT generate "result proof" (synthetic before/after of people who didn't use
 * the product) — that's fabricated evidence and the fastest way to lose the ad account; real
 * before/after comes from the producer via TransformationStudio. Degrades to null if generation is off.
 */
class MarketingImageStudio
{
    public const KINDS = ['product_mockup', 'mechanism_illustration', 'lifestyle', 'thumbnail_bg'];

    public function __construct(private readonly ImageGenerationProvider $provider = new ConfigurableImageProvider) {}

    /**
     * @param  array<string,mixed>  $opts  size, count, style
     */
    public function generate(AiMarketingVslAsset $asset, string $kind, array $opts = []): ?string
    {
        $prompt = $this->prompt($asset, $kind);

        return $prompt === '' ? null : $this->provider->generate($prompt, $opts);
    }

    /** The text-to-image prompt per creative kind, grounded in the dissected VSL. Public for inspection/testing. */
    public function prompt(AiMarketingVslAsset $asset, string $kind): string
    {
        if (! in_array($kind, self::KINDS, true)) {
            return '';
        }
        $offer = is_array($asset->offer) ? $asset->offer : [];
        $product = trim((string) ($offer['product_name'] ?? $asset->mechanism_name ?? 'the supplement'));
        $form = $this->form($asset);
        $niche = trim((string) ($asset->niche ?: 'health'));

        return match ($kind) {
            'product_mockup' => "Professional product photography of {$product}, a {$form}, on a clean white studio background, soft shadows, premium supplement packaging, high detail, commercial e-commerce style. No text, no people.",
            'mechanism_illustration' => 'Clean medical infographic illustration of three fat-burning hormones (GLP-1, GIP, glucagon) working together in metabolism, friendly modern flat style, soft teal and green palette, labeled, editorial science magazine look. No real people, no claims.',
            'lifestyle' => 'Bright authentic lifestyle photo of a confident, healthy woman over 40 enjoying her morning at home, natural light, candid and warm, wellness editorial style. Generic stock feel, not a testimonial, no text overlay.',
            'thumbnail_bg' => "Dramatic dark news-report background texture for a {$niche} video thumbnail, subtle red and gold accents, slight grain, cinematic, empty center for text. No people, no text.",
            default => '',
        };
    }

    private function form(AiMarketingVslAsset $asset): string
    {
        $blob = mb_strtolower((string) $asset->mechanism_name.' '.json_encode($asset->offer, JSON_UNESCAPED_UNICODE));
        foreach (['drops' => 'dropper bottle', 'gota' => 'dropper bottle', 'capsule' => 'capsule bottle', 'cápsula' => 'capsule bottle', 'powder' => 'powder jar', 'pó' => 'powder jar'] as $needle => $form) {
            if (str_contains($blob, $needle)) {
                return $form;
            }
        }

        return 'supplement bottle';
    }
}
