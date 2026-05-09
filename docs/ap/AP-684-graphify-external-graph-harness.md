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
Architecture Operations.  
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
- outputs ficam fora do runtime ate import revisado;
- confidence ausente vira `AMBIGUOUS`;
- `INFERRED` nunca e tratado como fato;
- `EXTRACTED` ainda exige source ref e privacy gate;
- provider call exige AP/policy posterior.

## 8. Definition Of Done

- service publica contrato fail-closed;
- comando/API validam candidato em modo read-only;
- schema rejeita JSON malformado, oversize e node/edge sem source ref;
- testes provam zero writes em Memory, Context Builder e Constelacao;
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

Status atual: P0 e P2 read-only estao implementados. P1 real de extracao
sandboxada e P3 Curator proposal continuam pendentes.

## 10. Beneficio Esperado

AP-684 deve economizar leitura humana/IA em repos grandes e revelar relacoes
entre codigo, docs e testes que o Code Intelligence atual ainda nao modela.

O ganho correto e melhorar o indice nativo do Atlas. O ganho errado seria virar
dependencia invisivel de uma ferramenta externa.
