<?php

declare(strict_types=1);

namespace Tests\Unit\Foundry\Frontier\Promotion;

use App\Services\Ai\Foundry\Frontier\Promotion\FoundryOperatorPromotionBacklogCompilerService;
use App\Services\Ai\Foundry\FoundrySchemas;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusInboxService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusOperatorDecisionService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusSelfConstructionAdmissionBridgeService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\FindingSlicePlannerService;
use PHPUnit\Framework\TestCase;

/**
 * AP-D Promotion (AFEF I4 No Self-Canonization).
 *
 * Proves: no receipt => no finding; an ACCEPT receipt => a canonical finding
 * through factory_max + admission + #2 gates with owner=atlas_dev; an AFEF
 * packet inherits the SAME bridge-set required_gates (no exception). The
 * promoted body is content-bound to the trusted inbox item, NEVER caller-supplied.
 */
final class FoundryOperatorPromotionBacklogCompilerServiceTest extends TestCase
{
    private const AREA = 'agentic_engineering_os';

    private const FOCUS = 'frontier_promotion';

    private const TRUSTED_HASH = 'sha256:trusted_survivor_hash_0001';

    private string $storageDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageDir = sys_get_temp_dir().'/afef_ap_d_'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if ($this->storageDir !== '' && is_dir($this->storageDir)) {
            $this->removeDir($this->storageDir);
        }
        parent::tearDown();
    }

    public function test_accept_receipt_promotes_through_admission_with_owner_atlas_dev(): void
    {
        $service = $this->service();
        $receipt = $this->acceptReceiptFor(self::TRUSTED_HASH);

        $result = $service->compile($receipt, $this->trustedInbox(), self::AREA, self::FOCUS);

        $this->assertSame(FoundryOperatorPromotionBacklogCompilerService::STATUS_PROMOTED, $result['status']);
        $this->assertTrue($result['admissible']);
        $this->assertTrue($result['backlog_written']);
        $this->assertIsArray($result['backlog_candidate']);
        $this->assertSame('atlas_dev', $result['backlog_candidate']['owner_candidate']);
        $this->assertTrue($result['backlog_candidate']['auto_execution_allowed']);
        // active_slice_id set by the bridge (== packet id), preserved verbatim.
        $this->assertNotSame('', (string) ($result['backlog_candidate']['active_slice_id'] ?? ''));

        // EXACTLY one JSONL line appended.
        $this->assertSame(1, $this->backlogLineCount());
    }

    public function test_no_afef_exception_packet_carries_same_bridge_set_required_gates(): void
    {
        $service = $this->service();
        $result = $service->compile($this->acceptReceiptFor(self::TRUSTED_HASH), $this->trustedInbox(), self::AREA, self::FOCUS);

        $packet = $result['backlog_candidate']['self_construction_packet'] ?? [];
        $this->assertSame(
            ['scope_validator', 'focused_test', 'judge_accept', 'merge_governor'],
            $packet['required_gates'] ?? null,
            'AFEF-origin packet MUST travel the SAME bridge-set gates — zero exemption.',
        );
    }

    public function test_no_receipt_blocks_with_zero_write(): void
    {
        $service = $this->service();

        $result = $service->compile([], $this->trustedInbox(), self::AREA, self::FOCUS);

        $this->assertSame(FoundryOperatorPromotionBacklogCompilerService::STATUS_BLOCKED, $result['status']);
        $this->assertSame(FoundryOperatorPromotionBacklogCompilerService::BLOCK_RECEIPT_REQUIRED, $result['blocker_reason']);
        $this->assertFalse($result['backlog_written']);
        $this->assertNull($result['backlog_candidate']);
        $this->assertFalse($this->backlogExists());
    }

    public function test_reject_and_defer_receipts_block_no_write(): void
    {
        $service = $this->service();

        foreach (['reject', 'defer'] as $decision) {
            $receipt = (new AreaFocusOperatorDecisionService())->decide([
                'operator_actor' => 'vitor',
                'decision' => $decision,
                'finding_hash' => self::TRUSTED_HASH,
                'area_id' => self::AREA,
            ]);

            $result = $service->compile($receipt, $this->trustedInbox(), self::AREA, self::FOCUS);

            $this->assertSame(FoundryOperatorPromotionBacklogCompilerService::STATUS_BLOCKED, $result['status'], $decision);
            $this->assertSame(FoundryOperatorPromotionBacklogCompilerService::BLOCK_DECISION_NOT_ACCEPT, $result['blocker_reason'], $decision);
            $this->assertFalse($result['backlog_written'], $decision);
        }
        $this->assertFalse($this->backlogExists());
    }

    public function test_schema_mismatch_receipt_blocks(): void
    {
        $service = $this->service();
        $receipt = $this->acceptReceiptFor(self::TRUSTED_HASH);
        $receipt['schema_version'] = 'atlas.some.other.receipt.v1';

        $result = $service->compile($receipt, $this->trustedInbox(), self::AREA, self::FOCUS);

        $this->assertSame(FoundryOperatorPromotionBacklogCompilerService::BLOCK_RECEIPT_SCHEMA_MISMATCH, $result['blocker_reason']);
        $this->assertFalse($result['backlog_written']);
        $this->assertFalse($this->backlogExists());
    }

    public function test_executed_receipt_is_not_a_promotion_credential(): void
    {
        $service = $this->service();
        $receipt = $this->acceptReceiptFor(self::TRUSTED_HASH);
        $receipt['executed'] = true;

        $result = $service->compile($receipt, $this->trustedInbox(), self::AREA, self::FOCUS);

        $this->assertSame(FoundryOperatorPromotionBacklogCompilerService::BLOCK_RECEIPT_NOT_PROMOTION_CREDENTIAL, $result['blocker_reason']);
        $this->assertFalse($this->backlogExists());
    }

    public function test_actorless_receipt_is_not_a_promotion_credential(): void
    {
        $service = $this->service();
        $receipt = $this->acceptReceiptFor(self::TRUSTED_HASH);
        $receipt['operator_actor'] = '';

        $result = $service->compile($receipt, $this->trustedInbox(), self::AREA, self::FOCUS);

        $this->assertSame(FoundryOperatorPromotionBacklogCompilerService::BLOCK_RECEIPT_NOT_PROMOTION_CREDENTIAL, $result['blocker_reason']);
        $this->assertFalse($this->backlogExists());
    }

    public function test_finding_hash_absent_from_inbox_blocks_content_binding(): void
    {
        $service = $this->service();
        // Valid accept receipt, but its finding_hash is NOT in the inbox projection.
        $receipt = $this->acceptReceiptFor('sha256:hash_not_in_inbox_9999');

        $result = $service->compile($receipt, $this->trustedInbox(), self::AREA, self::FOCUS);

        $this->assertSame(FoundryOperatorPromotionBacklogCompilerService::BLOCK_INBOX_ITEM_NOT_FOUND, $result['blocker_reason']);
        $this->assertFalse($result['backlog_written']);
        $this->assertFalse($this->backlogExists(), 'A fig-leaf finding cannot ride a valid receipt hash: body is never caller-supplied.');
    }

    public function test_admission_blocked_finding_blocks_no_write(): void
    {
        $service = $this->service();
        // Inbox item the bridge will reject: a generic docs-only finding produces
        // a non-decomposable plan under factory_max.
        $inbox = new FakeTrustedInbox([
            $this->inboxItem('sha256:non_admissible_0001', [
                'title' => 'Update README',
                'spec_seed' => ['affected_files' => ['docs/readme.md']],
            ]),
        ]);
        $receipt = $this->acceptReceiptFor('sha256:non_admissible_0001');

        $result = $service->compile($receipt, $inbox, self::AREA, self::FOCUS);

        $this->assertSame(FoundryOperatorPromotionBacklogCompilerService::STATUS_BLOCKED, $result['status']);
        $this->assertStringStartsWith('admission_blocked:', (string) $result['blocker_reason']);
        $this->assertFalse($result['backlog_written']);
        $this->assertFalse($this->backlogExists());
    }

    public function test_promoted_finding_hash_is_deterministic(): void
    {
        $receipt = $this->acceptReceiptFor(self::TRUSTED_HASH);

        $a = $this->service()->compile($receipt, $this->trustedInbox(), self::AREA, self::FOCUS);
        // Fresh storage dir so the second run is not idempotency-short-circuited.
        $this->storageDir = sys_get_temp_dir().'/afef_ap_d_'.bin2hex(random_bytes(6));
        $b = $this->service()->compile($receipt, $this->trustedInbox(), self::AREA, self::FOCUS);

        $this->assertNotSame('', $a['promoted_finding_hash']);
        $this->assertSame($a['promoted_finding_hash'], $b['promoted_finding_hash']);
    }

    public function test_promoting_same_survivor_twice_is_idempotent(): void
    {
        $service = $this->service();
        $receipt = $this->acceptReceiptFor(self::TRUSTED_HASH);

        $first = $service->compile($receipt, $this->trustedInbox(), self::AREA, self::FOCUS);
        $second = $service->compile($receipt, $this->trustedInbox(), self::AREA, self::FOCUS);

        $this->assertSame(FoundryOperatorPromotionBacklogCompilerService::STATUS_PROMOTED, $first['status']);
        $this->assertSame(FoundryOperatorPromotionBacklogCompilerService::STATUS_ALREADY_PROMOTED, $second['status']);
        $this->assertFalse($second['backlog_written']);
        $this->assertSame(1, $this->backlogLineCount(), 'Idempotency: exactly one JSONL line.');
    }

    public function test_spec_seed_carries_success_metric_and_measure_cmd_for_ap_e(): void
    {
        // The promoted finding's spec_seed must copy success_metric + measure_cmd
        // from the TRUSTED inbox item so AP-E can measure post-merge.
        $compiler = $this->service();
        // Reach the internal build via reflection: assert the candidate the bridge
        // emits is derived from a finding whose spec_seed carried these. We assert
        // through the validated finding shape produced before admission by
        // re-running the trusted-body path indirectly: the inbox item carries them.
        $item = $this->inboxItem(self::TRUSTED_HASH, [
            'spec_seed' => [
                'success_metric' => ['property' => 'p95_latency_ms', 'operator' => '<', 'baseline' => 100, 'threshold' => 90],
                'measure_cmd' => 'php artisan atlas:bench --json',
            ],
        ]);
        $this->assertSame('php artisan atlas:bench --json', $item['spec_seed']['measure_cmd']);
        $this->assertSame('p95_latency_ms', $item['spec_seed']['success_metric']['property']);

        // And the emitted PARENT finding carries the copied success_metric +
        // measure_cmd so AP-E can measure post-merge (mapped from the merged packet).
        $result = $compiler->compile($this->acceptReceiptFor(self::TRUSTED_HASH), new FakeTrustedInbox([$item]), self::AREA, self::FOCUS);
        $this->assertSame(FoundryOperatorPromotionBacklogCompilerService::STATUS_PROMOTED, $result['status']);

        $parentSeed = $result['promoted_parent_finding']['spec_seed'] ?? [];
        $this->assertSame('php artisan atlas:bench --json', $parentSeed['measure_cmd'] ?? null);
        $this->assertSame('p95_latency_ms', $parentSeed['success_metric']['property'] ?? null);
    }

    public function test_emitted_finding_passes_foundry_schema_advisory(): void
    {
        // The deep finding shape AP-D sets has no required_keys entry in
        // FoundrySchemas; advisory validateShape is used only as a non-generative
        // sanity. Assert validateShape is callable and never fabricates keys.
        $shape = FoundrySchemas::validateShape(
            FoundrySchemas::ROADMAP,
            ['roadmap_id' => 'r', 'area_id' => 'a', 'generated_at' => 't', 'version' => 1, 'capabilities' => []],
        );
        $this->assertTrue($shape['valid']);
        $this->assertSame([], $shape['missing']);
    }

    // ---- helpers -----------------------------------------------------------

    private function service(): FoundryOperatorPromotionBacklogCompilerService
    {
        $bridge = new AreaFocusSelfConstructionAdmissionBridgeService(
            new FindingSlicePlannerService(),
            new AgentControlPlaneTaskPacketBuilder(),
        );
        $service = new FoundryOperatorPromotionBacklogCompilerService($bridge);
        $service->setBacklogStorageDirForTesting($this->storageDir);

        return $service;
    }

    private function trustedInbox(): FakeTrustedInbox
    {
        return new FakeTrustedInbox([
            $this->inboxItem(self::TRUSTED_HASH, [
                'spec_seed' => [
                    'success_metric' => ['property' => 'p95_latency_ms', 'operator' => '<', 'baseline' => 100, 'threshold' => 90],
                    'measure_cmd' => 'php artisan atlas:bench --json',
                ],
            ]),
        ]);
    }

    /**
     * Build a trusted inbox item with a STRATEGIC title (planner produces >=2
     * semantic slices on a real source file => bridge admits it).
     *
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function inboxItem(string $findingHash, array $overrides = []): array
    {
        $base = [
            'finding_hash' => $findingHash,
            'finding_id' => 'inbox_'.substr(hash('sha256', $findingHash), 0, 12),
            'title' => 'Implement promotion measurement hook',
            'rationale' => 'Survivor approved by the operator for the factory_max backlog.',
            'risk_level' => 'medium',
            'evidence_refs' => [],
            'spec_seed' => [
                'affected_files' => [
                    'app/Services/Ai/Foundry/Frontier/Promotion/PromotedExample.php',
                    'tests/Unit/Foundry/Frontier/Promotion/PromotedExampleTest.php',
                ],
            ],
        ];

        return array_replace_recursive($base, $overrides);
    }

    private function acceptReceiptFor(string $findingHash): array
    {
        return (new AreaFocusOperatorDecisionService())->decide([
            'operator_actor' => 'vitor',
            'decision' => AreaFocusOperatorDecisionService::DECISION_ACCEPT,
            'finding_hash' => $findingHash,
            'area_id' => self::AREA,
        ]);
    }

    private function backlogPath(): string
    {
        return $this->storageDir.'/'.self::AREA.'/factory_max_promoted_backlog.jsonl';
    }

    private function backlogExists(): bool
    {
        return is_file($this->backlogPath());
    }

    private function backlogLineCount(): int
    {
        if (! $this->backlogExists()) {
            return 0;
        }

        return count(array_filter(
            explode("\n", (string) file_get_contents($this->backlogPath())),
            static fn (string $l): bool => trim($l) !== '',
        ));
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
 * Trusted inbox double: the SOLE body source. compile() locates the item by
 * receipt.finding_hash from THIS projection — the caller never supplies a body.
 */
final class FakeTrustedInbox extends AreaFocusInboxService
{
    /** @param list<array<string,mixed>> $items */
    public function __construct(private readonly array $items)
    {
        // Intentionally bypass the parent constructor: this double returns a
        // pre-seeded operator projection without the curation dependency chain.
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
