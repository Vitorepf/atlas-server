<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Foundry\Frontier\Promotion;

use App\Services\Ai\Foundry\Frontier\Armor\FrontierMetricRollbackGate;
use App\Services\Ai\Foundry\Frontier\Outcome\FileRoadmapStorePort;
use App\Services\Ai\Foundry\Frontier\Outcome\FoundryEvolutionOutcomeMaterializerService;
use App\Services\Ai\Foundry\Frontier\Outcome\GitRevertPort;
use App\Services\Ai\Foundry\Frontier\Outcome\MeasureCommandPort;
use App\Services\Ai\Foundry\Frontier\Promotion\FoundryOperatorPromotionBacklogCompilerService;
use App\Services\Ai\Foundry\FoundrySchemas;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusInboxService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusOperatorDecisionService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusSelfConstructionAdmissionBridgeService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\FindingSlicePlannerService;
use PHPUnit\Framework\TestCase;

/**
 * P2-BRIDGE-ROADMAP-SEED — close the AP-E dead-end (Finding 26).
 *
 * Before this seed, a freshly-merged AFEF capability had NO roadmap.v1 line
 * keyed by its parent finding_id, so AP-E materialize() always returned
 * BLOCK_ROADMAP_NOT_LINKED and the capability could never be consolidated.
 *
 * The compiler now seeds — at the operator-receipt-gated AP-D promotion moment
 * (the only I4-safe write point) — a roadmap.v1 capability
 * {finding_id, state:'implemented', version:0, evidence_ref:promotion_receipt_hash}
 * through the SAME append-only roadmap.jsonl ledger the materializer writes.
 *
 * Invariants proven still hold:
 *   I5: seeded state is ALWAYS 'implemented', NEVER 'proven' (proven stays
 *       gated solely on AP-E's real green measure).
 *   I4: the seed write occurs ONLY inside the operator-receipt-gated compile()
 *       path (no receipt => no seed).
 *   I9 / determinism: a single growing append-only line; latest() (highest
 *       version) resolution stays deterministic with TWO writers and a
 *       version:0 seed never overwrites or shadows a higher-version proven
 *       advance.
 */
final class FoundryRoadmapSeedTest extends TestCase
{
    private const AREA = 'agentic_engineering_os';

    private const FOCUS = 'frontier_promotion';

    private const TRUSTED_HASH = 'sha256:trusted_survivor_seed_0001';

    private string $storageDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageDir = sys_get_temp_dir().'/afef_seed_'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if ($this->storageDir !== '' && is_dir($this->storageDir)) {
            $this->removeDir($this->storageDir);
        }
        parent::tearDown();
    }

    public function test_first_promotion_seeds_implemented_version_zero_capability(): void
    {
        $result = $this->promote();

        self::assertSame(FoundryOperatorPromotionBacklogCompilerService::STATUS_PROMOTED, $result['status']);

        $parentFindingId = (string) $result['promoted_parent_finding']['finding_id'];
        self::assertNotSame('', $parentFindingId);

        $latest = $this->roadmapStore()->latest(self::AREA);
        self::assertIsArray($latest, 'a roadmap.v1 seed line must exist after the first promotion');

        $cap = $this->findCapability($latest, $parentFindingId);
        self::assertIsArray($cap, 'the seed capability must be keyed by the parent finding_id');
        self::assertSame('implemented', $cap['state'], 'I5: seed state is implemented, NEVER proven');
        self::assertSame(0, $cap['version']);
        self::assertSame(
            (string) $result['promotion_receipt_hash'],
            (string) $cap['evidence_ref'],
            'seed evidence_ref binds to the operator promotion_receipt_hash',
        );

        // The seed line is a valid roadmap.v1 shape (advisory, non-generative).
        $shape = FoundrySchemas::validateShape(FoundrySchemas::ROADMAP, $latest);
        self::assertTrue($shape['valid']);
        self::assertSame([], $shape['missing']);
    }

    public function test_no_receipt_seeds_nothing_I4(): void
    {
        $compiler = $this->compiler();
        $result = $compiler->compile([], $this->trustedInbox(), self::AREA, self::FOCUS);

        self::assertSame(FoundryOperatorPromotionBacklogCompilerService::STATUS_BLOCKED, $result['status']);
        self::assertFalse(is_file($this->roadmapPath()), 'I4: no operator receipt => zero roadmap write');
        self::assertNull($this->roadmapStore()->latest(self::AREA));
    }

    public function test_seed_is_idempotent_one_capability_no_second_line(): void
    {
        $compiler = $this->compiler();
        $receipt = $this->acceptReceiptFor(self::TRUSTED_HASH);

        $first = $compiler->compile($receipt, $this->trustedInbox(), self::AREA, self::FOCUS);
        $second = $compiler->compile($receipt, $this->trustedInbox(), self::AREA, self::FOCUS);

        self::assertSame(FoundryOperatorPromotionBacklogCompilerService::STATUS_PROMOTED, $first['status']);
        self::assertSame(FoundryOperatorPromotionBacklogCompilerService::STATUS_ALREADY_PROMOTED, $second['status']);

        // Exactly one seed line — the second promotion is idempotency-short-circuited
        // and the seed helper also refuses to re-seed an existing finding_id.
        self::assertSame(1, $this->roadmapLineCount());

        $parentFindingId = (string) $first['promoted_parent_finding']['finding_id'];
        $latest = $this->roadmapStore()->latest(self::AREA);
        self::assertCount(1, (array) $latest['capabilities']);
        self::assertSame('implemented', $this->findCapability($latest, $parentFindingId)['state']);
    }

    public function test_two_writers_one_ledger_seed_never_shadows_proven_advance(): void
    {
        // Writer #1: the AP-D compiler seeds version:0 implemented.
        $result = $this->promote();
        $parentFindingId = (string) $result['promoted_parent_finding']['finding_id'];
        $packetFindingId = (string) $result['backlog_candidate']['finding_id'];
        self::assertStringStartsWith($parentFindingId.'::packet::', $packetFindingId);

        $seedLine = $this->roadmapStore()->latest(self::AREA);
        self::assertSame(0, (int) $seedLine['version']);
        self::assertSame('implemented', $this->findCapability($seedLine, $parentFindingId)['state']);

        // Writer #2: the REAL materializer measures a green improvement and
        // consolidates implemented->proven, appending a HIGHER-version line to
        // the SAME roadmap.jsonl ledger (read via the SAME FileRoadmapStorePort).
        $materializer = $this->materializer(new SeedSpyMeasurePort(true, 0, '95'), new SeedFakeRevertPort());
        $out = $materializer->materialize(
            $this->mergedCycleReceipt($packetFindingId),
            $this->greenProposal(),
            self::AREA,
        );

        // Finding 26 closed: AP-E no longer dead-ends on cycle 0.
        self::assertNotSame(
            FoundryEvolutionOutcomeMaterializerService::BLOCK_ROADMAP_NOT_LINKED,
            $out['blocker_reason'] ?? null,
            'after seeding, AP-E finds the capability and no longer BLOCK_ROADMAP_NOT_LINKED',
        );
        self::assertSame('consolidated', $out['status']);
        self::assertSame('proven', $out['roadmap_after']['capabilities'][0]['state']);

        // Two writers => two append-only lines on the one ledger.
        self::assertSame(2, $this->roadmapLineCount());

        // Deterministic latest() resolution: highest version wins => the PROVEN
        // advance, NEVER the version:0 seed. The seed neither overwrites nor
        // shadows the proven advance.
        $latest = $this->roadmapStore()->latest(self::AREA);
        self::assertGreaterThan(0, (int) $latest['version'], 'latest() resolves to the higher-version advance, not the seed');
        self::assertSame('proven', $this->findCapability($latest, $parentFindingId)['state']);

        // The on-disk seed line itself was never mutated (append-only): it is
        // still implemented/version:0 in the ledger.
        $lines = $this->roadmapLines();
        $seedOnDisk = $lines[0];
        self::assertSame(0, (int) $seedOnDisk['version']);
        self::assertSame('implemented', $this->findCapability($seedOnDisk, $parentFindingId)['state']);
    }

    // ---- helpers -----------------------------------------------------------

    /** @return array<string,mixed> */
    private function promote(): array
    {
        return $this->compiler()->compile(
            $this->acceptReceiptFor(self::TRUSTED_HASH),
            $this->trustedInbox(),
            self::AREA,
            self::FOCUS,
        );
    }

    private function compiler(): FoundryOperatorPromotionBacklogCompilerService
    {
        $bridge = new AreaFocusSelfConstructionAdmissionBridgeService(
            new FindingSlicePlannerService(),
            new AgentControlPlaneTaskPacketBuilder(),
        );
        $compiler = new FoundryOperatorPromotionBacklogCompilerService($bridge, $this->roadmapStore());
        $compiler->setBacklogStorageDirForTesting($this->storageDir);
        // Point the seed append at the SAME foundry tree the store reads.
        $compiler->setRoadmapStorageDirForTesting($this->storageDir);

        return $compiler;
    }

    private function materializer(MeasureCommandPort $measure, GitRevertPort $revert): FoundryEvolutionOutcomeMaterializerService
    {
        $service = new FoundryEvolutionOutcomeMaterializerService(
            new FrontierMetricRollbackGate(),
            $measure,
            $revert,
            $this->roadmapStore(),
        );
        $service->setOutcomesStorageDirForTesting($this->storageDir);

        return $service;
    }

    private function roadmapStore(): FileRoadmapStorePort
    {
        $store = new FileRoadmapStorePort();
        $store->setStorageDir($this->storageDir);

        return $store;
    }

    private function trustedInbox(): SeedFakeTrustedInbox
    {
        return new SeedFakeTrustedInbox([
            [
                'finding_hash' => self::TRUSTED_HASH,
                'finding_id' => 'inbox_'.substr(hash('sha256', self::TRUSTED_HASH), 0, 12),
                'title' => 'Implement promotion measurement hook',
                'rationale' => 'Survivor approved by the operator for the factory_max backlog.',
                'risk_level' => 'medium',
                'evidence_refs' => [],
                'spec_seed' => [
                    'affected_files' => [
                        'app/Services/Ai/Foundry/Frontier/Promotion/PromotedExample.php',
                        'tests/Unit/Foundry/Frontier/Promotion/PromotedExampleTest.php',
                    ],
                    'success_metric' => ['property' => 'p95_latency_ms', 'operator' => '<', 'baseline' => 100, 'threshold' => 90],
                    'measure_cmd' => 'php artisan atlas:bench --json',
                ],
            ],
        ]);
    }

    /** @return array<string,mixed> */
    private function acceptReceiptFor(string $findingHash): array
    {
        return (new AreaFocusOperatorDecisionService())->decide([
            'operator_actor' => 'vitor',
            'decision' => AreaFocusOperatorDecisionService::DECISION_ACCEPT,
            'finding_hash' => $findingHash,
            'area_id' => self::AREA,
        ]);
    }

    /** @return array<string,mixed> */
    private function mergedCycleReceipt(string $packetFindingId): array
    {
        return [
            'lifecycle_state' => 'merged',
            'merge_hash' => 'merge-seed-abc',
            'finding_id' => $packetFindingId,
        ];
    }

    /** @return array<string,mixed> */
    private function greenProposal(): array
    {
        return [
            'proposal_id' => 'prop-seed-1',
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

    /**
     * @param  array<string,mixed>  $line
     * @return array<string,mixed>|null
     */
    private function findCapability(array $line, string $findingId): ?array
    {
        foreach ((array) ($line['capabilities'] ?? []) as $cap) {
            if (is_array($cap) && (string) ($cap['finding_id'] ?? '') === $findingId) {
                return $cap;
            }
        }

        return null;
    }

    private function roadmapPath(): string
    {
        return $this->storageDir.'/'.self::AREA.'/roadmap.jsonl';
    }

    /** @return list<array<string,mixed>> */
    private function roadmapLines(): array
    {
        if (! is_file($this->roadmapPath())) {
            return [];
        }
        $out = [];
        foreach (explode("\n", (string) file_get_contents($this->roadmapPath())) as $l) {
            $l = trim($l);
            if ($l === '') {
                continue;
            }
            $decoded = json_decode($l, true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }

        return $out;
    }

    private function roadmapLineCount(): int
    {
        return count($this->roadmapLines());
    }

    private function removeDir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}

/**
 * Trusted inbox double: the SOLE body source for the compiler. Mirrors the
 * AP-D test's FakeTrustedInbox without the curation dependency chain.
 */
final class SeedFakeTrustedInbox extends AreaFocusInboxService
{
    /** @param list<array<string,mixed>> $items */
    public function __construct(private readonly array $items)
    {
        // Intentionally bypass the parent constructor.
    }

    public function project(array $input = []): array
    {
        return [
            'schema_version' => self::INBOX_SCHEMA,
            'status' => self::STATUS_READY,
            'items' => $this->items,
        ];
    }
}

/** Labelled measure fake: ran=true, exit=0, green stdout. NEVER a real command. */
final class SeedSpyMeasurePort implements MeasureCommandPort
{
    public function __construct(
        private readonly bool $ran,
        private readonly int $exitCode,
        private readonly string $stdout,
    ) {}

    public function run(string $measureCmd, string $property): array
    {
        return ['ran' => $this->ran, 'exit_code' => $this->exitCode, 'stdout' => $this->stdout, 'stderr' => ''];
    }
}

/** Labelled revert fake — NEVER runs a real git revert in a test. */
final class SeedFakeRevertPort implements GitRevertPort
{
    public function revert(string $mergeHash, string $verifyCmd): array
    {
        return ['reverted' => true, 'revert_commit_hash' => 'revert-seed', 'verify_passed' => true, 'detail' => 'fake'];
    }
}
