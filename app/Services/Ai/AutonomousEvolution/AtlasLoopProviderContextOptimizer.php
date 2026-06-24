<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopDecompositionShapeFingerprinter;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;
use Throwable;

/**
 * Deterministic pre-prompt context shaper for loop provider calls.
 *
 * OFF is deliberately a raw baseline bundle: same packet in, same unranked bundle out, with no file reads
 * and no prompt mutation. ON ranks only bounded context fragments and preserves the hard safety rails:
 * forbidden files are never injected, paths outside allowed_files are never read, and acceptance/frozen-judge
 * inputs remain outside this advisory context surface.
 */
final class AtlasLoopProviderContextOptimizer
{
    public const SCHEMA_VERSION = 'atlas.loop.provider_context_bundle.v1';
    public const BUNDLE_KIND = 'CONTEXT_BUNDLE';

    public function __construct(
        private readonly ?AtlasLoopProviderRouter $providerRouter = null,
        private readonly ?AtlasLoopDecompositionOutcomeRecorder $outcomeRecorder = null,
        private readonly ?AtlasLoopDecompositionShapeFingerprinter $fingerprinter = null,
    ) {
    }

    /**
     * @param  array<string,mixed>|object  $taskPacket
     * @return array<string,mixed>
     */
    public function optimize(array|object $taskPacket): array
    {
        if (! $this->enabled()) {
            return $this->rawBaselineBundle($taskPacket);
        }

        $provider = $this->provider($taskPacket);
        $tokenCap = ($this->providerRouter ?? new AtlasLoopProviderRouter)
            ->contextTokenLimit($provider, $this->routing($taskPacket));

        $baseline = $this->rawBaselineBundle($taskPacket);
        $fragments = $this->rankedFragments($taskPacket, $tokenCap);

        return array_replace($baseline, [
            'enabled' => true,
            'provider' => $provider,
            'token_cap' => $tokenCap,
            'token_estimate' => array_sum(array_map(
                static fn (array $fragment): int => (int) ($fragment['token_estimate'] ?? 0),
                $fragments,
            )),
            'fragments' => $fragments,
            'metadata' => array_replace((array) $baseline['metadata'], [
                'ranking' => 'symbol_overlap_plus_outcome_ledger',
                'source' => 'allowed_files_scope_in_comprehension_model',
            ]),
        ]);
    }

    /**
     * Raw, unranked, no-read bundle used by the flag-OFF path. Tests compare this byte-for-byte.
     *
     * @param  array<string,mixed>|object  $taskPacket
     * @return array<string,mixed>
     */
    public function rawBaselineBundle(array|object $taskPacket): array
    {
        $allowed = $this->pathList($this->packetValue($taskPacket, 'allowed_files'));
        $scope = $this->pathList($this->packetValue($taskPacket, 'scope_in'));
        $forbidden = $this->pathSet($this->packetValue($taskPacket, 'forbidden_files'));
        $provider = $this->provider($taskPacket);
        $tokenCap = ($this->providerRouter ?? new AtlasLoopProviderRouter)
            ->contextTokenLimit($provider, $this->routing($taskPacket));

        $fragments = [];
        foreach ($this->orderedCandidatePaths($allowed, $scope, $forbidden) as $index => $path) {
            $fragments[] = [
                'id' => $this->fragmentId('baseline', $path),
                'kind' => isset($allowed[$path]) ? 'allowed_file' : 'scope_reference',
                'path' => $path,
                'read' => false,
                'rank' => $index + 1,
                'score' => 0,
                'score_components' => [
                    'symbol_overlap' => 0,
                    'allowed_file' => isset($allowed[$path]) ? 1 : 0,
                    'outcome_hit_rate' => 0.0,
                    'dependency_signal' => 0,
                ],
                'token_estimate' => $this->estimateTokens($path),
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'bundle_kind' => self::BUNDLE_KIND,
            'enabled' => false,
            'provider' => $provider,
            'token_cap' => $tokenCap,
            'token_estimate' => array_sum(array_map(
                static fn (array $fragment): int => (int) $fragment['token_estimate'],
                $fragments,
            )),
            'fragments' => $fragments,
            'guards' => [
                'forbidden_files_injected' => false,
                'acceptance_proposed' => false,
                'frozen_judge_inputs_altered' => false,
                'reads_outside_allowed_files' => false,
            ],
            'metadata' => [
                'allowed_files_count' => count($allowed),
                'scope_in_count' => count($scope),
                'forbidden_files_count' => count($forbidden),
                'baseline' => 'raw_unranked_no_read',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>|object  $taskPacket
     * @return list<array<string,mixed>>
     */
    private function rankedFragments(array|object $taskPacket, int $tokenCap): array
    {
        $allowed = $this->pathList($this->packetValue($taskPacket, 'allowed_files'));
        $scope = $this->pathList($this->packetValue($taskPacket, 'scope_in'));
        $forbidden = $this->pathSet($this->packetValue($taskPacket, 'forbidden_files'));
        $anchors = $this->symbolAnchors($taskPacket);
        $model = $this->scopeModel($taskPacket);

        $fragments = [];
        foreach ($this->orderedCandidatePaths($allowed, $scope, $forbidden) as $index => $path) {
            $isAllowed = isset($allowed[$path]);
            $descriptor = $model instanceof AtlasLoopScopeComprehensionModel ? $model->descriptorFor($path) : null;
            $content = $isAllowed ? $this->allowedFileExcerpt($path, $tokenCap) : null;
            $history = $this->outcomeHistory($taskPacket, $path);
            $overlap = $this->symbolOverlap($anchors, $path.' '.(string) ($descriptor['doc_purpose'] ?? '').' '.(string) $content);
            $hitRate = $history['total'] > 0 ? $history['certified'] / $history['total'] : 0.0;
            $dependencySignal = count((array) ($descriptor['wired_caller_paths'] ?? []))
                + (($descriptor['is_orphan'] ?? false) ? 1 : 0)
                + (($descriptor['clone_cluster_id'] ?? null) !== null ? 1 : 0);

            $score = ($overlap * 10) + ($isAllowed ? 5 : 0) + ($hitRate * 20) + $dependencySignal;
            $fragment = [
                'id' => $this->fragmentId('ranked', $path),
                'kind' => $isAllowed ? 'allowed_file' : 'scope_reference',
                'path' => $path,
                'read' => $content !== null,
                'rank' => 0,
                'score' => round($score, 4),
                'score_components' => [
                    'symbol_overlap' => $overlap,
                    'allowed_file' => $isAllowed ? 1 : 0,
                    'outcome_hit_rate' => round($hitRate, 4),
                    'outcome_history' => $history,
                    'dependency_signal' => $dependencySignal,
                ],
                'dependency_slice' => [
                    'wired_caller_paths' => array_values((array) ($descriptor['wired_caller_paths'] ?? [])),
                    'is_orphan' => (bool) ($descriptor['is_orphan'] ?? false),
                    'clone_cluster_id' => $descriptor['clone_cluster_id'] ?? null,
                ],
                'token_estimate' => $this->estimateTokens($content ?? $path),
                '_index' => $index,
            ];
            if ($content !== null) {
                $fragment['content_excerpt'] = $content;
            }
            $fragments[] = $fragment;
        }

        foreach ($this->originatorCitationFragments($taskPacket, $forbidden) as $fragment) {
            $fragment['_index'] = count($fragments);
            $fragments[] = $fragment;
        }

        usort($fragments, static function (array $a, array $b): int {
            $score = ((float) $b['score']) <=> ((float) $a['score']);
            if ($score !== 0) {
                return $score;
            }

            return ((int) $a['_index']) <=> ((int) $b['_index']);
        });

        $bounded = [];
        $remaining = max(1, $tokenCap);
        foreach ($fragments as $fragment) {
            unset($fragment['_index']);
            if ($remaining <= 0) {
                break;
            }
            $estimate = max(1, (int) ($fragment['token_estimate'] ?? 1));
            if ($estimate > $remaining && isset($fragment['content_excerpt'])) {
                $fragment['content_excerpt'] = mb_substr((string) $fragment['content_excerpt'], 0, max(1, $remaining * 4));
                $estimate = $this->estimateTokens((string) $fragment['content_excerpt']);
                $fragment['token_estimate'] = $estimate;
            }
            if ($estimate <= $remaining) {
                $bounded[] = $fragment;
                $remaining -= $estimate;
            }
        }

        foreach ($bounded as $i => $fragment) {
            $bounded[$i]['rank'] = $i + 1;
        }

        return $bounded;
    }

    /** @return array<string,mixed> */
    private function outcomeHistory(array|object $taskPacket, string $path): array
    {
        $hash = $this->outcomeFingerprint($taskPacket, $path);
        if ($hash === '') {
            return ['certified' => 0, 'total' => 0];
        }

        try {
            return ($this->outcomeRecorder ?? new AtlasLoopDecompositionOutcomeRecorder)->history($hash);
        } catch (Throwable) {
            return ['certified' => 0, 'total' => 0];
        }
    }

    private function outcomeFingerprint(array|object $taskPacket, string $path): string
    {
        foreach (['decomposition_outcome_fingerprints', 'outcome_fingerprints'] as $key) {
            $map = $this->packetValue($taskPacket, $key);
            if (is_array($map) && isset($map[$path]) && is_string($map[$path])) {
                return trim($map[$path]);
            }
        }

        $plans = $this->packetValue($taskPacket, 'decomposition_plans');
        if (is_array($plans) && isset($plans[$path]) && is_array($plans[$path])) {
            return (string) (($this->fingerprinter ?? new AtlasLoopDecompositionShapeFingerprinter)
                ->fingerprint($plans[$path])['hash'] ?? '');
        }

        return '';
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function originatorCitationFragments(array|object $taskPacket, array $forbidden): array
    {
        $originator = $this->packetValue($taskPacket, 'comprehension_originator')
            ?? $this->packetValue($taskPacket, 'origination');
        if (! is_array($originator)) {
            return [];
        }

        $fragments = [];
        foreach ($this->stringList($originator['cited_symbols'] ?? []) as $symbol) {
            $path = str_replace('\\', '/', $symbol);
            if (isset($forbidden[$path])) {
                continue;
            }
            $fragments[] = [
                'id' => $this->fragmentId('originator', $symbol),
                'kind' => 'originator_citation',
                'symbol' => $symbol,
                'path' => null,
                'read' => false,
                'rank' => 0,
                'score' => 3,
                'score_components' => [
                    'symbol_overlap' => 0,
                    'allowed_file' => 0,
                    'outcome_hit_rate' => 0.0,
                    'outcome_history' => ['certified' => 0, 'total' => 0],
                    'dependency_signal' => 0,
                ],
                'token_estimate' => $this->estimateTokens($symbol),
            ];
        }

        return $fragments;
    }

    private function allowedFileExcerpt(string $path, int $tokenCap): ?string
    {
        $absolute = $this->absolutePath($path);
        if ($absolute === null || ! is_file($absolute)) {
            return null;
        }

        try {
            $content = file_get_contents($absolute);
        } catch (Throwable) {
            return null;
        }
        if (! is_string($content) || $content === '') {
            return null;
        }

        return mb_substr($content, 0, max(256, min(12000, $tokenCap * 4)));
    }

    private function absolutePath(string $path): ?string
    {
        $path = $this->normalizePath($path);
        if ($path === '' || str_contains($path, '..')) {
            return null;
        }

        $base = function_exists('base_path') ? base_path() : getcwd();
        if (! is_string($base) || $base === '') {
            return null;
        }

        $candidate = rtrim($base, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$path;
        $realBase = realpath($base);
        $realCandidate = realpath($candidate);
        if ($realBase === false || $realCandidate === false) {
            return null;
        }

        return str_starts_with($realCandidate, rtrim($realBase, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)
            ? $realCandidate
            : null;
    }

    private function enabled(): bool
    {
        try {
            return (bool) config(
                'loop.provider_context_optimizer_enabled',
                config('atlas.loop.provider_context_optimizer_enabled', false),
            );
        } catch (Throwable) {
            return false;
        }
    }

    private function provider(array|object $taskPacket): string
    {
        $provider = trim((string) ($this->packetValue($taskPacket, 'provider') ?? ''));

        return $provider !== '' ? $provider : 'default';
    }

    /** @return array<string,mixed> */
    private function routing(array|object $taskPacket): array
    {
        $packetRouting = $this->packetValue($taskPacket, 'provider_routing');
        if (is_array($packetRouting)) {
            return $packetRouting;
        }

        try {
            return (array) config('atlas.loop.provider_routing', []);
        } catch (Throwable) {
            return [];
        }
    }

    private function scopeModel(array|object $taskPacket): ?AtlasLoopScopeComprehensionModel
    {
        $value = $this->packetValue($taskPacket, 'comprehension_model')
            ?? $this->packetValue($taskPacket, 'scope_comprehension_model');
        if ($value instanceof AtlasLoopScopeComprehensionModel) {
            return $value;
        }
        if (is_array($value)) {
            return AtlasLoopScopeComprehensionModel::fromArray($value);
        }

        return null;
    }

    /**
     * @param  array<string,string>  $allowed
     * @param  array<string,string>  $scope
     * @param  array<string,bool>  $forbidden
     * @return list<string>
     */
    private function orderedCandidatePaths(array $allowed, array $scope, array $forbidden): array
    {
        $paths = [];
        foreach (array_merge(array_values($allowed), array_values($scope)) as $path) {
            if ($path !== '' && ! isset($forbidden[$path]) && ! in_array($path, $paths, true)) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * @param  array<string,mixed>|object  $taskPacket
     * @return array<string,bool>
     */
    private function symbolAnchors(array|object $taskPacket): array
    {
        $text = (string) ($this->packetValue($taskPacket, 'objective') ?? '');
        $originator = $this->packetValue($taskPacket, 'comprehension_originator')
            ?? $this->packetValue($taskPacket, 'origination');
        if (is_array($originator)) {
            $text .= ' '.(string) ($originator['objective'] ?? '');
            $text .= ' '.implode(' ', $this->stringList($originator['cited_symbols'] ?? []));
        }

        return array_fill_keys($this->tokens($text), true);
    }

    /** @param array<string,bool> $anchors */
    private function symbolOverlap(array $anchors, string $text): int
    {
        if ($anchors === []) {
            return 0;
        }

        $count = 0;
        foreach ($this->tokens($text) as $token) {
            if (isset($anchors[$token])) {
                $count++;
            }
        }

        return $count;
    }

    /** @return list<string> */
    private function tokens(string $text): array
    {
        preg_match_all('/[A-Za-z][A-Za-z0-9_]{2,}/', $text, $matches);
        $tokens = [];
        foreach ($matches[0] ?? [] as $token) {
            $token = strtolower((string) $token);
            foreach (preg_split('/(?=[A-Z])|[_\/\\\\\.\-]+/', (string) $token) ?: [] as $part) {
                $part = strtolower(trim($part));
                if (strlen($part) >= 3) {
                    $tokens[$part] = true;
                }
            }
            if (strlen($token) >= 3) {
                $tokens[$token] = true;
            }
        }

        return array_keys($tokens);
    }

    private function estimateTokens(string $text): int
    {
        return max(1, (int) ceil(mb_strlen($text) / 4));
    }

    private function fragmentId(string $kind, string $value): string
    {
        return substr(hash('sha256', $kind.'|'.$value), 0, 16);
    }

    /** @return array<string,string> */
    private function pathList(mixed $value): array
    {
        $paths = [];
        foreach ($this->stringList($value) as $path) {
            $path = $this->normalizePath($path);
            if ($path !== '') {
                $paths[$path] = $path;
            }
        }

        return $paths;
    }

    /** @return array<string,bool> */
    private function pathSet(mixed $value): array
    {
        return array_fill_keys(array_values($this->pathList($value)), true);
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $strings[] = trim($item);
            }
        }

        return array_values(array_unique($strings));
    }

    private function normalizePath(string $path): string
    {
        return ltrim(str_replace('\\', '/', trim($path)), '/');
    }

    private function packetValue(array|object $taskPacket, string $key): mixed
    {
        if (is_array($taskPacket)) {
            return $taskPacket[$key] ?? null;
        }

        return $taskPacket->{$key} ?? null;
    }
}
