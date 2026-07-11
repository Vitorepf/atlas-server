<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Cognition;

use App\Services\Ai\Cognition\AtlasAcosRollbackTriggerCheckService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * ACOS Excellence ROL-01 — pre-declared rollback trigger check.
 *
 * Authority: docs/engineering-knowledge-base/atlas-acos-excellence-10-10-plan-v1.md §ROL-01
 */
class AtlasAcosRollbackTriggerCheckServiceTest extends TestCase
{
    public function test_config_declares_six_rollback_triggers(): void
    {
        /** @var list<array<string,mixed>> $flips */
        $flips = (array) config('atlas.acos.rollback_triggers.flips', []);

        $this->assertCount(6, $flips);

        $ids = array_column($flips, 'id');
        $this->assertSame([
            'eng_13_governance_enforce',
            'eng_14_forge_gate_enforce',
            'eng_15_adml_cost_outcome',
            'cpt_09_compaction_enforce',
            'ope_04_fee_04_pack_quality',
            'pip_04_remint_touched',
        ], $ids);

        foreach ($flips as $flip) {
            $this->assertNotEmpty($flip['rollback_action'] ?? null);
            $this->assertSame('watchdog_alert_operator_reverts', $flip['executor'] ?? null);
            $this->assertNotEmpty($flip['condition']['kind'] ?? null);
        }

        $cpt = collect($flips)->firstWhere('id', 'cpt_09_compaction_enforce');
        $this->assertSame('ATLAS_TOKEN_ECONOMY_ENFORCEMENT_MODE', $cpt['condition']['requires_flip']['env'] ?? null);
        $this->assertSame(['ATLAS_TOKEN_ECONOMY_ENFORCEMENT_MODE' => 'observe'], $cpt['rollback_action'] ?? null);
        $this->assertFalse((bool) config('atlas.engineering_kernel.forge_execution_gate_enforcing'));
    }

    public function test_pre_flip_triggers_do_not_alert_without_simulation(): void
    {
        $payload = app(AtlasAcosRollbackTriggerCheckService::class)->check(
            CarbonImmutable::parse('2026-07-08T12:00:00Z'),
        );

        $this->assertFalse($payload['alert']);
        $this->assertSame('healthy', $payload['status']);
        $this->assertSame(6, $payload['flip_count']);
        foreach ($payload['evaluations'] as $evaluation) {
            $this->assertFalse($evaluation['fired']);
            $this->assertContains($evaluation['status'], ['pre_flip', 'monitoring']);
        }
    }

    public function test_simulated_trigger_fires_alert_with_named_rollback_action(): void
    {
        $payload = app(AtlasAcosRollbackTriggerCheckService::class)->check(
            CarbonImmutable::parse('2026-07-08T12:00:00Z'),
            'eng_13_governance_enforce',
        );

        $this->assertTrue($payload['alert']);
        $this->assertSame('alert', $payload['status']);
        $this->assertSame('rollback_trigger_fired', $payload['alert_code']);
        $this->assertCount(1, $payload['alerts']);
        $this->assertSame('eng_13_governance_enforce', $payload['alerts'][0]['trigger_id']);
        $this->assertSame([
            'ATLAS_AI_GOVERNANCE_ENFORCE' => 'false',
            'ATLAS_AI_CALL_COST_GUARD_HARD_UNITS' => '0',
        ], $payload['alerts'][0]['rollback_action']);
        $this->assertTrue($payload['alerts'][0]['simulated']);
    }

    public function test_command_json_exits_non_zero_when_simulated_trigger_fires(): void
    {
        $this->artisan('atlas:acos:rollback-triggers', [
            '--json' => true,
            '--simulate' => 'pip_04_remint_touched',
        ])->assertFailed();
    }

    public function test_command_json_exits_zero_when_no_trigger_fires(): void
    {
        $this->artisan('atlas:acos:rollback-triggers', [
            '--json' => true,
        ])->assertSuccessful();
    }
}
