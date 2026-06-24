<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Sentinels;

use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * REGRESSION SENTINEL — locks down the bug where AtlasTaskBrainReplenisher::inferIntegrationSites() paired an
 * orphan with a sibling exemplar by ROLE-TOKEN ALONE (the last CamelCase suffix). A `TransferGate` orphan got
 * paired with a `ResourceGate` exemplar because both merely end in 'gate' — semantically different roles.
 *
 * The sentinel reads the docblock of the orphan AND of the proposed sibling site, tokenizes both to lowercase
 * semantic terms, and requires they share at least one CONTENT noun BEYOND the bare role suffix
 * (gate/service/bridge/ledger). When the only shared token is the role suffix it marks the candidate
 * `role_token_only_mismatch` and REFUSES it. Pure + deterministic; a FACTS-only verdict (no scalar score,
 * pétreo). The only I/O is reading the two files via Storage::disk('local').
 */
final class AtlasLoopReplenisherSiblingRoleCoherenceSentinel
{
    public const SCHEMA = 'atlas.loop.sibling_role_coherence.v1';

    /** The bare role suffixes that must NEVER be the sole shared token between an orphan and its sibling. */
    public const ROLE_SUFFIXES = ['gate', 'service', 'bridge', 'ledger'];

    /** Function words dropped before comparing — generic prose, never the semantic role of a class. */
    private const STOPWORDS = [
        'the', 'and', 'for', 'with', 'that', 'this', 'its', 'into', 'are', 'was', 'has', 'but', 'not',
        'all', 'any', 'can', 'may', 'per', 'via', 'use', 'used', 'uses', 'when', 'what', 'which', 'from',
        'only', 'one', 'two', 'each', 'every', 'over', 'across', 'their', 'them', 'they', 'how',
    ];

    /**
     * Pure core: compare two docblocks. Returns a FACTS-only verdict.
     *
     * @return array{schema:string, self_sufficient:bool, deficiencies:list<string>, facts:array<string,mixed>}
     */
    public function inspectDocblocks(string $orphanDocblock, string $siblingDocblock): array
    {
        $orphan = $this->tokens($orphanDocblock);
        $sibling = $this->tokens($siblingDocblock);

        $shared = array_values(array_intersect($orphan, $sibling));
        sort($shared);
        $roleShared = array_values(array_intersect($shared, self::ROLE_SUFFIXES));
        $sharedContent = array_values(array_diff($shared, self::ROLE_SUFFIXES));
        sort($sharedContent);
        sort($roleShared);

        if ($sharedContent !== []) {
            $coherent = true;
            $kind = null;
        } elseif ($roleShared !== []) {
            $coherent = false;
            $kind = 'role_token_only_mismatch'; // exactly today's bug: only the role suffix is shared
        } else {
            $coherent = false;
            $kind = 'no_shared_semantic_token';
        }

        return [
            'schema' => self::SCHEMA,
            'self_sufficient' => $coherent,
            'deficiencies' => $coherent ? [] : [$kind],
            'facts' => [
                'shared_tokens' => $shared,
                'shared_content_tokens' => $sharedContent,
                'role_suffixes_shared' => $roleShared,
                'markers' => $coherent ? [] : [$kind],
            ],
        ];
    }

    /**
     * Real path: resolve each side's docblock (explicit, or read from its file via Storage::disk('local'))
     * then run the pure comparison.
     *
     * @param  array{orphan_docblock?:string, sibling_docblock?:string, orphan_file?:string, sibling_file?:string}  $candidate
     * @return array{schema:string, self_sufficient:bool, deficiencies:list<string>, facts:array<string,mixed>}
     */
    public function inspectCandidate(array $candidate): array
    {
        return $this->inspectDocblocks(
            $this->resolveDocblock($candidate, 'orphan'),
            $this->resolveDocblock($candidate, 'sibling'),
        );
    }

    /**
     * @param  array<string,mixed>  $candidate
     */
    private function resolveDocblock(array $candidate, string $which): string
    {
        $explicit = trim((string) ($candidate[$which.'_docblock'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        $path = trim((string) ($candidate[$which.'_file'] ?? ''));
        if ($path === '') {
            return '';
        }

        try {
            $disk = Storage::disk('local');
            if (! $disk->exists($path)) {
                return '';
            }

            return $this->extractLeadingDocblock((string) $disk->get($path));
        } catch (Throwable) {
            return ''; // fail-soft: an unreadable file yields no tokens (the candidate is then refused as no-shared)
        }
    }

    private function extractLeadingDocblock(string $contents): string
    {
        if (preg_match('#/\*\*(.*?)\*/#s', $contents, $m) === 1) {
            return $m[1];
        }

        return $contents;
    }

    /**
     * Tokenize prose+identifiers to lowercase content terms: split CamelCase, drop short noise + stopwords.
     *
     * @return list<string>
     */
    private function tokens(string $text): array
    {
        $spaced = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $text) ?? $text;
        preg_match_all('/[a-z]+/', strtolower($spaced), $matches);

        $set = [];
        foreach ($matches[0] as $token) {
            if (strlen($token) < 3 || in_array($token, self::STOPWORDS, true)) {
                continue;
            }
            $set[$token] = true;
        }

        return array_keys($set);
    }
}
