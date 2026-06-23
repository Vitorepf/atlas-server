<?php

namespace App\Services\Ai\MarketingDomain\Content;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * TransformationStudio — the ingest half of the before/after flow. It pulls the offer's REAL,
 * release-approved transformation creatives (the producer's affiliate resource center) into the
 * offer's local assets dir and writes the transformations.json manifest the engine reads. This is the
 * legitimate path to before/after on the page: real photos with consent, sourced automatically — not
 * fabricated. Pairs missing either image are skipped. Deterministic given its inputs.
 */
class TransformationStudio
{
    private const EXT = ['image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/avif' => 'avif'];

    /**
     * Download each before/after pair into the offer assets dir and (re)write the manifest.
     *
     * @param  array<int,array<string,mixed>>  $pairs  each: name, result, weeks, before_url|before, after_url|after
     * @param  array<string,mixed>  $opts  assets_root (override)
     * @return array{dir:string,manifest:array<int,array<string,string>>,ingested:int,skipped:int}
     */
    public function ingest(string $offerSlug, array $pairs, array $opts = []): array
    {
        $dir = $this->dir($offerSlug, $opts);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $manifest = [];
        $skipped = 0;
        $i = 0;
        foreach ($pairs as $p) {
            if (! is_array($p)) {
                $skipped++;

                continue;
            }
            $i++;
            $before = $this->fetch((string) ($p['before_url'] ?? $p['before'] ?? ''), $dir, "before-{$i}");
            $after = $this->fetch((string) ($p['after_url'] ?? $p['after'] ?? ''), $dir, "after-{$i}");
            if ($before === '' || $after === '') {
                $skipped++;

                continue;
            }
            $manifest[] = [
                'name' => trim((string) ($p['name'] ?? '')),
                'result' => trim((string) ($p['result'] ?? '')),
                'weeks' => trim((string) ($p['weeks'] ?? '')),
                'before' => $before,
                'after' => $after,
            ];
        }

        file_put_contents($dir.'/transformations.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return ['dir' => $dir, 'manifest' => $manifest, 'ingested' => count($manifest), 'skipped' => $skipped];
    }

    /** Download a remote image into the assets dir; returns the local filename, or '' on any failure. */
    private function fetch(string $url, string $dir, string $stem): string
    {
        $url = trim($url);
        if ($url === '' || preg_match('~^https?://~i', $url) !== 1) {
            return '';
        }
        try {
            $resp = Http::timeout(25)->retry(2, 200)->get($url);
        } catch (\Throwable $e) {
            return '';
        }
        if (! $resp->successful()) {
            return '';
        }
        $ct = strtolower(trim(explode(';', (string) $resp->header('Content-Type'))[0]));
        $ext = self::EXT[$ct] ?? '';
        if ($ext === '' || $resp->body() === '') {
            return '';
        }
        $file = $stem.'.'.$ext;
        file_put_contents($dir.'/'.$file, $resp->body());

        return $file;
    }

    private function dir(string $offerSlug, array $opts): string
    {
        $slug = Str::slug($offerSlug) ?: 'offer';
        $root = rtrim((string) ($opts['assets_root'] ?? storage_path('app/marketing/assets')), '/');

        return $root.'/'.$slug;
    }
}
