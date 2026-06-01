<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasSurfacePlaneService;
use Tests\TestCase;

/**
 * Pins the documented "Contratos", "Fluxo" and "Regras para IA" of the
 * Surface Plane: it admits/normalizes a surface interaction, never decides
 * provider/budget/autonomy/policy, and only flows to `surface-adapter`.
 *
 * @see docs/engineering-knowledge-base/system-graph/surface-plane.md
 */
class AtlasSurfacePlaneTest extends TestCase
{
    private function service(): AtlasSurfacePlaneService
    {
        return new AtlasSurfacePlaneService();
    }

    /** An official surface with a clean payload is admitted and flows to the adapter. */
    public function test_official_surface_is_admitted_and_flows_to_adapter(): void
    {
        $result = $this->service()->admit([
            'surface' => 'atlas_code',
            'channel' => 'desktop',
            'origin' => 'operator',
            'intent' => 'start_obra',
            'affordances' => ['text', 'attachment'],
            'payload' => ['prompt' => 'build feature x'],
        ]);

        $this->assertSame(AtlasSurfacePlaneService::DECISION_ADMIT, $result['decision']);
        $this->assertSame('surface-adapter', $result['flows_to']);
        $this->assertNotNull($result['event']);
        // Fluxo: the event itself only ever flows to the adapter.
        $this->assertSame('surface-adapter', $result['event']['flows_to']);
        // Fluxo: origin / channel / affordances are preserved verbatim.
        $this->assertSame('operator', $result['event']['origin']);
        $this->assertSame('desktop', $result['event']['channel']);
        $this->assertSame(['text', 'attachment'], $result['event']['affordances']);
        $this->assertSame([], $result['stripped_decisions']);
        $this->assertSame([], $result['violations']);
        $this->assertFalse($result['event']['carries_operational_decision']);
    }

    /**
     * Core invariant: "a surface nao escolhe provider, budget, autonomia ou
     * policy". When the interaction smuggles any of these, they are stripped
     * from the forwarded event and recorded as a boundary violation — but a
     * recognised surface is still admitted (the decision is just removed).
     */
    public function test_surface_decision_fields_are_stripped_and_flagged(): void
    {
        $result = $this->service()->admit([
            'surface' => 'atlas_cli',
            'intent' => 'run_task',
            'payload' => [
                'prompt' => 'do the thing',
                'provider' => 'claude_code',
                'policy' => 'autonomous',
                'autonomy' => 'L5',
                'budget' => 1000,
            ],
        ]);

        $this->assertSame(AtlasSurfacePlaneService::DECISION_ADMIT, $result['decision']);
        // All four forbidden keys detected, sorted + de-duplicated.
        $this->assertSame(['autonomy', 'budget', 'policy', 'provider'], $result['stripped_decisions']);
        // The forwarded payload no longer carries any decision; only the prompt.
        $this->assertSame(['prompt' => 'do the thing'], $result['event']['payload']);
        $this->assertCount(1, $result['violations']);
        $this->assertSame(
            AtlasSurfacePlaneService::INV_NO_OPERATIONAL_DECISION,
            $result['violations'][0]['invariant'],
        );
    }

    /** Invariant: an unknown surface is rejected, never silently forwarded. */
    public function test_unofficial_surface_is_rejected(): void
    {
        $result = $this->service()->admit([
            'surface' => 'random_webhook',
            'intent' => 'ping',
        ]);

        $this->assertSame(AtlasSurfacePlaneService::DECISION_REJECT, $result['decision']);
        $this->assertSame('unofficial_surface', $result['reject_reason']);
        $this->assertNull($result['event']);
        $this->assertSame(
            AtlasSurfacePlaneService::INV_OFFICIAL_SURFACE_ONLY,
            $result['violations'][0]['invariant'],
        );
    }

    /**
     * Risk antidote: "canais diferentes gerarem semantica divergente para o
     * mesmo pedido". The same logical request (intent + payload) on two
     * different surfaces/channels must normalize to the SAME signature, while a
     * genuinely different intent must NOT collide.
     */
    public function test_same_request_across_channels_yields_same_signature(): void
    {
        $code = $this->service()->admit([
            'surface' => 'atlas_code',
            'channel' => 'desktop',
            'intent' => 'start_obra',
            'payload' => ['target' => 'ecommerce', 'scope' => 'full'],
        ]);

        $mobile = $this->service()->admit([
            'surface' => 'atlas_mobile',
            'channel' => 'phone',
            // Same logical request, payload keys in a different order.
            'intent' => 'start_obra',
            'payload' => ['scope' => 'full', 'target' => 'ecommerce'],
        ]);

        $this->assertSame(
            $code['event']['request_signature'],
            $mobile['event']['request_signature'],
        );

        $different = $this->service()->admit([
            'surface' => 'atlas_code',
            'intent' => 'cancel_obra',
            'payload' => ['target' => 'ecommerce', 'scope' => 'full'],
        ]);

        $this->assertNotSame(
            $code['event']['request_signature'],
            $different['event']['request_signature'],
        );
    }

    /** Aliases resolve divergent labels to one official surface kind. */
    public function test_surface_alias_resolves_to_official_kind(): void
    {
        $result = $this->service()->admit([
            'surface' => 'terminal',
            'intent' => 'status',
        ]);

        $this->assertSame(AtlasSurfacePlaneService::DECISION_ADMIT, $result['decision']);
        $this->assertSame('atlas_cli', $result['surface']);
        $this->assertSame('cli', $result['surface_kind']);
    }

    /** respectsBoundary is a pure predicate over the no-decision invariant. */
    public function test_respects_boundary_predicate(): void
    {
        $clean = $this->service()->respectsBoundary([
            'surface' => 'atlas_api',
            'payload' => ['query' => 'x'],
        ]);
        $this->assertTrue($clean['respects_invariant']);
        $this->assertSame([], $clean['offending_keys']);

        $dirty = $this->service()->respectsBoundary([
            'surface' => 'atlas_api',
            'provider' => 'gemini',
        ]);
        $this->assertFalse($dirty['respects_invariant']);
        $this->assertSame(['provider'], $dirty['offending_keys']);
    }
}
