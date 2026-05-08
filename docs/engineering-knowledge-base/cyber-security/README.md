---
id: atlas-ai-cyber-security-kb-index
type: engineering_knowledge
title: Atlas AI Cyber Security KB Index
status: scaffold
category: knowledge-base
priority: 87
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
owner: atlas-ai
layer: extension
line_limit: 120
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
| `recipes-catalog.md` | Recipes ofensivas propostas para Super Tool Runtime canonico | Operador humano + Super Tool Runtime team |

## Como navegar

- Vai escrever skill nova cyber-*? Le `cyber-security-extension.md` + `refusal-matrix.md` + 1 skill exemplo (`AtlasVault/_skills/cyber-bb-runner/SKILL.md`).
- Vai propor recipe nova? Le `recipes-catalog.md` + `super-tool-runtime-core.md`.
- Vai propor flow profile novo? Le `flow-profiles-proposal.md` + `domains/programming.md` + `atlas-ai-core-vs-domain.md`.
- Vai estender refusal matrix? Le `refusal-matrix.md` + `atlas-ai-knowledge-governance-system.md`.

## Princípios

1. Nada aqui e canonico de runtime ate ser promovido (`scaffold` -> `implemented`).
2. KB e referencia tecnica; decisao operacional vive em skill + flow + Policy.
3. Atualizacao desta KB nao precisa de AP, mas precisa de PR + docs-health verde.
