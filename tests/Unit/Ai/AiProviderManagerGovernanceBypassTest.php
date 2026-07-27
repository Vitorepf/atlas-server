<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Services\Ai\AiProviderManager;
use App\Services\Ai\Governance\ProviderGovernanceCoverageLedger;
use App\Services\Ai\Policy\AtlasAiRuntimeSettings;
use App\Services\Ai\ClaudeCliProvider;
use App\Services\Ai\CodexCliProvider;
use App\Services\Ai\GeminiCliProvider;
use App\Services\Ai\HermesCliProvider;
use App\Services\Ai\JarvisMlxProvider;
use App\Services\Ai\MinimaxM27CliProvider;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

final class AiProviderManagerGovernanceBypassTest extends TestCase
{
    private string $logPath;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->logPath = storage_path('framework/testing/provider_coverage.jsonl');
        @unlink($this->logPath);
    }

    protected function tearDown(): void
    {
        @unlink($this->logPath);
        parent::tearDown();
    }

    private function manager(?ProviderGovernanceCoverageLedger $ledger = null): AiProviderManager
    {
        $settings = $this->createMock(AtlasAiRuntimeSettings::class);
        $settings->method('defaultProvider')->willReturn('claude_cli');

        $manager = new AiProviderManager(
            $this->createMock(ClaudeCliProvider::class),
            $this->createMock(CodexCliProvider::class),
            $this->createMock(GeminiCliProvider::class),
            $this->createMock(JarvisMlxProvider::class),
            $this->createMock(HermesCliProvider::class),
            $this->createMock(MinimaxM27CliProvider::class),
            $settings,
        );

        if ($ledger !== null) {
            $manager->setCoverageLedger($ledger);
        }

        return $manager;
    }

    public function test_unknown_provider_is_fail_closed(): void
    {
        $manager = $this->manager();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported AI provider [unknown_provider].');

        $manager->get('unknown_provider');
    }

    public function test_get_records_covered_resolution_without_external_call(): void
    {
        $ledger = new ProviderGovernanceCoverageLedger();
        $ledger->setLogPathForTesting($this->logPath);
        $manager = $this->manager($ledger);

        $provider = $manager->get('claude_cli');

        $this->assertInstanceOf(ClaudeCliProvider::class, $provider);
        $summary = $ledger->summary();
        $this->assertSame(1, $summary['total']);
        $this->assertSame(1, $summary['covered']);
        $this->assertSame(0, $summary['bypass']);
        $this->assertSame('claude_cli', array_key_first($summary['by_provider']));
        $this->assertArrayHasKey('ai_provider_manager', $summary['by_surface']);
    }

    public function test_get_recommended_records_covered_resolution_without_external_call(): void
    {
        $ledger = new ProviderGovernanceCoverageLedger();
        $ledger->setLogPathForTesting($this->logPath);
        $manager = $this->manager($ledger);

        $resolution = $manager->getRecommended('coding', 'reviewer');

        $this->assertSame('claude_cli', $resolution['key']);
        $this->assertInstanceOf(ClaudeCliProvider::class, $resolution['provider']);
        $summary = $ledger->summary();
        $this->assertSame(1, $summary['total']);
        $this->assertSame(1, $summary['covered']);
        $this->assertSame(0, $summary['bypass']);
        $this->assertArrayHasKey('ai_provider_manager_recommendation', $summary['by_surface']);
    }

    public function test_record_bypass_exposes_muscle_path_resolution(): void
    {
        $ledger = new ProviderGovernanceCoverageLedger();
        $ledger->setLogPathForTesting($this->logPath);
        $manager = $this->manager($ledger);

        $manager->recordBypass('codex_cli', 'forge_process_runner', 'direct spawn');

        $summary = $ledger->summary();
        $this->assertSame(1, $summary['total']);
        $this->assertSame(0, $summary['covered']);
        $this->assertSame(1, $summary['bypass']);
        $this->assertSame('codex_cli', array_key_first($summary['by_provider']));
        $this->assertArrayHasKey('forge_process_runner', $summary['by_surface']);
    }
}
