<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\Maestro\DynamicPriority;

use App\Services\Ai\SelfConstruction\Maestro\DynamicPriority\AtlasMaestroExpansionVersusRepairArbiter;
use Tests\TestCase;

final class AtlasMaestroExpansionVersusRepairArbiterTest extends TestCase
{
    private function arbiter(): AtlasMaestroExpansionVersusRepairArbiter
    {
        return new AtlasMaestroExpansionVersusRepairArbiter;
    }

    // ── AC: malformed or new collision facts choose repair ──

    public function test_malformed_blockers_choose_repair(): void
    {
        $result = $this->arbiter()->arbitrate([
            'health_status' => 'healthy',
            'malformed_blockers' => ['missing_scope'],
        ]);

        $this->assertSame('repair', $result['decision']);
    }

    public function test_new_collisions_choose_repair(): void
    {
        $result = $this->arbiter()->arbitrate([
            'health_status' => 'healthy',
            'new_collisions' => ['target_a'],
        ]);

        $this->assertSame('repair', $result['decision']);
    }

    // ── AC: clean high-drain facts choose expansion ──

    public function test_clean_high_drain_chooses_expansion(): void
    {
        $result = $this->arbiter()->arbitrate([
            'health_status' => 'healthy',
            'malformed_blockers' => [],
            'new_collisions' => [],
            'give_back_rate' => 0.1,
            'success_rate' => 0.8,
            'drain_rate' => 0.7,
        ]);

        $this->assertSame('expansion', $result['decision']);
    }

    // ── AC: mixed facts choose consolidation ──

    public function test_mixed_facts_choose_consolidation(): void
    {
        $result = $this->arbiter()->arbitrate([
            'health_status' => 'healthy',
            'malformed_blockers' => [],
            'new_collisions' => [],
            'give_back_rate' => 0.4,
            'success_rate' => 0.5,
            'drain_rate' => 0.3,
        ]);

        $this->assertSame('consolidation', $result['decision']);
    }

    public function test_high_give_back_chooses_consolidation(): void
    {
        $result = $this->arbiter()->arbitrate([
            'health_status' => 'healthy',
            'give_back_rate' => 0.5,
            'success_rate' => 0.3,
            'drain_rate' => 0.6,
        ]);

        $this->assertSame('consolidation', $result['decision']);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->arbiter()->arbitrate([]);

        $this->assertSame(AtlasMaestroExpansionVersusRepairArbiter::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('decision', $result);
        $this->assertArrayHasKey('reasons', $result);
    }

    public function test_result_is_deterministic(): void
    {
        $input = ['health_status' => 'healthy', 'drain_rate' => 0.7, 'success_rate' => 0.8, 'give_back_rate' => 0.1];

        $a = $this->arbiter()->arbitrate($input);
        $b = $this->arbiter()->arbitrate($input);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
