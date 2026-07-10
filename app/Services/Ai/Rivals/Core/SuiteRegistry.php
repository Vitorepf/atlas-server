<?php

namespace App\Services\Ai\Rivals\Core;

use App\Services\Ai\Rivals\Adapters\AtlasBenchSuiteAdapter;
use App\Services\Ai\Rivals\Adapters\EliteRealitySuiteAdapter;
use App\Services\Ai\Rivals\Adapters\External\AiderBenchAdapter;
use App\Services\Ai\Rivals\Adapters\External\BfclAdapter;
use App\Services\Ai\Rivals\Adapters\External\HalHarnessAdapter;
use App\Services\Ai\Rivals\Adapters\External\HarborTerminalBenchAdapter;
use App\Services\Ai\Rivals\Adapters\External\InspectEvalsAdapter;
use App\Services\Ai\Rivals\Adapters\External\LiveCodeBenchAdapter;
use App\Services\Ai\Rivals\Adapters\External\SeniorSweBenchAdapter;
use App\Services\Ai\Rivals\Adapters\External\SweBenchLiveAdapter;
use App\Services\Ai\Rivals\Adapters\External\SweMarathonAdapter;
use App\Services\Ai\Rivals\Adapters\External\Tau2BenchAdapter;
use App\Services\Ai\Rivals\Adapters\LocalFakeSuiteAdapter;
use App\Services\Ai\Rivals\Contracts\BenchmarkSuiteAdapter;
use InvalidArgumentException;
use RuntimeException;

/**
 * Registry tipado: suite_id canônico = repo_id para as 10 suites externas.
 * Aliases legados (tau2_bfcl, harbor_terminal_bench) só resolvem leitura.
 */
final class SuiteRegistry
{
    /** @var array<string, class-string<BenchmarkSuiteAdapter>> */
    private const EXTERNAL_ADAPTERS = [
        'tau2_bench' => Tau2BenchAdapter::class,
        'bfcl' => BfclAdapter::class,
        'terminal_bench' => HarborTerminalBenchAdapter::class,
        'senior_swe_bench' => SeniorSweBenchAdapter::class,
        'swe_bench_live' => SweBenchLiveAdapter::class,
        'live_code_bench' => LiveCodeBenchAdapter::class,
        'inspect_evals' => InspectEvalsAdapter::class,
        'hal_harness' => HalHarnessAdapter::class,
        'aider_polyglot' => AiderBenchAdapter::class,
        'swe_marathon' => SweMarathonAdapter::class,
    ];

    /** @return list<string> */
    public function externalSuiteIds(): array
    {
        return array_keys(self::EXTERNAL_ADAPTERS);
    }

    /** @return array<string, string> */
    public function legacyAliases(): array
    {
        $fromConfig = (array) config('atlas_rivals.benchmarks.legacy_aliases', []);
        $defaults = [
            'tau2_bfcl' => 'tau2_bench',
            'harbor_terminal_bench' => 'terminal_bench',
        ];

        return $fromConfig !== [] ? $fromConfig : $defaults;
    }

    public function canonicalize(string $suiteOrRepoId, bool $allowLegacyAlias = true): string
    {
        $id = trim($suiteOrRepoId);
        if ($id === '') {
            throw new InvalidArgumentException('rivals_empty_suite_id');
        }
        if (isset(self::EXTERNAL_ADAPTERS[$id])
            || in_array($id, [
                LocalFakeSuiteAdapter::SUITE_ID,
                AtlasBenchSuiteAdapter::SUITE_ID,
                EliteRealitySuiteAdapter::SUITE_ID,
            ], true)) {
            return $id;
        }
        $aliases = $this->legacyAliases();
        if ($allowLegacyAlias && isset($aliases[$id])) {
            return $aliases[$id];
        }
        throw new InvalidArgumentException("unknown_suite:{$id}");
    }

    public function isLegacyAlias(string $suiteId): bool
    {
        return isset($this->legacyAliases()[$suiteId]);
    }

    public function suiteTier(string $suiteOrRepoId): string
    {
        $suiteId = $this->canonicalize($suiteOrRepoId);

        return match ($suiteId) {
            LocalFakeSuiteAdapter::SUITE_ID => ClaimTier::HARNESS,
            AtlasBenchSuiteAdapter::SUITE_ID,
            EliteRealitySuiteAdapter::SUITE_ID => ClaimTier::DIAGNOSTIC,
            default => ClaimTier::PRODUCTION,
        };
    }

    public function adapterFor(string $suiteOrRepoId, bool $allowLegacyAlias = true): BenchmarkSuiteAdapter
    {
        $suiteId = $this->canonicalize($suiteOrRepoId, $allowLegacyAlias);

        return match ($suiteId) {
            LocalFakeSuiteAdapter::SUITE_ID => new LocalFakeSuiteAdapter,
            AtlasBenchSuiteAdapter::SUITE_ID => new AtlasBenchSuiteAdapter,
            EliteRealitySuiteAdapter::SUITE_ID => new EliteRealitySuiteAdapter,
            default => $this->makeExternal($suiteId),
        };
    }

    /** @return array<int, array<string, mixed>> */
    public function catalog(): array
    {
        $repos = (array) config('atlas_rivals.benchmarks.repos', []);
        $rows = [];
        foreach ($this->externalSuiteIds() as $suiteId) {
            $spec = $repos[$suiteId] ?? null;
            if (! is_array($spec)) {
                throw new RuntimeException("registry_missing_repo:{$suiteId}");
            }
            $configuredAdapter = (string) ($spec['adapter'] ?? $suiteId);
            $class = self::EXTERNAL_ADAPTERS[$suiteId];
            $instance = new $class;
            if ($instance->suiteId() !== $suiteId) {
                throw new RuntimeException("adapter_suite_mismatch:{$suiteId}:{$instance->suiteId()}");
            }
            if ($configuredAdapter !== $suiteId && ! isset($this->legacyAliases()[$configuredAdapter])) {
                throw new RuntimeException("registry_adapter_key_mismatch:{$suiteId}:{$configuredAdapter}");
            }
            $rows[] = [
                'repo_id' => $suiteId,
                'suite_id' => $suiteId,
                'adapter' => $configuredAdapter,
                'adapter_class' => $class,
                'adapter_resolves' => true,
                'native_agent_default' => $spec['native_agent_default'] ?? $suiteId,
                'url' => $spec['url'] ?? null,
                'website' => $spec['website'] ?? null,
            ];
        }

        return $rows;
    }

    public function assertComplete(): void
    {
        $repos = (array) config('atlas_rivals.benchmarks.repos', []);
        if (count($repos) !== 10) {
            throw new RuntimeException('registry_repo_count_mismatch:'.count($repos));
        }
        foreach ($this->externalSuiteIds() as $suiteId) {
            if (! isset($repos[$suiteId])) {
                throw new RuntimeException("registry_missing_repo:{$suiteId}");
            }
            $this->adapterFor($suiteId, allowLegacyAlias: false);
        }
        foreach (array_keys($repos) as $repoId) {
            if (! isset(self::EXTERNAL_ADAPTERS[$repoId])) {
                throw new RuntimeException("registry_unknown_repo:{$repoId}");
            }
        }
    }

    private function makeExternal(string $suiteId): BenchmarkSuiteAdapter
    {
        $class = self::EXTERNAL_ADAPTERS[$suiteId] ?? null;
        if ($class === null) {
            throw new InvalidArgumentException("unknown_suite:{$suiteId}");
        }

        return new $class;
    }
}
