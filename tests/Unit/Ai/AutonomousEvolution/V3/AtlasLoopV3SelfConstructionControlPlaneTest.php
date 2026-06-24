<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\V3;

use App\Services\Ai\AutonomousEvolution\V3\AtlasLoopV3SelfConstructionControlPlane;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Proves the V3 self-construction control plane composes its four collaborators (all anonymous-class fakes via
 * constructor — no Mockery, no facades): ready_actions only populates on real upstream signal, strategy alone
 * suffices for has_evidence, and a throwing collaborator is isolated into errors without aborting the snapshot.
 */
final class AtlasLoopV3SelfConstructionControlPlaneTest extends TestCase
{
    /** @param array<string,array<string,mixed>> $byFqcn */
    private function promotion(array $byFqcn = []): object
    {
        return new class($byFqcn)
        {
            public function __construct(private array $byFqcn) {}

            public function evaluate(string $fqcn, array $real, array $planted, string $repoRoot): array
            {
                return $this->byFqcn[$fqcn] ?? ['promote' => false, 'reason' => 'no_signal'];
            }
        };
    }

    private function lever(?string $recommend): object
    {
        return new class($recommend)
        {
            public function __construct(private ?string $recommend) {}

            public function recommend(array $readings): array
            {
                return ['recommend' => $this->recommend, 'ranked' => [], 'rejected' => [], 'schema' => 'lever'];
            }
        };
    }

    /** @param list<array<string,mixed>> $strategies */
    private function strategy(array $strategies = []): object
    {
        return new class($strategies)
        {
            public function __construct(private array $strategies) {}

            public function distill(array $campaignIds): array
            {
                return ['strategies' => $this->strategies, 'single_campaign_noise' => [], 'schema' => 'strategy'];
            }
        };
    }

    /** @param array<string,string> $verdictByRoot 'boom' throws */
    private function transfer(array $verdictByRoot = []): object
    {
        return new class($verdictByRoot)
        {
            public function __construct(private array $verdictByRoot) {}

            public function probe(array $fingerprint, string $targetRoot): array
            {
                if ($targetRoot === 'boom') {
                    throw new RuntimeException('probe exploded');
                }

                return ['verdict' => $this->verdictByRoot[$targetRoot] ?? 'saturated', 'schema' => 'transfer'];
            }
        };
    }

    private function plane(object $promotion, object $lever, object $strategy, object $transfer): AtlasLoopV3SelfConstructionControlPlane
    {
        return new AtlasLoopV3SelfConstructionControlPlane($promotion, $lever, $strategy, $transfer);
    }

    public function test_no_signal_yields_no_evidence(): void
    {
        $out = $this->plane($this->promotion(), $this->lever(null), $this->strategy(), $this->transfer())
            ->snapshot(['candidate_graders' => [['fqcn' => 'X', 'real_rejected' => [], 'planted_false' => [], 'repo_root' => '/r']]]);

        $this->assertFalse($out['has_evidence']);
        $this->assertSame([], $out['ready_actions']);
        $this->assertSame('atlas.loop.v3.self_construction_control_plane.v1', $out['schema']);
    }

    public function test_promotable_grader_populates_ready_actions(): void
    {
        $out = $this->plane(
            $this->promotion(['G' => ['promote' => true, 'reason' => 'admits_real']]),
            $this->lever(null), $this->strategy(), $this->transfer(),
        )->snapshot(['candidate_graders' => [['fqcn' => 'G', 'real_rejected' => [], 'planted_false' => [], 'repo_root' => '/r']]]);

        $this->assertTrue($out['has_evidence']);
        $this->assertCount(1, $out['graders_promotable']);
        $this->assertSame('G', $out['graders_promotable'][0]['fqcn']);
        $this->assertSame($out['graders_promotable'], $out['ready_actions']);
    }

    public function test_lever_recommendation_populates_ready_actions(): void
    {
        $out = $this->plane($this->promotion(), $this->lever('feature_x'), $this->strategy(), $this->transfer())
            ->snapshot([]);

        $this->assertContains('feature_x', $out['ready_actions']);
        $this->assertTrue($out['has_evidence']);
    }

    public function test_only_transferable_jobs_are_ready(): void
    {
        $out = $this->plane($this->promotion(), $this->lever(null), $this->strategy(), $this->transfer(['good' => 'transferable', 'meh' => 'saturated']))
            ->snapshot(['transfer_jobs' => [
                ['fingerprint' => [], 'target_root' => 'good'],
                ['fingerprint' => [], 'target_root' => 'meh'],
            ]]);

        $this->assertCount(1, $out['transfers_ready']);
        $this->assertSame('good', $out['transfers_ready'][0]['target_root']);
        $this->assertSame($out['transfers_ready'], $out['ready_actions']);
    }

    public function test_strategy_signal_alone_is_evidence(): void
    {
        $out = $this->plane($this->promotion(), $this->lever(null), $this->strategy([['kind' => 'avoid', 'count' => 3, 'campaigns' => 2]]), $this->transfer())
            ->snapshot([]);

        $this->assertSame([], $out['ready_actions']);
        $this->assertTrue($out['has_evidence'], 'a cross-campaign strategy alone qualifies');
    }

    public function test_throwing_collaborator_is_isolated(): void
    {
        $out = $this->plane($this->promotion(), $this->lever(null), $this->strategy(), $this->transfer(['ok' => 'transferable']))
            ->snapshot(['transfer_jobs' => [
                ['fingerprint' => [], 'target_root' => 'boom'],
                ['fingerprint' => [], 'target_root' => 'ok'],
            ]]);

        $this->assertCount(1, $out['errors']);
        $this->assertSame('boom', $out['errors'][0]['fqcn']);
        $this->assertCount(1, $out['transfers_ready'], 'the good job still completes');
        $this->assertSame('ok', $out['transfers_ready'][0]['target_root']);
    }

    public function test_is_deterministic(): void
    {
        $build = fn (): AtlasLoopV3SelfConstructionControlPlane => $this->plane(
            $this->promotion(['G' => ['promote' => true]]),
            $this->lever('lev'),
            $this->strategy([['kind' => 'avoid', 'count' => 3]]),
            $this->transfer(['t1' => 'transferable', 'boom' => 'x']),
        );
        $input = [
            'candidate_graders' => [['fqcn' => 'G', 'real_rejected' => [], 'planted_false' => [], 'repo_root' => '/r']],
            'transfer_jobs' => [['fingerprint' => [], 'target_root' => 't1'], ['fingerprint' => [], 'target_root' => 'boom']],
        ];

        $a = $build()->snapshot($input);
        $b = $build()->snapshot($input);

        $this->assertSame(json_encode($a['ready_actions']), json_encode($b['ready_actions']));
        $this->assertSame(json_encode($a['errors']), json_encode($b['errors']));
    }
}
