<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Autonomy;

use App\Services\Ai\AtlasDecide\AtlasConductorRoutingMemory;
use App\Services\Ai\Autonomy\AtlasOperatorReviewDebtMeter;
use App\Services\Ai\Autonomy\AtlasWeeklyMemoryDigestService;
use App\Services\Ai\Cognition\Watchdog\Checks\OperatorReviewDebtWatchdogCheck;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class OperatorReviewDebtWatchdogCheckTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_watchdog_alerts_and_reports_ephemeral_slowdown_when_queue_age_exceeds_freeze_cap(): void
    {
        Carbon::setTestNow('2026-07-12 00:00:00');
        config(['atlas.ai.autonomous_learning.limit' => 50]);

        $log = sys_get_temp_dir().'/atlas-review-debt-'.bin2hex(random_bytes(4)).'.jsonl';
        $routing = new AtlasConductorRoutingMemory();
        $routing->setLogPathForTesting($log);
        file_put_contents($log.'.preferred.jsonl', json_encode([
            'action' => 'set',
            'task_category' => 'code',
            'role' => 'dev',
            'provider' => 'hermes_cli',
            'model' => 'x',
            'recorded_at' => '2026-07-01T00:00:00+00:00',
        ], JSON_THROW_ON_ERROR).PHP_EOL);

        $check = new OperatorReviewDebtWatchdogCheck(new AtlasWeeklyMemoryDigestService($routing));
        $result = $check->run()->toArray();

        $this->assertSame('alert', $result['status']);
        $this->assertSame('elev-25.operator_review_debt', $result['alert']['code'] ?? null);
        $this->assertSame(AtlasOperatorReviewDebtMeter::MEASURE_ID, $result['evidence']['measure_id'] ?? null);
        $this->assertSame(11, $result['evidence']['metrics']['idade_max_da_fila']['value_days'] ?? null);
        $this->assertSame(AtlasOperatorReviewDebtMeter::SLOWED_AUTO_APPLY_LIMIT, $result['evidence']['cadence']['next_cycle_effective_limit'] ?? null);
        $this->assertTrue($result['evidence']['cadence']['never_becomes_approval_queue'] ?? false);

        @unlink($log.'.preferred.jsonl');
    }
}
