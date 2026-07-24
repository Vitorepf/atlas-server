<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Aaeos;

use App\Services\Ai\Aaeos\Control\AaeosP4RealOperationGauntlet;
use Tests\TestCase;

final class AaeosP4NonTerminalExitZeroRejectionTest extends TestCase
{
    public function test_exit_zero_without_provider_and_authority_is_not_real_operation(): void
    {
        $receipt = AaeosP4RealOperationGauntlet::journeyReceipt('dev', [
            'exit_code' => 0,
            'stdout' => 'success-looking',
            'command' => 'bin/atlas dev x',
        ], [
            'plan_only' => false,
            'env' => [
                'ATLAS_P4_PG_PRODUCER_URL' => 'pgsql://atlas_p4_producer@localhost/atlas_p4',
                'ATLAS_P4_PG_VERIFIER_URL' => 'pgsql://atlas_p4_verifier@localhost/atlas_p4',
            ],
        ]);

        $this->assertSame(0, $receipt['exit_code']);
        $this->assertFalse($receipt['real_operation_qualified']);
        $this->assertTrue($receipt['exit_zero_alone_never_qualifies']);
        $this->assertContains('derived_capability_proofs_incomplete', $receipt['blockers']);
    }
}
