<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Autopoiesis\ScopeOrigination\AtlasLoopScopeOriginationProposer;
use App\Services\Ai\AutonomousEvolution\Autopoiesis\ScopeOrigination\LivingSignalSnapshot;
use Tests\TestCase;

final class AtlasLoopScopeOriginationProposerTest extends TestCase
{
    public function test_propose_returns_null_for_empty_snapshot_and_records_structured_abstain_reason(): void
    {
        $proposer = new AtlasLoopScopeOriginationProposer;

        $proposal = $proposer->propose($this->snapshot(
            operatorIntent: [],
            cortexMeaning: [],
            maestroOutcomes: [],
            loopTelemetry: [],
            coherence: null,
        ));

        $this->assertNull($proposal);
        $this->assertSame(
            [
                'kind' => 'no_fact_refs',
                'message' => 'No FACT, no proposal.',
                'snapshot_hash' => $this->snapshot(
                    operatorIntent: [],
                    cortexMeaning: [],
                    maestroOutcomes: [],
                    loopTelemetry: [],
                    coherence: null,
                )->toArray()['content_hash'],
            ],
            $proposer->lastAbstainReason()
        );
    }

    public function test_proposal_fact_refs_are_non_empty_and_reference_fact_ids_present_in_snapshot(): void
    {
        $snapshot = $this->snapshot(
            operatorIntent: [['fact_id' => 'op-1', 'target_paths' => ['app/Foo.php']]],
            cortexMeaning: [['fact_id' => 'ctx-1', 'target_paths' => ['app/Foo.php', 'app/Bar.php']]],
            maestroOutcomes: [['fact_id' => 'mae-1', 'target_path' => 'app/Baz.php']],
            loopTelemetry: [['fact_id' => 'loop-1', 'path' => 'app/Foo.php']],
            coherence: 0.5,
        );

        $proposal = (new AtlasLoopScopeOriginationProposer)->propose($snapshot);

        $this->assertNotNull($proposal);
        $payload = $proposal->toArray();
        $snapshotFactIds = $this->snapshotFactIds($snapshot);

        $this->assertNotEmpty($payload['fact_refs']);
        foreach ($payload['fact_refs'] as $factRef) {
            $this->assertContains($factRef['fact_id'], $snapshotFactIds);
        }
    }

    public function test_propose_is_deterministic_for_the_same_snapshot_and_policy(): void
    {
        $snapshot = $this->snapshot(
            operatorIntent: [['fact_id' => 'op-1', 'target_paths' => ['app/Foo.php']]],
            cortexMeaning: [['fact_id' => 'ctx-1', 'target_paths' => ['app/Bar.php']]],
            maestroOutcomes: [['fact_id' => 'mae-1', 'target_path' => 'app/Baz.php']],
            loopTelemetry: [['fact_id' => 'loop-1', 'path' => 'app/Foo.php']],
            coherence: 0.75,
        );
        $proposer = new AtlasLoopScopeOriginationProposer([
            'default_expected_leverage_signal' => 'coherence:unknown',
            'objective_prefix' => 'Originate concrete scope for',
        ]);

        $first = $proposer->propose($snapshot);
        $second = $proposer->propose($snapshot);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame($first->toArray()['content_hash'], $second->toArray()['content_hash']);
        $this->assertSame($first->toArray(), $second->toArray());
    }

    /**
     * @param  list<array<string,mixed>>  $operatorIntent
     * @param  list<array<string,mixed>>  $cortexMeaning
     * @param  list<array<string,mixed>>  $maestroOutcomes
     * @param  list<array<string,mixed>>  $loopTelemetry
     */
    private function snapshot(
        array $operatorIntent,
        array $cortexMeaning,
        array $maestroOutcomes,
        array $loopTelemetry,
        ?float $coherence,
    ): LivingSignalSnapshot {
        return new LivingSignalSnapshot([
            'coherence' => $coherence,
            'source_status' => [
                'operator_intent' => $operatorIntent === [] ? 'empty' : 'present',
                'cortex_meaning' => $cortexMeaning === [] ? 'empty' : 'present',
                'maestro_outcomes' => $maestroOutcomes === [] ? 'empty' : 'present',
                'loop_telemetry' => $loopTelemetry === [] ? 'empty' : 'present',
            ],
            'sources' => [
                'cortex_meaning' => $cortexMeaning,
                'loop_telemetry' => $loopTelemetry,
                'maestro_outcomes' => $maestroOutcomes,
                'operator_intent' => $operatorIntent,
            ],
            'window_end' => '2026-06-24T12:00:00+00:00',
            'window_start' => '2026-06-24T06:00:00+00:00',
        ]);
    }

    /**
     * @return list<string>
     */
    private function snapshotFactIds(LivingSignalSnapshot $snapshot): array
    {
        $factIds = [];

        foreach ($snapshot->toArray()['sources'] as $facts) {
            foreach ($facts as $fact) {
                $factIds[] = (string) ($fact['fact_id'] ?? '');
            }
        }

        return array_values(array_filter($factIds, static fn (string $factId): bool => $factId !== ''));
    }
}
