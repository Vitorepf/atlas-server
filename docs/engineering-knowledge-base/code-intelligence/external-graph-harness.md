---
id: atlas-code-intelligence-external-graph-harness
type: engineering_knowledge
title: Atlas Code Intelligence External Graph Harness
status: implemented_partial
category: code-intelligence
priority: 90
summary: Contrato canonico para usar grafos externos de codigo/docs como evidencia revisavel, sem criar memoria, contexto, Constelacao ou runtime paralelo.
tags:
  - atlas
  - code-intelligence
  - external-graph
  - graphify
  - governance
capabilities:
  - code_intelligence_index
  - external_graph_candidate
  - architecture_operations
  - curator_proposal
  - cognitive_immune_gate
decisions:
  - Grafos externos sao candidatos de evidencia, nunca autoridade operacional primaria.
  - Graphify e o primeiro source material dissecado, mas o contrato pertence ao Atlas.
  - Nenhum grafo externo pode escrever Memory Registry, Context Builder, Constelacao, Policy/Profile, Decision Receipt ou Evidence Ledger diretamente.
  - A primeira implementacao deve ser sandboxada, read-only, com paths permitidos e sem extracao semantica de memoria privada.
maintenance:
  - Atualize quando AP-684 mudar status, schema ou comandos.
  - Rode docs-health, sync e index-code depois de promover qualquer parte para codigo.
  - Mantenha este doc abaixo de 260 linhas; detalhes longos ficam no source material.
related_paths:
  - docs/engineering-knowledge-base/code-intelligence/README.md
  - docs/ap/AP-684-graphify-external-graph-harness.md
  - docs/engineering-knowledge-base/code-intelligence.md
  - docs/engineering-knowledge-base/archive/source-material/code-intelligence/graphify/README.md
  - docs/engineering-knowledge-base/archive/source-material/code-intelligence/graphify-v0-7-11-dissection-2026-05-09.md
  - docs/engineering-knowledge-base/memory/cognitive-immune-learning-kernel.md
  - docs/engineering-knowledge-base/atlas-ai-local-performance-memory-strategy.md
  - app/Services/Engineering/EngineeringCodeIntelligenceService.php
---

# Atlas Code Intelligence External Graph Harness

Este contrato define como o Atlas pode aproveitar ferramentas externas de grafo
de codigo, como Graphify, sem quebrar a arquitetura-mae.

O objetivo e acelerar leitura de repos, detectar relacoes, sugerir perguntas e
achar pontos cegos. O objetivo nao e criar outro cerebro.

## Autoridade

Este doc governa apenas a porta de entrada de grafos externos no Code
Intelligence.

Ele e subordinado a:

1. Kernel Pipeline;
2. Documentation Operating System;
3. Knowledge Governance System;
4. Code Intelligence Index;
5. Cognitive Immune Learning Kernel;
6. AP-684 para a primeira implementacao Graphify.

## O Que Existe

Ja existe:

- Code Intelligence deterministico com modulos, simbolos, rotas, comandos,
  migrations, testes e links docs->codigo;
- Knowledge Base sincronizada para Postgres;
- docs-health, architecture-validate e index-code;
- source material Graphify dissecado e preservado;
- source package enterprise Graphify com pipeline, inventario, Claude/Atlas,
  schema, taxonomia de capacidades, benchmark, mapa de colheita, riscos e DoD;
- AP-684 como contrato de implementacao futura.

Agora existe:

- contrato machine-readable `atlas.external_graph_harness.contract.v1`;
- validator read-only de `atlas.external_graph_candidate.v1`;
- comando `php artisan atlas:ai:external-graph-harness --json`;
- API `/ai/external-graph-harness`;
- operação `external_graph_harness_report` no catálogo Architecture Operations.

Ainda nao existe:

- tabela/read model para grafo externo;
- comando sandboxado para gerar candidato;
- query read-only sobre candidato externo;
- Curator proposal usando surpresa/god nodes de grafo externo;
- promocao revisada para Code Intelligence nativo.

## Regra De Pipeline

```text
External tool output
-> sandbox path allowlist
-> secret/privacy/tombstone filter
-> schema validation
-> external_graph_candidate.v1
-> Architecture Operations review
-> Curator proposal
-> human review
-> optional Atlas-native extractor improvement
```

O grafo externo nunca pula para Memory, Context Builder, Constelacao ou Decide.

## Superficies Implementadas

| Surface | Contrato | Autoridade |
|---|---|---|
| CLI | `php artisan atlas:ai:external-graph-harness --json` | publica contrato e report |
| CLI | `php artisan atlas:ai:external-graph-harness --candidate-file=<json> --json` | valida candidato sem writes |
| API | `GET /ai/external-graph-harness` | contrato/report |
| API | `POST /ai/external-graph-harness` com `candidate` | valida candidato sem writes |
| Architecture Operations | `external_graph_harness_report` | descoberta por humanos/IAs |

## Contrato `external_graph_candidate.v1`

Cada importacao deve guardar:

| Campo | Obrigatorio | Regra |
|---|---|---|
| `source_tool` | sim | `graphify` no AP-684 |
| `source_tool_version` | sim | versao upstream fixa |
| `source_archive_hash` | sim | hash do pacote ou commit auditado |
| `scan_root` | sim | path permitido e normalizado |
| `generated_at` | sim | timestamp local |
| `nodes` | sim | id, label, kind, source refs |
| `edges` | sim | source, target, relation, confidence |
| `confidence` | sim | preserva `EXTRACTED`, `INFERRED`, `AMBIGUOUS` quando existir |
| `privacy_class` | sim | default `engineering_internal` |
| `review_state` | sim | `candidate`, `reviewed`, `rejected`, `promoted` |
| `promotion_target` | nao | apenas apos review |

## Mapeamento De Confianca

| Externo | Atlas |
|---|---|
| `EXTRACTED` | evidencia estrutural candidata |
| `INFERRED` | proposta revisavel, nao fato |
| `AMBIGUOUS` | somente revisao humana |
| sem confidence | `AMBIGUOUS` por default |

## Gates Obrigatorios

1. Path allowlist antes de ler arquivo.
2. Bloqueio de `.env`, secrets, receipts, captures, notes pessoais e memoria
   privada.
3. Sem provider call por default.
4. Sem hook/skill instalado em repo Atlas.
5. Sem escrita em tabelas de Memory Core.
6. Sem inclusao automatica no Context Builder.
7. Sem estrela de Constelacao sem `constellation_eligible=true` revisado.
8. Sem policy patch, provider routing ou Decision Receipt gerado pelo grafo.
9. Source refs obrigatorios para cada node/edge promovivel.
10. Tudo que for importado fica read-only ate review.

## Saidas Permitidas

- relatorio de lacunas do Code Intelligence;
- relatorio de relacoes surpreendentes;
- candidatos de extractor AST nativo;
- proposal do Curator para humano revisar;
- sugestoes de docs faltantes;
- comparacao Atlas Code Intelligence vs grafo externo.

## Saidas Proibidas

- memoria nova;
- contexto automatico;
- decisao operacional;
- execucao de provider;
- atualizacao de policy;
- promocao para Constelacao;
- substituicao do Code Intelligence Index.

## Definition Of Done

O harness so deixa `implemented_partial` quando:

1. P1 tiver extracao sandboxada real ou decisao explicita de cancelamento;
2. schema `external_graph_candidate.v1` validar JSON malformado e oversize;
3. privacy/tombstone filters bloquearem paths sensiveis;
4. testes provarem ausencia de writes em Memory/Context/Constelacao;
5. Architecture Operations consumir o candidato como read-only;
6. Curator emitir proposta revisavel, nunca patch automatico;
7. docs-health, architecture-validate e index-code estiverem verdes.

P0/P2 ja cumprem contrato, validacao, CLI/API/report e testes focados. P1/P3
permanecem bloqueados ate existir decisao especifica para extrair grafo real e
emitir proposal do Curator.
