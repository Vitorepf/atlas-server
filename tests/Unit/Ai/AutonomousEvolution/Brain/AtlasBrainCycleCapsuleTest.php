<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainCycleCapsule;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainCycleCapsuleLedger;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainInternalizationPipeline;
use PHPUnit\Framework\TestCase;

/**
 * V3 internalization substrate — Cycle Capsule (replayable per-cycle record) + Internalization Pipeline
 * (capsule → internal capability candidates, never auto-promoted). Freezes capture/validation, the ledger
 * roundtrip (replay), and the candidate derivation + the no-auto-promote invariant.
 */
final class AtlasBrainCycleCapsuleTest extends TestCase
{
    private function fullCycle(): array
    {
        return [
            'task_packet_id' => 'brain-pkt-x',
            'scope' => 'autonomous',
            'objective' => 'Wire the registry into origination',
            'prompt_receipt' => 'receipt-123',
            'spec' => ['allowed_files' => ['app/Foo.php']],
            'decision' => 'proceed',
            'provider' => 'claude-muscle',
            'files_touched' => ['app/Foo.php', 'tests/FooTest.php', 'app/Foo.php'], // dup collapses
            'evidence' => ['diff' => 'abc', 'tests_or_gates_result' => 'OK'],
            'validation' => ['certified' => true, 'reasons' => []],
            'metrics' => ['duration_s' => 9],
            'failures' => [],
            'learning' => 'mirrored test path raises throughput',
        ];
    }

    public function test_capture_requires_a_task_packet_id(): void
    {
        self::assertNull(AtlasBrainCycleCapsule::capture(['objective' => 'no id']));
    }

    public function test_capture_normalizes_and_carries_replayable_fields(): void
    {
        $c = AtlasBrainCycleCapsule::capture($this->fullCycle());
        self::assertNotNull($c);
        self::assertSame('atlas.brain.cycle_capsule.v1', $c['schema']);
        self::assertSame('brain-pkt-x', $c['task_packet_id']);
        self::assertTrue($c['certified']);
        self::assertSame(['app/Foo.php', 'tests/FooTest.php'], $c['files_touched'], 'dedups files');
        self::assertSame('proceed', $c['decision']);
        self::assertSame('claude-muscle', $c['provider']);
        self::assertSame('receipt-123', $c['prompt_receipt']);
    }

    public function test_ledger_records_and_replays_by_cycle(): void
    {
        $root = sys_get_temp_dir().'/atlas-capsule-test-'.getmypid();
        @array_map('unlink', glob($root.'/*.jsonl') ?: []);
        $ledger = new AtlasBrainCycleCapsuleLedger('autonomous', $root);
        self::assertNotNull($ledger->record($this->fullCycle()));
        self::assertNull($ledger->record(['objective' => 'no id']), 'unattributable cycle writes nothing');
        self::assertSame(1, $ledger->count());
        $replay = $ledger->forCycle('brain-pkt-x');
        self::assertCount(1, $replay);
        self::assertSame('Wire the registry into origination', $replay[0]['objective']);
        @array_map('unlink', glob($root.'/*.jsonl') ?: []);
    }

    public function test_internalization_derives_candidates_never_auto_promoted(): void
    {
        $c = AtlasBrainCycleCapsule::capture($this->fullCycle());
        $cands = AtlasBrainInternalizationPipeline::candidatesFrom([$c]);
        $kinds = array_column($cands, 'kind');
        self::assertContains('wiring', $kinds, 'certified + files → wiring candidate');
        self::assertContains('reflection', $kinds, 'learning → reflection candidate');
        self::assertContains('metric', $kinds, 'metrics → metric candidate');
        foreach ($cands as $cand) {
            self::assertFalse($cand['promoted'], 'NEVER auto-promote');
            self::assertTrue($cand['requires_gate']);
            self::assertContains($cand['kind'], AtlasBrainInternalizationPipeline::KINDS);
        }
    }

    public function test_failure_capsule_yields_a_policy_candidate(): void
    {
        $c = AtlasBrainCycleCapsule::capture(['task_packet_id' => 'y', 'failures' => ['prepare_blocked'], 'certified' => false]);
        $cands = AtlasBrainInternalizationPipeline::candidatesFrom([$c]);
        self::assertSame(['policy'], array_column($cands, 'kind'), 'a failed cycle with no files/learning → only a policy candidate');
    }

    public function test_empty_capsule_yields_no_candidate(): void
    {
        $c = AtlasBrainCycleCapsule::capture(['task_packet_id' => 'z']); // no files/failures/metrics/learning
        self::assertSame([], AtlasBrainInternalizationPipeline::candidatesFrom([$c]));
    }
}
