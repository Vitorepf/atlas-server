<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel;

use App\Services\Ai\EngineeringKernel\CanonicalKernelPayload;
use App\Services\Ai\EngineeringKernel\EvidenceReceipt;
use App\Services\Ai\EngineeringKernel\QualityFoundryEvidenceApplicabilityMatrix;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class EvidenceReceiptTest extends TestCase
{
    public function test_issue_roundtrips_a_content_addressed_receipt_with_full_provenance(): void
    {
        $hashes = $this->hashes();
        $receipt = EvidenceReceipt::issue(
            'integration', 'php artisan test tests/Integration/FooTest.php', 'phpunit', '12.5',
            ['scope' => 'app/Foo.php'], [['name' => 'contract', 'passed' => true]], 0, 30.0,
            $hashes['artifact_hash'], $hashes['scope_hash'], 'independent-verifier',
            '2026-07-12T00:00:00Z', $hashes['spec_hash'], $hashes['world_hash'], $hashes['file_hash'],
        );

        $roundTrip = EvidenceReceipt::fromArray($receipt->toArray());
        self::assertSame($receipt->canonicalHash(), $receipt->receiptHash);
        self::assertSame($receipt->toArray(), $roundTrip->toArray());
        self::assertTrue($receipt->bindsTo($hashes));
    }

    public function test_hash_or_required_provenance_tampering_is_rejected(): void
    {
        $hashes = $this->hashes();
        $receipt = EvidenceReceipt::issue(
            'unit', 'php artisan test', 'phpunit', '12.5', [], [], 0, 5.0,
            $hashes['artifact_hash'], $hashes['scope_hash'], 'verifier', '2026-07-12T00:00:00Z',
            $hashes['spec_hash'], $hashes['world_hash'], $hashes['file_hash'],
        );
        $tampered = $receipt->toArray();
        $tampered['assertions'] = [['name' => 'tampered']];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('evidence_receipt_hash_mismatch');
        EvidenceReceipt::fromArray($tampered);
    }

    public function test_matrix_rejects_a_receipt_bound_to_an_old_final_hash(): void
    {
        $old = $this->hashes();
        $receipt = EvidenceReceipt::issue(
            'unit', 'php artisan test', 'phpunit', '12.5', [], [['name' => 'unit', 'passed' => true]],
            0, 5.0, $old['artifact_hash'], $old['scope_hash'], 'verifier',
            '2026-07-12T00:00:00Z', $old['spec_hash'], $old['world_hash'], $old['file_hash'],
        );
        $final = $old;
        $final['file_hash'] = hash('sha256', 'new-file');
        $result = (new QualityFoundryEvidenceApplicabilityMatrix)->evaluate([
            'risk_class' => 'R0', 'final_hashes' => $final,
            'evidence' => ['unit' => ['status' => 'pass', 'receipt_hash' => $receipt->receiptHash, 'receipt' => $receipt->toArray()]],
        ]);

        self::assertFalse($result['accepted']);
        self::assertStringContainsString('evidence_invalid:unit', implode(',', $result['blockers']));
    }

    /** @return array<string,string> */
    private function hashes(): array
    {
        return [
            'artifact_hash' => CanonicalKernelPayload::hash(['artifact' => 'fixture']),
            'scope_hash' => CanonicalKernelPayload::hash(['scope' => ['app/Foo.php']]),
            'spec_hash' => hash('sha256', 'spec'),
            'world_hash' => hash('sha256', 'world'),
            'file_hash' => hash('sha256', 'file'),
        ];
    }
}
