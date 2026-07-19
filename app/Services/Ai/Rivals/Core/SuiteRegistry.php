<?php

namespace App\Services\Ai\Rivals\Core;

use App\Services\Ai\Rivals\Adapters\AtlasBenchSuiteAdapter;
use App\Services\Ai\Rivals\Adapters\EliteRealitySuiteAdapter;
use App\Services\Ai\Rivals\Adapters\External\AiderBenchAdapter;
use App\Services\Ai\Rivals\Adapters\External\BfclAdapter;
use App\Services\Ai\Rivals\Adapters\External\EngineeringNativeSuiteAdapter;
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
    /** @var list<string> */
    private const FASE_A_SUITE_IDS = [
        'tau2_bench',
        'bfcl',
        'terminal_bench',
        'senior_swe_bench',
        'swe_bench_live',
        'live_code_bench',
        'inspect_evals',
        'hal_harness',
        'aider_polyglot',
        'swe_marathon',
    ];

    /** @var list<string> */
    private const ENGINEERING_NATIVE_SUITE_IDS = [
        'bfcl',
        'live_code_bench',
        'aider_polyglot',
        'archbench',
        'cruxeval',
        'classeval',
        'repobench',
        'locagent',
        'debug_gym',
        'testeval',
        'evalplus',
        'crosscodeeval',
        'bigcodebench',
        'deveval',
        'long_code_arena',
        'reval',
    ];

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
        'archbench' => EngineeringNativeSuiteAdapter::class,
        'cruxeval' => EngineeringNativeSuiteAdapter::class,
        'classeval' => EngineeringNativeSuiteAdapter::class,
        'repobench' => EngineeringNativeSuiteAdapter::class,
        'locagent' => EngineeringNativeSuiteAdapter::class,
        'debug_gym' => EngineeringNativeSuiteAdapter::class,
        'testeval' => EngineeringNativeSuiteAdapter::class,
        'evalplus' => EngineeringNativeSuiteAdapter::class,
        'crosscodeeval' => EngineeringNativeSuiteAdapter::class,
        'bigcodebench' => EngineeringNativeSuiteAdapter::class,
        'deveval' => EngineeringNativeSuiteAdapter::class,
        'long_code_arena' => EngineeringNativeSuiteAdapter::class,
        'reval' => EngineeringNativeSuiteAdapter::class,
    ];

    /** @return list<string> */
    public function externalSuiteIds(): array
    {
        return $this->profileSuiteIds('fase_a');
    }

    /** @return list<string> */
    public function registeredSuiteIds(): array
    {
        return array_keys(self::EXTERNAL_ADAPTERS);
    }

    /** @return list<string> */
    public function profileSuiteIds(string $profile): array
    {
        $configured = config("atlas_rivals.profiles.{$profile}.suite_ids");
        $suiteIds = is_array($configured)
            ? array_values(array_map('strval', $configured))
            : match ($profile) {
                'fase_a' => self::FASE_A_SUITE_IDS,
                'engineering_native' => self::ENGINEERING_NATIVE_SUITE_IDS,
                default => throw new InvalidArgumentException("unknown_rivals_profile:{$profile}"),
            };
        if ($suiteIds === [] || count($suiteIds) !== count(array_unique($suiteIds))) {
            throw new RuntimeException("invalid_rivals_profile_suite_ids:{$profile}");
        }
        foreach ($suiteIds as $suiteId) {
            if (! isset(self::EXTERNAL_ADAPTERS[$suiteId])) {
                throw new RuntimeException("rivals_profile_unknown_suite:{$profile}:{$suiteId}");
            }
        }

        return $suiteIds;
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
    public function catalog(string $profile = 'fase_a'): array
    {
        $repos = (array) config('atlas_rivals.benchmarks.repos', []);
        $rows = [];
        foreach ($this->profileSuiteIds($profile) as $suiteId) {
            $spec = $repos[$suiteId] ?? null;
            if (! is_array($spec)) {
                throw new RuntimeException("registry_missing_repo:{$suiteId}");
            }
            $configuredAdapter = (string) ($spec['adapter'] ?? $suiteId);
            $class = self::EXTERNAL_ADAPTERS[$suiteId];
            $instance = $this->makeExternal($suiteId);
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
        if (count($repos) !== count(self::EXTERNAL_ADAPTERS)) {
            throw new RuntimeException('registry_repo_count_mismatch:'.count($repos));
        }
        foreach ($this->registeredSuiteIds() as $suiteId) {
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

        return $class === EngineeringNativeSuiteAdapter::class
            ? new EngineeringNativeSuiteAdapter($suiteId)
            : new $class;
    }
}
