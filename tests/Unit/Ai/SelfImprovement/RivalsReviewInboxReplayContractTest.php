<?php

namespace Tests\Unit\Ai\SelfImprovement;

use App\Services\Ai\Cognitive\ProductiveFailure\ProductiveFailureSessionRepository;
use App\Services\Ai\Context\LocalRagBenchmarkService;
use App\Services\Ai\Kernel\Architecture\AtlasAiArchitectureValidationService;
use App\Services\Ai\Kernel\Architecture\AtlasArchitectureOperationsCatalog;
use App\Services\Ai\Kernel\Architecture\AtlasRivalsStrategyReadModel;
use App\Services\Ai\Kernel\Decision\DynamicComputeMarketAdvisor;
use App\Services\Ai\Kernel\Domain\AtlasAiDomainCatalogService;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\AtlasLedgerReplayService;
use App\Services\Ai\Kernel\Evidence\ProviderPerformanceProjection;
use App\Services\Ai\Mobile\ProposalInboxEmitter;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementInput;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementRuntime;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementScheduleService;
use App\Services\Ai\Voice\AtlasVoiceRivalsRunner;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class RivalsReviewInboxReplayContractTest extends TestCase
{
    public function test_inbox_action_replay_finding_caps_rivals_review_events_missing_scores(): void
    {
        $replay = Mockery::mock(AtlasLedgerReplayService::class);
        $replay->shouldReceive('inboxActionReportForWindow')
            ->once()
            ->with(Mockery::type('object'), null, ['action' => 'record_rivals_review'])
            ->andReturn([
                'available' => true,
                'inbox_action_count' => 7,
                'rivals_review_recorded_count' => 6,
                'rivals_review_with_scores_count' => 0,
                'action_counts' => ['record_rivals_review' => 6],
                'actor_type_counts' => ['operator_cli' => 6],
                'recommended_action_counts' => ['record_due_rivals_strategy_reviews' => 6],
                'review_signal' => [
                    'review_required' => true,
                    'recommended_action' => 'open_reviewable_inbox_action_evidence_proposal',
                    'reasons' => ['record_rivals_review_action_without_scores'],
                ],
                'recent_events' => collect(range(1, 6))
                    ->map(fn (int $index): array => [
                        'event_id' => "rivals-event-{$index}",
                        'envelope_id' => "rivals-envelope-{$index}",
                        'inbox_item_id' => "rivals-inbox-{$index}",
                        'action' => 'record_rivals_review',
                        'actor_type' => 'operator_cli',
                        'recommended_action' => 'record_due_rivals_strategy_reviews',
                        'rivals_review_id' => "review-{$index}",
                        'rivals_case_id' => "case-{$index}",
                        'rivals_regret_score' => null,
                        'rivals_alignment_score' => 8,
                        'rivals_agency_score' => 9,
                        'occurred_at' => "2026-05-0{$index}T00:00:00+00:00",
                    ])
                    ->all(),
            ]);

        $findings = $this->inboxActionReplayFindings($this->runtime($replay), 24, [
            'action' => 'record_rivals_review',
        ]);

        $this->assertCount(1, $findings);
        $finding = $findings[0];

        $this->assertSame('Corrigir revisao do Rivals Strategy sem scores humanos', $finding['title']);
        $this->assertSame('atlas.self_improvement.inbox_action_replay_gap.v1', data_get($finding, 'metadata.schema_version'));
        $this->assertSame('record_rivals_review_action_without_scores', data_get($finding, 'metadata.gap_type'));
        $this->assertSame(6, data_get($finding, 'metadata.rivals_review_recorded_count'));
        $this->assertSame(0, data_get($finding, 'metadata.rivals_review_with_scores_count'));
        $this->assertSame(['action' => 'record_rivals_review'], data_get($finding, 'metadata.filters'));
        $this->assertCount(5, data_get($finding, 'source_refs'));
        $this->assertSame('rivals-event-1', data_get($finding, 'source_refs.0.id'));
        $this->assertSame('rivals-event-5', data_get($finding, 'source_refs.4.id'));
        $this->assertSame('record_rivals_review', data_get($finding, 'source_refs.0.action'));
        $this->assertSame('review-1', data_get($finding, 'source_refs.0.rivals_review_id'));
        $this->assertSame('case-1', data_get($finding, 'source_refs.0.rivals_case_id'));
        $this->assertNull(data_get($finding, 'source_refs.0.rivals_regret_score'));
        $this->assertSame(8, data_get($finding, 'source_refs.0.rivals_alignment_score'));
        $this->assertSame(9, data_get($finding, 'source_refs.0.rivals_agency_score'));
    }

    public function test_inbox_action_replay_ignores_rivals_review_events_with_all_scores(): void
    {
        $replay = Mockery::mock(AtlasLedgerReplayService::class);
        $replay->shouldReceive('inboxActionReportForWindow')
            ->once()
            ->andReturn([
                'available' => true,
                'review_signal' => [
                    'review_required' => true,
                    'recommended_action' => 'open_reviewable_inbox_action_evidence_proposal',
                    'reasons' => ['record_rivals_review_action_without_scores'],
                ],
                'recent_events' => [[
                    'event_id' => 'rivals-scored-event',
                    'action' => 'record_rivals_review',
                    'rivals_regret_score' => 7,
                    'rivals_alignment_score' => 8,
                    'rivals_agency_score' => 9,
                ]],
            ]);

        $this->assertSame([], $this->inboxActionReplayFindings($this->runtime($replay), 24, [
            'action' => 'record_rivals_review',
        ]));
    }

    public function test_inbox_action_replay_requires_review_signal_for_rivals_review_score_completion(): void
    {
        $replay = Mockery::mock(AtlasLedgerReplayService::class);
        $replay->shouldReceive('inboxActionReportForWindow')
            ->once()
            ->andReturn([
                'available' => true,
                'review_signal' => [
                    'review_required' => false,
                    'recommended_action' => 'open_reviewable_inbox_action_evidence_proposal',
                    'reasons' => ['record_rivals_review_action_without_scores'],
                ],
                'recent_events' => [[
                    'event_id' => 'rivals-preview-only',
                    'action' => 'record_rivals_review',
                    'rivals_regret_score' => null,
                    'rivals_alignment_score' => 8,
                    'rivals_agency_score' => 9,
                ]],
            ]);

        $this->assertSame([], $this->inboxActionReplayFindings($this->runtime($replay), 24, [
            'action' => 'record_rivals_review',
        ]));
    }

    public function test_inbox_action_replay_skips_rivals_review_when_domain_filter_is_present(): void
    {
        $replay = Mockery::mock(AtlasLedgerReplayService::class);
        $replay->shouldNotReceive('inboxActionReportForWindow');

        $this->assertSame([], $this->inboxActionReplayFindings($this->runtime($replay), 24, [
            'action' => 'record_rivals_review',
            'domain' => 'programming',
        ]));
    }

    public function test_inbox_action_replay_passes_only_normalized_inbox_filters_to_rivals_review_replay(): void
    {
        $replay = Mockery::mock(AtlasLedgerReplayService::class);
        $replay->shouldReceive('inboxActionReportForWindow')
            ->once()
            ->with(Mockery::type('object'), null, [
                'action' => 'record_rivals_review',
                'actor_type' => 'operator_cli',
            ])
            ->andReturn([
                'available' => false,
                'review_signal' => [],
            ]);

        $this->assertSame([], $this->inboxActionReplayFindings($this->runtime($replay), 24, [
            'action' => ' record_rivals_review ',
            'actor_type' => ' operator_cli ',
            'provider' => 'codex_cli',
            'ignored' => 'drop-me',
        ]));
    }

    /**
     * @param  array<string,string|null>  $filters
     * @return array<int,array<string,mixed>>
     */
    private function inboxActionReplayFindings(AtlasSelfImprovementRuntime $runtime, int $hours, array $filters): array
    {
        $method = new ReflectionMethod($runtime, 'inboxActionReplayFindings');
        $method->setAccessible(true);

        return $method->invoke($runtime, $hours, $filters);
    }

    private function runtime(AtlasLedgerReplayService $replay): AtlasSelfImprovementRuntime
    {
        return new AtlasSelfImprovementRuntime(
            Mockery::mock(AtlasEvidenceLedger::class),
            $replay,
            Mockery::mock(ProposalInboxEmitter::class),
            Mockery::mock(AtlasAiDomainCatalogService::class),
            Mockery::mock(AtlasAiArchitectureValidationService::class),
            app(AtlasArchitectureOperationsCatalog::class),
            Mockery::mock(AtlasSelfImprovementScheduleService::class),
            new AtlasSelfImprovementInput,
            Mockery::mock(ProviderPerformanceProjection::class),
            Mockery::mock(DynamicComputeMarketAdvisor::class),
            Mockery::mock(AtlasRivalsStrategyReadModel::class),
            app(AtlasVoiceRivalsRunner::class),
            app(LocalRagBenchmarkService::class),
            Mockery::mock(ProductiveFailureSessionRepository::class),
        );
    }
}
