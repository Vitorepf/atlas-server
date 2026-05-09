---
id: atlas-constelacao-surface
type: engineering_knowledge
title: Atlas Constelacao Surface
status: active
category: surface-architecture
priority: 86
summary: Encaixe canonico da tela Constelacao no Atlas: surface contemplativa de serendipidade, status real, roadmap, dependencias de embeddings e regras anti-duplicacao.
tags:
  - atlas
  - constelacao
  - mobile
  - surface
  - serendipity
  - graph-rag
capabilities:
  - constellation_surface
  - semantic_serendipity_engine
  - mobile_surface_gateway
  - python_ai_data_runtime
  - human_knowledge_surface
decisions:
  - Constelacao e uma surface contemplativa do Atlas, nao um Domain, nao um dashboard e nao uma substituicao do Inbox.
  - A funcao primaria e cross-pollination entre capturas/ideias por gravidade semantica, preservando silencio operacional.
  - Lente 1 Bilderatlas e sempre o estado default; Command Sky/linhagem so entra por gesto explicito em fase posterior.
  - Endpoint backend v1 de posicoes existe com fallback deterministico governado e readiness semantico derivado de `atlas:ai:local-rag-readiness`.
  - Semantic positioning tem promotion gate: vector read-model pode ficar ready, mas Graph RAG/Python permanece bloqueado ate proposta revisada com Decision Receipt.
  - Python AI/Data Runtime pode calcular embeddings/reducao 2D, mas Laravel Kernel governa policy, receipt, evidence e API.
maintenance:
  - Atualizar antes de mexer na rota mobile, endpoint de posicoes, embeddings, Graph RAG, lineage ou visual language da Constelacao.
  - Nao expandir este doc com detalhes poeticos; preservar detalhes longos nos source materials e criar APs curtos por fase.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-mobile-surface-gateway.md
  - docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md
  - docs/engineering-knowledge-base/atlas-ai-local-performance-memory-strategy.md
  - docs/engineering-knowledge-base/atlas-ai-memory-context-core-open-brain.md
  - docs/engineering-knowledge-base/obsidian-atlas-vault.md
  - docs/engineering-knowledge-base/atlas-ai-pipeline.md
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
  - ../../atlas-vault-backup/00-constituicao/constelacao-spec.md
  - ../../atlas-vault-backup/00-constituicao/constelacao-codice-vivo.md
  - app/Http/Controllers/AtlasConstelacaoController.php
  - app/Services/Ai/Surface/ConstelacaoPositionsService.php
  - tests/Feature/Ai/AtlasConstelacaoPositionsApiTest.php
  - ../atlas-app/app/celestial.tsx
  - ../atlas-app/lib/atlasAiTelemetry.ts
  - ../atlas-app/lib/api/client.ts
---

# Atlas Constelacao Surface

Este documento encaixa a tela Constelacao na arquitetura canonica do Atlas sem
copiar a constituicao longa para dentro da Knowledge Base ativa.

## Authority

| Assunto | Documento que manda |
|---|---|
| Alma, mantra e exemplos constitucionais | `atlas-vault-backup/00-constituicao/constelacao-codice-vivo.md` |
| Regras visuais/interativas detalhadas | `atlas-vault-backup/00-constituicao/constelacao-spec.md` |
| Surface mobile e gateway | `atlas-ai-mobile-surface-gateway.md` |
| Python/Graph RAG/embeddings | `atlas-ai-runtime-language-boundaries.md` + `atlas-ai-local-performance-memory-strategy.md` |
| Memory, context, vault e semantic notes | `atlas-ai-memory-context-core-open-brain.md` + `obsidian-atlas-vault.md` |
| Pipeline, receipt, evidence e learning | `atlas-ai-pipeline.md` + `atlas-ai-kernel-architecture.md` |

## O Que E

Constelacao e o Motor de Serendipidade do Atlas: uma tela mobile contemplativa
onde capturas, notas e ideias aparecem como estrelas. A proximidade visual deve
representar afinidade semantica, nao pasta, tag, dominio fixo ou cronologia.

Ela existe para responder silenciosamente:

```text
Que area do meu conhecimento esta isolada hoje e deveria colidir com o resto?
```

## Onde Se Encaixa

| Camada | Encaixe |
|---|---|
| Surface Plane | Tela mobile secundaria, hoje aberta por triple-tap no Inbox. |
| Atlas Input / Memory | Capturas e semantic notes viram estrelas. |
| Context Builder | Seleciona corpus elegivel e provider-safe para embeddings/posicoes. |
| Python AI/Data Runtime | Calcula embeddings, reducao 2D e clusters quando aprovado. |
| Laravel Kernel | Expoe endpoint, aplica policy, cache, auth, evidence e fallback. |
| Evidence / Learning | Lente 2 e fases futuras leem lineage, outcomes e proposals. |

Constelacao nao e Domain. Ela cruza dominios como Programming, Marketing,
Health, Finance e Personal Development usando memoria/contexto do Core.

## Status Real

| Area | Status | Observacao |
|---|---|---|
| Constituicao | Completa como source material | Spec tecnica e Codice Vivo existem fora da KB ativa. |
| Cliente mobile | Implementado v1 parcial | `atlas-app/app/celestial.tsx` consome o endpoint governado e preserva fallback local domain+jitter. |
| Endpoint de posicoes | Implementado v1 | `GET /atlas/celestial/positions` e `/v1/mobile/atlas/celestial/positions` retornam posicoes governadas. |
| UX/telemetria Lente 1 | Implementado v1 | Payload declara `ui_contract`; mobile registra open/load/fail/tap sem conteudo bruto. |
| Semantic readiness | Implementado v1 | Payload inclui `semantic_positioning_readiness`, promotion gate, status, gates, `provider_bypass_allowed=false` e proxima acao. |
| Curator usage review | Implementado v1 | `self_improvement.docs_drift_review` observa `CONSTELACAO_POSITIONS_SERVED`, abre finding de revisao da Lente 1 e mantem Graph RAG/Python bloqueado. |
| Embeddings/Graph RAG | Futuro/parcial | Local performance doc marca Graph RAG/embeddings como roadmap governado; Graph RAG nao promove sem benchmark. |
| Lente 2/lineage | Futuro | Depende de Evidence Ledger, Decision Receipts, semantic notes e outcomes. |

## Beneficios

- Reduz ansiedade de perda: o operador ve que o Atlas guardou materia-prima.
- Cria cross-pollination entre nichos que listas cronologicas escondem.
- Preserva visao periferica durante hyperfoco.
- Ajuda o operador a enxergar a anatomia de ideias bem-sucedidas em fases futuras.
- Mantem um refugio cognitivo anti-dashboard dentro do ecossistema Atlas.

## Anti-Objetivos

- Nao mostrar tarefas, streaks, percentuais, contadores ou due dates.
- Nao substituir Inbox, Memory Review, Projects, Engineering ou Observability.
- Nao desenhar graph view com edges permanentes.
- Nao enviar push de insight; descoberta e pull, nao interrupcao.
- Nao expor dados crus do Vault ou capturas privadas a embeddings externos sem policy.

## Ordem De Implementacao

1. **Backend v1 governado**: entregue endpoint, auth token/mobile bearer,
   payload redigido, fallback deterministico, privacy e evidence minima.
2. **Cliente v1**: entregue consumo mobile do endpoint com fallback
   domain+jitter quando offline, sem criar uma cartografia paralela como fonte
   primaria.
3. **Embedding readiness**: entregue contrato de readiness semantico via
   `LocalRagReadinessService`; proximo passo e benchmark controlado antes de
   qualquer Graph RAG/Python runtime.
4. **Endpoint semantico**: evoluir `GET /atlas/celestial/positions` para
   `{capture_id,x,y,intensity,updated_at}` por item elegivel.
5. **Acceptance tests mobile**: provar paz contemplativa, fallback, tap para Detail,
   e que nao aparecem elementos operacionais na Lente 1.
6. **Uso 30 dias**: medir abertura, taps e relatos de conexao antes de v2.
7. **v2 Command Sky**: lineage e pulso operacional so depois de v1 estabilizado.
8. **v3 Cross-Pollination**: clusters, nomeacao e reading por LLM com review.

## Criterios De Pronto Do MVP

- Lente 1 abre sem alerta operacional, vermelho, lista ou cobranca.
- Posicoes semanticas vem do servidor quando disponiveis.
- Fallback deterministico do servidor funciona sem embeddings/Graph RAG.
- Tap em estrela abre o DetailSheet existente, sem duplicar UX de leitura.
- O endpoint registra evidence minima sem prompt/conteudo sensivel bruto.
- O operador descobre pelo menos uma conexao cross-domain por semana em uso real.

## Contrato Backend v1

| Item | Contrato |
|---|---|
| API token | `GET /atlas/celestial/positions?domain=&limit=&lens=` |
| Mobile bearer | `GET /v1/mobile/atlas/celestial/positions?domain=&limit=&lens=` |
| Schema | `atlas.constelacao.positions.v1` |
| Itens | `source_type`, `source_id`, `title`, `domains`, `x`, `y`, `intensity`, `cluster_key`, `position_hash` |
| Privacidade | Nao retorna `content_text`, `body_excerpt`, conteudo bruto do Vault, secrets ou prompt. |
| Evidence | Registra `CONSTELACAO_POSITIONS_SERVED` com contagem, refs e hashes. |
| UI Contract | `ui_contract` declara Lente 1 contemplativa, bloqueia chrome operacional e exige telemetria `constelacao_opened`, `constelacao_backend_loaded`, `constelacao_backend_failed`, `constelacao_star_tapped`. |
| Semantic readiness | `position_engine.semantic_positioning_readiness` deriva de `atlas.local_rag_readiness`, nunca permite provider bypass ou memoria paralela. |
| Promotion gate | `vector_positioning_allowed` so quando Local RAG esta `ready`; `graph_rag_promotion_allowed=false` e `python_runtime_allowed=false` ate review humano, benchmark, Curator proposal e Decision Receipt. |
| Curator review | `self_improvement.docs_drift_review` gera `atlas.self_improvement.constelacao_usage_review.v1` quando ha uso real no Ledger, sem promover Graph RAG. |
| Estado | `implemented_partial`: backend v1, consumo mobile e readiness semantico prontos; embeddings, Graph RAG e UX mobile final ainda pendentes. |

## Fases Futuras

| Fase | Quando desenvolver |
|---|---|
| v1 Bilderatlas | Depois do endpoint e privacy/evidence de embeddings. |
| v2 Command Sky | Depois de v1 gerar uso sem ansiedade por 30 dias. |
| v3 Surfacing | Depois de clusters semanticos terem precision aceitavel. |
| v4 Curation Mode | Depois de existir uso real para ensaio/painel manual. |
| v∞ Embodiment | Depois do StackChan/Native Agent ter policy, receipt e evidence maduros. |

## Validacao

Use antes de qualquer implementacao:

```bash
atlas engineering knowledge docs-health --json
php artisan atlas:ai:architecture-validate --json
rg "celestial|constelacao|positions|embedding" app routes tests docs
```

Implementacao real deve adicionar testes focados para endpoint, privacy,
fallback mobile e criterios de falha catastrofica da spec constitucional.
