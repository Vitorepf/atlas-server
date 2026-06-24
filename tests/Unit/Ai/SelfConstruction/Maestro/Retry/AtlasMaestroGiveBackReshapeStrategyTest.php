<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Retry;

use App\Services\Ai\SelfConstruction\Maestro\Retry\AtlasMaestroGiveBackReshapeStrategy;
use Tests\TestCase;

final class AtlasMaestroGiveBackReshapeStrategyTest extends TestCase
{
    public function test_reshape_drops_forbidden_and_adds_anchor(): void
    {
        $proposal = (new AtlasMaestroGiveBackReshapeStrategy)->propose([
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/Maestro/Forbidden.php',
                'app/Services/Ai/SelfConstruction/Maestro/Retry/Existing.php',
            ],
            'forbidden_hits' => [
                'app/Services/Ai/SelfConstruction/Maestro/Forbidden.php',
            ],
            'scope_in' => [
                'app/Services/Ai/SelfConstruction/Maestro/Retry/Existing.php',
                'app/Services/Ai/SelfConstruction/Maestro/Retry/Anchor.php',
            ],
            'scope_in_mismatches' => [],
            'missing_symbol_traces' => [[
                'symbol' => 'AnchorSymbol',
                'anchor_file' => 'app/Services/Ai/SelfConstruction/Maestro/Retry/Anchor.php',
            ]],
        ]);

        $this->assertNotNull($proposal);
        $payload = $proposal->toArray();

        $this->assertSame([
            'app/Services/Ai/SelfConstruction/Maestro/Retry/Anchor.php',
            'app/Services/Ai/SelfConstruction/Maestro/Retry/Existing.php',
        ], $payload['allowed_files']);
        $this->assertSame('high', $payload['confidence']);
        $this->assertFalse($payload['empty']);
    }

    public function test_reshape_refuses_when_no_anchor_evidence(): void
    {
        $proposal = (new AtlasMaestroGiveBackReshapeStrategy)->propose([
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/Maestro/Retry/Existing.php',
            ],
            'forbidden_hits' => [],
            'scope_in' => [
                'app/Services/Ai/SelfConstruction/Maestro/Retry/Existing.php',
            ],
            'scope_in_mismatches' => [],
            'missing_symbol_traces' => [],
        ]);

        $this->assertNotNull($proposal);
        $payload = $proposal->toArray();

        $this->assertSame([], $payload['allowed_files']);
        $this->assertSame('none', $payload['confidence']);
        $this->assertTrue($payload['empty']);
    }
}
