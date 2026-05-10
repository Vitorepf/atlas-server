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

class InboxActionReplayDiffRefsContractTest extends TestCase
{
    public function test_inbox_action_replay_finding_caps_review_patch_events_without_diff_refs(): void
    {
        $replay = Mockery::mock(AtlasLedgerReplayService::class);
        $replay->shouldReceive('inboxActionReportForWindow')
            ->once()
            ->with(Mockery::type('object'), null, ['action' => 'review_patch'])
            ->andReturn([
                'available' => true,
                'inbox_action_count' => 7,
                'reviewed_patch_count' => 6,
                'with_diff_refs_count' => 0,
                'action_counts' => ['review_patch' => 6],
                'actor_type_counts' => ['operator_cli' => 6],
                'recommended_action_counts' => ['review_patch' => 6],
                'review_signal' => [
                    'review_required' => true,
                    'recommended_action' => 'open_reviewable_inbox_action_evidence_proposal',
                    'reasons' => ['review_patch_action_without_diff_refs'],
                ],
                'recent_events' => collect(range(1, 6))
                    ->map(fn (int $index): array => [
                        'event_id' => "review-event-{$index}",
                        'envelope_id' => "review-envelope-{$index}",
                        'inbox_item_id' => "review-inbox-{$index}",
                        'action' => 'review_patch',
                        'actor_type' => 'operator_cli',
                        'recommended_action' => 'review_patch',
                        'diff_ref_count' => 0,
                        'occurred_at' => "2026-05-0{$index}T00:00:00+00:00",
                    ])
                    ->all(),
            ]);

        $findings = $this->inboxActionReplayFindings($this->runtime($replay), 24, [
            'action' => 'review_patch',
        ]);

        $this->assertCount(1, $findings);
        $finding = $findings[0];

        $this->assertSame('Restaurar contexto de patch nas revisoes humanas do Inbox', $finding['title']);
        $this->assertSame('atlas.self_improvement.inbox_action_replay_gap.v1', data_get($finding, 'metadata.schema_version'));
        $this->assertSame(6, data_get($finding, 'metadata.reviewed_patch_count'));
        $this->assertSame(0, data_get($finding, 'metadata.with_diff_refs_count'));
        $this->assertSame(['action' => 'review_patch'], data_get($finding, 'metadata.filters'));
        $this->assertCount(5, data_get($finding, 'source_refs'));
        $this->assertSame('review-event-1', data_get($finding, 'source_refs.0.id'));
        $this->assertSame('review-event-5', data_get($finding, 'source_refs.4.id'));
        $this->assertSame('review_patch', data_get($finding, 'source_refs.0.action'));
        $this->assertSame(0, data_get($finding, 'source_refs.0.diff_ref_count'));
    }

    public function test_inbox_action_replay_ignores_review_patch_events_with_diff_refs(): void
    {
        $replay = Mockery::mock(AtlasLedgerReplayService::class);
        $replay->shouldReceive('inboxActionReportForWindow')
            ->once()
            ->andReturn([
                'available' => true,
                'review_signal' => [
                    'review_required' => true,
                    'recommended_action' => 'open_reviewable_inbox_action_evidence_proposal',
                    'reasons' => ['review_patch_action_without_diff_refs'],
                ],
                'recent_events' => [[
                    'event_id' => 'review-event-with-diff',
                    'action' => 'review_patch',
                    'diff_ref_count' => 2,
                ]],
            ]);

        $this->assertSame([], $this->inboxActionReplayFindings($this->runtime($replay), 24, [
            'action' => 'review_patch',
        ]));
    }

    public function test_inbox_action_replay_requires_reviewable_proposal_action_for_diff_ref_findings(): void
    {
        $replay = Mockery::mock(AtlasLedgerReplayService::class);
        $replay->shouldReceive('inboxActionReportForWindow')
            ->once()
            ->andReturn([
                'available' => true,
                'review_signal' => [
                    'review_required' => true,
                    'recommended_action' => 'apply_patch',
                    'reasons' => ['review_patch_action_without_diff_refs'],
                ],
                'recent_events' => [[
                    'event_id' => 'review-event-without-diff',
                    'action' => 'review_patch',
                    'diff_ref_count' => 0,
                ]],
            ]);

        $this->assertSame([], $this->inboxActionReplayFindings($this->runtime($replay), 24, [
            'action' => 'review_patch',
        ]));
    }

    public function test_inbox_action_replay_skips_review_patch_when_domain_filter_is_present(): void
    {
        $replay = Mockery::mock(AtlasLedgerReplayService::class);
        $replay->shouldNotReceive('inboxActionReportForWindow');

        $this->assertSame([], $this->inboxActionReplayFindings($this->runtime($replay), 24, [
            'action' => 'review_patch',
            'domain' => 'programming',
        ]));
    }

    public function test_inbox_action_replay_passes_only_normalized_filters_to_review_patch_replay(): void
    {
        $replay = Mockery::mock(AtlasLedgerReplayService::class);
        $replay->shouldReceive('inboxActionReportForWindow')
            ->once()
            ->with(Mockery::type('object'), null, [
                'action' => 'review_patch',
                'actor_type' => 'operator_cli',
                'recommended_action' => 'review_patch',
            ])
            ->andReturn([
                'available' => false,
                'review_signal' => [],
            ]);

        $this->assertSame([], $this->inboxActionReplayFindings($this->runtime($replay), 24, [
            'action' => ' review_patch ',
            'actor_type' => ' operator_cli ',
            'recommended_action' => ' review_patch ',
            'provider' => 'codex_cli',
            'status' => 'warning',
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
