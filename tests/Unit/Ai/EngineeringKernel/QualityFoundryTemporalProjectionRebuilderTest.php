<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel;

use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\EngineeringKernel\Quality\QualityFoundryTemporalProjectionRebuilder;
use PHPUnit\Framework\TestCase;

final class QualityFoundryTemporalProjectionRebuilderTest extends TestCase
{
    public function test_rebuild_keeps_unobserved_windows_unknown_and_is_deterministic(): void
    {
        $rebuilder = new QualityFoundryTemporalProjectionRebuilder;
        $events = [$this->outcomeEvent(), $this->observationEvent('0h', 'healthy')];

        $projection = $rebuilder->rebuild($events);

        self::assertSame('pending', $projection['temporal_state']);
        self::assertSame('observed', $projection['windows']['0h']['state']);
        self::assertSame('unknown', $projection['windows']['24h']['state']);
        self::assertSame($projection, $rebuilder->rebuild($events));
    }

    public function test_rebuild_marks_same_window_contradiction_without_rewriting_first_observation(): void
    {
        $rebuilder = new QualityFoundryTemporalProjectionRebuilder;
        $events = [
            $this->outcomeEvent(),
            $this->observationEvent('24h', 'healthy', 'observation-first'),
            $this->observationEvent('24h', 'regressed', 'observation-late'),
        ];

        $projection = $rebuilder->rebuild($events);

        self::assertSame('blocked', $projection['temporal_state']);
        self::assertSame('contradictory', $projection['windows']['24h']['state']);
        self::assertSame('healthy', $projection['windows']['24h']['observations'][0]['status']);
        self::assertSame('regressed', $projection['windows']['24h']['observations'][1]['status']);
        self::assertContains('temporal_observation_contradictory:24h', $projection['blockers']);
    }

    public function test_rebuild_quarantines_observation_context_mismatch(): void
    {
        $rebuilder = new QualityFoundryTemporalProjectionRebuilder;
        $observation = $this->observationEvent('0h', 'healthy');
        $observation['payload']['observation']['run_id'] = 'forged-run';

        $projection = $rebuilder->rebuild([$this->outcomeEvent(), $observation]);

        self::assertSame('blocked', $projection['temporal_state']);
        self::assertContains('temporal_observation_context_mismatch:0h:run_id', $projection['blockers']);
    }

    public function test_rebuild_from_ledger_uses_only_the_canonical_correlation_stream(): void
    {
        $ledger = $this->createMock(AtlasEvidenceLedger::class);
        $ledger->expects(self::once())
            ->method('eventsForCorrelation')
            ->with('delivery-1')
            ->willReturn([$this->outcomeEvent()]);

        $projection = (new QualityFoundryTemporalProjectionRebuilder)->rebuildFromLedger($ledger, 'delivery-1');

        self::assertSame('run-1', $projection['outcome']['run_id']);
        self::assertSame('pending', $projection['temporal_state']);
    }

    /** @return array<string,mixed> */
    private function outcomeEvent(): array
    {
        return [
            'event_id' => 'outcome-event',
            'event_hash' => hash('sha256', 'outcome-event'),
            'occurred_at' => '2026-07-10T00:00:00+00:00',
            'payload' => [
                'event_name' => 'engineering.outcome.recorded',
                'outcome' => [
                    'run_id' => 'run-1', 'delivery_id' => 'delivery-1', 'status' => 'released',
                    'outcome_hash' => hash('sha256', 'outcome'),
                    'correlated_hashes' => [
                        'order' => hash('sha256', 'order'), 'spec' => hash('sha256', 'spec'),
                        'world' => hash('sha256', 'world'), 'release' => hash('sha256', 'release'),
                    ],
                ],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function observationEvent(string $window, string $status, string $eventId = 'observation-event'): array
    {
        return [
            'event_id' => $eventId,
            'event_hash' => hash('sha256', $eventId),
            'occurred_at' => '2026-07-11T00:00:00+00:00',
            'payload' => [
                'event_name' => 'outcome.observed',
                'observation' => [
                    'window' => $window, 'observed_at' => '2026-07-11T00:00:00+00:00',
                    'metrics' => ['status' => $status],
                    'provenance' => [
                        'source' => 'test', 'release_at' => '2026-07-10T00:00:00+00:00',
                        'spec_hash' => hash('sha256', 'spec'), 'world_hash' => hash('sha256', 'world'),
                        'uncertainty' => [],
                    ],
                ],
                'observation_hash' => hash('sha256', $eventId),
            ],
        ];
    }
}
