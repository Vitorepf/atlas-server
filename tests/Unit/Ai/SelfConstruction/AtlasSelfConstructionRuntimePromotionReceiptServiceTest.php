<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionCompletionEvidenceHashService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionRuntimePromotionReceiptService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasSelfConstructionRuntimePromotionReceiptServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function rows(): array
    {
        return [
            [
                'gap_id' => 'gap-a',
                'runtime_y' => false,
                'runtime_enabled' => false,
                'graduation_evidence_hash' => str_repeat('1', 64),
            ],
        ];
    }

    /** @param array<string,mixed> $overrides */
    private function receipt(array $overrides = []): array
    {
        $base = [
            'receipt_id' => 'receipt-001',
            'signed_by' => 'vitor-operator',
            'reason' => 'Reviewed runtime graduation candidates and approved runtime gap promotion for gap-a.',
            'runtime_gap_matrix_hash' => str_repeat('a', 64),
            'runtime_promotion_basis_hash' => str_repeat('b', 64),
            'runtime_promotion_closure_basis_hash' => str_repeat('c', 64),
            'promoted_gap_ids' => ['gap-a'],
            'graduation_evidence_hashes' => ['gap-a' => str_repeat('1', 64)],
            'runtime_promotion_approved' => true,
            'operator_reviewed_runtime_graduations' => true,
            'no_runtime_autopromotion_acknowledged' => true,
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
        ];
        $merged = array_merge($base, $overrides);
        $merged['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->runtimePromotionReceiptHash($merged);

        return $merged;
    }

    // ── AC1: required fields (existing behavior, backward-compatible default) ─

    public function test_valid_receipt_passes_verification(): void
    {
        $result = (new AtlasSelfConstructionRuntimePromotionReceiptService)->verify($this->receipt(), $this->rows());

        $this->assertSame('passed', $result['status']);
        $this->assertSame([], $result['violations']);
    }

    public function test_missing_receipt_id_is_reported(): void
    {
        $receipt = $this->receipt();
        $receipt['receipt_id'] = '';

        $result = (new AtlasSelfConstructionRuntimePromotionReceiptService)->verify($receipt, $this->rows());

        $this->assertNotSame('passed', $result['status']);
        $codes = array_column($result['violations'], 'code');
        $this->assertContains('required_runtime_promotion_receipt_field_missing', $codes);
    }

    // ── AC1: extended fields (evidence_refs, verdict, rollback_plan, runtime_target) opt-in ──

    public function test_extended_fields_not_required_by_default(): void
    {
        $result = (new AtlasSelfConstructionRuntimePromotionReceiptService)->verify($this->receipt(), $this->rows());

        $this->assertSame('passed', $result['status']);
    }

    public function test_extended_fields_required_when_opted_in(): void
    {
        $result = (new AtlasSelfConstructionRuntimePromotionReceiptService)->verify(
            $this->receipt(),
            $this->rows(),
            requireExtendedFields: true,
        );

        $this->assertNotSame('passed', $result['status']);
        $missingFields = array_column(array_filter(
            $result['violations'],
            static fn (array $v): bool => $v['code'] === 'required_runtime_promotion_receipt_field_missing',
        ), 'field');
        $this->assertContains('verdict', $missingFields);
        $this->assertContains('rollback_plan', $missingFields);
        $this->assertContains('runtime_target', $missingFields);
        $this->assertContains('evidence_refs', $missingFields);
    }

    public function test_extended_fields_satisfied_passes_when_opted_in(): void
    {
        $receipt = $this->receipt([
            'verdict' => 'promote',
            'rollback_plan' => 'revert to previous runtime gate config via flag flip',
            'runtime_target' => 'gap-a',
            'evidence_refs' => ['ev-1.md', 'ev-2.md'],
        ]);

        $result = (new AtlasSelfConstructionRuntimePromotionReceiptService)->verify(
            $receipt,
            $this->rows(),
            requireExtendedFields: true,
        );

        $this->assertSame('passed', $result['status']);
    }

    // ── AC2: persisting the same receipt twice returns the existing receipt ───

    public function test_persist_twice_returns_already_persisted_without_duplicating(): void
    {
        $service = new AtlasSelfConstructionRuntimePromotionReceiptService;
        $receipt = $this->receipt();

        $first = $service->persist($receipt, $this->rows());
        $this->assertTrue($first['persisted']);
        $this->assertFalse($first['already_persisted']);

        $second = $service->persist($receipt, $this->rows());
        $this->assertTrue($second['persisted']);
        $this->assertTrue($second['already_persisted']);
        $this->assertSame($first['receipt_path'], $second['receipt_path']);

        $files = Storage::disk('local')->allFiles();
        $matching = array_filter($files, static fn (string $f): bool => str_ends_with($f, $first['receipt_hash'].'.json'));
        $this->assertCount(1, $matching);
    }

    public function test_persist_blocked_receipt_is_never_written_to_disk(): void
    {
        $receipt = $this->receipt();
        $receipt['receipt_id'] = '';

        $result = (new AtlasSelfConstructionRuntimePromotionReceiptService)->persist($receipt, $this->rows());

        $this->assertFalse($result['persisted']);
        $this->assertSame('runtime_promotion_receipt_verification_failed', $result['persistence_blocker']);
    }

    // ── AC3: replay lookup by stable receipt hash ──────────────────────────────

    public function test_replay_by_hash_finds_persisted_receipt(): void
    {
        $service = new AtlasSelfConstructionRuntimePromotionReceiptService;
        $receipt = $this->receipt();
        $persisted = $service->persist($receipt, $this->rows());

        $replay = $service->replayByHash($persisted['receipt_hash']);

        $this->assertTrue($replay['found']);
        $this->assertFalse($replay['tampered']);
        $this->assertSame('passed', $replay['status']);
        $this->assertSame($persisted['receipt_hash'], $replay['receipt_hash']);
    }

    public function test_replay_by_hash_reports_missing_for_unknown_hash(): void
    {
        $replay = (new AtlasSelfConstructionRuntimePromotionReceiptService)->replayByHash(str_repeat('9', 64));

        $this->assertFalse($replay['found']);
        $this->assertSame('receipt_not_found', $replay['blocker']);
    }

    public function test_replay_by_hash_reports_invalid_hash_format(): void
    {
        $replay = (new AtlasSelfConstructionRuntimePromotionReceiptService)->replayByHash('not-a-valid-hash');

        $this->assertFalse($replay['found']);
        $this->assertSame('receipt_hash_invalid', $replay['blocker']);
    }

    public function test_replay_by_hash_detects_tamper_when_stored_content_diverges_from_lookup_hash(): void
    {
        $service = new AtlasSelfConstructionRuntimePromotionReceiptService;
        $receipt = $this->receipt();
        $persisted = $service->persist($receipt, $this->rows());
        $path = 'atlas/self-construction/os-completion/runtime-promotion-receipts/'.$persisted['receipt_hash'].'.json';

        $stored = json_decode((string) Storage::disk('local')->get($path), true);
        $stored['reason'] = 'This reason was tampered with after persistence and no longer matches the original hash.';
        Storage::disk('local')->put($path, json_encode($stored));

        $replay = $service->replayByHash($persisted['receipt_hash']);

        $this->assertTrue($replay['found']);
        $this->assertTrue($replay['tampered']);
        $this->assertSame('receipt_hash_tamper_detected', $replay['blocker']);
        $this->assertSame('blocked', $replay['status']);
    }
}
