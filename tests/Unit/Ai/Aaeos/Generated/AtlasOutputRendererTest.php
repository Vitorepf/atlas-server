<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasOutputRendererService;
use Tests\TestCase;

/**
 * Pins the documented "Contratos", "Escopo de Implementacao", "Regras para IA"
 * and "Riscos" of the Output Renderer: it adapts presentation per surface but
 * never alters canonical truth, never omits receipt/evidence, and never hides
 * failures or warnings.
 *
 * @see docs/engineering-knowledge-base/system-graph/output-renderer.md
 */
class AtlasOutputRendererTest extends TestCase
{
    private function service(): AtlasOutputRendererService
    {
        return new AtlasOutputRendererService();
    }

    /**
     * forbidden_changes: "Omitir receipt ou evidencia relevante da saida."
     * Exemplos: plan, receipt and evidence are separate panels bound to one
     * event. The risk panel for warnings is non-suppressible.
     */
    public function test_receipt_evidence_and_risk_panels_are_always_present(): void
    {
        $rendered = $this->service()->render([
            'kind' => AtlasOutputRendererService::KIND_PLAN,
            'event_id' => 'evt_1',
            'content' => ['steps' => ['a', 'b']],
            'receipt' => ['signed' => true],
            'evidence' => ['ledger_ref' => 'led_1'],
            'warnings' => ['flaky test'],
        ], 'atlas_code');

        $kinds = $rendered['presentation']['panel_kinds'];
        $this->assertContains('plan', $kinds);
        $this->assertContains('receipt', $kinds);
        $this->assertContains('evidence', $kinds);
        $this->assertContains('risk', $kinds);

        // Receipt, evidence and risk panels must be non-suppressible.
        foreach ($rendered['presentation']['panels'] as $panel) {
            if (in_array($panel['kind'], ['receipt', 'evidence', 'risk'], true)) {
                $this->assertFalse(
                    $panel['suppressible'],
                    "Panel {$panel['kind']} must not be suppressible.",
                );
            }
        }

        // Every panel is bound to the same canonical event id.
        $this->assertSame('evt_1', $rendered['event_id']);
    }

    /**
     * Invariante: "render nao altera dado canonico." + Riscos: "Output
     * divergente entre surfaces." The SAME result rendered to two different
     * surfaces yields the SAME canonical fingerprint and the SAME panel kinds —
     * only the layout density differs.
     */
    public function test_same_result_across_surfaces_keeps_identical_truth(): void
    {
        $result = [
            'kind' => AtlasOutputRendererService::KIND_RECEIPT,
            'event_id' => 'evt_2',
            'content' => ['decision' => 'approved'],
            'receipt' => ['signed' => true],
            'evidence' => ['ledger_ref' => 'led_2'],
        ];

        $code = $this->service()->render($result, 'atlas_code');
        $mobile = $this->service()->render($result, 'atlas_mobile');

        // Same truth -> same fingerprint and same panel kinds.
        $this->assertSame($code['canonical_fingerprint'], $mobile['canonical_fingerprint']);
        $this->assertSame(
            $code['presentation']['panel_kinds'],
            $mobile['presentation']['panel_kinds'],
        );

        // Only the layout (presentation density) differs per surface.
        $this->assertSame('panels', $code['layout']);
        $this->assertSame('cards', $mobile['layout']);
        $this->assertNotSame($code['layout'], $mobile['layout']);

        // Canonical content is carried verbatim, never altered.
        $this->assertFalse($code['altered_canonical']);
        $this->assertSame(['decision' => 'approved'], $code['canonical']['content']);
    }

    /**
     * Proibido: "remover falhas ou warnings." + Riscos: "UI esconder risco por
     * estetica." An input that asks to hide warnings is FLAGGED as a violation,
     * and the warnings are rendered anyway in the non-suppressible risk panel.
     */
    public function test_attempt_to_hide_warnings_is_flagged_and_warnings_still_render(): void
    {
        $rendered = $this->service()->render([
            'kind' => AtlasOutputRendererService::KIND_RESPONSE,
            'event_id' => 'evt_3',
            'content' => 'done',
            'failures' => ['build failed on lint'],
            'warnings' => ['deprecation used'],
            'hide_warnings' => true,
            'suppress_failures' => true,
        ], 'atlas_code');

        // The suppression attempt is recorded as a violation.
        $this->assertCount(1, $rendered['violations']);
        $this->assertSame(
            AtlasOutputRendererService::INV_NO_RISK_SUPPRESSION,
            $rendered['violations'][0]['invariant'],
        );

        // The risk panel still exists and still carries both lists verbatim.
        $risk = null;
        foreach ($rendered['presentation']['panels'] as $panel) {
            if ($panel['kind'] === 'risk') {
                $risk = $panel;
            }
        }
        $this->assertNotNull($risk);
        $this->assertSame(['build failed on lint'], $risk['content']['failures']);
        $this->assertSame(['deprecation used'], $risk['content']['warnings']);
    }

    /**
     * Regras para IA: "manter separacao entre conteudo canonico e apresentacao
     * visual." preservesTruth confirms the rendered output preserves truth, and
     * a tampered rendered canonical is detected as a violation.
     */
    public function test_preserves_truth_audit_detects_tampering(): void
    {
        $source = [
            'kind' => AtlasOutputRendererService::KIND_PLAN,
            'event_id' => 'evt_4',
            'content' => ['steps' => ['x', 'y']],
            'receipt' => ['signed' => true],
            'warnings' => ['minor'],
        ];

        $rendered = $this->service()->render($source, 'atlas_cli');

        // A faithful render preserves truth.
        $clean = $this->service()->preservesTruth($source, $rendered);
        $this->assertTrue($clean['preserves_truth']);
        $this->assertSame([], $clean['violations']);

        // Tamper: rewrite the rendered canonical content -> must be caught.
        $tampered = $rendered;
        $tampered['canonical']['content'] = ['steps' => ['x']];
        $audit = $this->service()->preservesTruth($source, $tampered);
        $this->assertFalse($audit['preserves_truth']);
        $this->assertSame(
            AtlasOutputRendererService::INV_NO_CANONICAL_MUTATION,
            $audit['violations'][0]['invariant'],
        );
    }

    /**
     * Invariante: re-ordering presentation keys must NOT read as a truth change
     * (associative key order is presentation), but a list's order IS canonical
     * (a plan's step order is truth, not presentation).
     */
    public function test_fingerprint_is_key_order_insensitive_but_list_order_sensitive(): void
    {
        $a = $this->service()->render([
            'kind' => AtlasOutputRendererService::KIND_RECEIPT,
            'event_id' => 'evt_5',
            'content' => ['alpha' => 1, 'beta' => 2],
        ], 'atlas_code');

        // Same map, different key order -> same fingerprint.
        $b = $this->service()->render([
            'kind' => AtlasOutputRendererService::KIND_RECEIPT,
            'event_id' => 'evt_5',
            'content' => ['beta' => 2, 'alpha' => 1],
        ], 'atlas_code');

        $this->assertSame($a['canonical_fingerprint'], $b['canonical_fingerprint']);

        // Same list, different ORDER -> different fingerprint (order is truth).
        $c = $this->service()->render([
            'kind' => AtlasOutputRendererService::KIND_PLAN,
            'event_id' => 'evt_5',
            'content' => ['steps' => ['one', 'two']],
        ], 'atlas_code');
        $d = $this->service()->render([
            'kind' => AtlasOutputRendererService::KIND_PLAN,
            'event_id' => 'evt_5',
            'content' => ['steps' => ['two', 'one']],
        ], 'atlas_code');

        $this->assertNotSame($c['canonical_fingerprint'], $d['canonical_fingerprint']);
    }

    /** Unknown kinds degrade to a safe 'response' panel rather than erroring. */
    public function test_unknown_kind_degrades_to_response(): void
    {
        $rendered = $this->service()->render([
            'kind' => 'totally_unknown',
            'event_id' => 'evt_6',
            'content' => 'hi',
        ], 'atlas_api');

        $this->assertSame(AtlasOutputRendererService::KIND_RESPONSE, $rendered['kind']);
        $this->assertSame('json', $rendered['layout']);
        $this->assertSame('surface-plane', $rendered['flows_to']);
    }
}
