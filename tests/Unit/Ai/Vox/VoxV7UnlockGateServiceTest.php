<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Vox;

use App\Services\Ai\Vox\Dogfood\VoxDogfoodService;
use App\Services\Ai\Vox\Gate\VoxV7UnlockGateService;
use App\Services\Ai\Vox\Metrics\VoxMetricsService;
use Tests\TestCase;

/**
 * Garante o canon do gate V7:
 *   - `unlocked` SEMPRE false (doutrina V6-F).
 *   - sem uso real → bloqueios completos e honestos.
 *   - schema/version estáveis para consumidores externos.
 */
final class VoxV7UnlockGateServiceTest extends TestCase
{
    private function service(): VoxV7UnlockGateService
    {
        return new VoxV7UnlockGateService(
            $this->app->make(VoxMetricsService::class),
            $this->app->make(VoxDogfoodService::class),
        );
    }

    public function test_envelope_uses_canonical_schema(): void
    {
        $env = $this->service()->evaluate();

        $this->assertSame(VoxV7UnlockGateService::SCHEMA, $env['schema']);
        $this->assertSame('atlas.vox.v7_unlock_gate.v1', $env['schema']);
        $this->assertIsString($env['version']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', (string) $env['generated_at']);
    }

    public function test_unlocked_is_always_false(): void
    {
        $env = $this->service()->evaluate();
        $this->assertFalse(
            $env['unlocked'],
            'unlocked deve permanecer false enquanto a doutrina V6-F estiver em vigor.',
        );
        $this->assertTrue($env['requires_human_adr']);
    }

    public function test_blockers_listed_when_no_real_usage(): void
    {
        $env = $this->service()->evaluate();
        // Em ambiente de teste limpo (sem ledger / dogfood) os bloqueios são
        // a regra. Garantimos que a lista carrega pelo menos os critérios
        // canon e está em PT-BR.
        $this->assertNotEmpty($env['blockers']);
        $this->assertNotEmpty($env['blockers_pt_br']);
        foreach (['usage_volume', 'success_rate_acceptable', 'regret_rate_acceptable', 'recurring_dictionary_corrections', 'explicit_human_approval'] as $key) {
            $this->assertContains($key, $env['blockers'], "esperava bloqueio: {$key}");
        }
    }

    public function test_criteria_status_carries_observed_data(): void
    {
        $env = $this->service()->evaluate();
        foreach ($env['criteria_status'] as $key => $row) {
            $this->assertArrayHasKey('target', $row, "{$key} sem target");
            $this->assertArrayHasKey('observed', $row, "{$key} sem observed");
            $this->assertArrayHasKey('ok', $row, "{$key} sem ok");
            $this->assertArrayHasKey('blocker_pt_br', $row, "{$key} sem blocker_pt_br");
            $this->assertIsBool($row['ok']);
        }
    }

    public function test_human_approval_marker_default_absent(): void
    {
        $env = $this->service()->evaluate();
        // Em ambiente de teste o marcador NÃO deve estar presente.
        $this->assertIsArray($env['human_approval']);
        $this->assertFalse($env['human_approval']['marker_present']);
        $this->assertIsString($env['human_approval']['blocked_reason_pt_br']);
    }

    public function test_envelope_serialises_to_json(): void
    {
        $env = $this->service()->evaluate();
        $json = json_encode($env, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->assertIsString($json);
        $this->assertGreaterThan(200, strlen($json));
        $decoded = json_decode($json, true);
        $this->assertSame($env['schema'], $decoded['schema']);
        $this->assertSame($env['unlocked'], $decoded['unlocked']);
    }
}
