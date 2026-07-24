<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\Forge;

use App\Models\AiForgeLongHorizonState;
use App\Services\Ai\Programming\Forge\Execution\ForgeObraSupervisor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ForgeObraSupervisorForwardProgressTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('ai_forge_long_horizon_states', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('uuid')->unique();
            $table->uuid('intake_id')->unique();
            $table->string('obra_title');
            $table->string('status');
            $table->json('milestone_progress');
            $table->json('active_work_packets');
            $table->json('completed_work_packets');
            $table->json('blockers');
            $table->json('evidence_refs');
            $table->json('next_action');
            $table->unsignedInteger('cycle_count')->default(0);
            $table->string('state_hash', 64);
            $table->timestamps();
        });
        Schema::create('ai_forge_work_packet_execution_cycles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('intake_id');
            $table->string('status');
            $table->string('execution_mode');
            $table->json('execution_plan')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamps();
        });
        Schema::create('atlas_task_scope_reservations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('state');
            $table->timestamp('lease_expires_at')->nullable();
            $table->string('active_scope_key')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_task_scope_reservations');
        Schema::dropIfExists('ai_forge_work_packet_execution_cycles');
        Schema::dropIfExists('ai_forge_long_horizon_states');

        parent::tearDown();
    }

    public function test_commissioned_obra_without_p1b_authority_is_observed_without_fake_progress(): void
    {
        $obraId = (string) Str::uuid();
        AiForgeLongHorizonState::query()->create([
            'uuid' => (string) Str::uuid(),
            'intake_id' => $obraId,
            'obra_title' => 'P1a commissioning-only obra',
            'status' => 'active',
            'milestone_progress' => [],
            'active_work_packets' => [],
            'completed_work_packets' => [],
            'blockers' => [],
            'evidence_refs' => [],
            'next_action' => ['kind' => 'await_p1b_authority'],
            'cycle_count' => 0,
            'state_hash' => str_repeat('a', 64),
        ]);

        $result = app(ForgeObraSupervisor::class)->run([$obraId], 900);

        self::assertSame('ok', $result['status']);
        self::assertSame(1, $result['active_obra_count']);
        self::assertSame(0, $result['stale_heartbeat_count']);
        self::assertSame('idle', $result['heartbeats'][0]['status']);
        self::assertSame('no_running_cycle', $result['heartbeats'][0]['reason']);
        self::assertSame(0, AiForgeLongHorizonState::query()->where('intake_id', $obraId)->firstOrFail()->cycle_count);
    }
}
