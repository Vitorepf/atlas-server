<?php

declare(strict_types=1);

namespace App\Services\Ai\OperatorIntelligence\Support;

use Illuminate\Support\Str;

/**
 * Pure stack/doc/env extractors for project learning (full-pass peel).
 * I/O stays on AtlasProjectStackLearner.
 */
final class ProjectStackLearnSupport
{
    /**
     * @param  array<string,mixed>  $manifestJson
     * @return array<string,mixed>
     */
    public static function dependenciesFromManifest(array $manifestJson): array
    {
        return array_merge(
            is_array($manifestJson['require'] ?? null) ? $manifestJson['require'] : [],
            is_array($manifestJson['require-dev'] ?? null) ? $manifestJson['require-dev'] : [],
            is_array($manifestJson['dependencies'] ?? null) ? $manifestJson['dependencies'] : [],
            is_array($manifestJson['devDependencies'] ?? null) ? $manifestJson['devDependencies'] : [],
        );
    }

    /**
     * @param  array<string,mixed>  $deps
     * @param  array<string,string>  $signatures package → label
     * @return array{0:list<string>,1:list<array{kind:string,fact:string,source:string}>}
     */
    public static function matchStack(array $deps, array $signatures, string $manifestName): array
    {
        $stack = [];
        $facts = [];
        foreach ($signatures as $pkg => $label) {
            if (! isset($deps[$pkg])) {
                continue;
            }
            $version = is_string($deps[$pkg]) ? $deps[$pkg] : '';
            $stack[] = $label.($version !== '' ? ' '.$version : '');
            $facts[] = [
                'kind' => 'stack',
                'fact' => $label.($version !== '' ? ' ('.$version.')' : ''),
                'source' => $manifestName,
            ];
        }

        return [$stack, $facts];
    }

    /**
     * First substantive paragraph from doc text (skip headings/badges/frontmatter).
     *
     * @return array{source:string,excerpt:string}
     */
    public static function excerptFromDocText(string $text, string $sourceName, int $minLen = 60, int $maxLen = 400): array
    {
        $skipPrefix = ['#', '---', '![', '[!', '<!--', '<', '|', '```', '> '];
        foreach (preg_split('/\n\s*\n/', $text) ?: [] as $para) {
            $clean = trim(preg_replace('/\s+/', ' ', $para) ?? '');
            $skip = false;
            foreach ($skipPrefix as $p) {
                if (str_starts_with($clean, $p)) {
                    $skip = true;
                    break;
                }
            }
            if (! $skip && mb_strlen($clean) >= $minLen) {
                return ['source' => $sourceName, 'excerpt' => Str::limit($clean, $maxLen)];
            }
        }

        return ['source' => '', 'excerpt' => ''];
    }

    /**
     * @return array<string,string> database label → source file
     */
    public static function databasesFromEnv(string $envContents): array
    {
        $found = [];
        if (preg_match('/^DB_CONNECTION=(\w+)/m', $envContents, $m) === 1) {
            $found[ucfirst($m[1]).' (DB_CONNECTION)'] = '.env';
        }
        if (str_contains($envContents, 'REDIS_HOST')) {
            $found['Redis'] = '.env';
        }

        return $found;
    }
}
