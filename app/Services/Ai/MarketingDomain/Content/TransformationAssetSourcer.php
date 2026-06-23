<?php

namespace App\Services\Ai\MarketingDomain\Content;

use App\Models\AiMarketingVslAsset;
use Illuminate\Support\Str;

/**
 * TransformationAssetSourcer — gives the engine the ability to fill the before/after proof block on its
 * own, from the offer's real creative assets (the producer's affiliate resource center the operator
 * drops into the assets dir, or an explicit manifest). It resolves, in order: explicit opts → a
 * transformations.json manifest in the offer's assets dir → a conventional before-N/after-N file scan.
 * Returns [] when no assets exist (the section simply doesn't render). Deterministic; no fabrication.
 */
class TransformationAssetSourcer
{
    private const IMG_EXT = ['jpg', 'jpeg', 'png', 'webp', 'avif'];

    /**
     * @param  array<string,mixed>  $opts  transformations (array), assets_dir (string), base_url (string)
     * @return array<int,array{name:string,result:string,weeks:string,before_url:string,after_url:string}>
     */
    public function source(AiMarketingVslAsset $asset, array $opts = []): array
    {
        if (is_array($opts['transformations'] ?? null) && $opts['transformations'] !== []) {
            return $this->normalize($opts['transformations']);
        }

        $dir = (string) ($opts['assets_dir'] ?? $this->defaultDir($asset));
        $base = rtrim((string) ($opts['base_url'] ?? ''), '/');

        $manifest = rtrim($dir, '/').'/transformations.json';
        if (is_file($manifest)) {
            $data = json_decode((string) file_get_contents($manifest), true);
            if (is_array($data)) {
                return $this->normalize($data, $base);
            }
        }

        return $this->scan($dir, $base);
    }

    /**
     * @param  array<int,mixed>  $rows
     * @return array<int,array{name:string,result:string,weeks:string,before_url:string,after_url:string}>
     */
    private function normalize(array $rows, string $base = ''): array
    {
        $out = [];
        foreach ($rows as $r) {
            if (! is_array($r)) {
                continue;
            }
            $before = $this->url((string) ($r['before'] ?? $r['before_url'] ?? ''), $base);
            $after = $this->url((string) ($r['after'] ?? $r['after_url'] ?? ''), $base);
            if ($before === '' || $after === '') {
                continue;
            }
            $out[] = [
                'name' => trim((string) ($r['name'] ?? '')),
                'result' => trim((string) ($r['result'] ?? '')),
                'weeks' => trim((string) ($r['weeks'] ?? '')),
                'before_url' => $before,
                'after_url' => $after,
            ];
        }

        return array_slice($out, 0, 6);
    }

    /**
     * Convention scan: before-1.jpg + after-1.jpg, before-2.png + after-2.png, … in the assets dir.
     *
     * @return array<int,array{name:string,result:string,weeks:string,before_url:string,after_url:string}>
     */
    private function scan(string $dir, string $base): array
    {
        if (! is_dir($dir)) {
            return [];
        }
        $out = [];
        for ($i = 1; $i <= 6; $i++) {
            $b = $this->firstExisting($dir, "before-{$i}");
            $a = $this->firstExisting($dir, "after-{$i}");
            if ($b === '' || $a === '') {
                continue;
            }
            $out[] = [
                'name' => '', 'result' => '', 'weeks' => '',
                'before_url' => $this->url($b, $base),
                'after_url' => $this->url($a, $base),
            ];
        }

        return $out;
    }

    private function firstExisting(string $dir, string $stem): string
    {
        foreach (self::IMG_EXT as $ext) {
            $p = rtrim($dir, '/').'/'.$stem.'.'.$ext;
            if (is_file($p)) {
                return $stem.'.'.$ext;
            }
        }

        return '';
    }

    /** Keep absolute http(s) URLs as-is; otherwise prefix with base_url (the operator's hosting root). */
    private function url(string $u, string $base): string
    {
        $u = trim($u);
        if ($u === '') {
            return '';
        }
        if (preg_match('~^https?://~i', $u) === 1) {
            return $u;
        }

        return $base !== '' ? $base.'/'.ltrim($u, '/') : $u;
    }

    private function defaultDir(AiMarketingVslAsset $asset): string
    {
        $offer = is_array($asset->offer) ? $asset->offer : [];
        $slug = Str::slug((string) ($offer['product_name'] ?? $asset->mechanism_name ?? 'offer')) ?: 'offer';

        return storage_path('app/marketing/assets/'.$slug);
    }
}
