<?php

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AtlasVoiceReadinessProductLoopReferenceTest extends TestCase
{
    private array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_ledger_events');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();

        parent::tearDown();
    }

    public function test_readiness_keeps_product_loop_check_reference_when_ledger_is_unavailable(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        $this->getJson('/ai/voice/readiness', $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'ledger_unavailable')
            ->assertJsonPath('product_loop_check.schema_version', 'atlas.voice_realtime.product_loop_check_reference.v1')
            ->assertJsonPath('product_loop_check.command', 'php artisan atlas:ai:voice product-loop-check --json')
            ->assertJsonPath('product_loop_check.promotion_allowed', false)
            ->assertJsonPath('product_loop_check.auto_promotion_allowed', false)
            ->assertJsonPath('product_loop_check.daemon_started', false)
            ->assertJsonPath('product_loop_check.required_gates.4', 'sdk_probe_import_safe')
            ->assertJsonPath('product_loop_check.next_action', 'run_product_loop_check_before_livekit_daemon_work');
    }
}
