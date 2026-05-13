---
title: Graphify External Graph Harness
status: implemented_partial
owner: Atlas Code Intelligence
line_limit: 220
related_paths:
  - docs/engineering-knowledge-base/code-intelligence/external-graph-harness.md
  - docs/engineering-knowledge-base/code-intelligence.md
  - docs/engineering-knowledge-base/archive/source-material/code-intelligence/graphify/README.md
  - docs/engineering-knowledge-base/archive/source-material/code-intelligence/graphify-v0-7-11-dissection-2026-05-09.md
  - docs/engineering-knowledge-base/memory/cognitive-immune-learning-kernel.md
---

# AP-684 - Graphify External Graph Harness

## 1. Proposito

Transformar a disseccao do Graphify em um harness governado para gerar grafo
externo candidato de codigo/docs do Atlas, sem criar fluxo paralelo de memoria,
contexto, runtime ou decisao.

## 2. Status Real

Documentacao: pronta para orientar implementacao.  
Codigo Atlas: P0/P2 implementado como contrato read-only, validator e report
Architecture Operations. Candidato aceito agora emite `review_packet`
machine-readable (`atlas.external_graph_review_packet.v1`) com
decisao humana requerida, rollback, `policy_patch_review_required=true`,
`forbidden_until_review` e `auto_promotion_allowed=false`; o report tambem publica
`promotion_allowed=false` e `next_action` explicito mesmo quando nao ha
candidato. O `review_packet` tambem carrega `future_runtime_invocation_contract`
do AP-201 para qualquer runtime Python futuro.
P1 possui builder sandboxado inicial via
`php artisan atlas:ai:external-graph-harness --scan-root=<allowed> --json`,
que gera `atlas.external_graph_candidate.v1` de paths permitidos sem executar
Graphify upstream, provider, rede, runtime ou writes.
P3 possui emissao governada inicial via `--emit-review-inbox`: candidato aceito
vira proposta `atlas.external_graph_review_inbox.v1`, sem grafo bruto persistido
e com Memory, Context Builder, Constelacao, provider, runtime e policy patch
bloqueados.
Graphify upstream: preservado como source material, nao dependencia aprovada.
Disseccao enterprise: pronta em source material detalhado, incluindo pipeline,
modulos, schema, comandos, Claude/Atlas, benchmark, colheita, riscos e DoD.

## 3. Escopo

- definir/importar `atlas.external_graph_candidate.v1`;
- validar candidatos por `atlas:ai:external-graph-harness --candidate-file=...`;
- expor contrato read-only via API `/ai/external-graph-harness`;
- publicar operação `external_graph_harness_report` no catálogo da arquitetura;
- rodar primeiro experimento apenas em paths permitidos de engenharia;
- bloquear memoria privada, notes, captures, receipts, `.env` e secrets;
- preservar confidence labels e source refs;
- comparar Graphify com Code Intelligence nativo;
- emitir relatorio/proposal revisavel.
- emitir `review_packet` proposal-only para comparacao humana/Curator.

## 4. Nao Escopo

- nao instalar hooks do Graphify;
- nao rodar extracao semantica com provider por default;
- nao copiar `graphify-out` como doc canonico;
- nao escrever Memory Registry;
- nao injetar Context Builder;
- nao criar estrelas de Constelacao;
- nao alterar Policy/Profile, Decide ou provider routing.
- nao emitir Curator proposal automatico nesta fatia P0/P2.

## 5. Fluxo Canonico

```text
allowed Atlas engineering paths
-> sandboxed Graphify-style extraction
-> external_graph_candidate.v1
-> schema/privacy/confidence gates
-> Architecture Operations read-only report
-> Self-Improvement/Curator proposal
-> human review
-> optional Atlas-native extractor improvement
```

## 6. Primeiro Experimento Permitido

Somente estes paths devem ser candidatos iniciais:

- `docs/engineering-knowledge-base`;
- `app/Services/Ai`;
- `app/Services/Engineering`;
- `tests/Feature` e `tests/Unit` relacionados.

Qualquer path fora disso exige nova revisao.

## 7. Regras De Seguranca

- allowlist vence discovery automatico;
- denylist vence allowlist quando houver segredo ou memoria privada;
- path normalizado nao pode escapar do repo;
- qualquer segmento `..` em `scan_root` ou `source_refs.path` e rejeitado mesmo
  quando o texto comeca por uma allowlist;
- outputs ficam fora do runtime ate import revisado;
- confidence ausente vira `AMBIGUOUS`;
- `INFERRED` nunca e tratado como fato;
- `EXTRACTED` ainda exige source ref e privacy gate;
- provider call exige AP/policy posterior.
- import aceito exige `source_tool=graphify`, hash SHA-256, timestamp parseavel,
  `privacy_class=engineering_internal`, `review_state=candidate` e sem
  `promotion_target`.
- candidato rejeita campos aninhados de autoridade como `provider_prompt`,
  `memory_write`, `context_builder_payload`, `policy_patch`, `tool_call` ou
  secrets, mesmo dentro de `metadata`;
- node ids duplicados sao rejeitados para impedir grafo ambíguo;
- candidato valido ainda carrega `promotion_allowed=false`;
- candidato valido carrega `review_packet.status=ready_for_human_review`;
- `review_packet.required_human_decision` deve ser
  `approve_or_reject_external_graph_candidate_for_native_extractor_improvement`;
- `review_packet.rollback_plan_required=true`,
  `policy_patch_review_required=true` e `forbidden_until_review` deve bloquear
  runtime Python, Memory, Context, Constelacao, provider prompt, policy patch e
  chamadas diretas por surface;
- `review_packet.future_runtime_invocation_contract` exige Kernel first,
  `DecisionReceipt`, `evidence_sink` e `python_ai_data` antes de qualquer runtime futuro;
- report sem candidato carrega `promotion_allowed=false` e pede candidato para
  validacao read-only, nunca promocao;
- `review_packet.auto_promotion_allowed=false` e bloqueia Memory, Context,
  Constelacao, Decide, provider prompt e Python Graph RAG runtime;
- runtime, memoria, contexto, Constelacao, Decide, provider prompt e policy
  patch permanecem bloqueados ate proposta Curator + review humano;
- qualquer promocao para Graph RAG exige AP-683 ou sucessor explicito.

## 8. Definition Of Done

- service publica contrato fail-closed;
- comando/API validam candidato em modo read-only;
- schema rejeita JSON malformado, oversize, origem invalida, promocao precoce
  node/edge sem source ref, traversal de path, node duplicado e metadata com autoridade proibida;
- testes provam zero writes em Memory, Context Builder e Constelacao;
- testes provam `review_packet`, `promotion_allowed=false` e auto-promocao bloqueada;
- relatorio compara lacunas Atlas vs Graphify;
- Curator recomenda review, nao aplica patch;
- docs linkam AP-684 no Code Intelligence e nos indices;
- `docs-health`, `architecture-validate` e `git diff --check` passam.

## 9. Slices De Implementacao

| Slice | Entrega | Proibido |
|---|---|---|
| P0 | schema, validator e fixture pequena de `external_graph_candidate.v1` | rodar provider ou Graphify em repo privado |
| P1 | comando sandboxado com allowlist/denylist e output local | instalar hook/skill ou escrever no runtime |
| P2 | report Architecture Operations comparando Atlas vs candidato | promover para memoria/contexto |
| P3 | Curator finding revisavel com source refs e confidence | aplicar patch automatico |

Status atual: P0 e P2 read-only estao implementados. P1 tem builder sandboxado
read-only inicial, ainda sem executar Graphify upstream. P3 emite proposal Inbox
para review humano quando ha candidato aceito. O contrato agora publica
`review_only_constraints` no contract, na validation e no report, e publica
`review_packet` por candidato validado. Graphify upstream real e qualquer AP de
runtime/extractor futuro continuam pendentes.

API hardening: `POST /ai/external-graph-harness` falha fechado com
`candidate_must_be_json_object` e HTTP 422 quando `candidate` existe mas nao e
objeto JSON. Payload malformado nunca cai no relatorio `ok` de "sem candidato".

## 10. Beneficio Esperado

AP-684 deve economizar leitura humana/IA em repos grandes e revelar relacoes
entre codigo, docs e testes que o Code Intelligence atual ainda nao modela.

O ganho correto e melhorar o indice nativo do Atlas. O ganho errado seria virar
dependencia invisivel de uma ferramenta externa.
