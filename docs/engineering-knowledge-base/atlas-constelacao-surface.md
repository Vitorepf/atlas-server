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
  - Posicionamento por dominio+jitter e fallback temporario; o MVP real exige endpoint server-side de posicoes por embeddings.
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
  - ../../atlas-app/app/celestial.tsx
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
| Cliente mobile | Scaffold visual parcial | `app/celestial.tsx` tem pan, estrelas, cardinais e fallback domain+jitter. |
| Endpoint de posicoes | Ausente | `GET /atlas/celestial/positions` ainda nao existe. |
| Embeddings/Graph RAG | Futuro/parcial | Local performance doc marca Graph RAG/embeddings como roadmap governado. |
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

1. **AP Constelacao v1 Backend**: criar contrato curto para endpoint, auth,
   payload, cache, fallback, privacy e evidence.
2. **Embedding readiness**: decidir modelo local/externo, redaction,
   invalidacao e armazenamento de posicoes.
3. **Endpoint**: `GET /atlas/celestial/positions` retorna
   `{capture_id,x,y,intensity,updated_at}` por item elegivel.
4. **Cliente v1**: trocar `starPosition()` por consumo do endpoint mantendo
   fallback domain+jitter quando offline.
5. **Acceptance tests**: provar paz contemplativa, fallback, tap para Detail,
   e que nao aparecem elementos operacionais na Lente 1.
6. **Uso 30 dias**: medir abertura, taps e relatos de conexao antes de v2.
7. **v2 Command Sky**: lineage e pulso operacional so depois de v1 estabilizado.
8. **v3 Cross-Pollination**: clusters, nomeacao e reading por LLM com review.

## Criterios De Pronto Do MVP

- Lente 1 abre sem alerta operacional, vermelho, lista ou cobranca.
- Posicoes semanticas vem do servidor quando disponiveis.
- Fallback local funciona quando servidor/embeddings falham.
- Tap em estrela abre o DetailSheet existente, sem duplicar UX de leitura.
- O endpoint registra evidence minima sem prompt/conteudo sensivel bruto.
- O operador descobre pelo menos uma conexao cross-domain por semana em uso real.

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
php artisan atlas:engineering:knowledge docs-health --json
php artisan atlas:ai:architecture-validate --json
rg "celestial|constelacao|positions|embedding" app routes tests docs
```

Implementacao real deve adicionar testes focados para endpoint, privacy,
fallback mobile e criterios de falha catastrofica da spec constitucional.
