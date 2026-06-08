---
title: python_ai_data Runtime - Code Graph Heavy Analytics (Centrality, Communities, Multi-lang, Multimodal)
status: proposed
owner: Atlas Code Intelligence
line_limit: 220
related_paths:
  - docs/ap/AP-811-atlas-code-graph-real-edges-traversal.md
  - docs/ap/AP-686-voice-realtime-python-runtime-boundary.md
  - docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md
  - runtimes/python/code_graph/main.py
  - runtimes/python/code_graph/atlas_code_graph/centrality.py
  - app/Services/Ai/Programming/ProgrammingPythonRuntimeExecutor.php
  - app/Services/Engineering/CodeGraph/CodeGraphAnalytics.php
---

# AP-812 - python_ai_data Runtime: Code Graph Heavy Analytics

## 1. Proposito

Levantar o runtime governado `python_ai_data` para os algoritmos de grafo
**pesados** que `atlas-ai-runtime-language-boundaries.md` proibe no Kernel
(Leiden communities, centrality em escala, tree-sitter multi-linguagem,
multimodal, cross-domain, grafo unificado). Cada chamada vem por payload
assinado pelo Kernel (`atlas.runtime.invoke.v1`) com `decision_receipt_hash`,
responde `atlas.runtime.result.v1`, grava Evidence. **Promocao = review humano**
(`runtime_promotion_policy.v1`). É o tier que o operador autorizou abrir.

Continuacao governada de AP-811 (P0: arestas reais + traversal + MCP, em PHP) e
do precedente AP-686 (voice runtime — boundary PHP↔Python provado).

## 2. Status Real (code-verified 2026-06-08, sem over-claim)

PROVADO nesta sessao (atras do gate, NAO promovido):
- `runtimes/python/code_graph/` — runtime real, padrao manifest-in/json-out igual
  ao `programming_intelligence`. Capability #1 = **betweenness centrality (Brandes,
  O(V*E), stdlib, deterministica)** — `atlas_code_graph/centrality.py`. 4 testes
  python verdes + smoke do entrypoint (manifest → `{ok,result}`).
- É o leap "centrality em escala" (P-7 alem do degree-god-nodes que ficou no
  Kernel em `CodeGraphAnalytics.php`).

NAO construido / gated:
- networkx / graspologic **nao instalados** → Leiden communities exige
  **dep-approval** neste review (ver secao 8). LP stdlib foi rejeitada por
  fragilidade (collapse); communities reais esperam networkx aprovado.
- tree-sitter multi-linguagem (P-1), multimodal (P-11), cross-domain (M-8),
  grafo unificado (M-9): sequenciados, nao construidos.
- PHP-side invoker assinado + Evidence wiring: **nao** wired (capability roda
  standalone; integracao governada e a proxima fatia).

## 3. Escopo

1. Runtime `runtimes/python/code_graph` (stdlib-first), entrypoint manifest→json.
2. Capabilities de grafo pesado, sequenciadas: **betweenness centrality (feita)**
   → Leiden communities (pos dep-approval) → tree-sitter multi-lang AST →
   multimodal (OCR/audio/video) → cross-domain → grafo unificado.
3. PHP-side: invoker que monta `atlas.runtime.invoke.v1` (com `decision_receipt_hash`),
   chama o runtime via `ProgrammingPythonRuntimeExecutor`-pattern, valida o
   `atlas.runtime.result.v1`, grava Evidence (`RUNTIME_INVOKED/RETURNED/FAILED`).
4. Gate de promocao: flag default-OFF; runtime nunca wired em Dev/Forge/produção
   sem o review humano desta AP.

## 4. Nao-Escopo (proibido por canon — review humano obrigatorio)

- **Auto-promocao** do runtime (proibida por `runtime_promotion_policy.v1`).
- Runtime decidir provider/modelo/dominio/flow, ou escrever Memory/Context/Policy.
- Cerebro Python paralelo ao Kernel (`parallel_kernel_brain_forbidden`).
- Instalar deps pesadas (networkx/graspologic/tree-sitter) **sem** a aprovacao
  explicita da secao 8 deste review.
- Chamar o runtime direto de surface, pulando Decision Receipt.

## 5. Contrato de Comunicacao

Invoke (Kernel → runtime), assinado:
```
{ "schema_version":"atlas.runtime.invoke.v1", "decision_receipt_hash":"sha256",
  "runtime":"python_ai_data", "domain_id":"programming", "flow_id":"programming.dev",
  "op":"betweenness|communities|extract|...", "input":{"edges":[...]}, "limits":{}, "evidence_contract":{} }
```
Result (runtime → Kernel):
```
{ "schema_version":"atlas.runtime.result.v1", "status":"succeeded|failed|blocked",
  "artifacts":[...], "metrics":{}, "findings":[], "evidence":[] }
```
Kernel valida, grava Evidence, roda gates, decide proximo passo.

## 6. Preflight / Definition of Done

Preflight (`runtime_boundary_preflight_gate.v1`): `place-feature` (feito, runtime
`python_ai_data`), runtime-boundary, doc dono (este), `runtime_invocation_contract`,
evidence contract, rollback plan, testes focados, `architecture-validate`.

DoD por capability: spec, schema invoke/result, DecisionReceipt, Evidence Ledger,
health check, teste de contrato, docs/Code Intelligence atualizados, `docs-health`
+ `architecture-validate` verdes. **betweenness**: testes verdes (feito); falta o
invoker PHP + Evidence wiring antes de promover.

## 7. Riscos e Mitigacoes

- **Cerebro paralelo**: mitigado — runtime ALIMENTA o read-model via contrato
  assinado; nao decide nada; flag default-off.
- **Dep-bloat**: mitigado — stdlib-first; networkx/tree-sitter so apos secao 8.
- **Over-claim**: status `proposed`; so betweenness esta provada; resto sequenciado.
- **Concorrencia** (auto-saver/loop ativo): trabalho aditivo em `runtimes/python/code_graph`
  (novo), sem tocar runtimes existentes.

## 8. Promotion Review (decisao do operador)

Para promover (flag on + wire em Dev/Forge), o operador revisa e decide:
1. **Aprovar deps pesadas?** networkx (Leiden/centrality em escala) e/ou
   tree-sitter (multi-lang AST). Sem isso, fica stdlib (betweenness).
2. Aprovar o invoker PHP + Evidence wiring (proxima fatia).
3. Aprovar wire em Dev/Forge (consome o grafo real).
Promocao sem este review = proibida.

## 9. Proximas Acoes

1. (feito) Capability #1 betweenness centrality + testes, atras do gate.
2. Invoker PHP assinado + Evidence wiring + teste de contrato (sem promover).
3. Review de dep (secao 8) → se aprovado: Leiden communities (networkx) + tree-sitter multi-lang.
4. Multimodal, cross-domain (M-8), grafo unificado (M-9) — fatias subsequentes, cada uma com review.
