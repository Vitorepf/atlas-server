<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Discovery;

use App\Services\Ai\Programming\AtlasDev\Schemas\AtlasDevOperationEnvelope as OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\CodeDiscoveryManifest;
use App\Services\Ai\Programming\AtlasDev\Schemas\CompactSdd;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\CodeCandidate;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ContextRef;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\MissingRef;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Support\AtlasDevStringListNormalizer;
use Throwable;

/**
 * Confirms files / symbols / tests likely involved in a run.
 *
 * Pipeline (deterministic):
 *   1. extract candidate identifiers + path-like tokens from normalized_intent
 *      and from explicit user_constraints
 *   2. resolve each candidate against the filesystem (is_file)
 *   3. cross-check optional symbol lookup (Code Intelligence) — advisory only
 *   4. confirm via RipgrepRunner when available; rg absence is OK
 *   5. discover conventional tests for confirmed targets
 *   6. anything not resolved becomes a missing_ref — never inventa path
 *
 * Confidence ladder per contracts doc 5.2 invariants 3–7:
 *   confirmed_fact      → ≥2 confirmed likely files, no missing required ref
 *   strong_inference    → ≥1 confirmed likely file
 *   hypothesis          → 0 confirmed files but ≥1 candidate token
 *   blocking_ambiguity  → 0 confirmed files and 0 usable tokens
 */
final class CodeDiscoveryEngine
{
    /**
     * @var list<string>
     */
    public const DEFAULT_FORBIDDEN_GLOBS = [
        'vendor/*',
        'node_modules/*',
        'storage/framework/*',
        '.env',
        '.env.*',
    ];

    /**
     * @var list<string>
     */
    private const STOPWORDS = [
        'corrigir', 'corrija', 'fix', 'bug', 'feature', 'add', 'remove', 'update',
        'in', 'em', 'no', 'na', 'the', 'a', 'an', 'and', 'e', 'que', 'pra',
        'for', 'with', 'com', 'sem', 'todo', 'tudo', 'rodar', 'execute',
        'teste', 'test', 'tests', 'falhando', 'failing', 'failed',
    ];

    private readonly RipgrepRunner $rg;

    private readonly ?SymbolLookup $symbols;

    public function __construct(
        ?RipgrepRunner $rg = null,
        ?SymbolLookup $symbols = null,
    ) {
        $this->rg = $rg ?? new RipgrepRunner;
        $this->symbols = $symbols;
    }

    public function discover(OperationEnvelope $envelope, CompactSdd $compactSdd): CodeDiscoveryManifest
    {
        $workspace = $envelope->workspace;
        $intent = $envelope->normalizedIntent;
        $tokens = $this->extractTokens($intent, $envelope->userConstraints);

        $confirmedFiles = [];
        $missing = [];
        $relatedSymbols = [];
        $relatedTests = [];
        $relatedCommands = [];

        $workspaceExists = is_dir($workspace);

        foreach ($tokens['paths'] as $pathToken) {
            $absolute = $this->resolveAbsolute($workspace, $pathToken);
            if ($absolute !== null && is_file($absolute)) {
                $confirmedFiles[$absolute] = [
                    'path' => $absolute,
                    'reason' => 'path mentioned in intent',
                    'confidence' => 0.95,
                    'symbols' => [],
                ];

                continue;
            }

            $missing[] = new MissingRef(
                what: $pathToken,
                whyMissing: 'path not found in workspace via is_file()',
            );
        }

        foreach ($tokens['symbols'] as $symbol) {
            $hits = $this->lookupSymbol($workspace, $symbol);

            foreach ($hits as $hit) {
                if (! is_file($hit['path'])) {
                    continue;
                }
                if (! isset($confirmedFiles[$hit['path']])) {
                    $confirmedFiles[$hit['path']] = [
                        'path' => $hit['path'],
                        'reason' => $hit['reason'],
                        'confidence' => $hit['confidence'],
                        'symbols' => [$symbol],
                    ];
                } else {
                    $existing = $confirmedFiles[$hit['path']]['symbols'] ?? [];
                    if (! in_array($symbol, $existing, true)) {
                        $existing[] = $symbol;
                    }
                    $confirmedFiles[$hit['path']]['symbols'] = array_values($existing);
                    $confirmedFiles[$hit['path']]['confidence'] = max(
                        (float) $confirmedFiles[$hit['path']]['confidence'],
                        (float) $hit['confidence'],
                    );
                }

                $relatedSymbols[$symbol] = new ContextRef(
                    kind: ContextRef::KIND_SYMBOL,
                    ref: 'code_intelligence://symbol/'.$symbol,
                    reason: $hit['reason'],
                );
            }

            if ($hits === []) {
                $missing[] = new MissingRef(
                    what: 'symbol:'.$symbol,
                    whyMissing: 'no filesystem confirmation via rg or symbol lookup',
                );
            }
        }

        foreach ($confirmedFiles as $path => $info) {
            $testPath = $this->guessTestPath($workspace, $path);
            if ($testPath !== null && is_file($testPath) && ! isset($relatedTests[$testPath])) {
                $relatedTests[$testPath] = new ContextRef(
                    kind: ContextRef::KIND_TEST,
                    ref: 'file://'.$testPath,
                    reason: 'conventional test path for '.basename($path),
                );
            }
        }

        $likely = [];
        foreach ($confirmedFiles as $info) {
            $likely[] = new CodeCandidate(
                path: $info['path'],
                reason: $info['reason'],
                confidence: min(1.0, max(0.0, (float) $info['confidence'])),
                symbols: array_values($info['symbols'] ?? []),
            );
        }

        usort($likely, static fn (CodeCandidate $a, CodeCandidate $b): int => strcmp($a->path, $b->path));

        $confidence = $this->classifyConfidence(
            confirmedCount: count($likely),
            candidateTokenCount: count($tokens['symbols']) + count($tokens['paths']),
            workspaceExists: $workspaceExists,
        );

        if ($confidence === CodeDiscoveryManifest::CONFIDENCE_BLOCKING_AMBIGUITY && $missing === []) {
            $missing[] = new MissingRef(
                what: 'intent_tokens',
                whyMissing: 'no usable tokens extracted from normalized_intent',
            );
        }

        if (in_array($confidence, [
            CodeDiscoveryManifest::CONFIDENCE_HYPOTHESIS,
            CodeDiscoveryManifest::CONFIDENCE_BLOCKING_AMBIGUITY,
        ], true) && $missing === []) {
            $missing[] = new MissingRef(
                what: 'confirmation',
                whyMissing: 'no candidate reached strong_inference threshold',
            );
        }

        $relatedSymbolsList = array_values($relatedSymbols);
        usort($relatedSymbolsList, static fn (ContextRef $a, ContextRef $b): int => strcmp($a->ref, $b->ref));

        $relatedTestsList = array_values($relatedTests);
        usort($relatedTestsList, static fn (ContextRef $a, ContextRef $b): int => strcmp($a->ref, $b->ref));

        $missingList = $missing;
        usort($missingList, static fn (MissingRef $a, MissingRef $b): int => strcmp($a->what, $b->what));

        $payload = [
            'confidence' => $confidence,
            'forbidden_files' => self::DEFAULT_FORBIDDEN_GLOBS,
            'likely_files' => array_map(
                static fn (CodeCandidate $c): array => $c->toCanonicalArray(),
                $likely,
            ),
            'manifest_hash' => '',
            'missing_refs' => array_map(
                static fn (MissingRef $r): array => $r->toCanonicalArray(),
                $missingList,
            ),
            'provider_safe' => true,
            'related_commands' => array_map(
                static fn (ContextRef $r): array => $r->toCanonicalArray(),
                $relatedCommands,
            ),
            'related_symbols' => array_map(
                static fn (ContextRef $r): array => $r->toCanonicalArray(),
                $relatedSymbolsList,
            ),
            'related_tests' => array_map(
                static fn (ContextRef $r): array => $r->toCanonicalArray(),
                $relatedTestsList,
            ),
            'run_id' => $envelope->runId,
            'schema_version' => CodeDiscoveryManifest::SCHEMA_VERSION,
        ];

        $manifestHash = CanonicalHasher::hashWithout($payload, 'manifest_hash');

        return new CodeDiscoveryManifest(
            runId: $envelope->runId,
            likelyFiles: $likely,
            relatedSymbols: $relatedSymbolsList,
            relatedTests: $relatedTestsList,
            relatedCommands: $relatedCommands,
            confidence: $confidence,
            missingRefs: $missingList,
            forbiddenFiles: self::DEFAULT_FORBIDDEN_GLOBS,
            providerSafe: true,
            manifestHash: $manifestHash,
        );
    }

    /**
     * @param  list<string>  $userConstraints
     * @return array{symbols:list<string>,paths:list<string>}
     */
    private function extractTokens(string $intent, array $userConstraints): array
    {
        $haystack = $intent;
        foreach ($userConstraints as $constraint) {
            if (is_string($constraint)) {
                $haystack .= "\n".$constraint;
            }
        }

        $paths = [];
        if (preg_match_all('@(?<![\w./])([A-Za-z0-9_./-]+\.(?:php|ts|tsx|js|jsx|html|css|md|blade\.php))@u', $haystack, $matches) > 0) {
            foreach ($matches[1] as $hit) {
                $paths[] = trim($hit);
            }
        }

        $symbols = [];
        if (preg_match_all('/\b[A-Z][A-Za-z0-9]+(?:[A-Z][A-Za-z0-9]+)+\b/u', $haystack, $matches) > 0) {
            foreach ($matches[0] as $hit) {
                $symbols[] = $hit;
            }
        }

        if (preg_match_all('/\b[a-z][a-z0-9]+(?:_[a-z0-9]+){2,}\b/u', $haystack, $matches) > 0) {
            foreach ($matches[0] as $hit) {
                if (! in_array($hit, self::STOPWORDS, true)) {
                    $symbols[] = $hit;
                }
            }
        }

        $symbols = AtlasDevStringListNormalizer::uniqueSortedStrings(array_values(array_filter(
            $symbols,
            static fn (string $token): bool => mb_strlen($token) >= 3,
        )));
        $paths = AtlasDevStringListNormalizer::uniqueSortedStrings($paths);

        return [
            'symbols' => $symbols,
            'paths' => $paths,
        ];
    }

    private function resolveAbsolute(string $workspace, string $candidate): ?string
    {
        $candidate = ltrim($candidate, './');
        if ($candidate === '') {
            return null;
        }

        if ($candidate[0] === '/') {
            return $candidate;
        }

        return rtrim($workspace, '/').'/'.$candidate;
    }

    /**
     * @return list<array{path:string,reason:string,confidence:float}>
     */
    private function lookupSymbol(string $workspace, string $symbol): array
    {
        $hits = [];

        $symbolHits = [];
        if ($this->symbols !== null) {
            try {
                $symbolHits = $this->symbols->find($workspace, $symbol);
            } catch (Throwable) {
                $symbolHits = [];
            }
        }

        foreach ($symbolHits as $hit) {
            if (! is_array($hit) || ! isset($hit['path']) || ! is_string($hit['path'])) {
                continue;
            }
            $absolute = $this->resolveAbsolute($workspace, $hit['path']);
            if ($absolute !== null && is_file($absolute)) {
                $hits[$absolute] = [
                    'path' => $absolute,
                    'reason' => 'symbol_lookup confirmed file for '.$symbol,
                    'confidence' => 0.85,
                ];
            }
        }

        if ($this->rg->isAvailable()) {
            $rgHits = $this->rg->search($workspace, $symbol, ['*.php', '*.ts', '*.tsx', '*.js', '*.jsx']);
            foreach ($rgHits as $rgHit) {
                $absolute = $this->resolveAbsolute($workspace, (string) $rgHit['file']);
                if ($absolute === null || ! is_file($absolute)) {
                    continue;
                }
                if (! isset($hits[$absolute])) {
                    $hits[$absolute] = [
                        'path' => $absolute,
                        'reason' => 'rg confirmed token "'.$symbol.'"',
                        'confidence' => 0.7,
                    ];
                } else {
                    $hits[$absolute]['confidence'] = min(1.0, $hits[$absolute]['confidence'] + 0.1);
                }
            }
        }

        return array_values($hits);
    }

    private function guessTestPath(string $workspace, string $absolutePath): ?string
    {
        $workspace = rtrim($workspace, '/');
        if (! str_starts_with($absolutePath, $workspace.'/')) {
            return null;
        }

        $relative = substr($absolutePath, strlen($workspace) + 1);
        $basename = basename($absolutePath, '.php');

        $candidates = [];
        if (str_starts_with($relative, 'app/')) {
            $candidates[] = $workspace.'/tests/Unit/'.$basename.'Test.php';
            $candidates[] = $workspace.'/tests/Feature/'.$basename.'Test.php';
            $inside = substr($relative, strlen('app/'));
            $candidates[] = $workspace.'/tests/Unit/'.dirname($inside).'/'.$basename.'Test.php';
        }

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function classifyConfidence(int $confirmedCount, int $candidateTokenCount, bool $workspaceExists): string
    {
        if (! $workspaceExists || ($confirmedCount === 0 && $candidateTokenCount === 0)) {
            return CodeDiscoveryManifest::CONFIDENCE_BLOCKING_AMBIGUITY;
        }

        if ($confirmedCount === 0) {
            return $candidateTokenCount > 0
                ? CodeDiscoveryManifest::CONFIDENCE_HYPOTHESIS
                : CodeDiscoveryManifest::CONFIDENCE_BLOCKING_AMBIGUITY;
        }

        if ($confirmedCount >= 2) {
            return CodeDiscoveryManifest::CONFIDENCE_CONFIRMED_FACT;
        }

        return CodeDiscoveryManifest::CONFIDENCE_STRONG_INFERENCE;
    }
}
