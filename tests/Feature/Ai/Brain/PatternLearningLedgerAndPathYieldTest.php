<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Brain;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainPatternLearningLedger;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class PatternLearningLedgerAndPathYieldTest extends TestCase
{
    private string $ledgerPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerPath = tempnam(sys_get_temp_dir(), 'atlas-pattern-ledger-').'.jsonl';
        @unlink($this->ledgerPath);
        config(['atlas.brain.pattern_learning_ledger' => $this->ledgerPath]);
        config(['atlas.brain.reflection_enabled' => true]);
    }

    protected function tearDown(): void
    {
        @unlink($this->ledgerPath);
        parent::tearDown();
    }

    public function test_reflection_enabled_defaults_true_in_config(): void
    {
        $this->assertTrue((bool) config('atlas.brain.reflection_enabled', false));
    }

    public function test_ledger_appends_valid_rows_and_rejects_incomplete(): void
    {
        $ledger = new AtlasBrainPatternLearningLedger($this->ledgerPath);

        $written = $ledger->append([
            'scope' => 'autonomos_land',
            'task_id' => 'task-001',
            'action_hint' => 'compound',
            'result_kind' => AtlasBrainPatternLearningLedger::RESULT_ACCEPTED,
            'evidence_refs' => ['file:foo.php'],
        ]);

        $this->assertNotNull($written);
        $this->assertSame('autonomos_land', $written['scope']);
        $this->assertSame(AtlasBrainPatternLearningLedger::SCHEMA, $written['schema']);

        $rejected = $ledger->append([
            'scope' => 'autonomos_land',
            'task_id' => '',
            'action_hint' => 'compound',
            'result_kind' => AtlasBrainPatternLearningLedger::RESULT_ACCEPTED,
        ]);
        $this->assertNull($rejected);

        $badKind = $ledger->append([
            'scope' => 'autonomos_land',
            'task_id' => 't1',
            'action_hint' => 'compound',
            'result_kind' => 'fabricated_kind',
        ]);
        $this->assertNull($badKind);

        $rows = $ledger->entries();
        $this->assertCount(1, $rows);
    }

    public function test_path_yield_command_reports_insufficient_signal_when_ledger_empty(): void
    {
        Artisan::call('atlas:brain:path-yield', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('insufficient_signal', $payload['status']);
        $this->assertSame([], (array) $payload['by_path']);
        $this->assertSame(0, $payload['source_counts']['joined_samples']);
    }

    public function test_path_yield_command_computes_ready_when_ledger_has_samples(): void
    {
        $ledger = new AtlasBrainPatternLearningLedger($this->ledgerPath);
        for ($i = 0; $i < 3; $i++) {
            $ledger->append([
                'scope' => 'autonomos_land',
                'task_id' => 'task-'.$i,
                'action_hint' => 'compound',
                'result_kind' => AtlasBrainPatternLearningLedger::RESULT_ACCEPTED,
                'evidence_refs' => [],
            ]);
        }
        for ($i = 0; $i < 2; $i++) {
            $ledger->append([
                'scope' => 'autonomos_land',
                'task_id' => 'task-blk-'.$i,
                'action_hint' => 'harvest_frontier',
                'result_kind' => AtlasBrainPatternLearningLedger::RESULT_ACCEPTED,
                'evidence_refs' => [],
            ]);
        }

        Artisan::call('atlas:brain:path-yield', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('ready', $payload['status']);
        $this->assertGreaterThanOrEqual(2, count($payload['by_path']));
    }
}
