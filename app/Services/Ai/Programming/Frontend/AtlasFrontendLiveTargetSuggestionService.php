<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use SplFileInfo;

final class AtlasFrontendLiveTargetSuggestionService
{
    public const SCHEMA_VERSION = 'atlas.frontend.live_target_suggestions.v1';

    /** @var array<int,string> */
    private const EXTENSIONS = ['tsx', 'jsx', 'ts', 'js', 'vue', 'svelte', 'astro', 'html', 'css'];

    /** @var array<int,string> */
    private const SKIP_DIRS = ['.git', '.atlas', 'node_modules', 'vendor', 'dist', 'build', '.next', '.nuxt', 'coverage'];

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function suggest(array $input): array
    {
        $workspace = rtrim((string) ($input['workspace'] ?? ''), DIRECTORY_SEPARATOR);
        $workspaceReal = realpath($workspace);
        if ($workspaceReal === false || ! File::isDirectory($workspaceReal)) {
            throw new RuntimeException('workspace_not_found');
        }

        $selection = is_array($input['visual_selection'] ?? null) ? (array) $input['visual_selection'] : [];
        $signals = $this->signals($selection, (string) ($input['file_hint'] ?? ''));
        $maxCandidates = max(1, min(20, (int) ($input['max_candidates'] ?? 5)));
        $candidates = [];

        foreach ($this->candidateFiles($workspaceReal) as $file) {
            $candidate = $this->scoreFile($workspaceReal, $file, $signals);
            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        usort($candidates, static function (array $left, array $right): int {
            return [$right['score'], $left['file']] <=> [$left['score'], $right['file']];
        });
        $candidates = array_slice($candidates, 0, $maxCandidates);

        $report = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => count($candidates) > 0 ? 'suggested' : 'no_candidate',
            'workspace_hash' => hash('sha256', $workspaceReal),
            'session' => trim((string) ($input['session'] ?? 'atlas-live-session')) ?: 'atlas-live-session',
            'candidate_count' => count($candidates),
            'candidates' => $candidates,
            'input_signals' => [
                'has_raw_component_hint' => $signals['component_hint'] !== '',
                'has_raw_selector' => $signals['selector'] !== '',
                'has_raw_text_excerpt' => $signals['text_excerpt'] !== '',
                'has_component_hint_hash' => $signals['component_hint_hash'] !== '',
                'has_selector_hash' => $signals['selector_hash'] !== '',
                'has_text_excerpt_hash' => $signals['text_excerpt_hash'] !== '',
                'has_file_hint' => $signals['file_hint'] !== '',
            ],
            'policy' => [
                'operator_local_target_snippet_returned' => true,
                'target_snippet_is_not_provider_safe' => true,
                'provider_dispatch_allowed' => false,
                'raw_customer_source_returned_to_provider' => false,
                'absolute_path_returned' => false,
                'suggestion_is_not_delivery_evidence' => true,
                'visual_selection_is_not_visual_quality_proof' => true,
                'selected_repository_is_primary_workspace' => true,
                'frontend_app_is_subscope_only' => true,
                'space_runtime_required' => false,
                'world_best_claim_allowed' => false,
            ],
            'warnings' => count($candidates) > 0
                ? ['operator_must_confirm_snippet_before_prepare_live_patch']
                : ['no_matching_local_target_found'],
        ];
        $report['suggestions_hash'] = MissionCanonicalHash::sha256($this->hashableReport($report));

        return $report;
    }

    /**
     * @param  array<string,mixed>  $selection
     * @return array<string,string>
     */
    private function signals(array $selection, string $fileHint): array
    {
        return [
            'file_hint' => trim($fileHint),
            'component_hint' => trim((string) ($selection['component_hint'] ?? '')),
            'component_hint_hash' => $this->hashSignal($selection['component_hint_hash'] ?? null),
            'selector' => trim((string) ($selection['selector'] ?? '')),
            'selector_hash' => $this->hashSignal($selection['selector_hash'] ?? null),
            'text_excerpt' => trim((string) ($selection['text_excerpt'] ?? '')),
            'text_excerpt_hash' => $this->hashSignal($selection['text_excerpt_hash'] ?? null),
        ];
    }

    /**
     * @return array<int,SplFileInfo>
     */
    private function candidateFiles(string $workspaceReal): array
    {
        $files = [];
        foreach (File::allFiles($workspaceReal) as $file) {
            if (count($files) >= 500) {
                break;
            }
            if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                continue;
            }
            if (! in_array(strtolower($file->getExtension()), self::EXTENSIONS, true)) {
                continue;
            }
            if ($file->getSize() > 500_000) {
                continue;
            }
            $relative = $this->relativePath($workspaceReal, $file->getRealPath() ?: $file->getPathname());
            if ($this->shouldSkip($relative)) {
                continue;
            }
            $files[] = $file;
        }

        return $files;
    }

    /**
     * @param  array<string,string>  $signals
     * @return array<string,mixed>|null
     */
    private function scoreFile(string $workspaceReal, SplFileInfo $file, array $signals): ?array
    {
        $real = $file->getRealPath() ?: $file->getPathname();
        $relative = $this->relativePath($workspaceReal, $real);
        $content = File::get($real);
        $score = 0;
        $matchedSignals = [];

        if ($signals['file_hint'] !== '' && (Str::contains($relative, $signals['file_hint']) || Str::contains($signals['file_hint'], $relative))) {
            $score += 25;
            $matchedSignals[] = 'file_hint';
        }
        if ($signals['component_hint'] !== '' && (Str::contains($relative, $signals['component_hint']) || Str::contains($content, $signals['component_hint']))) {
            $score += 40;
            $matchedSignals[] = 'component_hint';
        }
        if ($signals['selector'] !== '' && Str::contains($content, $signals['selector'])) {
            $score += 35;
            $matchedSignals[] = 'selector';
        }
        if ($signals['text_excerpt'] !== '' && Str::contains($content, $signals['text_excerpt'])) {
            $score += 45;
            $matchedSignals[] = 'text_excerpt';
        }

        [$hashScore, $hashSignals] = $this->hashScore($relative, $content, $signals);
        $score += $hashScore;
        $matchedSignals = array_values(array_unique(array_merge($matchedSignals, $hashSignals)));

        if ($score <= 0) {
            return null;
        }

        [$line, $snippet] = $this->targetSnippet($content, $signals, $matchedSignals);
        $occurrences = $snippet === '' ? 0 : substr_count($content, $snippet);

        return [
            'file' => $relative,
            'file_hash' => hash('sha256', $relative),
            'line' => $line,
            'target_snippet' => $snippet,
            'target_hash' => hash('sha256', $snippet),
            'target_occurrence_count' => $occurrences,
            'can_prepare_directly' => $occurrences === 1,
            'score' => $score,
            'signals' => $matchedSignals,
            'operator_local' => true,
            'target_snippet_is_not_provider_safe' => true,
        ];
    }

    /**
     * @param  array<string,string>  $signals
     * @return array{0:int,1:array<int,string>}
     */
    private function hashScore(string $relative, string $content, array $signals): array
    {
        $score = 0;
        $matched = [];
        $identifierHashes = [];
        preg_match_all('/[A-Za-z_][A-Za-z0-9_]{2,80}/', $relative."\n".$content, $identifiers);
        foreach ($identifiers[0] ?? [] as $identifier) {
            $identifierHashes[hash('sha256', $identifier)] = true;
        }

        if ($signals['component_hint_hash'] !== '' && isset($identifierHashes[$signals['component_hint_hash']])) {
            $score += 32;
            $matched[] = 'component_hint_hash';
        }

        $literalHashes = [];
        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            $trimmed = trim((string) $line);
            if ($trimmed !== '') {
                $literalHashes[hash('sha256', Str::limit($trimmed, 500, ''))] = true;
            }
            preg_match_all('/["\']([^"\']{1,240})["\']/', (string) $line, $literals);
            foreach ($literals[1] ?? [] as $literal) {
                $literalHashes[hash('sha256', trim((string) $literal))] = true;
            }
        }

        if ($signals['selector_hash'] !== '' && isset($literalHashes[$signals['selector_hash']])) {
            $score += 28;
            $matched[] = 'selector_hash';
        }
        if ($signals['text_excerpt_hash'] !== '' && isset($literalHashes[$signals['text_excerpt_hash']])) {
            $score += 36;
            $matched[] = 'text_excerpt_hash';
        }

        return [$score, $matched];
    }

    /**
     * @param  array<string,string>  $signals
     * @param  array<int,string>  $matchedSignals
     * @return array{0:int,1:string}
     */
    private function targetSnippet(string $content, array $signals, array $matchedSignals): array
    {
        $lines = preg_split('/\R/', $content) ?: [];
        foreach (['text_excerpt', 'selector', 'component_hint'] as $signal) {
            if ($signals[$signal] === '') {
                continue;
            }
            foreach ($lines as $index => $line) {
                if (Str::contains((string) $line, $signals[$signal])) {
                    return [$index + 1, Str::limit(trim((string) $line), 1000, '')];
                }
            }
        }

        if (in_array('component_hint_hash', $matchedSignals, true) && $signals['component_hint_hash'] !== '') {
            foreach ($lines as $index => $line) {
                preg_match_all('/[A-Za-z_][A-Za-z0-9_]{2,80}/', (string) $line, $identifiers);
                foreach ($identifiers[0] ?? [] as $identifier) {
                    if (hash('sha256', $identifier) === $signals['component_hint_hash']) {
                        return [$index + 1, Str::limit(trim((string) $line), 1000, '')];
                    }
                }
            }
        }

        foreach ($lines as $index => $line) {
            $trimmed = trim((string) $line);
            if ($trimmed !== '') {
                return [$index + 1, Str::limit($trimmed, 1000, '')];
            }
        }

        return [0, ''];
    }

    private function relativePath(string $workspaceReal, string $realPath): string
    {
        return ltrim(str_replace('\\', '/', Str::after($realPath, rtrim($workspaceReal, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)), '/');
    }

    private function shouldSkip(string $relative): bool
    {
        $segments = explode('/', $relative);
        foreach ($segments as $segment) {
            if (in_array($segment, self::SKIP_DIRS, true)) {
                return true;
            }
        }

        return false;
    }

    private function hashSignal(mixed $value): string
    {
        $hash = trim((string) $value);

        return preg_match('/\A[a-f0-9]{64}\z/', $hash) === 1 ? $hash : '';
    }

    /**
     * @param  array<string,mixed>  $report
     * @return array<string,mixed>
     */
    private function hashableReport(array $report): array
    {
        unset($report['suggestions_hash']);
        foreach ($report['candidates'] ?? [] as $index => $candidate) {
            if (is_array($candidate)) {
                $report['candidates'][$index]['target_snippet'] = hash('sha256', (string) ($candidate['target_snippet'] ?? ''));
            }
        }

        return $report;
    }
}
