<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel;

use App\Services\Ai\EngineeringKernel\Quality\RivalsClaimAuthorityStaticScanner;
use PHPUnit\Framework\TestCase;

final class RivalsClaimAuthorityStaticScannerTest extends TestCase
{
    public function test_external_claim_writers_are_blocked_but_rivals_is_allowlisted(): void
    {
        $result = (new RivalsClaimAuthorityStaticScanner)->scan([
            'app/Services/Ai/Other/Bad.php' => "<?php\nreturn ['claim_eligible' => true, 'world_leading' => true];",
            'app/Services/Ai/Rivals/Core/RivalsClaimAuthority.php' => "<?php\nreturn ['claim_eligible' => true, 'world_leading' => true];",
        ]);

        $this->assertSame('blocked', $result['status']);
        $this->assertCount(2, $result['violations']);
        $this->assertSame('app/Services/Ai/Other/Bad.php', $result['violations'][0]['file']);
    }

    public function test_contract_mentions_and_ineligible_outcomes_are_not_claim_writes(): void
    {
        $result = (new RivalsClaimAuthorityStaticScanner)->scan([
            'app/Services/Ai/EngineeringKernel/Quality/Contract.php' => "<?php\nreturn ['event' => 'claim.issued', 'claim_eligible' => false];",
        ]);

        $this->assertSame('pass', $result['status']);
        $this->assertSame([], $result['violations']);
    }
}
