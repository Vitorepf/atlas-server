<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Pipeline;

use App\Services\Ai\Programming\AtlasDev\Schemas\AtlasDevOperationEnvelope as OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\CodeDiscoveryManifest;

/**
 * Deterministic R0..R5 risk scorer.
 *
 * Pure heuristics on observable signals: task_kind, intent keywords, user
 * constraints, surface, discovery file count, missing refs, workspace state.
 * Never asks a model. R4/R5 escalate to forge preview — the orchestrator must
 * block any patch on those levels.
 *
 * Two-phase scoring: callers run `score()` once before discovery (preliminary)
 * and once after discovery (final, with file counts). Discovery raises risk
 * when the touch surface is wider than expected; it never lowers it.
 */
class RiskLevelScorer
{
    public const R0 = 'R0';

    public const R1 = 'R1';

    public const R2 = 'R2';

    public const R3 = 'R3';

    public const R4 = 'R4';

    public const R5 = 'R5';

    public const LEVELS = [self::R0, self::R1, self::R2, self::R3, self::R4, self::R5];

    private const RANK = [
        self::R0 => 0,
        self::R1 => 1,
        self::R2 => 2,
        self::R3 => 3,
        self::R4 => 4,
        self::R5 => 5,
    ];

    private const MULTIAGENT_TOKENS = [
        'multi-agent', 'multiagent', 'multi agent',
        'replay', 'audit', 'auditoria',
        'forge obra', 'obra ', 'long running', 'longa duracao', 'longa duração',
    ];

    private const TYPO_TOKENS = [
        'typo', 'fix typo', 'corrija typo', 'corrige typo',
        'docs ', 'documentation ', 'comentario', 'comentário', 'comment ',
        'reword', 'rephrase', 'reformule',
    ];

    private const LAYER_BUCKETS = [
        'db' => ['app/Models/', 'database/migrations/', '/Migrations/'],
        'api' => ['app/Http/Controllers/', 'routes/', '/Controllers/'],
        'ui' => ['atlas-desktop/', 'atlas-app/', 'resources/views/', 'resources/js/'],
        'service' => ['app/Services/'],
        'tests' => ['tests/'],
        'docs' => ['docs/', '.md'],
    ];

    public function score(
        OperationEnvelope $envelope,
        TaskClassification $classification,
        ?CodeDiscoveryManifest $discovery = null,
    ): string {
        $kind = $classification->taskKind;
        $haystack = $this->buildHaystack($envelope);

        if ($this->containsAny($haystack, self::MULTIAGENT_TOKENS) && $kind === TaskClassification::KIND_RISKY) {
            return self::R5;
        }

        if ($kind === TaskClassification::KIND_RISKY) {
            return self::R4;
        }

        if ($kind === TaskClassification::KIND_QUESTION) {
            return self::R0;
        }

        if ($kind === TaskClassification::KIND_REVIEW) {
            return self::R0;
        }

        if ($kind === TaskClassification::KIND_FRONTEND) {
            // Frontend defaults to R3 because visual gates apply and file
            // breadth is usually 2-5 files (component + style + test).
            $preliminary = self::R3;

            return $this->raiseWithDiscovery($preliminary, $envelope, $discovery, $haystack);
        }

        // patch / repair → file-count drives the level.
        if ($this->containsAny($haystack, self::TYPO_TOKENS)) {
            $preliminary = self::R1;
        } else {
            $preliminary = self::R2;
        }

        return $this->raiseWithDiscovery($preliminary, $envelope, $discovery, $haystack);
    }

    private function raiseWithDiscovery(
        string $preliminary,
        OperationEnvelope $envelope,
        ?CodeDiscoveryManifest $discovery,
        string $haystack,
    ): string {
        $level = $preliminary;
        if ($discovery !== null) {
            $explicitAllowedFiles = $this->explicitAllowedFiles($envelope);
            $likelyCount = $explicitAllowedFiles !== []
                ? count($explicitAllowedFiles)
                : count($discovery->likelyFiles);
            $layers = $explicitAllowedFiles !== []
                ? $this->countLayersFromPaths($explicitAllowedFiles)
                : $this->countLayers($discovery);

            if ($likelyCount >= 6 || $layers >= 3) {
                $level = $this->raise($level, self::R4);
            } elseif ($likelyCount >= 3) {
                $level = $this->raise($level, self::R3);
            }
        }

        // file count / layers raise — they never reduce.
        return $level;
    }

    private function countLayers(CodeDiscoveryManifest $discovery): int
    {
        foreach ($discovery->likelyFiles as $candidate) {
            $paths[] = $candidate->path;
        }

        return $this->countLayersFromPaths($paths ?? []);
    }

    /**
     * @param  list<string>  $paths
     */
    private function countLayersFromPaths(array $paths): int
    {
        $matched = [];
        foreach ($paths as $path) {
            $path = strtolower($path);
            foreach (self::LAYER_BUCKETS as $bucket => $needles) {
                foreach ($needles as $needle) {
                    if (str_contains($path, strtolower($needle))) {
                        $matched[$bucket] = true;
                        break;
                    }
                }
            }
        }

        // Layers we count as "real" risk: db, api, ui, service. Docs/tests
        // alone shouldn't trip the multi-layer rule.
        $real = ['db', 'api', 'ui', 'service'];

        return count(array_intersect_key($matched, array_flip($real)));
    }

    private function raise(string $current, string $candidate): string
    {
        return (self::RANK[$candidate] ?? 0) > (self::RANK[$current] ?? 0) ? $candidate : $current;
    }

    private function buildHaystack(OperationEnvelope $envelope): string
    {
        $parts = [$envelope->normalizedIntent];
        foreach ($envelope->userConstraints as $c) {
            if (is_string($c)) {
                $parts[] = $c;
            }
        }

        return strtolower(implode("\n", $parts));
    }

    /**
     * Explicit owner/runtime scope is authoritative for risk breadth. Discovery
     * may surface related files for context, but it must not promote a small
     * AP-759 owner task into Forge preview solely because nearby factory files
     * were found.
     *
     * @return list<string>
     */
    private function explicitAllowedFiles(OperationEnvelope $envelope): array
    {
        $files = [];
        foreach ($envelope->userConstraints as $constraint) {
            if (! is_string($constraint)) {
                continue;
            }
            $trimmed = trim($constraint);
            if (! preg_match('/\Aallowed_files?=(.+)\z/i', $trimmed, $matches)) {
                continue;
            }
            foreach (explode(',', $matches[1]) as $candidate) {
                $candidate = trim($candidate);
                if ($candidate !== '') {
                    $files[] = $candidate;
                }
            }
        }

        return array_values(array_unique($files));
    }

    /**
     * @param  list<string>  $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle === '') {
                continue;
            }
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
