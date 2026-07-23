<?php

declare(strict_types=1);

namespace App\Services\Ai\Obra;

use App\Models\AtlasMemoryEntry;
use App\Services\Ai\MemoryGovernance\AtlasMemoryPrivacyService;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Throwable;

/**
 * WO-17-T2 — the DETERMINISTIC brief (v1, NO LLM). "Lembra por quê e avisa antes"
 * at the module level, generated from facts that already exist in the repo + registry:
 *
 *   - hot_files / modules : churn from `git log --name-only` (what's moving now);
 *   - invariants          : memory_type=decision (the pétreas — "lembra por quê");
 *   - refutations         : refutation_memory (the "avisa antes" catalog);
 *   - head + generated_at : the freshness anchor.
 *
 * Persisted to storage/atlas/brief/<scope>.json. The pack reads it and, when the HEAD
 * has moved since generation, injects "BRIEF STALE desde X" instead of a silent stale
 * brief (the anti-morte-silenciosa rule: staleness is always visible). NO provider
 * spend, NO LLM — pure git + registry. Provider-safe: only the redacted projection of
 * decisions/refutations is emitted (the pack is provider-bound).
 */
final class AtlasDeterministicBriefService
{
    public const SCHEMA = 'atlas.obra.deterministic_brief.v1';

    private const CHURN_SINCE = '30 days ago';

    private string $dir;

    public function __construct(
        private readonly AtlasMemoryPrivacyService $privacy,
        ?string $dir = null,
    ) {
        $this->dir = rtrim($dir ?? storage_path('atlas/brief'), '/');
    }

    /**
     * Build + persist the brief for a scope (default: the whole repo).
     *
     * @return array<string,mixed>
     */
    public function generate(string $scope = 'atlas-server', ?string $repoPath = null): array
    {
        $scope = $this->sanitize($scope);
        $repoPath = rtrim($repoPath ?? base_path(), '/');
        $hotFiles = $this->hotFiles($repoPath);
        $brief = [
            'schema' => self::SCHEMA,
            'scope' => $scope,
            'head' => $this->gitHead($repoPath),
            'generated_at' => now()->toISOString(),
            'hot_files' => $hotFiles,
            'modules' => $this->modulesByChurn($hotFiles),
            'invariants' => $this->invariants(),
            'refutations' => $this->refutations(),
        ];

        try {
            if (! is_dir($this->dir)) {
                @mkdir($this->dir, 0775, true);
            }
            file_put_contents(
                $this->path($scope),
                json_encode($brief, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                LOCK_EX,
            );
        } catch (Throwable) {
            // fail-open — a write fault must not break brief generation for the caller.
        }

        return $brief;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function read(string $scope = 'atlas-server'): ?array
    {
        try {
            $path = $this->path($this->sanitize($scope));
            if (! is_file($path)) {
                return null;
            }
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Freshness: STALE the moment HEAD moves past the brief's generation head — never a
     * silent stale brief (the pack injects "BRIEF STALE desde X" from this).
     *
     * @param  array<string,mixed>  $brief
     * @return array{stale:bool, reason:string, generated_at:string, brief_head:string, current_head:string}
     */
    public function staleness(array $brief): array
    {
        $briefHead = trim((string) ($brief['head'] ?? ''));
        $current = $this->gitHead();
        $stale = $briefHead === '' || ($current !== '' && $briefHead !== $current);

        return [
            'stale' => $stale,
            'reason' => $stale ? ($briefHead === '' ? 'no_head_recorded' : 'head_moved') : 'fresh',
            'generated_at' => (string) ($brief['generated_at'] ?? ''),
            'brief_head' => $briefHead,
            'current_head' => $current,
        ];
    }

    public function path(string $scope): string
    {
        return $this->dir.'/'.$this->sanitize($scope).'.json';
    }

    /**
     * Top-churn files (last 30d) — what is moving in the repo right now.
     *
     * @return list<array{file:string, changes:int}>
     */
    private function hotFiles(?string $repoPath = null, int $limit = 15): array
    {
        try {
            $out = @shell_exec(
                'git -C '.escapeshellarg($repoPath ?? base_path())
                .' log --since='.escapeshellarg(self::CHURN_SINCE)
                .' --name-only --pretty=format: 2>/dev/null'
            );
            $counts = [];
            foreach (preg_split('/\R/', (string) $out) ?: [] as $line) {
                $line = trim($line);
                if ($line !== '' && $this->isProductSourceFile($line)) {
                    $counts[$line] = ($counts[$line] ?? 0) + 1;
                }
            }
            arsort($counts);

            return array_map(
                static fn (string $file, int $changes): array => ['file' => $file, 'changes' => $changes],
                array_keys(array_slice($counts, 0, $limit, true)),
                array_values(array_slice($counts, 0, $limit, true)),
            );
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * ADN — o churn é linguagem-agnóstico (a rede opera repos PHP, Swift, TS…):
     * arquivo de código de produto conta; testes/vendor/artefatos não. Antes o
     * filtro era `-- app` + `.php`, o que deixava todo repo não-Laravel com
     * brief estruturalmente vazio.
     */
    private function isProductSourceFile(string $path): bool
    {
        foreach (['tests/', 'vendor/', 'node_modules/', '.build/', 'build/', 'dist/'] as $excluded) {
            if (str_starts_with($path, $excluded) || str_contains($path, '/'.$excluded)) {
                return false;
            }
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, [
            'php', 'swift', 'ts', 'tsx', 'js', 'jsx', 'mjs', 'cjs',
            'py', 'go', 'rs', 'java', 'rb', 'kt', 'kts', 'scala', 'cpp', 'hpp', 'lua',
        ], true);
    }

    /**
     * @param  list<array{file:string, changes:int}>  $hotFiles
     * @return list<array{module:string, changes:int}>
     */
    private function modulesByChurn(array $hotFiles): array
    {
        $byModule = [];
        foreach ($hotFiles as $hot) {
            $parts = explode('/', (string) $hot['file']);
            $module = implode('/', array_slice($parts, 0, 4)); // app/Services/Ai/<X>
            $byModule[$module] = ($byModule[$module] ?? 0) + (int) $hot['changes'];
        }
        arsort($byModule);

        return array_map(
            static fn (string $module, int $changes): array => ['module' => $module, 'changes' => $changes],
            array_keys(array_slice($byModule, 0, 8, true)),
            array_values(array_slice($byModule, 0, 8, true)),
        );
    }

    /** @return list<string> the pétreas — provider-safe decision titles. */
    private function invariants(int $limit = 8): array
    {
        return $this->titlesOfType('decision', $limit);
    }

    /** @return list<string> the "avisa antes" catalog — provider-safe refutation titles. */
    private function refutations(int $limit = 8): array
    {
        return $this->titlesOfType('refutation_memory', $limit);
    }

    /** @return list<string> */
    private function titlesOfType(string $type, int $limit): array
    {
        if (! DatabaseTableAvailability::has('atlas_memory_entries')) {
            return [];
        }
        try {
            return AtlasMemoryEntry::query()
                ->where('memory_type', $type)
                ->where('status', 'active')
                ->orderByDesc('importance')
                ->orderByDesc('priority')
                ->limit($limit * 2)
                ->get()
                ->filter(fn (AtlasMemoryEntry $e): bool => $this->privacy->providerAllowed($e))
                ->map(fn (AtlasMemoryEntry $e): string => trim((string) $this->privacy->providerTitle($e)))
                ->filter(fn (string $t): bool => $t !== '')
                ->take($limit)
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    private function gitHead(?string $repoPath = null): string
    {
        try {
            return trim((string) @shell_exec('git -C '.escapeshellarg($repoPath ?? base_path()).' rev-parse --short HEAD 2>/dev/null'));
        } catch (Throwable) {
            return '';
        }
    }

    private function sanitize(string $scope): string
    {
        $scope = preg_replace('/[^A-Za-z0-9._-]/', '-', trim($scope)) ?? '';

        return $scope !== '' ? $scope : 'atlas-server';
    }
}
