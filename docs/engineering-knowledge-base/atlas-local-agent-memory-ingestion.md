---
id: atlas-local-agent-memory-ingestion
type: engineering_knowledge
title: Atlas Local Agent Memory Ingestion
status: active
category: atlas-ai
priority: 96
summary: Contrato canonico para ingerir pastas locais de Codex, Claude Code e outros agentes como fonte governada de aprendizado operacional, com discovery read-only, secret scan, quarentena, classificacao, evidence, human review e promocao seletiva.
tags:
  - atlas-ai
  - memory
  - local-agent
  - ingestion
  - codex
  - claude-code
  - governance
capabilities:
  - local_agent_memory_ingestion
  - secret_scan
  - cognitive_quarantine
  - agent_session_learning
  - prompt_goal_mining
  - operational_pattern_extraction
  - governed_memory_promotion
decisions:
  - Pastas locais de Codex, Claude Code e agentes similares sao source material, nao memoria primaria.
  - Ingestao deve ser read-only por default, com secret scan antes de qualquer indexacao, resumo ou promocao.
  - Raw capture nunca entra direto em memoria, contexto, embeddings, provider projection ou docs canonicos.
  - Conhecimento util so vira memoria/docs depois de classificacao, dedup, score de qualidade, evidence refs e review humano ou policy explicita.
  - Esta capacidade deve ser implementada depois de Mission Foundation, Evidence/Certification, Policy e Tool Runtime minimos.
maintenance:
  - Atualize este doc antes de implementar scanners locais, importadores de historico de agentes, sync com Codex/Claude Code ou promocao automatica de sessoes.
  - Nao adicionar paths pessoais reais, segredos ou exemplos com credenciais.
  - Manter abaixo de 520 linhas.
related_paths:
  - docs/engineering-knowledge-base/atlas-autonomous-intelligence-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md
  - docs/engineering-knowledge-base/atlas-ai-memory-context-core-open-brain.md
  - docs/engineering-knowledge-base/memory/cognitive-immune-learning-kernel.md
  - docs/engineering-knowledge-base/memory/contracts.md
  - docs/engineering-knowledge-base/memory/retrieval-and-context.md
  - docs/engineering-knowledge-base/atlas-evidence-truth-layer.md
  - docs/engineering-knowledge-base/atlas-evidence-certification-runtime.md
  - docs/engineering-knowledge-base/atlas-permission-budget-safety-layer.md
  - docs/engineering-knowledge-base/atlas-tool-economy.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-local-agent-memory-ingestion
graph_title: Atlas Local Agent Memory Ingestion
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-autonomous-intelligence-operating-system
graph_status: active
graph_source: repo
human_name: Atlas Local Agent Memory Ingestion
canonical_name: Atlas Local Agent Memory Ingestion
technical_name: atlas-local-agent-memory-ingestion
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/atlas-local-agent-memory-ingestion.md
owner: memory
repo_paths:
  - docs/engineering-knowledge-base/atlas-local-agent-memory-ingestion.md
allowed_changes:
  - Refinar pipeline, schemas, gates, threat model, source classes e implementation plan.
forbidden_changes:
  - Ingerir pastas locais sem secret scan.
  - Promover chat/log bruto para memoria/contexto sem cognitive quarantine e review.
  - Persistir credenciais, tokens, cookies, chaves SSH/API ou dados pessoais sensiveis.
  - Usar historico de provider como autoridade acima dos docs canonicos.
depends_on:
  - atlas-ai-knowledge-governance-system
  - atlas-ai-memory-context-core-open-brain
  - atlas-ai-cognitive-immune-learning-kernel
  - atlas-evidence-truth-layer
flows_to:
  - atlas_memory
  - atlas_compounding_learning
  - atlas_evidence
  - atlas_context_pack
unlocks:
  - agent_session_learning
  - prompt_goal_pattern_library
  - operator_workstyle_memory
governs:
  - atlas_ai.local_agent_memory_ingestion
evidence:
  - docs/engineering-knowledge-base/atlas-local-agent-memory-ingestion.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: critical
ai_entrypoints:
  - Leia Resumo, Pipeline, Secret Scan, Promotion Gate, Regras para IA e Definition of Done antes de implementar.
quality_gates:
  - source-discovered-read-only
  - secret-scan-passed
  - cognitive-quarantine-applied
  - source-classified
  - dedup-complete
  - evidence-recorded
  - human-review-or-policy-approved
  - promotion-receipt-created
failure_modes:
  - Vazamento de credenciais por ingestao bruta.
  - Memoria contaminada por logs velhos, errados ou contraditorios.
  - Provider history tratado como verdade canonica.
  - Prompts ruins promovidos como padrao operacional.
  - Dados privados indexados em embeddings sem permissao.
observability_signals:
  - ingestion_run_id
  - source_root_hash
  - discovered_file_count
  - secret_findings_count
  - quarantined_item_count
  - promoted_item_count
  - rejected_item_count
  - promotion_receipt_hash
next_actions:
  - Implementar apenas depois de Mission Foundation, Policy, Evidence e Tool Runtime basicos.
  - Criar scanner read-only e fixtures com segredos falsos antes de usar pastas reais.
line_limit: 520
---
# Atlas Local Agent Memory Ingestion

## Resumo

Atlas Local Agent Memory Ingestion define como o Atlas pode aprender com pastas
locais de Codex, Claude Code e outros agentes. Essas pastas podem conter metas,
prompts, sessoes, planos, erros, solucoes, diffs, comandos e preferencias reais
do operador.

Elas sao valiosas, mas perigosas. O contrato canonico e: capturar muito, confiar
em pouco, promover seletivamente e nunca indexar segredo.

## Papel no Atlas

Esta capacidade alimenta o Atlas AI com aprendizado operacional sobre como o
operador trabalha e como agentes externos resolvem ou falham em tarefas. Ela
nao substitui:

- docs canonicos;
- Evidence Ledger;
- Memory Registry;
- Mission Foundation;
- Compounding Learning;
- Human Knowledge Surface.

Pastas locais sao **source material em quarentena** ate passarem pelos gates.

## Onde Se Encaixa

```text
Local Agent Folders
-> Local Agent Memory Ingestion
-> Cognitive Immune Gate
-> Evidence / Promotion Review
-> Memory, Compounding, Tool Economy or Docs Proposal
```

O pipeline roda abaixo do Knowledge Governance System e do Memory Context Core.
Ele nao decide comportamento critico; ele produz candidatos revisaveis.

## Fontes Candidatas

Exemplos de fontes locais:

- historico de sessoes Codex;
- historico de Claude Code;
- prompts e goals antigos;
- planos de execucao;
- logs de comandos;
- outputs de testes;
- relatorios gerados por agentes;
- patches/diffs temporarios;
- preferencias operacionais;
- erros recorrentes e reparos bem-sucedidos.

Nao documentar paths pessoais reais. A implementacao deve receber roots
configurados pelo operador e registrar apenas hashes/aliases seguros.

## Pipeline Canonico

```text
Local Agent Folders
-> read-only discovery
-> path allowlist / denylist
-> secret scan
-> cognitive quarantine
-> source classification
-> dedup / freshness / relevance scoring
-> evidence refs
-> extraction of lessons, prompts, goals, failures, commands and patterns
-> human review or explicit promotion policy
-> memory/docs/compounding candidates
-> promotion receipt
```

Nenhum item pula secret scan, quarantine ou promotion gate.

## Fluxo

1. Registrar source root por alias seguro.
2. Descobrir arquivos em modo read-only.
3. Aplicar allowlist/denylist.
4. Rodar secret scan e redaction.
5. Colocar tudo em cognitive quarantine.
6. Classificar source class.
7. Calcular dedup, freshness e quality score.
8. Criar evidence refs hash-only quando apropriado.
9. Gerar candidatos de promocao.
10. Exigir review humano ou policy explicita.
11. Promover, rejeitar ou manter em quarentena.
12. Emitir promotion receipt.

## Source Classes

| Classe | Exemplo | Destino Default |
|---|---|---|
| `goal_prompt` | metas usadas em Codex/Claude | candidato a prompt pattern |
| `implementation_plan` | plano de execucao | candidato a workflow learning |
| `error_trace` | falha/teste/log | candidato a repair learning |
| `successful_fix` | diff + teste green | candidato a compounding learning |
| `operator_preference` | estilo de trabalho | candidato privado revisavel |
| `tool_recipe` | comando repetido | candidato a Tool Economy |
| `sensitive_secret` | token/cookie/chave | redigir, bloquear e auditar |
| `stale_context` | decisao velha ou superada | arquivar/rejeitar |
| `untrusted_output` | resposta de provider sem prova | manter como source material |

## Secret Scan

Antes de resumir, indexar, embedding ou promover, rodar detecao de:

- API keys;
- tokens OAuth;
- cookies;
- chaves SSH/GPG;
- `.env`;
- credenciais de banco;
- bearer tokens;
- session ids;
- paths privados sensiveis;
- dados pessoais sensiveis;
- segredos em screenshots ou anexos, quando suportado.

Findings devem gerar blocker ou redaction receipt. Segredo nunca vira memoria.

## Cognitive Quarantine

Todo item nasce com:

```text
memory_eligible: false
context_eligible: false
embedding_allowed: false
promotion_status: quarantined
```

A promocao exige evidencia de utilidade, ausencia de segredo, classificacao,
dedup e review/policy. O Atlas deve conseguir explicar por que um item foi
promovido, rejeitado ou mantido em quarentena.

## Promotion Targets

Um item aprovado pode virar:

- memory candidate privado;
- learning signal para Compounding;
- prompt/goal pattern;
- repair pattern;
- tool recipe;
- doc update proposal;
- evidence reference;
- benchmark candidate;
- operator preference, quando aprovado.

Nao promover direto para docs canonicos sem proposta revisavel e diff claro.

## Evidencias

Evidencias aceitas:

- source alias e root hash;
- file ref hash;
- secret scan report redigido;
- classification receipt;
- dedup/freshness score;
- review decision;
- promotion receipt;
- rejection/blocker reason.

Conteudo bruto sensivel nao deve aparecer em evidence publica.

## Contratos

- `atlas.ai.local_agent_ingestion.run.v1`
- `atlas.ai.local_agent_ingestion.source.v1`
- `atlas.ai.local_agent_ingestion.secret_scan.v1`
- `atlas.ai.local_agent_ingestion.classification.v1`
- `atlas.ai.local_agent_ingestion.promotion_candidate.v1`
- `atlas.ai.local_agent_ingestion.promotion_receipt.v1`

Campos minimos:

- `ingestion_run_id`;
- `source_alias`;
- `source_root_hash`;
- `file_ref_hash`;
- `source_class`;
- `secret_scan_status`;
- `redaction_status`;
- `quarantine_status`;
- `quality_score`;
- `freshness_score`;
- `evidence_refs`;
- `promotion_target`;
- `review_status`;
- `receipt_hash`.

## Regras para IA

- Nao ler pastas locais sem escopo aprovado pelo operador.
- Nao escrever, mover, apagar ou limpar arquivos locais nesse pipeline.
- Nao exibir segredo encontrado; redigir e registrar blocker.
- Nao tratar historico de agente como verdade canonica.
- Nao promover item contraditorio sem contradiction note.
- Nao criar embedding de raw logs antes do gate.
- Nao usar dados privados do operador em provider externo sem policy.
- Nao transformar prompt antigo em regra global sem evidence e review.

## Riscos

- Vazamento de segredo local.
- Promocao de contexto velho ou errado.
- Contaminacao de memoria por output fraco de provider.
- Exposicao de dados privados a provider externo.
- Duplicacao de docs canonicos com historico de chat.
- Aprendizado de padrao operacional ruim.

Mitigacao: read-only, allowlist, secret scan, quarantine, review, receipts e
promotion policy.

## Escopo de Implementacao

Implementacao futura deve incluir:

- source registry com aliases seguros;
- scanner read-only;
- allowlist/denylist;
- secret scanner;
- redaction service;
- classifier;
- dedup/freshness scoring;
- evidence recorder;
- promotion candidate builder;
- human review queue ou policy approval;
- receipts e control-plane read model;
- testes com fixtures contendo segredos falsos.

## Dependencias

- Mission Foundation para registrar ingestion run como mission/work order.
- Policy/Permission para autorizar paths, privacidade e provider use.
- Evidence/Certification para receipts e blockers.
- Memory/Cognitive Immune Kernel para quarantine e promotion.
- Tool Runtime para scanners e parsers.

## Exemplos

Exemplos validos:

- extrair padroes de `/goal` que produziram entregas completas;
- registrar comandos de teste usados repetidamente;
- aprender repair pattern de falhas reais;
- rejeitar uma sessao porque continha segredo ou decisao obsoleta.

Exemplo invalido: indexar todo o historico de Claude Code como memoria
automaticamente.

## Proximas Acoes

1. Criar fixtures locais com segredos falsos.
2. Especificar source registry com aliases seguros.
3. Implementar scanner read-only apos Policy/Evidence minimos.
4. Criar promotion review queue.
5. Integrar learning signals ao Compounding.

## Definition of Done

Esta capacidade esta pronta quando:

- discovery local e read-only;
- paths sao allowlisted;
- secret scan bloqueia/redige fixtures sensiveis;
- raw capture fica em quarentena;
- classifier separa prompts, goals, falhas, fixes, recipes e segredos;
- candidatos de promocao exigem evidence refs e review/policy;
- nada entra em memoria/contexto/embedding sem promotion receipt;
- control plane mostra runs, blockers, promoted/rejected/quarantined;
- testes provam que segredo nao vaza;
- docs-health passa.
