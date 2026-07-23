<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Provider;

use App\Models\AiJob;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiProviderHealthCheck;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\AiProviderResult;
use App\Services\Ai\Policy\AtlasAiRuntimeSettings;
use App\Services\Ai\AtlasDecide\AtlasDecideGatewayConsultationService;
use App\Services\Ai\AtlasDecide\AtlasDecideLiveOutcomeFeedbackService;
use App\Services\Ai\AtlasDecide\AtlasDecideMetaLearningService;
use App\Services\Ai\AtlasDecide\AtlasSwarmExecutorService;
use App\Services\Ai\Caching\AiCallCostGuard;
use App\Services\Ai\Caching\CachingAiProvider;
use App\Services\Ai\Caching\EfficiencyOutcomeRecorder;
use App\Services\Ai\Governance\AtlasAutonomyAdmissionService;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use App\Services\Ai\Governance\AtlasTrustBudgetService;
use App\Services\Ai\Governance\ProviderGovernanceConsult;
use App\Services\Ai\Governance\ProviderGovernanceCoverageLedger;
use App\Services\Ai\Programming\AtlasForgeBaseCliInvocationDriver;
use App\Services\Ai\Programming\AtlasForgeProviderCommandAllowlistService;
use App\Services\Ai\Programming\AtlasForgeProviderInvocationFailureClassifier;
use App\Services\Ai\Programming\AtlasForgeProviderProcessRunner;
use App\Services\Ai\Provider\Drivers\ProviderDriverRegistry;
use App\Services\Ai\Telemetry\AiCostEstimator;
use App\Services\Ai\Tokens\AtlasTokenEconomyBudgetPolicyService;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class ProviderPipeUnificationF0CharacterizationTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            File::deleteDirectory($directory);
        }

        parent::tearDown();
    }

    public function test_manager_and_compliance_registry_document_the_current_catalog_drift(): void
    {
        $managerKeys = app(AiProviderManager::class)->keys();
        $registryKeys = app(ProviderDriverRegistry::class)->providerIds();

        self::assertSame(
            ['claude_cli', 'codex_cli', 'gemini_cli', 'jarvis_mlx', 'hermes_cli', 'minimax_m27_cli'],
            $managerKeys,
        );
        self::assertSame(
            ['claude_cli', 'codex_cli', 'gemini_cli', 'claude_codex', 'jarvis_mlx', 'hermes_cli'],
            $registryKeys,
        );
        self::assertSame(['minimax_m27_cli'], array_values(array_diff($managerKeys, $registryKeys)));
        self::assertSame(['claude_codex'], array_values(array_diff($registryKeys, $managerKeys)));
    }

    public function test_manager_get_get_recommended_and_keys_execute_without_provider_calls(): void
    {
        $ledger = new ProviderGovernanceCoverageLedger;
        $ledger->setLogPathForTesting($this->temporaryPath('manager.jsonl'));
        $manager = app(AiProviderManager::class);
        $manager->setCoverageLedger($ledger);

        $resolved = $manager->get('codex_cli');
        $recommended = $manager->getRecommended('provider_pipe_f0', 'characterization');
        $summary = $ledger->summary();

        self::assertInstanceOf(AiProvider::class, $resolved);
        self::assertInstanceOf(AiProvider::class, $recommended['provider']);
        self::assertContains($recommended['key'], $manager->keys());
        self::assertSame(2, $summary['total']);
        self::assertSame(2, $summary['covered']);
        self::assertSame(0, $summary['consulted']);
        self::assertSame(0, $summary['bypass']);
        self::assertSame(1, $summary['by_surface'][ProviderGovernanceCoverageLedger::SURFACE_MANAGER]['covered']);
        self::assertSame(1, $summary['by_surface'][ProviderGovernanceCoverageLedger::SURFACE_RECOMMENDATION]['covered']);
    }

    public function test_manager_recommendation_falls_back_after_the_advisory_verdict_and_only_decorates_when_wired(): void
    {
        config([
            'atlas.ai.cache.enabled' => false,
            'atlas.ai.cache.cost_guard' => ['soft_units' => 0.0, 'hard_units' => 0.0],
        ]);

        $inner = new class implements AiProvider
        {
            public function key(): string
            {
                return 'provider_pipe_probe';
            }

            public function run(AiJob $job, string $prompt): AiProviderResult
            {
                throw new \LogicException('F0 must not run a provider.');
            }

            public function runStreaming(AiJob $job, string $prompt, ?callable $onEvent = null): AiProviderResult
            {
                throw new \LogicException('F0 must not run a provider.');
            }

            public function health(): AiProviderHealthCheck
            {
                return new AiProviderHealthCheck($this->key(), 'ok', 'safe fake');
            }
        };
        config(['atlas.ai.default_provider' => $inner->key()]);
        $this->app->instance(AtlasAiRuntimeSettings::class, new class($inner->key()) extends AtlasAiRuntimeSettings
        {
            public function __construct(private readonly string $configuredDefault) {}

            public function defaultProvider(): string
            {
                return $this->configuredDefault;
            }
        });
        $manager = app(AiProviderManager::class);
        $manager->registerDriver($inner->key(), $inner);

        $fallback = $manager->getRecommended('provider_pipe_f0', 'characterization');
        self::assertSame($inner->key(), app(AtlasAiRuntimeSettings::class)->defaultProvider());
        self::assertSame($inner->key(), $fallback['key']);
        self::assertSame($inner, $fallback['provider']);
        self::assertSame('free_to_choose', $fallback['verdict']);
        self::assertTrue($fallback['consulted']);
        self::assertIsArray($fallback['consultation']);
        self::assertSame($inner, $manager->get($inner->key()));

        config(['atlas.ai.cache.enabled' => true]);
        $manager->setCacheDecoration(
            app(AiCallCostGuard::class),
            new class implements EfficiencyOutcomeRecorder
            {
                /** @return array<string,mixed> */
                public function recordOutcome(array $input): array
                {
                    throw new \LogicException('F0 does not run a decorated provider.');
                }
            },
            app(AiCostEstimator::class),
            app(AtlasTokenEconomyBudgetPolicyService::class),
        );
        self::assertInstanceOf(CachingAiProvider::class, $manager->get($inner->key()));
    }

    public function test_registry_manifest_and_compliance_report_are_the_fusion_oracle(): void
    {
        $registry = app(ProviderDriverRegistry::class);
        $manifest = $registry->manifest();
        $report = $registry->complianceReport();

        self::assertSame(
            [
                [
                    'provider_id' => 'claude_cli',
                    'driver_class' => 'App\\Services\\Ai\\Provider\\Drivers\\ClaudeCliProviderDriver',
                    'supported_models' => ['claude_cli_default'],
                    'identity_fragment_id' => 'atlas-ai.provider.claude_cli.identity.v1',
                    'identity_fragment_hash' => 'c50928109d2d38319dd7d4789d88efeb660a6e8519cc6388bc86dc484272a4a2',
                    'legacy_provider' => 'App\\Services\\Ai\\ClaudeCliProvider',
                    'execution_policy_mode' => 'prepare_only',
                    'request_hash_algorithm' => 'sha256',
                    'request_hash_canonicalization' => 'provider_prepared_request.v1',
                    'dry_run_supported' => true,
                    'real_execution_enabled' => false,
                    'validation' => ['ok' => true, 'errors' => [], 'warnings' => []],
                ],
                [
                    'provider_id' => 'codex_cli',
                    'driver_class' => 'App\\Services\\Ai\\Provider\\Drivers\\CodexCliProviderDriver',
                    'supported_models' => ['codex_cli_default'],
                    'identity_fragment_id' => 'atlas-ai.provider.codex_cli.identity.v1',
                    'identity_fragment_hash' => '0e302caeeca7eaa27d2c9d844be8cdc11fe2af31aeed583a8f39d3466510d1c3',
                    'legacy_provider' => 'App\\Services\\Ai\\CodexCliProvider',
                    'execution_policy_mode' => 'prepare_only',
                    'request_hash_algorithm' => 'sha256',
                    'request_hash_canonicalization' => 'provider_prepared_request.v1',
                    'dry_run_supported' => true,
                    'real_execution_enabled' => false,
                    'validation' => ['ok' => true, 'errors' => [], 'warnings' => []],
                ],
                [
                    'provider_id' => 'gemini_cli',
                    'driver_class' => 'App\\Services\\Ai\\Provider\\Drivers\\GeminiCliProviderDriver',
                    'supported_models' => ['gemini-3.5-flash', 'gemini-3.1-pro-preview'],
                    'identity_fragment_id' => 'atlas-ai.provider.gemini_cli.identity.v1',
                    'identity_fragment_hash' => '25e5aa91367a7c0455aa037039f4749c058c5f07dda5b1ed5fdf05b2a6b0d51b',
                    'legacy_provider' => 'App\\Services\\Ai\\GeminiCliProvider',
                    'execution_policy_mode' => 'prepare_only',
                    'request_hash_algorithm' => 'sha256',
                    'request_hash_canonicalization' => 'provider_prepared_request.v1',
                    'dry_run_supported' => true,
                    'real_execution_enabled' => false,
                    'validation' => ['ok' => true, 'errors' => [], 'warnings' => []],
                ],
                [
                    'provider_id' => 'claude_codex',
                    'driver_class' => 'App\\Services\\Ai\\Provider\\Drivers\\ClaudeCodexCouncilProviderDriver',
                    'supported_models' => ['council_default', 'selected-by-decide'],
                    'identity_fragment_id' => 'atlas-ai.provider.claude_codex.identity.v1',
                    'identity_fragment_hash' => '4778952d6c52f670e0ad00d4cad9c2071371429b0dbaba92f7bd3cd552f9e659',
                    'legacy_provider' => 'App\\Services\\Ai\\Arena\\AiCouncilCoordinator',
                    'execution_policy_mode' => 'prepare_only',
                    'request_hash_algorithm' => 'sha256',
                    'request_hash_canonicalization' => 'provider_prepared_request.v1',
                    'dry_run_supported' => true,
                    'real_execution_enabled' => false,
                    'validation' => ['ok' => true, 'errors' => [], 'warnings' => []],
                ],
                [
                    'provider_id' => 'jarvis_mlx',
                    'driver_class' => 'App\\Services\\Ai\\Provider\\Drivers\\JarvisMlxProviderDriver',
                    'supported_models' => ['jarvis_mlx_default', 'llama3_mlx_4bit', 'phi3_mlx_8bit'],
                    'identity_fragment_id' => 'atlas-ai.provider.jarvis_mlx.identity.v1',
                    'identity_fragment_hash' => '5b351b283a0ef1caedd9f146c99a36605bde262ab4d3837f8587e8a2fdd6b228',
                    'legacy_provider' => 'App\\Services\\Ai\\JarvisMlxProvider',
                    'execution_policy_mode' => 'prepare_only',
                    'request_hash_algorithm' => 'sha256',
                    'request_hash_canonicalization' => 'provider_prepared_request.v1',
                    'dry_run_supported' => true,
                    'real_execution_enabled' => false,
                    'validation' => ['ok' => true, 'errors' => [], 'warnings' => []],
                ],
                [
                    'provider_id' => 'hermes_cli',
                    'driver_class' => 'App\\Services\\Ai\\Provider\\Drivers\\HermesCliProviderDriver',
                    'supported_models' => ['hermes_cli_default', 'hermes_selected_by_atlas_decide', 'qwen3.6-27b', 'kimi-k2.7'],
                    'identity_fragment_id' => 'atlas-ai.provider.hermes_cli.identity.v1',
                    'identity_fragment_hash' => '1c2958e50428ab3f7b482e222f2e57c85566a5b0484e36a19076b96b8dde2f0d',
                    'legacy_provider' => 'App\\Services\\Ai\\HermesCliProvider',
                    'execution_policy_mode' => 'prepare_only',
                    'request_hash_algorithm' => 'sha256',
                    'request_hash_canonicalization' => 'provider_prepared_request.v1',
                    'dry_run_supported' => true,
                    'real_execution_enabled' => false,
                    'validation' => ['ok' => true, 'errors' => [], 'warnings' => []],
                ],
            ],
            $manifest,
        );
        self::assertSame([
            'ok' => true,
            'count' => 6,
            'providers' => ['claude_cli', 'codex_cli', 'gemini_cli', 'claude_codex', 'jarvis_mlx', 'hermes_cli'],
            'errors' => [],
            'warnings' => [
                'claude_cli identityFragment is using fallback master identity projection',
                'codex_cli identityFragment is using fallback master identity projection',
                'gemini_cli identityFragment is using fallback master identity projection',
                'claude_codex identityFragment is using fallback master identity projection',
                'jarvis_mlx identityFragment is using fallback master identity projection',
                'hermes_cli identityFragment is using fallback master identity projection',
            ],
        ], $report);
    }

    public function test_swarm_stub_matches_the_full_normalized_execution_envelope_but_has_no_clock_seam(): void
    {
        $suffix = uniqid('', true);
        $kernel = new AtlasConstitutionalKernelService;
        $kernel->setViolationsLogPathForTesting($this->temporaryPath("kernel-{$suffix}.jsonl"));
        $feedback = new AtlasDecideLiveOutcomeFeedbackService;
        $feedback->setLogPathForTesting($this->temporaryPath("feedback-{$suffix}.jsonl"));
        $executor = new AtlasSwarmExecutorService($kernel, $feedback);
        $executor->setLogPathForTesting($this->temporaryPath("swarm-{$suffix}.jsonl"));
        $executor->setResolver(static fn (array $arm): array => [
            'result' => 'success',
            'latency_ms' => 100 + (int) $arm['rank'],
            'quality_score' => 1.0 - ((int) $arm['rank'] / 10),
            'output' => 'provider-pipe-f0-'.$arm['provider'],
        ]);

        $envelope = $executor->execute([
            'dispatch_id' => 'provider-pipe-f0',
            'arms' => [
                ['arm_id' => 'primary', 'rank' => 1, 'origin' => 'recommended', 'provider' => 'claude_cli', 'model' => 'opus'],
                ['arm_id' => 'fallback', 'rank' => 2, 'origin' => 'runner_up', 'provider' => 'codex_cli', 'model' => 'gpt'],
            ],
        ], ['task_category' => 'provider_pipe_f0', 'role' => 'characterization']);

        $source = (string) file_get_contents(app_path('Services/Ai/AtlasDecide/AtlasSwarmExecutorService.php'));
        self::assertStringContainsString(
            "new DateTimeImmutable('now', new DateTimeZone('UTC'))",
            $source,
            'F0 contradiction: the Swarm has no testable clock seam, so literal byte-identical time cannot be proven in test-only scope.',
        );

        self::assertSame(
            'sha256:'.hash('sha256', json_encode([
                'schema' => AtlasSwarmExecutorService::ENVELOPE_SCHEMA,
                'started_at' => $envelope['started_at'],
                'dispatch_id' => $envelope['dispatch_id'],
                'winner_arm_id' => data_get($envelope, 'winner.arm_id'),
                'arm_count' => $envelope['arm_count'],
            ], JSON_THROW_ON_ERROR)),
            $envelope['execution_hash'],
            'F0 first proves the live envelope hash with the production formula before normalizing the clock-only field in a copy.',
        );

        $normalized = $envelope;
        $normalized['started_at'] = '2026-07-22T12:34:56+00:00';
        $normalized['execution_hash'] = 'sha256:'.hash('sha256', json_encode([
            'schema' => AtlasSwarmExecutorService::ENVELOPE_SCHEMA,
            'started_at' => $normalized['started_at'],
            'dispatch_id' => $normalized['dispatch_id'],
            'winner_arm_id' => data_get($normalized, 'winner.arm_id'),
            'arm_count' => $normalized['arm_count'],
        ], JSON_THROW_ON_ERROR));

        self::assertSame([
            'schema_version' => AtlasSwarmExecutorService::ENVELOPE_SCHEMA,
            'started_at' => '2026-07-22T12:34:56+00:00',
            'dispatch_id' => 'provider-pipe-f0',
            'task_category' => 'provider_pipe_f0',
            'role' => 'characterization',
            'framework' => null,
            'arm_count' => 2,
            'outcomes' => [
                [
                    'schema_version' => AtlasSwarmExecutorService::OUTCOME_SCHEMA,
                    'arm_id' => 'primary',
                    'rank' => 1,
                    'origin' => 'recommended',
                    'provider' => 'claude_cli',
                    'model' => 'opus',
                    'result' => 'success',
                    'latency_ms' => 101,
                    'quality_score' => 0.9,
                    'output_hash' => 'sha256:f08ab62dd0e0a8e1c6f353d67941d0161820580faf760662e8262130cf407038',
                ],
                [
                    'schema_version' => AtlasSwarmExecutorService::OUTCOME_SCHEMA,
                    'arm_id' => 'fallback',
                    'rank' => 2,
                    'origin' => 'runner_up',
                    'provider' => 'codex_cli',
                    'model' => 'gpt',
                    'result' => 'success',
                    'latency_ms' => 102,
                    'quality_score' => 0.8,
                    'output_hash' => 'sha256:864c7a75b560e84a71c413c195ad20e880f301ba997fd6555790ccd402be84a0',
                ],
            ],
            'winner' => [
                'arm_id' => 'primary',
                'provider' => 'claude_cli',
                'model' => 'opus',
                'rank' => 1,
                'quality_score' => 0.9,
                'latency_ms' => 101,
                'result' => 'success',
                'reason' => 'highest_quality_lowest_latency',
            ],
            'kernel_hash' => 'sha256:1e6743acb950cbb00fd2626b68aa8999b1f69e041dd52a533ad97cf83b841eee',
            'execution_hash' => 'sha256:7c8221f68d7ee164d096ebed4bf567d1d30d7fc95c3cc020292a2bfd41b14d27',
        ], $normalized);
        self::assertCount(1, $executor->listExecutions());
    }

    public function test_real_forge_driver_composition_derives_governed_from_the_consult_seam(): void
    {
        $ledger = new ProviderGovernanceCoverageLedger;
        $ledgerPath = $this->temporaryPath('forge.jsonl');
        $ledger->setLogPathForTesting($ledgerPath);
        $runner = new AtlasForgeProviderProcessRunner($ledger);
        $capturedArgv = [];
        $runner->setProcessFactory(static function (array $argv, ?string $cwd, ?array $env, int $timeout) use (&$capturedArgv): Process {
            $capturedArgv[] = $argv;

            return new Process([PHP_BINARY, '-r', 'fwrite(STDOUT, "provider-pipe-f0 fake stdout");'], $cwd, $env, null, $timeout);
        });

        $this->app->instance(ProviderGovernanceConsult::class, $this->governanceConsult($ledger));
        $driver = $this->forgeDriver($runner);
        $request = ['cwd' => base_path(), 'prompt' => 'safe F0 provider-pipe fixture'];
        $governed = $driver->invoke($request);

        $this->app->forgetInstance(ProviderGovernanceConsult::class);
        $this->app->bind(ProviderGovernanceConsult::class, static fn (): never => throw new \RuntimeException('F0 deliberately removes the consult seam'));
        $bypass = $driver->invoke($request);
        $summary = $ledger->summary();

        self::assertSame('completed', $governed['process_status']);
        self::assertSame('completed', $bypass['process_status']);
        self::assertTrue($governed['provider_called']);
        self::assertTrue($bypass['provider_called']);
        self::assertSame('provider-pipe-f0 fake stdout', $governed['stdout_excerpt']);
        self::assertSame('provider-pipe-f0 fake stdout', $bypass['stdout_excerpt']);
        self::assertSame([
            ['codex', '-c', 'model_reasoning_effort="xhigh"', '--non-interactive'],
            ['codex', '-c', 'model_reasoning_effort="xhigh"', '--non-interactive'],
        ], $capturedArgv);
        self::assertSame(2, $summary['total']);
        self::assertSame(1, $summary['consulted']);
        self::assertSame(1, $summary['bypass']);
        self::assertSame(0.5, $summary['bypass_rate']);
        self::assertSame(0.5, $summary['governed_rate']);
        self::assertSame(
            ['covered' => 0, 'consulted' => 1, 'bypass' => 1],
            $summary['by_surface'][ProviderGovernanceCoverageLedger::SURFACE_FORGE_PROCESS_RUNNER],
        );
        $rows = array_values(array_filter(array_map(
            static fn (string $line): ?array => json_decode($line, true),
            array_filter(explode("\n", trim((string) file_get_contents($ledgerPath)))),
        )));
        self::assertSame(ProviderGovernanceCoverageLedger::PATH_CONSULTED, $rows[0]['path']);
        self::assertSame(ProviderGovernanceCoverageLedger::PATH_BYPASS, $rows[1]['path']);
        self::assertSame('forge', data_get($rows, '0.context.executor'));
        self::assertSame('forge', data_get($rows, '0.context.actor'));
    }

    private function governanceConsult(ProviderGovernanceCoverageLedger $ledger): ProviderGovernanceConsult
    {
        $kernel = new AtlasConstitutionalKernelService;
        $kernel->setViolationsLogPathForTesting($this->temporaryPath('forge-kernel.jsonl'));
        $admission = new AtlasAutonomyAdmissionService($kernel);
        $adml = new AtlasDecideGatewayConsultationService(
            new class extends AtlasDecideMetaLearningService
            {
                public function __construct() {}

                public function activeRouteFor(string $taskCategory, string $role, ?string $framework = null): ?array
                {
                    return null;
                }
            },
            $kernel,
            $admission,
        );
        $adml->setLogPathForTesting($this->temporaryPath('forge-adml.jsonl'));
        $trustBudget = new AtlasTrustBudgetService;
        $trustBudget->setLogPathForTesting($this->temporaryPath('forge-trust.jsonl'));

        return new ProviderGovernanceConsult(
            $adml,
            new AiCallCostGuard(new AtlasTokenEconomyBudgetPolicyService),
            $ledger,
            $trustBudget,
        );
    }

    private function forgeDriver(AtlasForgeProviderProcessRunner $runner): AtlasForgeBaseCliInvocationDriver
    {
        return new class(app(AtlasForgeProviderCommandAllowlistService::class), $runner, app(AtlasForgeProviderInvocationFailureClassifier::class)) extends AtlasForgeBaseCliInvocationDriver
        {
            public function provider(): string
            {
                return 'codex_cli';
            }

            /** @return list<string> */
            protected function candidateBinaries(): array
            {
                return ['codex'];
            }

            /** @return list<string> */
            protected function authEnvVars(): array
            {
                return [];
            }

            /** @return list<string> */
            protected function modelPrefixes(): array
            {
                return ['codex'];
            }

            /** @return array<string,mixed> */
            public function configured(): array
            {
                return ['configured' => true, 'blockers' => []];
            }

            protected function resolveBinaryPath(): ?string
            {
                return 'codex';
            }

            /** @return array<string,string> */
            protected function worktreeState(?string $cwd): array
            {
                return [];
            }
        };
    }

    private function temporaryPath(string $file): string
    {
        $directory = sys_get_temp_dir().'/atlas-provider-pipe-f0-'.uniqid('', true);
        $this->temporaryDirectories[] = $directory;

        return $directory.'/'.$file;
    }
}
