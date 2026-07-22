<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Provider;

use App\Services\Ai\AiProvider;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\AtlasDecide\AtlasDecideLiveOutcomeFeedbackService;
use App\Services\Ai\AtlasDecide\AtlasSwarmExecutorService;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use App\Services\Ai\Governance\ProviderGovernanceCoverageLedger;
use App\Services\Ai\Programming\AtlasForgeProviderProcessRunner;
use App\Services\Ai\Provider\Drivers\ProviderDriverRegistry;
use Illuminate\Support\Facades\File;
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

    public function test_registry_manifest_and_compliance_report_are_the_fusion_oracle(): void
    {
        $registry = app(ProviderDriverRegistry::class);
        $manifest = $registry->manifest();
        $report = $registry->complianceReport();

        self::assertSame(
            ['claude_cli', 'codex_cli', 'gemini_cli', 'claude_codex', 'jarvis_mlx', 'hermes_cli'],
            array_column($manifest, 'provider_id'),
        );
        self::assertSame(6, $report['count']);
        self::assertSame(array_column($manifest, 'provider_id'), $report['providers']);
        self::assertTrue($report['ok']);
        self::assertSame([], $report['errors']);
        foreach ($manifest as $entry) {
            self::assertTrue((bool) data_get($entry, 'validation.ok'));
            self::assertFalse((bool) $entry['real_execution_enabled']);
        }
    }

    public function test_swarm_stub_emits_the_characterized_execution_envelope(): void
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

        self::assertSame(AtlasSwarmExecutorService::ENVELOPE_SCHEMA, $envelope['schema_version']);
        self::assertSame(2, $envelope['arm_count']);
        self::assertSame('primary', data_get($envelope, 'winner.arm_id'));
        self::assertSame(['claude_cli', 'codex_cli'], array_column($envelope['outcomes'], 'provider'));
        self::assertSame(['success', 'success'], array_column($envelope['outcomes'], 'result'));
        self::assertStringStartsWith('sha256:', $envelope['execution_hash']);
        self::assertCount(1, $executor->listExecutions());
    }

    public function test_forge_runner_records_bypass_or_preserves_a_prior_consulted_receipt(): void
    {
        $ledger = new ProviderGovernanceCoverageLedger;
        $ledger->setLogPathForTesting($this->temporaryPath('forge.jsonl'));
        $runner = new AtlasForgeProviderProcessRunner($ledger);
        $request = [
            'argv' => [PHP_BINARY, '-r', 'echo "provider-pipe-f0";'],
            'cwd' => base_path(),
            'provider' => 'codex_cli',
            'timeout_seconds' => 5,
        ];

        $bypass = $runner->run($request);
        $ledger->recordConsulted('codex_cli', ProviderGovernanceCoverageLedger::SURFACE_FORGE_PROCESS_RUNNER, ['fixture' => 'provider_pipe_f0']);
        $governed = $runner->run($request + ['governed' => true]);
        $summary = $ledger->summary();

        self::assertSame(AtlasForgeProviderProcessRunner::STATUS_COMPLETED, $bypass['status']);
        self::assertSame(AtlasForgeProviderProcessRunner::STATUS_COMPLETED, $governed['status']);
        self::assertSame(2, $summary['total']);
        self::assertSame(1, $summary['consulted']);
        self::assertSame(1, $summary['bypass']);
        self::assertSame(0.5, $summary['bypass_rate']);
        self::assertSame(0.5, $summary['governed_rate']);
        self::assertSame(
            ['covered' => 0, 'consulted' => 1, 'bypass' => 1],
            $summary['by_surface'][ProviderGovernanceCoverageLedger::SURFACE_FORGE_PROCESS_RUNNER],
        );
    }

    private function temporaryPath(string $file): string
    {
        $directory = sys_get_temp_dir().'/atlas-provider-pipe-f0-'.uniqid('', true);
        $this->temporaryDirectories[] = $directory;

        return $directory.'/'.$file;
    }
}
