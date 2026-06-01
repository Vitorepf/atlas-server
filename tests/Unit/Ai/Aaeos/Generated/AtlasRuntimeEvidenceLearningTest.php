<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasRuntimeEvidenceLearningService;
use Tests\TestCase;

/**
 * Pins the documented Runtime / Evidence / Learning plane invariants.
 *
 * @see docs/engineering-knowledge-base/master-architecture/runtime-evidence-learning.md
 */
final class AtlasRuntimeEvidenceLearningTest extends TestCase
{
    private AtlasRuntimeEvidenceLearningService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AtlasRuntimeEvidenceLearningService;
    }

    public function test_scope_routes_to_owning_runtime_not_fashion(): void
    {
        // Laravel owns ledger/receipts/orchestration.
        $ledger = $this->service->routeRuntime('ledger');
        $this->assertTrue($ledger['routed']);
        $this->assertSame(AtlasRuntimeEvidenceLearningService::RUNTIME_LARAVEL, $ledger['runtime']);

        // Python owns AI/data/embeddings/agents.
        $this->assertSame(
            AtlasRuntimeEvidenceLearningService::RUNTIME_PYTHON,
            $this->service->routeRuntime('embeddings')['runtime']
        );

        // Go owns edge/streaming/webhooks.
        $this->assertSame(
            AtlasRuntimeEvidenceLearningService::RUNTIME_GO,
            $this->service->routeRuntime('webhooks')['runtime']
        );

        // Super Tool Runtime owns tools/gates/evidence.
        $this->assertSame(
            AtlasRuntimeEvidenceLearningService::RUNTIME_SUPER_TOOL,
            $this->service->routeRuntime('gates')['runtime']
        );
    }

    public function test_unknown_scope_is_not_guessed(): void
    {
        $verdict = $this->service->routeRuntime('something_not_documented');

        $this->assertFalse($verdict['routed']);
        $this->assertSame(AtlasRuntimeEvidenceLearningService::RUNTIME_UNPLACED, $verdict['runtime']);
        $this->assertSame('unknown_scope_not_guessed', $verdict['reason']);
    }

    public function test_evidence_plane_is_a_closed_vocabulary(): void
    {
        // All eight documented kinds are admitted.
        foreach (AtlasRuntimeEvidenceLearningService::EVIDENCE_EVENT_KINDS as $kind) {
            $this->assertTrue(
                $this->service->admitEvidence($kind)['admitted'],
                "documented evidence kind {$kind} must be admitted"
            );
        }

        // An undocumented kind is rejected.
        $rejected = $this->service->admitEvidence('random_event');
        $this->assertFalse($rejected['admitted']);
        $this->assertSame('unknown_evidence_kind', $rejected['reason']);
    }

    public function test_critical_learning_output_routes_to_proposal_review_never_auto_apply(): void
    {
        $critical = $this->service->classifyLearningOutput('repair_heuristic', true);

        $this->assertTrue($critical['valid_output']);
        $this->assertTrue($critical['requires_proposal_review']);
        $this->assertSame(
            AtlasRuntimeEvidenceLearningService::DISPOSITION_PROPOSAL_REVIEW,
            $critical['disposition']
        );
        $this->assertNotSame(
            AtlasRuntimeEvidenceLearningService::DISPOSITION_AUTO_APPLY,
            $critical['disposition'],
            'Learning must not silently mutate critical behavior'
        );
    }

    public function test_non_critical_learning_output_may_auto_apply(): void
    {
        $ok = $this->service->classifyLearningOutput('memory_signal', false);

        $this->assertTrue($ok['valid_output']);
        $this->assertFalse($ok['requires_proposal_review']);
        $this->assertSame(
            AtlasRuntimeEvidenceLearningService::DISPOSITION_AUTO_APPLY,
            $ok['disposition']
        );
    }

    public function test_curator_proposal_is_always_review_and_unknown_output_rejected(): void
    {
        // A curator_proposal is the review path itself even when non-critical.
        $proposal = $this->service->classifyLearningOutput('curator_proposal', false);
        $this->assertTrue($proposal['valid_output']);
        $this->assertTrue($proposal['requires_proposal_review']);

        // An output kind outside the six documented kinds is invalid.
        $bogus = $this->service->classifyLearningOutput('delete_kernel', false);
        $this->assertFalse($bogus['valid_output']);
        $this->assertSame(
            AtlasRuntimeEvidenceLearningService::DISPOSITION_REJECTED,
            $bogus['disposition']
        );
    }

    public function test_critical_change_cannot_reenter_runtime_without_reviewed_proposal(): void
    {
        // Critical + unreviewed => refused.
        $blocked = $this->service->mayReenterRuntime(true, false);
        $this->assertFalse($blocked['may_apply_to_runtime']);
        $this->assertSame('critical_behavior_needs_reviewed_proposal', $blocked['reason']);

        // Critical + reviewed proposal => allowed.
        $allowed = $this->service->mayReenterRuntime(true, true);
        $this->assertTrue($allowed['may_apply_to_runtime']);

        // Non-critical => allowed regardless of review.
        $this->assertTrue($this->service->mayReenterRuntime(false, false)['may_apply_to_runtime']);
    }

    public function test_action_summary_walks_all_three_planes(): void
    {
        $summary = $this->service->summarizeAction('orchestration', 'decision', 'repair_heuristic', true);

        $this->assertSame(AtlasRuntimeEvidenceLearningService::RUNTIME_LARAVEL, $summary['runtime']['runtime']);
        $this->assertTrue($summary['evidence']['admitted']);
        $this->assertSame(
            AtlasRuntimeEvidenceLearningService::DISPOSITION_PROPOSAL_REVIEW,
            $summary['learning']['disposition']
        );
        $this->assertTrue($summary['coherent']);

        // An unplaced scope makes the whole action incoherent.
        $incoherent = $this->service->summarizeAction('mystery', 'decision');
        $this->assertFalse($incoherent['coherent']);
        $this->assertNull($incoherent['learning']);
    }
}
