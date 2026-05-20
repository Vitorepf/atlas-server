---
id: atlas-ai-cyber-security-kb-index
type: engineering_knowledge
title: Atlas AI Cyber Security KB Index
status: building
category: knowledge-base
priority: 87
implementation_state: cyber_security_scaffold_not_runtime_promoted
summary: Indice da Knowledge Base tecnica da Cyber Security extension; consolida tecnicas de pentest, patterns de remediacao, detection engineering, refusal matrix, compliance mapping, flow profiles propostos e recipes propostas.
tags:
  - atlas-ai
  - cyber-security
  - knowledge-base
  - bug-bounty
capabilities:
  - cyber_kb_index
decisions:
  - KB tecnica consolidada em arquivos curtos (line_limit 280) por tema; nao fragmentar por sub-CWE ou sub-tecnica.
  - KB consumida por skills cyber-* via referenciamento direto, nao copia.
maintenance:
  - Atualize quando adicionar/remover doc desta KB.
  - Mantenha line_limit por arquivo abaixo de 280 linhas.
related_paths:
  - atlas-server/docs/engineering-knowledge-base/cyber-security-extension.md
  - atlas-server/docs/engineering-knowledge-base/cyber-security/playbooks-techniques.md
  - atlas-server/docs/engineering-knowledge-base/cyber-security/remediation-patterns.md
  - atlas-server/docs/engineering-knowledge-base/cyber-security/detection-engineering.md
  - atlas-server/docs/engineering-knowledge-base/cyber-security/refusal-matrix.md
  - atlas-server/docs/engineering-knowledge-base/cyber-security/compliance-mapping.md
  - atlas-server/docs/engineering-knowledge-base/cyber-security/flow-profiles-proposal.md
  - atlas-server/docs/engineering-knowledge-base/cyber-security/recipes-catalog.md
  - atlas-server/docs/engineering-knowledge-base/cyber-security/recipes-existing-tools.md
  - atlas-server/docs/engineering-knowledge-base/cyber-security/recipes-offensive-families.md
  - atlas-server/docs/engineering-knowledge-base/cyber-security/recipes-external-mcp.md
  - atlas-server/docs/engineering-knowledge-base/cyber-security/recipes-promotion-runbook.md
owner: atlas-ai
layer: extension
line_limit: 120
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-cyber-security-kb-index

graph_title: Atlas AI Cyber Security KB Index

graph_world: atlas

graph_layer: module

graph_kind: index

graph_parent: atlas-ai-canonical-architecture-index

graph_status: building

graph_source: repo

repo_paths:
  - docs/engineering-knowledge-base/cyber-security/README.md

allowed_changes:
  - Atualizar este doc quando codigo, arquitetura, fluxo, evidencia ou decisao canonica mudar.

forbidden_changes:
  - Declarar runtime, maturidade ou prontidao sem evidencia verificavel e gates verdes.

depends_on:
  - atlas-ai-documentation-operating-system

flows_to:
  - atlas-cartography
  - atlas-code

unlocks:
  - ai-safe-implementation-context

governs:
  - cyber-security

evidence:
  - docs/engineering-knowledge-base/cyber-security/README.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: high

visual_tags:
  - module
  - index
  - cyber-security

ai_entrypoints:
  - Leia Resumo, Contratos, Regras para IA, Evidencias e Riscos antes de implementar.

ai_usage_notes:
  - Use repo_paths, allowed_changes, forbidden_changes e required_tests como limites operacionais.

quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"

failure_modes:
  - Contexto desatualizado entre doc, codigo, teste e evidencia.

observability_signals:
  - docs-health status ok

next_actions:
  - Manter este doc sincronizado com codigo, testes, evidencias e Cartografia.
---
# Atlas AI Cyber Security KB Index

Knowledge Base tecnica da Cyber Security extension. Consumida por skills `cyber-*` via referenciamento direto. Spec principal em `cyber-security-extension.md`.

## Documentos

| Doc | Funcao | Audiencia |
|---|---|---|
| `playbooks-techniques.md` | Tecnicas Red por categoria (webapp, api, mobile, cloud, network/AD, source, crypto, supply chain, AI/ML, IoT, threat modeling, red team coord) | IA-executora rodando skill cyber-pentest-* |
| `remediation-patterns.md` | Patterns de fix por CWE primario com snippets por stack | IA-executora rodando skill desenvolvedor + cyber-* |
| `detection-engineering.md` | Sigma rules, detection-as-code, pareamento Red↔Blue | Skill cyber-purple-runner; futuro skill cyber-detection-engineer |
| `refusal-matrix.md` | Regras canonicas de refusal Cyber, integracao com Policy do kernel | Toda skill cyber-* |
| `compliance-mapping.md` | LGPD/GDPR/HIPAA/PCI-DSS/DFARS/SOC2/ISO27001 quando programa BB toca | Skill cyber-bb-runner para validar escopo de programa |
| `flow-profiles-proposal.md` | Flows propostos para registro em AtlasDomainProfileRegistry | Operador humano + Codex avaliando promotion |
| `recipes-catalog.md` | Indice compacto das recipes propostas para Super Tool Runtime canonico | Operador humano + Super Tool Runtime team |
| `recipes-existing-tools.md` | Tools defensivas ja existentes que nao devem ser recriadas | Cyber skills + Tool Runtime team |
| `recipes-offensive-families.md` | Familias ofensivas candidatas por categoria | Operador humano + Tool Runtime team |
| `recipes-external-mcp.md` | Governanca para MCP ofensivo externo via wrapper | Security reviewer + Tool Runtime team |
| `recipes-promotion-runbook.md` | Processo de promocao de recipe individual | Codex implementando recipe |

## Como navegar

- Vai escrever skill nova cyber-*? Le `cyber-security-extension.md` + `refusal-matrix.md` + 1 skill exemplo (`AtlasVault/_skills/cyber-bb-runner/SKILL.md`).
- Vai propor recipe nova? Le `recipes-catalog.md` + `recipes-promotion-runbook.md` + `super-tool-runtime-core.md`.
- Vai propor flow profile novo? Le `flow-profiles-proposal.md` + `domains/programming.md` + `atlas-ai-core-vs-domain.md`.
- Vai estender refusal matrix? Le `refusal-matrix.md` + `atlas-ai-knowledge-governance-system.md`.

## Princípios

1. Nada aqui e canonico de runtime ate ser promovido (`scaffold` -> `implemented`).
2. KB e referencia tecnica; decisao operacional vive em skill + flow + Policy.
3. Atualizacao desta KB nao precisa de AP, mas precisa de PR + docs-health verde.

## Resumo

Indice da Knowledge Base tecnica da Cyber Security extension; consolida tecnicas de pentest, patterns de remediacao, detection engineering, refusal matrix, compliance mapping, flow profiles propostos e recipes propostas.

## Papel no Atlas

Define a responsabilidade desta peca dentro da arquitetura Atlas.

## Onde Se Encaixa

Relaciona esta peca com seu sistema, camada, fluxo ou modulo pai.

## Contratos

Declara invariantes, entradas, saidas, limites e obrigacoes relevantes.

## Fluxo

Descreve o caminho operacional ou a sequencia de uso quando aplicavel.

## Regras para IA

Agentes devem respeitar escopo, evidencias, testes e proibicoes antes de alterar codigo.

## Escopo de Implementacao

Mudancas devem permanecer nos caminhos e limites declarados no frontmatter.

## Dependencias

Dependencias canonicas vivem em frontmatter e no corpo deste documento.

## Evidencias

Evidencias aceitas incluem docs, comandos, testes, receipts, reports e paths verificaveis.

## Riscos

Riscos principais devem ser tratados antes de promover status, runtime ou claims de prontidao.

## Exemplos

Exemplos concretos devem ser adicionados quando reduzirem ambiguidade para humanos ou IAs.

## Proximas Acoes

Proximas acoes devem ser concretas, verificaveis e ligadas a gates de qualidade.
