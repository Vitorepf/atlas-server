<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Aemor;

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
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_memory_deltas');
        $this->dropAemorTables();
        parent::tearDown();
    }

    public function test_successful_engineering_outcome_uses_one_aemor_envelope_and_stays_pending_review(): void
    {
        $result = app(AtlasEngineeringOutcomeRecorder::class)->record([
            'executor' => 'autonomos',
            'objective' => 'Land a scoped change with evidence.',
            'workspace' => base_path(),
            'status' => 'succeeded',
            'summary' => 'Scoped change passed verification.',
            'evidence_refs' => ['test:green', 'commit:abc'],
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
}
