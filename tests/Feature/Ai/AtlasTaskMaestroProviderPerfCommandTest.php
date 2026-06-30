<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\Maestro\ProviderLearning\AtlasMaestroProviderPerformanceLedger;
use App\Services\Ai\SelfConstruction\Maestro\ProviderLearning\AtlasMaestroProviderRecommendationEngine;
use App\Services\Ai\SelfConstruction\Maestro\ProviderLearning\AtlasMaestroProviderRecommendationReceiptLedger;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class AtlasTaskMaestroProviderPerfCommandTest extends TestCase
{
    private string $perfRoot = '';

    private string $receiptPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $tag = bin2hex(random_bytes(4));
        $this->perfRoot = sys_get_temp_dir().'/atlas-perf-cli-'.$tag;
        @mkdir($this->perfRoot, 0o755, true);
        $this->receiptPath = sys_get_temp_dir().'/atlas-perf-receipt-'.$tag.'.jsonl';

        AtlasMaestroProviderPerformanceLedger::setRootForTesting($this->perfRoot);
        app()->instance(AtlasMaestroProviderRecommendationReceiptLedger::class, new AtlasMaestroProviderRecommendationReceiptLedger($this->receiptPath));
    }

    protected function tearDown(): void
    {
        AtlasMaestroProviderPerformanceLedger::setRootForTesting(null);
        @unlink($this->receiptPath);
        foreach (glob($this->perfRoot.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->perfRoot);
        parent::tearDown();
    }

    private function seedSamples(string $taskClass, string $provider, int $successCount, int $giveBackCount): void
    {
        $ledger = app(AtlasMaestroProviderPerformanceLedger::class);
        for ($i = 0; $i < $successCount; $i++) {
            $ledger->recordOutcome($provider, $taskClass, AtlasMaestroProviderPerformanceLedger::OUTCOME_SUCCESS, 1000, time());
        }
        for ($i = 0; $i < $giveBackCount; $i++) {
            $ledger->recordOutcome($provider, $taskClass, AtlasMaestroProviderPerformanceLedger::OUTCOME_GIVE_BACK, 1000, time());
        }
    }

    private function runCmd(array $params): array
    {
        $buf = new BufferedOutput();
        /** @var \Illuminate\Contracts\Console\Kernel $kernel */
        $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
        $exit = $kernel->call('atlas:task:maestro:provider-perf', $params, $buf);

        return ['exit' => $exit, 'output' => trim($buf->fetch())];
    }

    public function test_inspect_emits_providers_with_per_class_facts(): void
    {
        $this->seedSamples('refactor', 'atlas_native', successCount: 8, giveBackCount: 2);
        $this->seedSamples('refactor', 'codex', successCount: 6, giveBackCount: 4);

        $r = $this->runCmd(['action' => 'inspect', '--class' => 'refactor', '--json' => true]);

        self::assertSame(0, $r['exit']);
        $payload = json_decode($r['output'], true);
        self::assertIsArray($payload);
        self::assertSame('refactor', $payload['task_class']);
        $names = array_column($payload['providers'], 'provider');
        self::assertContains('atlas_native', $names);
        $native = $payload['providers'][array_search('atlas_native', $names, true)];
        self::assertSame(8, $native['success_count']);
        self::assertSame(2, $native['give_back_count']);
        self::assertEqualsWithDelta(0.8, $native['success_rate'], 0.001);
        self::assertArrayHasKey('avg_duration_ms', $native);
    }

    public function test_recommend_with_sufficient_samples_writes_exactly_one_receipt(): void
    {
        $this->seedSamples('refactor', 'atlas_native', successCount: 9, giveBackCount: 1);
        $this->seedSamples('refactor', 'codex', successCount: 5, giveBackCount: 5);

        $r = $this->runCmd(['action' => 'recommend', '--class' => 'refactor', '--actor' => 'loop', '--json' => true]);

        self::assertSame(0, $r['exit'], 'recommend should exit 0: '.$r['output']);
        $payload = json_decode($r['output'], true);
        self::assertSame('ok', $payload['status']);
        self::assertSame('atlas_native', $payload['recommended_provider']);

        $lines = file($this->receiptPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        self::assertCount(1, $lines);
        $receipt = json_decode($lines[0], true);
        self::assertSame('refactor', $receipt['task_class']);
        self::assertSame('atlas_native', $receipt['recommended_provider']);
        self::assertSame('loop', $receipt['requested_by']);
    }

    public function test_recommend_below_min_sample_size_writes_zero_receipts_and_reports_insufficient_data(): void
    {
        $this->seedSamples('refactor', 'atlas_native', successCount: 1, giveBackCount: 0);

        $r = $this->runCmd(['action' => 'recommend', '--class' => 'refactor', '--actor' => 'loop', '--json' => true]);

        self::assertSame(0, $r['exit']);
        $payload = json_decode($r['output'], true);
        self::assertSame('insufficient_data', $payload['status']);
        self::assertFileDoesNotExist($this->receiptPath);
    }

    public function test_inspect_exits_zero_without_receipt_ledger_in_container(): void
    {
        // Regression: inspect must work even when the receipt ledger is NOT bound in the container.
        // Before the fix, handle() injected it eagerly and crashed here.
        app()->forgetInstance(AtlasMaestroProviderRecommendationReceiptLedger::class);

        $r = $this->runCmd(['action' => 'inspect', '--json' => true]);

        self::assertSame(0, $r['exit']);
        $decoded = json_decode($r['output'], true);
        self::assertIsArray($decoded, 'inspect must emit valid JSON without a receipt-ledger binding');
    }

    public function test_command_source_does_not_touch_provider_manager_router_or_auto_merge(): void
    {
        $src = (string) file_get_contents(base_path('app/Console/Commands/AtlasTaskMaestroProviderPerfCommand.php'));
        foreach (['AiProviderManager', 'AtlasLoopRouter', 'auto_merge', 'AutoMerge'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $src, "command must not reference {$forbidden}");
        }
    }

    public function test_unknown_action_exits_non_zero(): void
    {
        $r = $this->runCmd(['action' => 'bogus']);

        self::assertNotSame(0, $r['exit']);
        self::assertStringContainsString('unknown_action', $r['output']);
    }
}
