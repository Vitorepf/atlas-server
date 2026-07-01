<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionCompletionEvidenceHashService;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionCompletionEvidenceHashServiceTest extends TestCase
{
    public function test_identical_nested_evidence_with_different_key_order_produces_same_hash(): void
    {
        $svc = new AtlasSelfConstructionCompletionEvidenceHashService;

        $a = ['outer' => ['b' => 2, 'a' => 1], 'x' => 1];
        $b = ['x' => 1, 'outer' => ['a' => 1, 'b' => 2]];

        $this->assertSame($svc->runtimePromotionReceiptHash($a), $svc->runtimePromotionReceiptHash($b));
    }

    public function test_top_level_and_nested_volatile_fields_do_not_change_hash(): void
    {
        $svc = new AtlasSelfConstructionCompletionEvidenceHashService;

        $base = ['task_id' => 't1', 'nested' => ['result' => 'green']];
        $withVolatile = [
            'task_id' => 't1',
            'receipt_hash' => 'abc',
            'persisted_at' => '2026-01-01',
            'verified_at' => '2026-01-02',
            'certification_hash' => 'zzz',
            'nested' => [
                'result' => 'green',
                'smoke_hash' => 'nested-hash',
                'persisted_at' => '2026-01-03',
            ],
        ];

        $this->assertSame(
            $svc->runtimePromotionReceiptHash($base),
            $svc->runtimePromotionReceiptHash($withVolatile),
        );
        $this->assertSame(
            $svc->humanCompletionReceiptHash($base),
            $svc->humanCompletionReceiptHash($withVolatile),
        );
        $this->assertSame(
            $svc->realProviderSmokeHash($base),
            $svc->realProviderSmokeHash($withVolatile),
        );
    }

    public function test_substantive_nested_evidence_change_changes_the_hash(): void
    {
        $svc = new AtlasSelfConstructionCompletionEvidenceHashService;

        $a = ['nested' => ['result' => 'green']];
        $b = ['nested' => ['result' => 'red']];

        $this->assertNotSame($svc->runtimePromotionReceiptHash($a), $svc->runtimePromotionReceiptHash($b));
    }
}
