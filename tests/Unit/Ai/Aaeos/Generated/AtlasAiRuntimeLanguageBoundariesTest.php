<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasAiRuntimeLanguageBoundariesService;
use Tests\TestCase;

/**
 * Pins the executable rules of the Runtime Language Boundaries doc: the Decision
 * Matrix routing (each need -> exactly one owning language), the allow/block
 * verdict (no external runtime may decide governance or copy a Kernel
 * capability), and the invoke/result contract validation
 * (atlas.runtime.invoke.v1 / atlas.runtime.result.v1). Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md
 */
class AtlasAiRuntimeLanguageBoundariesTest extends TestCase
{
    private function service(): AtlasAiRuntimeLanguageBoundariesService
    {
        return new AtlasAiRuntimeLanguageBoundariesService;
    }

    public function test_decision_matrix_routes_each_need_to_its_documented_owner(): void
    {
        $svc = $this->service();

        // Doc "Matriz De Decisao": mass webhook/click/postback -> Go.
        $go = $svc->routeNeed('webhook_click_postback_em_massa');
        $this->assertSame('go_edge', $go['owner']);
        $this->assertSame('concorrencia_e_baixa_latencia', $go['reason']);
        $this->assertFalse($go['is_kernel']);

        // Graph/Vector RAG + embeddings -> Python.
        $this->assertSame('python_ai_data', $svc->routeNeed('graph_rag_vector_rag_embeddings')['owner']);

        // Touch ID / Keychain / native notifications -> Swift.
        $this->assertSame('swift_native_mac', $svc->routeNeed('touch_id_keychain_notificacoes')['owner']);

        // API, auth, policy, receipt, ledger, gates -> Laravel (sovereignty, never delegated).
        $kernel = $svc->routeNeed('api_auth_policy_receipt_ledger_gates');
        $this->assertSame('laravel', $kernel['owner']);
        $this->assertTrue($kernel['is_kernel']);

        // Provider routing is Kernel-owned, never a runtime decision.
        $this->assertSame('laravel', $svc->routeNeed('provider_routing')['owner']);
    }

    public function test_unknown_need_routes_to_kernel_and_demands_placement_never_guesses_a_runtime(): void
    {
        // The doc requires place-feature before any new runtime work; an
        // unclassified need must NOT be handed to Python/Go/Swift.
        $verdict = $this->service()->routeNeed('some_brand_new_unmapped_capability');

        $this->assertSame('laravel', $verdict['owner']);
        $this->assertTrue($verdict['is_kernel']);
        $this->assertFalse($verdict['known_need']);
        $this->assertTrue($verdict['needs_placement']);
    }

    public function test_external_runtime_cannot_decide_provider_model_domain_or_policy(): void
    {
        $svc = $this->service();

        // Doc "Nao use Python para ... decidir provider/modelo por conta propria".
        $providerDecision = $svc->evaluateAction('python_ai_data', 'decide_provider_or_model');
        $this->assertFalse($providerDecision['allowed']);
        $this->assertSame('block', $providerDecision['verdict']);
        $this->assertTrue($providerDecision['must_call_kernel']);
        $this->assertContains('forbidden_authority:decide_provider_or_model', $providerDecision['reasons']);

        // Doc "Nao use Go para ... decidir domain, flow, provider, policy ou repair".
        $this->assertFalse($svc->evaluateAction('go_edge', 'decide_domain_or_flow')['allowed']);
        $this->assertFalse($svc->evaluateAction('go_edge', 'decide_policy_or_repair')['allowed']);

        // Doc "Nao use Python para ... criar fila ou scheduler paralelo".
        $this->assertFalse($svc->evaluateAction('python_ai_data', 'create_parallel_queue_or_scheduler')['allowed']);

        // Doc "Nao use Swift para Kernel, API ...": replacing the Kernel API is blocked.
        $this->assertFalse($svc->evaluateAction('swift_native_mac', 'replace_kernel_api')['allowed']);
    }

    public function test_anti_duplication_blocks_copying_a_kernel_capability_and_demands_calling_kernel(): void
    {
        $svc = $this->service();

        // Doc "Anti-Duplicacao": a runtime needing a Kernel capability must call
        // the Kernel / receive a capability token, never copy policy engine,
        // provider selection, evidence schema, etc.
        $copyPolicy = $svc->evaluateAction('python_ai_data', 'policy_engine');
        $this->assertFalse($copyPolicy['allowed']);
        $this->assertTrue($copyPolicy['must_call_kernel']);
        $this->assertContains('anti_duplication:policy_engine', $copyPolicy['reasons']);
        $this->assertContains('must_call_kernel_or_receive_capability_token', $copyPolicy['reasons']);

        $this->assertFalse($svc->evaluateAction('go_edge', 'evidence_schema')['allowed']);
        $this->assertFalse($svc->evaluateAction('go_edge', 'canonical_scheduler')['allowed']);
    }

    public function test_action_inside_runtime_specialised_scope_is_allowed(): void
    {
        $svc = $this->service();

        // Doc "Use Python quando ... embeddings, Vector RAG, Graph RAG e reranking".
        $rag = $svc->evaluateAction('python_ai_data', 'graph_rag');
        $this->assertTrue($rag['allowed']);
        $this->assertSame('allow', $rag['verdict']);
        $this->assertFalse($rag['must_call_kernel']);

        // Doc "Use Go quando ... ingestao de alto volume: cliques, postbacks, webhooks".
        $this->assertTrue($svc->evaluateAction('go_edge', 'webhook_click_postback')['allowed']);

        // Doc "Use Swift quando ... Keychain, Touch ID".
        $this->assertTrue($svc->evaluateAction('swift_native_mac', 'keychain')['allowed']);

        // A capability outside Python's stated scope (e.g. raw webhook ingestion,
        // which is Go's) is NOT auto-allowed for Python — kept narrow.
        $outOfScope = $svc->evaluateAction('python_ai_data', 'webhook_click_postback');
        $this->assertFalse($outOfScope['allowed']);
        $this->assertContains('out_of_runtime_scope:python_ai_data', $outOfScope['reasons']);
    }

    public function test_unknown_runtime_id_is_blocked(): void
    {
        // Only python_ai_data / go_edge / swift_native_mac are canonical runtimes.
        $verdict = $this->service()->evaluateAction('rust_edge', 'streaming');

        $this->assertFalse($verdict['allowed']);
        $this->assertContains('unknown_external_runtime', $verdict['reasons']);
    }

    public function test_invoke_payload_without_kernel_signature_is_invalid(): void
    {
        $svc = $this->service();

        // Empty payload: every required field missing AND unsigned.
        $empty = $svc->validateInvoke([]);
        $this->assertFalse($empty['valid']);
        $this->assertFalse($empty['kernel_signed']);
        $this->assertContains('decision_receipt_hash', $empty['missing']);
        $this->assertContains('kernel_signature_missing:decision_receipt_hash', $empty['reasons']);

        // All fields present but decision_receipt_hash is blank -> still unsigned.
        $unsigned = $svc->validateInvoke([
            'schema_version' => 'atlas.runtime.invoke.v1',
            'envelope_id' => 'env-1',
            'decision_receipt_hash' => '   ',
            'runtime' => 'python_ai_data',
            'domain_id' => 'programming',
            'flow_id' => 'programming.flow',
            'input' => [], 'policy' => [], 'limits' => [], 'evidence_contract' => [],
        ]);
        $this->assertFalse($unsigned['valid']);
        $this->assertFalse($unsigned['kernel_signed']);
        $this->assertSame([], $unsigned['missing']);

        // Fully-signed, correctly-shaped, known runtime -> valid.
        $valid = $svc->validateInvoke([
            'schema_version' => 'atlas.runtime.invoke.v1',
            'envelope_id' => 'env-2',
            'decision_receipt_hash' => 'sha256:abc123',
            'runtime' => 'go_edge',
            'domain_id' => 'marketing',
            'flow_id' => 'marketing.ingest',
            'input' => ['x' => 1], 'policy' => ['p' => 1], 'limits' => ['l' => 1],
            'evidence_contract' => ['e' => 1],
        ]);
        $this->assertTrue($valid['valid']);
        $this->assertTrue($valid['kernel_signed']);
    }

    public function test_result_payload_status_must_be_one_of_the_four_documented_states(): void
    {
        $svc = $this->service();

        // Doc "status": "succeeded|failed|blocked|needs_review".
        $bad = $svc->validateResult([
            'schema_version' => 'atlas.runtime.result.v1',
            'status' => 'done',
            'artifacts' => [], 'metrics' => [], 'findings' => [], 'evidence' => [],
        ]);
        $this->assertFalse($bad['valid']);
        $this->assertFalse($bad['status_known']);
        $this->assertContains('unknown_result_status:done', $bad['reasons']);

        $ok = $svc->validateResult([
            'schema_version' => 'atlas.runtime.result.v1',
            'status' => 'needs_review',
            'artifacts' => [], 'metrics' => [], 'findings' => [], 'evidence' => [],
        ]);
        $this->assertTrue($ok['valid']);
        $this->assertTrue($ok['status_known']);
        $this->assertSame([], $ok['missing']);
    }
}
