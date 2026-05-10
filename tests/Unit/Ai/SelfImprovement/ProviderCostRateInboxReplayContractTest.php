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

class ProviderCostRateInboxReplayContractTest extends TestCase
{
    public function test_inbox_action_replay_finding_caps_events_and_keeps_rate_completion_reviewable(): void
    {
        $replay = Mockery::mock(AtlasLedgerReplayService::class);
        $replay->shouldReceive('inboxActionReportForWindow')
            ->once()
            ->with(Mockery::type('object'), null, ['action' => 'configure_provider_cost_rates'])
            ->andReturn([
                'available' => true,
                'inbox_action_count' => 8,
                'provider_cost_rate_action_count' => 6,
                'provider_cost_rate_applied_count' => 0,
                'provider_cost_rate_provider_counts' => ['codex_cli' => 6],
                'provider_cost_rate_model_counts' => ['codex_cli:gpt-5.5' => 6],
                'action_counts' => ['configure_provider_cost_rates' => 6],
                'actor_type_counts' => ['operator_cli' => 6],
                'recommended_action_counts' => ['configure_provider_cost_rates' => 6],
                'review_signal' => [
                    'review_required' => true,
                    'recommended_action' => 'configure_provider_cost_rates',
                    'reasons' => ['configure_provider_cost_rates_action_without_applied_rate'],
                ],
                'recent_events' => collect(range(1, 6))
                    ->map(fn (int $index): array => [
                        'event_id' => "event-{$index}",
                        'envelope_id' => "envelope-{$index}",
                        'inbox_item_id' => "inbox-{$index}",
                        'action' => 'configure_provider_cost_rates',
                        'provider_cost_rate_provider' => 'codex_cli',
                        'provider_cost_rate_model' => 'gpt-5.5',
                        'provider_cost_rate_applied' => false,
                        'provider_cost_rate_input_microusd' => null,
                        'provider_cost_rate_output_microusd' => null,
                        'provider_cost_rate_currency' => 'USD',
                        'provider_cost_rate_effective_from' => '2026-05-01T00:00:00+00:00',
                        'provider_cost_rate_effective_until' => null,
                        'provider_cost_rate_id' => null,
                        'occurred_at' => "2026-05-0{$index}T00:00:00+00:00",
                    ])
                    ->all(),
            ]);

        $findings = $this->inboxActionReplayFindings($this->runtime($replay), 24, [
            'action' => 'configure_provider_cost_rates',
        ]);

        $this->assertCount(1, $findings);
        $finding = $findings[0];

        $this->assertSame('Completar rates de custo dos providers no Inbox', $finding['title']);
        $this->assertSame('atlas.self_improvement.inbox_action_replay_gap.v1', data_get($finding, 'metadata.schema_version'));
        $this->assertSame('configure_provider_cost_rates_action_without_applied_rate', data_get($finding, 'metadata.gap_type'));
        $this->assertSame('configure_provider_cost_rates', data_get($finding, 'metadata.review_signal.recommended_action'));
        $this->assertSame('configure_provider_cost_rates', data_get($finding, 'available_actions.0.id'));
        $this->assertSame('configure_provider_cost_rates', data_get($finding, 'metadata.available_actions.0.id'));
        $this->assertSame(['action' => 'configure_provider_cost_rates'], data_get($finding, 'metadata.filters'));
        $this->assertSame(6, data_get($finding, 'payload.provider_cost_rates.missing_applied_rate_count'));
        $this->assertCount(5, data_get($finding, 'source_refs'));
        $this->assertCount(5, data_get($finding, 'payload.provider_cost_rates.events'));
        $this->assertSame('event-1', data_get($finding, 'source_refs.0.event_id'));
        $this->assertSame('event-5', data_get($finding, 'source_refs.4.event_id'));
        $this->assertSame('codex_cli', data_get($finding, 'source_refs.0.provider'));
        $this->assertSame('gpt-5.5', data_get($finding, 'payload.provider_cost_rates.events.0.model'));
        $this->assertFalse((bool) data_get($finding, 'payload.provider_cost_rates.events.0.applied'));
        $this->assertNull(data_get($finding, 'payload.provider_cost_rates.events.0.rate_id'));
    }

    public function test_inbox_action_replay_ignores_provider_cost_rate_events_already_applied(): void
    {
        $replay = Mockery::mock(AtlasLedgerReplayService::class);
        $replay->shouldReceive('inboxActionReportForWindow')
            ->once()
            ->andReturn([
                'available' => true,
                'review_signal' => [
                    'review_required' => true,
                    'reasons' => ['configure_provider_cost_rates_action_without_applied_rate'],
                ],
                'recent_events' => [[
                    'event_id' => 'event-applied',
                    'action' => 'configure_provider_cost_rates',
                    'provider_cost_rate_applied' => true,
                ]],
            ]);

        $this->assertSame([], $this->inboxActionReplayFindings($this->runtime($replay), 24, [
            'action' => 'configure_provider_cost_rates',
        ]));
    }

    public function test_inbox_action_replay_requires_review_signal_for_provider_cost_rate_completion(): void
    {
        $replay = Mockery::mock(AtlasLedgerReplayService::class);
        $replay->shouldReceive('inboxActionReportForWindow')
            ->once()
            ->andReturn([
                'available' => true,
                'review_signal' => [
                    'review_required' => false,
                    'recommended_action' => 'configure_provider_cost_rates',
                    'reasons' => ['configure_provider_cost_rates_action_without_applied_rate'],
                ],
                'recent_events' => [[
                    'event_id' => 'event-preview-only',
                    'action' => 'configure_provider_cost_rates',
                    'provider_cost_rate_applied' => false,
                ]],
            ]);

        $this->assertSame([], $this->inboxActionReplayFindings($this->runtime($replay), 24, [
            'action' => 'configure_provider_cost_rates',
        ]));
    }

    public function test_inbox_action_replay_skips_provider_cost_rate_when_domain_filter_is_present(): void
    {
        $replay = Mockery::mock(AtlasLedgerReplayService::class);
        $replay->shouldNotReceive('inboxActionReportForWindow');

        $this->assertSame([], $this->inboxActionReplayFindings($this->runtime($replay), 24, [
            'action' => 'configure_provider_cost_rates',
            'domain' => 'programming',
        ]));
    }

    public function test_inbox_action_replay_passes_only_normalized_inbox_filters_to_provider_cost_rate_replay(): void
    {
        $replay = Mockery::mock(AtlasLedgerReplayService::class);
        $replay->shouldReceive('inboxActionReportForWindow')
            ->once()
            ->with(Mockery::type('object'), null, [
                'action' => 'configure_provider_cost_rates',
                'actor_type' => 'operator_cli',
            ])
            ->andReturn([
                'available' => false,
                'review_signal' => [],
            ]);

        $this->assertSame([], $this->inboxActionReplayFindings($this->runtime($replay), 24, [
            'action' => ' configure_provider_cost_rates ',
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
