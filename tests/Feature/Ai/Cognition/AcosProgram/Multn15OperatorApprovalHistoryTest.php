<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Cognition\AcosProgram;

use App\Services\Ai\Cognition\AcosProgram\AcosMaxMeasureSeriesRegistry;
use App\Services\Ai\Autonomy\OperatorApprovalHistoryMeter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesOperatorApprovalTable;
use Tests\TestCase;

final class Multn15OperatorApprovalHistoryTest extends TestCase
{
    use CreatesOperatorApprovalTable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createOperatorApprovalTable();
    }

    protected function tearDown(): void
    {
        $this->dropOperatorApprovalTable();
        parent::tearDown();
    }

    public function test_empty_window_is_honest_insufficient_signal(): void
    {
        $exit = Artisan::call('atlas:operator-approval-history', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('insufficient_signal', $payload['status']);
        $this->assertSame('no_approvals', $payload['reason']);
        $this->assertSame([], $payload['cells']);
    }

    public function test_cells_publish_raw_counters_and_insufficient_n_below_frozen_floor(): void
    {
        foreach (range(1, 3) as $i) {
            DB::table('ai_operator_approvals')->insert($this->approval("a-$i", 'mission.deploy', 'high', 'approved', 'approved'));
        }
        foreach (range(1, 2) as $i) {
            DB::table('ai_operator_approvals')->insert($this->approval("d-$i", 'mission.deploy', 'high', 'denied', 'denied'));
        }

        Artisan::call('atlas:operator-approval-history', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $cell = $payload['cells'][0];
        $this->assertSame('operator.approval_history.v1', $payload['measure_id']);
        $this->assertSame('insufficient_signal', $payload['status']);
        $this->assertSame('insufficient_n', $cell['status']);
        $this->assertSame('mission.deploy', $cell['action_class']);
        $this->assertSame('high', $cell['risk_level']);
        $this->assertSame(5, $cell['n']);
        $this->assertSame(5, $cell['asks']);
        $this->assertSame(3, $cell['approved']);
        $this->assertSame(2, $cell['denied']);
        $this->assertSame(0, $cell['expired']);
        $this->assertSame(0, $cell['reused']);
    }

    public function test_multn15_02_series_is_registered_for_elev20s(): void
    {
        $entry = collect((new AcosMaxMeasureSeriesRegistry())->entries())
            ->firstWhere('slice', 'MULTN15-02');

        $this->assertSame(OperatorApprovalHistoryMeter::MEASURE_ID, $entry['series'] ?? null);
        $this->assertSame('command', $entry['source_type'] ?? null);
        $this->assertSame('freeze:operator.approval_history.v1', $entry['ttl_source'] ?? null);
    }

    /**
     * @return array<string,mixed>
     */
    private function approval(string $uuid, string $action, string $risk, string $status, ?string $decision): array
    {
        return [
            'id' => (string) str($uuid)->padRight(36, '0'),
            'uuid' => $uuid,
            'requested_action' => $action,
            'risk_level' => $risk,
            'gate_mode' => 'ask',
            'approval_required' => true,
            'reason' => 'test approval',
            'status' => $status,
            'operator_decision' => $decision,
            'hash' => hash('sha256', $uuid),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
