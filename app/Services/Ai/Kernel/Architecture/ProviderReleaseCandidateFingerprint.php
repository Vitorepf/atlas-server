<?php

namespace App\Services\Ai\Kernel\Architecture;

use Illuminate\Support\Str;

final class ProviderReleaseCandidateFingerprint
{
    /**
     * @param  array<string,string|null>  $queryAllowlist
     */
    public function canonicalUrl(string $url, array $queryAllowlist = []): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['host'])) {
            return rtrim(Str::lower($url), '/');
        }

        $scheme = 'https';
        $host = Str::lower((string) $parts['host']);
        $path = '/'.ltrim((string) ($parts['path'] ?? ''), '/');
        $path = rtrim($path, '/');
        $path = $path === '' ? '/' : $path;

        $query = $this->canonicalQuery((string) ($parts['query'] ?? ''), $queryAllowlist);

        return $scheme.'://'.$host.$path.($query !== '' ? '?'.$query : '');
    }

    public function contentHash(string $title, string $url, ?string $publishedAt = null, ?string $bodyHash = null): string
    {
        return hash('sha256', implode('|', [
            Str::lower(trim($title)),
            $this->canonicalUrl($url),
            trim((string) $publishedAt),
            trim((string) $bodyHash),
        ]));
    }

    /**
     * @param  array<string,mixed>  $candidate
     */
    public function dedupeKey(array $candidate): string
    {
        $url = (string) ($candidate['source_url'] ?? $candidate['url'] ?? '');
        $hash = (string) ($candidate['content_hash'] ?? '');

        return hash('sha256', $this->canonicalUrl($url).'|'.$hash);
    }

    /**
     * @param  array<int,array<string,mixed>>  $candidates
     * @return array<int,array<string,mixed>>
     */
    public function uniqueCandidates(array $candidates): array
    {
        $seen = [];
        $unique = [];

        foreach ($candidates as $candidate) {
            $key = $this->dedupeKey($candidate);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $candidate['dedupe_key'] = $key;
            $unique[] = $candidate;
        }

        return $unique;
    }

    /**
     * @param  array<string,string|null>  $queryAllowlist
     */
    private function canonicalQuery(string $query, array $queryAllowlist): string
    {
        if ($query === '' || $queryAllowlist === []) {
            return '';
        }

        parse_str($query, $parsed);
        $allowed = [];

        foreach ($queryAllowlist as $key => $_) {
            if (isset($parsed[$key]) && is_scalar($parsed[$key])) {
                $allowed[$key] = (string) $parsed[$key];
            }
        }

        ksort($allowed);

        return http_build_query($allowed);
    }
}
