<?php

namespace App\Services\Ai\MarketingDomain\Content;

/**
 * ImageGenerationProvider — pluggable image-generation backend for the marketing creative pipeline.
 * Implementations call a text-to-image model (Flux/SDXL/OpenAI Images/etc). Returns the generated
 * image URL (or local path), or null when generation is unavailable/disabled — callers degrade
 * gracefully (no creative rather than a broken one).
 */
interface ImageGenerationProvider
{
    /**
     * @param  array<string,mixed>  $opts  size, count, style, …
     */
    public function generate(string $prompt, array $opts = []): ?string;
}
