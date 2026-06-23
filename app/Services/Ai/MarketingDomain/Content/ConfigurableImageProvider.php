<?php

namespace App\Services\Ai\MarketingDomain\Content;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

/**
 * ConfigurableImageProvider — a generic HTTP image-generation driver wired by config (endpoint, key,
 * response path). Works with any JSON image API shaped like {prompt → {…url…}}. Returns null and never
 * throws when not configured or on any error, so the pipeline degrades to "no creative". No key in the
 * repo: the operator supplies it via env.
 */
class ConfigurableImageProvider implements ImageGenerationProvider
{
    public function generate(string $prompt, array $opts = []): ?string
    {
        $cfg = (array) config('atlas.marketing.image_generation', []);
        $endpoint = (string) ($cfg['endpoint'] ?? '');
        $key = (string) ($cfg['api_key'] ?? '');
        if (empty($cfg['enabled']) || $endpoint === '' || $key === '' || trim($prompt) === '') {
            return null;   // not configured → no-op, callers degrade gracefully
        }

        try {
            $resp = Http::withToken($key)->timeout((int) ($cfg['timeout'] ?? 60))->acceptJson()->post($endpoint, array_merge([
                'prompt' => $prompt,
                'size' => (string) ($opts['size'] ?? $cfg['size'] ?? '1024x1024'),
                'n' => (int) ($opts['count'] ?? 1),
            ], (array) ($cfg['extra'] ?? [])));
        } catch (\Throwable $e) {
            return null;
        }
        if (! $resp->successful()) {
            return null;
        }

        $url = Arr::get($resp->json() ?? [], (string) ($cfg['response_path'] ?? 'data.0.url'));

        return is_string($url) && preg_match('~^https?://~i', $url) === 1 ? $url : null;
    }
}
