<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Aaeos;

use App\Services\Ai\Aaeos\Control\AaeosAdmissionPolicy;
use App\Services\Ai\Aaeos\Control\AaeosAdmissionVerdict;
use App\Services\Ai\Aaeos\Control\AaeosCycleRuntime;
use App\Services\Ai\Aaeos\Control\AaeosDifficultyLevel;
use App\Services\Ai\Aaeos\Control\AaeosExecutorMode;
use App\Services\Ai\Aaeos\Control\AaeosScorecardProjector;
use Tests\TestCase;

/**
 * P1b.3: AAEOS projects native authorization/observation refs only —
 * cannot self-mint observed authority (anti-laundering).
 */
final class AaeosAuthorityLaunderingTest extends TestCase
{
    public function test_admission_refuses_self_minted_observed_authority(): void
    {
        $policy = new AaeosAdmissionPolicy;
        $result = $policy->admit(
            ['irreversible' => false, 'mint_observed_authority' => true],
            ['level' => AaeosDifficultyLevel::L1],
            ['mode' => AaeosExecutorMode::DEV],
            [],
        );

        $this->assertSame(AaeosAdmissionVerdict::REPAIR_REQUIRED, $result['verdict']);
        $this->assertFalse($result['allows_execution']);
        $this->assertContains('aaeos_cannot_self_mint_observed_authority', $result['reasons']);
    }

    public function test_cycle_projects_native_refs_and_refuses_hint_laundering(): void
    {
        $runtime = app(AaeosCycleRuntime::class);
        $receipt = $runtime->runCycle(
            'p1b3 projection only dry run',
            [
                'decision_event_id' => 'native-decision-from-owner',
                'decision_receipt_hash' => str_repeat('ab', 32),
                'observed_authority' => ['fabricated' => true],
                'minted_decision_event_id' => 'should-not-project',
            ],
            [],
            true,
        );

        $projection = $receipt['native_authority_projection'] ?? [];
        $this->assertSame('atlas.aaeos.native_authority_projection.v1', $projection['schema'] ?? null);
        $this->assertFalse((bool) ($projection['self_minted'] ?? true));
        $this->assertSame('native-decision-from-owner', $projection['projected']['decision_event_id'] ?? null);
        $this->assertContains('observed_authority', $projection['refused_self_mint'] ?? []);
        $this->assertContains('minted_decision_event_id', $projection['refused_self_mint'] ?? []);
        $this->assertArrayNotHasKey('minted_decision_event_id', $projection['projected'] ?? []);
    }

    public function test_scorecard_does_not_mint_authority_and_refuses_self_mint_hints(): void
    {
        $card = (new AaeosScorecardProjector)->project([
            'decision_event_id' => 'measured-decision-1',
            'self_sealed_authority' => ['nope' => true],
            'cycles_total' => 1,
        ]);

        $this->assertFalse((bool) ($card['runtime_write_performed'] ?? true));
        $projection = $card['native_authority_projection'] ?? [];
        $this->assertSame('measured-decision-1', $projection['projected']['decision_event_id'] ?? null);
        $this->assertContains('self_sealed_authority', $projection['refused_self_mint'] ?? []);
        $this->assertFalse((bool) ($projection['self_minted'] ?? true));
    }
}
