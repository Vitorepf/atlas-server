<?php

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AtlasVoiceReadinessCommandHumanOutputTest extends TestCase
{
    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        parent::tearDown();
    }

    public function test_readiness_human_output_lists_product_loop_contract_without_secrets(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        $exit = Artisan::call('atlas:ai:voice', [
            'action' => 'readiness',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Readiness', $output);
        $this->assertStringContainsString('Product loop contract', $output);
        $this->assertStringContainsString('Product loop command', $output);
        $this->assertStringContainsString('php artisan atlas:ai:voice product-loop-check --json', $output);
        $this->assertStringContainsString('Review action', $output);
        $this->assertStringNotContainsString('livekit-token', $output);
        $this->assertStringNotContainsString('product-loop-secret', $output);
    }
}
