<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\EvidenceRef;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class EvidenceRefTest extends TestCase
{
    use SchemaContractAssertions;

    public function test_canonical_array_preserves_key_when_governance_ref_is_null(): void
    {
        $ref = new EvidenceRef(kind: 'diff', path: 'storage/atlas-dev/receipts/r1/diff.patch', hash: 'abc');

        $canonical = $ref->toCanonicalArray();
        $this->assertArrayHasKey('governance_ledger_ref', $canonical);
        $this->assertNull($canonical['governance_ledger_ref']);
        $this->assertSame(['governance_ledger_ref', 'hash', 'kind', 'path'], array_keys($canonical));
        $this->assertCanonicalArrayKeysSorted($ref);
    }

    public function test_canonical_array_round_trip_with_governance_ref(): void
    {
        $ref = new EvidenceRef(
            kind: 'test_log',
            path: 'storage/atlas-dev/receipts/r1/test.log',
            hash: 'def',
            governanceLedgerRef: 'atlas_engineering_evidence:42',
        );

        $rebuilt = EvidenceRef::fromArray($ref->toCanonicalArray());
        $this->assertHashStable($ref, $rebuilt);
        $this->assertSame('atlas_engineering_evidence:42', $rebuilt->governanceLedgerRef);
        $this->assertContractSurface($ref);
    }

    public function test_hash_differs_when_governance_ref_present_vs_absent(): void
    {
        $without = new EvidenceRef(kind: 'diff', path: 'p', hash: 'h');
        $with = new EvidenceRef(kind: 'diff', path: 'p', hash: 'h', governanceLedgerRef: 'ledger:1');

        $this->assertHashIsSha256($without);
        $this->assertHashDiffers($without, $with);
    }

    public function test_unknown_kind_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new EvidenceRef(kind: 'invented_kind', path: 'p', hash: 'h');
    }

    public function test_empty_path_or_hash_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new EvidenceRef(kind: 'diff', path: '', hash: 'h');
    }

    public function test_with_governance_ledger_ref_returns_new_instance_preserving_other_fields(): void
    {
        $ref = new EvidenceRef(kind: 'diff', path: 'p', hash: 'h');
        $promoted = $ref->withGovernanceLedgerRef('ledger:99');

        $this->assertNotSame($ref, $promoted);
        $this->assertNull($ref->governanceLedgerRef);
        $this->assertSame('ledger:99', $promoted->governanceLedgerRef);
        $this->assertSame($ref->hash, $promoted->hash);
        $this->assertSame($ref->path, $promoted->path);
    }
}
