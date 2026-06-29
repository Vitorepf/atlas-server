<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the autopoietic scope-governance pipeline is live at the operator surface: an incomplete proposal is
 * refused with recorded blocking reasons, and a well-formed proposal exposes the admission verdict as booleans.
 */
final class AtlasLoopScopeGovernancePreviewCommandTest extends TestCase
{
    public function test_incomplete_proposal_is_not_admitted_with_reasons(): void
    {
        // No options, no seed → empty descriptor: missing root + missing fields + no operator receipt.
        $exit = Artisan::call('atlas:loop:scope-governance-preview', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertFalse($decoded['admitted']);
        $this->assertNotEmpty($decoded['blocking_reasons']);
    }

    public function test_well_formed_proposal_exposes_boolean_verdict(): void
    {
        $this->app->bind('atlas.loop.scope_governance_preview.proposal', fn (): array => [
            'scope_id' => 'marketing',
            'namespace' => 'App\\Services\\Ai\\Marketing',
            'operator_intent' => ['grow the marketing capability'],
            'root' => 'app/Services/Ai/Marketing',
            'operator_receipt' => 'op-receipt-123',
        ]);

        $exit = Artisan::call('atlas:loop:scope-governance-preview', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertIsBool($decoded['admitted']);
        $this->assertIsBool($decoded['requires_operator_receipt']);
        $this->assertTrue($decoded['admitted'], (string) json_encode($decoded));
        $this->assertSame([], $decoded['blocking_reasons']);
    }
}
