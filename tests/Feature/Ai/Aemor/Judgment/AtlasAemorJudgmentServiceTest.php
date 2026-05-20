<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Aemor\Judgment;

use App\Models\AtlasAemorJudgmentReport;
use App\Services\Ai\Aemor\AtlasAemorJudgmentService;
use App\Services\Ai\Aemor\AtlasAemorRuntimeService;
use Tests\Concerns\CreatesAemorTables;
use Tests\TestCase;

final class AtlasAemorJudgmentServiceTest extends TestCase
{
    use CreatesAemorTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAemorTables();
    }

    protected function tearDown(): void
    {
        $this->dropAemorTables();
        parent::tearDown();
    }

    public function test_judgment_passes_when_outcome_has_evidence_tests_and_attribution(): void
    {
        $runtime = app(AtlasAemorRuntimeService::class);
        $episode = $runtime->openEpisode(['objective' => 'green patch', 'evidence_refs' => ['apcr:e1']]);
        $runtime->closeOutcome([
            'episode_id' => $episode['episode_id'],
            'status' => 'succeeded',
            'summary' => 'Patch passed with tests.',
            'metrics' => ['tests_passed' => true, 'attribution_reviewed' => true],
            'context_utility' => ['helpful_sources' => ['doc:a'], 'missing_sources' => []],
            'evidence_refs' => ['test:green'],
        ]);

        $judgment = app(AtlasAemorJudgmentService::class)->judge((string) $episode['episode_id']);

        $this->assertSame(AtlasAemorJudgmentService::SCHEMA_VERSION, $judgment['schema_version']);
        $this->assertSame('passed', $judgment['status']);
        $this->assertSame('pass', data_get($judgment, 'false_learning_gate.status'));
        $this->assertSame('atlas.aemor.outcome_attribution.v1', data_get($judgment, 'outcome_attribution.schema_version'));
        $this->assertSame('atlas.aemor.provider_skill_reliability.v1', data_get($judgment, 'provider_skill_reliability.schema_version'));
        $this->assertSame('atlas.aemor.counterfactual_replay.v1', data_get($judgment, 'counterfactual_replay.schema_version'));
        $this->assertSame('atlas.aemor.memory_budget.v1', data_get($judgment, 'memory_budget.schema_version'));
        $this->assertSame('atlas.aemor.operational_doctrine.v1', data_get($judgment, 'operational_doctrine.schema_version'));
        $this->assertNotEmpty($judgment['judgment_hash']);
        $this->assertDatabaseCount('atlas_aemor_judgment_reports', 1);
        $report = AtlasAemorJudgmentReport::query()->firstOrFail();
        $this->assertSame($judgment['judgment_hash'], $report->judgment_hash);
        $this->assertSame('atlas.aemor.outcome_attribution.v1', data_get($report->outcome_attribution, 'schema_version'));
    }

    public function test_false_learning_gate_blocks_success_without_test_evidence(): void
    {
        $runtime = app(AtlasAemorRuntimeService::class);
        $episode = $runtime->openEpisode(['objective' => 'lucky patch']);
        $runtime->closeOutcome([
            'episode_id' => $episode['episode_id'],
            'status' => 'succeeded',
            'summary' => 'Patch claimed success but no tests.',
            'metrics' => ['tests_passed' => false],
            'evidence_refs' => ['manual:claim'],
        ]);

        $judgment = app(AtlasAemorJudgmentService::class)->judge((string) $episode['episode_id']);

        $this->assertSame('watch', $judgment['status']);
        $this->assertSame('blocked_for_learning', data_get($judgment, 'false_learning_gate.status'));
        $this->assertContains('success_without_test_or_gate_evidence', data_get($judgment, 'false_learning_gate.blockers'));
        $this->assertSame('candidate', data_get($judgment, 'negative_knowledge.status'));
        $this->assertSame('simulated', data_get($judgment, 'counterfactual_replay.status'));
    }

    public function test_repeated_failure_suppression_blocks_third_same_failure(): void
    {
        $runtime = app(AtlasAemorRuntimeService::class);
        for ($i = 0; $i < 3; $i++) {
            $episode = $runtime->openEpisode(['objective' => 'youtube ingestion failure '.$i]);
            $runtime->closeOutcome([
                'episode_id' => $episode['episode_id'],
                'status' => 'failed',
                'summary' => 'YouTube ingestion ignored url attachments.',
                'failure_signature' => 'youtube_ingestion_url_attachments_ignored',
                'metrics' => ['tests_passed' => false, 'attribution_reviewed' => true],
                'evidence_refs' => ['test:youtube:'.$i],
            ]);
        }

        $judgment = app(AtlasAemorJudgmentService::class)->judge((string) $episode['episode_id']);

        $this->assertSame('blocked', data_get($judgment, 'repeated_failure_suppression.status'));
        $this->assertContains('include_negative_knowledge_in_apcr', data_get($judgment, 'repeated_failure_suppression.required_mitigation'));
        $this->assertSame('candidate', data_get($judgment, 'negative_knowledge.status'));
        $this->assertContains('requires_human_or_compounding_review', [data_get($judgment, 'negative_knowledge.promotion_policy')]);
        $this->assertSame('candidate', data_get($judgment, 'operational_doctrine.status'));
        $this->assertNotEmpty($judgment['policy_proposals']);
    }

    public function test_risk_predict_finds_similar_prior_failure(): void
    {
        $runtime = app(AtlasAemorRuntimeService::class);
        $episode = $runtime->openEpisode(['objective' => 'youtube bug']);
        $runtime->closeOutcome([
            'episode_id' => $episode['episode_id'],
            'status' => 'failed',
            'summary' => 'YouTube upload status did not sync.',
            'failure_signature' => 'youtube_sync_failed',
            'evidence_refs' => ['test:youtube'],
        ]);

        $risk = app(AtlasAemorJudgmentService::class)->riskPredict('corrigir youtube sync');

        $this->assertSame('watch', $risk['status']);
        $this->assertNotEmpty($risk['similar_failures']);
        $this->assertContains('run_targeted_tests_before_completion', $risk['required_mitigations']);
    }

    public function test_judgment_emits_reliability_budget_and_doctrine_for_human_corrected_outcome(): void
    {
        $runtime = app(AtlasAemorRuntimeService::class);
        $episode = $runtime->openEpisode([
            'objective' => 'review forge handoff',
            'domain' => 'programming',
            'flow_id' => 'atlas_forge',
            'provider' => 'claude_cli',
            'evidence_refs' => ['handoff:input'],
        ]);
        $runtime->closeOutcome([
            'episode_id' => $episode['episode_id'],
            'status' => 'succeeded',
            'summary' => 'Forge handoff reviewed and tests passed.',
            'metrics' => [
                'tests_passed' => true,
                'attribution_reviewed' => true,
                'human_correction' => 'Always include Forge handoff hash before final certification.',
            ],
            'patch_outcome' => ['files_changed' => 2, 'tests_run' => 3, 'repair_loops' => 0, 'rollback_available' => true],
            'evidence_refs' => ['test:forge-handoff'],
        ]);

        $judgment = app(AtlasAemorJudgmentService::class)->judge((string) $episode['episode_id']);

        $this->assertSame('claude_cli', data_get($judgment, 'provider_skill_reliability.provider'));
        $this->assertSame('atlas_forge', data_get($judgment, 'provider_skill_reliability.flow_id'));
        $this->assertSame('positive', data_get($judgment, 'provider_skill_reliability.signal'));
        $this->assertSame('within_budget', data_get($judgment, 'memory_budget.status'));
        $this->assertSame('candidate', data_get($judgment, 'operational_doctrine.status'));
        $this->assertSame('candidate', data_get($judgment, 'human_correction.status'));
        $this->assertSame('normal', data_get($judgment, 'patch_quality_fingerprint.risk'));
    }
}
