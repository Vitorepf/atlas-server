<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Autonomy;

use App\Services\Ai\Autonomy\AtlasOperatorReviewDebtMeter;
use App\Services\Ai\AtlasDecide\AtlasConductorRoutingMemory;
use App\Services\Ai\Autonomy\AtlasWeeklyMemoryDigestService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * DB-free coverage of the Sunday digest read model. The DB-query path (counting
 * atlas_memory_entries / ai_compounding_memories) requires Postgres and is proven
 * live; these lock the read-model shape, the file-based applied-learnings logic, the
 * window clamp, and the no-throw guard when the tables are absent.
 */
class AtlasWeeklyMemoryDigestServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_digest_has_a_stable_read_model_shape(): void
    {
        $report = (new AtlasWeeklyMemoryDigestService())->digest(7);

        $this->assertSame(AtlasWeeklyMemoryDigestService::SCHEMA, $report['schema_version']);
        foreach (['memory_entries', 'compounding_memory', 'applied_learnings', 'auto_apply_safe', 'totals'] as $k) {
            $this->assertArrayHasKey($k, $report);
        }
        $this->assertSame(7, $report['window_days']);
        $this->assertIsInt($report['memory_entries']['count']);
        $this->assertIsInt($report['totals']['total_saved']);
    }

    public function test_window_is_clamped_to_a_sane_range(): void
    {
        $svc = new AtlasWeeklyMemoryDigestService();
        $this->assertSame(1, $svc->digest(0)['window_days']);
        $this->assertSame(365, $svc->digest(99999)['window_days']);
        $this->assertSame(30, $svc->digest(30)['window_days']);
    }

    public function test_applied_learnings_surfaces_recent_auto_applied_routes_with_a_reverse_handle(): void
    {
        $log = sys_get_temp_dir().'/atlas-pref-'.bin2hex(random_bytes(4)).'.jsonl';
        $routing = new AtlasConductorRoutingMemory();
        $routing->setLogPathForTesting($log);
        $routing->applyPreferred(['task_category' => 'code', 'role' => 'dev', 'provider' => 'hermes_cli', 'model' => 'gpt-5.5']);

        $applied = (new AtlasWeeklyMemoryDigestService($routing))->digest(7)['applied_learnings'];

        $this->assertGreaterThanOrEqual(1, $applied['count']);
        $item = collect($applied['items'])->firstWhere('task_category', 'code');
        $this->assertNotNull($item);
        $this->assertSame('hermes_cli', $item['provider']);
        $this->assertStringContainsString('meta-learning:deactivate', $item['reverse_handle']);

        @unlink($log.'.preferred.jsonl');
    }

    public function test_old_auto_applied_routes_fall_outside_the_window(): void
    {
        // A 'set' row stamped 30 days ago must NOT appear in a 7-day digest.
        $log = sys_get_temp_dir().'/atlas-pref-'.bin2hex(random_bytes(4)).'.jsonl';
        $routing = new AtlasConductorRoutingMemory();
        $routing->setLogPathForTesting($log);
        $old = now()->subDays(30)->toIso8601String();
        file_put_contents($log.'.preferred.jsonl', json_encode([
            'action' => 'set', 'task_category' => 'code', 'role' => 'dev',
            'provider' => 'hermes_cli', 'model' => 'x', 'recorded_at' => $old,
        ]).PHP_EOL);

        $applied = (new AtlasWeeklyMemoryDigestService($routing))->digest(7)['applied_learnings'];

        $this->assertSame(0, $applied['count']);
        @unlink($log.'.preferred.jsonl');
    }

    public function test_operator_review_debt_publishes_four_frozen_metrics_and_ephemeral_slow_cap(): void
    {
        Carbon::setTestNow('2026-07-12 00:00:00');
        config(['atlas.ai.autonomous_learning.limit' => 50]);

        $log = sys_get_temp_dir().'/atlas-pref-'.bin2hex(random_bytes(4)).'.jsonl';
        $routing = new AtlasConductorRoutingMemory();
        $routing->setLogPathForTesting($log);
        file_put_contents($log.'.preferred.jsonl', json_encode([
            'action' => 'set',
            'task_category' => 'code',
            'role' => 'dev',
            'provider' => 'hermes_cli',
            'model' => 'x',
            'recorded_at' => '2026-07-02T00:00:00+00:00',
        ], JSON_THROW_ON_ERROR).PHP_EOL);

        $debt = (new AtlasWeeklyMemoryDigestService($routing))->digest(14)['operator_review_debt'];

        $this->assertSame(AtlasOperatorReviewDebtMeter::MEASURE_ID, $debt['measure_id']);
        $this->assertSame([
            'itens_auto_aplicados_nao_revisados',
            'idade_max_da_fila',
            'itens_revisados_na_janela',
            'tempo_medio_inspecao',
        ], array_keys($debt['metrics']));
        $this->assertSame(1, $debt['metrics']['itens_auto_aplicados_nao_revisados']['value']);
        $this->assertSame(10, $debt['metrics']['idade_max_da_fila']['value_days']);
        $this->assertSame(AtlasOperatorReviewDebtMeter::MAX_QUEUE_AGE_DAYS, $debt['metrics']['idade_max_da_fila']['cap_days']);
        $this->assertSame('alert', $debt['status']);
        $this->assertTrue($debt['cadence']['auto_slowed']);
        $this->assertSame(50, $debt['cadence']['configured_auto_apply_limit']);
        $this->assertSame(AtlasOperatorReviewDebtMeter::SLOWED_AUTO_APPLY_LIMIT, $debt['cadence']['next_cycle_effective_limit']);
        $this->assertSame('ephemeral_safety_cap', $debt['cadence']['persistence']);
        $this->assertTrue($debt['cadence']['never_becomes_approval_queue']);

        @unlink($log.'.preferred.jsonl');
    }

    public function test_digest_does_not_throw_when_tables_are_absent(): void
    {
        // sqlite test DB has no atlas tables — the guard must yield empty sections, not crash.
        $report = (new AtlasWeeklyMemoryDigestService())->digest(7);
        $this->assertSame(0, $report['memory_entries']['count']);
        $this->assertArrayHasKey('note', $report['memory_entries']);
    }
}
