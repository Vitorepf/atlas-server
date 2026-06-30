<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Twin;

use App\Services\Ai\AutonomousEvolution\Twin\AtlasLoopSimulableTwinOrchestrator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

final class AtlasLoopSimulableTwinOrchestratorTest extends TestCase
{
    public function test_simulate_returns_reverted_true_even_when_apply_callback_throws(): void
    {
        $rollback = new class
        {
            public bool $called = false;

            public function execute(string $executionId, array $recordedPostExecutionShas = []): array
            {
                $this->called = true;

                return ['status' => 'restored', 'execution_id' => $executionId, 'restored_paths' => []];
            }
        };

        $result = (new AtlasLoopSimulableTwinOrchestrator($this->snapshotter(), $rollback))->simulate(
            $this->candidate(),
            static fn (array $mirror, array $candidate): array => throw new RuntimeException('mirror_apply_failed'),
        );

        $this->assertTrue($result['reverted']);
        $this->assertTrue($rollback->called);
        $this->assertSame($result['before_snapshot'], $result['after_snapshot']);
        $this->assertSame(0, $result['behavior_delta']['net_behavior_delta']);
    }

    public function test_before_after_snapshots_are_present_and_delta_is_computed_from_them(): void
    {
        $result = (new AtlasLoopSimulableTwinOrchestrator($this->snapshotter(), $this->rollback()))->simulate(
            $this->candidate(),
            static fn (array $mirror, array $candidate): array => [
                'after_snapshot' => [
                    'schema' => 'atlas.loop.behavior_delta_snapshot.v1',
                    'symbols' => [
                        ['fqcn' => 'Demo\\Foo', 'public_api_signature_hash' => 'sig-after', 'caller_fqcns' => ['Demo\\Bar']],
                    ],
                ],
                'post_execution_shas' => ['app/Services/Ai/AutonomousEvolution/Twin/Mirror/Foo.php' => 'sha-after'],
            ],
        );

        $this->assertSame(AtlasLoopSimulableTwinOrchestrator::SCHEMA_VERSION, $result['schema']);
        $this->assertArrayHasKey('before_snapshot', $result);
        $this->assertArrayHasKey('after_snapshot', $result);
        $this->assertSame([
            ['fqcn' => 'Demo\\Foo', 'before_hash' => 'sig-before', 'after_hash' => 'sig-after'],
        ], $result['behavior_delta']['api_signature_changed']);
        $this->assertSame(2, $result['behavior_delta']['net_behavior_delta']);
    }

    public function test_apply_receives_only_the_mirror_handle_and_source_contains_no_live_write_primitive(): void
    {
        $seenMirror = null;
        $candidate = $this->candidate() + ['live_source_path' => 'app/Live/DoNotTouch.php'];

        (new AtlasLoopSimulableTwinOrchestrator($this->snapshotter(), $this->rollback()))->simulate(
            $candidate,
            function (array $mirror, array $candidateEdit) use (&$seenMirror): array {
                $seenMirror = $mirror;

                return ['after_snapshot' => $this->beforeBehaviorSnapshot()];
            },
        );

        $this->assertSame('mirror-1', $seenMirror['id']);
        $this->assertArrayNotHasKey('live_source_path', $seenMirror);

        $ref = new ReflectionClass(AtlasLoopSimulableTwinOrchestrator::class);
        $source = file_get_contents((string) $ref->getFileName());
        $this->assertIsString($source);
        foreach (['file_put_contents', 'fopen', 'fwrite', 'shell_exec', 'proc_open', 'passthru', 'exec('] as $needle) {
            $this->assertStringNotContainsString($needle, $source);
        }
    }

    private function snapshotter(): object
    {
        return new class($this->beforeBehaviorSnapshot())
        {
            public function __construct(private readonly array $snapshot) {}

            public function snapshot(string $executionId, array $executionPlan): array
            {
                return $this->snapshot + [
                    'execution_id' => $executionId,
                    'execution_plan' => $executionPlan,
                ];
            }
        };
    }

    private function rollback(): object
    {
        return new class
        {
            public function execute(string $executionId, array $recordedPostExecutionShas = []): array
            {
                return [
                    'status' => 'restored',
                    'execution_id' => $executionId,
                    'restored_paths' => array_keys($recordedPostExecutionShas),
                ];
            }
        };
    }

    /** @return array<string,mixed> */
    private function candidate(): array
    {
        return [
            'execution_id' => 'sim-twin-test',
            'mirror' => [
                'id' => 'mirror-1',
                'root' => '/tmp/atlas-mirror',
                'execution_plan' => [
                    'allowed_files' => ['app/Services/Ai/AutonomousEvolution/Twin/Mirror/Foo.php'],
                ],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function beforeBehaviorSnapshot(): array
    {
        return [
            'schema' => 'atlas.loop.behavior_delta_snapshot.v1',
            'symbols' => [
                ['fqcn' => 'Demo\\Foo', 'public_api_signature_hash' => 'sig-before', 'caller_fqcns' => []],
            ],
        ];
    }

    // ---------- rankCandidate() ----------

    public function test_high_impact_low_collision_packet_ranks_above_proxy_packet(): void
    {
        $orch = new AtlasLoopSimulableTwinOrchestrator;

        $highImpact = $orch->rankCandidate([
            'allowed_files'    => ['app/Services/Ai/AutonomousEvolution/Twin/AtlasLoopSimulableTwinOrchestrator.php'],
            'risk_level'       => 'low',
            'objective'        => 'Add rankCandidate method so brain can pre-filter candidates before seeding.',
            'required_evidence'=> ['tests_or_gates_result'],
        ]);

        $proxy = $orch->rankCandidate([
            'allowed_files'    => ['app/Services/Ai/AutonomousEvolution/Twin/AtlasLoopSimulableTwinOrchestrator.php'],
            'risk_level'       => 'low',
            'objective'        => 'cleanup whitespace and fix indentation throughout file.',
            'required_evidence'=> ['tests_or_gates_result'],
        ]);

        $this->assertGreaterThan($proxy['rank_score'], $highImpact['rank_score'], 'high-impact must outrank proxy');
        $this->assertContains('proxy:objective_is_cleanup_or_rename', $proxy['reasons']);
        $this->assertSame([], $highImpact['reasons'], 'clean packet emits no penalty reasons');
    }

    public function test_high_impact_low_collision_packet_ranks_above_high_collision_packet(): void
    {
        $orch = new AtlasLoopSimulableTwinOrchestrator;

        $lowCollision = $orch->rankCandidate([
            'allowed_files'    => ['app/Services/Ai/AutonomousEvolution/Twin/AtlasLoopSimulableTwinOrchestrator.php'],
            'risk_level'       => 'low',
            'objective'        => 'Seal manifest sha using file-content map scheme.',
            'required_evidence'=> ['tests_or_gates_result'],
        ]);

        $highCollision = $orch->rankCandidate([
            'allowed_files'    => ['a.php', 'b.php', 'c.php', 'd.php', 'e.php', 'f.php'],
            'risk_level'       => 'low',
            'objective'        => 'Seal manifest sha using file-content map scheme.',
            'required_evidence'=> ['tests_or_gates_result'],
        ]);

        $this->assertGreaterThan($highCollision['rank_score'], $lowCollision['rank_score'], 'low-collision must outrank high-collision');
        $this->assertStringStartsWith('collision_risk:high:', $highCollision['reasons'][0]);
        $this->assertSame('high', $highCollision['collision_risk']);
        $this->assertSame('low', $lowCollision['collision_risk']);
    }

    public function test_rank_candidate_is_deterministic_across_two_calls(): void
    {
        $orch = new AtlasLoopSimulableTwinOrchestrator;
        $spec = [
            'allowed_files'    => ['app/Foo.php', 'app/Bar.php'],
            'risk_level'       => 'medium',
            'objective'        => 'Add structural method to improve origination quality.',
            'required_evidence'=> ['tests_or_gates_result', 'implementation_notes'],
        ];

        $this->assertSame(
            json_encode($orch->rankCandidate($spec), JSON_UNESCAPED_SLASHES),
            json_encode($orch->rankCandidate($spec), JSON_UNESCAPED_SLASHES),
        );
    }

    public function test_rank_score_never_goes_below_zero(): void
    {
        $orch   = new AtlasLoopSimulableTwinOrchestrator;
        $result = $orch->rankCandidate([
            'allowed_files'    => array_fill(0, 10, 'app/Foo.php'),
            'risk_level'       => 'high',
            'objective'        => 'cleanup whitespace formatting dead code rename variable',
            'required_evidence'=> array_fill(0, 5, 'item'),
        ]);

        $this->assertGreaterThanOrEqual(0, $result['rank_score']);
    }
}
