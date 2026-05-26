<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AtlasForge;

use App\Services\Ai\AtlasForge\AtlasObraDeterministicReplayService;
use PHPUnit\Framework\TestCase;

final class AtlasObraDeterministicReplayServiceTest extends TestCase
{
    public function test_replay_of_empty_event_list_returns_empty_state(): void
    {
        $svc = new AtlasObraDeterministicReplayService();

        $result = $svc->replay([]);

        self::assertSame(AtlasObraDeterministicReplayService::SCHEMA_VERSION, $result['schema']);
        self::assertSame([], $result['phases_executed']);
        self::assertSame([], $result['decisions']);
        self::assertSame(0, $result['counts']['events']);
        self::assertSame('', $result['final_state']['last_phase_out']);
    }

    public function test_replay_accumulates_phases_in_order(): void
    {
        $svc = new AtlasObraDeterministicReplayService();

        $events = [
            ['phase_out' => 'intent_capture'],
            ['phase_out' => 'placement'],
            ['phase_out' => 'classification'],
        ];

        $result = $svc->replay($events);

        self::assertSame(
            ['intent_capture', 'placement', 'classification'],
            $result['phases_executed'],
        );
        self::assertSame('classification', $result['final_state']['last_phase_out']);
    }

    public function test_replay_tracks_open_blockers(): void
    {
        $svc = new AtlasObraDeterministicReplayService();

        $events = [
            ['phase_out' => 'placement', 'blockers' => [['id' => 'missing_owner_doc']]],
            ['phase_out' => 'policy_gate', 'blockers' => [['id' => 'security_review_pending']]],
        ];

        $result = $svc->replay($events);

        self::assertContains('missing_owner_doc', $result['final_state']['blockers_open']);
        self::assertContains('security_review_pending', $result['final_state']['blockers_open']);
        self::assertSame(2, $result['counts']['blockers_open_at_end']);
    }

    public function test_replay_captures_decision_lineage(): void
    {
        $svc = new AtlasObraDeterministicReplayService();

        $events = [
            ['phase_out' => 'placement'],
            ['phase_out' => 'receipt', 'decision_id' => 'dec-001'],
            ['phase_out' => 'execution', 'decision_id' => 'dec-001'],
        ];

        $result = $svc->replay($events);

        self::assertArrayHasKey('dec-001', $result['decisions']);
        self::assertSame([1, 2], $result['decisions']['dec-001']);
    }

    public function test_lineage_helper_returns_event_chain(): void
    {
        $svc = new AtlasObraDeterministicReplayService();

        $events = [
            ['phase_out' => 'placement'],
            ['phase_out' => 'classification', 'decision_id' => 'dec-X'],
            ['phase_out' => 'execution', 'decision_id' => 'dec-X'],
        ];

        $lineage = $svc->lineage($events, 'dec-X');

        self::assertCount(2, $lineage);
        self::assertSame(1, $lineage[0]['event_index']);
        self::assertSame(2, $lineage[1]['event_index']);
    }

    public function test_lineage_for_unknown_decision_returns_empty(): void
    {
        $svc = new AtlasObraDeterministicReplayService();

        $lineage = $svc->lineage([['phase_out' => 'placement']], 'dec-unknown');

        self::assertSame([], $lineage);
    }

    public function test_replay_is_deterministic_for_identical_input(): void
    {
        $svc = new AtlasObraDeterministicReplayService();
        $events = [
            ['phase_out' => 'placement', 'decision_id' => 'd1'],
            ['phase_out' => 'execution', 'decision_id' => 'd1'],
        ];

        $a = $svc->replay($events)['replay_hash'];
        $b = $svc->replay($events)['replay_hash'];

        self::assertSame($a, $b);
    }

    public function test_replay_hash_changes_when_events_change(): void
    {
        $svc = new AtlasObraDeterministicReplayService();

        $a = $svc->replay([['phase_out' => 'placement']])['replay_hash'];
        $b = $svc->replay([['phase_out' => 'classification']])['replay_hash'];

        self::assertNotSame($a, $b);
    }

    public function test_state_at_index_provides_per_step_snapshot(): void
    {
        $svc = new AtlasObraDeterministicReplayService();

        $events = [
            ['phase_out' => 'placement'],
            ['phase_out' => 'classification'],
        ];

        $result = $svc->replay($events);

        self::assertSame(['placement'], $result['state_at_index'][0]['phases_executed']);
        self::assertSame(
            ['placement', 'classification'],
            $result['state_at_index'][1]['phases_executed'],
        );
    }
}
