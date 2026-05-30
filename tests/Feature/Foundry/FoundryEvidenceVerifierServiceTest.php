<?php

declare(strict_types=1);

namespace Tests\Feature\Foundry;

use App\Services\Ai\Foundry\FoundryEvidenceVerifierService;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousLoopReceiptIntegrityService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class FoundryEvidenceVerifierServiceTest extends TestCase
{
    private string $repoDir;

    private string $rejectionDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repoDir = sys_get_temp_dir().'/foundry_repo_'.uniqid('', true);
        @mkdir($this->repoDir, 0775, true);
        $this->rejectionDir = sys_get_temp_dir().'/foundry_rej_'.uniqid('', true);
        @mkdir($this->rejectionDir, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->repoDir);
        $this->rmrf($this->rejectionDir);
        parent::tearDown();
    }

    private function service(): FoundryEvidenceVerifierService
    {
        $svc = app(FoundryEvidenceVerifierService::class);
        $svc->setRepoRootForTesting($this->repoDir);
        $svc->setRejectionStorageDirForTesting($this->rejectionDir);

        return $svc;
    }

    private function rmrf(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach ((array) glob($dir.'/*') as $entry) {
            is_dir($entry) ? $this->rmrf($entry) : @unlink($entry);
        }
        @rmdir($dir);
    }

    private function rejectionLines(string $areaId = 'agentic_engineering_os'): array
    {
        $slug = preg_replace('/[^a-z0-9_]+/', '_', strtolower($areaId));
        $path = $this->rejectionDir.'/'.$slug.'/false_anchor_rejections.jsonl';
        if (! is_file($path)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $l): ?array => trim($l) === '' ? null : json_decode($l, true),
            file($path) ?: [],
        )));
    }

    // ---------- I1 CORE: fake cycle_id ----------

    public function test_i1_fake_cycle_id_is_refuted_with_recorded_reason(): void
    {
        $svc = $this->service();

        $verdict = $svc->verifyAnchor([
            'anchor_id' => 'a1',
            'anchor_type' => 'cycle_id',
            'anchor_claim' => 'does-not-exist',
            'source_path' => 'cycle_id',
        ], ['cycles' => [], 'area_id' => 'agentic_engineering_os']);

        $this->assertSame('refuted', $verdict['verdict']);
        $this->assertSame('cycle_id_not_found', $verdict['drop_reason']);
        $this->assertSame(FoundryEvidenceVerifierService::VERDICT_SCHEMA, $verdict['schema_version']);

        $rej = $this->rejectionLines();
        $this->assertCount(1, $rej);
        $this->assertSame('cycle_id_not_found', $rej[0]['drop_reason']);
        $this->assertSame(FoundryEvidenceVerifierService::REJECTION_SCHEMA, $rej[0]['schema_version']);
    }

    public function test_valid_cycle_id_resolved_via_seam_is_confirmed(): void
    {
        $svc = $this->service();

        $verdict = $svc->verifyAnchor([
            'anchor_id' => 'a2',
            'anchor_type' => 'cycle_id',
            'anchor_claim' => 'cycle-real-1',
            'source_path' => 'cycle_id',
        ], ['cycles' => [['cycle_id' => 'cycle-real-1']], 'area_id' => 'agentic_engineering_os']);

        $this->assertSame('confirmed', $verdict['verdict']);
        $this->assertNull($verdict['drop_reason']);
        $this->assertSame([], $this->rejectionLines());
    }

    // ---------- I1: commit_hash absent ----------

    public function test_i1_absent_commit_hash_is_refuted(): void
    {
        $svc = $this->service();

        $verdict = $svc->verifyAnchor([
            'anchor_type' => 'commit_hash',
            'anchor_claim' => 'deadbeef',
            'source_path' => 'commit.commit_hash',
        ], ['commit_hashes' => ['abc1234567'], 'area_id' => 'agentic_engineering_os']);

        $this->assertSame('refuted', $verdict['verdict']);
        $this->assertSame('commit_hash_absent', $verdict['drop_reason']);
    }

    public function test_present_commit_hash_is_confirmed_via_seam(): void
    {
        $svc = $this->service();

        $verdict = $svc->verifyAnchor([
            'anchor_type' => 'commit_hash',
            'anchor_claim' => 'abc1234',
            'source_path' => 'commit.commit_hash',
        ], ['commit_hashes' => ['abc1234567def']]);

        $this->assertSame('confirmed', $verdict['verdict']);
        $this->assertNull($verdict['drop_reason']);
    }

    // ---------- I1: repro_cmd ALWAYS refuted, never executes ----------

    public function test_i1_repro_cmd_always_refuted_without_execution(): void
    {
        $sentinel = $this->repoDir.'/SHELL_RAN';
        $svc = $this->service();

        $verdict = $svc->verifyAnchor([
            'anchor_type' => 'repro_cmd',
            'anchor_claim' => 'touch '.$sentinel,
            'source_path' => 'repro',
        ]);

        $this->assertSame('refuted', $verdict['verdict']);
        $this->assertSame('repro_cmd_unresolvable', $verdict['drop_reason']);
        // PROOF nothing was executed: the claimed command did not run.
        $this->assertFileDoesNotExist($sentinel);
    }

    // ---------- I1: ledger_event missing ----------

    public function test_i1_ledger_event_missing_is_refuted(): void
    {
        $svc = $this->service();

        $verdict = $svc->verifyAnchor([
            'anchor_type' => 'ledger_event',
            'anchor_claim' => 'evt-missing',
            'source_path' => 'ledger',
        ], ['ledger_events' => []]);

        $this->assertSame('refuted', $verdict['verdict']);
        $this->assertSame('ledger_event_missing', $verdict['drop_reason']);
    }

    // ---------- EVENT_HASH round-trip ----------

    private function realLedgerRow(): array
    {
        $occurredAt = CarbonImmutable::parse('2026-05-29T10:00:00Z');
        $envelope = [
            'event_id' => 'evt-real-1',
            'event_type' => 'GATE_EVALUATED',
            'envelope_id' => 'env-1',
            'correlation_id' => 'corr-1',
            'causation_id' => null,
            'scope_type' => 'cycle',
            'scope_id' => 'cycle-real-1',
            'payload_hash' => hash('sha256', 'payload'),
            'occurred_at' => $occurredAt->toISOString(),
        ];

        return [
            'event_id' => 'evt-real-1',
            'event_type' => 'GATE_EVALUATED',
            'envelope_id' => 'env-1',
            'correlation_id' => 'corr-1',
            'causation_id' => null,
            'scope_type' => 'cycle',
            'scope_id' => 'cycle-real-1',
            'payload_hash' => $envelope['payload_hash'],
            'occurred_at' => $occurredAt->toIso8601String(),
            'event_hash' => AtlasEvidenceLedger::computeEventHash($envelope),
        ];
    }

    public function test_event_hash_roundtrip_real_row_confirmed(): void
    {
        $svc = $this->service();
        $row = $this->realLedgerRow();

        $verdict = $svc->verifyAnchor([
            'anchor_type' => 'ledger_event',
            'anchor_claim' => 'evt-real-1',
            'source_path' => 'ledger',
        ], ['ledger_events' => [$row]]);

        $this->assertSame('confirmed', $verdict['verdict']);
        $this->assertNull($verdict['drop_reason']);
        $this->assertSame(['evt-real-1'], $verdict['matched_event_ids']);
    }

    public function test_event_hash_roundtrip_tampered_row_refuted(): void
    {
        $svc = $this->service();
        $row = $this->realLedgerRow();
        $row['payload_hash'] = hash('sha256', 'tampered'); // hash no longer matches

        $verdict = $svc->verifyAnchor([
            'anchor_type' => 'ledger_event',
            'anchor_claim' => 'evt-real-1',
            'source_path' => 'ledger',
        ], ['ledger_events' => [$row]]);

        $this->assertSame('refuted', $verdict['verdict']);
        $this->assertSame('event_hash_mismatch', $verdict['drop_reason']);
    }

    // ---------- GATE checks read from owner output ----------

    private function healthyCycle(): array
    {
        return [
            'cycle_id' => 'cycle-merged-1',
            'final_status' => 'merged',
            'merge_performed' => true,
            'selected_finding' => ['finding_id' => 'f1', 'title' => 'A real modest finding'],
            'inbox_item_id' => 'inbox-1',
            'result_bridge_id' => 'bridge-1',
            'inbox_emitted_before_merge_attempt' => true,
            'changed_files' => ['app/Foo.php'],
            'validation' => ['status' => 'passed', 'passed' => true, 'commands' => ['php artisan test']],
            'merge_governance' => ['status' => 'merged', 'merge_commit' => 'abc1234'],
            'evidence_refs' => ['evidence/pack-1.json'],
            'owner_flow' => ['provider_router_used' => false],
        ];
    }

    public function test_gate_healthy_cycle_confirmed(): void
    {
        $svc = $this->service();
        $cycle = $this->healthyCycle();

        $verdict = $svc->verifyAnchor([
            'anchor_type' => 'merge_hash',
            'anchor_claim' => 'cycle-merged-1',
            'source_path' => 'merge_hash',
        ], ['cycle' => $cycle, 'receipt_context' => ['session_id' => 'sess-1']]);

        if ($verdict['verdict'] !== 'confirmed') {
            $this->fail('expected confirmed, drop='.$verdict['drop_reason'].' checks='.json_encode($verdict['checks']));
        }
        $this->assertNull($verdict['drop_reason']);
    }

    public function test_gate_provider_router_used_refuted_via_owner_signal(): void
    {
        $svc = $this->service();
        $cycle = $this->healthyCycle();
        $cycle['owner_flow']['provider_router_used'] = true;

        $verdict = $svc->verifyAnchor([
            'anchor_type' => 'merge_hash',
            'anchor_claim' => 'cycle-merged-1',
            'source_path' => 'merge_hash',
        ], ['cycle' => $cycle]);

        $this->assertSame('refuted', $verdict['verdict']);
        $this->assertSame('provider_router_used', $verdict['drop_reason']);
    }

    public function test_gate_validation_not_passed_refuted(): void
    {
        $svc = $this->service();
        $cycle = $this->healthyCycle();
        $cycle['validation'] = ['status' => 'failed', 'passed' => false, 'commands' => ['php artisan test']];

        $verdict = $svc->verifyAnchor([
            'anchor_type' => 'merge_hash',
            'anchor_claim' => 'cycle-merged-1',
            'source_path' => 'merge_hash',
        ], ['cycle' => $cycle]);

        $this->assertSame('refuted', $verdict['verdict']);
        $this->assertSame('validation_not_passed', $verdict['drop_reason']);
    }

    public function test_gate_evidence_refs_empty_refuted(): void
    {
        $svc = $this->service();
        $cycle = $this->healthyCycle();
        // Owner derives evidence_refs from inbox/result_bridge/owner_flow ids; clear
        // them all so the owner reports an empty evidence_refs list.
        $cycle['inbox_item_id'] = '';
        $cycle['result_bridge_id'] = '';
        $cycle['owner_flow'] = ['provider_router_used' => false];

        $verdict = $svc->verifyAnchor([
            'anchor_type' => 'merge_hash',
            'anchor_claim' => 'cycle-merged-1',
            'source_path' => 'merge_hash',
        ], ['cycle' => $cycle]);

        $this->assertSame('refuted', $verdict['verdict']);
        $this->assertSame('evidence_refs_empty', $verdict['drop_reason']);
    }

    public function test_gate_pre_merge_inbox_absent_refuted(): void
    {
        $svc = $this->service();
        $cycle = $this->healthyCycle();
        // Clear the pre-merge inbox markers but keep evidence_refs non-empty via
        // owner_flow, so the pre_merge gate is the one that refutes.
        $cycle['inbox_item_id'] = '';
        $cycle['result_bridge_id'] = '';
        $cycle['owner_flow']['owner_execution_id'] = 'owner-exec-1';

        $verdict = $svc->verifyAnchor([
            'anchor_type' => 'merge_hash',
            'anchor_claim' => 'cycle-merged-1',
            'source_path' => 'merge_hash',
        ], ['cycle' => $cycle]);

        $this->assertSame('refuted', $verdict['verdict']);
        $this->assertSame('pre_merge_inbox_absent', $verdict['drop_reason']);
    }

    public function test_gate_integrity_not_ok_refuted_via_owner_signal(): void
    {
        $svc = $this->service();
        // Otherwise-clean cycle, but with no session_id => owner marks integrity
        // incomplete via missing(session_id). The specific gates all pass; only the
        // owner's aggregate integrity signal refutes. Read, never re-derived.
        $cycle = $this->healthyCycle();
        $cycle['cycle_id'] = 'cycle-broken-1';

        $receipt = app(AutonomousLoopReceiptIntegrityService::class)->receiptFor($cycle);
        $this->assertNotSame(
            AutonomousLoopReceiptIntegrityService::INTEGRITY_OK,
            $receipt['integrity'],
            'precondition: owner must mark this cycle integrity != ok'
        );

        $verdict = $svc->verifyAnchor([
            'anchor_type' => 'merge_hash',
            'anchor_claim' => 'cycle-broken-1',
            'source_path' => 'merge_hash',
        ], ['cycle' => $cycle]);

        $this->assertSame('refuted', $verdict['verdict']);
        $this->assertSame('integrity_not_ok', $verdict['drop_reason']);
    }

    // ---------- determinism ----------

    public function test_determinism_same_anchor_input_same_hash(): void
    {
        $anchor = [
            'anchor_id' => 'd1',
            'anchor_type' => 'cycle_id',
            'anchor_claim' => 'nope',
            'source_path' => 'cycle_id',
        ];

        $a = $this->service()->verifyAnchor($anchor, ['cycles' => []]);
        $b = $this->service()->verifyAnchor($anchor, ['cycles' => []]);

        $this->assertSame($a['verification_hash'], $b['verification_hash']);
        $this->assertNotSame('', $a['verification_hash']);
    }

    // ---------- claim policy honesty ----------

    public function test_claim_policy_is_honest(): void
    {
        $verdict = $this->service()->verifyAnchor([
            'anchor_type' => 'cycle_id',
            'anchor_claim' => 'x',
            'source_path' => 'cycle_id',
        ], ['cycles' => [['cycle_id' => 'x']]]);

        $cp = $verdict['claim_policy'];
        $this->assertFalse($cp['read_only']);
        $this->assertTrue($cp['writes_state']);
        $this->assertFalse($cp['ledger_record_invoked']);
        $this->assertFalse($cp['canonical_doc_write_allowed']);
        $this->assertFalse($cp['provider_invoked']);
        $this->assertFalse($cp['generates_code']);
    }

    // ---------- aggregate verify over dossier ----------

    public function test_verify_aggregates_dossier_anchors(): void
    {
        $svc = $this->service();
        $dossier = [
            'area_id' => 'agentic_engineering_os',
            'anchors' => [
                ['anchor_type' => 'cycle_id', 'anchor_claim' => 'real', 'source_path' => 'cycle_id'],
                ['anchor_type' => 'cycle_id', 'anchor_claim' => 'fake', 'source_path' => 'cycle_id'],
                ['anchor_type' => 'repro_cmd', 'anchor_claim' => 'echo hi', 'source_path' => 'repro'],
            ],
        ];

        $result = $svc->verify($dossier, ['cycles' => [['cycle_id' => 'real']]]);

        $this->assertSame(3, $result['anchor_count']);
        $this->assertSame(1, $result['confirmed_count']);
        $this->assertSame(2, $result['refuted_count']);
        $this->assertFalse($result['all_confirmed']);
        $this->assertNotSame('', $result['verification_hash']);
    }
}
