<?php

namespace Tests\Feature\Ai\VentureFoundry\Safety;

use App\Services\Ai\VentureFoundry\Safety\VentureActionDispatchController;
use App\Services\Ai\VentureFoundry\Safety\WindowedReservationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class VentureActionDispatchControllerTest extends TestCase
{
    private string $venture;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('ai_venture_spend_reservations');
        Schema::dropIfExists('ai_venture_spend_windows');
        Schema::create('ai_venture_spend_windows', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('scope_ref', 120)->index();
            $table->enum('window_kind', ['hour', 'day', 'week', 'portfolio']);
            $table->string('window_key', 40);
            $table->bigInteger('cap_microusd');
            $table->bigInteger('reserved_microusd')->default(0);
            $table->timestamps();
            $table->unique(['scope_ref', 'window_kind', 'window_key'], 'uniq_spend_window');
        });
        Schema::create('ai_venture_spend_reservations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('window_id')->index();
            $table->string('idempotency_key', 191)->unique();
            $table->bigInteger('amount_microusd');
            $table->timestamp('created_at')->nullable();
        });
        $this->venture = (string) Str::uuid();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_venture_spend_reservations');
        Schema::dropIfExists('ai_venture_spend_windows');
        parent::tearDown();
    }

    private function ctl(): VentureActionDispatchController
    {
        return new VentureActionDispatchController(new WindowedReservationService);
    }

    public function test_default_is_suggest_byte_identical_off(): void
    {
        $r = $this->ctl()->decide(['action_class' => 'marketing_post', 'venture_id' => $this->venture]);
        $this->assertSame(VentureActionDispatchController::SUGGEST, $r['decision'], 'default = propose only');
    }

    public function test_unknown_class_blocks(): void
    {
        $r = $this->ctl()->decide(['action_class' => 'launch_nukes', 'venture_id' => $this->venture]);
        $this->assertSame(VentureActionDispatchController::BLOCK, $r['decision']);
        $this->assertSame('unknown_action_class', $r['reason']);
    }

    public function test_irreversible_without_mandate_blocks_even_on_auto(): void
    {
        $r = $this->ctl()->decide(['action_class' => 'email', 'venture_id' => $this->venture, 'autonomy' => 'auto']);
        $this->assertSame(VentureActionDispatchController::BLOCK, $r['decision']);
        $this->assertSame('irreversible_requires_mandate', $r['reason']);
    }

    public function test_irreversible_with_mandate_allows(): void
    {
        $r = $this->ctl()->decide(['action_class' => 'email', 'venture_id' => $this->venture, 'has_live_mandate' => true]);
        $this->assertSame(VentureActionDispatchController::ALLOW, $r['decision']);
    }

    public function test_reversible_auto_allows(): void
    {
        $r = $this->ctl()->decide(['action_class' => 'marketing_post', 'venture_id' => $this->venture, 'autonomy' => 'auto']);
        $this->assertSame(VentureActionDispatchController::ALLOW, $r['decision']);
    }

    public function test_approve_without_mandate_blocks(): void
    {
        $r = $this->ctl()->decide(['action_class' => 'marketing_post', 'venture_id' => $this->venture, 'autonomy' => 'approve']);
        $this->assertSame(VentureActionDispatchController::BLOCK, $r['decision']);
        $this->assertSame('needs_operator_approval', $r['reason']);
    }

    public function test_spend_within_cap_allows_and_reserves(): void
    {
        $r = $this->ctl()->decide([
            'action_class' => 'ad_spend', 'venture_id' => $this->venture, 'has_live_mandate' => true,
            'amount_microusd' => 50000, 'spend_cap_microusd' => 100000,
            'spend_window_kind' => 'day', 'spend_window_key' => 'd', 'idempotency_key' => 'k1',
        ]);
        $this->assertSame(VentureActionDispatchController::ALLOW, $r['decision']);
        $this->assertTrue($r['reserved']);
    }

    public function test_spend_over_cap_blocks(): void
    {
        $ctl = $this->ctl();
        $ctl->decide(['action_class' => 'ad_spend', 'venture_id' => $this->venture, 'has_live_mandate' => true, 'amount_microusd' => 80000, 'spend_cap_microusd' => 100000, 'spend_window_kind' => 'day', 'spend_window_key' => 'd', 'idempotency_key' => 'k1']);
        $r = $ctl->decide(['action_class' => 'ad_spend', 'venture_id' => $this->venture, 'has_live_mandate' => true, 'amount_microusd' => 50000, 'spend_cap_microusd' => 100000, 'spend_window_kind' => 'day', 'spend_window_key' => 'd', 'idempotency_key' => 'k2']);
        $this->assertSame(VentureActionDispatchController::BLOCK, $r['decision']);
        $this->assertSame('spend_cap_exceeded', $r['reason']);
    }
}
