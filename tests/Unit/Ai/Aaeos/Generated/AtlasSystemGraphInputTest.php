<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasSystemGraphInputService;
use Tests\TestCase;

/**
 * Pins the documented "Contratos", "Fluxo", "Regras para IA", "Escopo" and
 * "Riscos" of Atlas Input: it forms a canonical input that preserves
 * origin/content/attachments/limits, mints a deterministic input_ref for
 * receipts, enforces the content budget, binds attachments to the thread/obra
 * relation, refuses to carry a resolved plan without intent routing, and flows
 * only to `operation-envelope`.
 *
 * @see docs/engineering-knowledge-base/system-graph/atlas-input.md
 */
class AtlasSystemGraphInputTest extends TestCase
{
    private function service(): AtlasSystemGraphInputService
    {
        return new AtlasSystemGraphInputService();
    }

    /**
     * Fluxo + Contratos: a clean normalized payload (text + an attachment) forms a
     * composite canonical input that preserves origin, flows only to
     * operation-envelope, and binds the attachment to the thread/obra relation.
     */
    public function test_clean_payload_forms_canonical_input_and_flows_to_envelope(): void
    {
        $result = $this->service()->formCanonical([
            'origin' => 'operator',
            'surface' => 'atlas_code',
            'content' => 'function add(a,b){return a+b}',
            'thread' => 'thread-7',
            'obra' => 'obra-ecommerce',
            'attachments' => [
                ['kind' => 'file', 'source' => 'files', 'attachment' => ['name' => 'spec.md']],
            ],
        ]);

        $this->assertSame(AtlasSystemGraphInputService::OUTCOME_CANONICAL, $result['outcome']);
        $this->assertSame('operation-envelope', $result['flows_to']);
        $this->assertNotNull($result['input']);
        // Fluxo: the canonical input itself only ever flows to the envelope.
        $this->assertSame('operation-envelope', $result['input']['flows_to']);
        // Invariante: origin preserved verbatim.
        $this->assertSame('operator', $result['input']['origin']);
        // Contratos: text + attachment => composite type.
        $this->assertSame(AtlasSystemGraphInputService::TYPE_COMPOSITE, $result['input']['type']);
        $this->assertSame('function add(a,b){return a+b}', $result['input']['content']);
        // Riscos: attachment is bound to the input_ref + thread/obra relation.
        $this->assertCount(1, $result['input']['attachments']);
        $this->assertTrue($result['input']['attachments'][0]['bound']);
        $this->assertSame('thread-7', $result['input']['attachments'][0]['thread']);
        $this->assertSame($result['input']['input_ref'], $result['input']['attachments'][0]['input_ref']);
        // Regras para IA: the input is a request, never a resolved objective.
        $this->assertFalse($result['input']['carries_decision']);
        $this->assertSame([], $result['violations']);
    }

    /**
     * Invariante (Contratos): "nenhum input pode perder origem" + forbidden_changes
     * "Descartar metadados necessarios para auditoria." A payload with no origin is
     * rejected — the step refuses to mint an untraceable canonical input.
     */
    public function test_payload_without_origin_is_rejected(): void
    {
        $result = $this->service()->formCanonical([
            'surface' => 'atlas_api',
            'content' => 'no origin here',
        ]);

        $this->assertSame(AtlasSystemGraphInputService::OUTCOME_REJECTED, $result['outcome']);
        $this->assertSame(AtlasSystemGraphInputService::REJECT_MISSING_ORIGIN, $result['reject_reason']);
        $this->assertNull($result['input']);
        $this->assertSame(
            AtlasSystemGraphInputService::INV_ORIGIN_REQUIRED,
            $result['violations'][0]['invariant'],
        );
    }

    /**
     * Escopo ("Proibido: transformar input em plano sem Intent Routing") + Regras
     * para IA ("nao assumir objetivo final sem roteamento de intencao"): a resolved
     * plan/objective on the payload is stripped from the canonical input and flagged.
     */
    public function test_resolved_plan_is_stripped_and_deferred_to_routing(): void
    {
        $result = $this->service()->formCanonical([
            'origin' => 'operator',
            'content' => 'maybe build, maybe research',
            'plan' => 'build_then_ship',
            'final_objective' => 'ship to prod',
        ]);

        $this->assertSame(AtlasSystemGraphInputService::OUTCOME_CANONICAL, $result['outcome']);
        // Detected, sorted + de-duplicated.
        $this->assertSame(['final_objective', 'plan'], $result['stripped_plan_fields']);
        // The canonical input carries NEITHER the plan nor the objective.
        $this->assertArrayNotHasKey('plan', $result['input']);
        $this->assertArrayNotHasKey('final_objective', $result['input']);
        $this->assertFalse($result['input']['carries_decision']);
        $this->assertSame(
            AtlasSystemGraphInputService::INV_NO_PLAN_WITHOUT_ROUTING,
            $result['violations'][0]['invariant'],
        );
    }

    /**
     * Riscos ("Entrada grande demais explodir contexto") + Escopo ("limite"):
     * content over the documented budget is preserved up to the limit (not dropped,
     * not passed whole), with the full original size recorded and a violation flagged.
     */
    public function test_oversized_content_is_truncated_to_the_documented_limit(): void
    {
        $limit = AtlasSystemGraphInputService::MAX_CONTENT_CHARS;
        $oversized = str_repeat('a', $limit + 500);

        $result = $this->service()->formCanonical([
            'origin' => 'operator',
            'content' => $oversized,
        ]);

        $this->assertSame(AtlasSystemGraphInputService::OUTCOME_CANONICAL, $result['outcome']);
        // Preserved exactly up to the budget — never the full oversized body.
        $this->assertSame($limit, $result['input']['limits']['preserved_content_chars']);
        $this->assertSame($limit + 500, $result['input']['limits']['original_content_chars']);
        $this->assertTrue($result['input']['limits']['truncated']);
        $this->assertSame($limit, mb_strlen($result['input']['content']));
        $this->assertSame(
            AtlasSystemGraphInputService::INV_CONTENT_LIMIT,
            $result['violations'][0]['invariant'],
        );
    }

    /**
     * Riscos ("Anexo perder relacao com a Obra ou thread"): when there is no
     * thread/obra, an attachment cannot be bound to a relation. The relation is
     * never silently dropped — the attachment is flagged bound=false and a
     * violation is recorded.
     */
    public function test_attachment_without_relation_is_flagged_not_dropped(): void
    {
        $result = $this->service()->formCanonical([
            'origin' => 'operator',
            'attachments' => [
                ['kind' => 'image', 'source' => 'images', 'attachment' => ['url' => 'a.png']],
            ],
        ]);

        $this->assertSame(AtlasSystemGraphInputService::OUTCOME_CANONICAL, $result['outcome']);
        // attachment-only => attachment type.
        $this->assertSame(AtlasSystemGraphInputService::TYPE_ATTACHMENT, $result['input']['type']);
        // Relation absent => not bound, but still present (never dropped).
        $this->assertCount(1, $result['input']['attachments']);
        $this->assertFalse($result['input']['attachments'][0]['bound']);
        $this->assertSame(
            AtlasSystemGraphInputService::INV_ATTACHMENT_RELATION,
            $result['violations'][0]['invariant'],
        );
    }

    /**
     * Proximas Acoes ("Documentar schema de input_ref usado em receipts"): the
     * input_ref is deterministic — same origin + content + relation => same ref —
     * so receipts/evidence can reference it reproducibly with no randomness/clock.
     */
    public function test_input_ref_is_deterministic_for_receipts(): void
    {
        $payload = [
            'origin' => 'operator',
            'content' => 'stable body',
            'thread' => 'thread-1',
        ];

        $a = $this->service()->formCanonical($payload);
        $b = $this->service()->formCanonical($payload);

        $this->assertSame($a['input']['input_ref'], $b['input']['input_ref']);
        $this->assertStringStartsWith('input_', $a['input']['input_ref']);

        // A different origin yields a different ref (origin is part of the identity).
        $c = $this->service()->formCanonical(['origin' => 'mobile', 'content' => 'stable body', 'thread' => 'thread-1']);
        $this->assertNotSame($a['input']['input_ref'], $c['input']['input_ref']);
    }

    /**
     * isCanonicalSource is a pure predicate over the origin + no-plan invariants.
     */
    public function test_is_canonical_source_predicate(): void
    {
        $clean = $this->service()->isCanonicalSource(['origin' => 'operator', 'content' => 'x']);
        $this->assertTrue($clean['is_canonical_source']);
        $this->assertFalse($clean['missing_origin']);
        $this->assertSame([], $clean['plan_fields']);

        $dirty = $this->service()->isCanonicalSource(['content' => 'x', 'plan' => 'ship', 'objective' => 'win']);
        $this->assertFalse($dirty['is_canonical_source']);
        $this->assertTrue($dirty['missing_origin']);
        $this->assertSame(['objective', 'plan'], $dirty['plan_fields']);
    }
}
