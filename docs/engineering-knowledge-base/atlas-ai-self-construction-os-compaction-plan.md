---
id: atlas-ai-self-construction-os-compaction-plan
type: engineering_knowledge
title: Atlas AI Self-Construction OS Compaction Plan
status: active
category: atlas-ai
priority: 102
summary: Plano canonico de compactacao do Self-Construction OS atual, que acumulou sprawl extremo (comandos com nomes de 200+ caracteres em serie e tabela monstruosa em `atlas-ai-self-construction-os.md`). Define refatoracao em 8 famílias de comandos hierarquicos, criacao de 5 docs filhos, reducao da doc-mae para indice <=280 linhas e estabelecimento de gate `command-name-max-80-chars` permanente.
tags:
  - atlas-ai
  - self-construction
  - compaction
  - refactor
  - sprawl-fix
  - command-naming
  - hierarchical-commands
capabilities:
  - self_construction_command_compaction
  - hierarchical_command_taxonomy
  - command_name_length_governance
  - doc_sprawl_remediation
  - safety_invariant_preservation
decisions:
  - O Self-Construction OS atual tem dívida documental critica: comandos como `agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-...` excedem 200 caracteres e quebram qualquer ferramenta CLI sensata.
  - Sprawl veio de ausencia de Multi-Agent Unified Architecture (T1.3); refator do ACP precisa preservar as 7 invariantes de seguranca e ainda assim entregar nomes <=80 chars.
  - Compactacao acontece em refator nao destrutivo: comandos antigos viram aliases deprecated por 90 dias, e novas familias se tornam canonicas.
  - Doc-mae `atlas-ai-self-construction-os.md` deve ser reduzida para indice <=280 linhas; conteudo migra para 5 docs filhos.
maintenance:
  - Atualize este doc antes de iniciar refator, mudar familias ou alterar invariantes preservadas.
  - Apos compactacao, validar que todos os comandos batem `command-name-max-80-chars`.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
  - docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md
  - docs/engineering-knowledge-base/self-construction/multi-provider-agent-orchestration-contract.md
  - docs/engineering-knowledge-base/atlas-multi-agent-unified-architecture.md
  - app/Console/Commands/Atlas/Ai/SelfConstruction/
  - app/Services/Ai/AtlasAgentControlPlane/
  - app/Services/Ai/AtlasSelfConstruction/
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-ai-self-construction-os-compaction-plan
graph_title: Atlas AI Self-Construction OS Compaction Plan
graph_world: atlas
graph_layer: system
graph_kind: policy
graph_parent: atlas-ai-self-construction-os
graph_status: active
graph_source: repo
human_name: Atlas AI Self-Construction OS Compaction Plan
canonical_name: Atlas AI Self-Construction OS Compaction Plan
technical_name: atlas-ai-self-construction-os-compaction-plan
cartography_type: refactor_plan
canonical_source: docs/engineering-knowledge-base/atlas-ai-self-construction-os-compaction-plan.md
owner: atlas-ai
product_name: Atlas Self-Construction OS Compaction Plan
internal_product_name: SCOS Compaction Plan
runtime_acronym: SCOS-CP
technical_runtime: atlas.self_construction.compaction
repo_paths:
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os-compaction-plan.md
allowed_changes:
  - Refinar familias, mapping comando-antigo para comando-novo, ordem de fases.
forbidden_changes:
  - Remover invariantes de seguranca do Self-Construction OS.
  - Eliminar comando antigo antes de 90 dias de alias deprecated.
  - Permitir comando novo com nome >80 caracteres.
depends_on:
  - atlas-ai-self-construction-os
  - atlas-multi-agent-unified-architecture
  - atlas-agentic-engineering-os-department-contract
flows_to:
  - atlas-aaeos-doc-as-code-tooling-spec
  - atlas-documentation-health-maturity-v2-spec
unlocks:
  - command-name-length-governance
  - hierarchical-command-taxonomy
  - self-construction-doc-readability
governs:
  - atlas_ai.self_construction.compaction
evidence:
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os-compaction-plan.md
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
visual_tags:
  - refactor-plan
  - sprawl-fix
  - compaction
ai_entrypoints:
  - Leia mapping comando-antigo para comando-novo, invariantes preservadas e fases antes de tocar Self-Construction OS.
ai_usage_notes:
  - Nunca renomear comando sem registrar alias deprecated por 90 dias.
quality_gates:
  - command-name-max-80-chars
  - 7-safety-invariants-preserved
  - 8-families-have-owner
  - 5-child-docs-created
  - parent-doc-leq-280-lines
failure_modes:
  - Comando renomeado sem alias deprecated quebra automacao externa.
  - Invariante de seguranca removida ao consolidar comandos.
  - Doc filho repete sprawl da doc-mae.
  - Comando novo com nome >80 chars passa pelo gate.
observability_signals:
  - scos_command_max_name_length
  - scos_alias_deprecated_count
  - scos_invariant_violation_count
next_actions:
  - Implementar gate `command-name-max-80-chars` no docs-health v2 (T5.2).
  - Criar 5 docs filhos antes de tocar codigo.
  - Aliases deprecated por 90 dias antes de remover comandos antigos.
---
# Atlas AI Self-Construction OS Compaction Plan

## Resumo

Plano canonico para compactar o sprawl extremo do Self-Construction OS (comandos com 200+ caracteres em serie + tabela monstruosa em `atlas-ai-self-construction-os.md`). Define refator em 8 familias hierarquicas de comandos, 5 docs filhos novos, reducao da doc-mae para <=280 linhas e gate `command-name-max-80-chars` permanente. Preserva as 7 invariantes de seguranca do Self-Construction OS atual.

## Papel no Atlas

O Self-Construction OS e canonico, mas sua representacao atual quebra ergonomia, ferramentas CLI e leitura por IA. Comandos como `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-...` (visto na leitura do doc) tem **mais de 250 caracteres**. Este doc e o plano de fix governado.

## Onde Se Encaixa

```text
atlas-ai-self-construction-os               (autoridade-mae)
  +-- atlas-ai-self-construction-os-compaction-plan  (este doc)
       +-- 5 docs filhos novos (lifecycle, dispatch, review, merge, persistence)
       +-- mapping comando-antigo -> comando-novo
       +-- gate command-name-max-80-chars
```

## Contratos

### Diagnostico do sprawl atual (medido no doc lido)

- Doc-mae: 439 linhas (acima do limite recomendado de 520, ainda OK, mas sintoma de sobrecarga)
- Comandos: 100+ comandos em UMA tabela
- Comando mais longo: ~250+ caracteres em UMA string
- Hierarquia: zero (todos no mesmo nivel)
- Doc readability score (estimado): 2/10 — proibitivo para humano e IA
- Familias implicitas: ~8 distintas misturadas

### As 8 familias canonicas propostas

| # | Familia | Prefixo curto | Owner | Doc filho |
|---|---------|---------------|-------|-----------|
| 1 | Session bootstrap & ownership | `scos.session.*` | Self-Construction OS | `self-construction/scos-session.md` |
| 2 | Packet lifecycle | `scos.packet.*` | Self-Construction OS | `self-construction/scos-packet.md` |
| 3 | Reservation ledger durable | `scos.reservation.*` | Multi-Agent Unified | `self-construction/scos-reservation.md` |
| 4 | Agent lifecycle (ACP) | `scos.agent.*` | Multi-Agent Unified ACP | `self-construction/scos-agent.md` |
| 5 | Dispatch | `scos.dispatch.*` | Multi-Agent Unified ACP | `self-construction/scos-agent.md` (subsection) |
| 6 | Review | `scos.review.*` | Review department | `self-construction/scos-review.md` |
| 7 | Merge | `scos.merge.*` | Forge department | `self-construction/scos-review.md` (subsection) |
| 8 | Persistence (receipts, ledger writes) | `scos.persistence.*` | Evidence Cert Runtime | `self-construction/scos-persistence.md` |

### Mapping comando-antigo -> comando-novo (amostra)

| Antigo (sprawl) | Novo (compacto) |
|-----------------|-----------------|
| `atlas:ai:self-construction --json` | `atlas:scos:status --json` |
| `atlas:ai:self-construction --meta-sdd --json` | `atlas:scos:meta-sdd --json` |
| `atlas:ai:self-construction --receipt-preview --json` | `atlas:scos:receipt --preview --json` |
| `atlas:ai:self-construction --traceability --json` | `atlas:scos:trace --json` |
| `atlas:ai:self-construction --promotion-gate --json` | `atlas:scos:promote --gate --json` |
| `atlas:ai:self-construction --execution-candidate --json` | `atlas:scos:exec --candidate --json` |
| `atlas:ai:self-construction --approval-packet --json` | `atlas:scos:approve --packet --json` |
| `atlas:ai:self-construction --signature-request --json` | `atlas:scos:sign --request --json` |
| `atlas:ai:self-construction --execution-runbook --json` | `atlas:scos:exec --runbook --json` |
| `atlas:ai:self-construction --evidence-packet --json` | `atlas:scos:evidence --packet --json` |
| `atlas:ai:self-construction --agent-control-plane --json` | `atlas:scos:agent --status --json` |
| `atlas:ai:self-construction --agent-launch-plan --json` | `atlas:scos:agent --launch-plan --json` |
| `atlas:ai:self-construction --agent-dispatch-preflight --json` | `atlas:scos:dispatch --preflight --json` |
| `atlas:ai:self-construction --agent-review-merge-action-template --json` | `atlas:scos:merge --action-template --json` |
| `atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-...` | `atlas:scos:persistence --writer-release --cycle=new --kind=disable --stage=<n> --json` |

A logica: o que ficou serializado em string (`...-...-...-...`) vira **flags estruturadas** (`--cycle`, `--kind`, `--stage`).

### As 7 invariantes de seguranca preservadas

A compactacao **nao remove** nenhuma das 7 invariantes do Self-Construction OS atual:

1. `execution_allowed=false` por padrao em comandos read-only.
2. Decision Receipt assinado obrigatorio antes de write.
3. Hot scope forbidden sem exception receipt.
4. Allowed/forbidden files declarados por packet.
5. Operator dual signature em hot paths de runtime.
6. Append-only ledger; nunca update.
7. Replay determinístico via hash chain.

Cada invariante vira teste de regressao no novo CLI.

### Gate canonico `command-name-max-80-chars`

```text
{
  "schema": "atlas.docs_health.gate.v1",
  "id": "command-name-max-80-chars",
  "scope": "all atlas:* commands",
  "limit": 80,
  "rationale": "comandos legiveis por humano e parseable por CLI tools; sprawl evitado",
  "exceptions": [],
  "fail_mode": "block_pr"
}
```

### Os 5 docs filhos novos (a criar em fase 2)

| # | Doc | Conteudo |
|---|-----|----------|
| 1 | `self-construction/scos-session.md` | bootstrap, ownership boundary, continuation token |
| 2 | `self-construction/scos-packet.md` | packet lifecycle, queue, scope validator, evidence report |
| 3 | `self-construction/scos-reservation.md` | durable reservation ledger, collision guard, lease lifecycle |
| 4 | `self-construction/scos-agent.md` | ACP: agent lifecycle, dispatch, heartbeat, cost events (subsection: dispatch) |
| 5 | `self-construction/scos-review.md` | review, merge, post-merge action chain (subsection: merge) |
| 6 | `self-construction/scos-persistence.md` | receipt writers, append-only ledger, fresh authorization cycles |

(Sao 6, nao 5; ajuste do plano original para refletir limite de 280 linhas por doc filho.)

## Fluxo

```mermaid
flowchart TD
  Today[Today: 100+ commands sprawl, doc 439 lines]
  Phase1[Phase 1: criar 6 docs filhos]
  Phase2[Phase 2: implementar nova taxonomia atlas:scos:*]
  Phase3[Phase 3: aliases deprecated por 90 dias]
  Phase4[Phase 4: remover aliases, sealing]

  Today --> Phase1 --> Phase2 --> Phase3 --> Phase4

  Phase2 --> Gate1[gate: command-name-max-80-chars]
  Phase2 --> Gate2[gate: 7-safety-invariants-preserved]
  Phase4 --> Gate3[gate: parent-doc-leq-280-lines]
```

### Fases detalhadas

| Fase | Duracao | Saida | Bloqueio |
|------|---------|-------|----------|
| 1 docs | 1 ciclo doc | 6 docs filhos + reducao da doc-mae | nao bloqueia codigo |
| 2 commands | implementacao | comandos novos + aliases | bloqueia se invariante quebra |
| 3 deprecation | 90 dias | aliases gerando warning | externos migram |
| 4 sealing | 1 ciclo | aliases removidos | gate `command-name-max-80-chars` ativo |

## Regras para IA

- Nunca criar comando atlas dot scos com nome >80 chars.
- Comandos antigos durante fase 3 emitem warning mas funcionam.
- Toda renomeacao registra `command_alias.v1` no registry.
- Antes de fase 2, **NAO** mexer em codigo do CLI.
- Refator de servicos PHP segue Multi-Agent Unified (T1.3) — ACP nao decide provider, etc.

## Escopo de Implementacao

Servicos afetados:
- `app/Console/Commands/Atlas/Ai/SelfConstruction/*` (renomeados/agrupados)
- `AtlasAgentControlPlane*` (refator boundary com Multi-Agent Unified)
- `AtlasSelfConstruction*` (refator interno)

Docs afetadas:
- `atlas-ai-self-construction-os.md` (reduzir para indice <=280 linhas)
- 6 docs filhos novos
- `atlas-canonical-glossary-and-naming.md` (adicionar `scos.*` namespace)

## Dependencias

Ver frontmatter. Resumo: depende de Self-Construction OS atual e Multi-Agent Unified Architecture (T1.3). Flui para Doc-as-Code Tooling Spec (T5.3) e Doc Health Maturity v2 (T5.2).

## Evidencias

- Doc canonico
- Mapping comando-antigo->novo (acima)
- Comando esperado: `php artisan atlas:scos:status --json`
- Suite de regressao: `tests/Feature/Scos/CommandRenamingTest.php`

## Riscos

- **Risco critico**: quebrar automacao externa que usa comandos antigos. Mitigacao: 90 dias de aliases.
- **Risco alto**: invariantes de seguranca perdidas no refator. Mitigacao: 7 invariantes viram testes de regressao explicitos.
- **Risco medio**: doc filho repete sprawl da mae. Mitigacao: limite 280 linhas por doc filho + revisao Architect.
- **Risco baixo**: namespace conflito com `atlas:scs:*` (Self-Construction Sandbox externo). Mitigacao: namespace `scos` distinto.

## O que este doc NAO e

- Nao e a doc-mae do Self-Construction OS (continua `atlas-ai-self-construction-os.md`).
- Nao e implementacao; e plano de refator declarativo.
- Nao remove invariantes; preserva todas as 7.
- Nao bloqueia evolucao do Self-Construction OS; libera-a ao tirar dívida documental.

## Exemplos

### Antes

```bash
php artisan atlas:ai:self-construction \
  --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-persistence-rejection-template \
  --json
```

(264 caracteres em uma flag, ilegivel.)

### Depois

```bash
php artisan atlas:scos:persistence \
  --writer-release \
  --cycle=new \
  --kind=disable-execution-later-cycle-authorization \
  --stage=persistence-rejection \
  --template \
  --json
```

(8 flags semanticas, cada uma <=50 chars, total comando <=80 chars na invocacao basica.)

## Proximas Acoes

1. Criar os 6 docs filhos vazios com frontmatter canonico.
2. Migrar mapping comando-antigo->novo para registry `config/atlas/scos-aliases.php`.
3. Implementar gate `command-name-max-80-chars` em docs-health v2 (T5.2).
4. Reduzir `atlas-ai-self-construction-os.md` para indice <=280 linhas (conteudo migrou para filhos).
5. Implementar comandos atlas dot scos novos.
6. Aliases deprecated por 90 dias.
7. Sealing: remover aliases, ativar gate permanente.
