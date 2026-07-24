<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Control;

use App\Services\Ai\Aaeos\Control\AaeosCycleRuntime;
use PHPUnit\Framework\TestCase;

final class AaeosReceiptHonestyTest extends TestCase
{
    public function test_dry_receipt_never_claims_a_runtime_or_evidence_write(): void
    {
        $receipt = (new AaeosCycleRuntime)->runCycle('fix a bounded validation bug', [], [], true);

        $this->assertTrue($receipt['dry_run']);
        $this->assertFalse($receipt['runtime_write_performed']);
        $this->assertSame('skipped', $receipt['evidence_status']);
        $this->assertArrayNotHasKey('human_in_engineering_loop', $receipt);
    }
}
