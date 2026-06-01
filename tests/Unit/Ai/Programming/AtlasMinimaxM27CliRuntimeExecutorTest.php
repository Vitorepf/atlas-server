<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming;

use App\Services\Ai\Programming\AtlasMinimaxM27CliRuntimeExecutor;
use Tests\TestCase;

final class AtlasMinimaxM27CliRuntimeExecutorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        putenv('ATLAS_MINIMAX_TOKEN_PLAN_KEY=tpk-test-12345678');
        putenv('ATLAS_MINIMAX_PAYGO_API_KEY');

        config()->set('atlas.ai.providers.minimax_m27_cli', [
            'enabled' => true,
            'python' => PHP_BINARY,
            'adapter_path' => __FILE__,
            'model' => AtlasMinimaxM27CliRuntimeExecutor::MODEL,
            'allow_token_plan' => true,
        ]);
    }

    protected function tearDown(): void
    {
        putenv('ATLAS_MINIMAX_TOKEN_PLAN_KEY');
        putenv('ATLAS_MINIMAX_PAYGO_API_KEY');

        parent::tearDown();
    }

    public function test_configured_uses_minimax_m3_model(): void
    {
        $status = app(AtlasMinimaxM27CliRuntimeExecutor::class)->configured();

        $this->assertTrue($status['configured']);
        $this->assertSame('MiniMax-M3', $status['model']);
        $this->assertSame(['MiniMax-M3', 'minimax-m3'], $status['model_prefixes']);
    }

    public function test_configured_rejects_legacy_m27_model(): void
    {
        config()->set('atlas.ai.providers.minimax_m27_cli.model', 'MiniMax-M2.7');

        $status = app(AtlasMinimaxM27CliRuntimeExecutor::class)->configured();

        $this->assertFalse($status['configured']);
        $this->assertContains(AtlasMinimaxM27CliRuntimeExecutor::BLOCKER_MODEL_NOT_M3, $status['blockers']);
    }

    public function test_plan_rejects_legacy_manifest_model_before_provider_spend(): void
    {
        $plan = app(AtlasMinimaxM27CliRuntimeExecutor::class)->plan([
            'model' => 'MiniMax-M2.7',
            'decision_receipt_id' => 'receipt_1',
            'decision_receipt_hash' => hash('sha256', 'receipt_1'),
            'workspace' => ['path' => base_path()],
            'scope_contract' => [
                'allowed_files' => ['app/Foo.php'],
            ],
        ]);

        $this->assertFalse($plan['plan_safe']);
        $this->assertFalse($plan['provider_called']);
        $this->assertContains(AtlasMinimaxM27CliRuntimeExecutor::BLOCKER_MODEL_NOT_M3, $plan['blockers']);
    }
}
