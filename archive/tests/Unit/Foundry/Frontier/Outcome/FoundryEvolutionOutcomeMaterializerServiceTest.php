<?php

declare(strict_types=1);

namespace Tests\Unit\Foundry\Frontier\Outcome;

use App\Services\Ai\Foundry\Frontier\Armor\FrontierMetricRollbackGate;
use App\Services\Ai\Foundry\Frontier\Outcome\FoundryEvolutionOutcomeMaterializerService;
use App\Services\Ai\Foundry\Frontier\Outcome\GitRevertPort;
use App\Services\Ai\Foundry\Frontier\Outcome\MeasureCommandPort;
use App\Services\Ai\Foundry\Frontier\Outcome\RoadmapStorePort;
use App\Services\Ai\Foundry\FoundrySchemas;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousLoopReceiptIntegrityService;
use PHPUnit\Framework\TestCase;

/**
 * AP-E unit ap_e_materializer · I5 measured-or-reverted + I9 property binding.
 *
 * Every measurement and git-revert path is exercised with LABELLED
 * Fake / Blocked port impls ONLY. No real measure_cmd is run and NO real git
 * revert is ever invoked — the logic is proven entirely with deterministic
 * fixtures, satisfying real-or-blocked.
 */
final class FoundryEvolutionOutcomeMaterializerServiceTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir().'/ap_e_materializer_'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->tmpDir);
        }
        parent::tearDown();
    }

    public function test_non_merged_receipt_blocks_with_no_measure_and_no_jsonl(): void
    {
        $measure = new SpyMeasureCommandPort(true, 0, '42');
        $service = $this->makeService($measure, new FakeGitRevertPort(), $this->roadmapStore('parent-1'));

        $result = $service->materialize(
            $this->receipt(lifecycleState: 'planned', mergeHash: 'abc'),
            $this->proposal(),
            'agentic_engineering_os',
        );

        self::assertSame('blocked', $result['status']);
        self::assertSame('finding_not_merged', $result['blocker_reason']);
        self::assertNull($result['outcome']);
        self::assertFalse($measure->wasRun, 'measure_cmd MUST NOT run on a non-merged receipt');
        self::assertNoJsonl();
    }

    public function test_empty_merge_hash_blocks_finding_not_merged(): void
    {
        $service = $this->makeService(new SpyMeasureCommandPort(true, 0, '42'), new FakeGitRevertPort(), $this->roadmapStore('parent-1'));
        $result = $service->materialize($this->receipt('merged', ''), $this->proposal(), 'area');
        self::assertSame('blocked', $result['status']);
        self::assertSame('finding_not_merged', $result['blocker_reason']);
    }

    public function test_empty_property_blocks_metric_property_required_I9(): void
    {
        $service = $this->makeService(new SpyMeasureCommandPort(true, 0, '42'), new FakeGitRevertPort(), $this->roadmapStore('parent-1'));
        $proposal = $this->proposal();
        $proposal['success_metric']['property'] = '';

        $result = $service->materialize($this->receipt(), $proposal, 'area');
        self::assertSame('blocked', $result['status']);
        self::assertSame('metric_property_required', $result['blocker_reason']);
        self::assertNoJsonl();
    }

    public function test_measure_cmd_absent_from_proposal_blocks_measure_cmd_required(): void
    {
        $service = $this->makeService(new SpyMeasureCommandPort(true, 0, '42'), new FakeGitRevertPort(), $this->roadmapStore('parent-1'));
        $proposal = $this->proposal();
        unset($proposal['success_metric']['measure_cmd']);

        $result = $service->materialize($this->receipt(), $proposal, 'area');
        self::assertSame('blocked', $result['status']);
        self::assertSame('measure_cmd_required', $result['blocker_reason']);
    }

    public function test_measure_cmd_only_from_unvalidated_spec_seed_blocks_not_bound_I9(): void
    {
        $service = $this->makeService(new SpyMeasureCommandPort(true, 0, '42'), new FakeGitRevertPort(), $this->roadmapStore('parent-1'));
        $proposal = $this->proposal();
        unset($proposal['success_metric']['measure_cmd']);

        // measure_cmd offered ONLY via an unvalidated finding spec_seed copy.
        $result = $service->materialize($this->receipt(), $proposal, 'area', [
            'finding_spec_seed' => ['success_metric' => ['measure_cmd' => 'php artisan rogue:measure']],
        ]);

        self::assertSame('blocked', $result['status']);
        self::assertSame('measure_cmd_not_bound_to_property', $result['blocker_reason']);
        self::assertNoJsonl();
    }

    public function test_no_matching_roadmap_capability_blocks_not_linked(): void
    {
        $service = $this->makeService(new SpyMeasureCommandPort(true, 0, '42'), new FakeGitRevertPort(), $this->roadmapStore('other-parent'));
        $result = $service->materialize($this->receipt(), $this->proposal(), 'area');

        self::assertSame('blocked', $result['status']);
        self::assertSame('roadmap_capability_not_linked', $result['blocker_reason']);
        self::assertNoJsonl();
    }

    public function test_null_roadmap_blocks_not_linked(): void
    {
        $service = $this->makeService(new SpyMeasureCommandPort(true, 0, '42'), new FakeGitRevertPort(), new NullRoadmapStorePort());
        $result = $service->materialize($this->receipt(), $this->proposal(), 'area');
        self::assertSame('roadmap_capability_not_linked', $result['blocker_reason']);
    }

    public function test_improved_real_green_measure_consolidates_and_proves_roadmap(): void
    {
        // FakeMeasureCommandPort: ran=true, exit=0, stdout passes operator+tolerance.
        $measure = new SpyMeasureCommandPort(true, 0, '95'); // operator '<=' threshold '120'
        $revert = new FakeGitRevertPort();
        $service = $this->makeService($measure, $revert, $this->roadmapStore('parent-1', version: 3, capState: 'implemented', capVersion: 2));

        $result = $service->materialize($this->receipt(), $this->proposal(), 'area');

        self::assertSame('consolidated', $result['status']);
        self::assertSame('consolidate', $result['outcome']['action']);
        self::assertFalse($result['outcome']['refuted_by_reality']);
        self::assertTrue($result['outcome']['improved']);
        self::assertSame(95.0, $result['outcome']['post_value']);

        $cap = $result['roadmap_after']['capabilities'][0];
        self::assertSame('proven', $cap['state']);
        self::assertSame(3, $cap['version'], 'capability version bumped 2->3');
        self::assertSame(4, $result['roadmap_after']['version'], 'roadmap version bumped 3->4');
        self::assertFalse($revert->wasCalled, 'git revert MUST NOT be called on a green measure');

        // two JSONL lines: outcome + roadmap.
        self::assertSame(1, $this->jsonlLineCount('area', 'evolution_outcomes.jsonl'));
        self::assertSame(1, $this->jsonlLineCount('area', 'roadmap.jsonl'));

        // schema validity asserted via valid===true / missing===[].
        $os = FoundrySchemas::validateShape(FoundrySchemas::EVOLUTION_OUTCOME, $result['outcome']);
        self::assertTrue($os['valid']);
        self::assertSame([], $os['missing']);
        $rs = FoundrySchemas::validateShape(FoundrySchemas::ROADMAP, $result['roadmap_after']);
        self::assertTrue($rs['valid']);
        self::assertSame([], $rs['missing']);
    }

    public function test_non_improvement_failing_comparison_reverts_via_port(): void
    {
        // exit=0 but stdout fails operator (<= 120) -> non-improvement.
        $measure = new SpyMeasureCommandPort(true, 0, '500');
        $revert = new FakeGitRevertPort('revert-commit-xyz');
        $service = $this->makeService($measure, $revert, $this->roadmapStore('parent-1'));

        $result = $service->materialize($this->receipt('merged', 'merge-abc'), $this->proposal(), 'area');

        self::assertSame('reverted', $result['status']);
        self::assertSame('reverted', $result['outcome']['action']);
        self::assertTrue($result['outcome']['refuted_by_reality']);
        self::assertSame(500.0, $result['outcome']['post_value']);
        self::assertTrue($revert->wasCalled);
        self::assertSame('merge-abc', $revert->mergeHash);
        self::assertSame('revert-commit-xyz', $result['revert_commit_hash']);
        self::assertSame('reverted', $result['roadmap_after']['capabilities'][0]['state']);
    }

    public function test_blocked_measure_port_reverts_with_null_post_value_never_fabricates(): void
    {
        // BlockedMeasureCommandPort: ran=false -> improved=false, post_value null.
        $revert = new FakeGitRevertPort('revert-blocked');
        $service = $this->makeService(new BlockedMeasureCommandPort(), $revert, $this->roadmapStore('parent-1'));

        $result = $service->materialize($this->receipt(), $this->proposal(), 'area');

        self::assertSame('reverted', $result['status']);
        self::assertSame('reverted', $result['outcome']['action']);
        self::assertTrue($result['outcome']['refuted_by_reality']);
        self::assertNull($result['outcome']['post_value'], 'blocked measure NEVER fabricates a numeric');
        self::assertTrue($revert->wasCalled);
    }

    public function test_consolidate_never_emitted_when_measure_is_blocked_I5(): void
    {
        $service = $this->makeService(new BlockedMeasureCommandPort(), new FakeGitRevertPort(), $this->roadmapStore('parent-1'));
        $result = $service->materialize($this->receipt(), $this->proposal(), 'area');
        self::assertNotSame('consolidate', $result['outcome']['action']);
        self::assertNotSame('consolidated', $result['status']);
    }

    public function test_outcome_hash_deterministic_for_identical_inputs(): void
    {
        $build = fn () => $this->makeService(new SpyMeasureCommandPort(true, 0, '95'), new FakeGitRevertPort(), $this->roadmapStore('parent-1'))
            ->materialize($this->receipt(), $this->proposal(), 'area', ['measured_at' => '2026-05-30T00:00:00+00:00']);

        $a = $build();
        $b = $build();
        self::assertSame($a['outcome']['outcome_hash'], $b['outcome']['outcome_hash']);
        self::assertSame(64, strlen($a['outcome']['outcome_hash']));
    }

    public function test_roadmap_append_is_append_only_original_untouched(): void
    {
        $original = $this->roadmapLine('parent-1', version: 1, capState: 'implemented', capVersion: 1);
        $service = $this->makeService(new SpyMeasureCommandPort(true, 0, '95'), new FakeGitRevertPort(), new InMemoryRoadmapStorePort($original));

        $result = $service->materialize($this->receipt(), $this->proposal(), 'area');

        // the store still returns the ORIGINAL line unchanged.
        self::assertSame(1, $original['version']);
        self::assertSame('implemented', $original['capabilities'][0]['state']);
        // the appended line is a NEW versioned line.
        self::assertSame(2, $result['roadmap_after']['version']);
        self::assertSame('proven', $result['roadmap_after']['capabilities'][0]['state']);
    }

    // ----------------------------------------------------------------- helpers

    private function makeService(MeasureCommandPort $measure, GitRevertPort $revert, RoadmapStorePort $store): FoundryEvolutionOutcomeMaterializerService
    {
        $service = new FoundryEvolutionOutcomeMaterializerService(
            new FrontierMetricRollbackGate(),
            $measure,
            $revert,
            $store,
        );
        $service->setOutcomesStorageDirForTesting($this->tmpDir);

        return $service;
    }

    /** @return array<string,mixed> */
    private function receipt(string $lifecycleState = 'merged', string $mergeHash = 'merge-abc'): array
    {
        return [
            'lifecycle_state' => $lifecycleState,
            'merge_hash' => $mergeHash,
            'finding_id' => 'parent-1::packet::1',
        ];
    }

    /** @return array<string,mixed> */
    private function proposal(): array
    {
        return [
            'proposal_id' => 'prop-9',
            'horizon' => 'near',
            'title' => 'Reduce p95 latency',
            'thesis' => 'thesis',
            'evidence_refs' => ['ev-1'],
            'why_it_multiplies' => 'why',
            'success_metric' => [
                'property' => 'p95_latency_ms',
                'operator' => '<=',
                'baseline' => '180',
                'threshold' => '120',
                'measure_cmd' => 'php artisan atlas:measure:p95',
            ],
            'rollback' => [
                'rollback_condition' => 'p95 regresses',
                'method' => 'git_revert',
                'verify_cmd' => 'php artisan atlas:verify:p95',
            ],
            'risk_level' => 'medium',
            'dependencies' => [],
            'proposed_packets' => [],
            'provider_tier_required' => 'standard',
            'anti_pattern_self_check' => 'ok',
        ];
    }

    private function roadmapStore(string $findingId, int $version = 1, string $capState = 'implemented', int $capVersion = 1): RoadmapStorePort
    {
        return new InMemoryRoadmapStorePort($this->roadmapLine($findingId, $version, $capState, $capVersion));
    }

    /** @return array<string,mixed> */
    private function roadmapLine(string $findingId, int $version, string $capState, int $capVersion): array
    {
        return [
            'roadmap_id' => 'rm-1',
            'area_id' => 'area',
            'generated_at' => '2026-05-01T00:00:00+00:00',
            'version' => $version,
            'capabilities' => [
                [
                    'capability_id' => 'cap-1',
                    'finding_id' => $findingId,
                    'title' => 'Reduce p95 latency',
                    'state' => $capState,
                    'version' => $capVersion,
                    'updated_at' => '2026-05-01T00:00:00+00:00',
                    'evidence_ref' => '',
                ],
            ],
        ];
    }

    private function jsonlLineCount(string $areaSlug, string $file): int
    {
        $path = $this->tmpDir.'/'.$areaSlug.'/'.$file;
        if (! is_file($path)) {
            return 0;
        }

        return count(array_filter(explode("\n", trim((string) file_get_contents($path))), fn ($l) => $l !== ''));
    }

    private function assertNoJsonl(): void
    {
        self::assertFalse(is_dir($this->tmpDir) && (glob($this->tmpDir.'/*/*.jsonl') ?: []) !== [], 'no JSONL must be written on a block');
    }
}

// --------------------------------------------------------------- labelled fakes

final class SpyMeasureCommandPort implements MeasureCommandPort
{
    public bool $wasRun = false;

    public function __construct(
        private readonly bool $ran,
        private readonly int $exitCode,
        private readonly string $stdout,
    ) {}

    public function run(string $measureCmd, string $property): array
    {
        $this->wasRun = true;

        return ['ran' => $this->ran, 'exit_code' => $this->exitCode, 'stdout' => $this->stdout, 'stderr' => ''];
    }
}

final class BlockedMeasureCommandPort implements MeasureCommandPort
{
    public function run(string $measureCmd, string $property): array
    {
        // Honest block: no real merged finding to measure. NEVER a numeric.
        return ['ran' => false, 'exit_code' => -1, 'stdout' => '', 'stderr' => 'measure_blocked: no real merged finding'];
    }
}

final class FakeGitRevertPort implements GitRevertPort
{
    public bool $wasCalled = false;

    public string $mergeHash = '';

    public function __construct(private readonly string $revertCommitHash = 'fake-revert-hash') {}

    public function revert(string $mergeHash, string $verifyCmd): array
    {
        // Labelled fixture ONLY — NEVER runs a real `git revert`.
        $this->wasCalled = true;
        $this->mergeHash = $mergeHash;

        return [
            'reverted' => true,
            'revert_commit_hash' => $this->revertCommitHash,
            'verify_passed' => true,
            'detail' => 'fixture git revert --no-edit (no real git invoked)',
        ];
    }
}

final class InMemoryRoadmapStorePort implements RoadmapStorePort
{
    /** @param array<string,mixed> $line */
    public function __construct(private readonly array $line) {}

    public function latest(string $areaId): ?array
    {
        return $this->line;
    }
}

final class NullRoadmapStorePort implements RoadmapStorePort
{
    public function latest(string $areaId): ?array
    {
        return null;
    }
}
