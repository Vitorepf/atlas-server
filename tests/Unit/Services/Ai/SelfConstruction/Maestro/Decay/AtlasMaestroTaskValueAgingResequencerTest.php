<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\Maestro\Decay;

use App\Services\Ai\SelfConstruction\Maestro\Decay\AtlasMaestroTaskValueAgingResequencer;
use Tests\TestCase;

final class AtlasMaestroTaskValueAgingResequencerTest extends TestCase
{
    private function resequencer(): AtlasMaestroTaskValueAgingResequencer
    {
        return new AtlasMaestroTaskValueAgingResequencer;
    }

    // ── AC: old low-value tasks are demoted ──

    public function test_old_low_value_tasks_demoted(): void
    {
        $result = $this->resequencer()->resequence([
            ['task_id' => 'old-low', 'age_days' => 30, 'value_score' => 0.1],
            ['task_id' => 'fresh', 'age_days' => 1, 'value_score' => 0.5],
        ]);

        $this->assertSame(['fresh', 'old-low'], $result['resequenced']);
        $this->assertSame('old_low_value_demoted', $result['enriched'][1]['adjustment']);
    }

    // ── AC: old high-value blockers are surfaced ──

    public function test_old_high_value_blockers_surfaced(): void
    {
        $result = $this->resequencer()->resequence([
            ['task_id' => 'normal', 'age_days' => 1, 'value_score' => 0.5],
            ['task_id' => 'old-blocker', 'age_days' => 30, 'value_score' => 0.9, 'is_blocker' => true],
        ]);

        $this->assertSame('old-blocker', $result['resequenced'][0]);
        $this->assertSame('old_high_value_blocker_surfaced', $result['enriched'][0]['adjustment']);
    }

    // ── AC: fresh critical repairs stay ahead ──

    public function test_fresh_critical_repairs_stay_ahead(): void
    {
        $result = $this->resequencer()->resequence([
            ['task_id' => 'old-blocker', 'age_days' => 30, 'value_score' => 0.9, 'is_blocker' => true],
            ['task_id' => 'fresh-critical', 'age_days' => 1, 'value_score' => 0.3, 'priority' => 'critical'],
        ]);

        $this->assertSame('fresh-critical', $result['resequenced'][0]);
        $this->assertSame('fresh_critical_stays_ahead', $result['enriched'][0]['adjustment']);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->resequencer()->resequence([]);

        $this->assertSame(AtlasMaestroTaskValueAgingResequencer::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('resequenced', $result);
        $this->assertArrayHasKey('enriched', $result);
        $this->assertArrayHasKey('total', $result);
    }

    public function test_result_is_deterministic(): void
    {
        $tasks = [
            ['task_id' => 'b', 'age_days' => 10, 'value_score' => 0.3],
            ['task_id' => 'a', 'age_days' => 10, 'value_score' => 0.3],
        ];

        $a = $this->resequencer()->resequence($tasks);
        $b = $this->resequencer()->resequence($tasks);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
