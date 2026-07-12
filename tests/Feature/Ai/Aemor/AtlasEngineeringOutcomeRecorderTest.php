<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Aemor;

use App\Models\AtlasAaeosTestRunReceipt;
use App\Services\Ai\AtlasDecide\AtlasDecideLiveOutcomeFeedbackService;
use App\Services\Ai\Aemor\AtlasEngineeringOutcomeRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesAemorTables;
use Tests\TestCase;

final class AtlasEngineeringOutcomeRecorderTest extends TestCase
{
    use CreatesAemorTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAemorTables();
        (require database_path('migrations/2026_07_09_153500_repair_missing_ai_memory_deltas_table.php'))->up();
        if (! Schema::hasTable('atlas_aaeos_test_run_receipts')) {
            (require database_path('migrations/2026_06_02_090000_create_atlas_aaeos_test_run_receipts_table.php'))->up();
        }
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_memory_deltas');
        Schema::dropIfExists('atlas_aaeos_test_run_receipts');
        $this->dropAemorTables();
        parent::tearDown();
    }

    public function test_successful_engineering_outcome_uses_one_aemor_envelope_and_stays_pending_review(): void
    {
        $receipt = $this->greenTestReceipt();

        $result = app(AtlasEngineeringOutcomeRecorder::class)->record([
            'executor' => 'autonomos',
            'objective' => 'Land a scoped change with evidence.',
            'workspace' => base_path(),
            'status' => 'succeeded',
            'summary' => 'Scoped change passed verification.',
            // FEE-02: caller metric claims are ignored unless evidence resolves
            // a green test_run_receipt (or ledger verification).
            'evidence_refs' => ['test_run_receipt:'.$receipt->id, 'commit:abc'],
            'metrics' => ['tests_passed' => true, 'attribution_reviewed' => true],
            'context_utility' => ['helpful_sources' => ['memory'], 'missing_sources' => []],
            'learning_claim' => 'Scoped commits with server-side verification prevent unrelated work from landing.',
        ]);

        $this->assertSame('recorded', $result['status']);
        $this->assertSame('succeeded', data_get($result, 'outcome.status'));
        $this->assertSame('passed', data_get($result, 'judgment.status'));
        $this->assertSame('candidate', data_get($result, 'learning.status'));
        $this->assertDatabaseCount('atlas_aemor_execution_episodes', 1);
        $this->assertDatabaseCount('atlas_aemor_outcomes', 1);
        $this->assertDatabaseCount('atlas_aemor_judgment_reports', 1);
        $this->assertDatabaseCount('ai_memory_deltas', 1);
        $this->assertSame('pending', DB::table('ai_memory_deltas')->value('status'));
        $this->assertTrue((bool) DB::table('ai_memory_deltas')->value('requires_confirmation'));
    }

    public function test_success_without_evidence_is_blocked_before_opening_an_episode(): void
    {
        $result = app(AtlasEngineeringOutcomeRecorder::class)->record([
            'executor' => 'forge',
            'objective' => 'Claim success without proof.',
            'status' => 'succeeded',
            'summary' => 'No evidence.',
            'evidence_refs' => [],
        ]);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('evidence_required', $result['reason']);
        $this->assertDatabaseCount('atlas_aemor_execution_episodes', 0);
    }

    public function test_offline_rollout_is_a_noop_without_writes(): void
    {
        config()->set('atlas.aemor.engineering_outcome_enabled', false);

        $result = app(AtlasEngineeringOutcomeRecorder::class)->record([
            'executor' => 'dev',
            'objective' => 'Should not write.',
            'status' => 'succeeded',
            'evidence_refs' => ['test:green'],
        ]);

        $this->assertSame('offline', $result['status']);
        $this->assertFalse((bool) ($result['writes'] ?? true));
        $this->assertDatabaseCount('atlas_aemor_execution_episodes', 0);
    }

    public function test_shadow_rollout_records_but_skips_distill(): void
    {
        config()->set('atlas.aemor.engineering_outcome_enabled', true);
        config()->set('atlas.aemor.engineering_outcome_mode', 'shadow');

        $result = app(AtlasEngineeringOutcomeRecorder::class)->record([
            'executor' => 'forge',
            'objective' => 'Shadow outcome with claim.',
            'workspace' => base_path(),
            'status' => 'succeeded',
            'summary' => 'Shadow path.',
            'evidence_refs' => ['test:green'],
            'metrics' => ['tests_passed' => true, 'attribution_reviewed' => true],
            'learning_claim' => 'Should not distill in shadow.',
        ]);

        $this->assertSame('recorded', $result['status']);
        $this->assertSame('shadow', data_get($result, 'rollout.mode'));
        $this->assertSame('shadow_skipped', data_get($result, 'learning.status'));
        $this->assertDatabaseCount('atlas_aemor_execution_episodes', 1);
        $this->assertDatabaseCount('ai_memory_deltas', 0);
    }

    public function test_multx03_missing_verified_is_fail_closed_in_outcome_spine(): void
    {
        $feedback = new AtlasDecideLiveOutcomeFeedbackService;
        $feedback->setLogPathForTesting(storage_path('framework/testing/multx03-live-outcomes.jsonl'));
        @unlink($feedback->logPath());
        app()->instance(AtlasDecideLiveOutcomeFeedbackService::class, $feedback);

        $result = app(AtlasEngineeringOutcomeRecorder::class)->record([
            'executor' => 'dev',
            'objective' => 'Passed claim without server verification flag.',
            'workspace' => base_path(),
            'status' => 'succeeded',
            'summary' => 'Caller omitted verified.',
            'evidence_refs' => ['test:green'],
            'metrics' => ['tests_passed' => true],
        ]);

        $line = json_decode((string) file_get_contents($feedback->logPath()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('recorded', $result['status']);
        $this->assertSame('atlas.engineering_outcome.v2', data_get($result, 'spine.outcome_contract_v2.schema_version'));
        $this->assertFalse(data_get($result, 'spine.outcome_contract_v2.verified'));
        $this->assertSame('absent', data_get($result, 'spine.outcome_contract_v2.verified_basis'));
        $this->assertFalse(data_get($result, 'spine.outcome_contract_v2.verified_source_present'));
        $this->assertSame('absent', $line['verified_basis'] ?? null);
        $this->assertFalse((bool) ($line['proven_real'] ?? true));
    }

    private function greenTestReceipt(): AtlasAaeosTestRunReceipt
    {
        return AtlasAaeosTestRunReceipt::query()->create([
            'capability_id' => 'aemor.recorder.green',
            'test_ref' => self::class.'::test_successful_engineering_outcome_uses_one_aemor_envelope_and_stays_pending_review',
            'filter' => 'test_successful_engineering_outcome_uses_one_aemor_envelope_and_stays_pending_review',
            'passed' => true,
            'tests_run' => 1,
            'exit_code' => 0,
            'metadata' => ['attribution_reviewed' => true],
            'ran_at' => now(),
        ]);
    }
}
