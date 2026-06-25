<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Maestro\ProviderLearning\AtlasMaestroProviderPerformanceLedger;
use App\Services\Ai\SelfConstruction\Maestro\ProviderLearning\AtlasMaestroProviderRecommendationEngine;
use Tests\TestCase;

final class AtlasMaestroProviderRecommendationEngineTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-maestro-reco-'.bin2hex(random_bytes(6));
        @mkdir($this->root, 0o755, true);
        AtlasMaestroProviderPerformanceLedger::setRootForTesting($this->root);
    }

    protected function tearDown(): void
    {
        AtlasMaestroProviderPerformanceLedger::setRootForTesting(null);
        foreach ((array) glob($this->root.'/*') as $f) {
            @unlink($f);
        }
        @rmdir($this->root);
        parent::tearDown();
    }

    private function seedRefactorTie(): AtlasMaestroProviderPerformanceLedger
    {
        $ledger = new AtlasMaestroProviderPerformanceLedger();
        // Provider A: 8 success / 2 give_back, avg 4000ms (sample 10).
        for ($i = 0; $i < 8; $i++) {
            $ledger->recordOutcome('A', 'refactor', 'success', 4000, 1700000000 + $i);
        }
        for ($i = 0; $i < 2; $i++) {
            $ledger->recordOutcome('A', 'refactor', 'give_back', 4000, 1700000100 + $i);
        }
        // Provider B: same 8/2 success/give_back, avg 9000ms (sample 10).
        for ($i = 0; $i < 8; $i++) {
            $ledger->recordOutcome('B', 'refactor', 'success', 9000, 1700001000 + $i);
        }
        for ($i = 0; $i < 2; $i++) {
            $ledger->recordOutcome('B', 'refactor', 'give_back', 9000, 1700001100 + $i);
        }

        return $ledger;
    }

    public function test_tie_break_on_avg_duration_picks_faster_provider(): void
    {
        $engine = new AtlasMaestroProviderRecommendationEngine($this->seedRefactorTie());
        $verdict = $engine->bestProviderFor('refactor');

        $this->assertSame('ok', $verdict['status']);
        $this->assertSame('A', $verdict['provider']);
        $this->assertSame(['B'], $verdict['tied_with']);
        $this->assertSame('avg_duration_ms', $verdict['tie_reason']);
    }

    public function test_below_min_sample_size_returns_insufficient_data(): void
    {
        $ledger = new AtlasMaestroProviderPerformanceLedger();
        $ledger->recordOutcome('A', 'refactor', 'success', 1000, 1700000000);
        $ledger->recordOutcome('A', 'refactor', 'success', 1000, 1700000001);
        $engine = new AtlasMaestroProviderRecommendationEngine($ledger, minSampleSize: 5);
        $verdict = $engine->bestProviderFor('refactor');

        $this->assertSame('insufficient_data', $verdict['status']);
        $this->assertSame(5, $verdict['min_required']);
        $this->assertArrayHasKey('A', $verdict['samples_seen']);
        $this->assertArrayNotHasKey('provider', $verdict);
    }

    public function test_unknown_class_returns_insufficient_data(): void
    {
        $engine = new AtlasMaestroProviderRecommendationEngine(new AtlasMaestroProviderPerformanceLedger());
        $verdict = $engine->bestProviderFor('does_not_exist');
        $this->assertSame('insufficient_data', $verdict['status']);
    }

    public function test_engine_source_imports_no_forbidden_classes(): void
    {
        $src = (string) file_get_contents(base_path('app/Services/Ai/SelfConstruction/Maestro/ProviderLearning/AtlasMaestroProviderRecommendationEngine.php'));
        foreach (['AiProviderManager', 'AtlasLoopRouter'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $src, "recommendation engine must NOT reference {$forbidden}");
        }
        $this->assertStringNotContainsString('namespace App\\Services\\Ai\\AutonomousEvolution', $src);
    }
}
