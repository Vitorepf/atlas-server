<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasSurfaceAdapterService;
use Tests\TestCase;

/**
 * Pins the documented "Contratos", "Fluxo", "Regras para IA" and "Escopo" of the
 * Surface Adapter: it normalizes a raw surface event into the common payload,
 * never carries execution/provider/policy, never infers a plan, requires origin
 * for audit, and only flows to `atlas-input`.
 *
 * @see docs/engineering-knowledge-base/system-graph/surface-adapter.md
 */
class AtlasSurfaceAdapterTest extends TestCase
{
    private function service(): AtlasSurfaceAdapterService
    {
        return new AtlasSurfaceAdapterService();
    }

    /**
     * Fluxo: a clean raw event normalizes to a payload that preserves origin and
     * flows only to `atlas-input`. Escopo: a paste of code + a file upload become
     * one normalized payload with deterministically classified attachments.
     */
    public function test_clean_event_normalizes_and_flows_to_atlas_input(): void
    {
        $result = $this->service()->normalize([
            'surface' => 'atlas_code',
            'origin' => 'operator',
            'channel' => 'desktop',
            'content' => 'function add(a,b){return a+b}',
            'files' => [['name' => 'spec.md', 'bytes' => 10]],
        ]);

        $this->assertSame(AtlasSurfaceAdapterService::OUTCOME_NORMALIZED, $result['outcome']);
        $this->assertSame('atlas-input', $result['flows_to']);
        $this->assertNotNull($result['normalized']);
        // Fluxo: the payload itself only ever flows to atlas-input.
        $this->assertSame('atlas-input', $result['normalized']['flows_to']);
        // Fluxo + Riscos: origin is preserved verbatim for audit.
        $this->assertSame('operator', $result['normalized']['origin']);
        // Escopo: text + attachment => mixed format, file classified by source.
        $this->assertSame('mixed', $result['normalized']['format']);
        $this->assertSame('function add(a,b){return a+b}', $result['normalized']['content']);
        $this->assertCount(1, $result['normalized']['attachments']);
        $this->assertSame('file', $result['normalized']['attachments'][0]['kind']);
        // Invariant: the adapter never carries a decision.
        $this->assertFalse($result['normalized']['carries_decision']);
        $this->assertSame([], $result['violations']);
    }

    /**
     * Invariant (Contratos): "sem execucao, sem provider e sem policy" +
     * forbidden_changes "Executar ferramentas ou escolher modelo no adapter".
     * Such fields are stripped from the normalized payload and flagged.
     */
    public function test_execution_provider_policy_are_stripped_and_flagged(): void
    {
        $result = $this->service()->normalize([
            'surface' => 'atlas_cli',
            'origin' => 'operator',
            'content' => 'do the thing',
            'provider' => 'claude_code',
            'model' => 'opus',
            'policy' => 'autonomous',
            'execute' => 'run_tool',
        ]);

        $this->assertSame(AtlasSurfaceAdapterService::OUTCOME_NORMALIZED, $result['outcome']);
        // All forbidden fields detected, sorted + de-duplicated.
        $this->assertSame(['execute', 'model', 'policy', 'provider'], $result['stripped_fields']);
        // The normalized payload carries NONE of them — only origin/content/etc.
        $this->assertArrayNotHasKey('provider', $result['normalized']);
        $this->assertArrayNotHasKey('policy', $result['normalized']);
        $this->assertArrayNotHasKey('execute', $result['normalized']);
        $this->assertSame(
            AtlasSurfaceAdapterService::INV_NO_EXECUTION_PROVIDER_POLICY,
            $result['violations'][0]['invariant'],
        );
    }

    /**
     * Riscos: "Perder origem do input e quebrar auditoria." A raw event with no
     * origin is rejected — the adapter refuses to guess and break the audit trail.
     */
    public function test_event_without_origin_is_rejected_for_audit(): void
    {
        $result = $this->service()->normalize([
            'surface' => 'atlas_api',
            'content' => 'no origin here',
        ]);

        $this->assertSame(AtlasSurfaceAdapterService::OUTCOME_REJECTED, $result['outcome']);
        $this->assertSame(AtlasSurfaceAdapterService::REJECT_MISSING_ORIGIN, $result['reject_reason']);
        $this->assertNull($result['normalized']);
        $this->assertSame(
            AtlasSurfaceAdapterService::INV_ORIGIN_REQUIRED_FOR_AUDIT,
            $result['violations'][0]['invariant'],
        );
    }

    /**
     * Regras para IA: "Se houver ambiguidade semantica, ela deve ir para etapas
     * posteriores." A plan/intent presented at the adapter level is NOT resolved;
     * it is flagged and recorded as unresolved for atlas-input — never acted on.
     */
    public function test_plan_inference_is_refused_and_deferred(): void
    {
        $result = $this->service()->normalize([
            'surface' => 'atlas_app',
            'origin' => 'operator',
            'content' => 'maybe build, maybe research',
            'plan' => 'build_then_ship',
            'resolved_intent' => 'ship',
        ]);

        $this->assertSame(AtlasSurfaceAdapterService::OUTCOME_NORMALIZED, $result['outcome']);
        // The adapter does not put a resolved plan on the payload.
        $this->assertArrayNotHasKey('plan', $result['normalized']);
        $this->assertArrayNotHasKey('resolved_intent', $result['normalized']);
        // Ambiguity is recorded as unresolved, deferred to later stages.
        $this->assertSame(['plan', 'resolved_intent'], $result['normalized']['unresolved']);
        $this->assertSame(
            AtlasSurfaceAdapterService::INV_NO_PLAN_INFERENCE,
            $result['violations'][0]['invariant'],
        );
    }

    /**
     * Escopo: attachment-only event with no text resolves to the 'attachment'
     * format, and an image source is classified as the 'image' kind by key alone
     * (no content inference).
     */
    public function test_attachment_only_event_classifies_image_kind(): void
    {
        $result = $this->service()->normalize([
            'surface' => 'atlas_mobile',
            'origin' => 'operator',
            'images' => [['url' => 'a.png'], ['url' => 'b.png']],
        ]);

        $this->assertSame('attachment', $result['normalized']['format']);
        $this->assertCount(2, $result['normalized']['attachments']);
        $this->assertSame('image', $result['normalized']['attachments'][0]['kind']);
        $this->assertSame('images', $result['normalized']['attachments'][0]['source']);
    }

    /**
     * respectsBoundary is a pure predicate over the no-execution/provider/policy
     * and no-plan invariants combined.
     */
    public function test_respects_boundary_predicate(): void
    {
        $clean = $this->service()->respectsBoundary([
            'surface' => 'atlas_api',
            'origin' => 'operator',
            'content' => 'x',
        ]);
        $this->assertTrue($clean['respects_invariant']);
        $this->assertSame([], $clean['offending_fields']);

        $dirty = $this->service()->respectsBoundary([
            'surface' => 'atlas_api',
            'origin' => 'operator',
            'provider' => 'gemini',
            'plan' => 'ship',
        ]);
        $this->assertFalse($dirty['respects_invariant']);
        $this->assertSame(['plan', 'provider'], $dirty['offending_fields']);
    }
}
