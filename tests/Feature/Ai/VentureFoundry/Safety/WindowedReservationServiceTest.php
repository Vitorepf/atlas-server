<?php

namespace Tests\Feature\Ai\VentureFoundry\Safety;

use App\Services\Ai\VentureFoundry\Safety\WindowedReservationService;
use App\Services\Ai\VentureFoundry\VentureFoundryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class WindowedReservationServiceTest extends TestCase
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

    private function svc(): WindowedReservationService
    {
        return new WindowedReservationService;
    }

    public function test_reserves_under_cap(): void
    {
        $ok = $this->svc()->reserve($this->venture, 'day', '2026-06-17', 100000, 30000, 'r1');
        $this->assertTrue($ok);
        $this->assertSame(30000, $this->svc()->reservedMicroUsd($this->venture, 'day', '2026-06-17'));
    }

    public function test_death_by_a_thousand_cuts_blocked_at_cap(): void
    {
        $s = $this->svc();
        // cap 100000; three 30000 reserves = 90000 (ok), the fourth 30000 would hit 120000 -> blocked.
        $this->assertTrue($s->reserve($this->venture, 'day', 'd', 100000, 30000, 'a'));
        $this->assertTrue($s->reserve($this->venture, 'day', 'd', 100000, 30000, 'b'));
        $this->assertTrue($s->reserve($this->venture, 'day', 'd', 100000, 30000, 'c'));
        $this->assertFalse($s->reserve($this->venture, 'day', 'd', 100000, 30000, 'd'), 'cumulative small spends cannot breach cap');
        // a smaller one that still fits is allowed (90000 + 10000 = 100000 <= cap)
        $this->assertTrue($s->reserve($this->venture, 'day', 'd', 100000, 10000, 'e'));
        $this->assertSame(100000, $s->reservedMicroUsd($this->venture, 'day', 'd'));
        // now anything > 0 fails
        $this->assertFalse($s->reserve($this->venture, 'day', 'd', 100000, 1, 'f'));
    }

    public function test_idempotent_key_reserves_once(): void
    {
        $s = $this->svc();
        $this->assertTrue($s->reserve($this->venture, 'day', 'd', 100000, 40000, 'same'));
        $this->assertTrue($s->reserve($this->venture, 'day', 'd', 100000, 40000, 'same')); // replay
        $this->assertSame(40000, $s->reservedMicroUsd($this->venture, 'day', 'd'), 'replay must not double-reserve');
    }

    public function test_rejects_bad_window_kind(): void
    {
        $this->expectException(VentureFoundryException::class);
        $this->svc()->reserve($this->venture, 'decade', 'x', 100, 1, 'k');
    }

    public function test_rejects_negative(): void
    {
        $this->expectException(VentureFoundryException::class);
        $this->svc()->reserve($this->venture, 'day', 'x', 100, -1, 'k');
    }
}
