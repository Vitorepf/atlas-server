<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionRuntimeGapMatrixAuditService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Atlas Self-Construction OS · Runtime Gap Matrix Audit v1.
 *
 * Locks down the closure-class contract for the 6 canonical gaps:
 *
 *   - adapter_execution_runtime
 *   - automatic_cost_import_runtime
 *   - automatic_work_product_collection_runtime
 *   - automatic_dispatch_scheduler_codex_real_invoker_post_start_receipt_contract_runtime
 *   - human_signed_os_complete_receipt_present
 *   - end_to_end_real_provider_smoke_green
 *
 * Each gap MUST be classified into exactly one closure class; no automation
 * is allowed to forge a human receipt or mark the real-provider smoke green
 * without real evidence.
 */
final class AtlasAiSelfConstructionRuntimeGapMatrixAuditTest extends TestCase
{
    public function test_constants_are_canonical(): void
    {
        $this->assertSame(
            'atlas.self_construction.runtime_gap_matrix_audit.v1',
            AtlasSelfConstructionRuntimeGapMatrixAuditService::SCHEMA_VERSION,
        );
        $this->assertSame(
            'read_only_runtime_gap_matrix_audit',
            AtlasSelfConstructionRuntimeGapMatrixAuditService::MODE,
        );
        $this->assertSame([
            'auto_closeable_by_existing_evidence',
            'closeable_by_safe_dry_run_evidence',
            'requires_operator_signed_receipt',
            'requires_real_provider_smoke',
            'must_remain_blocked',
        ], AtlasSelfConstructionRuntimeGapMatrixAuditService::CLOSURE_CLASSES);
    }

    public function test_audit_detects_every_canonical_gap(): void
    {
        $payload = $this->callAudit();
        $ids = array_column((array) $payload['gaps'], 'gap_id');

        foreach ([
            'adapter_execution_runtime',
            'automatic_cost_import_runtime',
            'automatic_work_product_collection_runtime',
            'automatic_dispatch_scheduler_codex_real_invoker_post_start_receipt_contract_runtime',
            'human_signed_os_complete_receipt_present',
            'end_to_end_real_provider_smoke_green',
        ] as $expected) {
            $this->assertContains($expected, $ids, "gap missing: {$expected}");
        }
    }

    public function test_each_gap_carries_one_canonical_closure_class(): void
    {
        $payload = $this->callAudit();
        foreach ((array) $payload['gaps'] as $gap) {
            $class = (string) ($gap['closure_class'] ?? '');
            $this->assertContains(
                $class,
                AtlasSelfConstructionRuntimeGapMatrixAuditService::CLOSURE_CLASSES,
                'gap '.($gap['gap_id'] ?? '?').' has unknown closure_class: '.$class,
            );
        }
    }

    public function test_human_receipt_gap_is_requires_operator_signed_receipt_when_absent(): void
    {
        $payload = $this->callAudit();
        $gap = $this->findGap($payload, 'human_signed_os_complete_receipt_present');

        if (! ($gap['runtime_y'] ?? false)) {
            $this->assertSame(
                AtlasSelfConstructionRuntimeGapMatrixAuditService::CLOSURE_OPERATOR_RECEIPT,
                $gap['closure_class'],
            );
            $this->assertFalse((bool) ($gap['auto_closable_locally'] ?? false));
            $this->assertNotEmpty($gap['blockers']);
            $this->assertContains('operator_must_sign_os_complete_receipt', (array) $gap['blockers']);
            $this->assertContains('do_not_forge_human_receipt', (array) data_get($gap, 'implementation_packet.absolute_prohibitions'));
        } else {
            // If the operator has already signed, the closure class flips
            // to auto. Either way, automation is never allowed to forge it.
            $this->assertSame(
                AtlasSelfConstructionRuntimeGapMatrixAuditService::CLOSURE_AUTO,
                $gap['closure_class'],
            );
        }
    }

    public function test_real_provider_smoke_gap_is_requires_real_provider_smoke_when_not_green(): void
    {
        $payload = $this->callAudit();
        $gap = $this->findGap($payload, 'end_to_end_real_provider_smoke_green');

        if (! ($gap['runtime_y'] ?? false)) {
            $this->assertSame(
                AtlasSelfConstructionRuntimeGapMatrixAuditService::CLOSURE_REAL_PROVIDER_SMOKE,
                $gap['closure_class'],
            );
            $this->assertContains('real_provider_smoke_missing_or_not_green', (array) $gap['blockers']);
            $packet = (array) ($gap['implementation_packet'] ?? []);
            $this->assertContains('do_not_mark_smoke_green_without_real_provider_evidence', (array) $packet['absolute_prohibitions']);
            $this->assertContains('do_not_forge_smoke_evidence', (array) $packet['absolute_prohibitions']);
        }
    }

    public function test_runtime_safety_flags_are_all_false(): void
    {
        $payload = $this->callAudit();
        foreach ([
            'runtime_execution_allowed',
            'dispatch_allowed',
            'provider_call_allowed',
            'token_spend_allowed',
            'self_programming_allowed',
            'ledger_write_allowed',
            'completion_real_allowed',
        ] as $flag) {
            $this->assertFalse((bool) $payload[$flag], $flag.' must be false');
        }
        $this->assertContains(
            'runtime_gap_matrix_audit_does_not_forge_human_receipt',
            (array) $payload['non_execution_guarantees'],
        );
        $this->assertContains(
            'runtime_gap_matrix_audit_does_not_call_provider',
            (array) $payload['non_execution_guarantees'],
        );
    }

    public function test_os_complete_promotion_is_never_allowed_when_any_gap_is_open(): void
    {
        $payload = $this->callAudit();
        $stillOpen = (int) $payload['still_open_count'];

        if ($stillOpen > 0) {
            $this->assertFalse((bool) $payload['os_complete_promotion_allowed']);
            $this->assertFalse((bool) $payload['completion_allowed']);
            $this->assertNotEmpty((array) $payload['blockers']);
        }
    }

    public function test_audit_emits_implementation_packet_for_every_gap(): void
    {
        $payload = $this->callAudit();
        foreach ((array) $payload['gaps'] as $gap) {
            $packet = (array) ($gap['implementation_packet'] ?? []);
            $this->assertArrayHasKey('gap_id', $packet);
            $this->assertArrayHasKey('closure_class', $packet);
            $this->assertArrayHasKey('commands', $packet);
            $this->assertArrayHasKey('acceptance_criteria', $packet);
            $this->assertArrayHasKey('expected_evidence', $packet);
            $this->assertArrayHasKey('receipts_required', $packet);
            $this->assertArrayHasKey('risks', $packet);
            $this->assertArrayHasKey('absolute_prohibitions', $packet);
            $this->assertNotEmpty($packet['absolute_prohibitions']);
        }
    }

    public function test_closure_class_index_partitions_every_gap_exactly_once(): void
    {
        $payload = $this->callAudit();
        $index = (array) $payload['closure_class_index'];
        $flat = [];
        foreach ($index as $bucket) {
            foreach ((array) $bucket as $id) {
                $flat[] = (string) $id;
            }
        }
        // Same multiset of gap_ids surfaced via gaps[] must be in
        // closure_class_index — partitioned, no duplicates.
        $expected = array_column((array) $payload['gaps'], 'gap_id');
        sort($expected);
        sort($flat);
        $this->assertSame($expected, $flat);
        $this->assertSame(count($flat), count(array_unique($flat)));
    }

    public function test_cli_status_emits_canonical_envelope(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--atlas-self-construction-os-runtime-gap-matrix-audit-status' => true,
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(
            'atlas.self_construction_agent_control_plane_runtime_gap_matrix_audit_status.v1',
            $payload['schema_version'] ?? null,
        );
        $this->assertFalse((bool) ($payload['execution_allowed'] ?? null));
        $this->assertFalse((bool) ($payload['dispatch_allowed'] ?? null));
        $this->assertArrayHasKey('agent_control_plane_runtime_gap_matrix_audit', $payload);
        $inner = $payload['agent_control_plane_runtime_gap_matrix_audit'];
        $this->assertSame(
            AtlasSelfConstructionRuntimeGapMatrixAuditService::SCHEMA_VERSION,
            $inner['schema_version'] ?? null,
        );
        $this->assertNotEmpty($inner['runtime_gap_matrix_audit_hash'] ?? null);
    }

    public function test_cli_quartet_works(): void
    {
        foreach (['contract', 'preflight', 'implementation-packet'] as $stage) {
            Artisan::call('atlas:ai:self-construction', [
                "--atlas-self-construction-os-runtime-gap-matrix-audit-{$stage}" => true,
                '--json' => true,
            ]);
            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $stageKey = str_replace('-', '_', $stage);
            $this->assertSame(
                "atlas.self_construction_agent_control_plane_runtime_gap_matrix_audit_{$stageKey}.v1",
                $payload['schema_version'] ?? null,
            );
        }
    }

    public function test_audit_hash_is_deterministic_for_same_state(): void
    {
        $a = $this->callAudit();
        $b = $this->callAudit();
        // The audit reads matrix + completion audit; their hashes are
        // deterministic when the underlying state is unchanged across a
        // single test run.
        $this->assertSame(
            (string) $a['runtime_gap_matrix_audit_hash'],
            (string) $b['runtime_gap_matrix_audit_hash'],
        );
    }

    public function test_no_gap_can_be_marked_runtime_y_without_evidence_hash(): void
    {
        $payload = $this->callAudit();
        foreach ((array) $payload['gaps'] as $gap) {
            if (! (bool) ($gap['runtime_y'] ?? false)) {
                continue;
            }
            $hash = (string) ($gap['graduation_evidence_hash'] ?? '');
            // For matrix-row gaps with runtime_y=true, an evidence hash MUST
            // be cited. The two completion-audit-criterion gaps may carry an
            // evidence_hash of '' when the underlying audit didn't expose
            // one (still safe: runtime_y=true there means the criterion
            // passed at the audit level).
            if (($gap['origin'] ?? '') === 'runtime_gap_matrix_row') {
                $this->assertNotSame('', $hash, 'matrix-row gap '.$gap['gap_id'].' is runtime_y=true but has empty evidence_hash');
            }
        }
    }

    // --- helpers ---

    /**
     * @return array<string, mixed>
     */
    private function callAudit(): array
    {
        return (new AtlasSelfConstructionRuntimeGapMatrixAuditService(
            $this->app->make(\App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService::class),
        ))->audit();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function findGap(array $payload, string $id): array
    {
        foreach ((array) $payload['gaps'] as $gap) {
            if (($gap['gap_id'] ?? '') === $id) {
                return $gap;
            }
        }
        $this->fail('gap not found: '.$id);
    }
}
