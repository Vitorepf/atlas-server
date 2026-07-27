<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\MultiProject;

use App\Services\Ai\SelfConstruction\MultiProject\AtlasProjectLaneIsolationSentinel;
use App\Services\Ai\SelfConstruction\MultiProject\AtlasProjectLaneReceiptPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasProjectLaneIsolationSentinel: all three FACT bundles present + passing for the same
 * project_id ⇒ status=pass; missing any bundle ⇒ status=hold; a present-but-failing bundle
 * (namespace/receipt/leak) ⇒ status=blocked with the appropriate blocker named.
 */
final class AtlasProjectLaneIsolationSentinelTest extends TestCase
{
    private function passingNamespace(): array
    {
        return ['project_id' => 'demo-lane', 'namespace' => 'lane.demo-lane.deadbeef.main'];
    }

    private function passingReceipt(): array
    {
        return [
            'envelopes' => [[
                'schema' => AtlasProjectLaneReceiptPolicy::SCHEMA,
                'project_id' => 'demo-lane',
                'envelope_hash' => 'env-hash-1',
            ]],
        ];
    }

    private function passingLeak(): array
    {
        return ['passed' => true, 'status' => 'clean', 'blockers' => []];
    }

    public function test_pass_when_all_three_bundles_present_and_passing(): void
    {
        $v = (new AtlasProjectLaneIsolationSentinel)->evaluate([
            'project_id' => 'demo-lane',
            'namespace_facts' => $this->passingNamespace(),
            'receipt_facts' => $this->passingReceipt(),
            'leak_detector_verdict' => $this->passingLeak(),
        ]);

        $this->assertSame(AtlasProjectLaneIsolationSentinel::STATUS_PASS, $v['status']);
        $this->assertTrue($v['passed']);
        $this->assertSame([], $v['blockers']);
    }

    public function test_hold_when_a_bundle_is_missing(): void
    {
        $v = (new AtlasProjectLaneIsolationSentinel)->evaluate([
            'project_id' => 'demo-lane',
            'namespace_facts' => $this->passingNamespace(),
            // receipt_facts missing
            'leak_detector_verdict' => $this->passingLeak(),
        ]);

        $this->assertSame(AtlasProjectLaneIsolationSentinel::STATUS_HOLD, $v['status']);
        $this->assertFalse($v['passed']);
        $this->assertContains('receipt_facts', $v['isolation_facts']['missing_observations']);
    }

    public function test_blocked_when_namespace_policy_fails(): void
    {
        $v = (new AtlasProjectLaneIsolationSentinel)->evaluate([
            'project_id' => 'demo-lane',
            'namespace_facts' => ['project_id' => 'demo-lane', 'namespace' => 'NOT_A_LANE'],
            'receipt_facts' => $this->passingReceipt(),
            'leak_detector_verdict' => $this->passingLeak(),
        ]);

        $this->assertSame(AtlasProjectLaneIsolationSentinel::STATUS_BLOCKED, $v['status']);
        $this->assertContains('namespace_policy_failed', $v['blockers']);
    }

    public function test_blocked_when_receipt_policy_fails(): void
    {
        $v = (new AtlasProjectLaneIsolationSentinel)->evaluate([
            'project_id' => 'demo-lane',
            'namespace_facts' => $this->passingNamespace(),
            // Cross-project receipt — wrong project_id
            'receipt_facts' => ['envelopes' => [['project_id' => 'OTHER', 'envelope_hash' => 'h-1']]],
            'leak_detector_verdict' => $this->passingLeak(),
        ]);

        $this->assertSame(AtlasProjectLaneIsolationSentinel::STATUS_BLOCKED, $v['status']);
        $this->assertContains('receipt_policy_failed', $v['blockers']);
    }

    public function test_blocked_when_leak_detector_fails(): void
    {
        $v = (new AtlasProjectLaneIsolationSentinel)->evaluate([
            'project_id' => 'demo-lane',
            'namespace_facts' => $this->passingNamespace(),
            'receipt_facts' => $this->passingReceipt(),
            'leak_detector_verdict' => ['passed' => false, 'status' => 'leaks_detected', 'blockers' => ['allowed_files_escape_lane']],
        ]);

        $this->assertSame(AtlasProjectLaneIsolationSentinel::STATUS_BLOCKED, $v['status']);
        $this->assertContains('leak_detector_failed', $v['blockers']);
        $this->assertContains('leak:allowed_files_escape_lane', $v['blockers']);
    }

    public function test_namespace_facts_without_project_id_cannot_pass(): void
    {
        $ns = $this->passingNamespace();
        unset($ns['project_id']);

        $v = (new AtlasProjectLaneIsolationSentinel)->evaluate([
            'project_id' => 'demo-lane',
            'namespace_facts' => $ns,
            'receipt_facts' => $this->passingReceipt(),
            'leak_detector_verdict' => $this->passingLeak(),
        ]);

        $this->assertNotSame(AtlasProjectLaneIsolationSentinel::STATUS_PASS, $v['status']);
        $this->assertFalse($v['passed']);
        $this->assertContains('namespace_policy_failed', $v['blockers']);
    }

    public function test_namespace_facts_with_mismatched_project_id_cannot_pass(): void
    {
        $v = (new AtlasProjectLaneIsolationSentinel)->evaluate([
            'project_id' => 'demo-lane',
            'namespace_facts' => ['project_id' => 'other-project', 'namespace' => 'lane.demo-lane.abc.main'],
            'receipt_facts' => $this->passingReceipt(),
            'leak_detector_verdict' => $this->passingLeak(),
        ]);

        $this->assertNotSame(AtlasProjectLaneIsolationSentinel::STATUS_PASS, $v['status']);
        $this->assertContains('namespace_policy_failed', $v['blockers']);
    }

    public function test_receipt_with_wrong_schema_cannot_pass(): void
    {
        $receipt = [
            'envelopes' => [[
                'schema' => 'atlas.wrong.schema.v9',
                'project_id' => 'demo-lane',
                'envelope_hash' => 'env-hash-ok',
            ]],
        ];

        $v = (new AtlasProjectLaneIsolationSentinel)->evaluate([
            'project_id' => 'demo-lane',
            'namespace_facts' => $this->passingNamespace(),
            'receipt_facts' => $receipt,
            'leak_detector_verdict' => $this->passingLeak(),
        ]);

        $this->assertNotSame(AtlasProjectLaneIsolationSentinel::STATUS_PASS, $v['status']);
        $this->assertContains('receipt_policy_failed', $v['blockers']);
    }

    public function test_receipt_with_missing_envelope_hash_cannot_pass(): void
    {
        $receipt = [
            'envelopes' => [[
                'schema' => AtlasProjectLaneReceiptPolicy::SCHEMA,
                'project_id' => 'demo-lane',
                // envelope_hash absent
            ]],
        ];

        $v = (new AtlasProjectLaneIsolationSentinel)->evaluate([
            'project_id' => 'demo-lane',
            'namespace_facts' => $this->passingNamespace(),
            'receipt_facts' => $receipt,
            'leak_detector_verdict' => $this->passingLeak(),
        ]);

        $this->assertNotSame(AtlasProjectLaneIsolationSentinel::STATUS_PASS, $v['status']);
        $this->assertContains('receipt_policy_failed', $v['blockers']);
    }

    public function test_leak_verdict_with_different_project_id_cannot_pass(): void
    {
        $leak = array_replace($this->passingLeak(), ['project_id' => 'completely-different-project']);

        $v = (new AtlasProjectLaneIsolationSentinel)->evaluate([
            'project_id' => 'demo-lane',
            'namespace_facts' => $this->passingNamespace(),
            'receipt_facts' => $this->passingReceipt(),
            'leak_detector_verdict' => $leak,
        ]);

        $this->assertNotSame(AtlasProjectLaneIsolationSentinel::STATUS_PASS, $v['status']);
        $this->assertFalse($v['passed']);
        $blockerStr = implode(',', $v['blockers']);
        $this->assertStringContainsString('leak_detector_project_id_mismatch', $blockerStr);
    }

    public function test_envelope_carries_canonical_schema_and_no_scalar_score(): void
    {
        $v = (new AtlasProjectLaneIsolationSentinel)->evaluate([
            'project_id' => 'demo-lane',
            'namespace_facts' => $this->passingNamespace(),
            'receipt_facts' => $this->passingReceipt(),
            'leak_detector_verdict' => $this->passingLeak(),
        ]);

        $this->assertSame(AtlasProjectLaneIsolationSentinel::SCHEMA, $v['schema_version']);
        $json = (string) json_encode($v);
        $this->assertDoesNotMatchRegularExpression('/score|grade|percent/i', $json);
    }

    // ── AC: file_leaks, queue_leaks, memory_leaks, receipt_leaks, docs_leaks, isolation_status, recommended_action ──

    private function fullObservation(array $overrides = []): array
    {
        return array_merge([
            'project_id' => 'demo-lane',
            'namespace_facts' => $this->passingNamespace(),
            'receipt_facts' => $this->passingReceipt(),
            'leak_detector_verdict' => $this->passingLeak(),
        ], $overrides);
    }

    public function test_clean_isolation_reports_empty_leak_lists_and_proceed_action(): void
    {
        $v = (new AtlasProjectLaneIsolationSentinel)->evaluate($this->fullObservation());

        $this->assertSame(AtlasProjectLaneIsolationSentinel::STATUS_PASS, $v['isolation_status']);
        $this->assertSame($v['status'], $v['isolation_status']);
        $this->assertSame([], $v['file_leaks']);
        $this->assertSame([], $v['queue_leaks']);
        $this->assertSame([], $v['memory_leaks']);
        $this->assertSame([], $v['receipt_leaks']);
        $this->assertSame([], $v['docs_leaks']);
        $this->assertSame('proceed', $v['recommended_action']);
    }

    public function test_file_leak_blocks_isolation_and_is_reported(): void
    {
        $v = (new AtlasProjectLaneIsolationSentinel)->evaluate($this->fullObservation([
            'leak_facts' => ['file_leaks' => ['app/OtherProject/Secret.php']],
        ]));

        $this->assertSame(AtlasProjectLaneIsolationSentinel::STATUS_BLOCKED, $v['isolation_status']);
        $this->assertSame(['app/OtherProject/Secret.php'], $v['file_leaks']);
        $this->assertContains('file_leaks:app/OtherProject/Secret.php', $v['blockers']);
        $this->assertSame('halt_and_isolate', $v['recommended_action']);
    }

    public function test_queue_namespace_leak_blocks_isolation_and_is_reported(): void
    {
        $v = (new AtlasProjectLaneIsolationSentinel)->evaluate($this->fullObservation([
            'leak_facts' => ['queue_leaks' => ['task claimed under wrong project namespace']],
        ]));

        $this->assertSame(AtlasProjectLaneIsolationSentinel::STATUS_BLOCKED, $v['isolation_status']);
        $this->assertSame(['task claimed under wrong project namespace'], $v['queue_leaks']);
        $this->assertContains('queue_leaks:task claimed under wrong project namespace', $v['blockers']);
    }

    public function test_memory_leak_blocks_isolation_and_is_reported(): void
    {
        $v = (new AtlasProjectLaneIsolationSentinel)->evaluate($this->fullObservation([
            'leak_facts' => ['memory_leaks' => ['memory record tagged with other-project scope']],
        ]));

        $this->assertSame(AtlasProjectLaneIsolationSentinel::STATUS_BLOCKED, $v['isolation_status']);
        $this->assertSame(['memory record tagged with other-project scope'], $v['memory_leaks']);
    }

    public function test_receipt_leak_blocks_isolation_and_is_reported(): void
    {
        $v = (new AtlasProjectLaneIsolationSentinel)->evaluate($this->fullObservation([
            'leak_facts' => ['receipt_leaks' => ['receipt env-hash-9 references foreign project_id']],
        ]));

        $this->assertSame(AtlasProjectLaneIsolationSentinel::STATUS_BLOCKED, $v['isolation_status']);
        $this->assertSame(['receipt env-hash-9 references foreign project_id'], $v['receipt_leaks']);
    }

    public function test_docs_leak_blocks_isolation_and_is_reported(): void
    {
        $v = (new AtlasProjectLaneIsolationSentinel)->evaluate($this->fullObservation([
            'leak_facts' => ['docs_leaks' => ['docs/OtherProject/internal.md']],
        ]));

        $this->assertSame(AtlasProjectLaneIsolationSentinel::STATUS_BLOCKED, $v['isolation_status']);
        $this->assertSame(['docs/OtherProject/internal.md'], $v['docs_leaks']);
    }

    public function test_mixed_leak_severity_reports_all_leak_types_simultaneously(): void
    {
        $v = (new AtlasProjectLaneIsolationSentinel)->evaluate($this->fullObservation([
            'leak_facts' => [
                'file_leaks' => ['app/Other/A.php'],
                'queue_leaks' => ['wrong-namespace-task'],
                'memory_leaks' => ['other-project-memory'],
                'receipt_leaks' => ['foreign-receipt'],
                'docs_leaks' => ['docs/leaked.md'],
            ],
        ]));

        $this->assertSame(AtlasProjectLaneIsolationSentinel::STATUS_BLOCKED, $v['isolation_status']);
        $this->assertSame(['app/Other/A.php'], $v['file_leaks']);
        $this->assertSame(['wrong-namespace-task'], $v['queue_leaks']);
        $this->assertSame(['other-project-memory'], $v['memory_leaks']);
        $this->assertSame(['foreign-receipt'], $v['receipt_leaks']);
        $this->assertSame(['docs/leaked.md'], $v['docs_leaks']);
        $this->assertSame('halt_and_isolate', $v['recommended_action']);
    }

    public function test_absent_leak_facts_never_triggers_hold(): void
    {
        // Regression guard: leak_facts is purely additive — its absence must never be treated as a
        // missing observation (unlike namespace_facts/receipt_facts/leak_detector_verdict).
        $v = (new AtlasProjectLaneIsolationSentinel)->evaluate($this->fullObservation());

        $this->assertSame(AtlasProjectLaneIsolationSentinel::STATUS_PASS, $v['status']);
        $this->assertNotContains('leak_facts', $v['isolation_facts']['missing_observations']);
    }
}
