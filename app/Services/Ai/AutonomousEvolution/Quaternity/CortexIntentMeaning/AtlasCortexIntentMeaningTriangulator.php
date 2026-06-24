<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Quaternity\CortexIntentMeaning;

/**
 * CORTEX INTENT-MEANING TRIANGULATOR — resolves a free-text operator intent string to the concrete, grounded
 * code sites (PHP class FQCNs and/or files) it actually refers to, using the Cortex inputs already present in
 * AutonomousEvolution: the grounded projection roles, the comprehension originator, and the changed-symbol
 * coverage census. Each collaborator supplies its grounded sites (duck-typed: any object exposing
 * groundedSites(): iterable of ['symbol'=>?string FQCN, 'file'=>string relPath]); in production these are thin
 * adapters over AtlasLoopGroundedProjectionRoles, AtlasLoopComprehensionOriginator and
 * AtlasLoopChangedSymbolCoverageCensus respectively.
 *
 * Output is FACT-shaped, one row per matched (site, token):
 *   ['intent'=>$raw, 'symbol'=>$fqcn|null, 'file'=>$relPath, 'evidence'=>['cortex_role'|'changed_symbol'|
 *    'comprehension_orphan'], 'matched_token'=>$token].
 *
 * ANTI-GOODHART INVARIANT: this primitive emits FACTS only — never a numeric verdict, an ordering weight, or a
 * proportion. It never guesses: a token that maps to no grounded site yields nothing, and an intent that maps to
 * nothing yields an empty array (fail-closed on unknowns). When an intent maps to two or more distinct sites it
 * returns ALL of them (a downstream ambiguity detector consumes the list). Pure: no DB writes, no provider
 * calls; the returned array is sorted byte-stably (by file, then symbol, then token).
 */
final class AtlasCortexIntentMeaningTriangulator
{
    /** @var list<string> */
    private const STOPWORDS = ['the', 'a', 'o', 'de', 'em', 'no', 'na', 'por', 'para', 'is', 'to', 'with', 'e', 'ou'];

    private const MIN_TOKEN_LEN = 3;

    public function __construct(
        private readonly object $projectionRoles,
        private readonly object $comprehensionOriginator,
        private readonly object $changedSymbolCensus,
    ) {
    }

    /**
     * @return list<array{intent:string,symbol:?string,file:string,evidence:list<string>,matched_token:string}>
     */
    public function triangulate(string $intentText): array
    {
        $tokens = $this->tokenize($intentText);
        if ($tokens === []) {
            return [];
        }

        $sites = $this->groundedSites();
        if ($sites === []) {
            return [];
        }

        $rows = [];
        $seen = [];
        foreach ($sites as $site) {
            $shortName = $this->shortName($site['symbol']);
            $baseName = strtolower(basename($site['file']));
            foreach ($tokens as $token) {
                $matchesSymbol = $shortName !== '' && str_contains($shortName, $token);
                $matchesFile = $baseName !== '' && str_contains($baseName, $token);
                if (! $matchesSymbol && ! $matchesFile) {
                    continue;
                }
                $key = ($site['symbol'] ?? '').'|'.$site['file'].'|'.$token;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $rows[] = [
                    'intent' => $intentText,
                    'symbol' => $site['symbol'],
                    'file' => $site['file'],
                    'evidence' => $site['evidence'],
                    'matched_token' => $token,
                ];
            }
        }

        usort($rows, static function (array $x, array $y): int {
            return [$x['file'], (string) $x['symbol'], $x['matched_token']]
                <=> [$y['file'], (string) $y['symbol'], $y['matched_token']];
        });

        return $rows;
    }

    /**
     * Collect grounded sites from the three Cortex collaborators, tagging each with its evidence kind and merging
     * sites reported by more than one collaborator (union of evidence kinds, stable order).
     *
     * @return list<array{symbol:?string,file:string,evidence:list<string>}>
     */
    private function groundedSites(): array
    {
        $merged = [];
        $tagged = [
            ['source' => $this->projectionRoles, 'evidence' => 'cortex_role'],
            ['source' => $this->changedSymbolCensus, 'evidence' => 'changed_symbol'],
            ['source' => $this->comprehensionOriginator, 'evidence' => 'comprehension_orphan'],
        ];

        foreach ($tagged as $tag) {
            foreach ($this->sitesOf($tag['source']) as $raw) {
                $symbol = isset($raw['symbol']) && $raw['symbol'] !== '' ? (string) $raw['symbol'] : null;
                $file = (string) ($raw['file'] ?? ($raw['relPath'] ?? ($raw['path'] ?? '')));
                if ($file === '' && $symbol === null) {
                    continue;
                }
                $key = ($symbol ?? '').'|'.$file;
                if (! isset($merged[$key])) {
                    $merged[$key] = ['symbol' => $symbol, 'file' => $file, 'evidence' => []];
                }
                if (! in_array($tag['evidence'], $merged[$key]['evidence'], true)) {
                    $merged[$key]['evidence'][] = $tag['evidence'];
                }
            }
        }

        return array_values($merged);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function sitesOf(object $collaborator): array
    {
        if (! method_exists($collaborator, 'groundedSites')) {
            return []; // fail-closed: a collaborator that cannot supply sites contributes nothing (never a guess)
        }

        $out = [];
        foreach ((array) $collaborator->groundedSites() as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function tokenize(string $text): array
    {
        $parts = preg_split('/\s+|::/', strtolower(trim($text))) ?: [];
        $tokens = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if (strlen($part) < self::MIN_TOKEN_LEN || in_array($part, self::STOPWORDS, true)) {
                continue;
            }
            if (! in_array($part, $tokens, true)) {
                $tokens[] = $part;
            }
        }

        return $tokens;
    }

    private function shortName(?string $symbol): string
    {
        if ($symbol === null || $symbol === '') {
            return '';
        }
        $pos = strrpos($symbol, '\\');

        return strtolower($pos === false ? $symbol : substr($symbol, $pos + 1));
    }
}
